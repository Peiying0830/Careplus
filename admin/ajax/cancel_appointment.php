<?php
require_once __DIR__ . '/../../config.php';
requireRole('patient');

header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Get JSON input from the JavaScript call
$data = json_decode(file_get_contents('php://input'), true);
$appointmentId = intval($data['appointment_id'] ?? 0);

try {
    // Verify ownership and cancellation window (24 hours) using MySQLi
    $checkQuery = "
        SELECT a.appointment_date, a.appointment_time 
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ? AND p.user_id = ? AND a.status IN ('pending', 'confirmed')
    ";
    
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("ii", $appointmentId, $userId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();

    if (!$appt) {
        throw new Exception("Appointment not found or cannot be cancelled.");
    }

    $apptDatetime = strtotime($appt['appointment_date'] . ' ' . $appt['appointment_time']);
    if ($apptDatetime - time() < (24 * 60 * 60)) {
        throw new Exception("Cancellation is only allowed 24 hours before the appointment.");
    }

    // Perform the cancellation
    $updateStmt = $conn->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?");
    $updateStmt->bind_param("i", $appointmentId);
    
    if ($updateStmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Appointment cancelled successfully.']);
    } else {
        throw new Exception("Database error during cancellation.");
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}