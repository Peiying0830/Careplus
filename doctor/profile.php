<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

// Get the MySQLi connection from the singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch user + doctor info using MySQLi
$stmt = $conn->prepare("
    SELECT u.email, d.* 
    FROM users u 
    JOIN doctors d ON u.user_id = d.user_id 
    WHERE u.user_id = ? 
    LIMIT 1
");
$stmt->bind_param("i", $userId); // "i" indicates integer
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
    $specialization = sanitizeInput($_POST['specialization'] ?? '');
    $qualifications = sanitizeInput($_POST['qualifications'] ?? '');
    $experience = sanitizeInput($_POST['experience_years'] ?? '');
    $consultationFee = sanitizeInput($_POST['consultation_fee'] ?? '');
    $licenseNumber = sanitizeInput($_POST['license_number'] ?? '');
    $bio = sanitizeInput($_POST['bio'] ?? '');
    
    // Validate consultation fee
    if (!empty($consultationFee) && (!is_numeric($consultationFee) || $consultationFee < 0)) {
        $errors[] = 'Consultation fee must be a valid positive number.';
    }
    
    // Validate experience years
    if (!empty($experience) && (!is_numeric($experience) || $experience < 0 || $experience > 70)) {
        $errors[] = 'Experience years must be between 0 and 70.';
    }
    
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
            $filename = 'doctor_' . $userId . '_' . time() . '.' . $extension;
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
        // Update using MySQLi
        $upd = $conn->prepare("
            UPDATE doctors 
            SET phone = ?, 
                specialization = ?, 
                qualifications = ?, 
                experience_years = ?, 
                consultation_fee = ?, 
                license_number = ?, 
                bio = ?, 
                profile_picture = ? 
            WHERE user_id = ?
        ");
        
        // s = string, i = integer, d = double
        // Types: s s s s i d s s s i
         $types = "sssidsssi"; 
        
        $upd->bind_param($types, 
            $phone, 
            $specialization, 
            $qualifications, 
            $experience, 
            $consultationFee, 
            $licenseNumber, 
            $bio, 
            $profilePicture, 
            $userId
        );
        
        if ($upd->execute()) {
            $success = 'Profile updated successfully!';
            // Refresh local data
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $me = $stmt->get_result()->fetch_assoc();
        } else {
            $errors[] = 'Update failed: ' . $conn->error;
        }
        $upd->close();
    }
}

// Get statistics using MySQLi
$statsStmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(DISTINCT a.appointment_id) FROM appointments a WHERE a.doctor_id = ?) as total_appointments,
        (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = ? AND a.status = 'completed') as completed_appointments,
        (SELECT COUNT(DISTINCT a.patient_id) FROM appointments a WHERE a.doctor_id = ?) as total_patients,
        (SELECT AVG(rating) FROM reviews r WHERE r.doctor_id = ?) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.doctor_id = ?) as total_reviews
");

// We need to pass the doctor_id 5 times for the subqueries
$dId = $me['doctor_id'];
$statsStmt->bind_param("iiiii", $dId, $dId, $dId, $dId, $dId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// Ensure values are not null
$stats['avg_rating'] = $stats['avg_rating'] ?? 0;
$stats['total_reviews'] = $stats['total_reviews'] ?? 0;

$statsStmt->close();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SITE_NAME; ?></title>
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
                <div>
                    <h1>👨‍⚕️ My Profile</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Manage your professional information and preferences</p>
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
                            <h2 class="profile-name">Dr. <?php echo htmlspecialchars($me['first_name'] . ' ' . $me['last_name']); ?></h2>
                            <p class="profile-email"><?php echo htmlspecialchars($me['email']); ?></p>
                            <div class="profile-badges">
                                <span class="info-badge">
                                    🆔 Doctor ID: <?php echo $me['doctor_id']; ?>
                                </span>
                                <span class="info-badge specialization-badge">
                                    🏥 <?php echo htmlspecialchars($me['specialization'] ?: 'General Practice'); ?>
                                </span>
                                <?php if ($stats['avg_rating'] > 0): ?>
                                <span class="info-badge rating-badge">
                                    ⭐ <?php echo number_format($stats['avg_rating'], 1); ?>/5.0 (<?php echo $stats['total_reviews']; ?> reviews)
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in" style="animation-delay: 0.15s">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #6DADE8 0%, #5B9BD5 100%);">📅</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['total_appointments']; ?></h3>
                        <p>Total Appointments</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #7FE5B8 0%, #4CAF50 100%);">✓</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['completed_appointments']; ?></h3>
                        <p>Completed</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #C4A5F0 0%, #9C27B0 100%);">👥</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['total_patients']; ?></h3>
                        <p>Total Patients</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #FFB5C9 0%, #E91E63 100%);">⭐</div>
                    <div class="stat-content">
                        <h3><?php echo number_format($stats['avg_rating'], 1); ?></h3>
                        <p>Average Rating</p>
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
                            <span class="profile-value">Dr. <?php echo htmlspecialchars($me['first_name'] . ' ' . $me['last_name']); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Email:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['email']); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Gender:</span>
                            <span class="profile-value"><?php echo ucfirst($me['gender']); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">License Number:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['license_number'] ?: 'Not provided'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="card fade-in" style="animation-delay: 0.3s">
                <div class="card-header">
                    <h2 class="card-title">✏️ Edit Professional Information</h2>
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
                                <button type="button" class="btn btn-secondary" onclick="document.getElementById('profile_picture').click();">
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
                                       placeholder="e.g., 012-345-6789">
                            </div>

                            <div class="form-group">
                                <label class="form-label">🏥 Specialization</label>
                                <input name="specialization" class="form-control" type="text" 
                                       value="<?php echo htmlspecialchars($me['specialization'] ?? ''); ?>" 
                                       placeholder="e.g., Cardiology, Pediatrics">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">🎓 Qualifications</label>
                                <input name="qualifications" class="form-control" type="text" 
                                       value="<?php echo htmlspecialchars($me['qualifications'] ?? ''); ?>" 
                                       placeholder="e.g., MBBS, MD">
                            </div>

                            <div class="form-group">
                                <label class="form-label">📜 License Number</label>
                                <input name="license_number" class="form-control" type="text" 
                                       value="<?php echo htmlspecialchars($me['license_number'] ?? ''); ?>" 
                                       placeholder="e.g., MMC-12345">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">📊 Years of Experience</label>
                                <input name="experience_years" class="form-control" type="number" 
                                       value="<?php echo htmlspecialchars($me['experience_years'] ?? ''); ?>" 
                                       placeholder="e.g., 5" min="0" max="70">
                            </div>

                            <div class="form-group">
                                <label class="form-label">💰 Consultation Fee (RM)</label>
                                <input name="consultation_fee" class="form-control" type="number" 
                                       value="<?php echo htmlspecialchars($me['consultation_fee'] ?? ''); ?>" 
                                       placeholder="e.g., 50.00" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">📝 Professional Bio</label>
                            <textarea name="bio" class="form-control" rows="4" 
                                      placeholder="Write a brief professional bio about yourself, your expertise, and approach to patient care"><?php echo htmlspecialchars($me['bio'] ?? ''); ?></textarea>
                        </div>

                        <div class="btn-row">
                            <button class="btn btn-primary" type="submit">💾 Save Changes</button>
                            <a href="dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
                        </div>
                    </form>

                </div>
            </div>
        </main>
    </div>

    <script src="profile.js"></script>
</body>
</html>