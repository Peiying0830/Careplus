<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

// Get the MySQLi connection
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch doctor info
$doctorStmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ? LIMIT 1");
$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();

if (!$doctor) {
    redirect('doctor/profile.php');
}

$doctorId = $doctor['doctor_id'];

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date'] ?? '';

// Build query based on filters
$query = "
    SELECT a.*, 
           p.first_name as patient_fname, 
           p.last_name as patient_lname,
           p.phone,
           p.date_of_birth,
           p.blood_type,
           p.ic_number,
           p.patient_id,
           u.email,
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON p.user_id = u.user_id
    WHERE a.doctor_id = ?
";

$params = [$doctorId];
$types = "i"; // doctor_id is integer

if ($statusFilter !== 'all') {
    $query .= " AND a.status = ?";
    $params[] = $statusFilter;
    $types .= "s"; // status is string
}

if (!empty($dateFilter)) {
    $query .= " AND a.appointment_date = ?";
    $params[] = $dateFilter;
    $types .= "s"; // date is string
}

$query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";

$appointmentsStmt = $conn->prepare($query);
// Use the unpacking operator (...) to bind dynamic parameters
$appointmentsStmt->bind_param($types, ...$params);
$appointmentsStmt->execute();
$appointments = $appointmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Count appointments by status
$statusCountsStmt = $conn->prepare("
    SELECT status, COUNT(*) as count
    FROM appointments 
    WHERE doctor_id = ?
    GROUP BY status
");
$statusCountsStmt->bind_param("i", $doctorId);
$statusCountsStmt->execute();
$statusCountsResult = $statusCountsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$statusCounts = [
    'all' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($statusCountsResult as $row) {
    $statusCounts[$row['status']] = $row['count'];
    $statusCounts['all'] += $row['count'];
}

// Get today's statistics
$today = date('Y-m-d');
$todayStatsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_today,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_today,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_today
    FROM appointments 
    WHERE doctor_id = ? AND appointment_date = ?
");
$todayStatsStmt->bind_param("is", $doctorId, $today);
$todayStatsStmt->execute();
$todayStats = $todayStatsStmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="appointments.css">
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- jsQR Library for QR Code Scanning -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header Section -->
        <div class="page-header-new">
            <div class="container-header">
                <div class="header-content">
                    <div>
                        <h1 class="header-title">📅 Appointment Management</h1>
                        <p class="header-subtitle">&emsp;&emsp;&emsp;Manage and track all your patient appointments</p>
                    </div>
                    <button class="btn-qr-scan" id="qrScanBtn">
                        <i data-lucide="qr-code"></i>
                        <span>Scan QR Code</span>
                    </button>
                </div>
            </div>
        </div>

        <main class="container">
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card stat-primary">
                    <div class="stat-content">
                        <div class="stat-icon stat-icon-blue">
                            <span class="stat-emoji">🗓️</span>
                        </div>
                        <div class="stat-info">
                             <p class="stat-value"><?php echo $todayStats['total_today'] ?? 0; ?></p>
                            <p class="stat-label">Today's Appointments</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-warning">
                    <div class="stat-content">
                        <div class="stat-icon stat-icon-amber">
                            <span class="stat-emoji">⏰</span>
                        </div>
                        <div class="stat-info">
                            <p class="stat-value"><?php echo $statusCounts['pending']; ?></p>
                            <p class="stat-label">Pending</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-info">
                    <div class="stat-content">
                        <div class="stat-icon stat-icon-blue">
                            <span class="stat-emoji">✅</span>
                        </div>
                        <div class="stat-info">
                            <p class="stat-value"><?php echo $statusCounts['confirmed']; ?></p>
                            <p class="stat-label">Confirmed</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-success">
                    <div class="stat-content">
                        <div class="stat-icon stat-icon-green">
                            <span class="stat-emoji">💚</span>
                        </div>
                        <div class="stat-info">
                            <p class="stat-value"><?php echo $todayStats['completed_today'] ?? 0; ?>/<?php echo $todayStats['total_today'] ?? 0; ?></p>
                            <p class="stat-label">Completed Today</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QR Scanner Section (Hidden by default) -->
            <div class="qr-scanner-section" id="qrScannerSection" style="display: none;">
                <div class="qr-scanner-header">
                    <h3 class="qr-scanner-title">
                        <i data-lucide="qr-code"></i>
                        QR Code Check-In
                    </h3>
                    <button class="qr-close-btn" id="qrCloseBtn">✕</button>
                </div>
                <div class="qr-scanner-body">
                    <div class="qr-scanner-placeholder">
                        <i data-lucide="qr-code" class="qr-icon-large"></i>
                    </div>
                    <p class="qr-instruction">Point camera at patient's QR code to check them in</p>
                    <button class="btn-start-camera">Start Camera</button>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section-new">
                <div class="filter-header-new">
                    <span class="emoji-icon">🔍</span>
                    <h3 class="filter-title">Filters</h3>
                </div>
                
                <div class="filter-grid">
                    <div class="filter-group-new">
                        <label class="filter-label">Status</label>
                        <select id="statusFilter" name="status" class="filter-select">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>
                                All Status (<?php echo $statusCounts['all']; ?>)
                            </option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>
                                Pending (<?php echo $statusCounts['pending']; ?>)
                            </option>
                            <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>
                                Confirmed (<?php echo $statusCounts['confirmed']; ?>)
                            </option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>
                                Completed (<?php echo $statusCounts['completed']; ?>)
                            </option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>
                                Cancelled (<?php echo $statusCounts['cancelled']; ?>)
                            </option>
                        </select>
                    </div>

                    <div class="filter-group-new">
                        <label class="filter-label">Date</label>
                        <input type="date" id="dateFilter" name="date" value="<?php echo htmlspecialchars($dateFilter); ?>" class="filter-input">
                    </div>

                    <div class="filter-group-new">
                        <label class="filter-label">Search Patient</label>
                        <div class="search-input-wrapper">
                            <span class="search-icon">🔎</span>
                            <input type="text" id="searchInput" placeholder="Name or phone..." class="filter-input search-input">
                        </div>
                    </div>
                </div>

                <button class="btn-clear-filters" id="clearFiltersBtn" style="display: none;">
                    Clear all filters
                </button>
            </div>

            <!-- Appointments List -->
            <div class="appointments-list">
                <?php if (!empty($appointments)): ?>
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                            $patientFullName = htmlspecialchars($appointment['patient_fname'] . ' ' . $appointment['patient_lname']);
                            $appointmentDate = date('D, M d, Y', strtotime($appointment['appointment_date']));
                            $appointmentTime = date('h:i A', strtotime($appointment['appointment_time']));
                            $status = $appointment['status'];
                            $qrCode = $appointment['qr_code'] ?? 'N/A';
                        ?>
                        <div class="appointment-card-new" 
                            data-appointment-id="<?php echo $appointment['appointment_id']; ?>"
                            data-status="<?php echo $status; ?>"
                            data-date="<?php echo $appointment['appointment_date']; ?>"
                            data-patient-name="<?php echo strtolower($patientFullName); ?>"
                            data-patient-fname="<?php echo htmlspecialchars($appointment['patient_fname']); ?>"
                            data-patient-lname="<?php echo htmlspecialchars($appointment['patient_lname']); ?>"
                            data-patient-id="<?php echo htmlspecialchars($appointment['patient_id']); ?>"
                            data-phone="<?php echo htmlspecialchars($appointment['phone']); ?>"
                            data-email="<?php echo htmlspecialchars($appointment['email'] ?? 'N/A'); ?>"
                            data-ic-number="<?php echo htmlspecialchars($appointment['ic_number'] ?? 'N/A'); ?>"
                            data-blood-type="<?php echo htmlspecialchars($appointment['blood_type'] ?? 'N/A'); ?>"
                            data-age="<?php echo htmlspecialchars($appointment['age'] ?? 'N/A'); ?>"
                            data-symptoms="<?php echo htmlspecialchars($appointment['symptoms'] ?? 'No symptoms recorded'); ?>"
                            data-notes="<?php echo htmlspecialchars($appointment['notes'] ?? 'No notes'); ?>"
                            data-appointment-time="<?php echo $appointment['appointment_time']; ?>"
                            data-qr-code="<?php echo htmlspecialchars($qrCode); ?>">
                            
                            <!-- Time Badge -->
                            <div class="time-badge-new">
                                <div class="time-badge-date"><?php echo date('D, M d', strtotime($appointment['appointment_date'])); ?></div>
                                <div class="time-badge-time"><?php echo $appointmentTime; ?></div>
                            </div>

                            <!-- Appointment Content -->
                            <div class="appointment-content-new">
                                <div class="appointment-header-new">
                                    <div>
                                        <h3 class="patient-name-new">
                                        <i data-lucide="user-circle"></i>
                                        <?php echo $patientFullName; ?>
                                        <span class="appt-id-badge">#<?php echo $appointment['appointment_id']; ?></span>
                                    </h3>
                                    </div>
                                    <span class="status-badge-new status-<?php echo $status; ?>">
                                        <i data-lucide="<?php 
                                            echo $status === 'completed' ? 'check-circle' : 
                                                 ($status === 'confirmed' ? 'check-circle' : 
                                                 ($status === 'cancelled' ? 'x-circle' : 'clock')); 
                                        ?>"></i>
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </div>

                                <div class="appointment-details-new">
                                    <div class="detail-item-new">
                                        <i data-lucide="phone"></i>
                                        <span><?php echo htmlspecialchars($appointment['phone']); ?></span>
                                    </div>
                                    <div class="detail-item-new">
                                        <i data-lucide="activity"></i>
                                        <span><?php echo $appointment['age']; ?> years old</span>
                                    </div>
                                    <?php if (!empty($appointment['blood_type'])): ?>
                                        <div class="detail-item-new">
                                            <span>🩸</span>
                                            <span><?php echo htmlspecialchars($appointment['blood_type']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="detail-item-new">
                                        <i data-lucide="qr-code"></i>
                                        <span class="qr-code-text"><?php echo htmlspecialchars($qrCode); ?></span>
                                    </div>
                                </div>

                                <?php if (!empty($appointment['symptoms'])): ?>
                                    <div class="symptoms-box-new">
                                        <strong>💊 Symptoms:</strong>
                                        <p><?php echo htmlspecialchars($appointment['symptoms']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <!-- Action Buttons -->
                                <div class="action-buttons-new">
                                    <?php if ($status === 'pending'): ?>
                                        <button class="btn-confirm" data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                            <i data-lucide="check-circle"></i>
                                            Confirm
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($status === 'confirmed'): ?>
                                        <button class="btn-complete" data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                            <i data-lucide="check-circle"></i>
                                            Complete
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($status, ['pending', 'confirmed'])): ?>
                                        <button class="btn-cancel" data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                            <i data-lucide="x-circle"></i>
                                            Cancel
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button class="btn-action btn-view" data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                        View Details
                                        <i data-lucide="chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state-new" id="emptyState">
                        <div class="empty-icon">📅</div>
                        <h3>No Appointments Found</h3>
                        <p>There are no appointments matching your current filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Appointment Details Modal -->
    <div class="modal-new" id="appointmentModal">
        <div class="modal-backdrop" id="modalBackdrop"></div>
        <div class="modal-content-new">
            <div class="modal-header-new">
                <h2 class="modal-title">
                    <i data-lucide="clipboard"></i>
                    Appointment Details
                </h2>
                <button class="modal-close-new" id="modalCloseBtn">✕</button>
            </div>
            <div class="modal-body-new">
                <!-- Patient Information -->
                <div class="info-section info-section-blue">
                    <h3 class="info-section-title">
                        <i data-lucide="user-circle"></i>
                        Patient Information
                    </h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Name:</span>
                            <span class="info-value" id="modalPatientName">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">NRIC:</span>
                            <span class="info-value" id="modalIcNumber">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone:</span>
                            <span class="info-value" id="modalPhone">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value" id="modalEmail">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Age:</span>
                            <span class="info-value" id="modalAge">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Blood Type:</span>
                            <span class="info-value" id="modalBloodType">-</span>
                        </div>
                    </div>
                </div>

                <!-- Appointment Information -->
                <div class="info-section info-section-gray">
                    <h3 class="info-section-title">
                        <i data-lucide="calendar"></i>
                        Appointment Information
                    </h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Appointment ID:</span>
                            <span class="info-value" id="modalAppointmentId">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date:</span>
                            <span class="info-value" id="modalDate">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Time:</span>
                            <span class="info-value" id="modalTime">-</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="info-value"><span id="modalStatus" class="status-badge-new">-</span></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">QR Code:</span>
                            <span class="info-value qr-code-text" id="modalQrCode">-</span>
                        </div>
                    </div>
                </div>

                <!-- Medical Information -->
                <div class="info-section info-section-green">
                    <h3 class="info-section-title">
                        <i data-lucide="activity"></i>
                        Medical Information
                    </h3>
                    <div class="info-item-full">
                        <span class="info-label">Symptoms:</span>
                        <p id="modalSymptoms" class="info-text">-</p>
                    </div>
                    <div class="info-item-full">
                        <span class="info-label">Notes:</span>
                        <p id="modalNotes" class="info-text">-</p>
                    </div>
                </div>

                <!-- Modal Actions -->
                <div class="modal-actions" id="modalActions">
                    <!-- Action buttons will be inserted here by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script src="appointments.js"></script>
    <script>
        // Initialize Lucide icons
        lucide.createIcons();
    </script>
</body>
</html>