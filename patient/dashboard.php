<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

// Get the MySQLi connection from your singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch patient info
$patientStmt = $conn->prepare("
    SELECT p.*, u.email 
    FROM patients p 
    JOIN users u ON p.user_id = u.user_id 
    WHERE p.user_id = ? 
    LIMIT 1
");
$patientStmt->bind_param("i", $userId);
$patientStmt->execute();
$patientResult = $patientStmt->get_result();
$patient = $patientResult->fetch_assoc();

if (!$patient) {
    redirect('patient/profile.php');
}

$patientId = $patient['patient_id'];
$patientFullName = trim($patient['first_name'] . ' ' . $patient['last_name']);
$today = date('Y-m-d');

// Fetch upcoming appointments - LIMIT 3
$upcomingStmt = $conn->prepare("
    SELECT a.*, 
           d.first_name as doctor_fname, 
           d.last_name as doctor_lname,
           d.specialization,
           d.consultation_fee
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.patient_id = ? 
    AND a.appointment_date >= ?
    AND a.status IN ('confirmed', 'pending')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 3
");
$upcomingStmt->bind_param("is", $patientId, $today);
$upcomingStmt->execute();
$upcomingAppointments = $upcomingStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Count appointments by status
$statsStmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'confirmed' AND appointment_date >= ? THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        COUNT(*) as total
    FROM appointments 
    WHERE patient_id = ?
");
$statsStmt->bind_param("si", $today, $patientId);
$statsStmt->execute();
$appointmentStats = $statsStmt->get_result()->fetch_assoc();

// Fetch recent medical records - LIMIT 3
$medicalStmt = $conn->prepare("
    SELECT a.*, 
           d.first_name as doctor_fname, 
           d.last_name as doctor_lname,
           d.specialization
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.patient_id = ? 
    AND a.status = 'completed'
    ORDER BY a.appointment_date DESC
    LIMIT 3
");
$medicalStmt->bind_param("i", $patientId);
$medicalStmt->execute();
$recentMedicalRecords = $medicalStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch available doctors
$availableStmt = $conn->prepare("
    SELECT doctor_id, first_name, last_name, specialization, consultation_fee, profile_picture
    FROM doctors 
    ORDER BY RAND()
    LIMIT 3
");
$availableStmt->execute();
$availableDoctors = $availableStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get today's appointments
$todayApptStmt = $conn->prepare("
    SELECT a.*, 
           d.first_name as doctor_fname, 
           d.last_name as doctor_lname,
           d.specialization
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.patient_id = ? 
    AND a.appointment_date = ?
    AND a.status IN ('confirmed', 'pending')
    ORDER BY a.appointment_time ASC
");
$todayApptStmt->bind_param("is", $patientId, $today);
$todayApptStmt->execute();
$todayAppointments = $todayApptStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch notifications
$notifications = [];
try {
    $notifStmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        AND is_read = 0
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $notifStmt->bind_param("i", $userId);
    $notifStmt->execute();
    $notifications = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Notifications error: " . $e->getMessage());
}

// Fetch recent payments
$payStmt = $conn->prepare("
    SELECT p.*, 
           a.appointment_date,
           d.first_name as doctor_fname, 
           d.last_name as doctor_lname,
           d.specialization
    FROM payments p
    LEFT JOIN appointments a ON p.appointment_id = a.appointment_id
    LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE p.patient_id = ?
    ORDER BY p.payment_date DESC
    LIMIT 2
");
$payStmt->bind_param("i", $patientId);
$payStmt->execute();
$recentPayments = $payStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Payment statistics
$payStatsStmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END) as pending_amount
    FROM payments 
    WHERE patient_id = ?
");
$payStatsStmt->bind_param("i", $patientId);
$payStatsStmt->execute();
$paymentStats = $payStatsStmt->get_result()->fetch_assoc();

// Recent prescriptions
$prescStmt = $conn->prepare("
    SELECT pr.*, 
           d.first_name as doctor_fname, 
           d.last_name as doctor_lname,
           d.specialization,
           (SELECT COUNT(*) FROM prescription_medications WHERE prescription_id = pr.prescription_id) as medication_count
    FROM prescriptions pr
    JOIN doctors d ON pr.doctor_id = d.doctor_id
    WHERE pr.patient_id = ?
    ORDER BY pr.prescription_date DESC
    LIMIT 2
");
$prescStmt->bind_param("i", $patientId);
$prescStmt->execute();
$recentPrescriptions = $prescStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Totals for "View All" buttons
try {
    // Upcoming Count
    $upCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE patient_id = ? AND appointment_date >= ? AND status IN ('confirmed', 'pending')");
    $upCountStmt->bind_param("is", $patientId, $today);
    $upCountStmt->execute();
    $upcomingCount = $upCountStmt->get_result()->fetch_assoc()['count'];

    // Doctors Count
    $drCountRes = $conn->query("SELECT COUNT(*) as count FROM doctors");
    $doctorsCount = $drCountRes->fetch_assoc()['count'];

    // Medical Records Count
    $recCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE patient_id = ? AND status = 'completed'");
    $recCountStmt->bind_param("i", $patientId);
    $recCountStmt->execute();
    $recordsCount = $recCountStmt->get_result()->fetch_assoc()['count'];

    // Payments Count
    $payCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM payments WHERE patient_id = ?");
    $payCountStmt->bind_param("i", $patientId);
    $payCountStmt->execute();
    $paymentsCount = $payCountStmt->get_result()->fetch_assoc()['count'];

    // Unread Notifications
    $unCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $unCountStmt->bind_param("i", $userId);
    $unCountStmt->execute();
    $unreadCount = $unCountStmt->get_result()->fetch_assoc()['count'];

    // Prescription Count
    $prCountStmt = $conn->prepare("SELECT COUNT(*) as total_prescriptions FROM prescriptions WHERE patient_id = ?");
    $prCountStmt->bind_param("i", $patientId);
    $prCountStmt->execute();
    // Fetch as an associative array so $prescriptionCount['total_prescriptions'] works
    $prescriptionCount = $prCountStmt->get_result()->fetch_assoc();

} catch (Exception $e) {
    // Initialize as an array in case of error to prevent "offset on null" warning
    $upcomingCount = 0; $doctorsCount = 0; $recordsCount = 0; $paymentsCount = 0; $unreadCount = 0; 
    $prescriptionCount = ['total_prescriptions' => 0]; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <main class="container">
            <!-- Welcome Section -->
            <div class="welcome-section fade-in">
                <div class="welcome-text">
                    <h1 data-patient-name="<?php echo htmlspecialchars($patientFullName); ?>">
                        Welcome back, <?php echo htmlspecialchars($patientFullName); ?>! 👋
                    </h1>
                    <p>Here's what's happening with your health today 💐 </p>
                </div>
                <div class="date-time">
                    <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="current-time" id="live-clock"><?php echo date('h:i A'); ?></div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid fade-in">
                <div class="stat-card" data-target="appointment.php">
                    <div class="stat-icon upcoming">
                        <span>📅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['upcoming'] ?? 0; ?></h3>
                        <p>Upcoming Appointments</p>
                    </div>
                </div>
                
                <div class="stat-card" data-target="appointment.php">
                    <div class="stat-icon pending">
                        <span>⏳</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['pending'] ?? 0; ?></h3>
                        <p>Pending Confirmation</p>
                    </div>
                </div>
                
                <div class="stat-card" data-target="medicalRecords.php">
                    <div class="stat-icon completed">
                        <span>✅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['completed'] ?? 0; ?></h3>
                        <p>Completed Visits</p>
                    </div>
                </div>
                
                <div class="stat-card" data-target="appointment.php">
                    <div class="stat-icon cancelled">
                        <span>❌</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['cancelled'] ?? 0; ?></h3>
                        <p>Cancelled Appointments</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions fade-in">
                <a href="appointment.php" class="action-card">
                    <div class="action-icon">
                        <span>➕</span>
                    </div>
                    <div class="action-text">
                        <h3>Book Appointment</h3>
                        <p>Schedule a new doctor visit</p>
                    </div>
                </a>
                
                <a href="medicalRecords.php" class="action-card">
                    <div class="action-icon">
                        <span>📋</span>
                    </div>
                    <div class="action-text">
                        <h3>Medical Records</h3>
                        <p>View your health history</p>
                    </div>
                </a>
                
                <a href="profile.php" class="action-card">
                    <div class="action-icon">
                        <span>👤</span>
                    </div>
                    <div class="action-text">
                        <h3>My Profile</h3>
                        <p>Update personal information</p>
                    </div>
                </a>
                
                <a href="prescription.php" class="action-card">
                    <div class="action-icon">
                        <span>💊</span>
                    </div>
                    <div class="action-text">
                        <h3>Prescriptions</h3>
                        <p>View current medications</p>
                    </div>
                </a>
            </div>

            <!-- Today's Schedule -->
            <?php if (!empty($todayAppointments)): ?>
                <div class="today-schedule fade-in">
                    <div class="schedule-header">
                        <h2><span>📅</span> Today's Schedule</h2>
                        <div class="schedule-count"><?php echo count($todayAppointments); ?> appointment(s)</div>
                    </div>
                    
                    <div class="appointment-list">
                        <?php foreach ($todayAppointments as $appointment): ?>
                            <div class="appointment-item today">
                                <div class="appointment-info">
                                    <h4>Dr. <?php echo htmlspecialchars($appointment['doctor_fname'] . ' ' . $appointment['doctor_lname']); ?></h4>
                                    <div class="appointment-doctor"><?php echo htmlspecialchars($appointment['specialization']); ?></div>
                                    <div class="appointment-time">
                                        <span>🕒</span> <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?>
                                    </div>
                                </div>
                                <div class="appointment-status status-<?php echo $appointment['status']; ?>">
                                    <?php echo ucfirst($appointment['status']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Hidden data for JavaScript -->
            <div id="today-appointments-data" style="display: none;">
                <?php echo json_encode($todayAppointments); ?>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Upcoming Appointments -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>⏳</span> Upcoming Appointments
                        </h2>
                        <a href="appointment.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($upcomingAppointments)): ?>
                            <div class="appointment-list">
                                <?php foreach ($upcomingAppointments as $appointment): ?>
                                    <div class="appointment-item">
                                        <div class="appointment-info">
                                            <h4>Dr. <?php echo htmlspecialchars($appointment['doctor_fname'] . ' ' . $appointment['doctor_lname']); ?></h4>
                                            <div class="appointment-doctor"><?php echo htmlspecialchars($appointment['specialization']); ?></div>
                                            <div class="appointment-time">
                                                <span>📅</span> <?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?>
                                                <span class="notranslate">🕒</span> <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?>
                                            </div>
                                        </div>
                                        <div class="appointment-status status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Show "View All" if there are more than 3 -->
                            <?php if ($upcomingCount > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="appointment.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>📅</span> View All <?php echo $upcomingCount; ?> Appointments
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📅</div>
                                <p>No upcoming appointments</p>
                                <a href="appointment.php" class="btn btn-primary" style="margin-top: 1rem;">
                                    <span>➕</span> Book Now
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Available Doctors Section -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>👨‍⚕️</span> Available &emsp;&emsp;&emsp;Doctors
                        </h2>
                        <a href="doctors.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($availableDoctors)): ?>
                            <div class="doctors-grid">
                            <?php foreach ($availableDoctors as $doctor): ?>
                                <div class="doctor-card" data-doctor-id="<?php echo $doctor['doctor_id']; ?>">
                                    <div class="doctor-avatar">
                                        <?php 
                                        $hasImage = false;
                                        $imagePath = '';
                                        
                                        if (!empty($doctor['profile_picture'])) {
                                            $cleanImageName = ltrim($doctor['profile_picture'], '/');
                                            $cleanImageName = str_replace('uploads/', '', $cleanImageName);
                                            $imagePath = '../uploads/' . $cleanImageName;
                                            $serverPath = __DIR__ . '/../uploads/' . $cleanImageName;
                                            $hasImage = file_exists($serverPath);
                                        }
                                        
                                        if ($hasImage): 
                                        ?>
                                            <img src="<?php echo htmlspecialchars($imagePath); ?>" 
                                                alt="Dr. <?php echo htmlspecialchars($doctor['last_name']); ?>"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <span class="default-avatar" style="display: none;">👨‍⚕️</span>
                                        <?php else: ?>
                                            <span class="default-avatar">👨‍⚕️</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doctor-info">
                                        <h4>Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?></h4>
                                        <div class="doctor-specialty"><?php echo htmlspecialchars($doctor['specialization']); ?></div>
                                        <div class="doctor-fee">RM <?php echo number_format($doctor['consultation_fee'], 2); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Show "View All" if there are more than 3 -->
                        <?php if ($doctorsCount > 3): ?>
                            <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                <a href="doctors.php" class="btn btn-primary" style="text-decoration: none;">
                                    <span>👨‍⚕️</span> View All <?php echo $doctorsCount; ?> Doctors
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">👨‍⚕️</div>
                                <p>No doctors available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Medical Records -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>📋</span> Recent &emsp;&emsp;&emsp;Medical Records
                        </h2>
                        <a href="medicalRecords.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentMedicalRecords)): ?>
                            <?php foreach ($recentMedicalRecords as $record): ?>
                                <div class="medical-record">
                                    <h4>Dr. <?php echo htmlspecialchars($record['doctor_fname'] . ' ' . $record['doctor_lname']); ?></h4>
                                    <div class="record-doctor"><?php echo htmlspecialchars($record['specialization']); ?></div>
                                    <div class="record-date">
                                        <span>📅</span> <?php echo date('M d, Y', strtotime($record['appointment_date'])); ?>
                                    </div>
                                    <?php if (!empty($record['diagnosis'])): ?>
                                        <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #666;">
                                            <?php echo htmlspecialchars(substr($record['diagnosis'], 0, 100)); ?>...
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Show "View All" if there are more than 3 -->
                            <?php if ($recordsCount > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="medicalRecords.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>📋</span> View All <?php echo $recordsCount; ?> Records
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📋</div>
                                <p>No medical records yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="three-column-grid fade-in">
                <!-- Recent Payments Card -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>💳</span> Recent &emsp;&emsp;&emsp;Payments
                        </h2>
                        <a href="payment.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPayments)): ?>
                            <!-- Payment Summary -->
                            <div class="payment-summary">
                                <div class="payment-summary-item">
                                    <div class="summary-label">Total Paid</div>
                                    <div class="summary-value success">
                                        RM <?php echo number_format($paymentStats['total_paid'] ?? 0, 2); ?>
                                    </div>
                                </div>
                                <div class="payment-summary-item">
                                    <div class="summary-label">Pending</div>
                                    <div class="summary-value warning">
                                        RM <?php echo number_format($paymentStats['pending_amount'] ?? 0, 2); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment List -->
                            <div class="payment-list">
                                <?php foreach ($recentPayments as $payment): ?>
                                    <div class="payment-item">
                                        <div class="payment-icon status-<?php echo $payment['payment_status']; ?>">
                                            <?php
                                            switch($payment['payment_status']) {
                                                case 'completed':
                                                    echo '✓';
                                                    break;
                                                case 'pending':
                                                    echo '⏳';
                                                    break;
                                                case 'failed':
                                                    echo '✗';
                                                    break;
                                                case 'refunded':
                                                    echo '↩';
                                                    break;
                                                default:
                                                    echo '💳';
                                            }
                                            ?>
                                        </div>
                                        <div class="payment-info">
                                            <h4>
                                                <?php if ($payment['doctor_fname']): ?>
                                                    Dr. <?php echo htmlspecialchars($payment['doctor_fname'] . ' ' . $payment['doctor_lname']); ?>
                                                <?php else: ?>
                                                    General Payment
                                                <?php endif; ?>
                                            </h4>
                                            <div class="payment-method">
                                                <?php 
                                                $methodIcons = [
                                                    'cash' => '💵',
                                                    'card' => '💳',
                                                    'online' => '🌐'
                                                ];
                                                echo ($methodIcons[$payment['payment_method']] ?? '💰') . ' ';
                                                echo ucfirst($payment['payment_method']);
                                                ?>
                                            </div>
                                            <div class="payment-date">
                                                <span>📅</span> <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                            </div>
                                        </div>
                                        <div class="payment-amount-wrapper">
                                            <div class="payment-amount">
                                                RM <?php echo number_format($payment['amount'], 2); ?>
                                            </div>
                                            <div class="payment-status status-badge-<?php echo $payment['payment_status']; ?>">
                                                <?php echo ucfirst($payment['payment_status']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Show "View All" if there are more than 3 -->
                            <?php if ($paymentsCount > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="payment.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>💳</span> View All <?php echo $paymentsCount; ?> Payments
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">💳</div>
                                <p>No payment history yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Prescriptions Card -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>💊</span> Recent Prescriptions
                        </h2>
                        <a href="prescription.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPrescriptions)): ?>
                            <!-- Prescription Summary -->
                            <div class="prescription-summary">
                                <div class="summary-stat">
                                    <span class="stat-icon">📋</span>
                                    <div>
                                        <div class="stat-number">
                                            <?php echo $prescriptionCount['total_prescriptions'] ?? 0; ?>
                                        </div>

                                        <?php if (($prescriptionCount['total_prescriptions'] ?? 0) > 3): ?>
                                            <a href="prescription.php" class="btn btn-primary">
                                                View All <?php echo $prescriptionCount['total_prescriptions']; ?> Prescriptions
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Prescription List -->
                            <div class="prescription-list">
                                <?php foreach ($recentPrescriptions as $prescription): ?>
                                    <div class="prescription-item">
                                        <div class="prescription-header">
                                            <div class="prescription-icon">💉</div>
                                            <div class="prescription-info">
                                                <h4>Dr. <?php echo htmlspecialchars($prescription['doctor_fname'] . ' ' . $prescription['doctor_lname']); ?></h4>
                                                <div class="prescription-specialty">
                                                    <?php echo htmlspecialchars($prescription['specialization']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="prescription-body">
                                            <div class="prescription-diagnosis">
                                                <strong>Diagnosis:</strong> 
                                                <?php echo htmlspecialchars(substr($prescription['diagnosis'], 0, 60)); ?>
                                                <?php echo strlen($prescription['diagnosis']) > 60 ? '...' : ''; ?>
                                            </div>
                                            <div class="prescription-meta">
                                                <span class="meta-item">
                                                    <span>💊</span> <?php echo $prescription['medication_count']; ?> Medication(s)
                                                </span>
                                                <span class="meta-item">
                                                    <span>📅</span> <?php echo date('M d, Y', strtotime($prescription['prescription_date'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="prescription-footer">
                                            <a href="prescription.php?id=<?php echo $prescription['prescription_id']; ?>" 
                                            class="btn-view-prescription">
                                                View Details →
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Show "View All" if there are more than 3 -->
                            <?php if ($prescriptionCount['total_prescriptions'] > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="prescription.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>💊</span> View All <?php echo $prescriptionCount['total_prescriptions']; ?> Prescriptions
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">💊</div>
                                <p>No prescriptions yet</p>
                                <small style="color: #999; display: block; margin-top: 0.5rem;">
                                    Prescriptions will appear here after your doctor consultations
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notifications Card -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>🔔</span> System &emsp;&emsp;&emsp;&emsp;Alert
                            <?php if (!empty($notifications)): ?>
                                <span class="notification-badge pulse"></span>
                            <?php endif; ?>
                        </h2>
                        <a href="notification.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($notifications)): ?>
                            <div class="notification-list">
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item unread" onclick="window.location.href='notification.php';" style="cursor: pointer;">
                                        <h4><?php echo htmlspecialchars($notification['title']); ?></h4>
                                        <div class="notification-text">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </div>
                                        <div class="notification-time">
                                            <?php echo date('M d, h:i A', strtotime($notification['created_at'])); ?>
                                        </div>
                                        <div class="notification-badge"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Show "View All" if there are more than 3 -->
                            <?php if ($unreadCount > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="notification.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>🔔</span> View All <?php echo $unreadCount; ?> Notifications
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">🔔</div>
                                <p>No new notifications</p>
                                <a href="notification.php" style="color: var(--primary-green); text-decoration: none; font-size: 0.9rem; margin-top: 0.5rem; display: inline-block;">
                                    View all notifications →
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid-health">
                <!-- Health Tips -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>💡</span> Health Tips
                        </h2>
                    </div>

                    <div class="health-tips-wrapper">
                        <div class="health-tips-grid">

                            <div class="health-tip">
                                <h4>💧 Stay Hydrated</h4>
                                <p>Drink at least 8 glasses of water daily to keep your body hydrated.</p>
                            </div>

                            <div class="health-tip">
                                <h4>🚶 Daily Exercise</h4>
                                <p>30 minutes of exercise improves heart health and mood.</p>
                            </div>

                            <div class="health-tip">
                                <h4>🍎 Balanced Diet</h4>
                                <p>Eat fruits, vegetables, and whole grains for better nutrition.</p>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script src="dashboard.js"></script>
</body>
</html>