<?php
session_start();
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

// Check if user is logged in and is a patient
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'patient') {
    // Handle AJAX requests differently
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // This is an AJAX request
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Session expired. Please log in again.',
            'redirect' => 'login.php'
        ]);
        exit();
    }
    
    // Check if it's a POST request (likely AJAX even without header)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Session expired. Please log in again.',
            'redirect' => 'login.php'
        ]);
        exit();
    }
    
    // Regular page request - redirect to login
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Auto Update Function
function autoUpdateAppointmentStatuses(mysqli $conn): void {
    try {
        
        // Only auto-cancel appointments where time has ACTUALLY passed
        $conn->query("
            UPDATE appointments 
            SET status = 'cancelled', 
                updated_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), ' [Auto-cancelled: appointment time passed without check-in]')
            WHERE status IN ('pending', 'confirmed')
            AND CONCAT(appointment_date, ' ', appointment_time) < NOW()
            AND checked_in_at IS NULL
        ");

        // Mark completed appointments (checked in AND appointment time has passed)
        $conn->query("
            UPDATE appointments 
            SET status = 'completed', updated_at = NOW()
            WHERE status IN ('pending', 'confirmed')
            AND checked_in_at IS NOT NULL
            AND CONCAT(appointment_date, ' ', appointment_time) < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        
    } catch (Exception $e) {
        // Silently fail - don't disrupt user experience
        error_log("Auto-update error: " . $e->getMessage());
    }
}

// Call Auto-update Immediately After Connection
autoUpdateAppointmentStatuses($conn);

// Ajax Handers
// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        case 'get_slots':
            echo json_encode(getAvailableSlots($conn, $_POST['doctor_id'], $_POST['date']));
            exit();

        case 'book_appointment':
            echo json_encode(bookAppointment($conn, $user_id, $_POST));
            exit();

        case 'cancel_appointment':
            echo json_encode(cancelAppointment($conn, $user_id, $_POST['appointment_id']));
            exit();

        case 'reschedule_appointment':
            echo json_encode(rescheduleAppointment($conn, $user_id, $_POST));
            exit();
        
        case 'confirm_appointment':
            echo json_encode(confirmAppointment($conn, $user_id, $_POST['appointment_id']));
            exit();

        case 'view_medical_record':
            echo json_encode(viewMedicalRecord($conn, $user_id, $_POST['appointment_id']));
            exit();

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit();
    }
}

//Functions
/* Get available time slots for a doctor on a specific date */
function getAvailableSlots(mysqli $conn, int $doctor_id, string $date): array {
    try {
        if (strtotime($date) < strtotime('today')) {
            return ['success' => false, 'message' => 'Cannot book appointments in the past'];
        }

        // Fetch the specific doctor's availability from database
        $stmt = $conn->prepare("SELECT available_days, available_hours FROM doctors WHERE doctor_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];

        $stmt->bind_param("i", $doctor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $doctor = $result->fetch_assoc();

        if (!$doctor) return ['success' => false, 'message' => 'Doctor not found'];
        
        // Check if doctor works on this specific Day of Week
        $day_of_week = date('l', strtotime($date));
        $available_days = json_decode($doctor['available_days'], true);
        if ($available_days && !in_array($day_of_week, $available_days)) {
            return ['success' => false, 'message' => 'Doctor is not available on this day'];
        }
        
        // PARSE DYNAMIC HOURS FROM DATABASE
        $working_hours = $doctor['available_hours']; 
        
        if (!empty($working_hours) && strpos($working_hours, '-') !== false) {
            list($start_str, $end_str) = explode('-', $working_hours);
            $start_time = strtotime($start_str);
            $end_time = strtotime($end_str);
        } else {
            // Fallback default if database column is empty
            $start_time = strtotime('09:00');
            $end_time = strtotime('17:00');
        }
        
        // Generate time slots based on the Doctor's specific start/end times
        $all_slots = [];
        // Increment by 1800 seconds (30 minutes)
        for ($time = $start_time; $time < $end_time; $time += 1800) {
            $all_slots[] = date('H:i:s', $time);
        }
        
        // Filter out slots that are already booked
        $stmt = $conn->prepare("
            SELECT appointment_time 
            FROM appointments 
            WHERE doctor_id = ? 
            AND appointment_date = ? 
            AND status NOT IN ('cancelled', 'expired')
        ");
        $stmt->bind_param("is", $doctor_id, $date);
        $stmt->execute();
        $booked_result = $stmt->get_result();
        
        $booked_slots = [];
        while ($row = $booked_result->fetch_assoc()) {
            $booked_slots[] = $row['appointment_time'];
        }
        
        // Remove booked slots from the generated list
        $available_slots = array_diff($all_slots, $booked_slots);
        
        $formatted_slots = array_map(function($slot) {
            return date('g:i A', strtotime($slot));
        }, array_values($available_slots));
        
        return [
            'success' => true, 
            'slots' => $formatted_slots,
            'slot_times' => array_values($available_slots)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* Book a new appointment */
function bookAppointment(mysqli $conn, int $user_id, array $data): array {
    try {
        $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $patient = $result->fetch_assoc();

        if (!$patient) return ['success' => false, 'message' => 'Patient profile not found'];

        $patient_id = $patient['patient_id'];
        $doctor_id = intval($data['doctor_id']);
        $appointment_date = $data['appointment_date'];
        $appointment_time = $data['appointment_time'];
        $reason = trim($data['reason']);
        $symptoms = trim($data['symptoms'] ?? '');
        
        // Validate inputs
        if (empty($doctor_id) || empty($appointment_date) || empty($appointment_time) || empty($reason)) {
            return ['success' => false, 'message' => 'Please fill in all required fields'];
        }
        
        // Convert time format if needed
        if (strpos($appointment_time, 'AM') !== false || strpos($appointment_time, 'PM') !== false) {
            $appointment_time = date('H:i:s', strtotime($appointment_time));
        }
        
        // Check if slot is still available
        $stmt = $conn->prepare("
            SELECT appointment_id 
            FROM appointments 
            WHERE doctor_id = ? 
            AND appointment_date = ? 
            AND appointment_time = ? 
            AND status NOT IN ('cancelled', 'expired')
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param("iss", $doctor_id, $appointment_date, $appointment_time);
        $stmt->execute();
        $check_result = $stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            return ['success' => false, 'message' => 'This time slot is no longer available'];
        }
        
        // Generate unique QR code
        $qr_code = 'APT-' . strtoupper(substr(md5(uniqid($patient_id . $doctor_id, true)), 0, 12));
        
        // Insert appointment
        $stmt = $conn->prepare("
            INSERT INTO appointments 
            (patient_id, doctor_id, appointment_date, appointment_time, status, reason, symptoms, qr_code, created_at) 
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param("iisssss", 
            $patient_id, 
            $doctor_id, 
            $appointment_date, 
            $appointment_time, 
            $reason, 
            $symptoms, 
            $qr_code
        );
        
        if ($stmt->execute()) {
            $appointment_id = $conn->insert_id;
            
            // Log QR code generation
            $stmt = $conn->prepare("
                INSERT INTO qr_code_history 
                (appointment_id, qr_code, generated_by, action, reason) 
                VALUES (?, ?, ?, 'generated', 'New appointment booking')
            ");
            if ($stmt) {
                $stmt->bind_param("isi", $appointment_id, $qr_code, $user_id);
                $stmt->execute();
            }
            
            // Create notification
            $notification_title = "Appointment Booked";
            $notification_message = "Your appointment has been booked for " . date('F j, Y', strtotime($appointment_date)) . " at " . date('g:i A', strtotime($appointment_time));
            
            $stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, notification_type, title, message, created_at) 
                VALUES (?, 'appointment', ?, ?, NOW())
            ");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $notification_title, $notification_message);
                $stmt->execute();
            }
            
            return [
                'success' => true, 
                'message' => 'Appointment booked successfully!',
                'appointment_id' => $appointment_id,
                'qr_code' => $qr_code
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to book appointment. Please try again.'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* Confirm Appointment */
function confirmAppointment(mysqli $conn, int $user_id, int $appointment_id): array {
    try {
        $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $patient = $result->fetch_assoc();

        if (!$patient) return ['success' => false, 'message' => 'Patient profile not found'];
        
        $patient_id = $patient['patient_id'];
        
        // Call stored procedure to confirm appointment
        $stmt = $conn->prepare("CALL sp_confirm_appointment(?, ?, @result, @message)");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param("ii", $appointment_id, $patient_id);
        $stmt->execute();
        
        // Get the output parameters
        $result = $conn->query("SELECT @result as result, @message as message");
        $output = $result->fetch_assoc();
        
        if ($output['result'] === 'SUCCESS') {
            // Create notification
            $notification_title = "Appointment Confirmed";
            $notification_message = "Your appointment has been confirmed! Please arrive 10 minutes early.";
            
            $stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, notification_type, title, message, created_at) 
                VALUES (?, 'appointment', ?, ?, NOW())
            ");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $notification_title, $notification_message);
                $stmt->execute();
            }
            
            return [
                'success' => true,
                'message' => $output['message']
            ];
        } else {
            return [
                'success' => false,
                'message' => $output['message']
            ];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* Cancel an appointment */
function cancelAppointment(mysqli $conn, int $user_id, int $appointment_id): array {
    try {
        $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];

        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $patient = $result->fetch_assoc();

        if (!$patient) return ['success' => false, 'message' => 'Patient profile not found'];
            
        // Verify appointment belongs to this patient and can be cancelled
        $stmt = $conn->prepare("
            SELECT appointment_id, appointment_date, appointment_time, status 
            FROM appointments 
            WHERE appointment_id = ? 
            AND patient_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param("ii", $appointment_id, $patient['patient_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $appointment = $result->fetch_assoc();
        
        if (!$appointment) {
            return ['success' => false, 'message' => 'Appointment not found'];
        }
        
        if ($appointment['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'Appointment is already cancelled'];
        }
        
        if ($appointment['status'] === 'completed') {
            return ['success' => false, 'message' => 'Cannot cancel a completed appointment'];
        }
        
        if ($appointment['status'] === 'expired') {
            return ['success' => false, 'message' => 'Cannot cancel an expired appointment'];
        }
        
        // Check if appointment date/time has passed
        $appointment_datetime = $appointment['appointment_date'] . ' ' . $appointment['appointment_time'];
        if (strtotime($appointment_datetime) < time()) {
            return ['success' => false, 'message' => 'Cannot cancel past appointments'];
        }
        
        // Calculate cancellation deadline (24 hours before appointment)
        $deadline = strtotime($appointment_datetime) - (24 * 60 * 60);
        
        if (time() > $deadline) {
            return ['success' => false, 'message' => 'Cannot cancel within 24 hours of appointment time. Please contact the clinic.'];
        }
        
        // Update appointment status
        $stmt = $conn->prepare("
            UPDATE appointments 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE appointment_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param("i", $appointment_id);
        
        if ($stmt->execute()) {
            // Create notification
            $notification_title = "Appointment Cancelled";
            $notification_message = "Your appointment for " . date('F j, Y', strtotime($appointment['appointment_date'])) . " at " . date('g:i A', strtotime($appointment['appointment_time'])) . " has been cancelled.";
            
            $stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, notification_type, title, message, created_at) 
                VALUES (?, 'appointment', ?, ?, NOW())
            ");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $notification_title, $notification_message);
                $stmt->execute();
            }
            
            return ['success' => true, 'message' => 'Appointment cancelled successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to cancel appointment'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* Reschedule Appointment */
function rescheduleAppointment(mysqli $conn, int $user_id, array $data): array {
    try {
        // Get patient ID
        $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $patient = $result->fetch_assoc();

        if (!$patient) return ['success' => false, 'message' => 'Patient profile not found'];

        $patient_id = $patient['patient_id'];
        $appointment_id = intval($data['appointment_id']);
        $new_date = $data['new_date'];
        $new_time = $data['new_time'];
        
        // Validate inputs
        if (empty($appointment_id) || empty($new_date) || empty($new_time)) {
            return ['success' => false, 'message' => 'Please fill in all required fields'];
        }
        
        // Convert time format if needed (from 12-hour to 24-hour)
        if (strpos($new_time, 'AM') !== false || strpos($new_time, 'PM') !== false) {
            $new_time = date('H:i:s', strtotime($new_time));
        }
        
        // Verify appointment belongs to this patient and can be rescheduled
        $stmt = $conn->prepare("
            SELECT appointment_id, doctor_id, appointment_date, appointment_time, status, qr_code
            FROM appointments 
            WHERE appointment_id = ? 
            AND patient_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param("ii", $appointment_id, $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $appointment = $result->fetch_assoc();
        
        if (!$appointment) {
            return ['success' => false, 'message' => 'Appointment not found'];
        }
        
        // Check if appointment can be rescheduled
        if ($appointment['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'Cannot reschedule a cancelled appointment'];
        }
        
        if ($appointment['status'] === 'completed') {
            return ['success' => false, 'message' => 'Cannot reschedule a completed appointment'];
        }
        
        // Check if the old appointment has already passed
        $old_datetime = $appointment['appointment_date'] . ' ' . $appointment['appointment_time'];
        if (strtotime($old_datetime) < time()) {
            return ['success' => false, 'message' => 'Cannot reschedule past appointments'];
        }
        
        // Check if new date/time is in the future
        $new_datetime = $new_date . ' ' . $new_time;
        if (strtotime($new_datetime) < time()) {
            return ['success' => false, 'message' => 'Cannot schedule appointments in the past'];
        }
        
        // Check if same as current - no change needed
        if ($appointment['appointment_date'] === $new_date && $appointment['appointment_time'] === $new_time) {
            return ['success' => false, 'message' => 'New date/time is the same as current appointment'];
        }
        
        // Check if new slot is available
        $doctor_id = $appointment['doctor_id'];
        $stmt = $conn->prepare("
            SELECT appointment_id 
            FROM appointments 
            WHERE doctor_id = ? 
            AND appointment_date = ? 
            AND appointment_time = ? 
            AND status NOT IN ('cancelled')
            AND appointment_id != ?
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param("issi", $doctor_id, $new_date, $new_time, $appointment_id);
        $stmt->execute();
        $check_result = $stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            return ['success' => false, 'message' => 'This time slot is no longer available. Please select another time.'];
        }
        
        // Update appointment with new date and time
        $stmt = $conn->prepare("
            UPDATE appointments 
            SET appointment_date = ?, 
                appointment_time = ?, 
                status = 'confirmed',
                updated_at = NOW() 
            WHERE appointment_id = ?
        ");
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param("ssi", $new_date, $new_time, $appointment_id);
        
        if ($stmt->execute()) {
            // Create notification
            $notification_title = "Appointment Rescheduled";
            $notification_message = "Your appointment has been rescheduled to " . 
                date('F j, Y', strtotime($new_date)) . " at " . 
                date('g:i A', strtotime($new_time));
            
            $stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, notification_type, title, message, created_at) 
                VALUES (?, 'appointment', ?, ?, NOW())
            ");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $notification_title, $notification_message);
                $stmt->execute();
            }
            
            // Log the reschedule in QR code history
            $stmt = $conn->prepare("
                INSERT INTO qr_code_history 
                (appointment_id, qr_code, generated_by, action, reason) 
                VALUES (?, ?, ?, 'regenerated', 'Appointment rescheduled')
            ");
            if ($stmt) {
                $qr_code = $appointment['qr_code'];
                $stmt->bind_param("isi", $appointment_id, $qr_code, $user_id);
                $stmt->execute();
            }
            
            return [
                'success' => true, 
                'message' => 'Appointment rescheduled successfully!',
                'appointment_id' => $appointment_id,
                'new_date' => $new_date,
                'new_time' => $new_time
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to reschedule appointment. Please try again.'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* View Medical Record */
function viewMedicalRecord(mysqli $conn, int $user_id, int $appointment_id): array {
    try {
        // Get patient ID
        $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $patient = $result->fetch_assoc();

        if (!$patient) return ['success' => false, 'message' => 'Patient profile not found'];
        
        $patient_id = $patient['patient_id'];
        
        // Verify appointment belongs to this patient
        $stmt = $conn->prepare("
            SELECT a.*, 
                   d.first_name as doctor_fname, 
                   d.last_name as doctor_lname,
                   d.specialization
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.appointment_id = ? 
            AND a.patient_id = ?
            AND a.status = 'completed'
        ");
        
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];
        
        $stmt->bind_param("ii", $appointment_id, $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $appointment = $result->fetch_assoc();
        
        if (!$appointment) {
            return ['success' => false, 'message' => 'Medical record not found or appointment not completed'];
        }
        
        // Get medical record
        $stmt = $conn->prepare("
            SELECT * FROM medical_records 
            WHERE appointment_id = ?
        ");
        
        if (!$stmt) return ['success' => false, 'message' => 'Database error'];
        
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $medical_record = $result->fetch_assoc();
        
        // Get prescriptions
        $stmt = $conn->prepare("
            SELECT p.*, pm.medication_name, pm.dosage, pm.frequency, pm.duration, pm.quantity_prescribed
            FROM prescriptions p
            LEFT JOIN prescription_medications pm ON p.prescription_id = pm.prescription_id
            WHERE p.appointment_id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param("i", $appointment_id);
            $stmt->execute();
            $prescriptions_result = $stmt->get_result();
            $prescriptions = [];
            while ($row = $prescriptions_result->fetch_assoc()) {
                $prescriptions[] = $row;
            }
        } else {
            $prescriptions = [];
        }
        
        return [
            'success' => true,
            'appointment' => $appointment,
            'medical_record' => $medical_record,
            'prescriptions' => $prescriptions
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Page Data
// Get patient information
$patient_query = "SELECT * FROM patients WHERE user_id = ?";
$stmt = $conn->prepare($patient_query);
if (!$stmt) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();

if (!$patient) {
    die("Patient profile not found. Please contact support.");
}

$patient_id = $patient['patient_id'];

// Get active doctors with their details
$doctors_query = "SELECT d.*, 
    COALESCE(AVG(r.rating), 0) as avg_rating,
    COUNT(DISTINCT r.review_id) as review_count
    FROM doctors d
    LEFT JOIN reviews r ON d.doctor_id = r.doctor_id
    WHERE d.status = 'active'
    GROUP BY d.doctor_id
    ORDER BY d.first_name, d.last_name";
$doctors_result = $conn->query($doctors_query);

// Get patient's appointments
$appointments_query = "SELECT a.*, 
    d.first_name as doctor_fname, 
    d.last_name as doctor_lname,
    d.specialization,
    d.profile_picture as doctor_picture,
    d.consultation_fee,  
    a.confirmation_deadline,
    a.confirmed_at
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 50";
$stmt = $conn->prepare($appointments_query);
if (!$stmt) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$appointments = $stmt->get_result();

// Get available specializations
$specializations_query = "SELECT DISTINCT specialization FROM doctors WHERE status = 'active' AND specialization IS NOT NULL ORDER BY specialization";
$specializations = $conn->query($specializations_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - Healthcare System</title>
    <link rel="stylesheet" href="appointment.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="header-content">
                    <h1>📆 Book an Appointment</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Schedule your medical consultation with our healthcare professionals</p>
                </div>
            </div>
            <div class="search-section">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchDoctor" placeholder="Search doctors by name or specialization...">
                </div>
                <div class="filter-group">
                    <select id="filterSpecialization" class="filter-select">
                        <option value="">All Specializations</option>
                        <?php while ($spec = $specializations->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($spec['specialization']); ?>">
                                <?php echo htmlspecialchars($spec['specialization']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <select id="sortBy" class="filter-select">
                        <option value="name">Sort by Name</option>
                        <option value="rating">Sort by Rating</option>
                        <option value="experience">Sort by Experience</option>
                    </select>
                </div>
            </div>

            <div class="doctors-grid" id="doctorsGrid">
                <?php while ($doctor = $doctors_result->fetch_assoc()): ?>
                <div class="doctor-card" 
                     data-specialization="<?php echo htmlspecialchars($doctor['specialization'] ?? ''); ?>"
                     data-name="<?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>"
                     data-rating="<?php echo $doctor['avg_rating']; ?>"
                     data-experience="<?php echo $doctor['experience_years'] ?? 0; ?>">
                    <div class="doctor-image">
                        <?php 
                        $profilePicturePath = !empty($doctor['profile_picture']) ? $doctor['profile_picture'] : '';
                        
                        if (!empty($profilePicturePath)) {
                            $cleanPath = ltrim($profilePicturePath, './');
                            $fullPath = __DIR__ . '/' . $cleanPath;
                            if (file_exists($fullPath)) {
                                $displayPath = $cleanPath;
                            } else {
                                $fullPath = __DIR__ . '/../' . $cleanPath;
                                if (file_exists($fullPath)) {
                                    $displayPath = '../' . $cleanPath;
                                } else {
                                    $displayPath = null;
                                }
                            }
                        } else {
                            $displayPath = null;
                        }
                        ?>
                        
                        <?php if ($displayPath): ?>
                            <img src="<?php echo htmlspecialchars($displayPath); ?>" 
                                 alt="Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>"
                                 onerror="this.parentElement.innerHTML='<div class=\'default-avatar\'><i class=\'fas fa-user-md\'></i></div><?php if ($doctor['avg_rating'] >= 4): ?><span class=\'badge-top\'>Top Rated</span><?php endif; ?>';">
                        <?php else: ?>
                            <div class="default-avatar">
                                <i class="fas fa-user-md"></i>
                            </div>
                        <?php endif; ?>
                        <?php if ($doctor['avg_rating'] >= 4): ?>
                            <span class="badge-top">Top Rated</span>
                        <?php endif; ?>
                    </div>
                    <div class="doctor-info">
                        <h3>Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?></h3>
                        <p class="specialization">
                            <i class="fas fa-stethoscope"></i>
                            <?php echo htmlspecialchars($doctor['specialization'] ?? 'General Practice'); ?>
                        </p>
                        <div class="doctor-meta">
                            <span class="rating">
                                <i class="fas fa-star"></i>
                                <?php echo number_format($doctor['avg_rating'], 1); ?>
                                <small>(<?php echo $doctor['review_count']; ?> reviews)</small>
                            </span>
                            <?php if ($doctor['experience_years']): ?>
                                <span class="experience">
                                    <i class="fas fa-certificate"></i>
                                    <?php echo $doctor['experience_years']; ?> years
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($doctor['consultation_fee']): ?>
                            <p class="fee">
                                <i class="fas fa-dollar-sign"></i>
                                RM <?php echo number_format($doctor['consultation_fee'], 2); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($doctor['bio']): ?>
                            <p class="bio"><?php echo htmlspecialchars(substr($doctor['bio'], 0, 100)); ?>...</p>
                        <?php endif; ?>
                        <button class="btn btn-primary book-btn" 
                                onclick="openBookingModal(<?php echo $doctor['doctor_id']; ?>, '<?php echo addslashes(htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name'])); ?>', '<?php echo addslashes(htmlspecialchars($doctor['specialization'] ?? '')); ?>')">
                            <i class="fas fa-calendar-plus"></i> Book Appointment
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <!-- My Appointments Table Section -->
            <div class="appointments-section">
                <div class="section-header">
                    <h2>📆 My Appointments</h2>
                </div>
                
                <div class="appointments-tabs">
                    <button class="tab-btn active" onclick="filterAppointments('all')">
                        <i class="fas fa-list"></i> All
                    </button>
                    <button class="tab-btn" onclick="filterAppointments('pending')">
                        <i class="fas fa-clock"></i> Pending
                    </button>
                    <button class="tab-btn" onclick="filterAppointments('confirmed')">
                        <i class="fas fa-check-circle"></i> Confirmed
                    </button>
                    <button class="tab-btn" onclick="filterAppointments('completed')">
                        <i class="fas fa-check-double"></i> Completed
                    </button>
                    <button class="tab-btn" onclick="filterAppointments('cancelled')">
                        <i class="fas fa-times-circle"></i> Cancelled
                    </button>
                </div>
                                
                <div class="table-container">
                    <table class="appointments-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Doctor</th>
                                <th>Date & Time</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>QR Code</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody id="appointmentsTableBody">
                            <?php 
                            $appointments->data_seek(0);
                            $hasAppointments = false;
                            
                            // START OF THE LOOP
                            while ($apt = $appointments->fetch_assoc()): 
                                $hasAppointments = true;
                            ?>
                            <tr data-status="<?php echo $apt['status']; ?>">
                                <td>
                                    <code style="background: #e0f2fe; padding: 6px 12px; border-radius: 8px; font-weight: 700; color: #0369a1;">
                                        #<?php echo $apt['appointment_id']; ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="doctor-mini">
                                        <?php 
                                        $doctorPicPath = !empty($apt['doctor_picture']) ? $apt['doctor_picture'] : '';
                                        if (!empty($doctorPicPath)) {
                                            $cleanDoctorPath = ltrim($doctorPicPath, './');
                                            $fullDoctorPath = __DIR__ . '/' . $cleanDoctorPath;
                                            if (file_exists($fullDoctorPath)) {
                                                $displayDoctorPath = $cleanDoctorPath;
                                            } else {
                                                $fullDoctorPath = __DIR__ . '/../' . $cleanDoctorPath;
                                                $displayDoctorPath = file_exists($fullDoctorPath) ? '../' . $cleanDoctorPath : null;
                                            }
                                        } else {
                                            $displayDoctorPath = null;
                                        }
                                        ?>
                                        <?php if ($displayDoctorPath): ?>
                                            <img src="<?php echo htmlspecialchars($displayDoctorPath); ?>" 
                                                alt="Dr. <?php echo htmlspecialchars($apt['doctor_fname'] . ' ' . $apt['doctor_lname']); ?>"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="mini-avatar" style="display: none;"><i class="fas fa-user-md"></i></div>
                                        <?php else: ?>
                                            <div class="mini-avatar"><i class="fas fa-user-md"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <strong>Dr. <?php echo htmlspecialchars($apt['doctor_fname'] . ' ' . $apt['doctor_lname']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($apt['specialization']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?></strong><br>
                                    <small><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($apt['reason']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($apt['status']); ?>">
                                        <?php echo ucfirst($apt['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($apt['qr_code'])): ?>
                                        <code class="qr-code">
                                            <?php echo htmlspecialchars(substr($apt['qr_code'], 0, 12)); ?>...
                                        </code>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <?php 
                                        $appointment_datetime = strtotime($apt['appointment_date'] . ' ' . $apt['appointment_time']);
                                        $now = time();
                                        $cancellation_deadline = $appointment_datetime - (24 * 60 * 60);
                                        
                                        $needs_confirmation = ($apt['status'] === 'pending' && empty($apt['confirmed_at']) && !empty($apt['confirmation_deadline']));
                                        $confirmation_expired = ($needs_confirmation && strtotime($apt['confirmation_deadline']) < $now);
                                        $confirmation_deadline_time = !empty($apt['confirmation_deadline']) ? strtotime($apt['confirmation_deadline']) : null;
                                        
                                        $can_reschedule = ($appointment_datetime > $now) && in_array($apt['status'], ['pending', 'confirmed']) && !$confirmation_expired;
                                        $can_cancel = ($appointment_datetime > $now) && ($now < $cancellation_deadline) && in_array($apt['status'], ['pending', 'confirmed']) && !$confirmation_expired;
                                        ?>

                                        <?php if ($needs_confirmation && !$confirmation_expired): ?>
                                            <button class="btn-action btn-confirm" onclick="confirmAppointmentAction(<?php echo $apt['appointment_id']; ?>)" title="Confirm Appointment">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($apt['qr_code']) && $apt['status'] === 'confirmed'): ?>
                                            <button class="btn-action btn-qr" onclick="viewQRCode('<?php echo $apt['qr_code']; ?>', <?php echo $apt['appointment_id']; ?>)" title="View QR Code">
                                                <i class="fas fa-qrcode"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($apt['status'] === 'completed'): ?>
                                            <button class="btn-action btn-view" onclick="viewMedicalRecord(<?php echo $apt['appointment_id']; ?>)" title="View Medical Record">
                                                <i class="fas fa-file-medical"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($can_reschedule): ?>
                                            <button class="btn-action btn-reschedule" onclick="openRescheduleModal(<?php echo $apt['appointment_id']; ?>, <?php echo $apt['doctor_id']; ?>, '<?php echo addslashes($apt['doctor_fname'] . ' ' . $apt['doctor_lname']); ?>', '<?php echo addslashes($apt['specialization']); ?>', '<?php echo $apt['appointment_date']; ?>', '<?php echo $apt['appointment_time']; ?>')" title="Reschedule">
                                                <i class="fas fa-calendar-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_cancel): ?>
                                            <button class="btn-action btn-cancel" onclick="confirmCancelAppointment(<?php echo $apt['appointment_id']; ?>)" title="Cancel">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; // THIS CLOSES THE WHILE LOOP ONCE ?>
                            
                            <?php if (!$hasAppointments): ?>
                            <tr>
                                <td colspan="7" class="no-appointments">
                                    <i class="fas fa-calendar-times"></i>
                                    <h3>No Appointments Found</h3>
                                    <p>You haven't booked any appointments yet.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Modal -->
        <div id="bookingModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-calendar-check"></i> Book Appointment</h2>
                    <span class="close" onclick="closeBookingModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="doctor-summary" id="doctorSummary"></div>
                    <form id="bookingForm">
                        <input type="hidden" name="action" value="book_appointment">
                        <input type="hidden" id="doctorId" name="doctor_id">
                        <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Appointment Date *</label>
                            <input type="date" id="appointmentDate" name="appointment_date" required>
                            <small>Select your preferred date</small>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Available Time Slots *</label>
                            <div id="timeSlots" class="time-slots">
                                <p class="loading">Select a date to view available slots</p>
                            </div>
                            <input type="hidden" id="selectedTime" name="appointment_time" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-clipboard"></i> Reason for Visit *</label>
                            <input type="text" name="reason" placeholder="e.g., Regular checkup, Follow-up, Consultation" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-notes-medical"></i> Symptoms (Optional)</label>
                            <textarea name="symptoms" rows="4" placeholder="Describe your symptoms or concerns..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeBookingModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> Confirm Booking
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reschedule Appointment Modal -->
        <div id="rescheduleModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-calendar-alt"></i> Reschedule Appointment</h2>
                    <span class="close" onclick="closeRescheduleModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="doctor-summary" id="rescheduleSummary"></div>
                    
                    <form id="rescheduleForm">
                        <input type="hidden" name="action" value="reschedule_appointment">
                        <input type="hidden" id="rescheduleAppointmentId" name="appointment_id">
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> New Appointment Date *</label>
                            <input type="date" id="rescheduleDate" name="new_date" required>
                            <small>Select your new preferred date</small>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Available Time Slots *</label>
                            <div id="rescheduleTimeSlots" class="time-slots">
                                <p class="loading">Select a date to view available slots</p>
                            </div>
                            <input type="hidden" id="rescheduleSelectedTime" name="new_time" required>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeRescheduleModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> Confirm Reschedule
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- QR Code Modal -->
        <div id="qrModal" class="modal">
            <div class="modal-content modal-sm">
                <div class="modal-header">
                    <h2><i class="fas fa-qrcode"></i> Appointment QR Code</h2>
                    <span class="close" onclick="closeQRModal()">&times;</span>
                </div>
                <div class="modal-body text-center">
                    <div id="qrCodeDisplay"></div>
                    <p class="qr-instructions">Show this QR code at the clinic for check-in</p>
                    <button class="btn btn-primary" onclick="downloadQR()">
                        <i class="fas fa-download"></i> Download QR Code
                    </button>
                </div>
            </div>
        </div>

        <!-- Cancel Confirmation Modal -->
        <div id="cancelModal" class="modal">
            <div class="modal-content modal-sm">
                <div class="modal-header" style="background: var(--gradient-danger);">
                    <h2><i class="fas fa-exclamation-triangle"></i> Cancel Appointment</h2>
                    <span class="close" onclick="closeCancelModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="cancel-warning">
                        <i class="fas fa-info-circle"></i>
                        <h3>Are you sure you want to cancel this appointment?</h3>
                        <p>This action cannot be undone. You will need to book a new appointment if you change your mind.</p>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">
                            <i class="fas fa-arrow-left"></i> No, Keep Appointment
                        </button>
                        <button type="button" class="btn btn-danger" onclick="executeCancelAppointment()">
                            <i class="fas fa-times-circle"></i> Yes, Cancel It
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Medical Record Modal -->
        <div id="medicalRecordModal" class="modal">
            <div class="modal-content modal-lg">
                <div class="modal-header">
                    <h2><i class="fas fa-file-medical"></i> Medical Record</h2>
                    <span class="close" onclick="closeMedicalRecordModal()">&times;</span>
                </div>
                <div class="modal-body" id="medicalRecordContent">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i> Loading medical record...
                    </div>
                </div>
            </div>
        </div>
        <!-- Notification Toast -->
        <div id="toast" class="toast"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="appointment.js"></script>
</body>
</html>