<?php
require_once __DIR__ . '/../../config.php';
requireRole('admin');
header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['user_id'], $data['new_status'])) {
    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
    $stmt->bind_param("si", $data['new_status'], $data['user_id']);
    echo json_encode(['success' => $stmt->execute()]);
}