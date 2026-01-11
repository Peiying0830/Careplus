<?php
/*Log chatbot activities (conversations, escalations, restrictions) */
function logChatbotActivity($userId, $action, $description, $severity = 'info') {
    $conn = Database::getInstance()->getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Store in Database
    try {
        $stmt = $conn->prepare("
            INSERT INTO chatbot_logs (patient_id, session_id, user_message, bot_response, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        // This is just for compatibility - actual logging happens in chatbot_api.php
        $stmt->close();
    } catch (Exception $e) {
        error_log("Chatbot Activity DB Log Error: " . $e->getMessage());
    }

    // Store in physical .log file
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/chatbot.log';
    
    // Ensure log file exists
    if (!file_exists($logFile)) {
        touch($logFile);
    }
    
    $severityTag = strtoupper($severity);
    $logEntry = date('Y-m-d H:i:s') . " [$severityTag] USER_ID: $userId | ACTION: $action | DESC: $description | IP: $ip" . PHP_EOL;
    $result = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    if ($result === false) {
        $error = error_get_last();
        error_log("Chatbot log write FAILED: $logFile - " . ($error['message'] ?? 'Unknown error'));
    }
}

/* Log chatbot escalations */
function logChatbotEscalation($escalationId, $patientId, $reason, $priority = 'medium') {
    $description = "Escalation #$escalationId created - Priority: $priority - Reason: $reason";
    logChatbotActivity($patientId, 'ESCALATION_CREATED', $description, 'warning');
}

/* Log restricted topic attempts */
function logRestrictedAttempt($userId, $topic, $severity = 'medium') {
    $description = "User attempted restricted topic: $topic";
    logChatbotActivity($userId, 'RESTRICTED_ATTEMPT', $description, $severity);
}

/*Get chatbot statistics */
function getChatbotStats($period = 'today') {
    $conn = Database::getInstance()->getConnection();
    
    switch ($period) {
        case 'today':
            $dateFilter = "DATE(created_at) = CURDATE()";
            break;
        case 'week':
            $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        default:
            $dateFilter = "1=1";
    }
    
    try {
        // Total conversations
        $result = $conn->query("
            SELECT COUNT(*) as total 
            FROM chatbot_logs 
            WHERE $dateFilter
        ");
        $row = $result->fetch_assoc();
        $total = $row['total'] ?? 0;
        
        // Restricted attempts
        $result = $conn->query("
            SELECT COUNT(*) as restricted 
            FROM chatbot_logs 
            WHERE is_restricted = 1 AND $dateFilter
        ");
        $row = $result->fetch_assoc();
        $restricted = $row['restricted'] ?? 0;
        
        // Pending escalations
        $result = $conn->query("
            SELECT COUNT(*) as escalations 
            FROM chatbot_escalations 
            WHERE status = 'pending'
        ");
        $row = $result->fetch_assoc();
        $escalations = $row['escalations'] ?? 0;
        
        return [
            'total_conversations' => $total,
            'restricted_attempts' => $restricted,
            'pending_escalations' => $escalations,
            'period' => $period
        ];
    } catch (Exception $e) {
        error_log("Error getting chatbot stats: " . $e->getMessage());
        return [
            'total_conversations' => 0,
            'restricted_attempts' => 0,
            'pending_escalations' => 0,
            'period' => $period
        ];
    }
}

/* Clean old chatbot logs */
function cleanOldChatbotLogs($daysToKeep = 90) {
    $conn = Database::getInstance()->getConnection();
    
    try {
        $stmt = $conn->prepare("
            DELETE FROM chatbot_logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("i", $daysToKeep);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        
        error_log("Cleaned $deleted old chatbot logs (older than $daysToKeep days)");
        return $deleted;
    } catch (Exception $e) {
        error_log("Error cleaning chatbot logs: " . $e->getMessage());
        return 0;
    }
}
?>