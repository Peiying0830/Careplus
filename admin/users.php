<?php
session_start();
require_once __DIR__ . '/../config.php';

// Get the MySQLi connection from your Database singleton
$conn = Database::getInstance()->getConnection();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            $conn->begin_transaction();
            
            $email = trim($_POST['email']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $user_type = $_POST['role']; 
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            $phone = trim($_POST['phone']);

            // Insert into main users table
            $stmt = $conn->prepare("INSERT INTO users (email, password_hash, user_type, status) VALUES (?, ?, ?, 'active')");
            $stmt->bind_param("sss", $email, $password, $user_type);
            $stmt->execute();
            $new_user_id = $conn->insert_id; // Get last insert ID in MySQLi

            // Insert into respective profile table
            if ($user_type === 'admin') {
                $stmt = $conn->prepare("INSERT INTO admins (user_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)");
            } elseif ($user_type === 'doctor') {
                $stmt = $conn->prepare("INSERT INTO doctors (user_id, first_name, last_name, phone, license_number) VALUES (?, ?, ?, ?, 'PENDING')");
            } else {
                $stmt = $conn->prepare("INSERT INTO patients (user_id, first_name, last_name, phone, ic_number, date_of_birth, gender) VALUES (?, ?, ?, ?, 'PENDING', '2000-01-01', 'male')");
            }
            
            $stmt->bind_param("isss", $new_user_id, $firstName, $lastName, $phone);
            $stmt->execute();
            
            $conn->commit();
            $message = 'User added successfully!';
            $messageType = 'success';

        } elseif ($action === 'edit') {
            $userId = $_POST['user_id'];
            $email = trim($_POST['email']);
            $user_type = $_POST['role'];
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            $phone = trim($_POST['phone']);
            
            $conn->begin_transaction();
            
            // Update User Credentials
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET email=?, password_hash=?, user_type=? WHERE user_id=?");
                $stmt->bind_param("sssi", $email, $password, $user_type, $userId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET email=?, user_type=? WHERE user_id=?");
                $stmt->bind_param("ssi", $email, $user_type, $userId);
            }
            $stmt->execute();

            // Update respective profile tables (Update all to ensure consistency)
            $profAdmin = $conn->prepare("UPDATE admins SET first_name=?, last_name=?, phone=? WHERE user_id=?");
            $profAdmin->bind_param("sssi", $firstName, $lastName, $phone, $userId);
            $profAdmin->execute();

            $profDoc = $conn->prepare("UPDATE doctors SET first_name=?, last_name=?, phone=? WHERE user_id=?");
            $profDoc->bind_param("sssi", $firstName, $lastName, $phone, $userId);
            $profDoc->execute();

            $profPat = $conn->prepare("UPDATE patients SET first_name=?, last_name=?, phone=? WHERE user_id=?");
            $profPat->bind_param("sssi", $firstName, $lastName, $phone, $userId);
            $profPat->execute();
            
            $conn->commit();
            $message = 'User updated successfully!';
            $messageType = 'success';
            
        } elseif ($action === 'delete') {
            $userId = (int)$_POST['user_id'];
            if ($userId == $_SESSION['user_id']) throw new Exception('Cannot delete your own account!');
            
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            $message = 'User deleted successfully!';
            $messageType = 'success';
            
        } elseif ($action === 'toggle_status') {
            $userId = (int)$_POST['user_id'];
            $newStatus = $_POST['new_status'];
            
            $stmt = $conn->prepare("UPDATE users SET status=? WHERE user_id=?");
            $stmt->bind_param("si", $newStatus, $userId);
            $stmt->execute();
            
            $message = 'User status updated!';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        @$conn->rollback();
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch Logic
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$query = "SELECT u.user_id, u.email, u.user_type, u.status, u.created_at,
          COALESCE(a.first_name, d.first_name, p.first_name) as first_name,
          COALESCE(a.last_name, d.last_name, p.last_name) as last_name,
          COALESCE(a.phone, d.phone, p.phone) as phone
          FROM users u
          LEFT JOIN admins a ON u.user_id = a.user_id
          LEFT JOIN doctors d ON u.user_id = d.user_id
          LEFT JOIN patients p ON u.user_id = p.user_id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (u.email LIKE ? OR a.first_name LIKE ? OR d.first_name LIKE ? OR p.first_name LIKE ?)";
    $st = "%$search%";
    array_push($params, $st, $st, $st, $st);
    $types .= "ssss";
}
if (!empty($roleFilter)) { 
    $query .= " AND u.user_type = ?"; 
    $params[] = $roleFilter; 
    $types .= "s";
}
if (!empty($statusFilter)) { 
    $query .= " AND u.status = ?"; 
    $params[] = $statusFilter; 
    $types .= "s";
}

$query .= " ORDER BY u.created_at DESC";
$stmt = $conn->prepare($query);

// Handle dynamic parameter binding in MySQLi
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats 
$statsQuery = $conn->query("SELECT COUNT(*) as total_users, 
    SUM(CASE WHEN user_type='patient' THEN 1 ELSE 0 END) as total_patients,
    SUM(CASE WHEN user_type='doctor' THEN 1 ELSE 0 END) as total_doctors,
    SUM(CASE WHEN user_type='admin' THEN 1 ELSE 0 END) as total_admins,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_users FROM users");
$stats = $statsQuery->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - CarePlus Admin</title>
    <link rel="stylesheet" href="users.css">
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="container">
        <!-- Modern Header -->
        <div class="page-header">
        <div class="header-left">
            <h1>👥 User Management</h1>
            <p>&emsp;&emsp;&emsp;&nbsp;&nbsp;&nbsp;Manage system users, roles, and permissions</p>
        </div>
        <div class="header-right">
            <button class="btn btn-outline" onclick="exportUsersCSV()">
                <span>📥</span> Export CSV
            </button>
            <button class="btn btn-outline" onclick="exportUsersPDF()">
                <span>📄</span> Export PDF
            </button>
            <button class="btn btn-primary" onclick="openAddModal()">
            ➕ Add New User
            </button>
        </div>
    </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType; ?>" id="alertMessage"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Modern Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #FFEDD5; color: #F97316;">👥</div>
                <div class="stat-content">
                    <h3><?= $stats['total_users']; ?></h3>
                    <p>Total Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #D1FAE5; color: #10B981;">🤒</div>
                <div class="stat-content">
                    <h3><?= $stats['total_patients']; ?></h3>
                    <p>Patients</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #DBEAFE; color: #3B82F6;">👨‍⚕️</div>
                <div class="stat-content">
                    <h3><?= $stats['total_doctors']; ?></h3>
                    <p>Doctors</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #DCFCE7; color: #22C55E;">✅</div>
                <div class="stat-content">
                    <h3><?= $stats['active_users']; ?></h3>
                    <p>Active Users</p>
                </div>
            </div>
        </div>

        <!-- Modern Filter Bar -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search users..." value="<?= htmlspecialchars($search); ?>">
                </div>
                <select name="role">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="doctor" <?= $roleFilter === 'doctor' ? 'selected' : ''; ?>>Doctor</option>
                    <option value="patient" <?= $roleFilter === 'patient' ? 'selected' : ''; ?>>Patient</option>
                </select>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
            </form>
        </div>
        <!-- Table -->
        <div class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['user_id']; ?></td>
                        <td><strong><?= htmlspecialchars($user['email']); ?></strong></td>
                        <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                        <td><?= htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                        <td><span class="badge badge-<?= $user['user_type']; ?>"><?= ucfirst($user['user_type']); ?></span></td>
                        <td><span class="status-badge status-<?= $user['status']; ?>"><?= ucfirst($user['status']); ?></span></td>
                        <td><?= date('M d, Y', strtotime($user['created_at'])); ?></td>
                        <td class="actions">
                            <button class="btn-action btn-edit" onclick='editUser(<?= json_encode($user); ?>)'>✏️</button>
                            <button class="btn-action btn-toggle" onclick="toggleStatus(<?= $user['user_id']; ?>, '<?= $user['status']; ?>')">
                                <?= $user['status'] === 'active' ? '🔒' : '🔓'; ?>
                            </button>
                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                <button class="btn-action btn-delete" onclick="deleteUser(<?= $user['user_id']; ?>, '<?= $user['email']; ?>')">🗑️</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Add User Modal -->
<div class="modal" id="addModal">
    <div class="modal-backdrop" onclick="closeAddModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>👤 Add New User</h2>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="addUserForm" onsubmit="return validateAddForm(event)">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name <span style="color: red;">*</span></label>
                        <input type="text" name="first_name" id="add_first_name" autocomplete="given-name" required>
                        <small class="error-message" id="add_first_name_error"></small>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span style="color: red;">*</span></label>
                        <input type="text" name="last_name" id="add_last_name" autocomplete="family-name" required>
                        <small class="error-message" id="add_last_name_error"></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email <span style="color: red;">*</span></label>
                    <input type="email" name="email" id="add_email" autocomplete="email" required>
                    <small class="error-message" id="add_email_error"></small>
                </div>
                
                <div class="form-group">
                    <label>Password <span style="color: red;">*</span></label>
                    <input type="password" name="password" id="add_password" autocomplete="new-password" required minlength="6">
                    <small class="form-helper-text">Minimum 6 characters</small>
                    <small class="error-message" id="add_password_error"></small>
                </div>
                
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" id="add_phone" autocomplete="tel" pattern="[0-9+\-\s()]*">
                    <small class="error-message" id="add_phone_error"></small>
                </div>
                
                <div class="form-group">
                    <label>Role <span style="color: red;">*</span></label>
                    <select name="role" id="add_role" autocomplete="off" required>
                        <option value="">-- Select Role --</option>
                        <option value="patient">Patient</option>
                        <option value="doctor">Doctor</option>
                        <option value="admin">Admin</option>
                    </select>
                    <small class="error-message" id="add_role_error"></small>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeAddModal()">Cancel</button>
            <button type="button" class="btn-updateUser" onclick="submitAddForm()">Add User</button>
        </div>
    </div>
</div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-backdrop" onclick="closeEditModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ Edit User</h2>
                <button class="modal-close" onclick="closeEditModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editUserForm" onsubmit="return validateEditForm(event)">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name <span style="color: red;">*</span></label>
                            <input type="text" name="first_name" id="edit_first_name" autocomplete="given-name" required>
                            <small class="error-message" id="edit_first_name_error"></small>
                        </div>
                        <div class="form-group">
                            <label>Last Name <span style="color: red;">*</span></label>
                            <input type="text" name="last_name" id="edit_last_name" autocomplete="family-name" required>
                            <small class="error-message" id="edit_last_name_error"></small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Email <span style="color: red;">*</span></label>
                        <input type="email" name="email" id="edit_email" autocomplete="email" required>
                        <small class="error-message" id="edit_email_error"></small>
                    </div>
                    
                    <div class="form-group">
                        <label>Password (leave blank to keep current)</label>
                        <input type="password" name="password" id="edit_password" autocomplete="new-password" placeholder="Leave blank to keep current password" minlength="6">
                        <small class="form-helper-text">Minimum 6 characters (if changing)</small>
                        <small class="error-message" id="edit_password_error"></small>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" id="edit_phone" autocomplete="tel" pattern="[0-9+\-\s()]*">
                        <small class="error-message" id="edit_phone_error"></small>
                    </div>
                    
                    <div class="form-group">
                        <label>Role <span style="color: red;">*</span></label>
                        <select name="role" id="edit_role" autocomplete="off" required>
                            <option value="">-- Select Role --</option>
                            <option value="patient">Patient</option>
                            <option value="doctor">Doctor</option>
                            <option value="admin">Admin</option>
                        </select>
                        <small class="error-message" id="edit_role_error"></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="button" class="btn-updateUser" onclick="submitEditForm()">Update User</button>
            </div>
        </div>
    </div>

    <style>
    /* CRITICAL */
    .modal-body {
        padding: 2rem 2.5rem;
        overflow-y: auto !important;
        flex: 1 1 auto !important;
        background: white;
        min-height: 0; /* CRITICAL - allows flex child to shrink */
    }

    .modal-content {
        position: relative;
        background: white;
        border-radius: 20px;
        width: 100%;
        max-width: 800px;
        max-height: 85vh;
        display: flex !important;
        flex-direction: column !important;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        animation: modalSlideIn 0.4s ease;
        overflow: hidden;
        z-index: 1;
    }

    /* Force form to not interfere with scrollbar */
    .modal-body form {
        display: block;
        width: 100%;
    }

    /* Validation Styles */
    .error-message {
        display: block;
        color: #ef4444;
        font-size: 0.85rem;
        margin-top: 0.3rem;
        min-height: 1.2rem;
        font-weight: 500;
    }

    .form-helper-text {
        display: block;
        color: #6b7280;
        font-size: 0.85rem;
        margin-top: 0.3rem;
    }

    .form-group input.error,
    .form-group select.error {
        border-color: #ef4444 !important;
        background-color: #fef2f2 !important;
    }

    .form-group input.success,
    .form-group select.success {
        border-color: #10b981 !important;
    }
    </style>

    <script>
    // Validation Functions
    function validateAddForm(event) {
        if (event) event.preventDefault();
        
        let isValid = true;
        clearErrors('add');
        
        // First Name
        const firstName = document.getElementById('add_first_name').value.trim();
        if (!firstName) {
            showError('add_first_name', 'First name is required');
            isValid = false;
        } else if (firstName.length < 2) {
            showError('add_first_name', 'First name must be at least 2 characters');
            isValid = false;
        }
        
        // Last Name
        const lastName = document.getElementById('add_last_name').value.trim();
        if (!lastName) {
            showError('add_last_name', 'Last name is required');
            isValid = false;
        } else if (lastName.length < 2) {
            showError('add_last_name', 'Last name must be at least 2 characters');
            isValid = false;
        }
        
        // Email
        const email = document.getElementById('add_email').value.trim();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email) {
            showError('add_email', 'Email is required');
            isValid = false;
        } else if (!emailRegex.test(email)) {
            showError('add_email', 'Please enter a valid email address');
            isValid = false;
        }
        
        // Password
        const password = document.getElementById('add_password').value;
        if (!password) {
            showError('add_password', 'Password is required');
            isValid = false;
        } else if (password.length < 6) {
            showError('add_password', 'Password must be at least 6 characters');
            isValid = false;
        }
        
        // Phone (optional but validate if provided)
        const phone = document.getElementById('add_phone').value.trim();
        if (phone && phone.length < 8) {
            showError('add_phone', 'Please enter a valid phone number');
            isValid = false;
        }
        
        // Role
        const role = document.getElementById('add_role').value;
        if (!role) {
            showError('add_role', 'Please select a role');
            isValid = false;
        }
        
        if (isValid) {
            document.getElementById('addUserForm').submit();
        } else {
            showNotification('Please fill in all required fields correctly', 'error');
        }
        
        return false;
    }

    function validateEditForm(event) {
        if (event) event.preventDefault();
        
        let isValid = true;
        clearErrors('edit');
        
        // First Name
        const firstName = document.getElementById('edit_first_name').value.trim();
        if (!firstName) {
            showError('edit_first_name', 'First name is required');
            isValid = false;
        } else if (firstName.length < 2) {
            showError('edit_first_name', 'First name must be at least 2 characters');
            isValid = false;
        }
        
        // Last Name
        const lastName = document.getElementById('edit_last_name').value.trim();
        if (!lastName) {
            showError('edit_last_name', 'Last name is required');
            isValid = false;
        } else if (lastName.length < 2) {
            showError('edit_last_name', 'Last name must be at least 2 characters');
            isValid = false;
        }
        
        // Email
        const email = document.getElementById('edit_email').value.trim();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email) {
            showError('edit_email', 'Email is required');
            isValid = false;
        } else if (!emailRegex.test(email)) {
            showError('edit_email', 'Please enter a valid email address');
            isValid = false;
        }
        
        // Password (optional for edit)
        const password = document.getElementById('edit_password').value;
        if (password && password.length < 6) {
            showError('edit_password', 'Password must be at least 6 characters if provided');
            isValid = false;
        }
        
        // Phone (optional but validate if provided)
        const phone = document.getElementById('edit_phone').value.trim();
        if (phone && phone.length < 8) {
            showError('edit_phone', 'Please enter a valid phone number');
            isValid = false;
        }
        
        // Role
        const role = document.getElementById('edit_role').value;
        if (!role) {
            showError('edit_role', 'Please select a role');
            isValid = false;
        }
        
        if (isValid) {
            document.getElementById('editUserForm').submit();
        } else {
            showNotification('Please fill in all required fields correctly', 'error');
        }
        
        return false;
    }

    function showError(fieldId, message) {
        const errorElement = document.getElementById(fieldId + '_error');
        const inputElement = document.getElementById(fieldId);
        
        if (errorElement) {
            errorElement.textContent = message;
        }
        if (inputElement) {
            inputElement.classList.add('error');
            inputElement.classList.remove('success');
        }
    }

    function clearErrors(formType) {
        const prefix = formType;
        const fields = ['first_name', 'last_name', 'email', 'password', 'phone', 'role'];
        
        fields.forEach(field => {
            const errorElement = document.getElementById(prefix + '_' + field + '_error');
            const inputElement = document.getElementById(prefix + '_' + field);
            
            if (errorElement) {
                errorElement.textContent = '';
            }
            if (inputElement) {
                inputElement.classList.remove('error', 'success');
            }
        });
    }

    function submitAddForm() {
        validateAddForm();
    }

    function submitEditForm() {
        validateEditForm();
    }

    // Real-time validation (clear error when user starts typing)
    document.addEventListener('DOMContentLoaded', function() {
        const addInputs = ['add_first_name', 'add_last_name', 'add_email', 'add_password', 'add_phone', 'add_role'];
        const editInputs = ['edit_first_name', 'edit_last_name', 'edit_email', 'edit_password', 'edit_phone', 'edit_role'];
        
        [...addInputs, ...editInputs].forEach(inputId => {
            const element = document.getElementById(inputId);
            if (element) {
                element.addEventListener('input', function() {
                    const errorElement = document.getElementById(inputId + '_error');
                    if (errorElement && errorElement.textContent) {
                        errorElement.textContent = '';
                        this.classList.remove('error');
                    }
                });
                
                element.addEventListener('blur', function() {
                    if (this.value.trim()) {
                        this.classList.add('success');
                    }
                });
            }
        });
    });
    </script>

    <!-- Action Forms -->
    <form id="deleteForm" method="POST" style="display: none;"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" id="delete_user_id"></form>
    <form id="toggleForm" method="POST" style="display: none;"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" id="toggle_user_id"><input type="hidden" name="new_status" id="toggle_new_status"></form>

    <!-- Add jsPDF library before closing </head> tag -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script src="users.js"></script>
</body>
</html>