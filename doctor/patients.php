<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch doctor info
$doctorStmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id = ? LIMIT 1");
$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctorResult = $doctorStmt->get_result();
$doctor = $doctorResult->fetch_assoc();

if (!$doctor) {
    redirect('doctor/profile.php');
}

$doctorId = $doctor['doctor_id'];

// Fetch all patients who have had appointments with this doctor
$patientsQuery = "
    SELECT DISTINCT
        p.patient_id,
        p.first_name,
        p.last_name,
        p.profile_picture,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.blood_type,
        p.address,
        p.allergies as medical_conditions,
        u.email,
        TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
        COUNT(a.appointment_id) as total_visits,
        MAX(a.appointment_date) as last_visit,
        SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed_visits
    FROM patients p
    LEFT JOIN users u ON p.user_id = u.user_id
    LEFT JOIN appointments a ON p.patient_id = a.patient_id AND a.doctor_id = ?
    WHERE EXISTS (
        SELECT 1 FROM appointments 
        WHERE patient_id = p.patient_id AND doctor_id = ?
    )
    GROUP BY p.patient_id
    ORDER BY last_visit DESC, p.first_name ASC
";

$patientsStmt = $conn->prepare($patientsQuery);
// Bind doctorId twice as it appears twice in the query
$patientsStmt->bind_param("ii", $doctorId, $doctorId);
$patientsStmt->execute();
$patientsResult = $patientsStmt->get_result();
$patients = $patientsResult->fetch_all(MYSQLI_ASSOC);

// Calculate statistics (Logic remains the same as it uses the array)
$totalPatients = count($patients);
$malePatients = 0;
$femalePatients = 0;
$totalVisits = 0;

foreach ($patients as $patient) {
    if (isset($patient['gender'])) {
        $g = strtolower($patient['gender']);
        if ($g === 'male') $malePatients++;
        if ($g === 'female') $femalePatients++;
    }
    $totalVisits += $patient['total_visits'];
}

// Close statements
$doctorStmt->close();
$patientsStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="patients.css">
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header fade-in-up">
                <div>
                    <h1>👥 Patient Management</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;View and manage your patient records</p>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <span class="number"><?php echo $totalPatients; ?></span>
                        <span class="label">Total Patients</span>
                    </div>
                    <div class="stat-item">
                        <span class="number"><?php echo $totalVisits; ?></span>
                        <span class="label">Total Visits</span>
                    </div>
                </div>
            </div>

            <!-- Search & Filter Section -->
            <div class="search-filter-section fade-in-up">
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchInput" placeholder="Search patients by name...">
                </div>
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="bloodTypeFilter">Blood Type</label>
                        <select id="bloodTypeFilter">
                            <option value="all">All Blood Types</option>
                            <option value="A+">A+</option>
                            <option value="A-">A-</option>
                            <option value="B+">B+</option>
                            <option value="B-">B-</option>
                            <option value="AB+">AB+</option>
                            <option value="AB-">AB-</option>
                            <option value="O+">O+</option>
                            <option value="O-">O-</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="genderFilter">Gender</label>
                        <select id="genderFilter">
                            <option value="all">All Genders</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- View Toggle -->
            <div class="view-toggle fade-in-up">
                <button class="view-btn active" data-view="grid">
                    <span>⊞</span> Grid View
                </button>
                <button class="view-btn" data-view="list">
                    <span>☰</span> List View
                </button>
            </div>

            <!-- Grid View -->
            <div class="patients-grid" id="gridView">
                <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $patient): ?>
                        <?php
                            $patientFullName = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
                            $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
                        ?>
                        <div class="patient-card fade-in-up" 
                            data-patient-id="<?php echo $patient['patient_id']; ?>"
                            data-patient-name="<?php echo strtolower($patientFullName); ?>"
                            data-profile-picture="<?php echo htmlspecialchars($patient['profile_picture'] ?? ''); ?>"
                            data-email="<?php echo htmlspecialchars($patient['email'] ?? ''); ?>"
                            data-phone="<?php echo htmlspecialchars($patient['phone'] ?? ''); ?>"
                            data-gender="<?php echo strtolower($patient['gender'] ?? ''); ?>"
                            data-blood-type="<?php echo htmlspecialchars($patient['blood_type'] ?? ''); ?>"
                            data-date-of-birth="<?php echo $patient['date_of_birth']; ?>"
                            data-age="<?php echo $patient['age']; ?>"
                            data-address="<?php echo htmlspecialchars($patient['address'] ?? ''); ?>"
                            data-total-visits="<?php echo $patient['total_visits']; ?>"
                            data-last-visit="<?php echo $patient['last_visit'] ?? ''; ?>"
                            data-medical-conditions="<?php echo htmlspecialchars($patient['medical_conditions'] ?? ''); ?>">
                            
                            <div class="patient-header">
                                <div class="patient-avatar">
                                    <?php if (!empty($patient['profile_picture']) && file_exists(__DIR__ . '/../' . $patient['profile_picture'])): ?>
                                        <img src="../<?php echo htmlspecialchars($patient['profile_picture']); ?>" alt="Profile" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="patient-name-section">
                                    <h3><?php echo $patientFullName; ?></h3>
                                    <div class="patient-id">ID: <?php echo $patient['patient_id']; ?></div>
                                </div>
                            </div> <!-- Correctly closed patient-header -->

                            <div class="patient-details">
                                <div class="detail-row">
                                    <span class="icon">🎂</span>
                                    <span><?php echo $patient['age']; ?> years old</span>
                                </div>
                                <div class="detail-row">
                                    <span class="icon"><?php echo strtolower($patient['gender']) === 'male' ? '♂️' : '♀️'; ?></span>
                                    <span><?php echo ucfirst($patient['gender']); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="icon">🩸</span>
                                    <span><?php echo htmlspecialchars($patient['blood_type'] ?: 'N/A'); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="icon">📞</span>
                                    <span><?php echo htmlspecialchars($patient['phone']); ?></span>
                                </div>
                            </div>

                            <div class="patient-card-footer">
                                <div class="patient-stats-container">
                                    <div class="patient-stats">
                                        <!-- Total Visits Box -->
                                        <div class="stat-box">
                                            <span class="number"><?php echo $patient['total_visits']; ?></span>
                                            <span class="label">Total Visits</span>
                                        </div>
                                        
                                        <!-- Last Visit Box -->
                                        <div class="stat-box">
                                            <span class="number last-visit-date">
                                                <?php echo $patient['last_visit'] ? date('M d, Y', strtotime($patient['last_visit'])) : 'Never'; ?>
                                            </span>
                                            <span class="label">Last Visit</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" id="emptyState">
                        <div class="empty-icon">👥</div>
                        <h3>No Patients Found</h3>
                    </div>
                <?php endif; ?>
            </div>

            <!-- List View -->
            <div class="patients-list" id="listView" style="display: none;">
                <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $patient): ?>
                        <?php
                            $patientFullName = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
                            $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
                        ?>
                        <div class="patient-list-item fade-in-up"
                            data-patient-id="<?php echo $patient['patient_id']; ?>"
                            data-patient-name="<?php echo strtolower($patientFullName); ?>"
                            data-profile-picture="<?php echo htmlspecialchars($patient['profile_picture'] ?? ''); ?>"
                            data-gender="<?php echo strtolower($patient['gender'] ?? ''); ?>"
                            data-blood-type="<?php echo htmlspecialchars($patient['blood_type'] ?? ''); ?>"
                            data-last-visit="<?php echo $patient['last_visit'] ?? ''; ?>">
                            
                            <div class="list-avatar">
                                <?php if (!empty($patient['profile_picture']) && file_exists(__DIR__ . '/../' . $patient['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($patient['profile_picture']); ?>" alt="Profile" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
                                <?php else: ?>
                                    <?php echo $initials; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="list-info">
                                <h3><?php echo $patientFullName; ?></h3>
                                <div class="list-details">
                                    <span>🎂 <?php echo $patient['age']; ?>y</span>
                                    <span><?php echo strtolower($patient['gender']) === 'male' ? '♂️' : '♀️'; ?></span>
                                    <span>🩸 <?php echo htmlspecialchars($patient['blood_type'] ?: 'N/A'); ?></span>
                                    <span>📞 <?php echo htmlspecialchars($patient['phone']); ?></span>
                                    <span>📅 <?php echo $patient['total_visits']; ?> visits</span>
                                    <span style="color: #0ea5e9; font-weight: 600;">🕒 Last: <?php echo $patient['last_visit'] ? date('d/m/y', strtotime($patient['last_visit'])) : 'Never'; ?></span>
                                </div>
                            </div>
                            
                            <div class="list-actions">
                                <button class="btn btn-outline btn-view-details" data-patient-id="<?php echo $patient['patient_id']; ?>">
                                    👁️ Details
                                </button>
                                <button class="btn btn-primary btn-view-medical-records" 
                                        onclick="event.stopPropagation(); viewMedicalRecords(<?php echo $patient['patient_id']; ?>)">
                                    📋 Records
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
    <!-- Patient Details Modal -->
    <div class="modal" id="patientModal">
        <div class="modal-content">
            <!-- Header (Fixed) -->
            <div class="modal-header">
                <h2>📋 Patient Details</h2>
                <button class="modal-close">&times;</button>
            </div>

            <!-- Body (Scrollable) -->
            <div class="modal-body">
                <!-- Patient Profile Card -->
                <div class="patient-profile">
                    <div class="profile-avatar" id="modalAvatar">JD</div>
                    <div class="profile-info">
                        <h2 id="modalPatientName" data-patient-id="">-</h2>
                        <div class="profile-meta">
                            <span id="modalPatientId">ID: -</span>
                            <span id="modalAge">-</span>
                            <span id="modalGender">-</span>
                        </div>
                    </div>
                </div>

                <!-- Detail Sections -->
                <div class="detail-sections">
                    <!-- Personal Information -->
                    <div class="detail-section">
                        <h3>👤 Personal Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Email</span>
                                <span class="info-value" id="modalEmail">-</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Phone</span>
                                <span class="info-value" id="modalPhone">-</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Blood Type</span>
                                <span class="info-value" id="modalBloodType">-</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Date of Birth</span>
                                <span class="info-value" id="modalDOB">-</span>
                            </div>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <span class="info-label">Address</span>
                                <span class="info-value" id="modalAddress">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="detail-section">
                        <h3>💊 Medical Information</h3>
                        <div class="info-item">
                            <span class="info-label">Medical Conditions</span>
                            <p id="modalConditions" style="margin-top: 0.5rem; color: #4A5568; line-height: 1.6;">-</p>
                        </div>
                    </div>

                    <!-- Visit Statistics -->
                    <div class="detail-section">
                        <h3>📊 Visit Statistics</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Total Visits</span>
                                <span class="info-value" id="modalTotalVisits">-</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Visit</span>
                                <span class="info-value" id="modalLastVisit">-</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer (Fixed) -->
            <div class="modal-footer">
                <button class="btn-close">Close</button>
                <button class="btn-viewMedicalRecord" id="btnMedicalRecordsModal">
                    📋 View Medical Records
                </button>
            </div>
        </div>
    </div>

    <script src="patients.js"></script>
</body>
</html>