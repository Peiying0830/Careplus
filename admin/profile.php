<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

// Get the MySQLi connection from the singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch user + admin data using MySQLi
$stmt = $conn->prepare("SELECT u.email, a.* FROM users u JOIN admins a ON u.user_id = a.user_id WHERE u.user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId); // "i" indicates the variable is an integer
$stmt->execute();
$result = $stmt->get_result();
$me = $result->fetch_assoc();

if (!$me) {
    redirect('login.php');
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $department = sanitizeInput($_POST['department'] ?? '');
    
    // Handle profile picture upload
    $profilePicture = $me['profile_picture'] ?? null;
    
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowedTypes)) {
            $errors[] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
        }
        
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size too large. Maximum size is 5MB.';
        }
        
        if (empty($errors)) {
            $uploadDir = __DIR__ . '/../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'admin_' . $userId . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            // Delete old profile picture if exists
            if (!empty($me['profile_picture']) && file_exists(__DIR__ . '/../' . $me['profile_picture'])) {
                @unlink(__DIR__ . '/../' . $me['profile_picture']);
            }
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $profilePicture = 'uploads/profiles/' . $filename;
            } else {
                $errors[] = 'Failed to upload profile picture.';
            }
        }
    }
    
    if (empty($errors)) {
        // Update profile using MySQLi
        $upd = $conn->prepare("UPDATE admins SET phone = ?, department = ?, profile_picture = ? WHERE user_id = ?");
        
        // "sssi" means: string, string, string, integer
        $upd->bind_param("sssi", $phone, $department, $profilePicture, $userId);
        
        if ($upd->execute()) {
            $success = 'Profile updated successfully!';
            
            // Refresh local data for display
            $stmt->execute();
            $me = $stmt->get_result()->fetch_assoc();
        } else {
            $errors[] = 'Update failed. Please try again.';
        }
        $upd->close();
    }
}

try {
    $conn->query("ALTER TABLE admins ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL");
} catch (Exception $e) {
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="profile.css">
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <h1>👤 My Profile</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Manage your administrative information and preferences</p>
                </div>
            </div>

            <!-- Profile Picture & Basic Info Card -->
            <div class="card fade-in" style="animation-delay: 0.1s">
                <div class="card-body">
                    <div class="profile-picture-section">
                        <div class="profile-picture-wrapper">
                            <div class="profile-picture-container">
                                <?php if (!empty($me['profile_picture']) && file_exists(__DIR__ . '/../' . $me['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($me['profile_picture']); ?>" 
                                         alt="Profile Picture" 
                                         class="profile-picture" 
                                         id="profilePreview">
                                <?php else: ?>
                                    <div class="profile-picture-placeholder" id="profilePreview">
                                        <span class="placeholder-icon">
                                            <?php echo strtoupper(substr($me['first_name'], 0, 1) . substr($me['last_name'], 0, 1)); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="profile-picture-overlay">
                                    <label for="profile_picture" class="upload-label">
                                        📷 Change Photo
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="profile-info-summary">
                            <h2 class="profile-name"><?php echo htmlspecialchars($me['first_name'] . ' ' . $me['last_name']); ?></h2>
                            <p class="profile-email"><?php echo htmlspecialchars($me['email']); ?></p>
                            <div class="profile-badges">
                                <span class="info-badge">
                                    🆔 Admin ID: <?php echo $me['admin_id']; ?>
                                </span>
                                <?php if (!empty($me['department'])): ?>
                                <span class="info-badge department-badge">
                                    🏢 <?php echo htmlspecialchars($me['department']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal Information Card -->
            <div class="card fade-in" style="animation-delay: 0.2s">
                <div class="card-header">
                    <h2 class="card-title">📋 Personal Information</h2>
                </div>
                <div class="card-body">
                    <div class="profile-section">
                        <div class="profile-row">
                            <span class="profile-label">Full Name:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['first_name'] . ' ' . $me['last_name']); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Email Address:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['email']); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Phone Number:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['phone'] ?? 'Not set'); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Department:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['department'] ?? 'Not assigned'); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Account Created:</span>
                            <span class="profile-value"><?php echo date('F d, Y', strtotime($me['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="card fade-in" style="animation-delay: 0.3s">
                <div class="card-header">
                    <h2 class="card-title">✏️ Edit Profile Information</h2>
                </div>
                <div class="card-body">

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <strong>⚠️ Please fix the following errors:</strong>
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <form method="post" id="profileForm" enctype="multipart/form-data">
                        
                        <!-- Profile Picture Upload -->
                        <div class="form-group">
                            <label class="form-label">Profile Picture</label>
                            <input type="file" 
                                   name="profile_picture" 
                                   id="profile_picture" 
                                   class="form-control-file" 
                                   accept="image/jpeg,image/jpg,image/png,image/gif"
                                   style="display: none;">
                            <div class="file-upload-info">
                                <p class="file-upload-hint">
                                    💡 Allowed formats: JPG, PNG, GIF | Maximum size: 5MB
                                </p>
                                <button type="button" class="btn-secondary" onclick="document.getElementById('profile_picture').click();">
                                    📁 Choose File
                                </button>
                                <span id="fileName" class="file-name-display"></span>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">📞 Phone Number</label>
                                <input name="phone" class="form-control" type="tel" 
                                       value="<?php echo htmlspecialchars($me['phone'] ?? ''); ?>" 
                                       placeholder="e.g., 012-345-6789"
                                       required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">🏢 Department</label>
                                <select name="department" class="form-control" required>
                                    <option value="">Select Department</option>
                                    <?php 
                                    $departments = ['Administration', 'Finance', 'Human Resources', 'IT Support', 'Operations', 'Patient Services', 'Medical Records', 'Facilities Management'];
                                    foreach ($departments as $dept): 
                                    ?>
                                        <option value="<?php echo $dept; ?>" <?php echo (($me['department'] ?? '') === $dept) ? 'selected' : ''; ?>>
                                            <?php echo $dept; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="btn-row">
                            <button class="btn-primary" type="submit">💾 Save Changes</button>
                            <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
                        </div>
                    </form>

                </div>
            </div>
        </main>
    </div>

    <script src="profile.js"></script>
</body>
</html>