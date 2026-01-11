<?php
require_once __DIR__ . '/../config.php';
requireRole('doctor');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch logs
$stmt = $conn->prepare("
    SELECT pl.*, u.email
    FROM prescription_logs pl
    LEFT JOIN users u ON pl.user_id = u.user_id
    WHERE pl.user_id = ?
    ORDER BY pl.created_at DESC
    LIMIT 100
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate stats using local array
$createCount = count(array_filter($logs, fn($l) => $l['action'] === 'create'));
$viewCount = count(array_filter($logs, fn($l) => $l['action'] === 'view'));
$printCount = count(array_filter($logs, fn($l) => $l['action'] === 'print'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Logs - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        body {
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #ccdeff 0%, #ccdeff 100%);
            min-height: 100vh;
            color: #2D3748;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 2.5rem;
        }

        .page-header {
            background: linear-gradient(135deg, #D9BFF7 0%, #C4A5F0 100%);
            color: white;
            border-radius: 28px;
            padding: 2.5rem 3rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 10px 40px rgba(196, 165, 240, 0.25);
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            text-align: center;
        }

        .stat-card .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: #C4A5F0;
            display: block;
        }

        .stat-card .label {
            font-size: 0.9rem;
            color: #718096;
            margin-top: 0.25rem;
        }

        .logs-table-container {
            background: white;
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            overflow-x: auto;
        }

        .logs-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .logs-table thead {
            background: linear-gradient(135deg, #D9BFF7 0%, #C4A5F0 100%);
            color: white;
        }

        .logs-table th {
            padding: 1rem 1.25rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .logs-table th:first-child {
            border-radius: 16px 0 0 0;
        }

        .logs-table th:last-child {
            border-radius: 0 16px 0 0;
        }

        .logs-table td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(196, 165, 240, 0.1);
        }

        .logs-table tbody tr:hover {
            background: rgba(196, 165, 240, 0.05);
        }

        .action-badge {
            display: inline-block;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .action-create { background: #7FE5B8; color: white; }
        .action-view { background: #6FD9D2; color: white; }
        .action-print { background: #6DADE8; color: white; }
        .action-error { background: #FF9090; color: white; }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: white;
            color: #C4A5F0;
            border: 2px solid #C4A5F0;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            margin-bottom: 1rem;
        }

        .btn-back:hover {
            background: #C4A5F0;
            color: white;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/headerNav.php'; ?>

    <div class="container">
        <a href="prescriptions.php" class="btn-back">← Back to Prescriptions</a>

        <div class="page-header">
            <h1>📊 Prescription Activity Logs</h1>
            <p>Track all prescription-related activities</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">📝</div>
                <span class="number"><?php echo $createCount; ?></span>
                <div class="label">Created</div>
            </div>
            <div class="stat-card">
                <div class="icon">👁️</div>
                <span class="number"><?php echo $viewCount; ?></span>
                <div class="label">Viewed</div>
            </div>
            <div class="stat-card">
                <div class="icon">🖨️</div>
                <span class="number"><?php echo $printCount; ?></span>
                <div class="label">Printed</div>
            </div>
            <div class="stat-card">
                <div class="icon">❌</div>
                <span class="number"><?php echo $errorCount; ?></span>
                <div class="label">Errors</div>
            </div>
        </div>

        <div class="logs-table-container">
            <h2 style="margin-bottom: 1.5rem; color: #2D3748;">Recent Activity (Last 100)</h2>
            
            <?php if (!empty($logs)): ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <span class="action-badge action-<?php echo $log['action']; ?>">
                                        <?php echo strtoupper($log['action']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; padding: 3rem; color: #718096;">No activity logs found.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>