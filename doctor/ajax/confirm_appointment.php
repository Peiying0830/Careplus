<?php
require_once __DIR__ . '/../../config.php';
requireRole('doctor');
header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['appointment_id'])) {
    $id = intval($data['appointment_id']);
    $stmt = $conn->prepare("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}