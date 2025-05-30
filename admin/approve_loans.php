<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$message = '';
$error_message = '';
$current_sim_date = get_simulated_date($conn); // Get current simulated date

// --- Loan Action Processing (Approve/Reject) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['loan_action']) && isset($_POST['loan_id'])) {
    $loan_id = intval($_POST['loan_id']);
    $action = $_POST['loan_action'];

    // Fetch loan details to ensure it's pending and for calculations
    $stmt_fetch_loan = $conn->prepare("SELECT user_id, amount_requested, interest_rate_monthly, term_months FROM loans WHERE id = ? AND status = 'pending'");
    $stmt_fetch_loan->bind_param("i", $loan_id);
    $stmt_fetch_loan->execute();
    $loan_result = $stmt_fetch_loan->get_result();

    if ($loan_result->num_rows === 1) {
        $loan_data = $loan_result->fetch_assoc();
        $stmt_fetch_loan->close();

        if ($action === 'approve') {
            $amount_approved = $loan_data['amount_requested']; // For now, approve requested amount
            $interest_rate_monthly = $loan_data['interest_rate_monthly'];
            $term_months = $loan_data['term_months'];
			// ---- MODIFICATION FOR SIMULATED DATETIME ----
			$simulated_approval_datetime = get_simulated_date($conn); // This is now DATETIME "YYYY-MM-DD HH:MM:SS"

			// For approval_date in DB, we use the full simulated DATETIME
			$approval_date_for_db = $simulated_approval_datetime;

			// For start_date, we generally want just the date part of the simulated approval
			$start_date_obj_temp = new DateTime($simulated_approval_datetime);
			$start_date = $start_date_obj_temp->format('Y-m-d'); // This is "YYYY-MM-DD"
			// ---- END MODIFICATION ----
			$loan_initial_status = 'active';
            // Calculate next payment due date (1 month from start_date)
            $start_date_obj = new DateTime($start_date);
            $start_date_obj->add(new DateInterval('P1M')); // Add 1 month
            $next_payment_due_date = $start_date_obj->format('Y-m-d');

            // Calculate Total Repayment Amount (using simple EMI formula for fixed monthly payments)
            // EMI = P * r * (1+r)^n / ((1+r)^n - 1)
            // P = Principal (amount_approved)
            // r = Monthly interest rate
            // n = Term in months
            $P = $amount_approved;
            $r = $interest_rate_monthly;
            $n = $term_months;

            if ($r > 0) {
                $monthly_payment = ($P * $r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);
                $total_repayment_amount = $monthly_payment * $n;
            } else { // No interest loan (or error in r)
                $monthly_payment = $P / $n;
                $total_repayment_amount = $P;
            }
            $total_repayment_amount = round($total_repayment_amount, 2);
            $remaining_balance = $total_repayment_amount; // Initially, remaining balance is the total to be repaid

			$stmt_update = $conn->prepare("UPDATE loans SET status = ?, amount_approved = ?, approval_date = ?, start_date = ?, next_payment_due_date = ?, total_repayment_amount = ?, remaining_balance = ? WHERE id = ?");
            if ($stmt_update) {
                $stmt_update->bind_param("dsssddi", $amount_approved, $approval_date, $start_date, $next_payment_due_date, $total_repayment_amount, $remaining_balance, $loan_id);
                if ($stmt_update->execute()) {
                    $message = "Loan ID $loan_id approved successfully.";
                    // Optional: Add logic to update user's general balance if you have one.
                } else {
                    $error_message = "Error approving loan: " . $stmt_update->error;
                }
                $stmt_update->close();
            } else {
                 $error_message = "Database statement preparation failed for update. Error: " . $conn->error;
            }

        } elseif ($action === 'reject') {
            $stmt_update = $conn->prepare("UPDATE loans SET status = 'rejected', approval_date = ? WHERE id = ?");
            if ($stmt_update) {
                $stmt_update->bind_param("si", $current_sim_date, $loan_id);
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
    } else {
        $stmt_fetch_loan->close();
        $error_message = "Loan ID $loan_id not found or not pending.";
    }
}

// --- Fetch Pending Loans for Display ---
$pending_loans = [];
$stmt_pending = $conn->prepare(
    "SELECT l.id, l.amount_requested, l.term_months, l.interest_rate_monthly, l.purpose, l.request_date, u.username, u.email, u.credit_score
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
    $error_message .= " Error fetching pending loans: " . $conn->error;
}
?>

<h2>Manage & Approve Loans</h2>
<p>Current Simulated System Date: <strong><?php echo htmlspecialchars($current_sim_date); ?></strong></p>

<?php if ($message): ?><p class="success"><?php echo $message; ?></p><?php endif; ?>
<?php if ($error_message): ?><p class="error"><?php echo $error_message; ?></p><?php endif; ?>

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
                    <td>$<?php echo htmlspecialchars(number_format($loan['amount_requested'], 2)); ?></td>
                    <td><?php echo htmlspecialchars($loan['term_months']); ?> months</td>
                    <td><?php echo htmlspecialchars(number_format($loan['interest_rate_monthly'] * 100, 2)); ?>%</td>
                    <td><?php echo htmlspecialchars($loan['purpose'] ? $loan['purpose'] : 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($loan['request_date']))); ?></td>
					<td>
                        <div style="display: flex; gap: 5px; align-items: center;">
                            <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/approve_loans.php'); ?>" method="post" style="margin: 0;">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <button type="submit" name="loan_action" value="approve" style="background-color: #5cb85c; padding: 5px 10px; font-size: 0.9em;">Approve</button>
                            </form>
                            <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/approve_loans.php'); ?>" method="post" style="margin: 0;">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <button type="submit" name="loan_action" value="reject" style="background-color: #d9534f; padding: 5px 10px; font-size: 0.9em;">Reject</button>
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