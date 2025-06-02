<?php
// No need to start session here, header.php does it
require_once __DIR__ . '/includes/header.php'; // Use __DIR__ for reliable path

// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect(BASE_URL . 'dashboard.php');
}

$errors = [];
$success_message = '';
$username = $email = $full_name = $monthly_income_input = ""; // Initialize variables

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $username = sanitize_input($_POST['username']);
    $email = sanitize_input($_POST['email']);
    $full_name = sanitize_input($_POST['full_name']);
    $password = $_POST['password']; // Don't sanitize password before hashing, but validate length etc
    $password_confirm = $_POST['password_confirm'];
    $monthly_income_input = sanitize_input($_POST['monthly_income']); // New field

    // Validate Full Name
    if (empty($full_name)) {
        $errors['full_name'] = "Full name is required.";
    } elseif (!is_valid_full_name($full_name)) {
        $errors['full_name'] = "Full name can only contain letters, spaces, and hyphens.";
    }

    // Validate Username
    if (empty($username)) {
        $errors['username'] = "Username is required.";
    } elseif (!is_valid_username($username)) {
        $errors['username'] = "Username must be 3-30 characters and can only contain letters, numbers, underscores, and hyphens.";
    } else {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors['username'] = "Username already taken.";
        }
        $stmt->close();
    }

    // Validate Email
    if (empty($email)) {
        $errors['email'] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Invalid email format.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors['email'] = "Email already registered.";
        }
        $stmt->close();
    }
	
	if (empty($monthly_income_input)) {
        $errors['monthly_income'] = "Monthly income is required.";
    } elseif (!is_numeric($monthly_income_input)) {
        $errors['monthly_income'] = "Monthly income must be a valid number.";
    } elseif (floatval($monthly_income_input) < 0) { // Cannot be negative
        $errors['monthly_income'] = "Monthly income cannot be negative.";
    } else {
        $monthly_income_for_db = floatval($monthly_income_input);
        // Optional: Add a reasonable minimum or maximum check if desired
        // if ($monthly_income_for_db < 1000) { $errors['monthly_income'] = "Monthly income seems too low."; }
    }
	
    // Validate Password
    if (empty($password)) {
        $errors['password'] = "Password is required.";
    } elseif (strlen($password) < 6) { // Basic length check
        $errors['password'] = "Password must be at least 6 characters long.";
    } elseif (strlen($password) > 50) { // Optional: Add a max length for raw password
        $errors['password'] = "Password cannot be longer than 50 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $password)) { // <--- ADD THIS LINE
        $errors['password'] = "Password can only contain letters and numbers (no special characters or spaces).";
    } elseif ($password !== $password_confirm) {
        $errors['password_confirm'] = "Passwords do not match.";
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Updated INSERT query to include monthly_income
        $stmt = $conn->prepare("INSERT INTO users (username, email, full_name, monthly_income, password_hash) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            // Bind monthly_income (d for decimal/double)
            $stmt->bind_param("sssds", $username, $email, $full_name, $monthly_income_for_db, $password_hash);

            if ($stmt->execute()) {
                $success_message = "Registration successful! You can now <a href='login.php'>login</a>.";
                $username = $email = $full_name = $monthly_income_input = ""; // Clear all fields
            } else {
                $errors['general'] = "Registration failed. Please try again. Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors['general'] = "Database statement preparation failed: " . $conn->error;
        }
    }
}
?>

<h2>User Registration</h2>

<?php if ($success_message): ?><p class="success"><?php echo $success_message; ?></p><?php endif; ?>
<?php if (!empty($errors['general'])): ?><p class="error"><?php echo $errors['general']; ?></p><?php endif; ?>

<form action="<?php echo htmlspecialchars(BASE_URL . 'register.php'); ?>" method="post">
    <div>
        <label for="full_name">Full Name:</label>
        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required>
        <?php if (isset($errors['full_name'])): ?><p class="error"><?php echo $errors['full_name']; ?></p><?php endif; ?>
    </div>
    <div>
        <label for="username">Username:</label>
        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
        <small> (Letters, numbers, underscores, hyphens, 3-30 chars)</small>
        <?php if (isset($errors['username'])): ?><p class="error"><?php echo $errors['username']; ?></p><?php endif; ?>
    </div>
    <div>
        <label for="email">Email:</label>
        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
        <?php if (isset($errors['email'])): ?><p class="error"><?php echo $errors['email']; ?></p><?php endif; ?>
    </div>
    <div>
        <label for="monthly_income">Monthly Income (₱):</label>
        <input type="number" step="1000" id="monthly_income" name="monthly_income" value="<?php echo htmlspecialchars($monthly_income_input); ?>" required min="10000">
        <?php if (isset($errors['monthly_income'])): ?><p class="error"><?php echo $errors['monthly_income']; ?></p><?php endif; ?>
    </div>
    <div>
        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required>
        <small> (Min. 6 characters, letters and numbers only)</small>
        <?php if (isset($errors['password'])): ?><p class="error"><?php echo $errors['password']; ?></p><?php endif; ?>
    </div>
    <div>
        <label for="password_confirm">Confirm Password:</label>
        <input type="password" id="password_confirm" name="password_confirm" required>
        <?php if (isset($errors['password_confirm'])): ?><p class="error"><?php echo $errors['password_confirm']; ?></p><?php endif; ?>
    </div>
    <button type="submit">Register</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>