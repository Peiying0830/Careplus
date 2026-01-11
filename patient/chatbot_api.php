<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/chatbot_error.log');

// Start output buffering to catch any accidental output
ob_start();

/* Simple debug logging function */
function logDebug($message) {
    $logFile = __DIR__ . '/logs/chatbot_debug.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

logDebug("=== Chatbot API Called ===");

try {
    // Read raw POST input
    $rawInput = file_get_contents('php://input');
    logDebug("Raw input length: " . strlen($rawInput));
    
    if (empty($rawInput)) {
        throw new Exception("Empty request body");
    }
    
    // Decode JSON
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== 0) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    
    // Extract parameters
    $message = isset($data['message']) ? trim($data['message']) : '';
    $session_id = isset($data['session_id']) ? $data['session_id'] : session_id();
    $patient_id = isset($data['patient_id']) ? $data['patient_id'] : null;
    
    logDebug("Session: $session_id, Patient: " . ($patient_id ? $patient_id : 'guest'));
    logDebug("Message: " . substr($message, 0, 50));
    
    if (empty($message)) {
        throw new Exception("Message is required");
    }
    
    // Check if required files exist - try multiple locations for config.php
    $configLocations = [
        __DIR__ . '/config.php',           // Same directory
        __DIR__ . '/../config.php',        // Parent directory (most likely)
        __DIR__ . '/../../config.php',     // Two levels up
        __DIR__ . '/../includes/config.php' // Common includes folder
    ];
    
    $configPath = null;
    foreach ($configLocations as $location) {
        if (file_exists($location)) {
            $configPath = $location;
            logDebug("Found config.php at: $location");
            break;
        }
    }
    
    if (!$configPath) {
        throw new Exception("Config file not found. Checked locations: " . implode(', ', $configLocations));
    }
    
    if (!file_exists('chatbot_controller.php')) {
        throw new Exception("Controller file not found");
    }
    
    // Load dependencies
    require_once 'chatbot_controller.php';
    require_once $configPath;
    
    // Get database connection
    $db = Database::getInstance()->getConnection();
    logDebug("Database connected");
    
    // Initialize controller
    $controller = new ChatbotScopeController($db);
    
    // Process message 
    $result = $controller->processMessage($message, $session_id, $patient_id);
    
    logDebug("Controller returned - Log ID: " . ($result['log_id'] ?? 'NULL') . 
             ", Scope ID: " . ($result['scope_id'] ?? 'NULL'));
    
    // Clear any output buffer and send clean JSON
    ob_end_clean();
    
    // Prepare response with only fields that exist in the database
    $response = array(
        'reply' => isset($result['reply']) ? $result['reply'] : 'Sorry, I could not process your request.',
        'is_restricted' => isset($result['is_restricted']) ? $result['is_restricted'] : false,
        'log_id' => isset($result['log_id']) ? $result['log_id'] : null,
        'scope_id' => isset($result['scope_id']) ? $result['scope_id'] : null
    );
    
    logDebug("✅ Sending response with log_id: " . $response['log_id']);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
    logDebug("Response sent successfully");
    
} catch (Exception $e) {
    // Clear buffer on error
    ob_end_clean();
    
    logDebug("ERROR: " . $e->getMessage());
    error_log("Chatbot API Error: " . $e->getMessage());
    
    // Send error response
    $errorResponse = array(
        'error' => true,
        'reply' => 'Sorry, something went wrong. Please try again.',
        'debug_message' => $e->getMessage()
    );
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}

exit;
?>