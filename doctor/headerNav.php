<?php 
$currentPath = $_SERVER['PHP_SELF'];

/* Dynamic Base URL Detection */
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
// Get the directory of the current script and clean it up
$scriptName = $_SERVER['SCRIPT_NAME']; 
$dir = str_replace('\\', '/', dirname($scriptName));
// If we are in a subfolder like /doctor/, we go one level up to find the root
$projectFolder = (basename($dir) == 'doctor' || basename($dir) == 'patient' || basename($dir) == 'admin') ? dirname($dir) : $dir;
$baseUrl = $protocol . "://" . $host . rtrim($projectFolder, '/');

// Get doctor name and profile picture from session
$doctorName = 'Guest Doctor';
$doctorInitials = '👨‍⚕️';
$doctorProfilePicture = null; 

if (isset($_SESSION['user_id'])) {
    try {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare("SELECT first_name, last_name, specialization, profile_picture FROM doctors WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $doctor = $result->fetch_assoc();
        
        if ($doctor && !empty($doctor['first_name'])) {
            $doctorName = 'Dr. ' . trim($doctor['first_name'] . ' ' . $doctor['last_name']);
            $doctorProfilePicture = $doctor['profile_picture'];
            
            // Generate initials
            $nameParts = explode(' ', trim($doctor['first_name'] . ' ' . $doctor['last_name']));
            $doctorInitials = strtoupper(substr($nameParts[0], 0, 1));
            if (isset($nameParts[1]) && !empty($nameParts[1])) {
                $doctorInitials .= strtoupper(substr($nameParts[1], 0, 1));
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log('Error fetching doctor name: ' . $e->getMessage());
    }
}

/* Image Path Logic*/
$profilePictureUrl = null;
$hasValidImage = false;

if (!empty($doctorProfilePicture)) {
    $dbPath = ltrim($doctorProfilePicture, '/');
    
    $serverPath = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dbPath);
    
    if (file_exists($serverPath)) {
        $profilePictureUrl = $baseUrl . '/' . $dbPath;
        $hasValidImage = true;
    }
}

$themeColors = ['doctor' => ['primary' => '#6DADE8', 'secondary' => '#8AC6F5', 'light' => '#F5FAFF']];
$colors = $themeColors['doctor'];
$logoutPath = '../logout.php';
?>

<!-- Load External CSS & JS -->
<link rel="stylesheet" href="headerNav.css?v=1.1">
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
            <span class="logo-text">CarePlus - Doctor Control Panel</span>
        </a>

        <ul class="nav-menu" id="mobileMenu">
            <div class="user-badge">
                <!-- DOCTOR IMAGE ICON -->
                <div class="user-icon" style="background-color: var(--nav-primary); display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 50%; width: 50px; height: 50px; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <?php if ($hasValidImage): ?>
                        <img src="<?= htmlspecialchars($profilePictureUrl); ?>" 
                            alt="<?= htmlspecialchars($doctorName); ?>" 
                            style="width: 100%; height: 100%; object-fit: cover;"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                        <span style="display:none; color: white; font-weight: bold;"><?= $doctorInitials; ?></span>
                    <?php else: ?>
                        <span style="color: white; font-weight: bold;"><?= $doctorInitials; ?></span>
                    <?php endif; ?>
                </div>

                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($doctorName); ?></div>
                    <div class="user-role">
                        <?= isset($doctor['specialization']) ? htmlspecialchars($doctor['specialization']) : 'Medical Professional'; ?>
                    </div>
                    <div class="doctor-status">
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

            <li><a href="dashboard.php" class="nav-link dashboard-link <?= basename($currentPath)=='dashboard.php' ? 'active':''; ?>">
                <span class="nav-icon">🏡</span>
                <span class="nav-text">Dashboard</span>
            </a></li>
            
            <li><a href="appointments.php" class="nav-link appointments-link <?= basename($currentPath)=='appointments.php' ? 'active':''; ?>">
                <span class="nav-icon">📅</span>
                <span class="nav-text">Appointments</span>
            </a></li>
            
            <li><a href="patients.php" class="nav-link patients-link <?= basename($currentPath)=='patients.php' ? 'active':''; ?>">
                <span class="nav-icon">👥</span>
                <span class="nav-text">Patients</span>
            </a></li>
            
            <li><a href="prescriptions.php" class="nav-link prescriptions-link <?= basename($currentPath)=='prescriptions.php' ? 'active':''; ?>">
                <span class="nav-icon">💊</span>
                <span class="nav-text">Prescriptions</span>
            </a></li>
            
            <li><a href="medicalRecords.php" class="nav-link medical-records-link <?= basename($currentPath)=='medicalRecords.php' ? 'active':''; ?>">
                <span class="nav-icon">📋</span>
                <span class="nav-text">Medical Records</span>
            </a></li>
            
            <li><a href="profile.php" class="nav-link <?= basename($currentPath)=='profile.php' ? 'active':''; ?>">
                <span class="nav-icon">👤</span>
                <span class="nav-text">My Profile</span>
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

console.log('✅ Doctor Header Navigation with Google Translate initialized');
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>