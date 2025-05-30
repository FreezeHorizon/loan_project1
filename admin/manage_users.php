<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$users = [];
$stmt = $conn->prepare("SELECT id, username, email, full_name, role, credit_score, created_at FROM users ORDER BY created_at DESC");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
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
                    <td><?php echo htmlspecialchars($user['credit_score']); ?></td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($user['created_at']))); ?></td>
                    <td>
                        <!-- Placeholder for future actions like Edit, View Loans, Adjust Credit Score -->
                        <button disabled>Edit</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>