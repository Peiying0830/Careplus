<?php
session_start();
require_once __DIR__ . '/../config.php';

// Check if user is admin or doctor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'doctor'])) {
    header("Location: ../login.php");
    exit();
}

/** @var mysqli $conn */
$conn = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

// Handle QR code scanning via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_code'])) {
    header('Content-Type: application/json');
    
    $qr_code = trim($_POST['qr_code']);
    $response = ['success' => false, 'message' => '', 'data' => null];
    
    if (empty($qr_code)) {
        $response['message'] = 'QR code is required';
        echo json_encode($response);
        exit();
    }
    
    // Find appointment by QR code
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            p.first_name as patient_fname,
            p.last_name as patient_lname,
            p.ic_number as patient_ic,
            p.phone as patient_phone,
            p.date_of_birth,
            p.blood_type,
            d.first_name as doctor_fname,
            d.last_name as doctor_lname,
            d.specialization
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.qr_code = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        $response['message'] = 'Database error: ' . $conn->error;
        echo json_encode($response);
        exit();
    }
    
    $stmt->bind_param("s", $qr_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    
    if (!$appointment) {
        $response['message'] = '❌ Invalid QR Code - Appointment not found';
        
        // Log invalid scan (MySQLi)
        $log_stmt = $conn->prepare("
            INSERT INTO qr_scan_logs (appointment_id, qr_code, scanned_by, scan_result, notes)
            VALUES (0, ?, ?, 'invalid', 'QR code not found in system')
        ");
        if ($log_stmt) {
            $log_stmt->bind_param("si", $qr_code, $user_id);
            $log_stmt->execute();
            $log_stmt->close();
        }
        
        echo json_encode($response);
        exit();
    }
    
    $appointment_id = $appointment['appointment_id'];
    $appointment_date = $appointment['appointment_date'];
    $status = $appointment['status'];
    $today = date('Y-m-d');
    
    // Validate Appointment Status
    if ($status === 'cancelled') {
        $response['message'] = '❌ This appointment has been cancelled';
        $response['data'] = [
            'Patient Name' => $appointment['patient_fname'] . ' ' . $appointment['patient_lname'],
            'Status' => '🚫 CANCELLED',
            'Original Date' => date('F j, Y', strtotime($appointment_date))
        ];
        
        $log_stmt = $conn->prepare("INSERT INTO qr_scan_logs (appointment_id, qr_code, scanned_by, scan_result, notes) VALUES (?, ?, ?, 'invalid', 'Cancelled')");
        $log_stmt->bind_param("isi", $appointment_id, $qr_code, $user_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode($response);
        exit();
    }
    
    if ($status === 'completed') {
        $response['message'] = '⚠️ This appointment has already been completed';
        echo json_encode($response);
        exit();
    }
    
    // Date Validation
    if ($appointment_date !== $today) {
        $response['message'] = '⚠️ Appointment is scheduled for ' . date('F j, Y', strtotime($appointment_date));
        echo json_encode($response);
        exit();
    }
    
    // Duplicate Check
    if ($appointment['checked_in_at']) {
        $response['message'] = '⚠️ Patient already checked in at ' . date('g:i A', strtotime($appointment['checked_in_at']));
        echo json_encode($response);
        exit();
    }
    
    // ✅ Check-in successful - Update using MySQLi
    $update_stmt = $conn->prepare("
        UPDATE appointments 
        SET checked_in_at = NOW(),
            checked_in_by = ?,
            status = 'confirmed'
        WHERE appointment_id = ?
    ");
    
    $update_stmt->bind_param("ii", $user_id, $appointment_id);
    
    if ($update_stmt->execute()) {
        // Log successful check-in
        $log_stmt = $conn->prepare("INSERT INTO qr_scan_logs (appointment_id, qr_code, scanned_by, scan_result, notes) VALUES (?, ?, ?, 'success', 'Patient checked in')");
        $log_stmt->bind_param("isi", $appointment_id, $qr_code, $user_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        // Calculate Age
        $age = 'N/A';
        if ($appointment['date_of_birth']) {
            $age = (new DateTime($appointment['date_of_birth']))->diff(new DateTime())->y . ' years old';
        }
        
        $response['success'] = true;
        $response['message'] = '✅ Check-in Successful!';
        $response['data'] = [
            'Patient Name' => '👤 ' . $appointment['patient_fname'] . ' ' . $appointment['patient_lname'],
            'IC Number' => '🪪 ' . ($appointment['patient_ic'] ?? 'N/A'),
            'Age' => '🎂 ' . $age,
            'Doctor' => '👨‍⚕️ Dr. ' . $appointment['doctor_fname'] . ' ' . $appointment['doctor_lname'],
            'Appointment Time' => '🕒 ' . date('g:i A', strtotime($appointment['appointment_time'])),
            'Checked In At' => '✅ ' . date('g:i A')
        ];
    } else {
        $response['message'] = 'Failed to update check-in status: ' . $update_stmt->error;
    }
    
    $stmt->close();
    $update_stmt->close();
    
    echo json_encode($response);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Scanner - Patient Check-in</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .scanner-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 3rem;
            max-width: 900px;
            margin: 0 auto;
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
        
        .scanner-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .scanner-header h1 {
            font-size: 2.5rem;
            color: #667eea;
            margin-bottom: 0.5rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }
        
        .scanner-header p {
            color: #666;
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        .scan-mode-toggle {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            background: #f5f5f5;
            padding: 0.5rem;
            border-radius: 12px;
        }
        
        .mode-btn {
            flex: 1;
            padding: 1rem;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            color: #666;
        }
        
        .mode-btn.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .scan-section {
            display: none;
        }
        
        .scan-section.active {
            display: block;
        }
        
        /* Camera Scanner */
        #qr-reader {
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        #qr-reader video {
            border-radius: 12px;
        }
        
        /* Manual Input */
        .scan-input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .scan-input-group i {
            position: absolute;
            left: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.5rem;
        }
        
        #qrCodeInput {
            width: 100%;
            padding: 1.2rem 1.5rem 1.2rem 4rem;
            border: 3px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1.1rem;
            transition: all 0.3s;
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        
        #qrCodeInput:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        
        .scan-btn {
            width: 100%;
            padding: 1.2rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }
        
        .scan-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        
        .scan-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Result Container */
        .result-container {
            margin-top: 2rem;
            padding: 2rem;
            border-radius: 16px;
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .result-container.success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 3px solid #28a745;
        }
        
        .result-container.error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border: 3px solid #dc3545;
        }
        
        .result-container.warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 3px solid #ffc107;
        }
        
        .result-header {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .result-header.success { color: #155724; }
        .result-header.error { color: #721c24; }
        .result-header.warning { color: #856404; }
        
        .result-details {
            display: grid;
            gap: 1rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            gap: 1rem;
        }
        
        .detail-label {
            font-weight: 700;
            color: #424242;
            min-width: 180px;
        }
        
        .detail-value {
            color: #666;
            text-align: right;
            flex: 1;
            font-weight: 600;
        }
        
        .close-result-btn {
            margin-top: 1.5rem;
            width: 100%;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .close-result-btn:hover {
            background: rgba(0, 0, 0, 0.2);
        }
        
        .back-btn {
            margin-top: 2rem;
            text-align: center;
        }
        
        .back-btn a {
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
        }
        
        .back-btn a:hover {
            background: rgba(102, 126, 234, 0.1);
            gap: 0.75rem;
        }
        
        .scan-history {
            margin-top: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .scan-history h3 {
            color: #424242;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }
        
        .scan-count {
            color: #667eea;
            font-size: 2rem;
            font-weight: 800;
        }
        
        @media (max-width: 768px) {
            .scanner-container {
                padding: 2rem 1.5rem;
            }
            
            .scanner-header h1 {
                font-size: 2rem;
            }
            
            .detail-row {
                flex-direction: column;
            }
            
            .detail-label {
                min-width: auto;
            }
            
            .detail-value {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="scanner-container">
        <div class="scanner-header">
            <h1><i class="fas fa-qrcode"></i> QR Code Scanner</h1>
            <p>Scan patient appointment QR code for check-in</p>
        </div>
        
        <!-- Mode Toggle -->
        <div class="scan-mode-toggle">
            <button class="mode-btn active" onclick="switchMode('camera')">
                <i class="fas fa-camera"></i> Camera Scanner
            </button>
            <button class="mode-btn" onclick="switchMode('manual')">
                <i class="fas fa-keyboard"></i> Manual Input
            </button>
        </div>
        
        <!-- Camera Scanner Section -->
        <div id="cameraSection" class="scan-section active">
            <div id="qr-reader" style="width: 100%;"></div>
            <p style="text-align: center; color: #666; margin-top: 1rem;">
                <i class="fas fa-info-circle"></i> Position the QR code within the camera frame
            </p>
        </div>
        
        <!-- Manual Input Section -->
        <div id="manualSection" class="scan-section">
            <form id="scanForm">
                <div class="scan-input-group">
                    <i class="fas fa-qrcode"></i>
                    <input type="text" 
                           id="qrCodeInput" 
                           name="qr_code" 
                           placeholder="APT-XXXXXXXXXXXX" 
                           autocomplete="off"
                           maxlength="16">
                </div>
                
                <button type="submit" class="scan-btn">
                    <i class="fas fa-search"></i>
                    <span>Scan & Check In</span>
                </button>
            </form>
        </div>
        
        <!-- Result Container -->
        <div id="resultContainer" class="result-container"></div>
        
        <!-- Scan History -->
        <div class="scan-history">
            <h3><i class="fas fa-history"></i> Today's Check-ins</h3>
            <div class="scan-count" id="scanCount">0</div>
        </div>
        
        <div class="back-btn">
            <a href="dashboard.php">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <script>
        let html5QrCode = null;
        let scanCount = 0;
        
        // Initialize camera scanner
        function initCameraScanner() {
            if (html5QrCode) return;
            
            html5QrCode = new Html5Qrcode("qr-reader");
            
            Html5Qrcode.getCameras().then(cameras => {
                if (cameras && cameras.length) {
                    const cameraId = cameras[cameras.length - 1].id; // Use back camera
                    
                    html5QrCode.start(
                        cameraId,
                        {
                            fps: 10,
                            qrbox: { width: 250, height: 250 }
                        },
                        (decodedText, decodedResult) => {
                            console.log('QR Code detected:', decodedText);
                            processQRCode(decodedText);
                            
                            // Stop scanning temporarily
                            html5QrCode.pause();
                            setTimeout(() => {
                                if (html5QrCode.isScanning) {
                                    html5QrCode.resume();
                                }
                            }, 3000);
                        },
                        (errorMessage) => {
                            // Scanning error (ignore)
                        }
                    ).catch(err => {
                        console.error('Unable to start camera:', err);
                        alert('Unable to access camera. Please use manual input instead.');
                    });
                }
            }).catch(err => {
                console.error('Error getting cameras:', err);
            });
        }
        
        // Switch between camera and manual mode
        function switchMode(mode) {
            const cameraSec = document.getElementById('cameraSection');
            const manualSec = document.getElementById('manualSection');
            const modeBtns = document.querySelectorAll('.mode-btn');
            
            modeBtns.forEach(btn => btn.classList.remove('active'));
            
            if (mode === 'camera') {
                cameraSec.classList.add('active');
                manualSec.classList.remove('active');
                modeBtns[0].classList.add('active');
                initCameraScanner();
            } else {
                cameraSec.classList.remove('active');
                manualSec.classList.add('active');
                modeBtns[1].classList.add('active');
                
                // Stop camera
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop();
                }
                
                document.getElementById('qrCodeInput').focus();
            }
        }
        
        // Manual form submission
        const scanForm = document.getElementById('scanForm');
        scanForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const qrCode = document.getElementById('qrCodeInput').value.trim().toUpperCase();
            
            if (!qrCode) {
                showResult('error', 'Please enter a QR code', {});
                return;
            }
            
            await processQRCode(qrCode);
            document.getElementById('qrCodeInput').value = '';
        });
        
        // Process QR code
        async function processQRCode(qrCode) {
            const submitBtn = scanForm.querySelector('.scan-btn');
            const originalHTML = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            try {
                const formData = new FormData();
                formData.append('qr_code', qrCode);
                
                const response = await fetch('scan_qr.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showResult('success', data.message, data.data);
                    scanCount++;
                    document.getElementById('scanCount').textContent = scanCount;
                    
                    // Play success sound (optional)
                    playSound('success');
                } else {
                    showResult(data.message.includes('⚠️') ? 'warning' : 'error', data.message, data.data || {});
                    playSound('error');
                }
            } catch (error) {
                console.error('Error:', error);
                showResult('error', 'Failed to process QR code. Please try again.', {});
                playSound('error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHTML;
            }
        }
        
        // Show result
        function showResult(type, message, data) {
            const resultContainer = document.getElementById('resultContainer');
            resultContainer.className = 'result-container ' + type;
            resultContainer.style.display = 'block';
            
            let html = `<div class="result-header ${type}">${message}</div>`;
            
            if (data && Object.keys(data).length > 0) {
                html += '<div class="result-details">';
                
                for (const [key, value] of Object.entries(data)) {
                    html += `
                        <div class="detail-row">
                            <span class="detail-label">${key}:</span>
                            <span class="detail-value">${value}</span>
                        </div>
                    `;
                }
                
                html += '</div>';
            }
            
            html += `
                <button class="close-result-btn" onclick="closeResult()">
                    <i class="fas fa-times"></i> Close
                </button>
            `;
            
            resultContainer.innerHTML = html;
            resultContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        // Close result
        function closeResult() {
            const resultContainer = document.getElementById('resultContainer');
            resultContainer.style.display = 'none';
            
            // Resume camera if in camera mode
            const cameraSection = document.getElementById('cameraSection');
            if (cameraSection.classList.contains('active') && html5QrCode) {
                if (html5QrCode.getState() === Html5QrcodeScannerState.PAUSED) {
                    html5QrCode.resume();
                }
            }
        }
        
        // Play sound
        function playSound(type) {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            if (type === 'success') {
                oscillator.frequency.value = 800;
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            } else {
                oscillator.frequency.value = 400;
                gainNode.gain.setValueAtTime(0.2, audioContext.currentTime);
            }
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        }
        
        // Initialize on page load
        window.addEventListener('load', () => {
            initCameraScanner();
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop();
            }
        });
    </script>
</body>
</html>