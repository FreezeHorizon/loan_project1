<?php
// This file should be included at the top of every user-facing PHP page.
// It starts sessions (via db_connect.php if not already done) and includes common functions.
if (session_status() == PHP_SESSION_NONE) { // Ensure session is started
    session_start();
}
require_once __DIR__ . '/db_connect.php'; // Path relative to this file's location
require_once __DIR__ . '/functions.php';

// Define BASE_URL if not already defined in db_connect.php
if (!defined('BASE_URL')) {
    // Adjust this if your project is in a subdirectory of htdocs
    // If loan_project is directly in htdocs, it's /loan_project/
    // If XAMPP is on a different port, include it e.g., http://localhost:8080/loan_project/
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_name_parts = explode('/', $_SERVER['SCRIPT_NAME']);
    // Assuming 'loan_project' is the main project folder in htdocs
    $project_folder_name = 'loan_project'; // Change if your folder name is different
    $base_path_index = array_search($project_folder_name, $script_name_parts);
    
    if ($base_path_index !== false) {
        $base_path = implode('/', array_slice($script_name_parts, 0, $base_path_index + 1)) . '/';
    } else {
        // Fallback if project folder not found in script path (e.g. directly in htdocs or complex setup)
        // This might need manual adjustment
        $base_path = '/'; 
    }
    define('BASE_URL', $protocol . $host . $base_path);
}
$header_simulated_date_str = "N/A"; // Default if DB not connected yet or error
if (isset($conn) && $conn instanceof mysqli && $conn->ping()) { // Check if $conn is valid
    // Make sure get_simulated_date is defined (it is, in functions.php)
    $header_simulated_date_str = get_simulated_date($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loaning System</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/style.css">
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">Loaning System</div>
            <nav>
                <ul>
                    <?php if (is_logged_in()): ?>
                        <li><a href="<?php echo BASE_URL; ?>dashboard.php">Dashboard</a></li>

                        <?php if (!is_admin()): // Links for non-admin users ?>
                            <li><a href="<?php echo BASE_URL; ?>my_loans.php">My Loans</a></li>
                            <li><a href="<?php echo BASE_URL; ?>request_loan.php">Request Loan</a></li>
                        <?php endif; ?>

                        <?php if (is_admin()): // Link for admin users ?>
                            <li><a href="<?php echo BASE_URL; ?>admin/index.php">Admin Panel</a></li>
                            <?php
                                // DECISION POINT: Should admins see a "My Loans" link?
                                // If an admin account might have had loans as a regular user before becoming admin,
                                // or if you assign specific "system" loans to an admin for tracking, they might need it.
                                // If so, you could add it here, perhaps styled differently or with a note.
                                // Example:
                                // if (is_admin()) { // Could also check a specific config or another session variable
                                // echo '<li><a href="' . BASE_URL . 'my_loans.php">View Assigned/Personal Loans</a></li>';
                                // }
                                // For now, the default behaviour of your logic is that admins do NOT see "My Loans"
                                // because the previous `!is_admin()` block handles it.
                            ?>
                        <?php endif; ?>

                        <li><a href="<?php echo BASE_URL; ?>logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                    <?php else: // This is for users NOT logged in ?>
                        <li><a href="<?php echo BASE_URL; ?>index.php">Home</a></li>
                        <li><a href="<?php echo BASE_URL; ?>login.php">Login</a></li>
                        <li><a href="<?php echo BASE_URL; ?>register.php">Register</a></li>
                    <?php endif; // End of is_logged_in() check ?>
                </ul>
            </nav>
        </div>
    </header>
    <div class="container main-content-area">
        <div style="text-align: right; margin-bottom: 15px; padding: 5px; background-color: #efefef; border-radius: 3px;">
            <strong>Simulated System DateTime:</strong> <?php
                if ($header_simulated_date_str !== "N/A") {
                    try {
                        $header_sim_date_obj = new DateTime($header_simulated_date_str);
                        echo htmlspecialchars($header_sim_date_obj->format('Y-m-d H:i:s'));
                    } catch (Exception $e) {
                        echo htmlspecialchars($header_simulated_date_str); // Display raw if format error
                    }
                } else {
                    echo "N/A (DB connection issue?)";
                }
            ?>
        </div>
    </header>
    <div class="container">
    <!-- Main content of each page will go here -->