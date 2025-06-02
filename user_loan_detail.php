<?php
require_once __DIR__ . '/includes/header.php';

if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}

if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    $_SESSION['user_message'] = "Invalid Loan ID specified.";
    $_SESSION['user_message_type'] = "error";
    redirect(BASE_URL . 'my_loans.php');
}

$loan_id = intval($_GET['loan_id']);
$user_id = $_SESSION['user_id']; // Current logged-in user

$loan_details = null;
$user_details_for_loan = null; // This will be the current user
$loan_payments = [];

// Fetch Loan Details, ensuring it belongs to the current user
$stmt_loan = $conn->prepare("SELECT l.*, u.username, u.full_name, u.email, u.credit_score
                            FROM loans l
                            JOIN users u ON l.user_id = u.id
                            WHERE l.id = ? AND l.user_id = ?");
if ($stmt_loan) {
    $stmt_loan->bind_param("ii", $loan_id, $user_id);
    $stmt_loan->execute();
    $result_loan = $stmt_loan->get_result();
    if ($result_loan->num_rows === 1) {
        $loan_details = $result_loan->fetch_assoc();
        // Since we joined users table and filtered by user_id, $loan_details already has user info aliased.
        // Or, fetch current user details separately if needed for other things (though redundant here).
        // For consistency with admin_loan_detail, let's assume $loan_details contains needed user info
        // via aliases like user_username, user_full_name (if you set them up in the query)
        // OR simply use $_SESSION for current user's basic info for display.

        // For this page, the "user" is always the logged-in user
        $user_details_for_loan = [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => '', // You might need to query users table again if full_name not in session
            'email' => $_SESSION['email'] ?? '', // if email is in session
            'credit_score' => 0 // Query users table for current score
        ];
        // Let's re-fetch the full user details for consistency:
        $stmt_user_sess = $conn->prepare("SELECT full_name, credit_score FROM users WHERE id = ?");
        if ($stmt_user_sess) {
            $stmt_user_sess->bind_param("i", $user_id);
            $stmt_user_sess->execute();
            $res_user_sess = $stmt_user_sess->get_result();
            if($data_user_sess = $res_user_sess->fetch_assoc()){
                $user_details_for_loan['full_name'] = $data_user_sess['full_name'];
                $user_details_for_loan['credit_score'] = $data_user_sess['credit_score'];
            }
            $stmt_user_sess->close();
        }


        // Fetch Associated Loan Payments
        $stmt_payments = $conn->prepare("SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY payment_date DESC");
        if ($stmt_payments) {
            $stmt_payments->bind_param("i", $loan_id);
            $stmt_payments->execute();
            $result_payments = $stmt_payments->get_result();
            while ($row = $result_payments->fetch_assoc()) {
                $loan_payments[] = $row;
            }
            $stmt_payments->close();
        }

    } else {
        $_SESSION['user_message'] = "Loan with ID $loan_id not found or you do not have permission to view it.";
        $_SESSION['user_message_type'] = "error";
        // redirect(BASE_URL . 'my_loans.php'); // Optional: redirect immediately
    }
    if($stmt_loan) $stmt_loan->close();
} else {
    $_SESSION['user_message'] = "Error preparing to fetch loan details.";
    $_SESSION['user_message_type'] = "error";
    // redirect(BASE_URL . 'my_loans.php'); // Optional: redirect immediately
}

$is_overdue_detail = false;
if ($loan_details && $loan_details['status'] === 'active' && !empty($loan_details['next_payment_due_date'])) {
    $current_sim_date_obj_detail = new DateTime(get_simulated_date($conn));
    $grace_period_days_detail = 3; // Should match process_time_advance.php
    $next_due_date_obj_detail = new DateTime($loan_details['next_payment_due_date']);
    $due_date_with_grace_obj_detail = clone $next_due_date_obj_detail;
    $due_date_with_grace_obj_detail->add(new DateInterval('P' . $grace_period_days_detail . 'D'));
    if ($current_sim_date_obj_detail > $due_date_with_grace_obj_detail) {
        $is_overdue_detail = true;
    }
}

?>

<h2>Loan Details - ID: <?php echo htmlspecialchars($loan_id); ?></h2>

<?php
// Display session message if set (e.g., from redirect or if loan not found)
if (isset($_SESSION['user_message'])) {
    echo "<p class='" . ($_SESSION['user_message_type'] ?? 'info') . "'>" . htmlspecialchars($_SESSION['user_message']) . "</p>";
    unset($_SESSION['user_message']);
    unset($_SESSION['user_message_type']);
}
?>

<?php if ($loan_details && $user_details_for_loan): ?>
    <?php if ($is_overdue_detail): ?>
        <p style="color: red; font-weight: bold; border: 1px solid red; padding: 10px; margin-bottom:15px;">
            Attention: This loan has an overdue payment according to the current simulated date.
        </p>
    <?php endif; ?>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; margin-bottom: 20px;">
        <div style="width: 100%; margin-bottom:15px; padding:10px; background-color:#f9f9f9; border:1px solid #eee;">
            <h3>Your Information</h3>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($user_details_for_loan['username']); ?></p>
            <p><strong>Full Name:</strong> <?php echo htmlspecialchars($user_details_for_loan['full_name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($user_details_for_loan['email']); ?></p>
            <p><strong>Your Current Credit Score:</strong> <?php echo htmlspecialchars($user_details_for_loan['credit_score']); ?></p>
        </div>
        <hr style="width:100%;">
        <div style="width: 100%; padding:10px; background-color:#f9f9f9; border:1px solid #eee; margin-top:15px;">
            <h3>Loan Summary</h3>
            <p><strong>Status:</strong> <span style="text-transform: capitalize; font-weight: bold;"><?php echo htmlspecialchars($loan_details['status']); ?></span></p>
            <p><strong>Amount Requested:</strong> ₱<?php echo htmlspecialchars(number_format($loan_details['amount_requested'], 2)); ?></p>
            <p><strong>Amount Approved:</strong> <?php echo $loan_details['amount_approved'] ? '₱' . htmlspecialchars(number_format($loan_details['amount_approved'], 2)) : 'N/A'; ?></p>
            <p><strong>Term:</strong> <?php echo htmlspecialchars($loan_details['term_months']); ?> months</p>
            <p><strong>Monthly Interest Rate:</strong> <?php echo htmlspecialchars(number_format($loan_details['interest_rate_monthly'] * 100, 2)); ?>%</p>
            <p><strong>Calculated Monthly Payment (EMI):</strong>
                <?php
                if (($loan_details['status'] === 'approved' || $loan_details['status'] === 'active' || $loan_details['status'] === 'paid_off' || $loan_details['status'] === 'defaulted') && $loan_details['amount_approved'] > 0) {
                    $emi = calculate_emi_from_monthly_rate(
                        $loan_details['amount_approved'],
                        $loan_details['interest_rate_monthly'],
                        $loan_details['term_months']
                    );
                    echo '₱' . htmlspecialchars(number_format($emi, 2));
                } else {
                    echo 'N/A';
                }
                ?>
            </p>
            <p><strong>Total Repayment Amount:</strong> <?php echo $loan_details['total_repayment_amount'] ? '₱' . htmlspecialchars(number_format($loan_details['total_repayment_amount'], 2)) : 'N/A'; ?></p>
            <p><strong>Remaining Balance:</strong> <?php echo $loan_details['remaining_balance'] !== null ? '₱' . htmlspecialchars(number_format($loan_details['remaining_balance'], 2)) : 'N/A'; ?></p>
            <p><strong>Purpose:</strong> <?php echo htmlspecialchars($loan_details['purpose'] ? $loan_details['purpose'] : 'N/A'); ?></p>
        </div>
    </div>

    <div style="clear:both;"></div>

    <h3>Loan Dates</h3>
    <p><strong>Request Date:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($loan_details['request_date']))); ?></p>
    <p><strong>Approval Date:</strong> <?php echo $loan_details['approval_date'] ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($loan_details['approval_date']))) : 'N/A'; ?></p>
    <p><strong>Start Date:</strong> <?php echo $loan_details['start_date'] ? htmlspecialchars($loan_details['start_date']) : 'N/A'; ?></p>
    <p><strong>Next Payment Due Date:</strong> <?php echo $loan_details['next_payment_due_date'] ? htmlspecialchars($loan_details['next_payment_due_date']) : 'N/A'; ?></p>

    <h3>Payment History (<?php echo count($loan_payments); ?>)</h3>
    <?php if (empty($loan_payments)): ?>
        <p>No payments have been made for this loan yet.</p>
    <?php else: ?>
        <table border="1" style="width:100%; border-collapse: collapse; margin-top: 10px;">
            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Payment Date</th>
                    <th>Amount Paid</th>
                    <th>Type</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loan_payments as $payment): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($payment['id']); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($payment['payment_date']))); ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format($payment['amount_paid'], 2)); ?></td>
                        <td style="text-transform: capitalize;"><?php echo htmlspecialchars($payment['payment_type']); ?></td>
                        <td><?php echo htmlspecialchars($payment['notes'] ? $payment['notes'] : 'N/A'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <hr style="margin:20px 0;">
    <p><a href="<?php echo BASE_URL; ?>receipt.php?loan_id=<?php echo $loan_id; ?>" target="_blank" class="button-link">Print Loan Summary</a></p>

<?php elseif (!isset($_SESSION['user_message'])): // Only show generic error if no specific session message was set ?>
    <p class="error">Could not retrieve loan details. The loan may not exist or you may not have permission to view it.</p>
<?php endif; ?>

<p style="margin-top: 30px;"><a href="<?php echo BASE_URL; ?>my_loans.php">« Back to My Loans</a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>