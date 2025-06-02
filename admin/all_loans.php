<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$all_loans = [];
// Consider adding pagination for many loans later
$stmt_all = $conn->prepare(
    "SELECT l.*, u.username AS user_username, u.full_name AS user_full_name
     FROM loans l
     JOIN users u ON l.user_id = u.id
     ORDER BY l.request_date DESC" // Or l.id DESC
);

if ($stmt_all) {
    $stmt_all->execute();
    $result_all = $stmt_all->get_result();
    while ($row = $result_all->fetch_assoc()) {
        $all_loans[] = $row;
    }
    $stmt_all->close();
} else {
    echo "<p class='error'>Error fetching all loans: " . htmlspecialchars($conn->error) . "</p>";
}
?>

<h2>All Loan Records</h2>

<?php if (empty($all_loans)): ?>
    <p>No loan records found in the system.</p>
<?php else: ?>
    <p>Total Loans: <?php echo count($all_loans); ?></p>
    <table border="1" style="width:100%; border-collapse: collapse; margin-top: 20px; font-size:0.9em;">
        <thead>
            <tr>
                <th>ID</th>
                <th>User</th>
                <th>Amount Req.</th>
                <th>Amount Appr.</th>
                <th>Term (M)</th>
                <th>Rate (Mo.)</th>
                <th>Status</th>
                <th>Total Repay</th>
                <th>Remaining Bal.</th>
                <th>Req. Date</th>
                <th>Appr. Date</th>
                <th>Start Date</th>
                <th>Next Due</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_loans as $loan): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loan['id']); ?></td>
                    <td><?php echo htmlspecialchars($loan['user_username']); ?> (<?php echo htmlspecialchars($loan['user_full_name']); ?>)</td>
                    <td>₱<?php echo htmlspecialchars(number_format($loan['amount_requested'], 2)); ?></td>
                    <td><?php echo $loan['amount_approved'] ? '₱' . htmlspecialchars(number_format($loan['amount_approved'], 2)) : 'N/A'; ?></td>
                    <td><?php echo htmlspecialchars($loan['term_months']); ?></td>
                    <td><?php echo htmlspecialchars(number_format($loan['interest_rate_monthly'] * 100, 2)); ?>%</td>
                    <td style="text-transform: capitalize;"><?php echo htmlspecialchars($loan['status']); ?></td>
                    <td><?php echo $loan['total_repayment_amount'] ? '₱' . htmlspecialchars(number_format($loan['total_repayment_amount'], 2)) : 'N/A'; ?></td>
                    <td><?php echo $loan['remaining_balance'] ? '₱' . htmlspecialchars(number_format($loan['remaining_balance'], 2)) : 'N/A'; ?></td>
                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($loan['request_date']))); ?></td>
                    <td><?php echo $loan['approval_date'] ? htmlspecialchars(date('Y-m-d', strtotime($loan['approval_date']))) : 'N/A'; ?></td>
                    <td><?php echo $loan['start_date'] ? htmlspecialchars($loan['start_date']) : 'N/A'; ?></td>
                    <td><?php echo $loan['next_payment_due_date'] ? htmlspecialchars($loan['next_payment_due_date']) : 'N/A'; ?></td>
                    <td>
                        <!-- Placeholder for actions like 'View Details', 'Edit Status (careful!)' -->
                        <a href="<?php echo BASE_URL; ?>admin/admin_loan_detail.php?loan_id=<?php echo $loan['id']; ?>" class="button-link">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>