<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

// Use the MySQLi connection from your singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch doctor info
$doctorStmt = $conn->prepare("
    SELECT d.*, u.email 
    FROM doctors d 
    JOIN users u ON d.user_id = u.user_id 
    WHERE d.user_id = ? 
    LIMIT 1
");
$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();

if (!$doctor) {
    redirect('doctor/profile.php');
}

$doctorId = $doctor['doctor_id'];
$doctorFullName = trim($doctor['first_name'] . ' ' . $doctor['last_name']);
$today = date('Y-m-d');

// Fetch today's appointments - LIMIT 3
$todayStmt = $conn->prepare("
    SELECT a.*, p.first_name as patient_fname, p.last_name as patient_lname, p.phone, p.blood_type
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.doctor_id = ? AND a.appointment_date = ? AND a.status IN ('confirmed', 'pending')
    ORDER BY a.appointment_time ASC LIMIT 3
");
$todayStmt->bind_param("is", $doctorId, $today);
$todayStmt->execute();
$todayAppointments = $todayStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch upcoming appointments (next 7 days) - LIMIT 3
$nextWeek = date('Y-m-d', strtotime('+7 days'));
$upStmt = $conn->prepare("
    SELECT a.*, p.first_name as patient_fname, p.last_name as patient_lname, p.phone
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.doctor_id = ? AND a.appointment_date > ? AND a.appointment_date <= ? AND a.status IN ('confirmed', 'pending')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 3
");
$upStmt->bind_param("iss", $doctorId, $today, $nextWeek);
$upStmt->execute();
$upcomingAppointments = $upStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Count total upcoming appointments
$upCountStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM appointments
    WHERE doctor_id = ? AND appointment_date > ? AND appointment_date <= ? AND status IN ('confirmed', 'pending')
");
$upCountStmt->bind_param("iss", $doctorId, $today, $nextWeek);
$upCountStmt->execute();
$upcomingCount = $upCountStmt->get_result()->fetch_assoc()['total'] ?? 0;

// Count appointments by status
$statsStmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'confirmed' AND appointment_date = ? THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM appointments WHERE doctor_id = ?
");
$statsStmt->bind_param("si", $today, $doctorId);
$statsStmt->execute();
$appointmentStats = $statsStmt->get_result()->fetch_assoc();

// Get total patients count
$patCountStmt = $conn->prepare("SELECT COUNT(DISTINCT patient_id) as total FROM appointments WHERE doctor_id = ?");
$patCountStmt->bind_param("i", $doctorId);
$patCountStmt->execute();
$totalPatients = $patCountStmt->get_result()->fetch_assoc()['total'] ?? 0;

// Fetch recent patients - LIMIT 3
$recentPatStmt = $conn->prepare("
    SELECT DISTINCT p.*, MAX(a.appointment_date) as last_visit, COUNT(a.appointment_id) as total_visits
    FROM patients p
    JOIN appointments a ON p.patient_id = a.patient_id
    WHERE a.doctor_id = ?
    GROUP BY p.patient_id ORDER BY last_visit DESC LIMIT 3
");
$recentPatStmt->bind_param("i", $doctorId);
$recentPatStmt->execute();
$recentPatients = $recentPatStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate earnings (this month)
$perfStmt = $conn->prepare("
    SELECT COUNT(*) as completed_appointments
    FROM appointments
    WHERE doctor_id = ? 
    AND status = 'completed'
    AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$perfStmt->bind_param("i", $doctorId);
$perfStmt->execute();
$performance = $perfStmt->get_result()->fetch_assoc();

// Fetch unread notifications - LIMIT 3
$notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 3");
$notifStmt->bind_param("i", $userId);
$notifStmt->execute();
$notifications = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Summary Stats for Timeline
$sumStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
    FROM appointments WHERE doctor_id = ? AND appointment_date = ?
");
$sumStmt->bind_param("is", $doctorId, $today);
$sumStmt->execute();
$todaySummary = $sumStmt->get_result()->fetch_assoc();

// Calculate today's total count
$todayCount = $todaySummary['confirmed_count'] + $todaySummary['pending_count'];

// Fetch pending appointments - LIMIT 3
$pendingStmt = $conn->prepare("
    SELECT a.*, p.first_name as patient_fname, p.last_name as patient_lname, p.phone
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.doctor_id = ? AND a.status = 'pending'
    ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 3
");
$pendingStmt->bind_param("i", $doctorId);
$pendingStmt->execute();
$pendingAppointments = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Count total pending appointments
$pendingCountStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM appointments
    WHERE doctor_id = ? AND status = 'pending'
");
$pendingCountStmt->bind_param("i", $doctorId);
$pendingCountStmt->execute();
$pendingCount = $pendingCountStmt->get_result()->fetch_assoc()['total'] ?? 0;

// Calculate action required items
$actionRequired = [
    'pending_count' => $appointmentStats['pending'] ?? 0,
    'recent_cancellations' => 0,
    'reschedule_requests' => 0
];

// Count recent cancellations (last 24 hours)
$cancelStmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM appointments
    WHERE doctor_id = ? AND status = 'cancelled' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$cancelStmt->bind_param("i", $doctorId);
$cancelStmt->execute();
$actionRequired['recent_cancellations'] = $cancelStmt->get_result()->fetch_assoc()['total'] ?? 0;

$totalActionsRequired = array_sum($actionRequired);

// Calculate next appointment countdown
$nextAppointment = null;
if (!empty($todayAppointments)) {
    $now = new DateTime();
    foreach ($todayAppointments as $apt) {
        $aptTime = new DateTime($apt['appointment_date'] . ' ' . $apt['appointment_time']);
        if ($aptTime > $now) {
            $nextAppointment = $apt;
            $nextAppointment['countdown'] = $now->diff($aptTime);
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - <?php echo SITE_NAME; ?></title>
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
                    <h1 data-doctor-name="<?php echo htmlspecialchars($doctorFullName); ?>">
                        Welcome back, Dr. <?php echo htmlspecialchars($doctorFullName); ?>! 👋
                    </h1>
                    <p><?php echo htmlspecialchars($doctor['specialization']); ?> • Ready to make a difference today 💐</p>
                </div>
                <div class="date-time">
                    <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="current-time" id="live-clock"><?php echo date('h:i A'); ?></div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon today">
                        <span>📅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['today'] ?? 0; ?></h3>
                        <p>Today's Appointments</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon pending">
                        <span>⏳</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['pending'] ?? 0; ?></h3>
                        <p>Pending Confirmation</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon patients">
                        <span>👥</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalPatients; ?></h3>
                        <p>Total Patients</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon completed">
                        <span>✅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $appointmentStats['completed'] ?? 0; ?></h3>
                        <p>Completed Appointments</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions fade-in">
                <a href="appointments.php" class="action-card">
                    <div class="action-icon">
                        <span>📋</span>
                    </div>
                    <div class="action-text">
                        <h3>View Appointments</h3>
                        <p>Manage your schedule</p>
                    </div>
                </a>
                
                <a href="patients.php" class="action-card">
                    <div class="action-icon">
                        <span>👥</span>
                    </div>
                    <div class="action-text">
                        <h3>Patient Records</h3>
                        <p>Access medical history</p>
                    </div>
                </a>
                
                <a href="profile.php" class="action-card">
                    <div class="action-icon">
                        <span>👤</span>
                    </div>
                    <div class="action-text">
                        <h3>My Profile</h3>
                        <p>Update your information</p>
                    </div>
                </a>
                
                <a href="prescriptions.php" class="action-card">
                    <div class="action-icon">
                        <span>💊</span>
                    </div>
                    <div class="action-text">
                        <h3>Prescription</h3>
                        <p>View or create prescriptions</p>
                    </div>
                </a>
            </div>

            <!-- Action Required Card -->
            <?php if ($totalActionsRequired > 0): ?>
            <div class="action-required-card fade-in">
                <div class="action-required-header">
                    <div class="action-required-icon">
                        <span>⚡</span>
                    </div>
                    <div class="action-required-text">
                        <h2>Action Required</h2>
                        <p><?php echo $totalActionsRequired; ?> item<?php echo $totalActionsRequired !== 1 ? 's' : ''; ?> need<?php echo $totalActionsRequired === 1 ? 's' : ''; ?> your attention</p>
                    </div>
                </div>
                
                <div class="action-required-body">
                    <?php if ($actionRequired['pending_count'] > 0): ?>
                    <a href="appointments.php?status=pending" class="action-item">
                        <div class="action-item-icon pending-icon">⏳</div>
                        <div class="action-item-content">
                            <h4><?php echo $actionRequired['pending_count']; ?> Pending Approval<?php echo $actionRequired['pending_count'] !== 1 ? 's' : ''; ?></h4>
                            <p>New appointment requests awaiting confirmation</p>
                        </div>
                        <div class="action-item-arrow">→</div>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($actionRequired['recent_cancellations'] > 0): ?>
                    <a href="appointments.php?status=cancelled" class="action-item">
                        <div class="action-item-icon cancelled-icon">❌</div>
                        <div class="action-item-content">
                            <h4><?php echo $actionRequired['recent_cancellations']; ?> Recent Cancellation<?php echo $actionRequired['recent_cancellations'] !== 1 ? 's' : ''; ?></h4>
                            <p>Appointments cancelled in the last 24 hours</p>
                        </div>
                        <div class="action-item-arrow">→</div>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($actionRequired['reschedule_requests'] > 0): ?>
                    <a href="appointments.php?filter=reschedule" class="action-item">
                        <div class="action-item-icon reschedule-icon">🔄</div>
                        <div class="action-item-content">
                            <h4><?php echo $actionRequired['reschedule_requests']; ?> Reschedule Request<?php echo $actionRequired['reschedule_requests'] !== 1 ? 's' : ''; ?></h4>
                            <p>Patients requesting appointment changes</p>
                        </div>
                        <div class="action-item-arrow">→</div>
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="action-required-footer">
                    <a href="appointments.php" class="btn-review-all">
                        <span>👁️</span> Review All
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Enhanced Today's Schedule with Timeline -->
            <?php if (!empty($todayAppointments)): ?>
            <div class="today-schedule fade-in">
                <div class="schedule-header">
                    <div>
                        <h2><span>📅</span> Today's Schedule</h2>
                        <div class="today-summary">
                            <span class="summary-badge confirmed">✔ <?php echo $todaySummary['confirmed_count']; ?> confirmed</span>
                            <span class="summary-badge pending">⏳ <?php echo $todaySummary['pending_count']; ?> pending</span>
                            <?php if ($todaySummary['cancelled_count'] > 0): ?>
                            <span class="summary-badge cancelled">❌ <?php echo $todaySummary['cancelled_count']; ?> cancelled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($nextAppointment): ?>
                    <div class="next-appointment-countdown">
                        <div class="countdown-label">Next appointment in:</div>
                        <div class="countdown-time">
                            <?php 
                            $diff = $nextAppointment['countdown'];
                            if ($diff->h > 0) {
                                echo $diff->h . 'h ' . $diff->i . 'm';
                            } else {
                                echo $diff->i . 'm';
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="appointment-timeline">
                    <?php 
                    $currentTime = date('H:i');
                    foreach ($todayAppointments as $index => $appointment): 
                        $appointmentTime = date('H:i', strtotime($appointment['appointment_time']));
                        $isPast = $appointmentTime < $currentTime;
                        $isCurrent = abs(strtotime($appointmentTime) - strtotime($currentTime)) < 1800; // Within 30 min
                    ?>
                    <a href="appointments.php?date=<?php echo $today; ?>" style="text-decoration: none; color: inherit; display: block;">
                        <div class="timeline-item <?php echo $isPast ? 'past' : ''; ?> <?php echo $isCurrent ? 'current' : ''; ?>" 
                             data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                            <div class="timeline-time">
                                <span class="time-hour"><?php echo date('h:i', strtotime($appointment['appointment_time'])); ?></span>
                                <span class="time-period"><?php echo date('A', strtotime($appointment['appointment_time'])); ?></span>
                            </div>
                            
                            <div class="timeline-dot <?php echo $appointment['status']; ?>"></div>
                            
                            <div class="timeline-content">
                                <div class="timeline-patient">
                                    <h4><?php echo htmlspecialchars($appointment['patient_fname'] . ' ' . $appointment['patient_lname']); ?></h4>
                                    <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                        <?php echo ucfirst($appointment['status']); ?>
                                    </span>
                                </div>
                                <div class="timeline-details">
                                    <span>📞 <?php echo htmlspecialchars($appointment['phone']); ?></span>
                                    <?php if (!empty($appointment['blood_type'])): ?>
                                        <span>🩸 <?php echo htmlspecialchars($appointment['blood_type']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($appointment['reason'])): ?>
                                    <div class="timeline-reason">
                                        <?php echo htmlspecialchars($appointment['reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($appointment['status'] === 'pending'): ?>
                            <div class="timeline-actions">
                                <button class="btn-action confirm" 
                                        onclick="event.preventDefault(); event.stopPropagation(); confirmAppointment(<?php echo $appointment['appointment_id']; ?>); return false;">
                                    ✓
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($todayCount > 3): ?>
                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.3); margin-top: 1rem;">
                    <a href="appointments.php?date=<?php echo $today; ?>" class="btn btn-primary" style="text-decoration: none; background: white; color: var(--primary-blue);">
                        <span>📅</span> View All <?php echo $todayCount; ?> Today's Appointments
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Hidden data for JavaScript -->
            <div id="today-appointments-data" style="display: none;">
                <?php echo json_encode($todayAppointments); ?>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Pending Confirmations -->
                <div class="dashboard-card fade-in <?php echo !empty($pendingAppointments) ? 'urgent' : ''; ?>">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>⚠️</span> Pending Confirmations
                            <?php if (!empty($pendingAppointments)): ?>
                                <span class="notification-badge pulse"></span>
                            <?php endif; ?>
                        </h2>
                        <a href="appointments.php?status=pending" class="btn btn-sm btn-outline">View</a>
                    </div>

                    <div class="card-body">
                        <?php if (!empty($pendingAppointments)): ?>
                            <div class="appointment-list">
                                <?php foreach ($pendingAppointments as $appointment): ?>
                                    <div class="appointment-item pending">
                                        <div class="appointment-info">
                                            <h4><?php echo htmlspecialchars($appointment['patient_fname'] . ' ' . $appointment['patient_lname']); ?></h4>
                                            <div class="appointment-time">
                                                <span>📅</span> <?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?>
                                                <span>🕒</span> <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?>
                                            </div>
                                        </div>
                                        <div class="appointment-actions">
                                            <button class="btn-action confirm"
                                                onclick="confirmAppointment(<?php echo $appointment['appointment_id']; ?>)">
                                                ✓
                                            </button>
                                            <button class="btn-action cancel"
                                                onclick="cancelAppointment(<?php echo $appointment['appointment_id']; ?>)">
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($pendingCount > 3): ?>
                                <div style="text-align:center; padding-top:1rem; border-top:1px solid #e0e0e0; margin-top:1rem;">
                                    <a href="appointments.php?status=pending" class="btn btn-primary">
                                        <span>⚠️</span> View All <?php echo $pendingCount; ?> Pending
                                    </a>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="empty-state" style="text-align:center; padding:2rem; color:#666;">
                                <div style="font-size:2rem;">✅</div>
                                <p style="margin-top:0.5rem; font-weight:500;">
                                    No pending appointments
                                </p>
                                <small>All appointments are confirmed</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Appointments -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>⏰</span> Upcoming Appointments
                        </h2>
                        <a href="appointments.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($upcomingAppointments)): ?>
                            <div class="appointment-list">
                                <?php foreach ($upcomingAppointments as $appointment): ?>
                                    <div class="appointment-item">
                                        <div class="appointment-info">
                                            <h4><?php echo htmlspecialchars($appointment['patient_fname'] . ' ' . $appointment['patient_lname']); ?></h4>
                                            <div class="appointment-time">
                                                <span>📅</span> <?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?>
                                                <span>🕒</span> <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?>
                                            </div>
                                        </div>
                                        <div class="appointment-status status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($upcomingCount > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="appointments.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>📅</span> View All <?php echo $upcomingCount; ?> Appointments
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📅</div>
                                <p>No upcoming appointments</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Patients -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>👥</span> Recent&emsp;&emsp;&emsp; Patients
                        </h2>
                        <a href="patients.php" class="btn btn-sm btn-outline">View</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPatients)): ?>
                            <div class="patients-list">
                                <?php foreach ($recentPatients as $patient): ?>
                                    <div class="patient-card" data-patient-id="<?php echo $patient['patient_id']; ?>">
                                        <div class="patient-avatar">
                                            <?php 
                                            $profilePic = !empty($patient['profile_picture']) ? $patient['profile_picture'] : '';
                                            $fullPath = __DIR__ . '/../' . $profilePic;
                                            
                                            if (!empty($profilePic) && file_exists($fullPath)): ?>
                                                <img src="../<?php echo htmlspecialchars($profilePic); ?>" 
                                                    alt="Patient" 
                                                    style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                            <?php else: ?>
                                                <span><?php echo strtoupper(substr($patient['first_name'], 0, 1)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="patient-info">
                                            <h4><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h4>
                                            <div class="patient-details">
                                                <span>Last visit: <?php echo date('M d', strtotime($patient['last_visit'])); ?></span>
                                                <span>Total visits: <?php echo $patient['total_visits']; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($totalPatients > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="patients.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>👥</span> View All <?php echo $totalPatients; ?> Patients
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">👥</div>
                                <p>No patients yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Performance Summary -->
                <div class="dashboard-card fade-in performance-section">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>💡</span> Doctor’s Daily Focus
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="performance-grid">
                            <div class="performance-item">
                                <div class="performance-icon">🩺 </div>
                                <h4>Review Today's Appointments</h4>
                                <p>Check confirmed and pending appointments to prepare patient notes and treatments.</p>
                            </div>
                            <div class="performance-item">
                                <div class="performance-icon">📋</div>
                                <h4>Update Patient Records</h4>
                                <p>Ensure all recent visits and prescription notes are properly documented.</p>
                            </div>
                            <div class="performance-item">
                                <div class="performance-icon">💊</div>
                                <h4>Review Prescriptions</h4>
                                <p>Check ongoing medications and adjust prescriptions if necessary.</p>
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