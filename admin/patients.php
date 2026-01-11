<?php
session_start();
require_once __DIR__ . '/../config.php';

$message = '';
$messageType = '';

// Image Upload Function
function handleImageUpload($file) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) throw new Exception("Invalid image type.");
    
    $uploadDir = '../uploads/profiles/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'patient_' . bin2hex(random_bytes(4)) . '_' . time() . '.' . $extension;
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
            
            // Insert into users
            $stmt = $conn->prepare("INSERT INTO users (email, password_hash, user_type, status) VALUES (?, ?, 'patient', 'active')");
            $stmt->bind_param("ss", $email, $password);
            $stmt->execute();
            $new_user_id = $conn->insert_id;

            // Insert into patients
            $stmt = $conn->prepare("INSERT INTO patients (user_id, first_name, last_name, ic_number, date_of_birth, gender, phone, address, blood_type, allergies, medical_conditions, emergency_contact, profile_picture) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $fname = $_POST['first_name'];
            $lname = $_POST['last_name'];
            $ic = $_POST['ic_number'];
            $dob = $_POST['date_of_birth'];
            $gender = $_POST['gender'];
            $phone = $_POST['phone'];
            $addr = $_POST['address'] ?? null;
            $blood = $_POST['blood_type'] ?? null;
            $allerg = $_POST['allergies'] ?? null;
            $med_cond = $_POST['medical_conditions'] ?? null;
            $em_contact = $_POST['emergency_contact'] ?? null;

            $stmt->bind_param("issssssssssss", $new_user_id, $fname, $lname, $ic, $dob, $gender, $phone, $addr, $blood, $allerg, $med_cond, $em_contact, $imgPath);
            $stmt->execute();
            
            $conn->commit();
            $message = "Patient registered successfully.";
            $messageType = "success";

        } elseif ($action === 'edit') {
            $patient_id = intval($_POST['patient_id']);
            $user_id = intval($_POST['user_id']);
            $conn->begin_transaction();
            
            $imgPath = handleImageUpload($_FILES['profile_picture'] ?? null);

            // Update user email
            $stmt = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
            $email = $_POST['email'];
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();

            $fname = $_POST['first_name'];
            $lname = $_POST['last_name'];
            $ic = $_POST['ic_number'];
            $dob = $_POST['date_of_birth'];
            $gender = $_POST['gender'];
            $phone = $_POST['phone'];
            $addr = $_POST['address'] ?? null;
            $blood = $_POST['blood_type'] ?? null;
            $allerg = $_POST['allergies'] ?? null;
            $med_cond = $_POST['medical_conditions'] ?? null;
            $em_contact = $_POST['emergency_contact'] ?? null;

            if ($imgPath) {
                $stmt = $conn->prepare("UPDATE patients SET first_name=?, last_name=?, ic_number=?, date_of_birth=?, gender=?, phone=?, address=?, blood_type=?, allergies=?, medical_conditions=?, emergency_contact=?, profile_picture=? WHERE patient_id=?");
                $stmt->bind_param("ssssssssssssi", $fname, $lname, $ic, $dob, $gender, $phone, $addr, $blood, $allerg, $med_cond, $em_contact, $imgPath, $patient_id);
            } else {
                $stmt = $conn->prepare("UPDATE patients SET first_name=?, last_name=?, ic_number=?, date_of_birth=?, gender=?, phone=?, address=?, blood_type=?, allergies=?, medical_conditions=?, emergency_contact=? WHERE patient_id=?");
                $stmt->bind_param("sssssssssssi", $fname, $lname, $ic, $dob, $gender, $phone, $addr, $blood, $allerg, $med_cond, $em_contact, $patient_id);
            }
            $stmt->execute();

            $conn->commit();
            $message = "Patient profile updated.";
            $messageType = "success";

        } elseif ($action === 'delete') {
            $user_id = intval($_POST['user_id']);
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "Patient removed from system.";
            $messageType = "success";

        } elseif ($action === 'toggle_status') {
            $user_id = intval($_POST['user_id']);
            $new_status = $_POST['new_status'];
            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
            $stmt->bind_param("si", $new_status, $user_id);
            $stmt->execute();
            $message = "Patient status updated.";
            $messageType = "success";
        }
    } catch (Exception $e) {
        @$conn->rollback();
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// Search and Filters
$search = $_GET['search'] ?? '';
$genderFilter = $_GET['gender'] ?? '';
$bloodTypeFilter = $_GET['blood_type'] ?? '';

$query = "SELECT p.*, u.email, u.status FROM patients p JOIN users u ON p.user_id = u.user_id WHERE 1=1";
$params = [];
$types = "";

if ($search) {
    $query .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.ic_number LIKE ? OR p.phone LIKE ?)";
    $st = "%$search%";
    array_push($params, $st, $st, $st, $st);
    $types .= "ssss";
}
if ($genderFilter) {
    $query .= " AND p.gender = ?";
    $params[] = $genderFilter;
    $types .= "s";
}
if ($bloodTypeFilter) {
    $query .= " AND p.blood_type = ?";
    $params[] = $bloodTypeFilter;
    $types .= "s";
}

$query .= " ORDER BY p.created_at DESC";
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$patients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$statsRes = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN u.status='active' THEN 1 ELSE 0 END) as active,
    COUNT(DISTINCT p.blood_type) as blood_types,
    COUNT(CASE WHEN p.gender='male' THEN 1 END) as male,
    COUNT(CASE WHEN p.gender='female' THEN 1 END) as female
FROM patients p 
JOIN users u ON p.user_id = u.user_id");
$stats = $statsRes->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patient Management - CarePlus</title>
    <link rel="stylesheet" href="patients.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="container" style="padding-top: 100px;">
        <div class="page-header">
            <div class="header-left">
                <h1>🤒 Patient Management</h1>
                <p>&emsp;&emsp;&emsp;&nbsp;Register and manage patient records</p>
            </div>
            <div class="header-right">
                <button class="btn btn-outline" onclick="exportPatientsCSV()">
                    <span>📥</span> Export CSV
                </button>
                <button class="btn btn-outline" onclick="exportPatientsPDF()">
                    <span>📄</span> Export PDF
                </button>
                <button class="btn btn-primary" onclick="openAddModal()">
                 ➕ Add New Patient
                </button>
            </div>
        </div>

        <?php if($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #DBEAFE; color: #3B82F6;">😷</div>
                <div class="stat-content"><h3><?= $stats['total'] ?></h3><p>Total Patients</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #DCFCE7; color: #16A34A;">✅</div>
                <div class="stat-content"><h3><?= $stats['active'] ?></h3><p>Active Patients</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #E0E7FF; color: #6366F1;">♂️</div>
                <div class="stat-content"><h3><?= $stats['male'] ?></h3><p>Male Patients</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #FCE7F3; color: #EC4899;">♀️</div>
                <div class="stat-content"><h3><?= $stats['female'] ?></h3><p>Female Patients</p></div>
            </div>
        </div>

        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by name, IC, or phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="gender">
                    <option value="">All Genders</option>
                    <option value="male" <?= $genderFilter === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= $genderFilter === 'female' ? 'selected' : '' ?>>Female</option>
                </select>
                <select name="blood_type">
                    <option value="">All Blood Types</option>
                    <option value="A+">A+</option>
                    <option value="A-">A-</option>
                    <option value="B+">B+</option>
                    <option value="B-">B-</option>
                    <option value="O+">O+</option>
                    <option value="O-">O-</option>
                    <option value="AB+">AB+</option>
                    <option value="AB-">AB-</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
            </form>
        </div>

        <div class="table-container">
            <table class="patients-table">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>IC Number</th>
                        <th>Age/Gender</th>
                        <th>Blood Type</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patients as $patient): ?>
                    <?php
                        $age = date_diff(date_create($patient['date_of_birth']), date_create('today'))->y;
                    ?>
                    <tr>
                        <td>
                            <div class="patient-img">
                                <?php if (!empty($patient['profile_picture'])): ?>
                                    <img src="../<?= htmlspecialchars($patient['profile_picture']) ?>" alt="Patient">
                                <?php else: ?>
                                    <span><?= substr($patient['first_name'], 0, 1) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($patient['first_name'].' '.$patient['last_name']) ?></strong><br>
                            <small><?= $patient['email'] ?></small>
                        </td>
                        <td><code><?= htmlspecialchars($patient['ic_number']) ?></code></td>
                        <td><?= $age ?> years / <?= ucfirst($patient['gender']) ?></td>
                        <td><span class="blood-badge"><?= htmlspecialchars($patient['blood_type'] ?? 'N/A') ?></span></td>
                        <td><?= htmlspecialchars($patient['phone']) ?></td>
                        <td><span class="status-badge status-<?= $patient['status'] ?>"><?= ucfirst($patient['status']) ?></span></td>
                        <td class="actions">
                            <button class="btn-action btn-edit" onclick='editPatient(<?= json_encode($patient) ?>)' title="Edit">✏️</button>
                            <button class="btn-action btn-toggle" onclick="toggleStatus(<?= $patient['user_id'] ?>, '<?= $patient['status'] ?>')" title="<?= $patient['status'] === 'active' ? 'Lock (Deactivate)' : 'Unlock (Activate)' ?>">
                                <?= $patient['status'] === 'active' ? '🔒' : '🔓' ?>
                            </button>
                            <button class="btn-action btn-delete" onclick="deletePatient(<?= $patient['user_id'] ?>, '<?= htmlspecialchars($patient['first_name'].' '.$patient['last_name']) ?>')" title="Delete">🗑️</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Patient Management Modal -->
    <div id="patientModal" class="modal">
        <div class="modal-backdrop" onclick="closeModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">➕ Register New Patient</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            
            <div class="modal-body">
                <form id="patientForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="patient_id" id="patientId">
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
                                <input type="text" name="first_name" id="firstName" required>
                                <small class="error-message" id="firstName_error"></small>
                            </div>
                            <div class="form-group">
                                <label>Last Name <span style="color: red;">*</span></label>
                                <input type="text" name="last_name" id="lastName" required>
                                <small class="error-message" id="lastName_error"></small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>IC Number <span style="color: red;">*</span></label>
                                <input type="text" name="ic_number" id="icNumber" placeholder="XXXXXX-XX-XXXX" required>
                                <small class="error-message" id="icNumber_error"></small>
                            </div>
                            <div class="form-group">
                                <label>Date of Birth <span style="color: red;">*</span></label>
                                <input type="date" name="date_of_birth" id="dob" required>
                                <small class="error-message" id="dob_error"></small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Gender <span style="color: red;">*</span></label>
                                <select name="gender" id="gender" required>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Phone Number <span style="color: red;">*</span></label>
                                <input type="tel" name="phone" id="phone" required>
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
                                <input type="password" name="password" id="password">
                                <small class="form-helper-text" id="passHelper">Min. 6 characters</small>
                                <small class="error-message" id="password_error"></small>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Medical Info -->
                    <div class="form-section" style="border-bottom: none;">
                        <h3>🩺 Medical Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Blood Type</label>
                                <select name="blood_type" id="bloodType">
                                    <option value="">Select Blood Type</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Emergency Contact</label>
                                <input type="text" name="emergency_contact" id="emergencyContact" placeholder="Name - Phone">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Home Address</label>
                            <textarea name="address" id="address" rows="2"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Known Allergies</label>
                                <textarea name="allergies" id="allergies" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Medical Conditions</label>
                                <textarea name="medical_conditions" id="medicalConditions" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-savePatient" id="submitBtn" onclick="submitPatientForm()">Save Patient</button>
            </div>
        </div>
    </div>

    <!-- Hidden Action Forms -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" id="delUserId">
    </form>
    <form id="statusForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="user_id" id="statUserId">
        <input type="hidden" name="new_status" id="statNewVal">
    </form>

    <script src="patients.js"></script>
</body>
</html>