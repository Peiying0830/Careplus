<?php 
$currentPath = $_SERVER['PHP_SELF'];
$baseUrl = defined('SITE_URL') ? SITE_URL : 'http://localhost/CarePlus';

// Get patient name and profile picture from database
$patientName = 'Guest User';
$patientInitials = '👤';
$profilePicture = null;

if (isset($_SESSION['user_id'])) {
    try {
        // Get the MySQLi connection from your Database singleton
        $conn = Database::getInstance()->getConnection();
        
        // Prepare the statement
        $stmt = $conn->prepare("SELECT first_name, last_name, profile_picture FROM patients WHERE user_id = ?");
        
        // Bind the parameter (i = integer)
        $stmt->bind_param("i", $_SESSION['user_id']);
        
        // Execute the statement
        $stmt->execute();
        
        // Get the result set
        $result = $stmt->get_result();
        
        // Fetch as associative array
        $patient = $result->fetch_assoc();
        
        if ($patient && !empty($patient['first_name'])) {
            $patientName = trim($patient['first_name'] . ' ' . $patient['last_name']);
            $profilePicture = $patient['profile_picture'] ?? null;
            
            // Generate initials
            $nameParts = explode(' ', $patientName);
            $patientInitials = strtoupper(substr($nameParts[0], 0, 1));
            if (isset($nameParts[1]) && !empty($nameParts[1])) {
                $patientInitials .= strtoupper(substr($nameParts[1], 0, 1));
            }
        }
        
        // Close statement
        $stmt->close();
        
    } catch (Exception $e) {
        error_log('Error fetching patient data: ' . $e->getMessage());
    }
}

$themeColors = [
    'patient' => ['primary' => '#26a69a', 'secondary' => '#00897b', 'light' => '#d4f1e8'],
];

$colors = $themeColors['patient'];
$logoutPath = '../logout.php';
?>

<!-- Load External CSS & JS -->
<link rel="stylesheet" href="headerNav.css?v=1.4">
<script src="headerNav.js" defer></script>

<style>
:root {
    --nav-primary: <?= $colors['primary']; ?>;
    --nav-secondary: <?= $colors['secondary']; ?>;
    --nav-light: <?= $colors['light']; ?>;
}
</style>

<header class="header">
    <nav class="navbar">
        <a class="logo">
            <img src="logo.png" class="logo-img" alt="Logo">
            <span class="logo-text">CarePlus - Smart Clinic Management Portal</span>
        </a>

        <ul class="nav-menu" id="mobileMenu">
            <div class="user-badge">
                <div class="user-avatar">
                    <?php if (!empty($profilePicture) && file_exists(__DIR__ . '/../' . $profilePicture)): ?>
                        <img src="../<?= htmlspecialchars($profilePicture); ?>" 
                             alt="Profile Picture" 
                             class="user-avatar-img">
                    <?php else: ?>
                        <div class="user-icon"><?= $patientInitials; ?></div>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($patientName); ?></div>
                    <div class="user-role">Patient Portal</div>
                    <div class="user-status">
                        <span class="status-indicator"></span>
                        <span>Online</span>
                    </div>
                </div>
            </div>

            <!-- Google Translate - Sidebar only -->
            <div class="translate-wrapper-sidebar">
                <div class="translate-label">🌐 Language</div>
                <div id="google_translate_element"></div>
            </div>

            <li><a href="dashboard.php" class="nav-link <?= basename($currentPath)=='dashboard.php' ? 'active':''; ?>">
                <span class="nav-icon">🏡</span>
                <span class="nav-text">Dashboard</span>
            </a></li>
            
            <li><a href="appointment.php" class="nav-link <?= basename($currentPath)=='appointment.php' ? 'active':''; ?>">
                <span class="nav-icon">📅</span>
                <span class="nav-text">Appointments</span>
            </a></li>
            
            <li><a href="doctors.php" class="nav-link <?= basename($currentPath)=='doctors.php' ? 'active':''; ?>">
                <span class="nav-icon">👨‍⚕️</span> 
                <span class="nav-text">Doctors</span>
            </a></li>

            <li><a href="medicalRecords.php" class="nav-link <?= basename($currentPath)=='medicalRecords.php' ? 'active':''; ?>">
                <span class="nav-icon">📋</span>
                <span class="nav-text">Medical Records</span>
            </a></li>
            
            <li><a href="symptomChecker.php" class="nav-link <?= basename($currentPath)=='symptomChecker.php' ? 'active':''; ?>">
                <span class="nav-icon">🔍</span>
                <span class="nav-text">Symptom Checker</span>
            </a></li>
            
            <li><a href="prescription.php" class="nav-link <?= basename($currentPath)=='prescription.php' ? 'active':''; ?>">
                <span class="nav-icon">💊</span> 
                <span class="nav-text">Prescriptions</span>
            </a></li>

            <li><a href="payment.php" class="nav-link <?= basename($currentPath)=='payment.php' ? 'active':''; ?>">
                <span class="nav-icon">💳</span> 
                <span class="nav-text">Payments</span>
            </a></li>

            <li><a href="notification.php" class="nav-link <?= basename($currentPath)=='notification.php' ? 'active':''; ?>">
                <span class="nav-icon">🔔</span> 
                <span class="nav-text">Notification</span>
            </a></li>

            <li><a href="profile.php" class="nav-link <?= basename($currentPath)=='profile.php' ? 'active':''; ?>">
                <span class="nav-icon">👤</span>
                <span class="nav-text">Profile</span>
            </a></li>

            <li class="nav-divider"></li>

            <li><a href="<?= $logoutPath; ?>" class="nav-link logout-link" onclick="return confirm('Are you sure you want to logout?')">
                <span class="nav-icon">🚪</span>
                <span class="nav-text">Logout</span>
            </a></li>
        </ul>

        <button class="mobile-menu-toggle" id="mobileToggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
    </nav>
</header>

<div class="mobile-menu-overlay" id="mobileOverlay"></div>

<!--  JavaScript for chatbot -->
<script>
    const session_id_php = "<?= session_id() ?>";
    const patient_id_php = <?= isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null' ?>;
    const userType = "<?= $_SESSION['user_type'] ?? 'patient' ?>";
</script>

<!-- Google Translate Script -->
<script type="text/javascript">
function googleTranslateElementInit() {
    // Sidebar version only
    new google.translate.TranslateElement({
        pageLanguage: 'en',
        includedLanguages: 'en,zh-CN,zh-TW,ms,ta,hi,bn,th,vi,id,ja,ko,ar,es,fr,de,pt,ru,it,nl,pl,tr,sv,no,da,fi,el,he,cs,ro',
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
        autoDisplay: false
    }, 'google_translate_element');
}

// Save and restore selected language
window.addEventListener('load', function() {
    setTimeout(() => {
        const translateSelect = document.querySelector('#google_translate_element select');
        
        if (translateSelect) {
            // Restore saved language
            const savedLanguage = localStorage.getItem('selectedLanguage');
            if (savedLanguage) {
                translateSelect.value = savedLanguage;
            }

            // Save on change
            translateSelect.addEventListener('change', function() {
                localStorage.setItem('selectedLanguage', this.value);
            });
        }
    }, 1000);
});

console.log('✅ Patient Header Navigation with Google Translate initialized');
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>