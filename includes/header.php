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
                        <li><a href="<?php echo BASE_URL; ?>my_loans.php">My Loans</a></li>
                        <li><a href="<?php echo BASE_URL; ?>request_loan.php">Request Loan</a></li>
                        <?php if (is_admin()): ?>
                            <li><a href="<?php echo BASE_URL; ?>admin/index.php">Admin Panel</a></li>
                        <?php endif; ?>
                        <li><a href="<?php echo BASE_URL; ?>logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                    <?php else: ?>
                        <li><a href="<?php echo BASE_URL; ?>index.php">Home</a></li>
                        <li><a href="<?php echo BASE_URL; ?>login.php">Login</a></li>
                        <li><a href="<?php echo BASE_URL; ?>register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
    <div class="container">
    <!-- Main content of each page will go here -->