<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

header('Content-Type: application/json');

// Get the MySQLi connection
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Get doctor info
$doctorStmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ? LIMIT 1");
$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();

if (!$doctor) {
    echo json_encode([
        'success' => false,
        'message' => 'Doctor profile not found'
    ]);
    exit;
}

$doctorId = $doctor['doctor_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$qrCode = $input['qr_code'] ?? '';

if (empty($qrCode)) {
    echo json_encode([
        'success' => false,
        'message' => 'QR code is required'
    ]);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Find appointment by QR code
    $appointmentStmt = $conn->prepare("
        SELECT a.*, 
               p.first_name, 
               p.last_name,
               p.patient_id
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.qr_code = ? AND a.doctor_id = ?
        LIMIT 1
    ");
    $appointmentStmt->bind_param("si", $qrCode, $doctorId);
    $appointmentStmt->execute();
    $appointment = $appointmentStmt->get_result()->fetch_assoc();

    if (!$appointment) {
        throw new Exception('Invalid QR code or appointment not found');
    }

    $appointmentId = $appointment['appointment_id'];
    $appointmentDate = $appointment['appointment_date'];
    $appointmentStatus = $appointment['status'];
    $patientName = $appointment['first_name'] . ' ' . $appointment['last_name'];

    // Validation checks
    if ($appointmentStatus === 'cancelled') {
        throw new Exception('This appointment has been cancelled');
    }

    if ($appointmentStatus === 'completed') {
        throw new Exception('This appointment is already completed');
    }

    // Check if appointment is for today
    $today = date('Y-m-d');
    if ($appointmentDate !== $today) {
        $formattedDate = date('F d, Y', strtotime($appointmentDate));
        throw new Exception("Appointment is scheduled for {$formattedDate}");
    }

    // Check if already checked in
    if (!empty($appointment['checked_in_at'])) {
        $checkedInTime = date('h:i A', strtotime($appointment['checked_in_at']));
        throw new Exception("Patient already checked in at {$checkedInTime}");
    }

    // Update appointment - mark as checked in
    $updateStmt = $conn->prepare("
        UPDATE appointments 
        SET checked_in_at = NOW(),
            checked_in_by = ?,
            status = 'confirmed'
        WHERE appointment_id = ?
    ");
    $updateStmt->bind_param("ii", $userId, $appointmentId);
    $updateStmt->execute();

    // Log the scan in qr_scan_logs
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $logStmt = $conn->prepare("
        INSERT INTO qr_scan_logs (
            appointment_id,
            qr_code,
            scanned_by,
            scanned_at,
            scan_result,
            scan_location,
            device_info,
            ip_address,
            notes
        ) VALUES (?, ?, ?, NOW(), 'success', 'Doctor Dashboard', ?, ?, ?)
    ");
    
    $scanLocation = 'Doctor Dashboard';
    $successResult = 'success';
    $notes = "Check-in successful for {$patientName}";
    
    $logStmt->bind_param(
        "isisss",
        $appointmentId,
        $qrCode,
        $userId,
        $userAgent,
        $ipAddress,
        $notes
    );
    $logStmt->execute();

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Patient checked in successfully! ✅',
        'data' => [
            'appointment_id' => $appointmentId,
            'patient_name' => $patientName,
            'checked_in_at' => date('h:i A'),
            'appointment_time' => date('h:i A', strtotime($appointment['appointment_time']))
        ]
    ]);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    // Still log failed scan attempts
    try {
        $failedLogStmt = $conn->prepare("
            INSERT INTO qr_scan_logs (
                appointment_id,
                qr_code,
                scanned_by,
                scanned_at,
                scan_result,
                scan_location,
                device_info,
                ip_address,
                notes
            ) VALUES (?, ?, ?, NOW(), 'invalid', 'Doctor Dashboard', ?, ?, ?)
        ");
        
        $appointmentIdForLog = $appointment['appointment_id'] ?? null;
        $failedResult = 'invalid';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $errorNotes = "Failed: " . $e->getMessage();
        
        $failedLogStmt->bind_param(
            "isisss",
            $appointmentIdForLog,
            $qrCode,
            $userId,
            $userAgent,
            $ipAddress,
            $errorNotes
        );
        $failedLogStmt->execute();
    } catch (Exception $logError) {
        // Silently fail if logging the error fails
        error_log("Failed to log QR scan error: " . $logError->getMessage());
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}