<?php
session_start();
require_once __DIR__ . '/../config.php';

// Get the MySQLi connection
$conn = Database::getInstance()->getConnection();

// Check for Flash Messages 
$message = '';
$messageType = '';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'];
    // Clear them so they don't show up again on next refresh
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

$transactionStarted = false; 

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // Capture Items Array and convert to JSON
        $items_json = null;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $valid_items = [];
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['amount'])) {
                    $valid_items[] = [
                        'item' => sanitizeInput($item['description']), 
                        'price' => (float)$item['amount']
                    ];
                }
            }
            if (!empty($valid_items)) {
                $items_json = json_encode($valid_items);
            }
        }

        if ($action === 'add') {
                $conn->begin_transaction();
                $transactionStarted = true;
                
                $receipt_number = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
                
                $stmt = $conn->prepare("INSERT INTO payments (patient_id, appointment_id, amount, payment_method, payment_status, receipt_number, transaction_id, payment_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                
                $p_id = $_POST['patient_id'];
                $a_id = !empty($_POST['appointment_id']) ? $_POST['appointment_id'] : null;
                $amt = $_POST['amount'];
                $meth = $_POST['payment_method'];
                $stat = $_POST['payment_status'] ?? 'pending';
                $t_id = !empty($_POST['transaction_id']) ? $_POST['transaction_id'] : null;

                $stmt->bind_param("iidsssss", $p_id, $a_id, $amt, $meth, $stat, $receipt_number, $t_id, $items_json);
                $stmt->execute();
                
                $payment_id = $conn->insert_id; 
                
                if (!empty($valid_items)) {
                        $itemStmt = $conn->prepare("
                        INSERT INTO payment_items (payment_id, item_type, item_name, description, quantity, unit_price, total_price)
                        VALUES (?, 'other', ?, '', 1, ?, ?)
                        ");
                        
                        foreach ($valid_items as $item) {
                        $price = (float)$item['price'];
                        $itemStmt->bind_param("isdd", $payment_id, $item['item'], $price, $price);
                        $itemStmt->execute();
                        }
                }
                
                $conn->commit();
                
                $_SESSION['flash_message'] = "Payment recorded successfully. Receipt #: " . $receipt_number;
                $_SESSION['flash_type'] = "success";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();

        }elseif ($action === 'edit') {
                $payment_id = $_POST['payment_id'];
                
                $conn->begin_transaction();
                
                $stmt = $conn->prepare("UPDATE payments SET patient_id=?, appointment_id=?, amount=?, payment_method=?, payment_status=?, transaction_id=?, payment_details=? WHERE payment_id=?");
                
                $p_id = $_POST['patient_id'];
                $a_id = !empty($_POST['appointment_id']) ? $_POST['appointment_id'] : null;
                $amt = $_POST['amount'];
                $meth = $_POST['payment_method'];
                $stat = $_POST['payment_status'];
                $t_id = $_POST['transaction_id'] ?? null;

                $stmt->bind_param("iidssssi", $p_id, $a_id, $amt, $meth, $stat, $t_id, $items_json, $payment_id);
                $stmt->execute();
                
                $deleteStmt = $conn->prepare("DELETE FROM payment_items WHERE payment_id = ?");
                $deleteStmt->bind_param("i", $payment_id);
                $deleteStmt->execute();
                
                if (!empty($valid_items)) {
                        $itemStmt = $conn->prepare("
                        INSERT INTO payment_items (payment_id, item_type, item_name, description, quantity, unit_price, total_price)
                        VALUES (?, 'other', ?, '', 1, ?, ?)
                        ");
                        
                        foreach ($valid_items as $item) {
                        $price = (float)$item['price'];
                        $itemStmt->bind_param("isdd", $payment_id, $item['item'], $price, $price);
                        $itemStmt->execute();
                        }
                }
                
                $conn->commit();
                
                $_SESSION['flash_message'] = "Payment updated successfully.";
                $_SESSION['flash_type'] = "success";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();

        } elseif ($action === 'delete') {
            $payment_id = $_POST['payment_id'];
            $stmt = $conn->prepare("DELETE FROM payments WHERE payment_id = ?");
            $stmt->bind_param("i", $payment_id);
            $stmt->execute();
            
            // Redirect
            $_SESSION['flash_message'] = "Payment record deleted successfully.";
            $_SESSION['flash_type'] = "success";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
            
        } elseif ($action === 'confirm_payment') {
            $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
            $confirm_action = $_POST['confirm_action'] ?? ''; 
            $notes = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');
            $appt_payment_status = $_POST['appointment_payment_status'] ?? 'unpaid';
            $currentUserId = $_SESSION['user_id']; 

            if (!$payment_id) {
                throw new Exception("Invalid payment ID");
            }

            $newPaymentStatus = ($confirm_action === 'approve') ? 'completed' : 'failed';

            $stmtAppt = $conn->prepare("SELECT appointment_id FROM payments WHERE payment_id = ?");
            $stmtAppt->bind_param("i", $payment_id);
            $stmtAppt->execute();
            $apptResult = $stmtAppt->get_result()->fetch_assoc();
            $appointmentId = $apptResult['appointment_id'] ?? null;

            $conn->begin_transaction();

            try {
                $updateStmt = $conn->prepare("UPDATE payments SET payment_status = ? WHERE payment_id = ?");
                $updateStmt->bind_param("si", $newPaymentStatus, $payment_id);
                $updateStmt->execute();

                if ($appointmentId) {
                    $updateApptStmt = $conn->prepare("UPDATE appointments SET payment_status = ? WHERE appointment_id = ?");
                    $updateApptStmt->bind_param("si", $appt_payment_status, $appointmentId);
                    $updateApptStmt->execute();
                }

                $conn->commit();
                
                // Redirect
                $msg = ($confirm_action === 'approve') 
                    ? "Payment approved successfully! Appointment status updated to: " . strtoupper($appt_payment_status) 
                    : "Payment rejected. Appointment status set to: " . strtoupper($appt_payment_status);
                
                $_SESSION['flash_message'] = $msg;
                $_SESSION['flash_type'] = "success";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                throw $e; // Throw to outer catch block
            }
        }

    } catch (Exception $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        
        // It is often safer to redirect on error too, to avoid re-submitting bad data
        $_SESSION['flash_message'] = "Error: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Search and Filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$methodFilter = $_GET['method'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Query with appointment payment status
$query = "SELECT 
        py.*,
        p.first_name as patient_fname, 
        p.last_name as patient_lname,
        p.phone as patient_phone,
        p.ic_number as patient_ic,
        a.appointment_date,
        a.appointment_time,
        a.payment_status as appt_payment_status, 
        d.first_name as doctor_fname,
        d.last_name as doctor_lname
FROM payments py
JOIN patients p ON py.patient_id = p.patient_id
LEFT JOIN appointments a ON py.appointment_id = a.appointment_id
LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
WHERE 1=1";

$params = [];
$types = "";

if ($search) {
    $query .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.ic_number LIKE ? OR py.receipt_number LIKE ?)";
    $st = "%$search%";
    array_push($params, $st, $st, $st, $st);
    $types .= "ssss";
}

if ($statusFilter) {
    $query .= " AND py.payment_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($methodFilter) {
    $query .= " AND py.payment_method = ?";
    $params[] = $methodFilter;
    $types .= "s";
}

if ($dateFrom) {
    $query .= " AND DATE(py.payment_date) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo) {
    $query .= " AND DATE(py.payment_date) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$query .= " ORDER BY py.payment_date DESC";
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$statsResult = $conn->query("SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN payment_status='completed' THEN amount ELSE 0 END), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN payment_status='pending' THEN amount ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN payment_status='completed' THEN 1 ELSE 0 END), 0) as completed,
        COALESCE(SUM(CASE WHEN payment_status='pending' THEN 1 ELSE 0 END), 0) as pending,
        COALESCE(SUM(CASE WHEN payment_status='failed' THEN 1 ELSE 0 END), 0) as failed,
        COALESCE(SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END), 0) as today_revenue
        FROM payments");
$stats = $statsResult->fetch_assoc();

// Dropdowns
$patients = $conn->query("SELECT patient_id, first_name, last_name, ic_number FROM patients ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);
$appointmentsList = $conn->query("SELECT 
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.payment_status,
        a.patient_id,
        p.first_name as patient_fname,
        p.last_name as patient_lname,
        d.first_name as doctor_fname,
        d.last_name as doctor_lname
FROM appointments a
JOIN patients p ON a.patient_id = p.patient_id
JOIN doctors d ON a.doctor_id = d.doctor_id
WHERE a.status IN ('confirmed', 'completed')
ORDER BY a.appointment_date DESC")->fetch_all(MYSQLI_ASSOC);

$appointmentsJson = json_encode($appointmentsList);
?>

<!DOCTYPE html>
<html lang="en">
<head>
        <meta charset="UTF-8">
        <title>Billing & Payment Management - CarePlus</title>
        <link rel="stylesheet" href="billing.css">
</head>
<body>
        <?php include 'headerNav.php'; ?>

        <div class="container">
                <!-- Header & Stats -->
                <div class="page-header">
                <div class="header-left">
                        <h1>💰 Billing & Payment Management</h1>
                        <p>&emsp;&emsp;&emsp;&nbsp;Manage patient payments, invoices, and confirmations</p>
                </div>
                <div class="header-right">
                        <button class="btn btn-outline" onclick="exportPaymentsCSV()"><span>📥</span> Export CSV</button>
                        <button class="btn btn-outline" onclick="exportPaymentsPDF()"><span>📄</span> Export PDF</button>
                        <button class="btn btn-primary" onclick="openAddModal()">➕ Record Payment</button>
                </div>
                </div>

                <?php if($message): ?>
                <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
                        <div class="stat-card">
                                <div class="stat-icon" style="background: #DCFCE7; color: #16A34A;">💵</div>
                                <div class="stat-content">
                                <h3>RM <?= number_format($stats['total_revenue'] ?? 0, 2) ?></h3>
                                <p>Total Payments Received</p>
                                </div>
                        </div>
                        <div class="stat-card">
                                <div class="stat-icon" style="background: #FEF3C7; color: #F59E0B;">⏳</div>
                                <div class="stat-content">
                                <h3><?= $stats['pending'] ?? 0 ?></h3>
                                <p>Pending Payments</p>
                                </div>
                        </div>
                        <div class="stat-card">
                                <div class="stat-icon" style="background: #DBEAFE; color: #418affff;">📊</div>
                                <div class="stat-content">
                                <h3><?= $stats['completed'] ?? 0 ?></h3>
                                <p>Paid</p>
                                </div>
                        </div>
                        <div class="stat-card">
                                <div class="stat-icon" style="background: #E0E7FF; color: #6366F1;">📅</div>
                                <div class="stat-content">
                                <h3>RM <?= number_format($stats['today_revenue'] ?? 0, 2) ?></h3>
                                <p>Today's Paid</p>
                                </div>
                        </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                <form method="GET" class="filters-form">
                        <div class="search-box">
                        <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select name="status">
                        <option value="">All Status</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                        <select name="method">
                        <option value="">All Methods</option>
                        <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="card" <?= $methodFilter === 'card' ? 'selected' : '' ?>>Card</option>
                        <option value="online" <?= $methodFilter === 'online' ? 'selected' : '' ?>>Online</option>
                        </select>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                        <button type="submit" class="btn-filter">Filter</button>
                </form>
                </div>

                <!-- Table -->
                <div class="table-container">
                <table class="payments-table">
                        <thead>
                                <tr>
                                        <th>Receipt #</th>
                                        <th>Patient</th>
                                        <th>Appointment</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                </tr>
                        </thead>
                        <tbody>
                                <?php foreach ($payments as $pay): 
                                $currentApptStatus = $pay['appt_payment_status'] ?? 'unpaid';
                                ?>
                                <tr>
                                <td><code class="receipt-code"><?= htmlspecialchars($pay['receipt_number']) ?></code></td>
                                <td>
                                        <strong><?= htmlspecialchars($pay['patient_fname'] . ' ' . $pay['patient_lname']) ?></strong><br>
                                        <small><?= htmlspecialchars($pay['patient_ic']) ?></small>
                                </td>
                                <td>
                                        <?php if ($pay['appointment_id']): ?>
                                        <strong>#<?= $pay['appointment_id'] ?></strong>
                                        <span class="status-badge" style="font-size: 0.7em; padding: 2px 6px; background: #f3f4f6; color: #666;">
                                                <?= ucfirst($currentApptStatus) ?>
                                        </span><br>
                                        <small><?= date('M d, Y', strtotime($pay['appointment_date'])) ?></small>
                                        <?php else: ?>
                                        <span class="text-muted">Walk-in</span>
                                        <?php endif; ?>
                                </td>
                                <td><strong class="amount-text">RM <?= number_format($pay['amount'] ?? 0, 2) ?></strong></td>
                                <td><span class="method-badge method-<?= $pay['payment_method'] ?>"><?= ucfirst($pay['payment_method']) ?></span></td>
                                <td><span class="status-badge status-<?= $pay['payment_status'] ?>"><?= ucfirst($pay['payment_status']) ?></span></td>
                                <td>
                                        <strong><?= date('M d, Y', strtotime($pay['payment_date'])) ?></strong><br>
                                        <small><?= date('h:i A', strtotime($pay['payment_date'])) ?></small>
                                </td>
                                <td class="actions">
                                <button class="btn-action btn-view" 
                                        onclick="viewPayment(<?= $pay['payment_id'] ?>)" 
                                        title="View">👁️</button>

                                <?php if ($pay['payment_status'] === 'pending'): ?>
                                        <button class="btn-action btn-approve" 
                                                onclick="openConfirmModal(<?= $pay['payment_id'] ?>, 'approve', '<?= $currentApptStatus ?>')">✅</button>
                                        <button class="btn-action btn-reject" 
                                                onclick="openConfirmModal(<?= $pay['payment_id'] ?>, 'reject', '<?= $currentApptStatus ?>')">❌</button>
                                <?php endif; ?>

                                <button class="btn-action btn-edit" 
                                        onclick="editPayment(<?= $pay['payment_id'] ?>)" 
                                        title="Edit">✏️</button>
                                        
                                <button class="btn-action btn-delete" 
                                        onclick="deletePayment(<?= $pay['payment_id'] ?>, '<?= htmlspecialchars($pay['receipt_number']) ?>')" 
                                        title="Delete">🗑️</button>
                                </td>
                                </tr>
                                <?php endforeach; ?>
                        </tbody>
                </table>
        </div>
        <!-- Add/Edit Payment Modal -->
        <div id="paymentModal" class="modal">
                <div class="modal-backdrop" onclick="closeModal()"></div>
                <div class="modal-content">
                <div class="modal-header">
                        <h2 id="modalTitle">💰 Record New Payment</h2>
                        <button class="modal-close" onclick="closeModal()">×</button>
                </div>
                <div class="modal-body">
                        <form id="paymentForm" method="POST">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="payment_id" id="paymentId">
                        
                        <div class="form-section">
                                <h3>💳 Patient Info</h3>
                                <div class="form-row">
                                <div class="form-group">
                                        <label>Patient <span style="color: red;">*</span></label>
                                        <select name="patient_id" id="patientId" required onchange="loadPatientAppointments()">
                                        <option value="">Select Patient</option>
                                        <?php foreach ($patients as $patient): ?>
                                                <option value="<?= $patient['patient_id'] ?>"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?> - <?= $patient['ic_number'] ?></option>
                                        <?php endforeach; ?>
                                        </select>
                                </div>
                                <div class="form-group">
                                        <label>Appointment (Optional)</label>
                                        <select name="appointment_id" id="appointmentId"><option value="">Select or Leave Blank</option></select>
                                </div>
                                </div>
                        </div>

                        <!-- Itemized Billing -->
                        <div class="form-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                <h3>🧾 Bill Items</h3>
                                <button type="button" class="btn-savePayment" onclick="addBillItem()">+ Add Item</button>
                                </div>
                                
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
                                <thead>
                                        <tr style="width: 100%; padding: 0.875rem 1rem; background: #ffffffff;">
                                        <th><label>&emsp;Description</label></th>
                                        <th>&emsp;Price (RM)</th>
                                        <th style="padding: 8px; width: 30px;"></th>
                                        </tr>
                                </thead>
                                <tbody id="billItemsContainer">
                                        <!-- Javascript will add rows here -->
                                </tbody>
                                </table>
                                
                                <div style="text-align: right; font-weight: bold; margin-top: 10px;">
                                Total Calculated: RM <span id="calculatedTotal">0.00</span>
                                </div>
                        </div>
                
                        <div class="form-section" style="border-bottom: none; margin-bottom: 0;">
                                <h3>💰 Payment Details</h3>
                                <div class="form-row">
                                <div class="form-group">
                                        <label>Total Amount (RM) <span style="color: red;">*</span></label>
                                        <input type="number" name="amount" id="amount" step="0.01" min="0" required readonly style="background: #f9fafb; font-weight: bold;">
                                </div>
                                <div class="form-group">
                                        <label>Payment Method <span style="color: red;">*</span></label>
                                        <select name="payment_method" id="paymentMethod" required>
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                        <option value="online">Online</option>
                                        </select>
                                </div>
                                </div>
                                <div class="form-row">
                                <div class="form-group">
                                        <label>Status</label>
                                        <select name="payment_status" id="paymentStatus">
                                        <option value="pending">Pending</option>
                                        <option value="completed">Completed</option>
                                        </select>
                                </div>
                                <div class="form-group">
                                        <label>Transaction ID</label>
                                        <input type="text" name="transaction_id" id="transactionId">
                                </div>
                                </div>
                        </div>
                        </form>
                </div>
                <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                        <button type="button" class="btn-savePayment" id="submitBtn" onclick="document.getElementById('paymentForm').submit()">Save Payment</button>
                </div>
                </div>
        </div>

        <!-- Confirm Payment Modal -->
        <div id="confirmModal" class="modal">
                <div class="modal-backdrop" onclick="closeConfirmModal()"></div>
                <div class="modal-content">
                <div class="modal-header">
                        <h2 id="confirmModalTitle">Confirm Payment</h2>
                        <button class="modal-close" onclick="closeConfirmModal()">×</button>
                </div>
                
                <form method="POST" id="confirmForm">
                        <input type="hidden" name="action" value="confirm_payment">
                        <input type="hidden" name="payment_id" id="confirm_payment_id">
                        <input type="hidden" name="confirm_action" id="confirm_action_type">
                        
                        <div class="modal-body">
                        <p id="confirmMessage" style="margin-bottom: 15px; font-weight: 500;"></p>
                        
                        <div class="form-group" style="margin-bottom: 15px; background: #f9fafb; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">
                                Update Appointment Status To:
                                </label>
                                <select name="appointment_payment_status" id="confirmAppointmentStatus" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #d1d5db;">
                                <option value="unpaid">Unpaid</option>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                </select>
                                <small style="display: block; margin-top: 5px; color: #6b7280;">This will update the appointment payment status.</small>
                        </div>

                        <div class="form-group">
                                <label>Notes (Optional)</label>
                                <textarea name="notes" id="confirm_notes" rows="3" placeholder="Add any notes..."></textarea>
                        </div>
                        </div>
                        
                        <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeConfirmModal()">Cancel</button>
                        <button type="submit" class="btn-savePayment" id="confirmSubmitBtn">Confirm</button>
                        </div>
                </form>
                </div>
        </div>

        <!-- View Modal -->
        <div id="viewModal" class="modal">
                <div class="modal-backdrop" onclick="closeViewModal()"></div>
                <div class="modal-content">
                <div class="modal-header">
                        <h2>🔍 Payment Details</h2>
                        <button class="modal-close" onclick="closeViewModal()">×</button>
                </div>
                <div class="modal-body" id="viewContent"></div>
                <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeViewModal()">Close</button>
                        <button type="button" class="btn-printReceipt" onclick="printReceipt()">📄 Print Receipt</button>
                </div>
                </div>
        </div>
        
        <!-- Delete Form -->
        <form id="deleteForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="payment_id" id="delPaymentId">
        </form>

        <script>
        console.log('=== DEBUGGING DATA ===');
        console.log('Raw payments from PHP:', <?= json_encode($payments) ?>);
        console.log('Raw appointments from PHP:', <?= $appointmentsJson ?>);
        console.log('Total payments count:', <?= count($payments) ?>);
        console.log('======================');

        // Initialize data for JavaScript
        window.allPaymentsData = <?= json_encode($payments) ?>;
        window.appointmentsData = <?= $appointmentsJson ?>;
        
        console.log('window.allPaymentsData:', window.allPaymentsData);
        console.log('window.appointmentsData:', window.appointmentsData);
        </script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
        <script src="billing.js"></script>
</body>
</html>