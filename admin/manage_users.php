<?php
require_once __DIR__ . '/../includes/header.php';

// Display session feedback messages if any from other pages like edit_user.php
if (isset($_SESSION['feedback_message_manage_users'])) {
    echo '<p class="message ' . htmlspecialchars($_SESSION['feedback_type_manage_users'] ?? 'info') . '">' . htmlspecialchars($_SESSION['feedback_message_manage_users']) . '</p>';
    unset($_SESSION['feedback_message_manage_users']);
    unset($_SESSION['feedback_type_manage_users']);
}

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$current_admin_s_role = isset($_SESSION['role']) ? $_SESSION['role'] : null; // Use $_SESSION['role'] and check if set

// Base query
$sql = "SELECT id, username, email, full_name, role, created_at, credit_score FROM users"; // Added credit_score back
$conditions = [];
$params = [];
$types = '';

// Filter by role for regular admins
if ($current_admin_s_role === 'admin') {
    $conditions[] = "role = ?";
    $params[] = 'user';
    $types .= 's';
}

// Search functionality (remains the same, but applies to the filtered set if admin)
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
if (!empty($search_term)) {
    $conditions[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
    $like_search_term = "%$search_term%";
    array_push($params, $like_search_term, $like_search_term, $like_search_term);
    $types .= 'sss';
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    echo "Error preparing statement: " . $conn->error;
    $result = false; // Ensure $result is defined
}

$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
} else {
    echo "<p class='error'>Error fetching users: " . htmlspecialchars($conn->error) . "</p>";
}
?>

<h2>Manage Users</h2>

<?php if (empty($users)): ?>
    <p>No users found.</p>
<?php else: ?>
    <table border="1" style="width:100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Credit Score</th>
                <th>Registered On</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td style="text-transform: capitalize;"><?php echo htmlspecialchars($user['role']); ?></td>
                    <td>
                        <?php 
                        if ($user['role'] === 'user') {
                            echo htmlspecialchars($user['credit_score'] ?? 'N/A'); 
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($user['created_at']))); ?></td>
                    <td>
                        <?php if (is_super_admin() && $user['id'] == $_SESSION['user_id']): ?>
                            <!-- Super Admins cannot edit themselves from this interface -->
                            <span>N/A (Self)</span>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>admin/edit_user.php?user_id=<?php echo htmlspecialchars($user['id']); ?>" class="button">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>