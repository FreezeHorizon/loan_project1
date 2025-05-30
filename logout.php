<?php
// Start session to access session variables
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Include base_url definition if you redirect using it
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_name_parts = explode('/', $_SERVER['SCRIPT_NAME']);
    $project_folder_name = 'loan_project';
    $base_path_index = array_search($project_folder_name, $script_name_parts);
    if ($base_path_index !== false) {
        $base_path = implode('/', array_slice($script_name_parts, 0, $base_path_index + 1)) . '/';
    } else {
        $base_path = '/'; 
    }
    define('BASE_URL', $protocol . $host . $base_path);
}


// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page or home page
header("Location: " . BASE_URL . "login.php");
exit();
?>