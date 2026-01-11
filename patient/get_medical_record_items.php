<?php
session_start();
require_once __DIR__ . '/../config.php';
$conn = Database::getInstance()->getConnection();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit();
}

$userId = $_SESSION['user_id'];
$appointmentId = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;

try {
    // Get the patient_id linked to this user
    $pStmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
    $pStmt->bind_param("i", $userId);
    $pStmt->execute();
    $patient = $pStmt->get_result()->fetch_assoc();
    $patientId = $patient['patient_id'] ?? 0;

    // Get the appointment and consultation fee
    $stmt = $conn->prepare("
        SELECT a.appointment_id, d.consultation_fee, d.first_name, d.last_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_id = ? AND a.patient_id = ?
    ");
    $stmt->bind_param("ii", $appointmentId, $patientId);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();

    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found for this patient']);
        exit();
    }

    $items = [];
    
    // Always add the Consultation Fee if the appointment exists
    $items[] = [
        'item_type' => 'consultation',
        'item_name' => 'Medical Consultation',
        'description' => 'Professional fee for Dr. ' . $appointment['last_name'],
        'quantity' => 1,
        'unit_price' => (float)$appointment['consultation_fee']
    ];

    // Get medications. 
    // IMPORTANT: mc.unit_price must match your table column name
    $mStmt = $conn->prepare("
        SELECT pm.medication_name, pm.dosage, pm.quantity_prescribed, 
               COALESCE(mc.unit_price, 0.00) as unit_price
        FROM prescriptions p
        JOIN prescription_medications pm ON p.prescription_id = pm.prescription_id
        LEFT JOIN medicine_catalog mc ON pm.medication_name = mc.medicine_name
        WHERE p.appointment_id = ?
    ");
    $mStmt->bind_param("i", $appointmentId);
    $mStmt->execute();
    $meds = $mStmt->get_result();

    while ($row = $meds->fetch_assoc()) {
        $items[] = [
            'item_type' => 'medicine',
            'item_name' => $row['medication_name'],
            'description' => $row['dosage'],
            'quantity' => (int)$row['quantity_prescribed'],
            'unit_price' => (float)$row['unit_price']
        ];
    }

    echo json_encode(['success' => true, 'items' => $items]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}