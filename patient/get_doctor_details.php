<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

header('Content-Type: application/json');

// Get the MySQLi connection from your singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Get doctor_id from request
$doctorId = filter_input(INPUT_GET, 'doctor_id', FILTER_VALIDATE_INT);

if (!$doctorId) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid doctor ID'
    ]);
    exit;
}

try {
    // Fetch doctor details with statistics
    $stmt = $conn->prepare("
        SELECT 
            d.*,
            COUNT(DISTINCT a.appointment_id) as total_appointments,
            COALESCE(AVG(r.rating), 0) as avg_rating,
            COUNT(DISTINCT r.review_id) as total_reviews
        FROM doctors d
        LEFT JOIN appointments a ON d.doctor_id = a.doctor_id AND a.status = 'completed'
        LEFT JOIN reviews r ON d.doctor_id = r.doctor_id
        WHERE d.doctor_id = ? AND d.status = 'active'
        GROUP BY d.doctor_id
        LIMIT 1
    ");
    
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $doctor = $result->fetch_assoc();
    
    if (!$doctor) {
        echo json_encode([
            'success' => false,
            'message' => 'Doctor not found'
        ]);
        exit;
    }
    
    // Fetch recent reviews
    $reviewsStmt = $conn->prepare("
        SELECT 
            r.*,
            p.first_name as patient_fname,
            p.last_name as patient_lname,
            CONCAT(SUBSTRING(p.first_name, 1, 1), '. ', p.last_name) as patient_name
        FROM reviews r
        JOIN patients p ON r.patient_id = p.patient_id
        WHERE r.doctor_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    
    $reviewsStmt->bind_param("i", $doctorId);
    $reviewsStmt->execute();
    $reviewsResult = $reviewsStmt->get_result();
    $reviews = $reviewsResult->fetch_all(MYSQLI_ASSOC);
    
    // Parse available days from JSON
    $availableDays = [];
    if (!empty($doctor['available_days'])) {
        $availableDays = json_decode($doctor['available_days'], true);
        if (!is_array($availableDays)) {
            $availableDays = [];
        }
    }
    
    // Format profile picture path
    $profilePicture = null;
    if (!empty($doctor['profile_picture'])) {
        $profilePicture = '../' . $doctor['profile_picture'];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'doctor' => [
            'doctor_id' => $doctor['doctor_id'],
            'name' => $doctor['first_name'] . ' ' . $doctor['last_name'],
            'first_name' => $doctor['first_name'],
            'last_name' => $doctor['last_name'],
            'specialization' => $doctor['specialization'] ?? 'General Practitioner',
            'license_number' => $doctor['license_number'],
            'phone' => $doctor['phone'],
            'address' => $doctor['address'] ?? null,
            'email' => $doctor['email'] ?? null,
            'bio' => $doctor['bio'] ?? null,
            'qualifications' => $doctor['qualifications'] ?? null,
            'experience_years' => (int)($doctor['experience_years'] ?? 0),
            'consultation_fee' => (float)$doctor['consultation_fee'],
            'available_days' => $availableDays,
            'available_hours' => $doctor['available_hours'] ?? null,
            'profile_picture' => $profilePicture,
            'avg_rating' => round((float)$doctor['avg_rating'], 1),
            'total_reviews' => (int)$doctor['total_reviews'],
            'total_appointments' => (int)$doctor['total_appointments']
        ],
        'reviews' => array_map(function($review) {
            return [
                'review_id' => $review['review_id'],
                'patient_name' => $review['patient_name'],
                'rating' => (int)$review['rating'],
                'review_text' => $review['review_text'],
                'created_at' => $review['created_at']
            ];
        }, $reviews)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error fetching doctor details: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching doctor details'
    ]);
}