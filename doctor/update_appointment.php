<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

header('Content-Type: application/json');

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['appointment_id']) || !isset($input['status'])) {
        throw new Exception('Missing required parameters');
    }
    
    $appointmentId = (int)$input['appointment_id'];
    $newStatus = $input['status'];
    $notes = $input['notes'] ?? null;
    
    // Validate status
    $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
    if (!in_array($newStatus, $validStatuses)) {
        throw new Exception('Invalid status');
    }
    
    // Get MySQLi connection from singleton
    $conn = Database::getInstance()->getConnection();
    $userId = getUserId(); // Doctor's user ID
    
    // Verify doctor owns this appointment and get Patient User ID for notification
    $checkStmt = $conn->prepare("
        SELECT a.appointment_id, p.user_id as patient_user_id, a.appointment_date 
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ? AND d.user_id = ?
    ");
    $checkStmt->bind_param("ii", $appointmentId, $userId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $appointmentData = $checkResult->fetch_assoc();
    
    if (!$appointmentData) {
        throw new Exception('Appointment not found or access denied');
    }
    
    $patientUserId = $appointmentData['patient_user_id'];
    $apptDate = date('M d, Y', strtotime($appointmentData['appointment_date']));

    // Update appointment status
    if ($notes !== null) {
        $formattedNotes = "\n[" . date('Y-m-d H:i:s') . "] " . $notes;
        $updateQuery = "UPDATE appointments SET status = ?, notes = CONCAT(COALESCE(notes, ''), ?), updated_at = CURRENT_TIMESTAMP WHERE appointment_id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ssi", $newStatus, $formattedNotes, $appointmentId);
    } else {
        $updateQuery = "UPDATE appointments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE appointment_id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("si", $newStatus, $appointmentId);
    }
    
    if ($updateStmt->execute()) {
        
        // start Notification Logic
        $notifTitle = "";
        $notifMessage = "";

        switch ($newStatus) {
            case 'confirmed':
                // This is triggered when the doctor scans the QR code
                $notifTitle = "Appointment Checked-in";
                $notifMessage = "You have successfully checked in for your appointment on $apptDate. Please wait for your name to be called.";
                break;
            case 'completed':
                $notifTitle = "Appointment Completed";
                $notifMessage = "Your appointment on $apptDate has been marked as completed. You can now view your records.";
                break;
            case 'cancelled':
                $notifTitle = "Appointment Cancelled";
                $notifMessage = "Your appointment on $apptDate has been cancelled by the doctor.";
                break;
        }

        if ($notifTitle !== "") {
            $notifStmt = $conn->prepare("
                INSERT INTO notifications (user_id, notification_type, title, message, is_read, created_at) 
                VALUES (?, 'appointment', ?, ?, 0, CURRENT_TIMESTAMP)
            ");
            $notifStmt->bind_param("iss", $patientUserId, $notifTitle, $notifMessage);
            $notifStmt->execute();
            $notifStmt->close();
        }
        // End Notification Logic
        echo json_encode([
            'success' => true,
            'message' => 'Appointment status updated and patient notified',
            'new_status' => $newStatus
        ]);
    } else {
        throw new Exception("Database update failed: " . $conn->error);
    }
    
    $checkStmt->close();
    $updateStmt->close();
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>