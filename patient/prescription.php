<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

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
$patient = $patientStmt->get_result()->fetch_assoc();

if (!$patient) {
    redirect('patient/profile.php');
}

$patientId = $patient['patient_id'];

// Fetch prescriptions with doctor details
$prescriptionsStmt = $conn->prepare("
    SELECT 
        pr.*,
        d.first_name as doctor_fname,
        d.last_name as doctor_lname,
        d.specialization,
        d.phone as doctor_phone,
        d.profile_picture as doctor_profile_picture,
        d.gender as doctor_gender,
        a.appointment_date,
        a.appointment_time
    FROM prescriptions pr
    JOIN doctors d ON pr.doctor_id = d.doctor_id
    LEFT JOIN appointments a ON pr.appointment_id = a.appointment_id
    WHERE pr.patient_id = ?
    ORDER BY pr.prescription_date DESC
");
$prescriptionsStmt->bind_param("i", $patientId);
$prescriptionsStmt->execute();
$prescriptions = $prescriptionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch medications for each prescription
foreach ($prescriptions as &$prescription) {
    $medsStmt = $conn->prepare("
        SELECT * FROM prescription_medications 
        WHERE prescription_id = ?
        ORDER BY medication_id ASC
    ");
    $medsStmt->bind_param("i", $prescription['prescription_id']);
    $medsStmt->execute();
    $prescription['medications'] = $medsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Calculate statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT pr.prescription_id) as total_prescriptions,
        COUNT(pm.medication_id) as total_medications,
        COUNT(DISTINCT pr.doctor_id) as total_doctors,
        MAX(pr.prescription_date) as latest_prescription
    FROM prescriptions pr
    LEFT JOIN prescription_medications pm ON pr.prescription_id = pm.prescription_id
    WHERE pr.patient_id = ?
");
$statsStmt->bind_param("i", $patientId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Prescriptions - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="prescription.css">
    
    <!-- Add jsPDF library for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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
                    <h1>💊 My Prescriptions</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;View and manage your medical prescriptions</p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success fade-in">
                    <span class="alert-icon">✅</span>
                    <div class="alert-content">
                        <strong>Success!</strong>
                        <p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p>
                    </div>
                    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error fade-in">
                    <span class="alert-icon">❌</span>
                    <div class="alert-content">
                        <strong>Error!</strong>
                        <p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p>
                    </div>
                    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon prescriptions">
                        <span>📋</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_prescriptions'] ?? 0; ?></h3>
                        <p>Total Prescriptions</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon medications">
                        <span>💊</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_medications'] ?? 0; ?></h3>
                        <p>Medications Prescribed</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon doctors">
                        <span>👨‍⚕️</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_doctors'] ?? 0; ?></h3>
                        <p>Prescribing Doctors</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon recent">
                        <span>📅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['latest_prescription'] ? date('M d', strtotime($stats['latest_prescription'])) : 'N/A'; ?></h3>
                        <p>Latest Prescription</p>
                    </div>
                </div>
            </div>

            <!-- Prescriptions List -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h2><span>📋</span> Prescription History</h2>
                    <div class="header-actions">
                        <input type="text" id="searchInput" class="search-input" placeholder="Search prescriptions..." onkeyup="searchPrescriptions()">
                    </div>
                </div>
                
                <div class="prescriptions-container">
                    <?php if (!empty($prescriptions)): ?>
                        <?php foreach ($prescriptions as $prescription): ?>
                            <div class="prescription-card" data-prescription-id="<?php echo $prescription['prescription_id']; ?>">
                                <!-- Prescription Header -->
                                <div class="prescription-header">
                                    <div class="prescription-info">
                                        <div class="prescription-number">
                                            <span class="icon">🧾</span>
                                            <span class="number">Rx #<?php echo str_pad($prescription['prescription_id'], 6, '0', STR_PAD_LEFT); ?></span>
                                        </div>
                                        <div class="prescription-date">
                                            <span class="icon">📅</span>
                                            <span><?php echo date('M d, Y', strtotime($prescription['prescription_date'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="prescription-actions">
                                        <button class="btn-viewMore" onclick="viewPrescriptionDetails(<?php echo $prescription['prescription_id']; ?>)" title="View Details">
                                            <span>View More</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Doctor Information -->
                                <div class="doctor-section">
                                    <div class="doctor-avatar">
                                        <?php if (!empty($prescription['doctor_profile_picture']) && file_exists(__DIR__ . '/../' . $prescription['doctor_profile_picture'])): ?>
                                            <img src="../<?php echo htmlspecialchars($prescription['doctor_profile_picture']); ?>" 
                                                 alt="Dr. <?php echo htmlspecialchars($prescription['doctor_fname'] . ' ' . $prescription['doctor_lname']); ?>"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <span class="fallback-icon" style="display: none;">
                                                <?php echo ($prescription['doctor_gender'] === 'female') ? '👩‍⚕️' : '👨‍⚕️'; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="fallback-icon">
                                                <?php echo ($prescription['doctor_gender'] === 'female') ? '👩‍⚕️' : '👨‍⚕️'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doctor-details">
                                        <h3>Dr. <?php echo htmlspecialchars($prescription['doctor_fname'] . ' ' . $prescription['doctor_lname']); ?></h3>
                                        <p class="specialization"><?php echo htmlspecialchars($prescription['specialization']); ?></p>
                                        <p class="contact"><span>📞</span> <?php echo htmlspecialchars($prescription['doctor_phone']); ?></p>
                                    </div>
                                </div>

                                <!-- Diagnosis -->
                                <div class="diagnosis-section">
                                    <h4><span>🔍</span> Diagnosis</h4>
                                    <p><?php echo htmlspecialchars($prescription['diagnosis']); ?></p>
                                </div>

                                <!-- Medications List -->
                                <div class="medications-section">
                                    <h4><span>💊</span> Medications (<?php echo count($prescription['medications']); ?>)</h4>
                                    <div class="medications-list">
                                        <?php foreach ($prescription['medications'] as $medication): ?>
                                            <div class="medication-item">
                                                <div class="medication-header">
                                                    <span class="medication-name"><?php echo htmlspecialchars($medication['medication_name']); ?></span>
                                                    <span class="medication-dosage"><?php echo htmlspecialchars($medication['dosage']); ?></span>
                                                </div>
                                                <div class="medication-details">
                                                    <div class="detail-row">
                                                        <span class="detail-label">⏰ Frequency:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($medication['frequency']); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">📆 Duration:</span>
                                                        <span class="detail-value"><?php echo htmlspecialchars($medication['duration']); ?></span>
                                                    </div>
                                                    <?php if (!empty($medication['instructions'])): ?>
                                                        <div class="detail-row instructions">
                                                            <span class="detail-label">📝 Instructions:</span>
                                                            <span class="detail-value"><?php echo htmlspecialchars($medication['instructions']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Additional Notes -->
                                <?php if (!empty($prescription['notes'])): ?>
                                    <div class="notes-section">
                                        <h4><span>📌</span> Additional Notes</h4>
                                        <p><?php echo nl2br(htmlspecialchars($prescription['notes'])); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">💊</div>
                            <h3>No Prescriptions Yet</h3>
                            <p>Your prescription history will appear here after doctor consultations</p>
                            <a href="appointment.php" class="btn btn-primary">
                                <span>📅</span> Book an Appointment
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Prescription Details Modal -->
    <div id="prescriptionModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>💊 Prescription Details</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" onclick="downloadCurrentPrescription()">
                    <span>📄</span> Download PDF
                </button>
            </div>
        </div>
    </div>

    <script src="prescription.js"></script>
</body>
</html>