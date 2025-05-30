<?php
require_once __DIR__ . '/includes/header.php';

// Check if user is logged in, otherwise redirect to login page
if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}
?>

<h2>Welcome to Your Dashboard, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>

<p>This is your personal dashboard. From here you can manage your loans and account.</p>
<p>Your current simulated system date is: <strong><?php echo htmlspecialchars(get_simulated_date($conn)); ?></strong></p>

<?php if (!is_admin()): // <<< ADD THIS CHECK ?>
<ul>
    <li><a href="<?php echo BASE_URL; ?>request_loan.php">Request a New Loan</a></li>
    <li><a href="<?php echo BASE_URL; ?>my_loans.php">View My Loans</a></li>
    <!-- More links can be added later -->
</ul>
<?php else: ?>
<p>As an administrator, your primary functions are available in the <a href="<?php echo BASE_URL; ?>admin/index.php">Admin Panel</a>.</p>
<?php endif; // <<< END OF CHECK ?>


<?php require_once __DIR__ . '/includes/footer.php'; ?>