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

// Handle POST AJAX actions (Mark as read, delete, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Mark single notification as read
    if ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        $updateStmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        $updateStmt->bind_param("ii", $notificationId, $userId);
        $result = $updateStmt->execute();
        echo json_encode(['success' => $result]);
        exit;
    }
    
    // Mark all as read
    if ($_POST['action'] === 'mark_all_read') {
        $updateStmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $updateStmt->bind_param("i", $userId);
        $result = $updateStmt->execute();
        echo json_encode(['success' => $result]);
        exit;
    }
    
    // Delete notification
    if ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        $deleteStmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        $deleteStmt->bind_param("ii", $notificationId, $userId);
        $result = $deleteStmt->execute();
        echo json_encode(['success' => $result]);
        exit;
    }
}

// Fetch all notifications
$notificationsStmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$notificationsStmt->bind_param("i", $userId);
$notificationsStmt->execute();
$notifications = $notificationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_notifications,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
        SUM(CASE WHEN notification_type = 'appointment' THEN 1 ELSE 0 END) as appointment_count,
        SUM(CASE WHEN notification_type = 'payment' THEN 1 ELSE 0 END) as payment_count,
        SUM(CASE WHEN notification_type = 'reminder' THEN 1 ELSE 0 END) as reminder_count,
        SUM(CASE WHEN notification_type = 'general' THEN 1 ELSE 0 END) as general_count
    FROM notifications 
    WHERE user_id = ?
");
$statsStmt->bind_param("i", $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

// Group notifications by date (Logic remains the same)
$groupedNotifications = [];
foreach ($notifications as $notification) {
    $date = date('Y-m-d', strtotime($notification['created_at']));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date === $today) {
        $dateLabel = 'Today';
    } elseif ($date === $yesterday) {
        $dateLabel = 'Yesterday';
    } else {
        $dateLabel = date('F j, Y', strtotime($date));
    }
    
    if (!isset($groupedNotifications[$dateLabel])) {
        $groupedNotifications[$dateLabel] = [];
    }
    $groupedNotifications[$dateLabel][] = $notification;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="notification.css">
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
                    <h1>🔔 My Notifications</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Stay updated with your appointments, payments, and reminders</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <span>📬</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_notifications'] ?? 0; ?></h3>
                        <p>Total Notifications</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon unread">
                        <span>🔴</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['unread_count'] ?? 0; ?></h3>
                        <p>Unread Messages</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon appointments">
                        <span>📅</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['appointment_count'] ?? 0; ?></h3>
                        <p>Appointments</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon reminders">
                        <span>⏰</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['reminder_count'] ?? 0; ?></h3>
                        <p>Reminders</p>
                    </div>
                </div>
            </div>

            <!-- Notifications Section -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h2><span>🔔</span> All Notifications</h2>
                    <div class="header-actions">
                        <select id="filterType" class="filter-select" onchange="filterNotifications()">
                            <option value="all">All Types</option>
                            <option value="appointment">Appointments</option>
                            <option value="payment">Payments</option>
                            <option value="reminder">Reminders</option>
                            <option value="general">General</option>
                        </select>
                        <select id="filterStatus" class="filter-select" onchange="filterNotifications()">
                            <option value="all">All Status</option>
                            <option value="unread">Unread Only</option>
                            <option value="read">Read Only</option>
                        </select>
                        <?php if ($stats['unread_count'] > 0): ?>
                        <button class="btn btn-primary" onclick="markAllAsRead()">
                            <span>✓</span> Mark All as Read
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="notifications-container">
                    <?php if (!empty($groupedNotifications)): ?>
                        <?php foreach ($groupedNotifications as $dateLabel => $dateNotifications): ?>
                            <div class="notification-date-group">
                                <h3 class="date-label"><?php echo $dateLabel; ?></h3>
                                <div class="notifications-list">
                                    <?php foreach ($dateNotifications as $notification): ?>
                                        <div class="notification-card <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>" 
                                             data-notification-id="<?php echo $notification['notification_id']; ?>"
                                             data-type="<?php echo $notification['notification_type']; ?>"
                                             data-status="<?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                                            
                                            <div class="notification-icon <?php echo $notification['notification_type']; ?>">
                                                <?php
                                                $icons = [
                                                    'appointment' => '📅',
                                                    'payment' => '💳',
                                                    'reminder' => '⏰',
                                                    'general' => '📢'
                                                ];
                                                echo $icons[$notification['notification_type']] ?? '📬';
                                                ?>
                                            </div>
                                            
                                            <div class="notification-content">
                                                <div class="notification-header">
                                                    <h4 class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></h4>
                                                    <div class="notification-meta">
                                                        <span class="notification-time">
                                                            <?php
                                                            $time = strtotime($notification['created_at']);
                                                            $diff = time() - $time;
                                                            if ($diff < 60) {
                                                                echo 'Just now';
                                                            } elseif ($diff < 3600) {
                                                                echo floor($diff / 60) . ' minutes ago';
                                                            } elseif ($diff < 86400) {
                                                                echo floor($diff / 3600) . ' hours ago';
                                                            } else {
                                                                echo date('g:i A', $time);
                                                            }
                                                            ?>
                                                        </span>
                                                        <?php if (!$notification['is_read']): ?>
                                                        <span class="unread-badge">New</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <p class="notification-message"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                                                
                                                <div class="notification-actions">
                                                    <?php if (!$notification['is_read']): ?>
                                                    <button class="btn-action" onclick="markAsRead(<?php echo $notification['notification_id']; ?>)">
                                                        <span>✓</span> Mark as Read
                                                    </button>
                                                    <?php endif; ?>
                                                    <button class="btn-action delete" onclick="deleteNotification(<?php echo $notification['notification_id']; ?>)">
                                                        <span>🗑️</span> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">🔔</div>
                            <h3>No Notifications Yet</h3>
                            <p>You're all caught up! New notifications will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="notification.js"></script>
</body>
</html>