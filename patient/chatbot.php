<?php
session_start();
require_once 'config.php';

// Get session information
$session_id = session_id();
$patient_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$is_logged_in = !empty($patient_id);

// Get user information if logged in
$user_name = 'Guest';
$user_email = '';

if ($is_logged_in) {
    try {
        $conn = Database::getInstance()->getConnection();
        
        $stmt = $conn->prepare("
            SELECT p.first_name, p.last_name, u.email
            FROM patients p 
            JOIN users u ON p.user_id = u.user_id
            WHERE p.user_id = ?
        ");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            $user_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
            $user_email = htmlspecialchars($user['email']);
        }
        
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching user info: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 CarePlus Assistant</title>
    <link rel="stylesheet" href="chatbot.css">
</head>
<body>

<div class="chat-container">
    <!-- Header -->
    <div class="chat-header">
        <div class="header-content">
            <div class="bot-avatar">🤖</div>
            <div class="header-text">
                <h2>CarePlus Assistant</h2>
                <?php if ($is_logged_in): ?>
                    <div class="user-greeting">
                        Welcome, <?= $user_name ?>!
                    </div>
                <?php else: ?>
                    <div class="status">
                        <span class="status-dot"></span>
                        <span>Online</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <button class="close-btn" onclick="closeChat()" title="Close chat">×</button>
    </div>
    
    <!-- Chatbox -->
    <div id="chatbox">
        <!-- Welcome Message -->
        <div class="message-wrapper bot welcome-message">
            <div class="message-avatar">🤖</div>
            <div class="message-content">
                <div class="message-bubble">
                    👋 <strong>Hi<?= $is_logged_in ? ' ' . explode(' ', $user_name)[0] : '' ?>! I'm your CarePlus assistant.</strong> How can I help you today?
                    <ul>
                        <li>📅 Book appointments</li>
                        <li>🏥 Clinic information</li>
                        <li>👨‍⚕️ Find doctors</li>
                        <li>💡 Health tips</li>
                    </ul>
                </div>
                <div class="message-time">Just now</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <button class="quick-action-btn" onclick="sendQuickMessage('How do I book an appointment?')">
                📅 Book Appointment
            </button>
            <button class="quick-action-btn" onclick="sendQuickMessage('What are your clinic hours?')">
                🕐 Clinic Hours
            </button>
            <button class="quick-action-btn" onclick="sendQuickMessage('How can I find a doctor?')">
                👨‍⚕️ Find Doctor
            </button>
            <button class="quick-action-btn" onclick="sendQuickMessage('How can I contact the clinic?')">
                📞 Contact
            </button>
        </div>
    </div>
    
    <!-- Input Area -->
    <div class="chat-input-wrapper">
        <div class="chat-input">
            <input 
                type="text" 
                id="userMessage" 
                placeholder="Ask me anything..."
                autocomplete="off"
                aria-label="Type your message"
            >
            <button class="send-btn" onclick="sendMessage()" id="sendBtn" aria-label="Send message">
                ➤
            </button>
        </div>
    </div>

    <!-- Footer -->
    <div class="chat-footer">
        <?php if (!$is_logged_in): ?>
            <small>
                <a href="login.php" style="color: #667eea; text-decoration: underline;">Login</a> 
                for personalized assistance • 
        <?php endif; ?>
        <span style="color: #ef4444;">⚠️ For emergencies, call 999</span>
        <?php if (!$is_logged_in): ?>
            </small>
        <?php endif; ?>
    </div>
</div>

<!-- Pass PHP variables to JavaScript -->
<script>
    const session_id_php = "<?= htmlspecialchars($session_id) ?>";
    const patient_id_php = <?= $patient_id ? $patient_id : 'null' ?>;
    const is_logged_in = <?= $is_logged_in ? 'true' : 'false' ?>;
    const userType = "<?= $_SESSION['user_type'] ?? 'guest' ?>";
</script>

<!-- Load JavaScript -->
<script src="chatbot.js"></script>

</body>
</html>