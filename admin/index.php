<?php
// Admin pages should always start by including the main header
// which handles session start, DB connection, and functions.
require_once __DIR__ . '/../includes/header.php'; // Go up one directory to includes

// Check if user is logged in and is an admin, otherwise redirect to login page
if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

// Get current simulated date for display
$current_sim_date = get_simulated_date($conn);

// Fetch count of pending loans for a quick overview
$pending_loans_count = 0;
$stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE status = 'pending'");
if ($stmt_count) {
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $row_count = $result_count->fetch_assoc();
    $pending_loans_count = $row_count['count'];
    $stmt_count->close();
}
?>

<h2>Admin Dashboard</h2>
<p>Welcome, Administrator <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
<p>Current Simulated System Date: <strong><?php echo htmlspecialchars($current_sim_date); ?></strong></p>

<div style="margin-bottom: 20px;">
    <h3>Quick Stats:</h3>
    <p>Pending Loan Approvals: <strong id="adminPendingLoanCount"><?php echo $pending_loans_count; ?></strong></p> 
</div>


    

<h3>Admin Functions:</h3>
<ul>

	<li><a href="<?php echo BASE_URL; ?>admin/approve_loans.php">Manage & Approve Loans</a> (<span id="adminPendingLoanLinkText"><?php echo $pending_loans_count; ?></span> pending)</li>
	<li><a href="<?php echo BASE_URL; ?>admin/all_loans.php">View All Loan Records</a></li>
	<li><a href="<?php echo BASE_URL; ?>admin/manage_users.php">Manage Users</a></li>
	<li><a href="<?php echo BASE_URL; ?>admin/time_controls.php">Advance System Time</a></li>
	
    <!-- Add more admin links as features are developed -->
</ul>

<p><a href="<?php echo BASE_URL; ?>dashboard.php">« Back to User Dashboard</a> (if you also have user functions)</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>