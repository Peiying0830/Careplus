<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    
    // Check if user is logged in and is a doctor
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'doctor') {
        throw new Exception('Unauthorized access');
    }
    
    // Get the MySQLi connection from the singleton
    $conn = Database::getInstance()->getConnection();
    $userId = $_SESSION['user_id'];
    
    // Get doctor ID
    $doctorStmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
    $doctorStmt->bind_param("i", $userId);
    $doctorStmt->execute();
    $doctor = $doctorStmt->get_result()->fetch_assoc();
    
    if (!$doctor) {
        throw new Exception('Doctor profile not found');
    }
    
    $doctorId = $doctor['doctor_id'];
    
    // Get POST data (JSON)
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    // Validate required fields
    if (empty($data['record_id'])) {
        throw new Exception('Record ID is required');
    }
    if (empty($data['patient_id'])) {
        throw new Exception('Patient is required');
    }
    if (empty($data['visit_date'])) {
        throw new Exception('Visit date is required');
    }
    if (empty($data['diagnosis'])) {
        throw new Exception('Diagnosis is required');
    }
    
    // Verify record belongs to this doctor
    $checkStmt = $conn->prepare("
        SELECT record_id 
        FROM medical_records 
        WHERE record_id = ? AND doctor_id = ?
    ");
    $checkStmt->bind_param("ii", $data['record_id'], $doctorId);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Record not found or unauthorized');
    }
    
    // AUTO-LINK: Try to find matching appointment by patient, doctor, and date
    $p_appointment_id = null;
    if (!empty($data['appointment_id'])) {
        // If appointment_id is provided, use it
        $p_appointment_id = $data['appointment_id'];
    } else {
        // Auto-find matching appointment
        $findAptStmt = $conn->prepare("
            SELECT appointment_id 
            FROM appointments 
            WHERE patient_id = ? 
            AND doctor_id = ? 
            AND DATE(appointment_date) = DATE(?)
            AND status = 'completed'
            ORDER BY appointment_date DESC, appointment_time DESC
            LIMIT 1
        ");
        $findAptStmt->bind_param("iis", $data['patient_id'], $doctorId, $data['visit_date']);
        $findAptStmt->execute();
        $aptResult = $findAptStmt->get_result()->fetch_assoc();
        
        if ($aptResult) {
            $p_appointment_id = $aptResult['appointment_id'];
        }
        $findAptStmt->close();
    }
    
    // Prepare SQL Update statement (now includes appointment_id)
    $sql = "UPDATE medical_records SET
        patient_id = ?,
        appointment_id = ?,
        visit_date = ?,
        symptoms = ?,
        diagnosis = ?,
        prescription = ?,
        lab_results = ?,
        notes = ?,
        updated_at = NOW()
        WHERE record_id = ? AND doctor_id = ?";
    
    $stmt = $conn->prepare($sql);
    
    // Map values to variables for bind_param (prevents reference issues)
    $patient_id    = $data['patient_id'];
    $visit_date    = $data['visit_date'];
    $symptoms      = !empty($data['symptoms']) ? $data['symptoms'] : null;
    $diagnosis     = $data['diagnosis'];
    $prescription  = !empty($data['prescription']) ? $data['prescription'] : null;
    $lab_results   = !empty($data['lab_results']) ? $data['lab_results'] : null;
    $notes         = !empty($data['notes']) ? $data['notes'] : null;
    $record_id     = $data['record_id'];

    // Bind parameters: i = int, s = string
    // Order: patient_id(i), appointment_id(i), visit_date(s), symptoms(s), diagnosis(s), prescription(s), lab_results(s), notes(s), record_id(i), doctor_id(i)
    $stmt->bind_param(
        "iissssssii", 
        $patient_id,
        $p_appointment_id,  // Now included
        $visit_date, 
        $symptoms, 
        $diagnosis, 
        $prescription, 
        $lab_results, 
        $notes, 
        $record_id, 
        $doctorId
    );
    
    if ($stmt->execute()) {
        // Clear any unexpected output
        ob_clean();
        
        echo json_encode([
            'success' => true,
            'message' => 'Medical record updated successfully' . ($p_appointment_id ? ' and linked to appointment #' . $p_appointment_id : ''),
            'appointment_id' => $p_appointment_id
        ]);
    } else {
        throw new Exception('Failed to update medical record: ' . $stmt->error);
    }

    $doctorStmt->close();
    $checkStmt->close();
    $stmt->close();
    
} catch (Exception $e) {
    // Clear any unexpected output
    if (ob_get_length()) ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// End output buffering and send
ob_end_flush();