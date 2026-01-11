<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch patient info
$patientStmt = $conn->prepare("SELECT * FROM patients WHERE user_id = ? LIMIT 1");
$patientStmt->bind_param("i", $userId);
$patientStmt->execute();
$patient = $patientStmt->get_result()->fetch_assoc();

if (!$patient) {
    redirect('patient/profile.php');
}

$patientId = $patient['patient_id'];

// Get filter parameters
$specialization = isset($_GET['specialization']) ? htmlspecialchars(trim($_GET['specialization']), ENT_QUOTES, 'UTF-8') : '';
$searchQuery = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search']), ENT_QUOTES, 'UTF-8') : '';

// Build query dynamically
$sql = "
    SELECT 
        d.*,
        COUNT(DISTINCT a.appointment_id) as total_appointments,
        COALESCE(AVG(r.rating), 0) as avg_rating,
        COUNT(DISTINCT r.review_id) as total_reviews
    FROM doctors d
    LEFT JOIN appointments a ON d.doctor_id = a.doctor_id
    LEFT JOIN reviews r ON d.doctor_id = r.doctor_id
    WHERE d.status = 'active'
";

$params = [];
$types = "";

if ($specialization) {
    $sql .= " AND d.specialization = ?";
    $params[] = $specialization;
    $types .= "s";
}

if ($searchQuery) {
    $sql .= " AND (d.first_name LIKE ? OR d.last_name LIKE ? OR d.specialization LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

$sql .= " GROUP BY d.doctor_id ORDER BY avg_rating DESC, total_appointments DESC";

$stmt = $conn->prepare($sql);

// Dynamically bind parameters if they exist
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$doctors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all unique specializations
$specializationsResult = $conn->query("
    SELECT DISTINCT specialization 
    FROM doctors 
    WHERE status = 'active' AND specialization IS NOT NULL
    ORDER BY specialization ASC
");
$specializations = [];
while ($row = $specializationsResult->fetch_assoc()) {
    $specializations[] = $row['specialization'];
}

// Calculate statistics
$statsResult = $conn->query("
    SELECT 
        COUNT(*) as total_doctors,
        COUNT(DISTINCT specialization) as total_specializations,
        AVG(consultation_fee) as avg_fee
    FROM doctors
    WHERE status = 'active'
");
$stats = $statsResult->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Doctors - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="doctors.css">
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <main class="container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="header-content">
                    <h1>👨‍⚕️ Find Doctors</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;Browse our network of qualified healthcare professionals</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon doctors">
                        <span>👨‍⚕️</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_doctors'] ?? 0; ?></h3>
                        <p>Available Doctors</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon specializations">
                        <span>🏥</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_specializations'] ?? 0; ?></h3>
                        <p>Specializations</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon fee">
                        <span>💰</span>
                    </div>
                    <div class="stat-info">
                        <h3>RM <?php echo number_format($stats['avg_fee'] ?? 0, 0); ?></h3>
                        <p>Average Consultation</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon results">
                        <span>🔍</span>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo count($doctors); ?></h3>
                        <p>Search Results</p>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="section-card fade-in">
                <form method="GET" action="" class="search-filter-form">
                    <div class="search-section">
                        <div class="search-input-group">
                            <input 
                                type="text" 
                                name="search" 
                                class="search-input" 
                                placeholder="Search by doctor name or specialization..."
                                value="<?php echo htmlspecialchars($searchQuery); ?>"
                            >
                        </div>
                        
                        <select name="specialization" class="filter-select">
                            <option value="">All Specializations</option>
                            <?php foreach ($specializations as $spec): ?>
                                <option value="<?php echo htmlspecialchars($spec); ?>" 
                                    <?php echo ($specialization === $spec) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($spec); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <span>🔍</span> Search
                        </button>
                        
                        <?php if ($specialization || $searchQuery): ?>
                            <a href="doctors.php" class="btn btn-outline">
                                <span>🔄</span> Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Doctors Grid -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h2><span>👨‍⚕️</span> Our Doctors</h2>
                </div>
                
                <div class="doctors-grid">
                    <?php if (!empty($doctors)): ?>
                        <?php foreach ($doctors as $doctor): ?>
                            <div class="doctor-card" data-doctor-id="<?php echo $doctor['doctor_id']; ?>">
                                <!-- Doctor Image -->
                                <div class="doctor-image">
                                    <?php 
                                    $imagePath = '../' . $doctor['profile_picture'];
                                    if (!empty($doctor['profile_picture']) && file_exists(__DIR__ . '/' . $imagePath)): 
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="Dr. <?php echo htmlspecialchars($doctor['first_name']); ?>">
                                    <?php else: ?>
                                        <div class="doctor-placeholder">
                                            <span>👨‍⚕️</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Doctor Info -->
                                <div class="doctor-info">
                                    <h3 class="doctor-name">
                                        Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                    </h3>
                                    
                                    <p class="doctor-specialization">
                                        <span class="icon">🏥</span>
                                        <?php echo htmlspecialchars($doctor['specialization']); ?>
                                    </p>

                                    <?php if ($doctor['avg_rating'] > 0): ?>
                                        <div class="rating-badge">
                                            <span>⭐</span>
                                            <span><?php echo number_format($doctor['avg_rating'], 1); ?></span>
                                            <span class="reviews-count">(<?php echo $doctor['total_reviews']; ?> <?php echo $doctor['total_reviews'] == 1 ? 'review' : 'reviews'; ?>)</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($doctor['bio'])): ?>
                                        <p class="doctor-bio">
                                            <?php echo htmlspecialchars(substr($doctor['bio'], 0, 120)); ?>
                                            <?php echo strlen($doctor['bio']) > 120 ? '...' : ''; ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div class="doctor-details">
                                        <div class="detail-item">
                                            <span class="icon">💼</span>
                                            <span><?php echo $doctor['experience_years'] ?? 0; ?> years exp.</span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="icon">📞</span>
                                            <span><?php echo htmlspecialchars($doctor['phone']); ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="icon">💰</span>
                                            <span>RM <?php echo number_format($doctor['consultation_fee'], 2); ?></span>
                                        </div>
                                        
                                        <?php if ($doctor['total_reviews'] > 0): ?>
                                            <div class="detail-item">
                                                <span class="icon">📝</span>
                                                <span><?php echo $doctor['total_reviews']; ?> reviews</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="doctor-actions">
                                        <button class="btn btn-outline btn-sm" onclick="viewDoctorDetails(<?php echo $doctor['doctor_id']; ?>)">
                                            <span>👁️</span> View Profile
                                        </button>
                                        <a href="appointment.php?doctor_id=<?php echo $doctor['doctor_id']; ?>" class="btn btn-primary btn-sm">
                                            <span>📅</span> Book Appointment
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">👨‍⚕️</div>
                            <h3>No Doctors Found</h3>
                            <p>Try adjusting your search filters</p>
                            <a href="doctors.php" class="btn btn-primary">
                                <span>🔄</span> View All Doctors
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Doctor Details Modal -->
    <div id="doctorModal" class="modal">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>👨‍⚕️ Doctor Profile</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" onclick="bookAppointmentFromModal()">
                    <span>📅</span> Book Appointment
                </button>
            </div>
        </div>
    </div>

    <script src="doctors.js"></script>
</body>
</html>