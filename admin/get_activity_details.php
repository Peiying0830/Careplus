<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

header('Content-Type: application/json');

$conn = Database::getInstance()->getConnection();
$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if (!$type || !$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    if ($type === 'appointment') {
        $stmt = $conn->prepare("
            SELECT 
                a.*,
                CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                p.phone as patient_phone,
                p.profile_picture as patient_image, -- 这里修改了：从 profile_picture 读取
                CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
                d.specialization,
                d.profile_picture as doctor_image  -- 这里修改了：从 profile_picture 读取
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.appointment_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            echo json_encode(['success' => true, 'details' => $result]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        }
        
    } elseif ($type === 'doctor') {
        $stmt = $conn->prepare("
            SELECT 
                d.*,
                d.profile_picture as profile_image, -- 保持别名，确保 JS 逻辑不变
                u.email,
                u.status,
                u.created_at
            FROM doctors d
            JOIN users u ON d.user_id = u.user_id
            WHERE d.doctor_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            echo json_encode(['success' => true, 'details' => $result]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Doctor not found']);
        }
        
    } elseif ($type === 'patient') {
        $stmt = $conn->prepare("
            SELECT 
                p.*,
                p.profile_picture as profile_image, -- 保持别名，确保 JS 逻辑不变
                u.email,
                u.status,
                u.created_at
            FROM patients p
            JOIN users u ON p.user_id = u.user_id
            WHERE p.patient_id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            echo json_encode(['success' => true, 'details' => $result]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Patient not found']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}