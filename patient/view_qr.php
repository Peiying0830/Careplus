<?php
// patient/view_qr.php - View and Download QR Code (Converted to MySQLi)
require_once __DIR__ . '/../config.php';
requireRole('patient');

// Get the MySQLi connection from your singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Get patient ID
$stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId); // "i" for integer
$stmt->execute();
$patientResult = $stmt->get_result();
$patient = $patientResult->fetch_assoc();

if (!$patient) {
    redirect('patient/dashboard.php');
}

$patientId = $patient['patient_id'];

// Get appointment ID from URL
$appointmentId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$appointmentId) {
    redirect('patient/appointment.php');
}

// Fetch appointment details with JOINs
$appointmentStmt = $conn->prepare("
    SELECT a.*, 
           d.first_name as doctor_fname, 
           d.last_name as doctor_lname,
           d.specialization,
           p.first_name as patient_fname,
           p.last_name as patient_lname
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.appointment_id = ? AND a.patient_id = ?
    LIMIT 1
");

$appointmentStmt->bind_param("ii", $appointmentId, $patientId);
$appointmentStmt->execute();
$appointmentResult = $appointmentStmt->get_result();
$appointment = $appointmentResult->fetch_assoc();

if (!$appointment) {
    redirect('patient/appointment.php');
}

// Logic: Check if QR code exists
if (empty($appointment['qr_code'])) {
    $message = "QR code not available for this appointment.";
}

// Generate QR code URLs (API handles the rendering)
$qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($appointment['qr_code'] ?? '') . "&size=500";
$qrCodeUrlDisplay = "https://quickchart.io/qr?text=" . urlencode($appointment['qr_code'] ?? '') . "&size=300";

// Expiry Logic (Remains standard PHP)
$appointmentDate = strtotime($appointment['appointment_date']);
$currentDate = time();
$expiryDate = $appointmentDate + (24 * 60 * 60); // 1 day after appointment
$isExpired = $currentDate > $expiryDate;

// Close statements
$stmt->close();
$appointmentStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment QR Code - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .qr-viewer-container {
            max-width: 650px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            padding: 3rem;
            text-align: center;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .qr-header {
            margin-bottom: 2rem;
        }
        
        .qr-header h1 {
            font-size: 2.2rem;
            color: #00695c;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .qr-header p {
            color: #666;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .appointment-details {
            background: linear-gradient(135deg, #d4f1e8 0%, #e8f5f1 100%);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            text-align: left;
            border: 2px solid #26a69a;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 700;
            color: #00695c;
        }
        
        .detail-value {
            color: #424242;
            font-weight: 500;
        }
        
        .qr-code-display {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            border: 3px dashed #26a69a;
            margin: 2rem 0;
            position: relative;
        }

        .qr-code-display.expired {
            border-color: #f44336;
            background: #ffebee;
        }

        .expired-badge {
            position: absolute;
            top: -15px;
            right: 20px;
            background: #f44336;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .valid-badge {
            position: absolute;
            top: -15px;
            right: 20px;
            background: #4caf50;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .qr-code-display img {
            max-width: 100%;
            height: auto;
            border: 3px solid #e0e0e0;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            transition: transform 0.3s ease;
        }

        .qr-code-display img:hover {
            transform: scale(1.05);
        }
        
        .qr-code-text {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #f5f5f5;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            color: #00695c;
            font-weight: 700;
            letter-spacing: 2px;
            word-break: break-all;
            border: 2px solid #e0e0e0;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .btn {
            flex: 1;
            min-width: 150px;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #26a69a, #00897b);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #00897b, #00695c);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(38, 166, 154, 0.3);
        }
        
        .btn-outline {
            background: white;
            color: #00695c;
            border: 2px solid #00695c;
        }
        
        .btn-outline:hover {
            background: #00695c;
            color: white;
        }
        
        .instruction {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
            text-align: left;
        }
        
        .instruction h3 {
            color: #e65100;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .instruction ol {
            margin-left: 1.5rem;
            color: #666;
            line-height: 1.8;
        }

        .instruction ol li {
            margin-bottom: 0.5rem;
        }

        .alert-warning {
            background: #fff3e0;
            border: 2px solid #ff9800;
            color: #e65100;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        
        @media print {
            body {
                background: white;
            }
            
            .action-buttons,
            .instruction {
                display: none;
            }
            
            .qr-viewer-container {
                box-shadow: none;
                max-width: 100%;
            }

            .qr-code-display {
                border: 2px solid #000;
            }
        }
        
        @media (max-width: 768px) {
            .qr-viewer-container {
                padding: 2rem 1.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }

            .qr-header h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="qr-viewer-container">
        <div class="qr-header">
            <h1>🎫 Appointment QR Code</h1>
            <p>Appointment ID: #<?php echo $appointment['appointment_id']; ?></p>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert-warning">
                ⚠️ <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($isExpired): ?>
            <div class="alert-warning">
                ⚠️ This QR code has expired (appointment date has passed)
            </div>
        <?php endif; ?>
        
        <div class="appointment-details">
            <div class="detail-row">
                <span class="detail-label">📋 Patient:</span>
                <span class="detail-value"><?php echo htmlspecialchars($appointment['patient_fname'] . ' ' . $appointment['patient_lname']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">👨‍⚕️ Doctor:</span>
                <span class="detail-value">Dr. <?php echo htmlspecialchars($appointment['doctor_fname'] . ' ' . $appointment['doctor_lname']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">🏥 Specialization:</span>
                <span class="detail-value"><?php echo htmlspecialchars($appointment['specialization']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">📅 Date:</span>
                <span class="detail-value"><?php echo date('l, F d, Y', strtotime($appointment['appointment_date'])); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">🕒 Time:</span>
                <span class="detail-value"><?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">📊 Status:</span>
                <span class="detail-value" style="text-transform: uppercase; color: <?php echo $appointment['status'] === 'confirmed' ? '#4caf50' : '#ff9800'; ?>; font-weight: 700;">
                    <?php echo htmlspecialchars($appointment['status']); ?>
                </span>
            </div>
        </div>
        
        <?php if (!empty($appointment['qr_code'])): ?>
            <div class="qr-code-display <?php echo $isExpired ? 'expired' : ''; ?>">
                <?php if ($isExpired): ?>
                    <span class="expired-badge">⚠️ EXPIRED</span>
                <?php else: ?>
                    <span class="valid-badge">✓ VALID</span>
                <?php endif; ?>
                
                <img src="<?php echo htmlspecialchars($qrCodeUrlDisplay); ?>" 
                     alt="Appointment QR Code" 
                     id="qrCodeImage"
                     loading="lazy">
                
                <div class="qr-code-text"><?php echo htmlspecialchars($appointment['qr_code']); ?></div>
            </div>
            
            <div class="action-buttons">
                <a href="<?php echo htmlspecialchars($qrCodeUrl); ?>" 
                   download="appointment-<?php echo $appointment['appointment_id']; ?>-qr.png" 
                   class="btn btn-primary">
                    <span>⬇️</span> Download QR Code
                </a>
                <button onclick="window.print()" class="btn btn-outline">
                    <span>🖨️</span> Print
                </button>
                <a href="appointment.php" class="btn btn-outline">
                    <span>←</span> Back to Appointments
                </a>
            </div>
            
            <div class="instruction">
                <h3>📱 How to Use This QR Code</h3>
                <ol>
                    <li><strong>Save It:</strong> Download or screenshot this QR code for offline access</li>
                    <li><strong>Bring It:</strong> Show it at the clinic reception on your appointment day</li>
                    <li><strong>Scan It:</strong> The clinic staff will scan it to verify and check you in</li>
                    <li><strong>Keep It:</strong> Retain this QR code until your appointment is completed</li>
                </ol>
            </div>
        <?php else: ?>
            <div class="alert-warning">
                ⚠️ QR code is not available for this appointment. Please contact the clinic.
            </div>
            <div class="action-buttons">
                <a href="appointment.php" class="btn btn-primary">
                    <span>←</span> Back to Appointments
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-hide expired warning after 10 seconds
        setTimeout(() => {
            const warnings = document.querySelectorAll('.alert-warning');
            warnings.forEach(warning => {
                if (warning.textContent.includes('expired')) {
                    warning.style.transition = 'opacity 0.5s ease';
                    warning.style.opacity = '0';
                    setTimeout(() => warning.remove(), 500);
                }
            });
        }, 10000);

        // Prevent right-click on QR code image (optional security)
        document.getElementById('qrCodeImage')?.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            alert('Use the Download button to save the QR code');
        });
    </script>
</body>
</html>