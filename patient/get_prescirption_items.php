<?php
session_start();
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'patient') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;

if (!$appointment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
    exit();
}

try {
    // Get patient ID
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit();
    }
    
    // Verify appointment belongs to patient and is completed
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.status, d.consultation_fee
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_id = ? 
        AND a.patient_id = ?
        AND a.status = 'completed' -- DELETE THIS LINE
    ");
    $stmt->bind_param("ii", $appointment_id, $patient['patient_id']);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or not completed']);
        exit();
    }
    
    $items = [];
    $subtotal = 0;
    
    // Add consultation fee
    $consultation_fee = floatval($appointment['consultation_fee']);
    $items[] = [
        'item_type' => 'consultation',
        'item_name' => 'Medical Consultation',
        'description' => 'Doctor consultation fee',
        'quantity' => 1,
        'unit_price' => $consultation_fee
    ];
    $subtotal += $consultation_fee;
    
    // Get REAL prescription medications
    $stmt = $conn->prepare("
        SELECT 
            pm.medication_name,
            pm.dosage,
            pm.frequency,
            pm.duration,
            pm.quantity_prescribed,
            COALESCE(mc.unit_price, 0.00) as unit_price -- Use unit_price, NOT price_per_unit
        FROM prescriptions p
        JOIN prescription_medications pm ON p.prescription_id = pm.prescription_id
        LEFT JOIN medicine_catalog mc ON pm.medication_name = mc.medicine_name
        WHERE p.appointment_id = ? 
        AND p.status = 'active'
    ");
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $medications = $stmt->get_result();
    
    // Add each medication
    while ($med = $medications->fetch_assoc()) {
        $quantity = intval($med['quantity_prescribed']);
        $unit_price = floatval($med['unit_price']);
        
        $items[] = [
            'item_type' => 'medicine',
            'item_name' => $med['medication_name'],
            'description' => $med['dosage'] . ' - ' . $med['frequency'] . ' for ' . $med['duration'],
            'quantity' => $quantity,
            'unit_price' => $unit_price
        ];
        
        $subtotal += ($quantity * $unit_price);
    }
    
    // Calculate tax and total
    $tax = $subtotal * 0.06; // 6% SST
    $total = $subtotal + $tax;
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => $total
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading prescription: ' . $e->getMessage()
    ]);
}
?>