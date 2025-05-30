<?php
require_once __DIR__ . '/../includes/header.php'; // Includes db_connect.php, functions.php (for calculate_emi_from_monthly_rate, get_simulated_date)

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$message = '';
$error_message = '';
$current_simulated_datetime_str = get_simulated_date($conn); // Fetches current "YYYY-MM-DD HH:MM:SS"

// --- Loan Action Processing (Approve/Reject) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['loan_action']) && isset($_POST['loan_id'])) {
    $loan_id = intval($_POST['loan_id']);
    $action = $_POST['loan_action'];

    // Fetch loan details to ensure it's pending and for calculations
    $stmt_fetch_loan = $conn->prepare("SELECT user_id, amount_requested, interest_rate_monthly, term_months FROM loans WHERE id = ? AND status = 'pending'");
    if (!$stmt_fetch_loan) {
        $error_message = "Database Error: Could not prepare statement to fetch loan. " . $conn->error;
    } else {
        $stmt_fetch_loan->bind_param("i", $loan_id);
        $stmt_fetch_loan->execute();
        $loan_result = $stmt_fetch_loan->get_result();

        if ($loan_result->num_rows === 1) {
            $loan_data = $loan_result->fetch_assoc();
            // $stmt_fetch_loan->close(); // Close it after fetching data

            if ($action === 'approve') {
                $amount_approved = $loan_data['amount_requested']; // For now, approve requested amount
                $interest_rate_monthly = $loan_data['interest_rate_monthly'];
                $term_months = $loan_data['term_months'];

                // Approval datetime and start date (date part of simulated approval)
                $simulated_approval_datetime = $current_simulated_datetime_str;
                $approval_date_for_db = $simulated_approval_datetime;

                $start_date_obj_temp = new DateTime($simulated_approval_datetime);
                $start_date = $start_date_obj_temp->format('Y-m-d');

                // Calculate theoretical_end_date
                $theoretical_end_date_obj = clone $start_date_obj_temp; // Use the date part object
                $theoretical_end_date_obj->add(new DateInterval('P' . $term_months . 'M'));
                $theoretical_end_date_for_db = $theoretical_end_date_obj->format('Y-m-d');

                // Calculate next payment due date (1 month from start_date)
                $next_due_calc_obj = new DateTime($start_date);
                $next_due_calc_obj->add(new DateInterval('P1M'));
                $next_payment_due_date = $next_due_calc_obj->format('Y-m-d');

                // Calculate Total Repayment Amount (using EMI)
                $P = $amount_approved;
                $r = $interest_rate_monthly;
                $n = $term_months;
                $total_repayment_amount = 0;

                if ($P > 0 && $n > 0) { // Ensure principal and term are positive
                    if ($r > 0) {
                        $monthly_payment = calculate_emi_from_monthly_rate($P, $r, $n); // Uses function from functions.php
                        $total_repayment_amount = $monthly_payment * $n;
                    } else { // No interest loan
                        $monthly_payment = $P / $n;
                        $total_repayment_amount = $P;
                    }
                    $total_repayment_amount = round($total_repayment_amount, 2);
                } else {
                    // Should not happen if validation is correct, but handle defensively
                    $error_message = "Cannot calculate repayment for zero amount or term.";
                }
                
                $remaining_balance = $total_repayment_amount; // Initially, remaining balance is the total to be repaid
                $loan_initial_status = 'active'; // Set directly to active if starts immediately

                if (empty($error_message)) { // Proceed only if no calculation errors
                    $stmt_update = $conn->prepare(
                        "UPDATE loans SET status = ?, amount_approved = ?, approval_date = ?, start_date = ?, 
                                        next_payment_due_date = ?, theoretical_end_date = ?, 
                                        total_repayment_amount = ?, remaining_balance = ? 
                         WHERE id = ?"
                    );
                    if ($stmt_update) {
                        $stmt_update->bind_param(
                            "sdssssddi", // s for status, d for amount_approved, s for approval_date, s for start_date,
                                         // s for next_payment_due_date, s for theoretical_end_date,
                                         // d for total_repayment, d for remaining_balance, i for id
                            $loan_initial_status,
                            $amount_approved,
                            $approval_date_for_db,
                            $start_date,
                            $next_payment_due_date,
                            $theoretical_end_date_for_db,
                            $total_repayment_amount,
                            $remaining_balance,
                            $loan_id
                        );
                        if ($stmt_update->execute()) {
                            $message = "Loan ID $loan_id approved successfully and set to active.";
                        } else {
                            $error_message = "Error approving loan (Execute Failed): " . $stmt_update->error;
                        }
                        $stmt_update->close();
                    } else {
                        $error_message = "Database statement preparation failed for update. Error: " . $conn->error;
                    }
                }

            } elseif ($action === 'reject') {
                $simulated_rejection_datetime = $current_simulated_datetime_str;
                $stmt_update = $conn->prepare("UPDATE loans SET status = 'rejected', approval_date = ? WHERE id = ?");
                if ($stmt_update) {
                    $stmt_update->bind_param("si", $simulated_rejection_datetime, $loan_id);
                    if ($stmt_update->execute()) {
                        $message = "Loan ID $loan_id rejected successfully.";
                    } else {
                        $error_message = "Error rejecting loan: " . $stmt_update->error;
                    }
                    $stmt_update->close();
                } else {
                    $error_message = "Database statement preparation failed for rejection. Error: " . $conn->error;
                }
            }
            if ($stmt_fetch_loan) $stmt_fetch_loan->close(); // Close the fetch statement here
        } else {
            if ($stmt_fetch_loan) $stmt_fetch_loan->close(); // Close if no rows found
            $error_message = "Loan ID $loan_id not found or not pending.";
        }
    } // End of initial $stmt_fetch_loan check
}

// --- Fetch Pending Loans for Display ---
$pending_loans = [];
$stmt_pending = $conn->prepare(
    "SELECT l.id, l.amount_requested, l.term_months, l.interest_rate_monthly, l.purpose, l.request_date, 
            u.username, u.email, u.credit_score
     FROM loans l
     JOIN users u ON l.user_id = u.id
     WHERE l.status = 'pending'
     ORDER BY l.request_date ASC"
);
if ($stmt_pending) {
    $stmt_pending->execute();
    $result_pending = $stmt_pending->get_result();
    while ($row = $result_pending->fetch_assoc()) {
        $pending_loans[] = $row;
    }
    $stmt_pending->close();
} else {
    $error_message .= " Error fetching pending loans: " . $conn->error; // Append to existing errors
}
?>

<h2>Manage & Approve Loans</h2>
<p>Current Simulated System DateTime: <strong><?php echo htmlspecialchars((new DateTime($current_simulated_datetime_str))->format('Y-m-d H:i:s')); ?></strong></p>

<?php if ($message): ?><p class="success"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
<?php if ($error_message): ?><p class="error"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>

<?php if (empty($pending_loans)): ?>
    <p>There are no pending loan requests at this time.</p>
<?php else: ?>
    <table border="1" style="width:100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr>
                <th>Loan ID</th>
                <th>User</th>
                <th>Email</th>
                <th>Credit Score</th>
                <th>Amount Req.</th>
                <th>Term</th>
                <th>Rate (Mo.)</th>
                <th>Purpose</th>
                <th>Requested On</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pending_loans as $loan): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loan['id']); ?></td>
                    <td><?php echo htmlspecialchars($loan['username']); ?></td>
                    <td><?php echo htmlspecialchars($loan['email']); ?></td>
                    <td><?php echo htmlspecialchars($loan['credit_score']); ?></td>
                    <td>₱<?php echo htmlspecialchars(number_format($loan['amount_requested'], 2)); ?></td>
                    <td><?php echo htmlspecialchars($loan['term_months']); ?> months</td>
                    <td><?php echo htmlspecialchars(number_format($loan['interest_rate_monthly'] * 100, 2)); ?>%</td>
                    <td><?php echo nl2br(htmlspecialchars($loan['purpose'] ? $loan['purpose'] : 'N/A')); ?></td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($loan['request_date']))); ?></td>
                    <td>
                        <div style="display: flex; gap: 5px; align-items: center;">
                            <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/approve_loans.php'); ?>" method="post" style="margin: 0;">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <button type="submit" name="loan_action" value="approve" class="action-button approve">Approve</button>
                            </form>
                            <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/approve_loans.php'); ?>" method="post" style="margin: 0;">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <button type="submit" name="loan_action" value="reject" class="action-button reject">Reject</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>