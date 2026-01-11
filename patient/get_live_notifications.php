<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'] ?? 0;

if (!$userId) {
    echo json_encode(['success' => false]);
    exit;
}

// Fetch the most recent unread notification created in the last 15 seconds
// This ensures we only catch "live" events
$stmt = $conn->prepare("
    SELECT notification_id, title, message 
    FROM notifications 
    WHERE user_id = ? AND is_read = 0 AND created_at >= NOW() - INTERVAL 15 SECOND
    ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result) {
    echo json_encode(['success' => true, 'data' => $result]);
} else {
    echo json_encode(['success' => false]);
}