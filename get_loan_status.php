<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid loan ID']);
    exit();
}

$loan_id = intval($_GET['loan_id']);
$user_id = $_SESSION['user_id'];
$status = null;

$stmt = $conn->prepare("SELECT status FROM loans WHERE id = ? AND user_id = ?");
if ($stmt) {
    $stmt->bind_param("ii", $loan_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $status = $row['status'];
        echo json_encode(['success' => true, 'loan_id' => $loan_id, 'status' => $status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Loan not found or access denied']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
}
?>