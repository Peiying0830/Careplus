<?php
require_once __DIR__ . '/../../config.php';
requireRole('admin');
header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['doctor_id'])) {
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE user_id = (SELECT user_id FROM doctors WHERE doctor_id = ?)");
    $stmt->bind_param("i", $data['doctor_id']);
    echo json_encode(['success' => $stmt->execute()]);
}