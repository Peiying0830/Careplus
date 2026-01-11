<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

$conn = Database::getInstance()->getConnection(); 
$userId = getUserId();

// Fetch doctor info
$doctorStmt = $conn->prepare("SELECT doctor_id, first_name, last_name, specialization FROM doctors WHERE user_id = ? LIMIT 1");
$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();

if (!$doctor) {
    redirect('doctor/profile.php');
}
$doctorId = $doctor['doctor_id'];

// Handle POST REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action']) && $_POST['action'] === 'create_prescription') {
        try {
            $patientId = intval($_POST['patient_id']);
            $diagnosis = trim($_POST['diagnosis']);
            $notes = trim($_POST['notes'] ?? '');
            $medications = json_decode($_POST['medications'], true);
            $overrideWarnings = isset($_POST['override_warnings']) && $_POST['override_warnings'] === 'true';
            
            if (!$patientId || empty($diagnosis) || empty($medications)) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            
            $conn->begin_transaction();
            $verificationCode = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
            $validUntil = date('Y-m-d', strtotime('+30 days'));
            
            $prescStmt = $conn->prepare("INSERT INTO prescriptions (patient_id, doctor_id, diagnosis, notes, verification_code, valid_until, status, prescription_date) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
            $prescStmt->bind_param("iissss", $patientId, $doctorId, $diagnosis, $notes, $verificationCode, $validUntil);
            $prescStmt->execute();
            $prescriptionId = $conn->insert_id;
            
            $medStmt = $conn->prepare("INSERT INTO prescription_medications (prescription_id, medication_name, dosage, frequency, duration, instructions, quantity_prescribed) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($medications as $med) {
                $q = $med['quantity'] ?? 1;
                $medStmt->bind_param("isssssi", $prescriptionId, $med['name'], $med['dosage'], $med['frequency'], $med['duration'], $med['instructions'], $q);
                $medStmt->execute();
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Created', 'prescription_id' => $prescriptionId, 'verification_code' => $verificationCode]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'cancel_prescription') {
        $prescriptionId = intval($_POST['prescription_id']);
        $reason = "\nCancellation Reason: " . trim($_POST['reason'] ?? '');
        $stmt = $conn->prepare("UPDATE prescriptions SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ?) WHERE prescription_id = ? AND doctor_id = ?");
        $stmt->bind_param("sii", $reason, $prescriptionId, $doctorId);
        $stmt->execute();
        echo json_encode(['success' => $conn->affected_rows > 0]);
        exit;
    }
}

// Fetch prescriptions for UI
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

$sql = "SELECT pr.*, p.first_name as patient_first_name, p.last_name as patient_last_name, 
        p.profile_picture, p.gender, TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as patient_age,
        (SELECT COUNT(*) FROM prescription_medications WHERE prescription_id = pr.prescription_id) as medication_count
        FROM prescriptions pr
        LEFT JOIN patients p ON pr.patient_id = p.patient_id
        WHERE pr.doctor_id = ?";

$params = [$doctorId];
$types = "i";

if ($statusFilter !== 'all') {
    $sql .= " AND pr.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($searchQuery) {
    $sql .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR pr.diagnosis LIKE ?)";
    $s = "%$searchQuery%";
    array_push($params, $s, $s);
    $types .= "ss";
}

$sql .= " ORDER BY pr.prescription_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate Stat Logic
$totalPrescriptions = count($prescriptions);
$activePrescriptions = 0;
$thisMonth = 0;
$thisWeek = 0;

$startOfMonth = strtotime('first day of this month 00:00:00');
$startOfWeek = strtotime('monday this week 00:00:00');

foreach ($prescriptions as $p) {
    if ($p['status'] === 'active') $activePrescriptions++;
    
    $pTime = strtotime($p['prescription_date']);
    if ($pTime >= $startOfMonth) $thisMonth++;
    if ($pTime >= $startOfWeek) $thisWeek++;
}

// Fetch patients for dropdown
$patStmt = $conn->prepare("SELECT patient_id, first_name, last_name, profile_picture, date_of_birth, gender, allergies FROM patients");
$patStmt->execute();
$patients = $patStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Placeholder for catalog 
$medicationCatalog = [
    ['medicine_name' => 'Paracetamol', 'dosage_form' => 'Tablet', 'strength' => '500mg'],
    ['medicine_name' => 'Ibuprofen', 'dosage_form' => 'Tablet', 'strength' => '400mg']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="prescriptions.css">
    <!-- Core jsPDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/headerNav.php'; ?>

    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header fade-in-up">
                <div class="header-main">
                    <div class="header-title-wrapper">
                        <span class="header-emoji">💊</span>
                        <div class="header-texts">
                            <h1>Prescription Management</h1>
                            <p>Create and manage patient prescriptions with safety checks</p>
                        </div>
                    </div>
                </div>
                
                <div class="header-stats-glass">
                    <div class="glass-stat-item">
                        <span class="number"><?php echo $totalPrescriptions; ?></span><br>
                        <span class="label">Total</span>
                    </div>
                    <div class="stat-item">
                        <span class="number">&emsp;<?php echo $activePrescriptions; ?></span><br>
                        <span class="label">Active</span>
                    </div>
                    <div class="stat-item">
                        <span class="number">&emsp;&emsp;<?php echo $thisMonth; ?></span><br>
                        <span class="label">This Month</span>
                    </div>
                    <div class="stat-item">
                        <span class="number">&emsp;&emsp;<?php echo $thisWeek; ?></span><br>
                        <span class="label">This Week</span>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar fade-in-up">
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchInput" placeholder="Search by patient name, diagnosis, or verification code...">
                </div>
                
                <select id="statusFilter" class="status-filter">
                    <option value="all">All Status</option>
                    <option value="active">Active</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                
                <button class="btn btn-primary" id="btnNewPrescription">
                    ➕ New Prescription
                </button>
            </div>

            <!-- Prescriptions List -->
            <div class="prescriptions-list" id="prescriptionsList">
                <?php if (!empty($prescriptions)): ?>
                    <?php foreach ($prescriptions as $prescription): ?>
                        <?php
                            $patientName = htmlspecialchars($prescription['patient_first_name'] . ' ' . $prescription['patient_last_name']);
                            $initials = strtoupper(substr($prescription['patient_first_name'], 0, 1) . substr($prescription['patient_last_name'], 0, 1));
                            
                            $statusColors = [
                                'active' => 'status-active',
                                'fulfilled' => 'status-fulfilled',
                                'expired' => 'status-expired',
                                'cancelled' => 'status-cancelled'
                            ];
                            $statusClass = $statusColors[$prescription['status']] ?? 'status-active';
                        ?>
                        <div class="prescription-card fade-in-up" 
                             data-prescription-id="<?php echo $prescription['prescription_id']; ?>"
                             data-patient-name="<?php echo strtolower($patientName); ?>"
                             data-diagnosis="<?php echo strtolower(htmlspecialchars($prescription['diagnosis'])); ?>"
                             data-status="<?php echo $prescription['status']; ?>">
                            
                            <div class="prescription-header">
                                <div class="patient-info">
                                    <div class="patient-avatar">
                                        <?php 
                                        $imgPath = $prescription['profile_picture']; 
                                        $fullImgPath = !empty($imgPath) ? '../' . $imgPath : null; 
                                        
                                        if ($fullImgPath && file_exists(__DIR__ . '/../' . $imgPath)): ?>
                                            <img src="<?php echo htmlspecialchars($fullImgPath); ?>" 
                                                alt="<?php echo $patientName; ?>" 
                                                style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                        <?php else: ?>
                                            <div class="avatar-initials"><?php echo $initials; ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3><?php echo $patientName; ?></h3>
                                        <div class="patient-meta">
                                            <span><?php echo $prescription['patient_age']; ?> years</span>
                                            <span>•</span>
                                            <span><?php echo ucfirst($prescription['gender']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="prescription-status">
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($prescription['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="prescription-body">
                                <div class="info-row">
                                    <span class="info-icon">📅</span>
                                    <div>
                                        <span class="info-label">Date</span>
                                        <span class="info-value"><?php echo date('M d, Y', strtotime($prescription['prescription_date'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-icon">🔐</span>
                                    <div>
                                        <span class="info-label">Verification Code</span>
                                        <span class="info-value verification-code">
                                            <?php echo htmlspecialchars($prescription['verification_code'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($prescription['diagnosis'])): ?>
                                    <div class="info-row">
                                        <span class="info-icon">🩺</span>
                                        <div>
                                            <span class="info-label">Diagnosis</span>
                                            <span class="info-value"><?php echo htmlspecialchars($prescription['diagnosis']); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="info-row">
                                    <span class="info-icon">💊</span>
                                    <div>
                                        <span class="info-label">Medications</span>
                                        <span class="info-value"><?php echo $prescription['medication_count']; ?> medication(s)</span>
                                    </div>
                                </div>
                                
                                <?php if ($prescription['valid_until']): ?>
                                    <div class="info-row">
                                        <span class="info-icon">⏳</span>
                                        <div>
                                            <span class="info-label">Valid Until</span>
                                            <span class="info-value"><?php echo date('M d, Y', strtotime($prescription['valid_until'])); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="prescription-footer">
                                <button class="btn-view" data-prescription-id="<?php echo $prescription['prescription_id']; ?>">
                                    👁️ View
                                </button>
                                <?php if ($prescription['status'] === 'active'): ?>
                                    <button class="btn-edit" data-prescription-id="<?php echo $prescription['prescription_id']; ?>">
                                        ✏️ Edit
                                    </button>
                                <?php endif; ?>
                                <button class="btn-download" data-prescription-id="<?php echo $prescription['prescription_id']; ?>">
                                    📄 Download
                                </button>
                                <?php if ($prescription['status'] === 'active'): ?>
                                    <button class="btn-cancel" data-prescription-id="<?php echo $prescription['prescription_id']; ?>">
                                        ❌ Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">💊</div>
                        <h3>No Prescriptions Yet</h3>
                        <p>Start by creating your first prescription</p>
                        <button class="btn btn-primary" onclick="document.getElementById('btnNewPrescription').click()">
                            ➕ Create Prescription
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- New/Edit Prescription Modal -->
    <div class="modal" id="newPrescriptionModal">
        <div class="modal-content">
            <!-- Header -->
            <div class="modal-header">
                <h2>➕ Create New Prescription</h2>
                <button class="modal-close">&times;</button>
            </div>
            
            <!-- Scrollable Body -->
            <div class="modal-body">
                <form id="prescriptionForm">
                    <!-- Patient Selection -->
                    <div class="form-section">
                        <h3>👤 Patient Information</h3>
                        <div class="form-group">
                            <label for="patient_id">Select Patient *</label>
                            <select name="patient_id" id="patientSelect" required>
                                <option value="">-- Select a Patient --</option>
                                <?php foreach ($patients as $patient): ?>
                                    <?php 
                                        // Calculate Age
                                        $birthDate = new DateTime($patient['date_of_birth']);
                                        $today = new DateTime();
                                        $age = $today->diff($birthDate)->y;

                                        // Handle Profile Picture Path
                                        // Database stores: uploads/profiles/img.jpg
                                        // PHP needs: ../uploads/profiles/img.jpg
                                        $photoPath = '';
                                        if (!empty($patient['profile_picture'])) {
                                            $photoPath = '../' . htmlspecialchars($patient['profile_picture']);
                                        }
                                    ?>
                                    <option value="<?php echo $patient['patient_id']; ?>"
                                            data-photo="<?php echo $photoPath; ?>"
                                            data-allergies="<?php echo htmlspecialchars($patient['allergies'] ?? ''); ?>"
                                            data-name="<?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>">
                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                        (<?php echo $age; ?> years, <?php echo ucfirst($patient['gender']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <!-- Patient Preview -->
                            <div id="selectedPatientPreview" style="display: none; align-items: center; margin-top: 15px; padding: 10px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <div id="previewPhoto" style="width: 45px; height: 45px; border-radius: 50%; overflow: hidden; margin-right: 12px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #64748b; flex-shrink: 0;">
                                </div>
                                <div>
                                    <div id="previewName" style="font-weight: 600; color: #1e293b; font-size: 0.95rem;"></div>
                                    <div style="font-size: 0.8rem; color: #64748b;">Selected Patient</div>
                                </div>
                            </div>
                        </div>

                        <!-- Allergies Warning -->
                        <div class="patient-allergies" id="patientAllergiesDisplay" style="display: none;">
                            <h4>⚠️ Patient Allergies</h4>
                            <p id="patientAllergiesText"></p>
                        </div>
                    </div>

                    <!-- Diagnosis -->
                    <div class="form-section">
                        <h3>🩺 Diagnosis</h3>
                        <div class="form-group">
                            <label for="diagnosis">Diagnosis *</label>
                            <textarea name="diagnosis" id="diagnosis" required maxlength="500" 
                                      placeholder="Enter patient diagnosis..."></textarea>
                            <span class="char-counter">0 / 500</span>
                        </div>
                    </div>

                    <!-- Medications -->
                    <div class="form-section">
                        <h3>💊 Medications</h3>
                        <div id="medicationsContainer" class="medications-container">
                            <!-- Medication fields added dynamically -->
                        </div>
                        <button type="button" class="btn btn-outline" id="btnAddMedication">
                            ➕ Add Another Medication
                        </button>
                    </div>

                    <!-- Additional Notes -->
                    <div class="form-section">
                        <h3>📝 Additional Notes (Optional)</h3>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea name="notes" id="notes" 
                                      placeholder="Any additional instructions or notes..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Fixed Footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close-btn">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSubmitPrescription">
                    ✓ Create Prescription
                </button>
            </div>
        </div>
    </div>

    <!-- View Prescription Modal -->
    <div class="modal" id="viewPrescriptionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📄 Prescription Details</h2>
                <button class="modal-close">&times;</button>
            </div>
            
            <div class="modal-body">
                <div id="prescriptionDetails">
                    <!-- Details loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <script>
        window.doctorInfo = <?php echo json_encode([
            'name' => $doctor['first_name'] . ' ' . $doctor['last_name'],
            'specialization' => $doctor['specialization']
        ]); ?>;
        
        window.medicationCatalog = <?php echo json_encode($medicationCatalog); ?>;
    </script>

    <script src="prescriptions.js"></script>
</body>
</html>