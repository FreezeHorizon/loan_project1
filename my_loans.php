<?php
require_once __DIR__ . '/includes/header.php'; // Includes functions.php

// Check if user is logged in, otherwise redirect to login page
if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}

$user_id = $_SESSION['user_id'];
$loans = []; // Initialize $loans array
$current_sim_date_obj = new DateTime(get_simulated_date($conn)); // Get current sim date
$grace_period_days = 3; // Should match process_time_advance.php

// This block for $payment_due_for_button should be INSIDE the loop
// $payment_due_for_button = $loan['monthly_payment_display'];
// if (is_numeric($loan['remaining_balance']) && $loan['remaining_balance'] > 0 && $loan['remaining_balance'] < $loan['monthly_payment_display']) {
// $payment_due_for_button = round($loan['remaining_balance'], 2);
// }

$stmt = $conn->prepare("SELECT * FROM loans WHERE user_id = ? ORDER BY request_date DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Calculate Monthly Payment (EMI) for display
        if (($row['status'] === 'approved' || $row['status'] === 'active' || $row['status'] === 'paid_off') && isset($row['amount_approved']) && $row['amount_approved'] > 0) {
            $row['monthly_payment_display'] = calculate_emi_from_monthly_rate(
                $row['amount_approved'],
                $row['interest_rate_monthly'],
                $row['term_months']
            );
        } else {
            $row['monthly_payment_display'] = 'N/A';
        }
        $loans[] = $row;
    }
    $stmt->close();
} else {
    echo "<p class='error'>Error preparing to fetch loans: " . htmlspecialchars($conn->error) . "</p>";
}

if (isset($_SESSION['payment_message'])) {
    echo "<p class='" . ($_SESSION['payment_message_type'] ?? 'success') . "'>" . htmlspecialchars($_SESSION['payment_message']) . "</p>";
    unset($_SESSION['payment_message']);
    unset($_SESSION['payment_message_type']);
}
?>

<h2>My Loan History</h2>

<?php if (empty($loans)): ?>
    <p>You have not requested any loans yet. <a href="<?php echo BASE_URL; ?>request_loan.php">Request a loan now!</a></p>
<?php else: ?>
    <table border="1" style="width:100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr>
                <th>ID & Details</th>
                <th>Status</th>
                <th>Amount Appr.</th>
                <th>Term</th>
                <th>Monthly Rate</th>
                <th>Total Repayment</th>
                <th>Monthly Payment (EMI)</th>
                <th>Remaining Bal.</th>
                <th>Next Due Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($loans as $loan): ?>
                <?php
                $is_overdue = false;
                if ($loan['status'] === 'active' && !empty($loan['next_payment_due_date'])) {
                    $next_due_date_obj = new DateTime($loan['next_payment_due_date']);
                    $due_date_with_grace_obj = clone $next_due_date_obj;
                    $due_date_with_grace_obj->add(new DateInterval('P' . $grace_period_days . 'D'));
                    if ($current_sim_date_obj > $due_date_with_grace_obj) {
                        $is_overdue = true;
                    }
                }
                ?>
                <tr <?php if ($is_overdue) echo 'style="background-color: #fff0f0;"' ?>>
                    <td>
                        <?php echo htmlspecialchars($loan['id']); ?>
                        <br><a href="<?php echo BASE_URL; ?>user_loan_detail.php?loan_id=<?php echo $loan['id']; ?>" style="font-size:0.8em;">Details</a>
                    </td>
                    <td style="text-transform: capitalize; <?php if ($is_overdue) echo 'font-weight:bold; color:red;'; ?>" class="user-loan-status" data-loan-id="<?php echo $loan['id']; ?>" data-status="<?php echo htmlspecialchars($loan['status']); ?>">
                        <?php echo htmlspecialchars($loan['status']); ?>
                        <?php if ($is_overdue): ?>
                            <br><span style="color:red; font-size:0.8em;">(Overdue)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo isset($loan['amount_approved']) && $loan['amount_approved'] ? '₱' . htmlspecialchars(number_format($loan['amount_approved'], 2)) : 'N/A'; ?></td>
                    <td><?php echo htmlspecialchars($loan['term_months']); ?> months</td>
                    <td><?php echo htmlspecialchars(number_format($loan['interest_rate_monthly'] * 100, 2)); ?>%</td>
                    <td><?php echo isset($loan['total_repayment_amount']) && $loan['total_repayment_amount'] ? '₱' . htmlspecialchars(number_format($loan['total_repayment_amount'], 2)) : 'N/A'; ?></td>
                    <td><?php echo is_numeric($loan['monthly_payment_display']) ? '₱' . htmlspecialchars(number_format($loan['monthly_payment_display'], 2)) : $loan['monthly_payment_display']; ?></td>
                    <td><?php echo isset($loan['remaining_balance']) && $loan['remaining_balance'] !== null ? '₱' . htmlspecialchars(number_format($loan['remaining_balance'], 2)) : 'N/A'; ?></td>
                    <td><?php echo $loan['next_payment_due_date'] ? htmlspecialchars($loan['next_payment_due_date']) : 'N/A'; ?></td>
                    <td class="loan-action-cell">
                        <?php
                        if ($loan['status'] === 'active' && isset($loan['remaining_balance']) && $loan['remaining_balance'] > 0) {
                            $payment_due_for_button = $loan['monthly_payment_display']; // Default to EMI
                            if (is_numeric($loan['remaining_balance']) && is_numeric($loan['monthly_payment_display']) &&
                                $loan['remaining_balance'] < $loan['monthly_payment_display']) {
                                $payment_due_for_button = round($loan['remaining_balance'], 2);
                            } elseif (!is_numeric($loan['monthly_payment_display']) && is_numeric($loan['remaining_balance'])) {
                                // If EMI is N/A but there's a balance, set button to remaining balance
                                $payment_due_for_button = round($loan['remaining_balance'], 2);
                            }
                        ?>
                            <form action="<?php echo BASE_URL; ?>make_payment.php" method="post" onsubmit="return confirm('Confirm payment of ₱<?php echo number_format(floatval($payment_due_for_button), 2); ?> for loan ID <?php echo $loan['id']; ?>?');">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <input type="hidden" name="payment_amount" value="<?php echo floatval($payment_due_for_button); ?>">
                                <button type="submit">Pay ₱<?php echo number_format(floatval($payment_due_for_button), 2); ?></button>
                            </form>
                        <?php } elseif ($loan['status'] === 'paid_off') { ?>
                            Paid Off
                        <?php } elseif ($loan['status'] === 'pending') { ?>
                            Awaiting Approval
                        <?php } elseif ($loan['status'] === 'approved') { ?>
                            Approved (Awaiting start)
                        <?php } else { // For 'rejected', 'defaulted', etc. ?>
                            -
                        <?php } // End of main status if/elseif/else block ?>

                        <?php // View Receipt link - shown for loans that are not pending or rejected
                        if ($loan['status'] !== 'pending' && $loan['status'] !== 'rejected'): ?>
                            <a href="<?php echo BASE_URL; ?>receipt.php?loan_id=<?php echo $loan['id']; ?>" target="_blank" style="font-size:0.8em; margin-left:5px; display: block; margin-top: 5px;">View Receipt</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>dashboard.php">« Back to Dashboard</a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>