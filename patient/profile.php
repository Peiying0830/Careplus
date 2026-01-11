<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

$userId = getUserId();

// Fetch user + patient info 
$stmt = $conn->prepare("SELECT u.email, p.* FROM users u JOIN patients p ON u.user_id = p.user_id WHERE u.user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId); // "i" means integer
$stmt->execute();
$result = $stmt->get_result();
$me = $result->fetch_assoc();

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $blood = sanitizeInput($_POST['blood_type'] ?? '');
    $allergies = sanitizeInput($_POST['allergies'] ?? '');
    $emergency = sanitizeInput($_POST['emergency_contact'] ?? '');
    
    // Handle profile picture upload
    $profilePicture = $me['profile_picture'] ?? null; 
    
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; 
        
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
            $filename = 'patient_' . $userId . '_' . time() . '.' . $extension;
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
        // Update record using MySQLi 
        $upd = $conn->prepare("UPDATE patients SET phone = ?, address = ?, blood_type = ?, allergies = ?, emergency_contact = ?, profile_picture = ? WHERE user_id = ?");
        
        // sss sss i -> 6 strings, 1 integer
        $upd->bind_param("ssssssi", $phone, $address, $blood, $allergies, $emergency, $profilePicture, $userId);
        
        if ($upd->execute()) {
            $success = 'Profile updated successfully!';
            
            // Refresh data for the display
            $stmt->execute();
            $me = $stmt->get_result()->fetch_assoc();
        } else {
            $errors[] = 'Update failed: ' . $conn->error;
        }
        $upd->close();
    }
}

// Age calculation remains the same (PHP logic)
function calculateAge($dob) {
    if (empty($dob)) return ['years' => 0, 'months' => 0, 'days' => 0];
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $diff = $today->diff($birthDate);
    
    return [
        'years' => $diff->y,
        'months' => $diff->m,
        'days' => $diff->d,
        'total_days' => $diff->days
    ];
}

$age = calculateAge($me['date_of_birth'] ?? '');
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
                    <h1>👤 My Profile</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Manage your personal information and preferences</p>
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
                                    🆔 Patient ID: <?php echo $me['patient_id']; ?>
                                </span>
                                <span class="info-badge age-badge">
                                    🎂 Age: <?php echo $age['years']; ?> years, <?php echo $age['months']; ?> months, <?php echo $age['days']; ?> days
                                </span>
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
                            <span class="profile-label">IC Number:</span>
                            <span class="profile-value"><?php echo htmlspecialchars($me['ic_number']); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Date of Birth:</span>
                            <span class="profile-value">
                                <?php echo date('F d, Y', strtotime($me['date_of_birth'])); ?>
                                <span class="age-detail">(<?php echo $age['years']; ?> years, <?php echo $age['months']; ?> months, <?php echo $age['days']; ?> days)</span>
                            </span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Gender:</span>
                            <span class="profile-value"><?php echo ucfirst($me['gender']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="card fade-in" style="animation-delay: 0.3s">
                <div class="card-header">
                    <h2 class="card-title">✏️ Edit Contact & Medical Information</h2>
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
                                <label class="form-label">🚨 Emergency Contact</label>
                                <input name="emergency_contact" class="form-control" type="tel" 
                                       value="<?php echo htmlspecialchars($me['emergency_contact'] ?? ''); ?>" 
                                       placeholder="e.g., 012-345-6789">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">🏠 Address</label>
                            <textarea name="address" class="form-control" rows="3" 
                                      placeholder="Enter your complete address"><?php echo htmlspecialchars($me['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">🩸 Blood Type</label>
                                <select name="blood_type" class="form-control">
                                    <option value="">Select Blood Type</option>
                                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $b): ?>
                                        <option value="<?php echo $b; ?>" <?php echo (($me['blood_type'] ?? '') === $b) ? 'selected' : ''; ?>>
                                            <?php echo $b; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">⚠️ Allergies</label>
                            <textarea name="allergies" class="form-control" rows="3" 
                                      placeholder="List any allergies or medical conditions"><?php echo htmlspecialchars($me['allergies'] ?? ''); ?></textarea>
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