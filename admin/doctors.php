<?php
session_start();
require_once __DIR__ . '/../config.php';

/** @var mysqli $conn */
$conn = Database::getInstance()->getConnection();

$message = '';
$messageType = '';

// Image Upload Preview
function handleImageUpload($file) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) throw new Exception("Invalid image type.");
    
    $uploadDir = '../uploads/profiles/'; 
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'doctor_' . bin2hex(random_bytes(4)) . '_' . time() . '.' . $extension;
    $targetPath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/profiles/' . $fileName; 
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            $conn->begin_transaction();
            
            $imgPath = handleImageUpload($_FILES['profile_picture'] ?? null);
            $email = trim($_POST['email']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Insert User
            $stmt = $conn->prepare("INSERT INTO users (email, password_hash, user_type, status) VALUES (?, ?, 'doctor', 'active')");
            $stmt->bind_param("ss", $email, $password);
            $stmt->execute();
            $new_user_id = $conn->insert_id;

            // Insert Doctor
            $stmt = $conn->prepare("INSERT INTO doctors (user_id, first_name, last_name, specialization, license_number, phone, consultation_fee, ic_number, profile_picture, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("issssssss", $new_user_id, $_POST['first_name'], $_POST['last_name'], $_POST['specialization'], $_POST['license_number'], $_POST['phone'], $_POST['consultation_fee'], $_POST['ic_number'], $imgPath);
            $stmt->execute();
            
            $conn->commit();
            $message = "Doctor registered successfully.";
            $messageType = "success";

        } elseif ($action === 'edit') {
            $doctor_id = intval($_POST['doctor_id']);
            $user_id = intval($_POST['user_id']);
            $conn->begin_transaction();
            
            $imgPath = handleImageUpload($_FILES['profile_picture'] ?? null);

            // Update User Email
            $stmt = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
            $stmt->bind_param("si", $_POST['email'], $user_id);
            $stmt->execute();

            if ($imgPath) {
                $stmt = $conn->prepare("UPDATE doctors SET first_name=?, last_name=?, specialization=?, license_number=?, phone=?, consultation_fee=?, ic_number=?, profile_picture=? WHERE doctor_id=?");
                $stmt->bind_param("ssssssssi", $_POST['first_name'], $_POST['last_name'], $_POST['specialization'], $_POST['license_number'], $_POST['phone'], $_POST['consultation_fee'], $_POST['ic_number'], $imgPath, $doctor_id);
            } else {
                $stmt = $conn->prepare("UPDATE doctors SET first_name=?, last_name=?, specialization=?, license_number=?, phone=?, consultation_fee=?, ic_number=? WHERE doctor_id=?");
                $stmt->bind_param("sssssssi", $_POST['first_name'], $_POST['last_name'], $_POST['specialization'], $_POST['license_number'], $_POST['phone'], $_POST['consultation_fee'], $_POST['ic_number'], $doctor_id);
            }
            $stmt->execute();

            $conn->commit();
            $message = "Doctor profile updated.";
            $messageType = "success";

        } elseif ($action === 'delete') {
            $user_id = intval($_POST['user_id']);
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "Doctor removed from system.";
            $messageType = "success";

        } elseif ($action === 'toggle_status') {
            $stmt = $conn->prepare("UPDATE doctors SET status = ? WHERE doctor_id = ?");
            $stmt->bind_param("si", $_POST['new_status'], $_POST['doctor_id']);
            $stmt->execute();
            $message = "Doctor status updated.";
            $messageType = "success";
        }
    } catch (Exception $e) {
        @$conn->rollback();
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// Search and Filter
$search = $_GET['search'] ?? '';
$specFilter = $_GET['specialization'] ?? '';

$query = "SELECT d.*, u.email FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE 1=1";
$params = [];
$types = "";

if ($search) {
    $query .= " AND (d.first_name LIKE ? OR d.last_name LIKE ? OR d.license_number LIKE ?)";
    $st = "%$search%";
    array_push($params, $st, $st, $st);
    $types .= "sss";
}
if ($specFilter) {
    $query .= " AND d.specialization = ?";
    $params[] = $specFilter;
    $types .= "s";
}

$query .= " ORDER BY d.first_name ASC";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$doctors = $result->fetch_all(MYSQLI_ASSOC);

// Stats
$statsQuery = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,
    COUNT(DISTINCT specialization) as specs
FROM doctors");
$stats = $statsQuery->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Doctor Management - CarePlus</title>
    <link rel="stylesheet" href="doctors.css">
    <!-- jsPDF Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="container" style="padding-top: 100px;">
        <div class="page-header">
            <div class="header-content">
                <h1>👨‍⚕️ Doctor Management</h1>
                <p>&emsp;&emsp;&emsp;&nbsp;Register and manage medical specialists</p>
            </div>
            <div class="header-right">
                <button class="btn btn-outline" onclick="exportDoctorsCSV()">
                    <span>📥</span> Export CSV
                </button>
                <button class="btn btn-outline" onclick="exportDoctorsPDF()">
                    <span>📄</span> Export PDF
                </button>
                <button class="btn btn-primary" onclick="openAddModal()">
                    ➕ Add New Doctor
                </button>
            </div>
        </div>

        <?php if($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #DBEAFE; color: #3B82F6;">👨‍⚕️</div>
                <div class="stat-content"><h3><?= $stats['total'] ?></h3><p>Total Doctors</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #DCFCE7; color: #16A34A;">✅</div>
                <div class="stat-content"><h3><?= $stats['active'] ?></h3><p>Active Now</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #FEF3C7; color: #D97706;">🩺</div>
                <div class="stat-content"><h3><?= $stats['specs'] ?></h3><p>Specializations</p></div>
            </div>
        </div>

        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name or license..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="specialization">
                    <option value="">All Specialties</option>
                    <option value="General Practitioner">General Practitioner</option>
                    <option value="Cardiologist">Cardiologist</option>
                    <option value="Pediatrician">Pediatrician</option>
                    <option value="Neurologist">Neurologist</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
            </form>
        </div>

        <div class="table-container">
            <table class="doctors-table">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Specialization</th>
                        <th>License No.</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors as $doc): ?>
                    <tr>
                        <td>
                            <div class="doc-img">
                                <?php if (!empty($doc['profile_picture'])): ?>
                                    <img src="../<?= htmlspecialchars($doc['profile_picture']) ?>" alt="Doctor">
                                <?php else: ?>
                                    <span><?= substr($doc['first_name'], 0, 1) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <strong>Dr. <?= htmlspecialchars($doc['first_name'].' '.$doc['last_name']) ?></strong><br>
                            <small><?= $doc['email'] ?></small>
                        </td>
                        <td><?= htmlspecialchars($doc['specialization']) ?></td>
                        <td><code><?= htmlspecialchars($doc['license_number']) ?></code></td>
                        <td><?= htmlspecialchars($doc['phone']) ?></td>
                        <td><span class="status-badge status-<?= $doc['status'] ?>"><?= ucfirst($doc['status']) ?></span></td>
                        <td class="actions">
                            <button class="btn-action btn-edit" onclick='editDoctor(<?= json_encode($doc) ?>)' title="Edit">✏️</button>
                            <button class="btn-action btn-toggle" onclick="toggleStatus(<?= $doc['doctor_id'] ?>, '<?= $doc['status'] ?>')" title="<?= $doc['status'] === 'active' ? 'Lock (Deactivate)' : 'Unlock (Activate)' ?>">
                                <?= $doc['status'] === 'active' ? '🔒' : '🔓' ?>
                            </button>
                            <button class="btn-action btn-delete" onclick="deleteDoctor(<?= $doc['user_id'] ?>, 'Dr. <?= $doc['last_name'] ?>')" title="Delete">🗑️</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Doctor Management Modal -->
    <div id="doctorModal" class="modal">
        <div class="modal-backdrop" onclick="closeModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">👨‍⚕️ Register New Doctor</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            
            <div class="modal-body">
                <form id="doctorForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="doctor_id" id="docId">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <!-- Section: Profile Picture -->
                    <div class="form-section">
                        <h3>📸 Profile Picture</h3>
                        <div class="form-group">
                            <div style="display: flex; gap: 20px; align-items: center;">
                                <div id="imagePreview" class="image-preview-box" onclick="document.getElementById('profilePicture').click()">
                                    <span>+</span>
                                </div>
                                <input type="file" name="profile_picture" id="profilePicture" accept="image/*" onchange="previewImage(this)" style="display: none;">
                                <div style="flex: 1;">
                                    <p style="margin: 0; font-size: 14px; color: #374151; font-weight: 600;">Click box to upload photo</p>
                                    <p style="margin: 5px 0 0; font-size: 13px; color: #9ca3af;">JPG, PNG or WEBP (Max 5MB)</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Personal Info -->
                    <div class="form-section">
                        <h3>👤 Personal Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name <span style="color: red;">*</span></label>
                                <input type="text" name="first_name" id="fName" required>
                                <small class="error-message" id="fName_error"></small>
                            </div>
                            <div class="form-group">
                                <label>Last Name <span style="color: red;">*</span></label>
                                <input type="text" name="last_name" id="lName" required>
                                <small class="error-message" id="lName_error"></small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>IC Number <span style="color: red;">*</span></label>
                                <input type="text" name="ic_number" id="ic" placeholder="e.g. 900101145566" required>
                                <small class="error-message" id="ic_error"></small>
                            </div>
                            <div class="form-group">
                                <label>Phone <span style="color: red;">*</span></label>
                                <input type="tel" name="phone" id="phone" placeholder="e.g. 0123456789" required>
                                <small class="error-message" id="phone_error"></small>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Account Info -->
                    <div class="form-section">
                        <h3>🔐 Account Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email Address <span style="color: red;">*</span></label>
                                <input type="email" name="email" id="email" required>
                                <small class="error-message" id="email_error"></small>
                            </div>
                            <div class="form-group" id="passGroup">
                                <label id="passLabel">Password <span style="color: red;">*</span></label>
                                <input type="password" name="password" id="pass" minlength="6">
                                <small class="form-helper-text" id="passHelper">Minimum 6 characters</small>
                                <small class="error-message" id="pass_error"></small>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Professional Info -->
                    <div class="form-section" style="border-bottom: none; margin-bottom: 0;">
                        <h3>🩺 Professional Details</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Specialization <span style="color: red;">*</span></label>
                                <input type="text" name="specialization" id="spec" list="specializationList" placeholder="Select or type..." required>
                                <datalist id="specializationList">
                                    <option value="General Practitioner">
                                    <option value="Cardiologist">
                                    <option value="Pediatrician">
                                    <option value="Neurologist">
                                    <option value="Dermatologist">
                                </datalist>
                                <small class="error-message" id="spec_error"></small>
                            </div>
                            <div class="form-group">
                                <label>License Number <span style="color: red;">*</span></label>
                                <input type="text" name="license_number" id="license" placeholder="e.g. MMC12345" required>
                                <small class="error-message" id="license_error"></small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Consultation Fee (RM) <span style="color: red;">*</span></label>
                            <input type="number" step="0.01" name="consultation_fee" id="fee" min="0" placeholder="0.00" required>
                            <small class="error-message" id="fee_error"></small>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-saveDoctor" id="submitBtn" onclick="submitDoctorForm()">Save Doctor</button>
            </div>
        </div>
    </div>

    <!-- Hidden Action Forms -->
    <form id="deleteForm" method="POST" style="display:none;"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" id="delUserId"></form>
    <form id="statusForm" method="POST" style="display:none;"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="doctor_id" id="statDocId"><input type="hidden" name="new_status" id="statNewVal"></form>

    <script src="doctors.js"></script>
</body>
</html>