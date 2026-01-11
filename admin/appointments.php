<?php
session_start();
require_once __DIR__ . '/../config.php';

// Get the MySQLi connection from your Database singleton
$conn = Database::getInstance()->getConnection();

$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            $conn->begin_transaction();
            
            // Generate unique QR code
            $qr_code = 'APT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12));
            
            $stmt = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, reason, symptoms, notes, qr_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            // Assign variables for bind_param (Best practice for MySQLi references)
            $p_id = $_POST['patient_id'];
            $d_id = $_POST['doctor_id'];
            $a_date = $_POST['appointment_date'];
            $a_time = $_POST['appointment_time'];
            $status = $_POST['status'] ?? 'pending';
            $reason = !empty($_POST['reason']) ? $_POST['reason'] : null;
            $symptoms = !empty($_POST['symptoms']) ? $_POST['symptoms'] : null;
            $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;
            
            $stmt->bind_param("iisssssss", $p_id, $d_id, $a_date, $a_time, $status, $reason, $symptoms, $notes, $qr_code);
            $stmt->execute();
            
            $conn->commit();
            $message = "Appointment created successfully.";
            $messageType = "success";
            
        } elseif ($action === 'edit') {
            $appointment_id = (int)$_POST['appointment_id'];
            
            $stmt = $conn->prepare("UPDATE appointments SET patient_id=?, doctor_id=?, appointment_date=?, appointment_time=?, status=?, reason=?, symptoms=?, notes=? WHERE appointment_id=?");
            
            $p_id = $_POST['patient_id'];
            $d_id = $_POST['doctor_id'];
            $a_date = $_POST['appointment_date'];
            $a_time = $_POST['appointment_time'];
            $status = $_POST['status'];
            $reason = !empty($_POST['reason']) ? $_POST['reason'] : null;
            $symptoms = !empty($_POST['symptoms']) ? $_POST['symptoms'] : null;
            $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;
            
            $stmt->bind_param("iissssssi", $p_id, $d_id, $a_date, $a_time, $status, $reason, $symptoms, $notes, $appointment_id);
            $stmt->execute();
            
            $message = "Appointment updated successfully.";
            $messageType = "success";
            
        } elseif ($action === 'delete') {
            $appointment_id = (int)$_POST['appointment_id'];
            $stmt = $conn->prepare("DELETE FROM appointments WHERE appointment_id = ?");
            $stmt->bind_param("i", $appointment_id);
            $stmt->execute();
            $message = "Appointment deleted successfully.";
            $messageType = "success";
            
        } elseif ($action === 'update_status') {
            $appointment_id = (int)$_POST['appointment_id'];
            $new_status = $_POST['new_status'];
            $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
            $stmt->bind_param("si", $new_status, $appointment_id);
            $stmt->execute();
            $message = "Appointment status updated.";
            $messageType = "success";
        }
    } catch (Exception $e) {
        // Corrected rollback logic
        @$conn->rollback();
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// --- Filtering and Query Logic (Your dynamic binding logic below is perfect) ---
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$doctorFilter = $_GET['doctor'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$query = "SELECT a.*, p.first_name as patient_fname, p.last_name as patient_lname, p.phone as patient_phone, p.ic_number as patient_ic, d.first_name as doctor_fname, d.last_name as doctor_lname, d.specialization FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id WHERE 1=1";

$params = [];
$types = "";

if ($search) {
    $query .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.ic_number LIKE ? OR d.first_name LIKE ? OR d.last_name LIKE ?)";
    $st = "%$search%";
    $params = array_merge($params, [$st, $st, $st, $st, $st]);
    $types .= "sssss";
}
if ($statusFilter) { $query .= " AND a.status = ?"; $params[] = $statusFilter; $types .= "s"; }
if ($doctorFilter) { $query .= " AND a.doctor_id = ?"; $params[] = (int)$doctorFilter; $types .= "i"; }
if ($dateFrom) { $query .= " AND a.appointment_date >= ?"; $params[] = $dateFrom; $types .= "s"; }
if ($dateTo) { $query .= " AND a.appointment_date <= ?"; $params[] = $dateTo; $types .= "s"; }

$query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats - Corrected with COALESCE to avoid NULL values
$statsRes = $conn->query("SELECT 
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END), 0) as pending,
    COALESCE(SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END), 0) as confirmed,
    COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END), 0) as completed,
    COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END), 0) as cancelled,
    COALESCE(SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END), 0) as today
FROM appointments");
$stats = $statsRes->fetch_assoc();

// Dropdowns - Corrected fetch method
$patients = $conn->query("SELECT patient_id, first_name, last_name, ic_number FROM patients ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);
$doctors = $conn->query("SELECT doctor_id, first_name, last_name, specialization FROM doctors WHERE status='active' ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appointment Management - CarePlus</title>
    <link rel="stylesheet" href="appointments.css">
    <!-- jsPDF Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="container" style="padding-top: 100px;">
        <div class="page-header">
            <div class="header-left">
                <h1>📅 Appointment Management</h1>
                <p>&emsp;&emsp;&emsp;&nbsp;Schedule and manage patient appointments</p>
            </div>
            <div class="header-right">
                <button class="btn btn-outline" onclick="exportAppointmentsCSV()">
                    <span>📥</span> Export CSV
                </button>
                <button class="btn btn-outline" onclick="exportAppointmentsPDF()">
                    <span>📄</span> Export PDF
                </button>
                <button class="btn btn-outline" onclick="openAddModal()">
                ➕ Schedule New Appointment
                </button>
            </div>
        </div>

        <?php if($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #DBEAFE; color: #418affff;">📋</div>
                <div class="stat-content"><h3><?= $stats['total'] ?></h3><p>Total Appointments</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #FEF3C7; color: #F59E0B;">⏳</div>
                <div class="stat-content"><h3><?= $stats['pending'] ?></h3><p>Pending</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #DCFCE7; color: #16A34A;">✅</div>
                <div class="stat-content"><h3><?= $stats['confirmed'] ?></h3><p>Confirmed</p></div>
            </div>
            <div class="stat-card ">
                <div class="stat-icon" style="background: #E0E7FF; color: #6366F1;">📅</div>
                <div class="stat-content"><h3><?= $stats['today'] ?></h3><p>Today</p></div>
            </div>
        </div>

        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by patient or doctor name..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
                <select name="doctor">
                    <option value="">All Doctors</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?= $doc['doctor_id'] ?>" <?= $doctorFilter == $doc['doctor_id'] ? 'selected' : '' ?>>
                            Dr. <?= htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" placeholder="From Date" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="date" name="date_to" placeholder="To Date" value="<?= htmlspecialchars($dateTo) ?>">
                <button type="submit" class="btn-filter">Filter</button>
            </form>
        </div>

        <div class="table-container">
            <table class="appointments-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Date & Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>QR Code</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $apt): ?>
                    <tr>
                        <td><code>#<?= $apt['appointment_id'] ?></code></td>
                        <td>
                            <strong><?= htmlspecialchars($apt['patient_fname'] . ' ' . $apt['patient_lname']) ?></strong><br>
                            <small><?= htmlspecialchars($apt['patient_ic']) ?></small>
                        </td>
                        <td>
                            <strong>Dr. <?= htmlspecialchars($apt['doctor_fname'] . ' ' . $apt['doctor_lname']) ?></strong><br>
                            <small><?= htmlspecialchars($apt['specialization']) ?></small>
                        </td>
                        <td>
                            <strong><?= date('M d, Y', strtotime($apt['appointment_date'])) ?></strong><br>
                            <small><?= date('h:i A', strtotime($apt['appointment_time'])) ?></small>
                        </td>
                        <td>
                            <span class="reason-text"><?= htmlspecialchars($apt['reason'] ?? 'General Checkup') ?></span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $apt['status'] ?>">
                                <?= ucfirst($apt['status']) ?>
                            </span>
                        </td>
                        <td>
                            <code class="qr-code-text" title="<?= htmlspecialchars($apt['qr_code']) ?>">
                                <?= htmlspecialchars(substr($apt['qr_code'], 0, 12)) ?>...
                            </code>
                        </td>
                        <td class="actions">
                            <button class="btn-action btn-view" onclick='viewAppointment(<?= json_encode($apt) ?>)' title="View Details">👁️</button>
                            <button class="btn-action btn-edit" onclick='editAppointment(<?= json_encode($apt) ?>)' title="Edit">✏️</button>
                            <button class="btn-action btn-status" onclick="showStatusModal(<?= $apt['appointment_id'] ?>, '<?= $apt['status'] ?>')" title="Change Status">🔄</button>
                            <button class="btn-action btn-delete" onclick="deleteAppointment(<?= $apt['appointment_id'] ?>, '<?= htmlspecialchars($apt['patient_fname']) ?>')" title="Delete">🗑️</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Appointment Modal -->
    <div id="appointmentModal" class="modal">
        <div class="modal-backdrop" onclick="closeModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">📅 Schedule New Appointment</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
                        
            <div class="modal-body">
                <form id="appointmentForm" method="POST">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="appointment_id" id="appointmentId">
                    
                    <div class="form-section">
                        <h3>📅 Appointment Details</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Patient <span style="color: red;">*</span></label>
                                <select name="patient_id" id="patientId" required>
                                    <option value="">Select Patient</option>
                                    <?php foreach ($patients as $patient): ?>
                                        <option value="<?= $patient['patient_id'] ?>">
                                            <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?> - <?= $patient['ic_number'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Doctor <span style="color: red;">*</span></label>
                                <select name="doctor_id" id="doctorId" required>
                                    <option value="">Select Doctor</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <option value="<?= $doctor['doctor_id'] ?>">
                                            Dr. <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?> - <?= $doctor['specialization'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Date <span style="color: red;">*</span></label>
                                <input type="date" name="appointment_date" id="appointmentDate" required>
                            </div>
                            <div class="form-group">
                                <label>Time <span style="color: red;">*</span></label>
                                <input type="time" name="appointment_time" id="appointmentTime" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="status">
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="no-show">No-Show</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Reason</label>
                                <input type="text" name="reason" id="reason" placeholder="e.g., General Checkup">
                            </div>
                        </div>
                    </div>

                    <div class="form-section" style="border-bottom: none;">
                        <h3>📝 Additional Information</h3>
                        <div class="form-group">
                            <label>Symptoms</label>
                            <textarea name="symptoms" id="symptoms" rows="3" placeholder="Describe patient symptoms..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" id="notes" rows="3" placeholder="Additional notes..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-saveAppointment" id="submitBtn" onclick="submitAppointmentForm()">Save Appointment</button>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-backdrop" onclick="closeViewModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2>🔍 Appointment Details</h2>
                <button class="modal-close" onclick="closeViewModal()">×</button>
            </div>
            <div class="modal-body" id="viewContent">
                <!-- Content injected by JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeViewModal()">Close</button>
                <button type="button" class="btn-download" onclick="downloadAppointmentPDF()">📄 Download PDF</button>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-backdrop" onclick="closeStatusModal()"></div>
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>🔄 Update Status</h2>
                <button class="modal-close" onclick="closeStatusModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="statusUpdateForm" method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="appointment_id" id="statusAppointmentId">
                    <div class="form-group">
                        <label>Select New Status</label>
                        <select name="new_status" id="newStatus" required>
                            <option value="pending">⏳ Pending</option>
                            <option value="confirmed">✅ Confirmed</option>
                            <option value="completed">✔️ Completed</option>
                            <option value="cancelled">❌ Cancelled</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeStatusModal()">Cancel</button>
                <button type="button" class="btn-updateStatus" onclick="document.getElementById('statusUpdateForm').submit()">Update Status</button>
            </div>
        </div>
    </div>

    <!-- Hidden Delete Form -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="appointment_id" id="delAppointmentId">
    </form>

    <script src="appointments.js"></script>
</body>
</html>