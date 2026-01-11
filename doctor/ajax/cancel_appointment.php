<?php
require_once __DIR__ . '/../../config.php';
requireRole('doctor');
header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['appointment_id'])) {
    $id = intval($data['appointment_id']);
    $reason = $data['reason'] ?? 'No reason provided';
    
    $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled', notes = CONCAT(COALESCE(notes,''), ?) WHERE appointment_id = ?");
    $note = "\nCancellation reason: " . $reason;
    $stmt->bind_param("si", $note, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}