<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();
$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);

if (!$paymentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit;
}

try {

    $patStmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
    $patStmt->bind_param("i", $userId);
    $patStmt->execute();
    $patientData = $patStmt->get_result()->fetch_assoc();
    
    if (!$patientData) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT p.*, 
               CONCAT(pat.first_name, ' ', pat.last_name) as patient_name, 
               u.email as patient_email, 
               pat.phone as patient_phone,
               COALESCE(CONCAT(d.first_name, ' ', d.last_name), 'N/A') as doctor_name, 
               COALESCE(d.specialization, 'General') as specialization, 
               a.appointment_date, 
               a.appointment_time
        FROM payments p
        JOIN patients pat ON p.patient_id = pat.patient_id
        JOIN users u ON pat.user_id = u.user_id
        LEFT JOIN appointments a ON p.appointment_id = a.appointment_id
        LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE p.payment_id = ? AND p.patient_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $paymentId, $patientData['patient_id']);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit;
    }
    
    $itemStmt = $conn->prepare("SELECT * FROM payment_items WHERE payment_id = ? ORDER BY item_type");
    $itemStmt->bind_param("i", $paymentId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($items) && !empty($payment['payment_details'])) {
        $jsonDetails = json_decode($payment['payment_details'], true);
        if (is_array($jsonDetails)) {
            foreach ($jsonDetails as $row) {
                $items[] = [
                    'item_name' => $row['item'] ?? 'General Service',
                    'item_type' => 'other',
                    'quantity' => $row['quantity'] ?? 1,
                    'unit_price' => $row['price'] ?? 0,
                    'total_price' => ($row['quantity'] ?? 1) * ($row['price'] ?? 0)
                ];
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'payment' => array_merge($payment, ['items' => $items])
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}