<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Get prescription ID from request
$prescriptionId = filter_input(INPUT_GET, 'prescription_id', FILTER_VALIDATE_INT);

if (!$prescriptionId) {
    echo json_encode(['success' => false, 'message' => 'Invalid prescription ID']);
    exit;
}

try {
    // Get patient ID for security
    $pStmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
    $pStmt->bind_param("i", $userId);
    $pStmt->execute();
    $patient = $pStmt->get_result()->fetch_assoc();
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }
    
    // Fetch main prescription details
    $stmt = $conn->prepare("
        SELECT 
            pr.*,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            u.email as patient_email,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
            d.specialization,
            d.phone as doctor_phone,
            d.profile_picture as doctor_profile_picture,
            d.gender as doctor_gender
        FROM prescriptions pr
        JOIN patients p ON pr.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id
        JOIN doctors d ON pr.doctor_id = d.doctor_id
        WHERE pr.prescription_id = ? AND pr.patient_id = ?
    ");
    $stmt->bind_param("ii", $prescriptionId, $patient['patient_id']);
    $stmt->execute();
    $prescription = $stmt->get_result()->fetch_assoc();
    
    if (!$prescription) {
        echo json_encode(['success' => false, 'message' => 'Prescription not found']);
        exit;
    }
    
    // Fetch all medications
    $medsStmt = $conn->prepare("
        SELECT * FROM prescription_medications 
        WHERE prescription_id = ?
        ORDER BY medication_id ASC
    ");
    $medsStmt->bind_param("i", $prescriptionId);
    $medsStmt->execute();
    $prescription['medications'] = $medsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['success' => true, 'prescription' => $prescription]);
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch details']);
}