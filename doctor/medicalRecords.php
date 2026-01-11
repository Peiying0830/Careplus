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

// Get filter parameters
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterDate = isset($_GET['date']) ? $_GET['date'] : '';
$filterPatient = isset($_GET['patient_id']) ? $_GET['patient_id'] : '';

// Build query for medical records dynamically
$query = "
    SELECT mr.*, 
           p.first_name as patient_fname, 
           p.last_name as patient_lname,
           p.profile_picture,
           p.date_of_birth,
           p.blood_type,
           p.phone,
           p.gender,
           d.first_name as doctor_fname,
           d.last_name as doctor_lname,
           a.appointment_time
    FROM medical_records mr
    JOIN patients p ON mr.patient_id = p.patient_id
    JOIN doctors d ON mr.doctor_id = d.doctor_id
    LEFT JOIN appointments a ON mr.appointment_id = a.appointment_id
    WHERE mr.doctor_id = ?
";

$params = [$doctorId];
$types = "i"; // first param is doctor_id (int)

if ($searchQuery) {
    $query .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR mr.diagnosis LIKE ? OR mr.symptoms LIKE ?)";
    $searchParam = "%$searchQuery%";
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= "ssss";
}

if ($filterDate) {
    $query .= " AND DATE(mr.visit_date) = ?";
    $params[] = $filterDate;
    $types .= "s";
}

if ($filterPatient) {
    $query .= " AND mr.patient_id = ?";
    $params[] = (int)$filterPatient;
    $types .= "i";
}

$query .= " ORDER BY mr.visit_date DESC, mr.created_at DESC";

$recordsStmt = $conn->prepare($query);
$recordsStmt->bind_param($types, ...$params); // Unpacking operator for dynamic binding
$recordsStmt->execute();
$medicalRecords = $recordsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_records,
        COUNT(CASE WHEN DATE(visit_date) = CURDATE() THEN 1 END) as today_records,
        COUNT(DISTINCT patient_id) as total_patients,
        COUNT(CASE WHEN prescription IS NOT NULL AND prescription != '' THEN 1 END) as with_prescription,
        COUNT(CASE WHEN lab_results IS NOT NULL AND lab_results != '' THEN 1 END) as with_lab_results,
        COUNT(CASE WHEN visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week
    FROM medical_records 
    WHERE doctor_id = ?
");
$statsStmt->bind_param("i", $doctorId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// Get recent patients
$recentPatientsStmt = $conn->prepare("
    SELECT p.*, 
           COUNT(mr.record_id) as total_records,
           MAX(mr.visit_date) as last_visit_date
    FROM patients p
    JOIN medical_records mr ON p.patient_id = mr.patient_id
    WHERE mr.doctor_id = ?
    GROUP BY p.patient_id
    ORDER BY last_visit_date DESC
    LIMIT 10
");
$recentPatientsStmt->bind_param("i", $doctorId);
$recentPatientsStmt->execute();
$recentPatients = $recentPatientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all patients for dropdown
$allPatientsStmt = $conn->prepare("
    SELECT DISTINCT p.patient_id, p.first_name, p.last_name, p.phone, p.blood_type
    FROM patients p
    JOIN appointments a ON p.patient_id = a.patient_id
    WHERE a.doctor_id = ?
    ORDER BY p.first_name, p.last_name
");
$allPatientsStmt->bind_param("i", $doctorId);
$allPatientsStmt->execute();
$allPatients = $allPatientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Records - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="medicalRecords.css">
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="header-content">
                    <h1>📋 Medical Records</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Comprehensive patient medical history and documentation</p>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <span class="number"><?php echo $stats['total_records']; ?></span>
                        <span class="label">Total Records</span>
                    </div>
                    <div class="stat-item">
                        <span class="number"><?php echo $stats['today_records']; ?></span>
                        <span class="label">Today</span>
                    </div>
                    <div class="stat-item">
                        <span class="number"><?php echo $stats['total_patients']; ?></span>
                        <span class="label">Patients</span>
                    </div>
                </div>
            </div>

            <!-- Action Bar with Filters -->
            <div class="action-bar fade-in">
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Search by patient name, diagnosis, or symptoms..." 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                
                <input type="date" 
                       id="filterDate" 
                       class="filter-date"
                       value="<?php echo htmlspecialchars($filterDate); ?>"
                       placeholder="Filter by date">
                
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <span>&emsp; &nbsp;🔄</span> Clear &emsp; &nbsp;
                </button>
                
                <button class="btn btn-primary" onclick="openAddRecordModal()">
                    <span>➕</span> New Record
                </button>
            </div>

            <!-- Main Content Grid -->
            <div class="records-layout">
                <!-- Medical Records List -->
                <div class="records-section fade-in">
                    <?php if (!empty($medicalRecords)): ?>
                        <div class="records-list">
                            <?php foreach ($medicalRecords as $record): ?>
                                <div class="record-card" data-record-id="<?php echo $record['record_id']; ?>">
                                    <div class="record-header">
                                        <div class="patient-info">
                                            <div class="patient-avatar">
                                                <?php 
                                                    $patientPic = $record['profile_picture'];
                                                    if (!empty($patientPic) && file_exists(__DIR__ . '/../' . $patientPic)): ?>
                                                        <img src="../<?php echo htmlspecialchars($patientPic); ?>" 
                                                            alt="Patient" 
                                                            style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                    <?php else: ?>
                                                        <span><?php echo strtoupper(substr($record['patient_fname'], 0, 1)); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <div>
                                                <h3><?php echo htmlspecialchars($record['patient_fname'] . ' ' . $record['patient_lname']); ?></h3>
                                                <div class="patient-meta">
                                                    <span>📞 <?php echo htmlspecialchars($record['phone']); ?></span>
                                                    <?php if ($record['blood_type']): ?>
                                                        <span>🩸 <?php echo htmlspecialchars($record['blood_type']); ?></span>
                                                    <?php endif; ?>
                                                    <span><?php echo $record['gender'] === 'male' ? '👨' : '👩'; ?> <?php echo ucfirst($record['gender']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="record-date">
                                            <span class="date-label">Visit Date</span>
                                            <span class="date-value"><?php echo date('M d, Y', strtotime($record['visit_date'])); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="record-body">
                                        <?php if ($record['symptoms']): ?>
                                            <div class="info-row">
                                                <span class="info-icon">💊</span>
                                                <div>
                                                    <span class="info-label">Symptoms</span>
                                                    <span class="info-value"><?php echo htmlspecialchars(substr($record['symptoms'], 0, 100)); ?><?php echo strlen($record['symptoms']) > 100 ? '...' : ''; ?></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($record['diagnosis']): ?>
                                            <div class="info-row">
                                                <span class="info-icon">🔬</span>
                                                <div>
                                                    <span class="info-label">Diagnosis</span>
                                                    <span class="info-value"><?php echo htmlspecialchars(substr($record['diagnosis'], 0, 100)); ?><?php echo strlen($record['diagnosis']) > 100 ? '...' : ''; ?></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="record-badges">
                                            <?php if ($record['prescription']): ?>
                                                <span class="badge badge-prescription">💊 Prescription</span>
                                            <?php endif; ?>
                                            <?php if ($record['lab_results']): ?>
                                                <span class="badge badge-lab">🧪 Lab Results</span>
                                            <?php endif; ?>
                                            <?php if ($record['appointment_id']): ?>
                                                <span class="badge badge-appointment">📅 From Appointment</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($record['notes']): ?>
                                            <div class="notes-section">
                                                <span class="info-label">📝 Notes</span>
                                                <p class="notes-text"><?php echo nl2br(htmlspecialchars(substr($record['notes'], 0, 150))); ?><?php echo strlen($record['notes']) > 150 ? '...' : ''; ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="record-footer">
                                        <button class="btn-view" onclick="viewRecord(<?php echo $record['record_id']; ?>)">
                                            <span>👁️</span> View Details
                                        </button>
                                        <button class="btn-edit" onclick="editRecord(<?php echo $record['record_id']; ?>)">
                                            <span>&emsp;&nbsp;✏️</span> Edit&nbsp;&emsp;
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">📋</div>
                            <h3>No Medical Records Found</h3>
                            <p><?php echo $searchQuery || $filterDate ? 'Try adjusting your search or filters' : 'Start by adding a new medical record for your patients'; ?></p>
                            <button class="btn btn-primary" onclick="<?php echo $searchQuery || $filterDate ? 'clearFilters()' : 'openAddRecordModal()'; ?>">
                                <span><?php echo $searchQuery || $filterDate ? '🔄' : '➕'; ?></span> <?php echo $searchQuery || $filterDate ? 'Clear Filters' : 'Add First Record'; ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar - Recent Patients -->
                <aside class="sidebar-section fade-in">
                    <div class="sidebar-card">
                        <div class="sidebar-header">
                            <h3>👥 Recent Patients</h3>
                        </div>
                        <div class="sidebar-body">
                            <?php if (!empty($recentPatients)): ?>
                                <div class="patients-list">
                                    <?php foreach ($recentPatients as $patient): ?>
                                        <div class="patient-item" onclick="filterByPatient(<?php echo $patient['patient_id']; ?>)">
                                            <div class="patient-avatar-sm">
                                                <?php 
                                                $sidebarPic = $patient['profile_picture'];
                                                if (!empty($sidebarPic) && file_exists(__DIR__ . '/../' . $sidebarPic)): ?>
                                                    <img src="../<?php echo htmlspecialchars($sidebarPic); ?>" 
                                                        alt="Patient" 
                                                        style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                <?php else: ?>
                                                    <span><?php echo strtoupper(substr($patient['first_name'], 0, 1)); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="patient-details">
                                                <h4><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h4>
                                                <div class="patient-stats">
                                                    <span><?php echo $patient['total_records']; ?> record<?php echo $patient['total_records'] != 1 ? 's' : ''; ?></span>
                                                    <span>Last: <?php echo date('M d', strtotime($patient['last_visit_date'])); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state-sm">
                                    <p>No patients with records yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="sidebar-card">
                        <div class="sidebar-header">
                            <h3>📊 Statistics</h3>
                        </div>
                        <div class="sidebar-body">
                            <div class="quick-stats">
                                <div class="quick-stat-item">
                                    <span class="stat-icon">📅</span>
                                    <div>
                                        <span class="stat-number"><?php echo $stats['this_week']; ?></span>
                                        <span class="stat-label">This Week</span>
                                    </div>
                                </div>
                                <div class="quick-stat-item">
                                    <span class="stat-icon">💊</span>
                                    <div>
                                        <span class="stat-number"><?php echo $stats['with_prescription']; ?></span>
                                        <span class="stat-label">With Prescription</span>
                                    </div>
                                </div>
                                <div class="quick-stat-item">
                                    <span class="stat-icon">🧪</span>
                                    <div>
                                        <span class="stat-number"><?php echo $stats['with_lab_results']; ?></span>
                                        <span class="stat-label">With Lab Results</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </main>
    </div>

    <!-- Add/Edit Record Modal -->
    <div id="recordModal" class="modal">
        <div class="modal-content">
            <!-- Modal Header -->
            <div class="modal-header">
                <h2 id="modalTitle">📋 Add Medical Record</h2>
                <button class="modal-close" onclick="closeRecordModal()">×</button>
            </div>

            <!-- Modal Body (Scrollable) -->
            <div class="modal-body">
                <form id="recordForm">
                    <input type="hidden" id="recordId" name="record_id">
                    
                    <div class="form-section">
                        <h3>👤 Patient Information</h3>
                        <div class="form-group">
                            <label for="patientSelect">Select Patient *</label>
                            <select id="patientSelect" name="patient_id" required>
                                <option value="">Choose a patient...</option>
                                <?php foreach ($allPatients as $patient): ?>
                                    <?php 
                                        $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
                                        $photoPath = !empty($patient['profile_picture']) ? '../' . htmlspecialchars($patient['profile_picture']) : '';
                                    ?>
                                    <option value="<?php echo $patient['patient_id']; ?>"
                                            data-photo="<?php echo $photoPath; ?>"
                                            data-name="<?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>"
                                            data-initials="<?php echo $initials; ?>">
                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?> 
                                        - <?php echo htmlspecialchars($patient['phone']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <!-- Patient Preview (Same as Prescription design) -->
                            <div id="selectedPatientPreview" style="display: none; align-items: center; margin-top: 15px; padding: 12px; background: #f8fafc; border-radius: 16px; border: 1px solid #e2e8f0;">
                                <div id="previewPhoto" class="modal-patient-avatar" style="width: 50px; height: 50px; min-width: 50px; font-size: 1.2rem; margin-right: 15px;">
                                </div>
                                <div>
                                    <div id="previewName" style="font-weight: 700; color: #2d3748; font-size: 1rem;"></div>
                                    <div style="font-size: 0.85rem; color: #718096;">Patient Selected</div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="visitDate">Visit Date *</label>
                            <input type="date" id="visitDate" name="visit_date" required>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>🔬 Medical Information</h3>
                        <div class="form-group">
                            <label for="symptoms">Symptoms</label>
                            <textarea id="symptoms" name="symptoms" rows="3" placeholder="Describe patient's symptoms..."></textarea>
                        </div>
                        <div class="form-group">
                            <label for="diagnosis">Diagnosis *</label>
                            <textarea id="diagnosis" name="diagnosis" rows="3" required placeholder="Enter diagnosis..."></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>💊 Treatment & Results</h3>
                        <div class="form-group">
                            <label for="prescription">Prescription</label>
                            <textarea id="prescription" name="prescription" rows="3" placeholder="Enter prescription details..."></textarea>
                        </div>
                        <div class="form-group">
                            <label for="labResults">Lab Results</label>
                            <textarea id="labResults" name="lab_results" rows="3" placeholder="Enter lab results..."></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>📝 Additional Notes</h3>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Any additional notes or observations..."></textarea>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Modal Footer (Fixed) -->
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeRecordModal()">Cancel</button>
                <button type="submit" form="recordForm" class="btn btn-primary">
                    <span>💾</span> Save Medical Record
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden data for JavaScript -->
    <div id="records-data" style="display: none;">
        <?php echo json_encode($medicalRecords); ?>
    </div>

    <!-- jsPDF Library for PDF Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    
    <script src="medicalRecords.js"></script>
</body>
</html>