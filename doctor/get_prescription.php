<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

header('Content-Type: application/json');

// Get the MySQLi connection from the singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Get doctor ID
$doctorStmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ? LIMIT 1");
$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctorResult = $doctorStmt->get_result();
$doctorId = ($row = $doctorResult->fetch_row()) ? $row[0] : null;

if (!$doctorId) {
    echo json_encode(['success' => false, 'message' => 'Doctor not found']);
    exit;
}

// Get prescription ID from request
$prescriptionId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);

if (!$prescriptionId) {
    echo json_encode(['success' => false, 'message' => 'Invalid prescription ID']);
    exit;
}

try {
    // Fetch prescription details
    // UPDATE: Added p.profile_picture to the SELECT list below
    $prescQuery = "
        SELECT 
            pr.prescription_id,
            pr.prescription_date,
            pr.diagnosis,
            pr.notes,
            pr.status,
            pr.verification_code,
            pr.valid_until,
            p.patient_id,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.profile_picture, 
            p.date_of_birth,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as patient_age
        FROM prescriptions pr
        JOIN patients p ON pr.patient_id = p.patient_id
        WHERE pr.prescription_id = ? AND pr.doctor_id = ?
        LIMIT 1
    ";
    
    $prescStmt = $conn->prepare($prescQuery);
    $prescStmt->bind_param("ii", $prescriptionId, $doctorId);
    $prescStmt->execute();
    $prescResult = $prescStmt->get_result();
    $prescription = $prescResult->fetch_assoc();
    
    if (!$prescription) {
        echo json_encode(['success' => false, 'message' => 'Prescription not found']);
        exit;
    }
    
    // Fetch medications
    $medQuery = "
        SELECT 
            medication_name,
            dosage,
            frequency,
            duration,
            instructions,
            quantity_prescribed
        FROM prescription_medications
        WHERE prescription_id = ?
        ORDER BY medication_id ASC
    ";
    
    $medStmt = $conn->prepare($medQuery);
    $medStmt->bind_param("i", $prescriptionId);
    $medStmt->execute();
    $medResult = $medStmt->get_result();
    $medications = $medResult->fetch_all(MYSQLI_ASSOC);
    
    // Format response
    $prescription['medications'] = $medications;
    $prescription['patient_gender'] = ucfirst($prescription['gender'] ?? '');
    
    echo json_encode([
        'success' => true,
        'prescription' => $prescription
    ]);
    
    // Close statements
    $doctorStmt->close();
    $prescStmt->close();
    $medStmt->close();
    
} catch (Exception $e) {
    error_log("Error fetching prescription: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>