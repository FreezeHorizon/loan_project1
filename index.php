<?php
require_once __DIR__ . '/includes/header.php'; // This includes functions.php and starts session

if (is_logged_in()) {
    redirect(BASE_URL . 'dashboard.php');
} else {
    // Optionally, show a landing page content or redirect to login
    // For simplicity, let's redirect to login.php
    redirect(BASE_URL . 'login.php');

    // OR, you could display some content here:
    /*
    ?>
    <h2>Welcome to the Loaning System</h2>
    <p>Please <a href="login.php">login</a> or <a href="register.php">register</a> to continue.</p>
    <?php
    */
}

// Footer is not strictly needed if redirecting, but good practice if showing content
// require_once __DIR__ . '/includes/footer.php';
?>