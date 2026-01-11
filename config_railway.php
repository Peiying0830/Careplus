<?php
/* Session Settings */
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 60 * 60 * 8, // 8 hours
        'cookie_httponly' => true,
        'use_strict_mode' => true,
        'cookie_samesite' => 'Lax'
    ]);
}

/* Timezones */
date_default_timezone_set('Asia/Kuala_Lumpur');

/* Site Config */
// Railway 会自动分配域名，也可以通过环境变量设置
define('SITE_URL', getenv('RAILWAY_PUBLIC_DOMAIN') ? 'https://' . getenv('RAILWAY_PUBLIC_DOMAIN') : 'http://localhost');
define('SITE_NAME', 'CarePlus - Smart Clinic Management Portal');

/* Database Config - 从 Railway 环境变量读取 */
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway'); 
define('DB_PORT', getenv('MYSQLPORT') ?: '3306');

/* File Upload Config */
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('QR_CODE_DIR', __DIR__ . '/qrcodes/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 MB

/* MySQL Database Connection */
class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($this->conn->connect_error) {
            error_log("DB Error: " . $this->conn->connect_error);
            die("❌ Database connection failed. Please check Railway Environment Variables.");
        }
        
        $this->conn->set_charset("utf8mb4");
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function query($sql, $params = []) {
        if (empty($params)) {
            return $this->conn->query($sql);
        }
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return false;
        }
        
        if (!empty($params)) {
            $types = $this->getParamTypes($params);
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt;
    }
    
    private function getParamTypes($params) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        return $types;
    }
}

/* Auth & Utilities (保持不变) */
function isLoggedIn() { return isset($_SESSION['user_id'], $_SESSION['user_type']); }
function getUserId() { return $_SESSION['user_id'] ?? null; }
function getUserType() { return $_SESSION['user_type'] ?? null; }
function requireLogin() { if (!isLoggedIn()) { header("Location: " . SITE_URL . "/login.php"); exit; } }
function requireRole($role) { requireLogin(); if (getUserType() !== $role) { header("Location: " . SITE_URL . "/unauthorized.php"); exit; } }
function loginUser($userId, $userType) { $_SESSION['user_id'] = $userId; $_SESSION['user_type'] = $userType; }
function logoutUser() { session_unset(); session_destroy(); header("Location: " . SITE_URL . "/login.php"); exit; }
function redirect($url) { header("Location: $url"); exit; }
function sanitizeInput($data) { return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8'); }
function generateToken($length = 32) { return bin2hex(random_bytes($length)); }
function hashPassword($password) { return password_hash($password, PASSWORD_DEFAULT); }
function verifyPassword($password, $hash) { return password_verify($password, $hash); }
function sendJsonResponse($data, $statusCode = 200) { http_response_code($statusCode); header("Content-Type: application/json"); echo json_encode($data); exit; }
function logError($message) { error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, __DIR__ . '/logs/error.log'); }
function formatDate($date, $format = 'd M Y') { return date($format, strtotime($date)); }
function formatDateTime($datetime, $format = 'd M Y H:i') { return date($format, strtotime($datetime)); }
function formatTime($time, $format = 'h:i A') { return date($format, strtotime($time)); }
function formatCurrency($amount) { return "RM " . number_format($amount, 2); }

/* Activity Loggers (保持不变) */
function logActivity($userId, $action, $description) {
    $conn = Database::getInstance()->getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        $stmt = $conn->prepare("INSERT INTO symptom_checker_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $action, $description, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) { error_log("Activity DB Log Error: " . $e->getMessage()); }
}

function logPrescriptionActivity($userId, $action, $description) {
    $conn = Database::getInstance()->getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        $stmt = $conn->prepare("INSERT INTO prescription_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $action, $description, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) { error_log("Prescription Activity DB Log Error: " . $e->getMessage()); }
}

function logChatbotActivity($userId, $action, $description, $severity = 'info') {
    $conn = Database::getInstance()->getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logFile = $logDir . '/chatbot.log';
    $severityTag = strtoupper($severity);
    $logEntry = date('Y-m-d H:i:s') . " [$severityTag] USER_ID: $userId | ACTION: $action | DESC: $description | IP: $ip" . PHP_EOL;
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/* Create Required Directories */
$directories = [__DIR__ . '/uploads', __DIR__ . '/qrcodes', __DIR__ . '/logs', __DIR__ . '/receipts'];
foreach ($directories as $dir) { if (!is_dir($dir)) mkdir($dir, 0755, true); }

/* Global MySQLi Connection */
$conn = Database::getInstance()->getConnection();

?>
