<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

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
    
    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    // Validate required fields
    if (empty($data['patient_id'])) {
        throw new Exception('Patient is required');
    }
    
    if (empty($data['visit_date'])) {
        throw new Exception('Visit date is required');
    }
    
    if (empty($data['diagnosis'])) {
        throw new Exception('Diagnosis is required');
    }
    
    // Validate patient belongs to this doctor
    $patientCheck = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM appointments 
        WHERE patient_id = ? AND doctor_id = ?
    ");
    $patientCheck->bind_param("ii", $data['patient_id'], $doctorId);
    $patientCheck->execute();
    $patientExists = $patientCheck->get_result()->fetch_assoc();
    
    if ($patientExists['count'] == 0) {
        throw new Exception('Invalid patient selection. Patient must have an appointment with you.');
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
    
    // Prepare SQL statement for Insert
    $sql = "INSERT INTO medical_records (
        patient_id, 
        doctor_id, 
        appointment_id,
        visit_date, 
        symptoms, 
        diagnosis, 
        prescription, 
        lab_results, 
        notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    // Prepare variables for binding (must be variables, not ternary results)
    $p_patient_id    = $data['patient_id'];
    $p_visit_date     = $data['visit_date'];
    $p_symptoms       = !empty($data['symptoms']) ? $data['symptoms'] : null;
    $p_diagnosis      = $data['diagnosis'];
    $p_prescription   = !empty($data['prescription']) ? $data['prescription'] : null;
    $p_lab_results    = !empty($data['lab_results']) ? $data['lab_results'] : null;
    $p_notes          = !empty($data['notes']) ? $data['notes'] : null;

    // Type string: iiissssss (3 integers, 6 strings)
    $stmt->bind_param(
        "iiissssss", 
        $p_patient_id, 
        $doctorId, 
        $p_appointment_id, 
        $p_visit_date, 
        $p_symptoms, 
        $p_diagnosis, 
        $p_prescription, 
        $p_lab_results, 
        $p_notes
    );
    
    if ($stmt->execute()) {
        $recordId = $conn->insert_id;
        
        // Clear any unexpected output
        ob_clean();
        
        echo json_encode([
            'success' => true,
            'message' => 'Medical record added successfully' . ($p_appointment_id ? ' and linked to appointment #' . $p_appointment_id : ''),
            'record_id' => $recordId,
            'appointment_id' => $p_appointment_id
        ]);
    } else {
        throw new Exception('Failed to add medical record: ' . $stmt->error);
    }
    
    $stmt->close();
    $patientCheck->close();
    $doctorStmt->close();

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