<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    $_SESSION['admin_message'] = "Invalid Loan ID specified.";
    $_SESSION['admin_message_type'] = "error";
    redirect(BASE_URL . 'admin/all_loans.php');
}

$loan_id = intval($_GET['loan_id']);
$loan_details = null;
$user_details = null;
$loan_payments = [];

// Fetch Loan Details
$stmt_loan = $conn->prepare("SELECT * FROM loans WHERE id = ?");
if ($stmt_loan) {
    $stmt_loan->bind_param("i", $loan_id);
    $stmt_loan->execute();
    $result_loan = $stmt_loan->get_result();
    if ($result_loan->num_rows === 1) {
        $loan_details = $result_loan->fetch_assoc();

        // Fetch User Details for this loan
        $stmt_user = $conn->prepare("SELECT id, username, full_name, email, credit_score FROM users WHERE id = ?");
        if ($stmt_user) {
            $stmt_user->bind_param("i", $loan_details['user_id']);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            if ($result_user->num_rows === 1) {
                $user_details = $result_user->fetch_assoc();
            }
            $stmt_user->close();
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
        $_SESSION['admin_message'] = "Loan with ID $loan_id not found.";
        $_SESSION['admin_message_type'] = "error";
        redirect(BASE_URL . 'admin/all_loans.php');
    }
    $stmt_loan->close();
} else {
    // Handle statement preparation error
    $_SESSION['admin_message'] = "Error preparing to fetch loan details.";
    $_SESSION['admin_message_type'] = "error";
    redirect(BASE_URL . 'admin/all_loans.php');
}


?>

<h2>Loan Details - ID: <?php echo htmlspecialchars($loan_id); ?></h2>

<?php if ($loan_details && $user_details): ?>
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
        <div style="width: 48%;">
            <h3>User Information</h3>
            <p><strong>User ID:</strong> <?php echo htmlspecialchars($user_details['id']); ?></p>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($user_details['username']); ?></p>
            <p><strong>Full Name:</strong> <?php echo htmlspecialchars($user_details['full_name']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($user_details['email']); ?></p>
            <p><strong>Current Credit Score:</strong> <?php echo htmlspecialchars($user_details['credit_score']); ?></p>
        </div>
        <div style="width: 48%;">
            <h3>Loan Summary</h3>
			<p>
				<a href="<?php echo BASE_URL; ?>receipt.php?loan_id=<?php echo htmlspecialchars($loan_id); ?>" target="_blank" class="button-link" style="background-color: #f0ad4e;">View/Print Receipt</a>
			</p>
            <p><strong>Status:</strong> <span style="text-transform: capitalize; font-weight: bold;"><?php echo htmlspecialchars($loan_details['status']); ?></span></p>
            <p><strong>Amount Requested:</strong> $<?php echo htmlspecialchars(number_format($loan_details['amount_requested'], 2)); ?></p>
            <p><strong>Amount Approved:</strong> <?php echo $loan_details['amount_approved'] ? '$' . htmlspecialchars(number_format($loan_details['amount_approved'], 2)) : 'N/A'; ?></p>
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
                    echo '$' . htmlspecialchars(number_format($emi, 2));
                } else {
                    echo 'N/A';
                }
                ?>
            </p>
            <p><strong>Total Repayment Amount:</strong> <?php echo $loan_details['total_repayment_amount'] ? '$' . htmlspecialchars(number_format($loan_details['total_repayment_amount'], 2)) : 'N/A'; ?></p>
            <p><strong>Remaining Balance:</strong> <?php echo $loan_details['remaining_balance'] ? '$' . htmlspecialchars(number_format($loan_details['remaining_balance'], 2)) : 'N/A'; ?></p>
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
                        <td>$<?php echo htmlspecialchars(number_format($payment['amount_paid'], 2)); ?></td>
                        <td style="text-transform: capitalize;"><?php echo htmlspecialchars($payment['payment_type']); ?></td>
                        <td><?php echo htmlspecialchars($payment['notes'] ? $payment['notes'] : 'N/A'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <hr style="margin: 20px 0;">
    <!-- Future: Add admin actions here like 'Mark as Defaulted', 'Add Manual Payment Note', 'Adjust Due Date (with reason)' 
    <p><strong>Admin Actions:</strong></p>
    <p><em>(Further admin actions for this loan can be added here in the future.)</em></p> -->


<?php else: ?>
    <p class="error">Could not retrieve complete loan or user details.</p>
<?php endif; ?>

<p style="margin-top: 30px;"><a href="<?php echo BASE_URL; ?>admin/all_loans.php">« Back to All Loan Records</a></p>
<p><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>