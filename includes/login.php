<?php
// No need to start session here, header.php does it
require_once __DIR__ . '/includes/header.php';

// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect(BASE_URL . 'dashboard.php');
}

$errors = [];
$username_or_email = ""; // Initialize variable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_or_email = sanitize_input($_POST['username_or_email']);
    $password = $_POST['password']; // Don't sanitize password input for verification

    if (empty($username_or_email)) {
        $errors['username_or_email'] = "Username or Email is required.";
    }
    if (empty($password)) {
        $errors['password'] = "Password is required.";
    }

    if (empty($errors)) {
        // Try to fetch user by username or email
        $stmt = $conn->prepare("SELECT id, username, password_hash, role, email FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_or_email, $username_or_email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            // Verify password
            if (password_verify($password, $user['password_hash'])) {
                // Password is correct, start session
                session_regenerate_id(true); // Prevent session fixation
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                // Redirect to dashboard or admin panel
                if ($user['role'] === 'admin') {
                    redirect(BASE_URL . 'admin/index.php'); // We'll create this later
                } else {
                    redirect(BASE_URL . 'dashboard.php');
                }
            } else {
                $errors['credentials'] = "Invalid username/email or password.";
            }
        } else {
            $errors['credentials'] = "Invalid username/email or password.";
        }
        $stmt->close();
    }
}
?>

<h2>User Login</h2>

<?php if (!empty($errors['credentials'])): ?>
    <p class="error"><?php echo $errors['credentials']; ?></p>
<?php endif; ?>

<form action="<?php echo htmlspecialchars(BASE_URL . 'login.php'); ?>" method="post">
    <div>
        <label for="username_or_email">Username or Email:</label>
        <input type="text" id="username_or_email" name="username_or_email" value="<?php echo htmlspecialchars($username_or_email); ?>" required>
        <?php if (isset($errors['username_or_email'])): ?><p class="error"><?php echo $errors['username_or_email']; ?></p><?php endif; ?>
    </div>
    <div>
        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required>
        <?php if (isset($errors['password'])): ?><p class="error"><?php echo $errors['password']; ?></p><?php endif; ?>
    </div>
    <button type="submit">Login</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>