<?php
require_once __DIR__ . '/../includes/db_connect.php'; // For $conn
require_once __DIR__ . '/../includes/functions.php'; // For is_admin, is_logged_in

header('Content-Type: application/json');

if (!is_logged_in() || !is_admin()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM loans WHERE status = 'pending'");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = $row['count'];
    $stmt->close();
    echo json_encode(['success' => true, 'count' => $count]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
}
?>