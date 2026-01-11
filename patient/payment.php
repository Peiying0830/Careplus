<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch patient info
$patientStmt = $conn->prepare("SELECT p.*, u.email FROM patients p JOIN users u ON p.user_id = u.user_id WHERE p.user_id = ? LIMIT 1");
$patientStmt->bind_param("i", $userId);
$patientStmt->execute();
$patient = $patientStmt->get_result()->fetch_assoc();

if (!$patient) {
    redirect('patient/profile.php');
}

$patientId = $patient['patient_id'];

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    try {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
        $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
        $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
        $reviewText = htmlspecialchars(trim($_POST['review_text'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        if (!$appointmentId || !$doctorId || !$rating || $rating < 1 || $rating > 5) {
            throw new Exception("Invalid review data");
        }
        
        // Check existing
        $checkStmt = $conn->prepare("SELECT review_id FROM reviews WHERE appointment_id = ? AND patient_id = ?");
        $checkStmt->bind_param("ii", $appointmentId, $patientId);
        $checkStmt->execute();
        $existingReview = $checkStmt->get_result()->fetch_assoc();
        
        if ($existingReview) {
            if (isset($_POST['update_review']) && $_POST['update_review'] == '1') {
                $updateStmt = $conn->prepare("UPDATE reviews SET rating = ?, review_text = ?, updated_at = NOW() WHERE review_id = ?");
                $updateStmt->bind_param("isi", $rating, $reviewText, $existingReview['review_id']);
                $updateStmt->execute();
                $_SESSION['success_message'] = "Your review has been updated successfully!";
            }
        } else {
            $insertStmt = $conn->prepare("INSERT INTO reviews (doctor_id, patient_id, appointment_id, rating, review_text, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $insertStmt->bind_param("iiiis", $doctorId, $patientId, $appointmentId, $rating, $reviewText);
            $insertStmt->execute();
            $_SESSION['success_message'] = "Thank you for your review!";
        }
        header("Location: payment.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Failed to submit review: " . $e->getMessage();
    }
}

// Handle AJAX requests for receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'get_receipt') {
        try {
            $paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
            
            if (!$paymentId) {
                echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
                exit;
            }
            
            // Fetch receipt data with all necessary joins
            $receiptStmt = $conn->prepare("
                SELECT 
                    p.payment_id,
                    p.amount,
                    p.payment_method,
                    p.transaction_id,
                    p.payment_date,
                    p.payment_status,
                    a.appointment_id,
                    a.appointment_date,
                    a.appointment_time,
                    d.doctor_id,
                    CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
                    d.specialization,
                    pat.patient_id,
                    CONCAT(pat.first_name, ' ', pat.last_name) as patient_name,
                    pat.phone as patient_phone,
                    u.email as patient_email
                FROM payments p
                JOIN appointments a ON p.appointment_id = a.appointment_id
                JOIN doctors d ON a.doctor_id = d.doctor_id
                JOIN patients pat ON a.patient_id = pat.patient_id
                JOIN users u ON pat.user_id = u.user_id
                WHERE p.payment_id = ? AND pat.user_id = ?
                LIMIT 1
            ");
            
            $receiptStmt->bind_param("ii", $paymentId, $userId);
            $receiptStmt->execute();
            $receipt = $receiptStmt->get_result()->fetch_assoc();
            
            if (!$receipt) {
                echo json_encode(['success' => false, 'message' => 'Receipt not found or access denied']);
                exit;
            }
            
            // Verify payment belongs to current user
            if ($receipt['payment_status'] !== 'completed') {
                echo json_encode(['success' => false, 'message' => 'Payment not completed']);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'receipt' => $receipt
            ]);
            exit;
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error retrieving receipt: ' . $e->getMessage()
            ]);
            exit;
        }
    }
}

// Fetch all appointments
$appointmentsStmt = $conn->prepare("
    SELECT 
        a.appointment_id, 
        a.appointment_date, 
        a.appointment_time, 
        a.status as appointment_status,
        d.doctor_id, 
        d.first_name as doctor_fname, 
        d.last_name as doctor_lname, 
        d.specialization, 
        d.profile_picture as doctor_profile_picture, 
        d.gender as doctor_gender,
        p.payment_id,
        p.payment_status,
        p.amount,
        r.review_id, 
        r.rating, 
        r.review_text
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN payments p ON a.appointment_id = p.appointment_id
    LEFT JOIN reviews r ON a.appointment_id = r.appointment_id AND r.patient_id = ?
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$appointmentsStmt->bind_param("ii", $patientId, $patientId);
$appointmentsStmt->execute();
$appointments = $appointmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate stats and identify pending payments
$totalAppointments = count($appointments);
$paidCount = 0;
$pendingCount = 0;
$completedCount = 0;
$pendingPayments = []; 

foreach ($appointments as $apt) {
    if ($apt['payment_status'] === 'completed') {
        $paidCount++;
    } 
    elseif ($apt['payment_status'] === 'pending') {
        $pendingCount++;
        $pendingPayments[] = [
            'appointment_id' => $apt['appointment_id'],
            'doctor_id' => $apt['doctor_id'],
            'doctor_fname' => $apt['doctor_fname'],
            'doctor_lname' => $apt['doctor_lname'],
            'specialization' => $apt['specialization'],
            'doctor_profile_picture' => $apt['doctor_profile_picture'],
            'doctor_gender' => $apt['doctor_gender'],
            'appointment_date' => $apt['appointment_date'],
            'appointment_time' => $apt['appointment_time'],
            'consultation_fee' => $apt['amount']
        ];
    }
    
    if ($apt['appointment_status'] === 'completed') {
        $completedCount++;
    }
}

// Get eligible reviews
$eligibleStmt = $conn->prepare("
    SELECT a.appointment_id, a.appointment_date, d.doctor_id, d.first_name as doctor_fname, 
        d.last_name as doctor_lname, d.specialization, d.profile_picture as doctor_profile_picture, d.gender as doctor_gender
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    LEFT JOIN reviews r ON a.appointment_id = r.appointment_id
    WHERE a.patient_id = ? AND a.status = 'completed' AND r.review_id IS NULL
");
$eligibleStmt->bind_param("i", $patientId);
$eligibleStmt->execute();
$eligibleReviews = $eligibleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingReviewCount = count($eligibleReviews);

// Function to generate star display
function getStarDisplay($rating) {
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '<span class="star-filled">★</span>';
        } else {
            $stars .= '<span class="star-empty">★</span>';
        }
    }
    return $stars;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="payment.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/headerNav.php'; ?>

    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="header-content">
                    <h1>💳 Payment Status</h1>
                    <p>View your appointment payment status and share your experience</p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success fade-in">
                    <span class="alert-icon">✅</span>
                    <div class="alert-content">
                        <strong>Success!</strong>
                        <p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p>
                    </div>
                    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error fade-in">
                    <span class="alert-icon">❌</span>
                    <div class="alert-content">
                        <strong>Error!</strong>
                        <p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p>
                    </div>
                    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endif; ?>

            <!-- PENDING PAYMENT SECTION - PROMINENT DISPLAY -->
            <?php if (!empty($pendingPayments)): ?>
                <div class="pending-payments-section fade-in" data-count="<?php echo count($pendingPayments); ?>">
                    <div class="pending-section-header">
                        <h2 class="pending-title">
                            <span class="pending-icon">⚠️</span>
                            Action Required: Pending Payments
                        </h2>
                        <span class="pending-count-badge">
                            <?php echo count($pendingPayments); ?> Payment<?php echo count($pendingPayments) > 1 ? 's' : ''; ?> Due
                        </span>
                    </div>

                    <div class="pending-payments-grid">
                        <?php foreach ($pendingPayments as $pending): ?>
                            <div class="pending-payment-card">
                                <div class="payment-card-header">
                                    <div class="doctor-info">
                                        <div class="doctor-avatar">
                                            <?php 
                                            $dbPath = $pending['doctor_profile_picture'] ?? '';
                                            $physicalPath = __DIR__ . '/../' . ltrim($dbPath, '/');
                                            if (!empty($dbPath) && file_exists($physicalPath)): 
                                            ?>
                                                <img src="../<?php echo htmlspecialchars($dbPath); ?>" alt="Dr. Avatar">
                                            <?php else: ?>
                                                <span><?php echo ($pending['doctor_gender'] ?? 'male') === 'female' ? '👩‍⚕️' : '👨‍⚕️'; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="doctor-details">
                                            <h3>Dr. <?php echo htmlspecialchars($pending['doctor_fname'] . ' ' . $pending['doctor_lname']); ?></h3>
                                            <p class="specialization"><?php echo htmlspecialchars($pending['specialization']); ?></p>
                                        </div>
                                    </div>
                                    <div class="amount-display">
                                        <span class="amount-label">Amount Due</span>
                                        <span class="amount">RM <?php echo number_format($pending['consultation_fee'], 2); ?></span>
                                    </div>
                                </div>
                                <div class="payment-card-body">
                                    <div class="appointment-details">
                                        <div class="detail-item">
                                            <span class="detail-icon">📅</span>
                                            <span><?php echo date('M d, Y', strtotime($pending['appointment_date'])); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-icon">🕐</span>
                                            <span><?php echo date('h:i A', strtotime($pending['appointment_time'])); ?></span>
                                        </div>
                                    </div>
                                    <button class="btn-action btn-pay" 
                                            onclick="showLocalhostPaymentMessage()"
                                            title="Pay at Counter">
                                        💳 Pay Now
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon total"><span>📋</span></div>
                    <div class="stat-info">
                        <h3><?php echo $totalAppointments; ?></h3>
                        <p>Total Appointments</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><span>✅</span></div>
                    <div class="stat-info">
                        <h3><?php echo $paidCount; ?></h3>
                        <p>Paid</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon pending"><span>⏳</span></div>
                    <div class="stat-info">
                        <h3><?php echo $pendingCount; ?></h3>
                        <p>Pending Payment</p>
                    </div>
                </div>
                <div class="stat-card <?php echo ($pendingReviewCount > 0) ? 'clickable' : ''; ?>" 
                     <?php if($pendingReviewCount > 0) echo 'onclick="openPendingListModal()"'; ?>>
                    <div class="stat-icon completed"><span>⭐</span></div>
                    <div class="stat-info">
                        <h3><?php echo $pendingReviewCount; ?></h3>
                        <p>Pending Reviews</p>
                    </div>
                </div>
            </div>

            <!-- Payment History Table -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h2><span>📋</span> Appointment Payment History</h2>
                </div>
                <?php if (!empty($appointments)): ?>
                    <div class="payment-table-wrapper">
                        <table class="payment-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Doctor</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Review</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $apt): ?>
                                    <tr class="payment-row">
                                        <td>#<?php echo str_pad($apt['appointment_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <div class="doctor-cell">
                                                <div class="doctor-mini-avatar">
                                                    <?php 
                                                    $dbPath = $apt['doctor_profile_picture'] ?? '';
                                                    $physicalPath = __DIR__ . '/../' . ltrim($dbPath, '/');
                                                    if (!empty($dbPath) && file_exists($physicalPath)): ?>
                                                        <img src="../<?php echo htmlspecialchars($dbPath); ?>" alt="Avatar">
                                                    <?php else: ?>
                                                        <span><?php echo ($apt['doctor_gender'] ?? 'male') === 'female' ? '👩‍⚕️' : '👨‍⚕️'; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <strong>Dr. <?php echo htmlspecialchars($apt['doctor_fname'] . ' ' . $apt['doctor_lname']); ?></strong><br>
                                                    <span class="specialization-small"><?php echo htmlspecialchars($apt['specialization']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-cell">
                                                <strong><?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?></strong>
                                                <span class="time-small"><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($apt['appointment_status']); ?>">
                                                <?php echo ucfirst($apt['appointment_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($apt['payment_status'] === 'completed'): ?>
                                                <span class="status-badge status-paid">✓ Paid</span>
                                            <?php elseif ($apt['appointment_status'] === 'completed'): ?>
                                                <span class="status-badge status-pending">⏳ Pending</span>
                                            <?php else: ?>
                                                <span>-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($apt['review_id'])): ?>
                                                <div class="review-display">
                                                    <div class="review-stars">
                                                        <?php echo getStarDisplay($apt['rating']); ?>
                                                    </div>
                                                    <?php if (!empty($apt['review_text'])): ?>
                                                        <div class="review-text-snippet">"<?php echo htmlspecialchars($apt['review_text']); ?>"</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($apt['appointment_status'] === 'completed'): ?>
                                                <span class="text-muted-small">-</span>
                                            <?php else: ?>
                                                <span>-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons-group">
                                                <?php if ($apt['payment_status'] === 'completed'): ?>
                                                    <!-- Paid - Show Receipt and Review Options -->
                                                    <button class="btn-action btn-receipt" 
                                                            onclick="viewReceipt(<?php echo $apt['payment_id']; ?>)"
                                                            title="View Receipt">
                                                        📄 Receipt
                                                    </button>
                                                    
                                                    <?php if (!empty($apt['review_id'])): ?>
                                                        <button class="btn-action btn-edit" 
                                                                onclick="editReview(<?php echo $apt['appointment_id']; ?>, <?php echo $apt['doctor_id']; ?>, '<?php echo htmlspecialchars($apt['doctor_fname'].' '.$apt['doctor_lname'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($apt['specialization'], ENT_QUOTES); ?>', <?php echo $apt['rating']; ?>, '<?php echo htmlspecialchars($apt['review_text'] ?? '', ENT_QUOTES); ?>')"
                                                                title="Edit Review">
                                                            ✏️ Edit
                                                        </button>
                                                    <?php elseif ($apt['appointment_status'] === 'completed'): ?>
                                                        <button class="btn-action btn-rate" 
                                                                onclick="openReviewModal(<?php echo $apt['appointment_id']; ?>, <?php echo $apt['doctor_id']; ?>, '<?php echo htmlspecialchars($apt['doctor_fname'].' '.$apt['doctor_lname'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($apt['specialization'], ENT_QUOTES); ?>')"
                                                                title="Rate">
                                                            ⭐ Rate
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                <?php elseif ($apt['appointment_status'] === 'completed' && !$apt['payment_id']): ?>
                                                    <button class="btn-action btn-details" 
                                                            onclick="viewPaymentDetails(<?php echo $apt['appointment_id']; ?>, '<?php echo htmlspecialchars($apt['doctor_fname'].' '.$apt['doctor_lname'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($apt['specialization'], ENT_QUOTES); ?>', '<?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?>', '<?php echo date('h:i A', strtotime($apt['appointment_time'])); ?>', 50.00)"
                                                            title="View Bill Details">
                                                        🧾 View Details
                                                    </button>
                                                    <button class="btn-action btn-pay" 
                                                            onclick="showLocalhostPaymentMessage()"
                                                            title="Pay at Counter">
                                                        💳 Pay Now
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted-small">-</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>No appointments found</p>
                    </div>
                <?php endif; ?>

                <!-- Payment Modal -->
                <div id="paymentModal" class="modal">
                    <div class="modal-content payment-modal-content">
                        <div class="modal-header">
                            <h2>💳 Payment Options</h2>
                            <button class="modal-close" onclick="closePaymentModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="payment-modal-body">
                                <!-- Appointment Details -->
                                <div class="payment-info-section">
                                    <h3>📋 Appointment Details</h3>
                                    <div class="payment-detail-card">
                                        <div class="payment-detail-row">
                                            <span class="detail-label">Doctor:</span>
                                            <span class="detail-value" id="payment_doctor_name">-</span>
                                        </div>
                                        <div class="payment-detail-row">
                                            <span class="detail-label">Specialization:</span>
                                            <span class="detail-value" id="payment_specialization">-</span>
                                        </div>
                                        <div class="payment-detail-row">
                                            <span class="detail-label">Date:</span>
                                            <span class="detail-value" id="payment_date">-</span>
                                        </div>
                                        <div class="payment-detail-row">
                                            <span class="detail-label">Time:</span>
                                            <span class="detail-value" id="payment_time">-</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Amount -->
                                <div class="payment-amount-section">
                                    <div class="payment-amount-card">
                                        <span class="amount-label">Total Amount Due</span>
                                        <span class="amount-value">RM <span id="payment_amount">0.00</span></span>
                                    </div>
                                </div>

                                <!-- Payment Options -->
                                <div class="payment-options-section">
                                    <h3>Choose Payment Method</h3>
                                    
                                    <!-- Online Payment Option -->
                                    <div class="payment-option-card online-payment">
                                        <div class="payment-option-icon">💻</div>
                                        <div class="payment-option-content">
                                            <h4>Pay Online</h4>
                                            <p>Secure online payment with credit/debit card or e-wallet</p>
                                            <ul class="payment-benefits">
                                                <li>✓ Instant confirmation</li>
                                                <li>✓ Digital receipt</li>
                                                <li>✓ Multiple payment methods</li>
                                            </ul>
                                        </div>
                                        <button class="btn-payment-option btn-online" id="btn_pay_online">
                                            Pay Online Now
                                        </button>
                                    </div>

                                    <!-- Counter Payment Option -->
                                    <div class="payment-option-card counter-payment">
                                        <div class="payment-option-icon">🏥</div>
                                        <div class="payment-option-content">
                                            <h4>Pay at Counter</h4>
                                            <p>Visit our payment counter to complete your payment</p>
                                            <ul class="payment-benefits">
                                                <li>✓ Cash or card accepted</li>
                                                <li>✓ Printed receipt available</li>
                                                <li>✓ Staff assistance</li>
                                            </ul>
                                            <div class="counter-info">
                                                <strong>📍 Payment Counter Location:</strong>
                                                <p>Ground Floor, Main Building</p>
                                                <strong>🕐 Operating Hours:</strong>
                                                <p>Monday - Friday: 8:00 AM - 5:00 PM<br>
                                                Saturday: 8:00 AM - 1:00 PM<br>
                                                Sunday: Closed</p>
                                            </div>
                                        </div>
                                        <button class="btn-payment-option btn-counter" onclick="acknowledgeCounterPayment()">
                                            I'll Pay at Counter
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Pending Reviews List Modal -->
    <div id="pendingListModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>⭐ Pending Reviews</h2>
                <button class="modal-close" onclick="closePendingListModal()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if (empty($eligibleReviews)): ?>
                    <div class="empty-state">
                        <p>You have no pending reviews. Great job!</p>
                    </div>
                <?php else: ?>
                    <div class="review-list">
                        <?php foreach ($eligibleReviews as $review): ?>
                            <div class="review-list-item">
                                <div class="review-doctor-details">
                                    <div class="review-list-avatar">
                                        <?php if (!empty($review['doctor_profile_picture'])): ?>
                                            <img src="../<?php echo htmlspecialchars($review['doctor_profile_picture']); ?>" style="width:100%; height:100%; border-radius:50%;" alt="Dr">
                                        <?php else: ?>
                                            <span><?php echo ($review['doctor_gender'] ?? 'male') === 'female' ? '👩‍⚕️' : '👨‍⚕️'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong>Dr. <?php echo htmlspecialchars($review['doctor_fname'] . ' ' . $review['doctor_lname']); ?></strong>
                                        <div class="review-meta">
                                            <?php echo htmlspecialchars($review['specialization']); ?> • 
                                            <?php echo date('M d, Y', strtotime($review['appointment_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <button class="btn-review-small" 
                                        onclick="openReviewModal(<?php echo $review['appointment_id']; ?>, <?php echo $review['doctor_id']; ?>, '<?php echo htmlspecialchars($review['doctor_fname'] . ' ' . $review['doctor_lname']); ?>', '<?php echo htmlspecialchars($review['specialization']); ?>')">
                                    Rate Now
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePendingListModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Review Form Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>⭐ Rate Your Experience</h2>
                <button class="modal-close" onclick="closeReviewModal()">&times;</button>
            </div>
            <form method="POST" id="reviewForm" onsubmit="return validateReview()">
                <input type="hidden" name="appointment_id" id="review_appointment_id">
                <input type="hidden" name="doctor_id" id="review_doctor_id">
                <input type="hidden" name="rating" id="modal_rating" value="0">
                <input type="hidden" name="submit_review" value="1">
                <div class="modal-body">
                    <div class="review-doctor-info">
                        <h3>Rate Your Visit With</h3>
                        <div class="doctor-name-display">
                            <strong id="review_doctor_name"></strong>
                            <span id="review_specialization" class="specialization"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Your Rating *</label>
                        <div class="star-rating">
                            <span class="star-input" onclick="setRating(1)">★</span>
                            <span class="star-input" onclick="setRating(2)">★</span>
                            <span class="star-input" onclick="setRating(3)">★</span>
                            <span class="star-input" onclick="setRating(4)">★</span>
                            <span class="star-input" onclick="setRating(5)">★</span>
                        </div>
                        <span id="rating_error" class="error-message"></span>
                    </div>
                    <div class="form-group">
                        <label>Your Feedback (Optional)</label>
                        <textarea name="review_text" id="review_text" rows="4" maxlength="500" placeholder="Share your experience... (Optional)"></textarea>
                        <small class="char-counter"><span id="char_count">0</span>/500 characters</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeReviewModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><span>⭐</span> Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <script src="payment.js"></script>
</body>
</html>