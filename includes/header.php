<?php
// This file should be included at the top of every user-facing PHP page.
// It starts sessions (via db_connect.php if not already done) and includes common functions.
if (session_status() == PHP_SESSION_NONE) { // Ensure session is started
    session_start();
}
require_once __DIR__ . '/db_connect.php'; // Establishes $conn, handles basic DB connection
require_once __DIR__ . '/functions.php';  // Defines helper functions like is_logged_in, is_admin, get_simulated_date

// Define BASE_URL if not already defined (e.g., in a central config or db_connect.php)
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    // Attempt to determine project folder dynamically. Adjust $project_folder_name if needed.
    $project_folder_name = 'loan_project';
    $script_name_parts = explode('/', $_SERVER['SCRIPT_NAME']);
    $base_path_index = array_search($project_folder_name, $script_name_parts);

    if ($base_path_index !== false) {
        $base_path = implode('/', array_slice($script_name_parts, 0, $base_path_index + 1)) . '/';
    } else {
        // Fallback if project folder is not in the script path (e.g. running from htdocs root)
        // Or if script is in a deeper subdirectory not matching project_folder_name directly.
        // This might require manual adjustment for complex setups.
        // If loan_project is directly in htdocs, this will be '/loan_project/'
        // If your files are in htdocs/loan_project/some_subdir/file.php, this logic might need refinement
        // or you might hardcode BASE_URL in a config file.
        // For a typical XAMPP setup where loan_project is a folder in htdocs:
        $base_path = '/' . $project_folder_name . '/';
        if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $base_path . 'index.php')) { // Simple check
             $base_path = '/'; // Default to root if path seems incorrect
        }
    }
    define('BASE_URL', $protocol . $host . $base_path);
}

// Fetch simulated date to make it available for display in the header section of content
$header_simulated_date_str = "N/A"; // Default
if (isset($conn) && $conn instanceof mysqli && $conn->ping()) { // Check if $conn is valid and connection is alive
    if (function_exists('get_simulated_date')) {
        $header_simulated_date_str = get_simulated_date($conn);
    } else {
        // This case should ideally not happen if functions.php is included correctly
        $header_simulated_date_str = "Error: get_simulated_date() not found.";
    }
} else {
    $header_simulated_date_str = "N/A (DB Connection Issue)";
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
    <header class="site-header"> <!-- Main Site Header -->
        <div class="container"> <!-- Inner container for centering header content -->
            <div class="logo"><a href="<?php echo BASE_URL; ?>index.php">Loaning System</a></div>
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

    <div class="container main-content-area"> <!-- Main Page Content Wrapper -->
        <div class="simulated-time-display">
            <strong>Simulated System DateTime:</strong> 
            <?php
                if ($header_simulated_date_str !== "N/A" && strpos($header_simulated_date_str, "Error:") === false && strpos($header_simulated_date_str, "Issue") === false) {
                    try {
                        $header_sim_date_obj = new DateTime($header_simulated_date_str);
                        echo htmlspecialchars($header_sim_date_obj->format('Y-m-d H:i:s'));
                    } catch (Exception $e) {
                        // If DateTime conversion fails (e.g., malformed date string from DB somehow)
                        echo htmlspecialchars($header_simulated_date_str) . " (Invalid Format)";
                    }
                } else {
                    echo htmlspecialchars($header_simulated_date_str); // Display "N/A" or the error message
                }
            ?>
        </div>
        <!-- Individual page content will start after this in each respective page file -->
        <!-- The closing </div> for main-content-area will be in footer.php -->