<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'general_settings') {
            $siteName = sanitizeInput($_POST['site_name']);
            $siteEmail = sanitizeInput($_POST['site_email']);
            
            $updateSql = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
            $stmt = $conn->prepare($updateSql);
            
            $settings = ['site_name' => $siteName, 'contact_email' => $siteEmail];
            foreach ($settings as $key => $value) {
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
            }
            $message = "General settings updated successfully.";
            $messageType = "success";

        } elseif ($action === 'update_email') {
            $newEmail = sanitizeInput($_POST['email']);
            $upd = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
            $upd->bind_param("si", $newEmail, $userId);
            if($upd->execute()) {
                $message = "Email updated successfully.";
                $messageType = "success";
            }

        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'];
            $newPassword = $_POST['new_password'];
            
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();

            if (verifyPassword($currentPassword, $userRow['password_hash'])) {
                $newHash = hashPassword($newPassword);
                $upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $upd->bind_param("si", $newHash, $userId);
                $upd->execute();
                $message = "Password changed successfully.";
                $messageType = "success";
            } else {
                $message = "Current password is incorrect.";
                $messageType = "error";
            }
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// Select u.* to get status, user_type, security_question, etc.
$stmt = $conn->prepare("SELECT u.*, a.* FROM users u JOIN admins a ON u.user_id = a.user_id WHERE u.user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$adminData = $stmt->get_result()->fetch_assoc();

// Fetch logs so the Activity tab doesn't crash
$logs = [];
$logStmt = $conn->prepare("SELECT action, created_at FROM symptom_checker_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$logStmt->bind_param("i", $userId);
$logStmt->execute();
$logRes = $logStmt->get_result();
while($row = $logRes->fetch_assoc()) {
    $logs[] = $row;
}

$settingsResult = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$sysSettings = [];
if($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $sysSettings[$row['setting_key']] = $row['setting_value'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - CarePlus</title>
    <link rel="stylesheet" href="settings.css">
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="container">
        <div class="page-header">
            <div class="header-left">
                <h1>⚙️ Settings</h1>
                <p>&emsp;&emsp;&emsp;&nbsp;Manage your account preferences</p>
            </div>
        </div>

        <?php if($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span><?= $message ?></span>
            </div>
        <?php endif; ?>

        <div class="settings-layout">
            <!-- Settings Navigation -->
            <div class="settings-nav">
                <button class="nav-item active" data-tab="account">
                    <span class="nav-icon">👤</span>
                    <span class="nav-text">Account</span>
                </button>
                <button class="nav-item" data-tab="security">
                    <span class="nav-icon">🔒</span>
                    <span class="nav-text">Security</span>
                </button>
                <button class="nav-item" data-tab="privacy">
                    <span class="nav-icon">🛡️</span>
                    <span class="nav-text">Privacy</span>
                </button>
            </div>

            <!-- Settings Content -->
            <div class="settings-content">
                
                <!-- Account Tab -->
                <div class="tab-content active" id="account">
                    <div class="content-header">
                        <h2>Account Settings</h2>
                        <p>Manage your account information</p>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Email Address</h3>
                            <p>Your current email: <strong><?= htmlspecialchars($adminData['email']) ?></strong></p>
                        </div>
                        <form method="POST" class="setting-form">
                            <input type="hidden" name="action" value="update_email">
                            <div class="form-group">
                                <label>New Email Address</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($adminData['email']) ?>" required>
                            </div>
                            <button type="submit" class="btn-primary">Update Email</button>
                        </form>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Account Type</h3>
                            <p>Your account type: <span class="badge badge-<?= $adminData['user_type'] ?>"><?= ucfirst($adminData['user_type']) ?></span></p>
                        </div>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Account Status</h3>
                            <p>Status: <span class="badge badge-<?= $adminData['is_active'] ? 'active' : 'inactive' ?>"><?= $adminData['is_active'] ? 'Active' : 'Inactive' ?></span></p>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div class="tab-content" id="security">
                    <div class="content-header">
                        <h2>Security Settings</h2>
                        <p>Keep your account secure</p>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Change Password</h3>
                            <p>Update your password regularly for better security</p>
                        </div>
                        <form method="POST" class="setting-form">
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" required>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" minlength="8" required>
                                <small>Must be at least 8 characters</small>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" minlength="8" required>
                            </div>
                            <button type="submit" class="btn-primary">Change Password</button>
                        </form>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Security Question</h3>
                            <p>Used for account recovery</p>
                        </div>
                        <form method="POST" class="setting-form">
                            <input type="hidden" name="action" value="update_security">
                            <div class="form-group">
                                <label>Security Question</label>
                                <select name="security_question" required>
                                    <option value="">Select a question</option>
                                    <option value="pet" <?= ($adminData['security_question'] ?? '') === 'pet' ? 'selected' : '' ?>>What is your pet's name?</option>
                                    <option value="city" <?= ($adminData['security_question'] ?? '') === 'city' ? 'selected' : '' ?>>What city were you born in?</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Answer</label>
                                <input type="text" name="security_answer" placeholder="Enter your answer" required>
                            </div>
                            <button type="submit" class="btn-primary">Update Security Question</button>
                        </form>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Two-Factor Authentication</h3>
                            <p>Add an extra layer of security</p>
                        </div>
                        <p class="help-text">Two-factor authentication is currently not enabled. Coming soon!</p>
                    </div>
                </div>

                <!-- Privacy Tab -->
                <div class="tab-content" id="privacy">
                    <div class="content-header">
                        <h2>Privacy Settings</h2>
                        <p>Control your data and privacy</p>
                    </div>

                    <div class="setting-card">
                        <div class="setting-header">
                            <h3>Data Sharing</h3>
                            <p>Manage how your data is used</p>
                        </div>
                        <form method="POST" class="setting-form">
                            <div class="toggle-setting">
                                <div>
                                    <label>Share analytics data</label>
                                    <small>Help us improve the service</small>
                                </div>
                                <label class="toggle">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </form>
                    </div>

                    <div class="setting-card danger">
                        <div class="setting-header">
                            <h3>Deactivate Account</h3>
                            <p>Temporarily deactivate your account</p>
                        </div>
                        <button class="btn-danger" onclick="openDeactivateModal()">Deactivate Account</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Deactivate Account Modal -->
    <div id="deactivateModal" class="modal">
        <div class="modal-content">
            <h2>⚠️ Deactivate Account</h2>
            <p style="margin: 20px 0; color: #ef4444; font-weight: 600;">Are you sure you want to deactivate your account? This action can be reversed by contacting support.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="deactivate_account">
                <div class="form-group">
                    <label>Confirm your password to continue</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeDeactivateModal()">Cancel</button>
                    <button type="submit" class="btn-danger">Deactivate Account</button>
                </div>
            </form>
        </div>
    </div>

    <script src="settings.js"></script>
</body>
</html>