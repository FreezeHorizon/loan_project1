<?php
// Database connection details
$servername = "localhost";    // Usually "localhost" for XAMPP
$username_db = "root";        // Default XAMPP MySQL username
$password_db = "";            // Default XAMPP MySQL password (empty)
$dbname = "loaning_system";   // The database name you created

// Create connection
$conn = new mysqli($servername, $username_db, $password_db, $dbname);

// Check connection
if ($conn->connect_error) {
    // In a real application, you might log this error and show a user-friendly message
    die("Database Connection Failed: " . $conn->connect_error);
}

// Set character set to utf8mb4 for better compatibility with various characters
if (!$conn->set_charset("utf8mb4")) {
    // Log error or handle if charset setting fails
    // printf("Error loading character set utf8mb4: %s\n", $conn->error);
}

// Start session if not already started.
// Sessions will be used for login status, user data, etc.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// (Optional) Define a function to get the current simulated date easily
function get_simulated_date($db_connection) {
    $result = $db_connection->query("SELECT current_simulated_date FROM system_time WHERE id = 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['current_simulated_date'];
    }
    return date('Y-m-d'); // Fallback to real date if not found, though it shouldn't happen
}

// (Optional) You might want to define your site's base URL here for easier link generation later
// define('BASE_URL', 'http://localhost/loan_project/');
?>