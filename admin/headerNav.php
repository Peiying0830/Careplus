<?php 
$currentPath = $_SERVER['PHP_SELF'];

/* Dynamic Base URL Detection */
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$scriptName = $_SERVER['SCRIPT_NAME']; 
$dir = str_replace('\\', '/', dirname($scriptName));
// If we are in /admin/, we go one level up to find the root
$projectFolder = (basename($dir) == 'admin' || basename($dir) == 'doctor' || basename($dir) == 'patient') ? dirname($dir) : $dir;
$baseUrl = $protocol . "://" . $host . rtrim($projectFolder, '/');

// Get admin info from database
$adminName = 'Guest Admin';
$adminInitials = '👨‍💼';
$adminRole = 'System Administrator';
$adminProfilePicture = null;

if (isset($_SESSION['user_id'])) {
    try {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare("SELECT first_name, last_name, department as role, profile_picture FROM admins WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        
        if ($admin && !empty($admin['first_name'])) {
            $adminName = trim($admin['first_name'] . ' ' . $admin['last_name']);
            $adminRole = !empty($admin['role']) ? $admin['role'] : 'System Administrator';
            $adminProfilePicture = $admin['profile_picture'];
            
            // Generate initials
            $nameParts = explode(' ', $adminName);
            $adminInitials = strtoupper(substr($nameParts[0], 0, 1));
            if (isset($nameParts[1])) $adminInitials .= strtoupper(substr($nameParts[1], 0, 1));
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log('Error fetching admin info: ' . $e->getMessage());
    }
}

/* Image Logic Path */
$profilePictureUrl = null;
$hasValidImage = false;

if (!empty($adminProfilePicture)) {
    // Database contains "uploads/profiles/admin_..."
    $dbPath = ltrim($adminProfilePicture, '/');
    
    // Server-side check (Physical file)
    $serverPath = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dbPath);
    
    if (file_exists($serverPath)) {
        $profilePictureUrl = $baseUrl . '/' . $dbPath;
        $hasValidImage = true;
    }
}

$themeColors = ['admin' => ['primary' => '#FF8C42', 'secondary' => '#FFB380', 'light' => '#FFF5EE']];
$colors = $themeColors['admin'];
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
            <span class="logo-text">CarePlus - Admin Control Panel</span>
        </a>

        <ul class="nav-menu" id="mobileMenu">
            <div class="user-badge" style="display: flex; align-items: center; gap: 12px; padding: 10px;">
            <!-- Admin Avatar -->
            <div class="user-icon" style="
                background-color: var(--nav-primary); 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                overflow: hidden; 
                border-radius: 50%; 
                width: 50px; 
                height: 50px; 
                border: 2px solid white; 
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                flex-shrink: 0;">
                
                <?php if ($hasValidImage): ?>
                    <img src="<?= htmlspecialchars($profilePictureUrl); ?>" 
                        alt="<?= htmlspecialchars($adminName); ?>" 
                        style="width: 100%; height: 100%; object-fit: cover;"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <span style="display:none; color: white; font-weight: bold;"><?= $adminInitials; ?></span>
                <?php else: ?>
                    <span style="color: white; font-weight: bold; font-size: 16px;"><?= $adminInitials; ?></span>
                <?php endif; ?>
            </div>

            <div class="user-info">
                <div class="user-name" ><?= htmlspecialchars($adminName); ?></div>
                <div class="user-role"><?= htmlspecialchars($adminRole); ?></div>
                <div class="admin-status">
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
            
            <li><a href="users.php" class="nav-link users-link <?= basename($currentPath)=='users.php' ? 'active':''; ?>">
                <span class="nav-icon">👥</span>
                <span class="nav-text">User Management</span>
            </a></li>
            
            <li><a href="doctors.php" class="nav-link doctors-link <?= basename($currentPath)=='doctors.php' ? 'active':''; ?>">
                <span class="nav-icon">👨‍⚕️</span>
                <span class="nav-text">Doctors</span>
            </a></li>
            
            <li><a href="patients.php" class="nav-link patients-link <?= basename($currentPath)=='patients.php' ? 'active':''; ?>">
                <span class="nav-icon">🤒</span>
                <span class="nav-text">Patients</span>
            </a></li>
            
            <li><a href="appointments.php" class="nav-link appointments-link <?= basename($currentPath)=='appointments.php' ? 'active':''; ?>">
                <span class="nav-icon">📅</span>
                <span class="nav-text">Appointments</span>
            </a></li>

            <li><a href="billing.php" class="nav-link billing-link <?= basename($currentPath)=='billing.php' ? 'active':''; ?>">
                <span class="nav-icon">💳</span>
                <span class="nav-text">Billing & Payments</span>
            </a></li>

            <li><a href="activity.php" class="nav-link settings-link <?= basename($currentPath)=='activity.php' ? 'active':''; ?>">
                <span class="nav-icon">🔔</span>
                <span class="nav-text">Notifications</span>
            </a></li>

            <li><a href="reports.php" class="nav-link reports-link <?= basename($currentPath)=='reports.php' ? 'active':''; ?>">
                <span class="nav-icon">📊</span>
                <span class="nav-text">Reports & Analytics</span>
            </a></li>
            
            <li><a href="settings.php" class="nav-link settings-link <?= basename($currentPath)=='settings.php' ? 'active':''; ?>">
                <span class="nav-icon">⚙️</span>
                <span class="nav-text">System Settings</span>
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

console.log('✅ Admin Header Navigation with Google Translate initialized');
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>