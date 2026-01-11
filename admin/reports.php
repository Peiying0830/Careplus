<?php
session_start();
require_once __DIR__ . '/../config.php';

$conn = Database::getInstance()->getConnection();

$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['user_type'] ?? '';

if (!$user_id || $user_type !== 'admin') {
    header('Location: ../login.php?error=unauthorized');
    exit();
}

// Date range filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$reportType = $_GET['report_type'] ?? 'overview';

// Get statistics (REMOVED: total_revenue, avg_transaction, total_payments)
$statsStmt = $conn->prepare("SELECT 
    COUNT(DISTINCT a.appointment_id) as total_appointments,
    COUNT(DISTINCT CASE WHEN a.status='completed' THEN a.appointment_id END) as completed_appointments,
    COUNT(DISTINCT CASE WHEN a.status='cancelled' THEN a.appointment_id END) as cancelled_appointments,
    COUNT(DISTINCT p.patient_id) as total_patients
FROM appointments a
LEFT JOIN patients p ON a.patient_id = p.patient_id
WHERE a.appointment_date BETWEEN ? AND ?");

$statsStmt->bind_param("ss", $dateFrom, $dateTo);
$statsStmt->execute();
$statistics = $statsStmt->get_result()->fetch_assoc();

// Top doctors by appointments
$topDoctorsStmt = $conn->prepare("SELECT 
    d.doctor_id,
    d.first_name,
    d.last_name,
    d.specialization,
    COUNT(a.appointment_id) as appointment_count,
    COUNT(CASE WHEN a.status='completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN a.status='confirmed' THEN 1 END) as confirmed_count
FROM doctors d
LEFT JOIN appointments a ON d.doctor_id = a.doctor_id AND a.appointment_date BETWEEN ? AND ?
GROUP BY d.doctor_id, d.first_name, d.last_name, d.specialization
ORDER BY appointment_count DESC
LIMIT 10");

$topDoctorsStmt->bind_param("ss", $dateFrom, $dateTo);
$topDoctorsStmt->execute();
$doctors = $topDoctorsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Appointment status breakdown
$appointmentStatusStmt = $conn->prepare("SELECT 
    status,
    COUNT(*) as count
FROM appointments
WHERE appointment_date BETWEEN ? AND ?
GROUP BY status");

$appointmentStatusStmt->bind_param("ss", $dateFrom, $dateTo);
$appointmentStatusStmt->execute();
$statuses = $appointmentStatusStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$statsStmt->close();
$topDoctorsStmt->close();
$appointmentStatusStmt->close();

$trendStmt = $conn->prepare("SELECT 
    DATE(appointment_date) as date,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled
FROM appointments
WHERE appointment_date BETWEEN ? AND ?
GROUP BY DATE(appointment_date)
ORDER BY DATE(appointment_date) ASC");

$trendStmt->bind_param("ss", $dateFrom, $dateTo);
$trendStmt->execute();
$trendData = $trendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$trendStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - CarePlus</title>
    <link rel="stylesheet" href="reports.css">
</head>
<body>
    <?php include 'headerNav.php'; ?>

    <div class="container">
        <div class="page-header">
            <div class="header-left">
                <h1>📊 Reports & Analytics</h1>
                <p class="subtitle-indent">&emsp;&emsp;&emsp;&nbsp;Comprehensive insights and statistics</p>
            </div>
            <div class="header-right">
                <button class="btn-outline" onclick="exportReport()">📥 Export CSV</button>
                <button class="btn-primary" onclick="exportReportsPDF()" style="margin-left: 10px;">📄 Export PDF</button>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" class="filters-form" id="reportFilterForm">
                <select name="report_type" id="reportType">
                    <option value="overview" <?= $reportType === 'overview' ? 'selected' : '' ?>>Overview</option>
                    <option value="appointments" <?= $reportType === 'appointments' ? 'selected' : '' ?>>Appointments</option>
                    <option value="doctors" <?= $reportType === 'doctors' ? 'selected' : '' ?>>Doctors</option>
                </select>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" required>
                <button type="submit" class="btn-filter">Generate Report</button>
            </form>

            <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                <button type="button" onclick="setDateRange('today')" style="flex: 1; padding: 8px 16px; border-radius: 8px; border: 2px solid #3b82f6; background: #dbeafe; color: #1e40af; font-weight: 600; cursor: pointer; font-size: 14px;">📅 Today</button>
                <button type="button" onclick="setDateRange('week')"  style="flex: 1; padding: 8px 16px; border-radius: 8px; border: 2px solid #8b5cf6; background: #ede9fe; color: #6d28d9; font-weight: 600; cursor: pointer; font-size: 14px;">📊 This Week</button>
                <button type="button" onclick="setDateRange('month')"  style="flex: 1; padding: 8px 16px; border-radius: 8px; border: 2px solid #16a34a; background: #dcfce7; color: #166534; font-weight: 600; cursor: pointer; font-size: 14px;">📅 This Month</button>
                <button type="button" onclick="setDateRange('year')"  style="flex: 1; padding: 8px 16px; border-radius: 8px; border: 2px solid #f59e0b; background: #fef3c7; color: #92400e; font-weight: 600; cursor: pointer; font-size: 14px;">📈 This Year</button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #DBEAFE; color: #2563EB;">📅</div>
                <div class="stat-content">
                    <h3><?= $statistics['total_appointments'] ?></h3>
                    <p>Total Appointments</p>
                    <small class="stat-change <?= $statistics['completed_appointments'] > 0 ? 'positive' : '' ?>">
                        +<?= $statistics['completed_appointments'] ?> completed
                    </small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #FEF3C7; color: #F59E0B;">👥</div>
                <div class="stat-content">
                    <h3><?= $statistics['total_patients'] ?></h3>
                    <p>Active Patients</p>
                    <small class="stat-change">In selected period</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #E0E7FF; color: #6366F1;">📈</div>
                <div class="stat-content">
                    <h3><?= $statistics['total_appointments'] > 0 ? round(($statistics['completed_appointments'] / $statistics['total_appointments']) * 100) : 0 ?>%</h3>
                    <p>Completion Rate</p>
                    <small class="stat-change negative"><?= $statistics['cancelled_appointments'] ?> cancelled</small>
                </div>
            </div>
        </div>

        <div class="charts-container">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>📈 Appointment Trends</h3>
                    <span class="chart-subtitle">Volume over time</span>
                </div>
                <div class="chart-canvas-wrapper">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>📊 Status Breakdown</h3>
                    <span class="chart-subtitle">Current distribution</span>
                </div>
                <div class="chart-canvas-wrapper">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Doctors -->
        <div class="table-section">
            <div class="section-header">
                <h2>👨‍⚕️ Top Performing Doctors</h2>
                <span class="section-subtitle subtitle-indent">Based on appointment volume</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Doctor</th>
                            <th>Specialization</th>
                            <th>Apointments</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($doctors)): ?>
                            <tr><td colspan="5" style="text-align:center;">No doctor data available</td></tr>
                        <?php else: foreach ($doctors as $index => $doc): ?>
                        <tr>
                            <td>#<?= $index + 1 ?></td>
                            <td><strong>Dr. <?= htmlspecialchars($doc['first_name'].' '.$doc['last_name']) ?></strong></td>
                            <td><span class="badge badge-specialty"><?= htmlspecialchars($doc['specialization']) ?></span></td>
                            <td><?= $doc['appointment_count'] ?></td>
                            <td>
                                <?php 
                                    // Calculate confirmed rate
                                    $rate = $doc['appointment_count'] > 0 ? round((($doc['confirmed_count'] + $doc['completed_count']) / $doc['appointment_count']) * 100) : 0;
                                    $barColor = $rate >= 70 ? '#10b981' : ($rate >= 40 ? '#3b82f6' : '#f59e0b');
                                ?>
                                <div class="progress-container" style="display: flex; align-items: center; gap: 10px;">
                                    <div class="progress-bar" style="flex-grow: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                        <div class="progress-fill" style="width:<?= $rate ?>%; height: 100%; background: <?= $barColor ?>; transition: width 0.5s ease;"></div>
                                    </div>
                                    <span style="font-size: 12px; font-weight: bold; color: #64748b; min-width: 35px;"><?= $rate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>        
        const statusData = <?= json_encode($statuses) ?>;
        const trendData = <?= json_encode($trendData) ?>;
    </script>
    <script src="reports.js"></script>
</body>
</html>