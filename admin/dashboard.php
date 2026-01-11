<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

// Get the MySQLi connection
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch admin info
$adminStmt = $conn->prepare("SELECT a.*, u.email, u.status FROM admins a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ? LIMIT 1");
$adminStmt->bind_param("i", $userId);
$adminStmt->execute();
$admin = $adminStmt->get_result()->fetch_assoc();

if (!$admin) { redirect('login.php'); }

$adminName = trim($admin['first_name'] . ' ' . $admin['last_name']);
$today = date('Y-m-d');
$currentMonth = date('Y-m');

// Simple Counts
$totalDoctors = $conn->query("SELECT COUNT(*) as total FROM doctors")->fetch_assoc()['total'] ?? 0;
$totalPatients = $conn->query("SELECT COUNT(*) as total FROM patients")->fetch_assoc()['total'] ?? 0;
$totalAppointments = $conn->query("SELECT COUNT(*) as total FROM appointments")->fetch_assoc()['total'] ?? 0;

// Count active users
$activeDoctorsStmt = $conn->prepare("SELECT COUNT(*) as total FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE u.status = 'active'");
$activeDoctorsStmt->execute();
$activeDoctors = $activeDoctorsStmt->get_result()->fetch_assoc()['total'] ?? 0;

$activePatientsStmt = $conn->prepare("SELECT COUNT(*) as total FROM patients p JOIN users u ON p.user_id = u.user_id WHERE u.status = 'active'");
$activePatientsStmt->execute();
$activePatients = $activePatientsStmt->get_result()->fetch_assoc()['total'] ?? 0;

// Appointment stats
$apptStatsStmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'confirmed' AND appointment_date = ? THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM appointments
");
$apptStatsStmt->bind_param("s", $today);
$apptStatsStmt->execute();
$appointmentStats = $apptStatsStmt->get_result()->fetch_assoc();

// Count Pending Payments 
$pendingPaymentsStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payments
    WHERE payment_status = 'pending'
");
$pendingPaymentsStmt->execute();
$pendingPaymentsCount = $pendingPaymentsStmt->get_result()->fetch_assoc()['total'] ?? 0;

// Lists (Doctors, Patients, Pending Approvals)
$recentDoctors = $conn->query("SELECT d.*, u.email, u.status, u.created_at FROM doctors d JOIN users u ON d.user_id = u.user_id ORDER BY u.created_at DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$recentPatients = $conn->query("SELECT p.*, u.email, u.status, u.created_at FROM patients p JOIN users u ON p.user_id = u.user_id ORDER BY u.created_at DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$pendingDoctors = $conn->query("SELECT d.*, u.email, u.created_at FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE u.status = 'pending' ORDER BY u.created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Recent Activity (Union Query)
$activityQuery = "
    (SELECT 'appointment' as type, a.appointment_id as id, CONCAT(p.first_name, ' ', p.last_name) as patient_name, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, a.status, a.created_at
    FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id)
    UNION ALL
    (SELECT 'doctor' as type, d.doctor_id as id, NULL as patient_name, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, u.status, u.created_at
    FROM doctors d JOIN users u ON d.user_id = u.user_id)
    UNION ALL
    (SELECT 'patient' as type, p.patient_id as id, CONCAT(p.first_name, ' ', p.last_name) as patient_name, NULL as doctor_name, u.status, u.created_at
    FROM patients p JOIN users u ON p.user_id = u.user_id)
    ORDER BY created_at DESC LIMIT 10
";
$recentActivity = $conn->query($activityQuery)->fetch_all(MYSQLI_ASSOC);

// Prep for systemStats array used in the UI
$systemStats = [
    'monthly_revenue' => $revenueData['revenue'] ?? 0,
    'today_appointments' => $appointmentStats['today'] ?? 0,
    'completed_appointments' => $appointmentStats['completed'] ?? 0,
    'pending_appointments' => $appointmentStats['pending'] ?? 0,
    'monthly_consultations' => $revenueData['count'] ?? 0
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
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
                    <h1 data-admin-name="<?php echo htmlspecialchars($adminName); ?>">
                        Welcome back, <?php echo htmlspecialchars($adminName); ?>! ⚡
                    </h1>
                    <p>System Administrator for Managing the Smart Clinic Management Portal</p>
                </div>
                <div class="date-time">
                    <div class="current-date"><?php echo date('l, F j, Y'); ?></div>
                    <div class="current-time" id="live-clock"><?php echo date('h:i A'); ?></div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon doctors">
                        <span>👨‍⚕️</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $activeDoctors; ?></h3>
                        <p>Active Doctors</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon patients">
                        <span>🤒</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $activePatients; ?></h3>
                        <p>Active Patients</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon appointments">
                        <span>📅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalAppointments; ?></h3>
                        <p>Total Appointments</p>
                    </div>
                </div>
                
                <!-- Pending Payments Card -->
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <span>💳</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $pendingPaymentsCount; ?></h3>
                        <p>Pending Payments</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions fade-in">
                <a href="doctors.php" class="action-card">
                    <div class="action-icon">
                        <span>👨‍⚕️</span>
                    </div>
                    <div class="action-text">
                        <h3>Manage Doctors</h3>
                        <p>View and manage doctors</p>
                    </div>
                </a>
                
                <a href="patients.php" class="action-card">
                    <div class="action-icon">
                        <span>🤒</span>
                    </div>
                    <div class="action-text">
                        <h3>Manage Patients</h3>
                        <p>View patient records</p>
                    </div>
                </a>
                
                <a href="appointments.php" class="action-card">
                    <div class="action-icon">
                        <span>📋</span>
                    </div>
                    <div class="action-text">
                        <h3>Appointments</h3>
                        <p>View all appointments</p>
                    </div>
                </a>
                
                <a href="billing.php" class="action-card">
                    <div class="action-icon">
                        <span>💳</span>
                    </div>
                    <div class="action-text">
                        <h3>Billing</h3>
                        <p>Manage payments</p>
                    </div>
                </a>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Pending Doctor Approvals -->
                <?php if (!empty($pendingDoctors)): ?>
                <div class="dashboard-card fade-in urgent">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>⚠️</span> Pending Doctor Approvals
                            <span class="notification-badge pulse" data-pending-count><?php echo count($pendingDoctors); ?></span>
                        </h2>
                        <a href="doctors.php?status=pending" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-body">
                        <div class="list-container">
                            <?php foreach ($pendingDoctors as $doctor): ?>
                                <div class="list-item">
                                    <div class="list-item-info">
                                        <h4>Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?></h4>
                                        <div class="list-item-details">
                                            <span>📧 <?php echo htmlspecialchars($doctor['email']); ?></span>
                                            <span>🩺 <?php echo htmlspecialchars($doctor['specialization']); ?></span>
                                            <span>📅 <?php echo date('M d, Y', strtotime($doctor['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="list-item-actions">
                                        <button class="btn-action edit" onclick="approveDoctor(<?php echo $doctor['doctor_id']; ?>)">
                                            ✓ Approve
                                        </button>
                                        <button class="btn-action delete" onclick="rejectDoctor(<?php echo $doctor['doctor_id']; ?>)">
                                            ✕ Reject
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Doctors -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>👨‍⚕️</span> Recent Doctors
                        </h2>
                        <a href="doctors.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentDoctors)): ?>
                            <div class="list-container">
                                <?php foreach ($recentDoctors as $doctor): ?>
                                    <div class="list-item" id=<?php echo $doctor['doctor_id']; ?>">
                                        <div class="list-item-info">
                                            <h4>Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?></h4>
                                            <div class="list-item-details">
                                                <span>🩺 <?php echo htmlspecialchars($doctor['specialization']); ?></span>
                                                <span>📧 <?php echo htmlspecialchars($doctor['email']); ?></span>
                                            </div>
                                        </div>
                                        <div class="status-badge status-<?php echo $doctor['status']; ?>">
                                            <?php echo ucfirst($doctor['status']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">👨‍⚕️</div>
                                <p>No doctors registered yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Patients -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>🤒</span> Recent Patients
                        </h2>
                        <a href="patients.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentPatients)): ?>
                            <div class="list-container">
                                <?php foreach ($recentPatients as $patient): ?>
                                    <div class="list-item" id=<?php echo $patient['patient_id']; ?>">
                                        <div class="list-item-info">
                                            <h4><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h4>
                                            <div class="list-item-details">
                                                <span>📧 <?php echo htmlspecialchars($patient['email']); ?></span>
                                                <span>📞 <?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                        <div class="status-badge status-<?php echo $patient['status']; ?>">
                                            <?php echo ucfirst($patient['status']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">🤒</div>
                                <p>No patients registered yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="dashboard-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <span>📊</span> Recent Activity
                        </h2>
                        <a href="activity.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentActivity)): ?>
                            <div class="activity-feed">
                                <?php 
                                $activityCount = count($recentActivity);
                                foreach (array_slice($recentActivity, 0, 3) as $activity): 
                                ?>
                                    <div class="activity-item">
                                        <div class="activity-icon <?php echo $activity['type']; ?>">
                                            <?php 
                                            $icons = [
                                                'appointment' => '📅',
                                                'doctor' => '👨‍⚕️',
                                                'patient' => '🤒'
                                            ];
                                            echo $icons[$activity['type']] ?? '📋';
                                            ?>
                                        </div>
                                        <div class="activity-info">
                                            <h4>
                                                <?php if ($activity['type'] === 'appointment'): ?>
                                                    New Appointment
                                                <?php elseif ($activity['type'] === 'doctor'): ?>
                                                    New Doctor Registration
                                                <?php else: ?>
                                                    New Patient Registration
                                                <?php endif; ?>
                                            </h4>
                                            <p>
                                                <?php if ($activity['type'] === 'appointment'): ?>
                                                    <?php echo htmlspecialchars($activity['patient_name']); ?> with 
                                                    Dr. <?php echo htmlspecialchars($activity['doctor_name']); ?>
                                                <?php elseif ($activity['type'] === 'doctor'): ?>
                                                    Dr. <?php echo htmlspecialchars($activity['doctor_name']); ?>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($activity['patient_name']); ?>
                                                <?php endif; ?>
                                            </p>
                                            <div class="activity-time">
                                                <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($activityCount > 3): ?>
                                <div style="text-align: center; padding-top: 1rem; border-top: 1px solid #e0e0e0; margin-top: 1rem;">
                                    <a href="activity.php" class="btn btn-primary" style="text-decoration: none;">
                                        <span>📊</span> View All <?php echo $activityCount; ?> Activities
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📊</div>
                                <p>No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- System Statistics -->
            <div class="dashboard-card fade-in" style="margin-top: 2rem;">
                <div class="card-header">
                    <h2 class="card-title">
                        <span>🛠️</span> System Administration Notes
                    </h2>
                </div>
                <div class="card-body">
                    <div class="performance-grid">
                        <div class="performance-item">
                            <div class="performance-icon">🖥️</div>
                            <h4>System Status</h4>
                            <p>All core services are running normally.</p>
                        </div>
                        <div class="performance-item">
                            <div class="performance-icon">👥</div>
                            <h4>User Management</h4>
                            <p>Monitor active, pending, and suspended user accounts.</p>
                        </div>
                        <div class="performance-item">
                            <div class="performance-icon">🔐</div>
                            <h4>Security Reminder</h4>
                            <p>Regularly review access roles and update passwords.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="dashboard.js"></script>
</body>
</html>