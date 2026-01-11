<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch admin info
$adminStmt = $conn->prepare("SELECT a.*, u.email FROM admins a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ? LIMIT 1");
$adminStmt->bind_param("i", $userId);
$adminStmt->execute();
$admin = $adminStmt->get_result()->fetch_assoc();

if (!$admin) { redirect('login.php'); }

// Pagination & Filter Setup
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$filterType = $_GET['type'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');
$searchTerm = "%$searchQuery%";
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Optimized & Secure Activity Query
$subqueries = [];
$params =[];
$types = "";

// Appointment Subquery
if ($filterType === 'appointment' || $filterType === 'all') {
    $sql = "SELECT 'appointment' as type, a.appointment_id as id, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name, 
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name, 
            a.status, a.created_at, a.appointment_date, a.appointment_time,
            NULL as profile_image
            FROM appointments a 
            JOIN patients p ON a.patient_id = p.patient_id 
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE 1=1";
    if ($searchQuery) {
        $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR d.first_name LIKE ? OR d.last_name LIKE ?)";
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        $types .= "ssss";
    }
    if ($dateFrom) $sql .= " AND a.created_at >= '$dateFrom 00:00:00'";
    if ($dateTo) $sql .= " AND a.created_at <= '$dateTo 23:59:59'";
    $subqueries[] = "($sql)";
}

// Doctor Subquery
if ($filterType === 'doctor' || $filterType === 'all') {
    $sql = "SELECT 'doctor' as type, d.doctor_id as id, 
            NULL as patient_name, 
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name, 
            u.status, u.created_at, 
            NULL as appointment_date, 
            NULL as appointment_time, 
            d.profile_picture as profile_image
            FROM doctors d 
            JOIN users u ON d.user_id = u.user_id 
            WHERE 1=1";
    if ($searchQuery) {
        $sql .= " AND (d.first_name LIKE ? OR d.last_name LIKE ?)";
        array_push($params, $searchTerm, $searchTerm);
        $types .= "ss";
    }
    if ($dateFrom) $sql .= " AND u.created_at >= '$dateFrom 00:00:00'";
    if ($dateTo) $sql .= " AND u.created_at <= '$dateTo 23:59:59'";
    $subqueries[] = "($sql)";
}

// Patient Subquery
if ($filterType === 'patient' || $filterType === 'all') {
    $sql = "SELECT 'patient' as type, p.patient_id as id, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name, 
            NULL as doctor_name, 
            u.status, u.created_at, 
            NULL as appointment_date, 
            NULL as appointment_time, 
            p.profile_picture as profile_image
            FROM patients p 
            JOIN users u ON p.user_id = u.user_id 
            WHERE 1=1";
    if ($searchQuery) {
        $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ?)";
        array_push($params, $searchTerm, $searchTerm);
        $types .= "ss";
    }
    if ($dateFrom) $sql .= " AND u.created_at >= '$dateFrom 00:00:00'";
    if ($dateTo) $sql .= " AND u.created_at <= '$dateTo 23:59:59'";
    $subqueries[] = "($sql)";
}

$finalQuery = implode(" UNION ALL ", $subqueries);

$countQuery = "SELECT COUNT(*) as total FROM ($finalQuery) as combined";
$stmtCount = $conn->prepare($countQuery);

if ($types) {
    $stmtCount->bind_param($types, ...$params);
}

$stmtCount->execute();
$totalActivities = $stmtCount->get_result()->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($totalActivities / $perPage);
$stmtCount->close();

$activityQuery = $finalQuery . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmtData = $conn->prepare($activityQuery);

$dataTypes = $types . "ii";

$dataParams = array_merge($params, [$perPage, $offset]);

$stmtData->bind_param($dataTypes, ...$dataParams);
$stmtData->execute();
$allActivities = $stmtData->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtData->close();

$statsQuery = "
    SELECT 
        SUM(CASE WHEN type = 'appointment' THEN 1 ELSE 0 END) as appointment_count,
        SUM(CASE WHEN type = 'doctor' THEN 1 ELSE 0 END) as doctor_count,
        SUM(CASE WHEN type = 'patient' THEN 1 ELSE 0 END) as patient_count
    FROM (
        (SELECT 'appointment' as type FROM appointments)
        UNION ALL
        (SELECT 'doctor' as type FROM doctors)
        UNION ALL
        (SELECT 'patient' as type FROM patients)
    ) as stats
";
$stats = $conn->query($statsQuery)->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Activity - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="activity.css">
    <!-- jsPDF Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="page-title">
                    <h1><span>📊</span> System Activity</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;&nbsp;View all system activities and logs</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-outline" onclick="exportActivities()">
                        <span>📥</span> Export CSV
                    </button>
                    <button class="btn btn-outline" onclick="exportToPDF()">
                        <span>📄</span> Export PDF
                    </button>
                </div>
            </div>

            <!-- Activity Stats -->
            <div class="activity-stats fade-in">
                <div class="stat-item">
                    <div class="stat-icon appointments">📅</div>
                    <div class="stat-info">
                        <h3><?php echo $stats['appointment_count'] ?? 0; ?></h3>
                        <p>Total Appointments</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon doctors">👨‍⚕️</div>
                    <div class="stat-info">
                        <h3><?php echo $stats['doctor_count'] ?? 0; ?></h3>
                        <p>Doctor Registrations</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon patients">🤒</div>
                    <div class="stat-info">
                        <h3><?php echo $stats['patient_count'] ?? 0; ?></h3>
                        <p>Patient Registrations</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon total">📈</div>
                    <div class="stat-info">
                        <h3><?php echo $totalActivities; ?></h3>
                        <p>Total Activities</p>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section fade-in">
                <form method="GET" action="activity.php" class="filter-form">
                    <div class="filter-group">
                        <label for="type">Type:</label>
                        <select name="type" id="type" onchange="this.form.submit()">
                            <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All Activities</option>
                            <option value="appointment" <?php echo $filterType === 'appointment' ? 'selected' : ''; ?>>Appointments</option>
                            <option value="doctor" <?php echo $filterType === 'doctor' ? 'selected' : ''; ?>>Doctors</option>
                            <option value="patient" <?php echo $filterType === 'patient' ? 'selected' : ''; ?>>Patients</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="search">Search:</label>
                        <input type="text" 
                               name="search" 
                               id="search" 
                               placeholder="Search by name..." 
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="date_from">From:</label>
                        <input type="date" 
                               name="date_from" 
                               id="date_from" 
                               value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="date_to">To:</label>
                        <input type="date" 
                               name="date_to" 
                               id="date_to" 
                               value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <span>🔍</span> Filter
                        </button>
                        <a href="activity.php" class="btn btn-outline">
                            <span>🔄</span> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Activity List -->
            <div class="activity-container fade-in">
                <div class="activity-header">
                    <h2>Activity Log</h2>
                    <span class="activity-count"><?php echo $totalActivities; ?> total activities</span>
                </div>

                <?php if (!empty($allActivities)): ?>
                    <div class="activity-list">
                        <?php foreach ($allActivities as $activity): ?>
                            <div class="activity-card <?php echo $activity['type']; ?>">
                                <div class="activity-icon-wrapper">
                                <div class="activity-type-icon <?php echo $activity['type']; ?>">
                                        <?php 
                                        // Go up one level (../) because images are in the root uploads folder, but we are in /admin/
                                        $imgPath = !empty($activity['profile_image']) ? '../' . $activity['profile_image'] : '';
                                        $fullPath = __DIR__ . '/../' . $activity['profile_image'];

                                        if (!empty($activity['profile_image']) && file_exists($fullPath)): ?>
                                                <img src="<?php echo htmlspecialchars($imgPath); ?>" 
                                                alt="Profile" 
                                                style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
                                        <?php else: ?>
                                                <?php 
                                                $icons = ['appointment' => '📅', 'doctor' => '👨‍⚕️', 'patient' => '🤒'];
                                                echo $icons[$activity['type']] ?? '📋';
                                                ?>
                                        <?php endif; ?>
                                        </div>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-main">
                                        <h3 class="activity-title">
                                            <?php if ($activity['type'] === 'appointment'): ?>
                                                New Appointment Created
                                            <?php elseif ($activity['type'] === 'doctor'): ?>
                                                New Doctor Registration
                                            <?php else: ?>
                                                New Patient Registration
                                            <?php endif; ?>
                                        </h3>

                                        <div class="activity-description">
                                            <?php if ($activity['type'] === 'appointment'): ?>
                                                <span class="activity-detail">
                                                    <strong>Patient:</strong> <?php echo htmlspecialchars($activity['patient_name']); ?>
                                                </span>
                                                <span class="activity-detail">
                                                    <strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($activity['doctor_name']); ?>
                                                </span>
                                                <?php if ($activity['appointment_date']): ?>
                                                    <span class="activity-detail">
                                                        <strong>Date:</strong> <?php echo date('M d, Y', strtotime($activity['appointment_date'])); ?>
                                                        at <?php echo date('h:i A', strtotime($activity['appointment_time'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php elseif ($activity['type'] === 'doctor'): ?>
                                                <span class="activity-detail">
                                                    <strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($activity['doctor_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="activity-detail">
                                                    <strong>Patient:</strong> <?php echo htmlspecialchars($activity['patient_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="activity-meta">
                                        <span class="activity-status status-<?php echo $activity['status']; ?>">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                        <span class="activity-time">
                                            <?php 
                                            $createdTime = strtotime($activity['created_at']);
                                            $now = time();
                                            $diff = $now - $createdTime;
                                            
                                            if ($diff < 60) {
                                                echo 'Just now';
                                            } elseif ($diff < 3600) {
                                                echo floor($diff / 60) . ' minutes ago';
                                            } elseif ($diff < 86400) {
                                                echo floor($diff / 3600) . ' hours ago';
                                            } elseif ($diff < 604800) {
                                                echo floor($diff / 86400) . ' days ago';
                                            } else {
                                                echo date('M d, Y h:i A', $createdTime);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="activity-actions">
                                    <?php if ($activity['type'] === 'appointment'): ?>
                                        <button class="btn-view" onclick="viewActivityDetails('appointment', <?php echo $activity['id']; ?>)">
                                            View Details →
                                        </button>
                                    <?php elseif ($activity['type'] === 'doctor'): ?>
                                        <button class="btn-view" onclick="viewActivityDetails('doctor', <?php echo $activity['id']; ?>)">
                                            View Profile →
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-view" onclick="viewActivityDetails('patient', <?php echo $activity['id']; ?>)">
                                            View Profile →
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&type=<?php echo $filterType; ?>&search=<?php echo urlencode($searchQuery); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" 
                                   class="page-btn">
                                    ← Previous
                                </a>
                            <?php endif; ?>

                            <div class="page-numbers">
                                <?php 
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                if ($startPage > 1): ?>
                                    <a href="?page=1&type=<?php echo $filterType; ?>&search=<?php echo urlencode($searchQuery); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" 
                                       class="page-number">1</a>
                                    <?php if ($startPage > 2): ?>
                                        <span class="page-dots">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&type=<?php echo $filterType; ?>&search=<?php echo urlencode($searchQuery); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" 
                                       class="page-number <?php echo $i === $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <span class="page-dots">...</span>
                                    <?php endif; ?>
                                    <a href="?page=<?php echo $totalPages; ?>&type=<?php echo $filterType; ?>&search=<?php echo urlencode($searchQuery); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" 
                                       class="page-number"><?php echo $totalPages; ?></a>
                                <?php endif; ?>
                            </div>

                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&type=<?php echo $filterType; ?>&search=<?php echo urlencode($searchQuery); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" 
                                   class="page-btn">
                                    Next →
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📊</div>
                        <h3>No Activities Found</h3>
                        <p>There are no activities matching your current filters.</p>
                        <a href="activity.php" class="btn btn-primary">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Activity Details Modal -->
    <div id="activityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Loading...</h2>
                <button class="modal-close" onclick="closeActivityModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="modal-loading">
                    <div class="spinner"></div>
                    <p>Loading details...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="activity.js"></script>
</body>
</html>