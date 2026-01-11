<?php
require_once __DIR__ . '/../config.php';
requireRole('admin'); // Only admins can manage diagnostic scope

// Get the MySQLi connection from your singleton
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'add_symptom_scope') {
        $symptomName = sanitizeInput($_POST['symptom_name'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? '');
        $possibleConditions = sanitizeInput($_POST['possible_conditions'] ?? '');
        $urgencyLevel = sanitizeInput($_POST['urgency_level'] ?? 'routine');
        $warningKeywords = sanitizeInput($_POST['warning_keywords'] ?? '');
        $aiGuidance = sanitizeInput($_POST['guidance'] ?? '');
        $recommendedSpec = sanitizeInput($_POST['recommended_specialization'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($symptomName) || empty($category)) {
            echo json_encode(['success' => false, 'message' => 'Symptom name and category are required']);
            exit;
        }
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO symptom_scope 
                (symptom_name, category, possible_conditions, urgency_level, warning_keywords, guidance, recommended_specialization, is_active, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Types: s=string, i=integer
            $stmt->bind_param("ssssssisi", 
                $symptomName, 
                $category, 
                $possibleConditions, 
                $urgencyLevel, 
                $warningKeywords, 
                $aiGuidance, 
                $recommendedSpec, 
                $isActive, 
                $userId
            );
            
            if ($stmt->execute()) {
                logActivity($userId, 'symptom_scope_added', "Added symptom scope: $symptomName");
                echo json_encode([
                    'success' => true,
                    'message' => 'Symptom scope added successfully',
                    'id' => $conn->insert_id
                ]);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            logError("Add Symptom Scope Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error adding symptom scope']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_symptom_scope') {
        $scopeId = intval($_POST['scope_id'] ?? 0);
        $symptomName = sanitizeInput($_POST['symptom_name'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? '');
        $possibleConditions = sanitizeInput($_POST['possible_conditions'] ?? '');
        $urgencyLevel = sanitizeInput($_POST['urgency_level'] ?? 'routine');
        $warningKeywords = sanitizeInput($_POST['warning_keywords'] ?? '');
        $aiGuidance = sanitizeInput($_POST['guidance'] ?? '');
        $recommendedSpec = sanitizeInput($_POST['recommended_specialization'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        try {
            $stmt = $conn->prepare("
                UPDATE symptom_scope 
                SET symptom_name = ?, category = ?, possible_conditions = ?, 
                    urgency_level = ?, warning_keywords = ?, guidance = ?, 
                    recommended_specialization = ?, is_active = ?
                WHERE id = ?
            ");
            
            $stmt->bind_param("ssssssiii", 
                $symptomName, 
                $category, 
                $possibleConditions, 
                $urgencyLevel, 
                $warningKeywords, 
                $aiGuidance, 
                $recommendedSpec, 
                $isActive, 
                $scopeId
            );
            
            if ($stmt->execute()) {
                logActivity($userId, 'symptom_scope_updated', "Updated symptom scope ID: $scopeId");
                echo json_encode(['success' => true, 'message' => 'Symptom scope updated successfully']);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            logError("Update Symptom Scope Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error updating symptom scope']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete_symptom_scope') {
        $scopeId = intval($_POST['scope_id'] ?? 0);
        
        try {
            $stmt = $conn->prepare("DELETE FROM symptom_scope WHERE id = ?");
            $stmt->bind_param("i", $scopeId);
            
            if ($stmt->execute()) {
                logActivity($userId, 'symptom_scope_deleted', "Deleted symptom scope ID: $scopeId");
                echo json_encode(['success' => true, 'message' => 'Symptom scope deleted successfully']);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            logError("Delete Symptom Scope Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error deleting symptom scope']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_symptom_scope') {
        $scopeId = intval($_POST['scope_id'] ?? 0);
        
        try {
            $stmt = $conn->prepare("SELECT * FROM symptom_scope WHERE id = ?");
            $stmt->bind_param("i", $scopeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $scope = $result->fetch_assoc();
            
            if ($scope) {
                echo json_encode(['success' => true, 'scope' => $scope]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Symptom scope not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error fetching symptom scope']);
        }
        exit;
    }
}

// Fetch all symptom scopes
$symptomScopes = [];
try {
    $result = $conn->query("SELECT * FROM symptom_scope ORDER BY category, symptom_name");
    if ($result) {
        $symptomScopes = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Symptom Scope Fetch Error: " . $e->getMessage());
}

// Group by category
$scopesByCategory = [];
foreach ($symptomScopes as $scope) {
    $scopesByCategory[$scope['category']][] = $scope;
}

// Get statistics
$totalScopes = count($symptomScopes);
$activeScopes = count(array_filter($symptomScopes, function($s) { return $s['is_active'] == 1; }));
$emergencyScopes = count(array_filter($symptomScopes, function($s) { return $s['urgency_level'] == 'emergency'; }));

logActivity($userId, 'diagnostic_page_access', 'Accessed diagnostic management page');
?>

<!DOCTYPE html>
<!-- ... Rest of your HTML (it remains exactly the same) ... -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic Scope Management - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="diagnostic.css">
</head>
<body>
    <!-- Include Header Navigation -->
    <?php include __DIR__ . '/headerNav.php'; ?>

    <div class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <h1 class="page-title">🔬 Diagnostic Scope Management</h1>
                    <p class="page-subtitle">Control symptom checker responses and diagnostic guidelines</p>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $totalScopes; ?></div>
                        <div class="stat-label">Total Scopes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $activeScopes; ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $emergencyScopes; ?></div>
                        <div class="stat-label">Emergency</div>
                    </div>
                </div>
            </div>

            <!-- Add New Symptom Scope -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">➕ Add New Symptom Scope</h2>
                </div>
                <div class="card-body">
                    <form id="addScopeForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Symptom Name *</label>
                                <input type="text" name="symptom_name" class="form-control" required 
                                    placeholder="e.g., Chest Pain, Fever, Headache">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category" class="form-control" required>
                                    <option value="">Select Category</option>
                                    <option value="Respiratory">Respiratory</option>
                                    <option value="Cardiovascular">Cardiovascular</option>
                                    <option value="Neurological">Neurological</option>
                                    <option value="Gastrointestinal">Gastrointestinal</option>
                                    <option value="Musculoskeletal">Musculoskeletal</option>
                                    <option value="Dermatological">Dermatological</option>
                                    <option value="General">General</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Possible Conditions</label>
                            <textarea name="possible_conditions" class="form-control" rows="3"
                                placeholder="List common conditions associated with this symptom (comma-separated)
Example: Common Cold, Bronchitis, Pneumonia, Asthma"></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Default Urgency Level *</label>
                                <select name="urgency_level" class="form-control" required>
                                    <option value="routine">Routine - Regular appointment</option>
                                    <option value="urgent">Urgent - See doctor soon</option>
                                    <option value="emergency">Emergency - Immediate attention</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_active" checked>
                                    <span>Active (Include in responses)</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Warning Keywords</label>
                            <input type="text" name="warning_keywords" class="form-control" 
                                placeholder="Keywords that escalate urgency (comma-separated)
Example: severe, crushing, sudden, radiating, persistent">
                            <small class="form-helper">When these words appear with the symptom, urgency level increases</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Guidance</label>
                            <textarea name="guidance" class="form-control" rows="4"
                                placeholder="Specific instructions when this symptom is detected

Example: Always recommend ECG for chest pain with radiation. Emphasize calling emergency services if severe or accompanied by shortness of breath. Consider cardiac risk factors."></textarea>
                            <small class="form-helper">This guides how the system should respond when detecting this symptom</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Recommended Doctor Specialization</label>
                            <input type="text" name="recommended_specialization" class="form-control" 
                                placeholder="e.g., Cardiologist, Neurologist, General Practitioner, Pulmonologist">
                            <small class="form-helper">Which type of doctor should patients see for this symptom?</small>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            💾 Add Symptom Scope
                        </button>
                    </form>
                </div>
            </div>

            <!-- Existing Symptom Scopes -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📋 Existing Symptom Scopes</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($symptomScopes)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">🔬</div>
                            <p>No symptom scopes defined yet. Add your first scope above.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($scopesByCategory as $category => $scopes): ?>
                            <div class="category-section">
                                <div class="category-header">
                                    <span class="category-name"><?php echo htmlspecialchars($category); ?></span>
                                    <span class="category-count"><?php echo count($scopes); ?> symptoms</span>
                                </div>
                                <div class="scopes-grid">
                                    <?php foreach ($scopes as $scope): ?>
                                        <div class="scope-card">
                                            <div class="scope-header">
                                                <h3 class="scope-name"><?php echo htmlspecialchars($scope['symptom_name']); ?></h3>
                                                <div class="scope-badges">
                                                    <span class="badge badge-<?php echo $scope['urgency_level']; ?>">
                                                        <?php 
                                                        $urgencyIcons = [
                                                            'routine' => '✓',
                                                            'urgent' => '⚠️',
                                                            'emergency' => '🚨'
                                                        ];
                                                        echo $urgencyIcons[$scope['urgency_level']] . ' ' . strtoupper($scope['urgency_level']); 
                                                        ?>
                                                    </span>
                                                    <span class="badge badge-<?php echo $scope['is_active'] ? 'active' : 'inactive'; ?>">
                                                        <?php echo $scope['is_active'] ? '● Active' : '○ Inactive'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($scope['possible_conditions'])): ?>
                                                <div class="scope-detail">
                                                    <strong>Conditions:</strong>
                                                    <p><?php echo htmlspecialchars(substr($scope['possible_conditions'], 0, 100)); ?>
                                                    <?php echo strlen($scope['possible_conditions']) > 100 ? '...' : ''; ?></p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($scope['warning_keywords'])): ?>
                                                <div class="scope-detail">
                                                    <strong>Warning Keywords:</strong>
                                                    <div class="keyword-tags">
                                                        <?php 
                                                        $keywords = array_slice(array_map('trim', explode(',', $scope['warning_keywords'])), 0, 5);
                                                        foreach ($keywords as $keyword): 
                                                        ?>
                                                            <span class="keyword-tag"><?php echo htmlspecialchars($keyword); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($scope['recommended_specialization'])): ?>
                                                <div class="scope-detail">
                                                    <strong>👨‍⚕️ Recommended Doctor:</strong>
                                                    <p style="color: #26a69a; font-weight: 500;">
                                                        <?php echo htmlspecialchars($scope['recommended_specialization']); ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($scope['guidance'])): ?>
                                                <div class="scope-detail">
                                                    <strong>Guidance:</strong>
                                                    <p><?php echo htmlspecialchars(substr($scope['guidance'], 0, 120)); ?>
                                                    <?php echo strlen($scope['guidance']) > 120 ? '...' : ''; ?></p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="scope-actions">
                                                <button class="btn btn-sm btn-edit" onclick="editScope(<?php echo $scope['id']; ?>)">
                                                    ✏️ Edit
                                                </button>
                                                <button class="btn btn-sm btn-delete" onclick="deleteScope(<?php echo $scope['id']; ?>)">
                                                    🗑️ Delete
                                                </button>
                                            </div>
                                            
                                            <div class="scope-footer">
                                                <small>Created: <?php echo date('M d, Y', strtotime($scope['created_at'])); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-backdrop" onclick="closeEditModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>✏️ Edit Symptom Scope</h3>
                <button class="modal-close" onclick="closeEditModal()">✖</button>
            </div>
            <div class="modal-body">
                <form id="editScopeForm">
                    <input type="hidden" name="scope_id" id="edit_scope_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Symptom Name *</label>
                            <input type="text" name="symptom_name" id="edit_symptom_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category *</label>
                            <select name="category" id="edit_category" class="form-control" required>
                                <option value="Respiratory">Respiratory</option>
                                <option value="Cardiovascular">Cardiovascular</option>
                                <option value="Neurological">Neurological</option>
                                <option value="Gastrointestinal">Gastrointestinal</option>
                                <option value="Musculoskeletal">Musculoskeletal</option>
                                <option value="Dermatological">Dermatological</option>
                                <option value="General">General</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Possible Conditions</label>
                        <textarea name="possible_conditions" id="edit_possible_conditions" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Default Urgency Level *</label>
                            <select name="urgency_level" id="edit_urgency_level" class="form-control" required>
                                <option value="routine">Routine</option>
                                <option value="urgent">Urgent</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" id="edit_is_active">
                                <span>Active</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Warning Keywords</label>
                        <input type="text" name="warning_keywords" id="edit_warning_keywords" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Guidance</label>
                        <textarea name="guidance" id="edit_guidance" class="form-control" rows="4"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Recommended Doctor Specialization</label>
                        <input type="text" name="recommended_specialization" id="edit_recommended_specialization" class="form-control" 
                            placeholder="e.g., Cardiologist, Neurologist, General Practitioner">
                    </div>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn btn-primary">💾 Update Scope</button>
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="diagnostic.js"></script>
</body>
</html>