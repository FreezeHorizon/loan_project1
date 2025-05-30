<?php
// Ensure db_connect.php is included, as it starts the session.
// If not already included by the calling script, include it.
if (!isset($conn)) {
    require_once 'db_connect.php';
}

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data); // Prevents XSS
    return $data;
}

// Function to validate username (alphanumeric, underscores, hyphens)
function is_valid_username($username) {
    // Allow letters, numbers, underscores, hyphens, between 3 and 30 characters
    if (preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
        return true;
    }
    return false;
}

// Function to validate full name (letters, spaces, hyphens)
function is_valid_full_name($name) {
    // Allow letters, spaces, hyphens, apostrophes (for names like O'Malley)
    if (preg_match("/^[a-zA-Z-' ]*$/", $name)) {
        return true;
    }
    return false;
}


// Function to check if a user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Function to check if the logged-in user is an admin
function is_admin() {
    return (is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
}

// Function to redirect to a different page
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Function to get current simulated date (if not already in db_connect.php)
// You might have this in db_connect.php already. If so, you don't need to repeat it.
// function get_simulated_date($db_connection) {
//     $stmt = $db_connection->prepare("SELECT current_simulated_date FROM system_time WHERE id = 1");
//     $stmt->execute();
//     $result = $stmt->get_result();
//     if ($result && $result->num_rows > 0) {
//         $row = $result->fetch_assoc();
//         return $row['current_simulated_date'];
//     }
//     return date('Y-m-d'); // Fallback
// }
?>