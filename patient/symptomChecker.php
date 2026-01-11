<?php
require_once __DIR__ . '/../config.php';
requireRole('patient');

// Use the MySQLi connection from your Database class
$conn = Database::getInstance()->getConnection();
$userId = getUserId();

// Fetch Patient Info 
$stmt = $conn->prepare("SELECT * FROM patients WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();

if (!$patient) {
    redirect('patient/profile.php');
}
$patientId = $patient['patient_id'];
$patientAge = $patient['age'] ?? 0;

// Fetch Recent Symptom Checks
$recentChecks = [];
$totalChecks = 0;
try {
    // Count total checks
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM symptom_checks WHERE user_id = ?");
    $countStmt->bind_param("i", $userId);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $totalChecks = $countRes->fetch_row()[0] ?? 0;

    // Fetch recent checks query
    $checksStmt = $conn->prepare("
        SELECT 
            sc.*, 
            GROUP_CONCAT(ss.symptom_name ORDER BY ss.symptom_name SEPARATOR ', ') AS matched_symptoms
        FROM symptom_checks sc
        LEFT JOIN symptom_check_scopes scs ON sc.id = scs.check_id
        LEFT JOIN symptom_scope ss ON scs.scope_id = ss.scope_id 
        WHERE sc.user_id = ?
        GROUP BY sc.check_id
        ORDER BY sc.created_at DESC
        LIMIT 10
    ");
    $checksStmt->bind_param("i", $userId);
    $checksStmt->execute();
    $recentChecks = $checksStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Symptom checks table error: " . $e->getMessage());
}

// Handle AJAX Requests 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Action: Check Symptoms 
    if ($_POST['action'] === 'check_symptoms') {
        $symptoms = sanitizeInput($_POST['symptoms'] ?? '');
        $duration = sanitizeInput($_POST['duration'] ?? '');
        $age = intval($_POST['age'] ?? $patientAge);
        $additionalInfo = sanitizeInput($_POST['additional_info'] ?? '');

        if (empty($symptoms)) {
            echo json_encode(['success' => false, 'message' => 'Please describe your symptoms']);
            exit;
        }

        $detectedScopes = [];
        $matchedConditions = [];
        $urgencyLevel = 'routine';
        $recommendedSpecializations = [];

        try {
            // Fetch all active symptom scopes using MySQLi query
            $scopeResult = $conn->query("SELECT * FROM symptom_scope WHERE is_active = 1");
            $activeScopes = $scopeResult->fetch_all(MYSQLI_ASSOC);
            
            $symptomsLower = strtolower($symptoms . ' ' . $additionalInfo . ' ' . $duration);

            // Logic for matching (Same as before)
            $criticalEmergencyKeywords = [
                'heart attack', 'cardiac arrest', 'stroke', 'seizure', 
                'unconscious', 'not breathing', 'can\'t breathe', 'cannot breathe',
                'severe bleeding', 'heavy bleeding', 'suicide', 'suicidal',
                'overdose', 'poisoning', 'chest crushing', 'crushing chest pain'
            ];

            $isCriticalEmergency = false;
            foreach ($criticalEmergencyKeywords as $criticalWord) {
                if (preg_match('/\b' . preg_quote($criticalWord, '/') . '\b/i', $symptomsLower)) {
                    $isCriticalEmergency = true;
                    break;
                }
            }

            $highestUrgencyValue = 0;
            $urgencyMap = ['routine' => 0, 'urgent' => 1, 'emergency' => 2];
            $reverseUrgencyMap = [0 => 'routine', 1 => 'urgent', 2 => 'emergency'];

            foreach ($activeScopes as $scope) {
                $symptomNameLower = strtolower(trim($scope['symptom_name']));
                if (empty($symptomNameLower)) continue;

                if (preg_match('/\b' . preg_quote($symptomNameLower, '/') . '\b/i', $symptomsLower)) {
                    $currentUrgencyValue = $urgencyMap[$scope['urgency_level']] ?? 0;
                    $keywords = array_filter(array_map('trim', explode(',', strtolower($scope['warning_keywords'] ?? ''))));
                    
                    foreach ($keywords as $keyword) {
                        if (!empty($keyword) && preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $symptomsLower)) {
                            if ($currentUrgencyValue < 1) $currentUrgencyValue = 1;
                            break;
                        }
                    }

                    if ($currentUrgencyValue > $highestUrgencyValue) $highestUrgencyValue = $currentUrgencyValue;
                    $detectedScopes[] = $scope;

                    if (!empty($scope['possible_conditions'])) {
                        $conditions = array_map('trim', explode(',', $scope['possible_conditions']));
                        foreach (array_slice($conditions, 0, 3) as $cond) {
                            $matchedConditions[] = ['condition' => $cond, 'symptom' => $scope['symptom_name']];
                        }
                    }
                    if (!empty($scope['recommended_specialization'])) $recommendedSpecializations[] = $scope['recommended_specialization'];
                }
            }

            $urgencyLevel = $isCriticalEmergency ? 'emergency' : $reverseUrgencyMap[$highestUrgencyValue];
            $recommendedSpecializations = array_unique($recommendedSpecializations);

            $aiResponse = generateStructuredResponse($matchedConditions, $urgencyLevel, $detectedScopes, $symptoms, $duration, $age, $recommendedSpecializations);

            // MySQLi Insert main check
            $insertStmt = $conn->prepare("INSERT INTO symptom_checks (user_id, patient_id, symptoms, duration, age, additional_info, response, urgency_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->bind_param("iisissss", $userId, $patientId, $symptoms, $duration, $age, $additionalInfo, $aiResponse, $urgencyLevel);
            $insertStmt->execute();
            $checkId = $conn->insert_id;
            $symptomNames = array_column($detectedScopes, 'symptom_name');

            // MySQLi Insert mapping scopes
            if (!empty($detectedScopes)) {
                $mapStmt = $conn->prepare("INSERT INTO symptom_check_scopes (check_id, scope_id) VALUES (?, ?)");
                foreach ($detectedScopes as $scope) {
                    $scopeId = $scope['scope_id']; 
                    $mapStmt->bind_param("ii", $checkId, $scopeId);
                    $mapStmt->execute();
                }
            }

            echo json_encode([
                'success' => true,
                'response' => $aiResponse,
                'urgency' => $urgencyLevel,
                'check_id' => $checkId,
                'detected_scopes' => count($detectedScopes),
                'created_at' => date('Y-m-d H:i:s'),
                'matched_symptom_names' => implode(', ', $symptomNames)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Action: View Check Details
    if ($_POST['action'] === 'view_check') {
        $checkId = intval($_POST['check_id'] ?? 0);
        try {
            $stmt = $conn->prepare("SELECT * FROM symptom_checks WHERE check_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $checkId, $userId);
            $stmt->execute();
            $check = $stmt->get_result()->fetch_assoc();

            if ($check) {
                echo json_encode(['success' => true, 'check' => $check]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Record not found.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }
}

function generateStructuredResponse($conditions, $urgency, $scopes, $symptoms, $duration, $age, $specializations = []) {
    $response = "# Symptom Analysis Results\n\n";

    // Consolidate all conditions and remove duplicates
    $allConditions = [];
    foreach ($conditions as $item) {
        $condition = trim($item['condition']);
        if (!isset($allConditions[$condition])) {
            $allConditions[$condition] = [
                'name' => $condition,
                'symptoms' => []
            ];
        }
        if (!empty($item['symptom'])) {
            $allConditions[$condition]['symptoms'][] = $item['symptom'];
        }
    }

    // Enhanced condition descriptions
    $conditionDescriptions = [
        'Common Cold' => 'A viral infection of the upper respiratory tract causing congestion, runny nose, and mild fever',
        'Bronchitis' => 'Inflammation of the bronchial tubes causing persistent cough and chest discomfort',
        'Pneumonia' => 'Lung infection causing fever, cough, and difficulty breathing - requires medical attention',
        'Asthma' => 'Chronic respiratory condition causing wheezing, shortness of breath, and chest tightness',
        'Tension Headache' => 'Most common headache type causing band-like pressure around the head',
        'Migraine' => 'Severe headache often with nausea, light sensitivity, and visual disturbances',
        'Cluster Headache' => 'Intense headache episodes occurring in clusters over weeks or months',
        'Viral Infection' => 'Illness caused by a virus, often causing fever, fatigue, and body aches',
        'Bacterial Infection' => 'Illness caused by bacteria that may require antibiotic treatment',
        'UTI' => 'Urinary tract infection causing painful urination, frequent urge to urinate, and possible fever',
        'Flu' => 'Influenza virus causing high fever, severe body aches, fatigue, and respiratory symptoms',
        'COVID-19' => 'Coronavirus infection with symptoms ranging from mild cold-like to severe respiratory distress',
        'Gastroenteritis' => 'Stomach and intestinal inflammation causing nausea, vomiting, and diarrhea',
        'Food Poisoning' => 'Illness from contaminated food causing rapid onset nausea, vomiting, and diarrhea',
        'Sinusitis' => 'Sinus inflammation causing facial pain, pressure, and nasal congestion',
        'Allergies' => 'Immune system reaction causing sneezing, itching, runny nose, and watery eyes',
        'GERD' => 'Acid reflux causing heartburn, chest discomfort, and throat irritation',
        'Dehydration' => 'Insufficient fluid intake causing fatigue, dizziness, and dry mouth',
        'Anemia' => 'Low red blood cell count causing fatigue, weakness, and pale skin',
        'Hypothyroidism' => 'Underactive thyroid causing fatigue, weight gain, and cold sensitivity',
        'Sleep Disorder' => 'Disrupted sleep patterns affecting energy levels and daily functioning',
        'Strep Throat' => 'Bacterial throat infection requiring antibiotics, causing severe sore throat and fever',
        'Tonsillitis' => 'Inflamed tonsils causing severe throat pain, difficulty swallowing, and fever',
        'IBS' => 'Irritable bowel syndrome causing abdominal pain, bloating, and irregular bowel movements',
        'Vertigo' => 'Spinning sensation and balance problems, often from inner ear issues',
        'Low Blood Pressure' => 'Hypotension causing dizziness, lightheadedness, and possible fainting',
        'Muscle Strain' => 'Overstretched or torn muscle causing pain and limited movement',
        'Angina' => 'Chest pain from reduced blood flow to the heart muscle',
        'Heart Attack' => 'Life-threatening blockage of blood flow to the heart - requires immediate emergency care',
        'Costochondritis' => 'Inflammation of chest wall cartilage causing localized chest pain',
        'Anxiety' => 'Mental health condition causing excessive worry, tension, and physical symptoms',
        'Pericarditis' => 'Inflammation of the protective sac around the heart',
        'COPD' => 'Chronic obstructive pulmonary disease affecting breathing and lung function',
        'Arrhythmia' => 'Irregular heartbeat that may require medical evaluation',
        'Epilepsy' => 'Neurological disorder causing recurrent seizures',
        'Dementia' => 'Progressive decline in cognitive function affecting memory and daily activities',
        'Alzheimers' => 'Most common type of dementia causing memory loss and confusion',
        'MS' => 'Multiple sclerosis - autoimmune disease affecting the nervous system',
        'Appendicitis' => 'Inflammation of the appendix requiring urgent surgical evaluation',
        'Gastritis' => 'Stomach lining inflammation causing pain and nausea',
        'Gallstones' => 'Hardened deposits in the gallbladder causing pain',
        'Ulcer' => 'Sore in stomach or intestinal lining causing pain and bleeding',
        'IBD' => 'Inflammatory bowel disease causing chronic intestinal inflammation',
        'Diverticulosis' => 'Small pouches in the colon wall that may become inflamed',
        'Herniated Disc' => 'Spinal disc problem causing back or neck pain and nerve symptoms',
        'Sciatica' => 'Nerve pain radiating from lower back down the leg',
        'Arthritis' => 'Joint inflammation causing pain, stiffness, and swelling',
        'Gout' => 'Sudden, severe joint pain from uric acid crystal buildup',
        'Osteoarthritis' => 'Wear-and-tear arthritis causing joint degeneration',
        'Rheumatoid Arthritis' => 'Autoimmune arthritis causing joint inflammation',
        'Eczema' => 'Skin condition causing itchy, inflamed patches',
        'Psoriasis' => 'Autoimmune skin condition causing scaly patches',
        'Melanoma' => 'Serious skin cancer requiring prompt treatment',
        'Cellulitis' => 'Bacterial skin infection requiring antibiotic treatment',
        'Mononucleosis' => 'Viral infection causing severe fatigue and sore throat',
        'Glaucoma' => 'Eye condition with increased pressure that can damage vision',
        'Cataracts' => 'Clouding of the eye lens affecting vision',
        'Retinal Detachment' => 'Emergency eye condition requiring immediate treatment',
        'Hyperthyroidism' => 'Overactive thyroid causing rapid metabolism',
        'Diabetes' => 'Metabolic disorder affecting blood sugar regulation',
        'Sleep Apnea' => 'Sleep disorder with breathing interruptions',
        'Chronic Fatigue Syndrome' => 'Complex disorder causing extreme, persistent tiredness'
    ];

    $response .= "## 💊 Possible Diagnosis\n\n";
    
    if (!empty($allConditions)) {
        $response .= "Based on your symptoms, you may be experiencing:\n\n";
        
        $conditionCount = 0;
        foreach ($allConditions as $condition) {
            if ($conditionCount >= 5) break;
            
            $conditionName = $condition['name'];
            $description = $conditionDescriptions[$conditionName] ?? 'A medical condition that requires professional evaluation';
            
            $response .= "### ✓ " . $conditionName . "\n\n";
            $response .= "" . $description . "\n\n";
            
            if (!empty($condition['symptoms'])) {
                $uniqueSymptoms = array_unique($condition['symptoms']);
                $response .= "**Related to your symptoms:** " . implode(', ', $uniqueSymptoms) . "\n\n";
            }
            
            $conditionCount++;
        }
    } else {
        $response .= "Your symptoms do not closely match our diagnostic database. We recommend consulting a healthcare provider for proper evaluation.\n\n";
    }

    $response .= "## 💖 Self-Care Recommendations\n\n";
    $hasFever = false;
    $hasCough = false;
    $hasRespiratory = false;
    $hasDigestive = false;
    
    foreach ($scopes as $scope) {
        $symptomLower = strtolower($scope['symptom_name']);
        $category = $scope['category'] ?? '';
        
        if (strpos($symptomLower, 'fever') !== false) $hasFever = true;
        if (strpos($symptomLower, 'cough') !== false) $hasCough = true;
        if (in_array($category, ['Respiratory'])) $hasRespiratory = true;
        if (in_array($category, ['Gastrointestinal'])) $hasDigestive = true;
    }
    
    $response .= "* **Rest:** Get adequate sleep (7-9 hours per night) to help your body recover\n";
    $response .= "* **Hydration:** Drink plenty of fluids - water, warm tea, clear soup, or electrolyte drinks\n";
    
    if ($hasFever) {
        $response .= "* **Fever Management:** Take paracetamol (acetaminophen) or ibuprofen as directed for temperature above 38.5°C (101°F)\n";
        $response .= "* **Cool Compress:** Apply cool, damp cloth to forehead and neck to reduce fever\n";
    }
    
    if ($hasCough || $hasRespiratory) {
        $response .= "* **Respiratory Relief:** Use throat lozenges, honey (if over 1 year old), steam inhalation, or saline nasal spray\n";
        $response .= "* **Humidity:** Use a humidifier or breathe steam from hot shower to ease congestion\n";
    }
    
    if ($hasDigestive) {
        $response .= "* **Bland Diet:** Stick to easily digestible foods like rice, bananas, toast, and clear broths (BRAT diet)\n";
        $response .= "* **Small Meals:** Eat small, frequent meals rather than large portions\n";
    }
    
    $response .= "* **Nutrition:** Maintain balanced diet even if appetite is reduced - small nutritious meals help recovery\n";
    $response .= "* **Monitor:** Keep track of temperature, symptoms, and overall condition - note any changes\n";
    $response .= "* **Avoid:** Skip smoking, alcohol, and strenuous activities until fully recovered\n\n";

    $response .= "## ⚠️ When to Seek Medical Attention\n\n";
    
    $warningKeywords = [];
    foreach ($scopes as $scope) {
        if (!empty($scope['warning_keywords'])) {
            $keywords = array_map('trim', explode(',', $scope['warning_keywords']));
            $warningKeywords = array_merge($warningKeywords, $keywords);
        }
    }
    $warningKeywords = array_unique($warningKeywords);
    
    if (!empty($warningKeywords)) {
        $response .= "**Seek medical care if you experience:**\n\n";
        foreach (array_slice($warningKeywords, 0, 6) as $keyword) {
            $response .= "* " . ucfirst($keyword) . "\n";
        }
        $response .= "\n";
    }
    
    $response .= "**General warning signs requiring medical evaluation:**\n\n";
    
    if ($urgency === 'routine') {
        $response .= "* Fever above 39°C (102°F) lasting more than 3 days\n";
        $response .= "* Symptoms persist or worsen after 7-10 days\n";
        $response .= "* Difficulty breathing or chest pain develops\n";
        $response .= "* Persistent vomiting or inability to keep fluids down\n";
        $response .= "* Signs of dehydration (decreased urination, extreme thirst, dizziness)\n";
        $response .= "* Severe or worsening pain\n\n";
    }

    $response .= "## 🎯 Urgency Assessment\n\n";
    switch ($urgency) {
        case 'emergency':
            $response .= "**🚨 EMERGENCY - IMMEDIATE ACTION REQUIRED**\n\n";
            $response .= "Call 999 or visit the nearest Emergency Room immediately. Do not wait or try to drive yourself if symptoms are severe.\n\n";
            break;
        case 'urgent':
            $response .= "**⚠️ URGENT - SEEK MEDICAL ATTENTION SOON**\n\n";
            $response .= "You should see a doctor within 24-48 hours. Contact your healthcare provider today or visit an urgent care clinic.\n\n";
            break;
        default:
            $response .= "**✓ ROUTINE CARE RECOMMENDED**\n\n";
            $response .= "Schedule an appointment with your doctor during normal office hours. Continue self-care measures in the meantime.\n\n";
    }

    if (!empty($specializations)) {
        $response .= "## 👨‍⚕️ Recommended Healthcare Provider\n\n";
        $uniqueSpecs = array_unique($specializations);

        if ($urgency === 'emergency') {
            $response .= "**First:** Go to Emergency Room immediately\n\n";
            $response .= "**Follow-up with:**\n\n";
            foreach (array_slice($uniqueSpecs, 0, 2) as $spec) {
                $response .= "* " . trim($spec) . "\n";
            }
        } else {
            $response .= "**Recommended appointment with:**\n\n";
            foreach (array_slice($uniqueSpecs, 0, 2) as $spec) {
                $response .= "* " . trim($spec) . "\n";
            }

            if (count($uniqueSpecs) > 2) {
                $response .= "\n**Alternative specialists:** " . implode(", ", array_slice($uniqueSpecs, 2)) . "\n";
            }
        }
        $response .= "\n";
    } else {
        $response .= "## 👨‍⚕️ Recommended Healthcare Provider\n\n";
        $response .= "* **General Practitioner** or **Family Doctor**\n\n";
    }

    $response .= "## 🚨 Important Medical Disclaimer\n\n";
    $response .= "This assessment is for informational purposes only and does not constitute medical advice. ";
    $response .= "It should not replace consultation with a qualified healthcare professional. ";
    $response .= "If you have concerns about your health, please contact your doctor.\n\n";

    return $response;
}

logActivity($userId, 'symptom_checker_access', 'Accessed symptom checker page');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Symptom Checker - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="symptomChecker.css">
</head>
<body>
    <?php include __DIR__ . '/headerNav.php'; ?>

    <div class="main-content">
        <main class="container">
            <div class="page-header">
                <div class="header-content">
                    <h1>🔍 Symptom Checker</h1>
                    <p>&emsp;&emsp;&emsp;&nbsp;&nbsp;Get preliminary health assessment based on your symptoms</p>
                </div>
            </div>

            <div class="alert alert-warning">
                <div class="alert-icon">⚠️</div>
                <div class="alert-content">
                    <strong>Medical Disclaimer:</strong> This is a preliminary assessment tool. It does not replace professional medical advice, diagnosis, or treatment. Always consult a qualified healthcare provider for accurate medical guidance.
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span>📝</span> Describe Your Symptoms
                    </h2>
                </div>
                <div class="card-body">
                    <form id="symptomForm">
                        <div class="form-group">
                            <label class="form-label">
                                <span>🏥</span> Quick Select Common Symptoms
                            </label>
                            <p class="form-helper">Click on symptoms you're experiencing (optional)</p>
                            <div class="common-symptoms" id="commonSymptoms">
                                <div class="symptom-chip" data-symptom="fever">🌡️ Fever</div>
                                <div class="symptom-chip" data-symptom="cough">😷 Cough</div>
                                <div class="symptom-chip" data-symptom="headache">🤕 Headache</div>
                                <div class="symptom-chip" data-symptom="sore throat">😣 Sore Throat</div>
                                <div class="symptom-chip" data-symptom="fatigue">😴 Fatigue</div>
                                <div class="symptom-chip" data-symptom="nausea">🤢 Nausea</div>
                                <div class="symptom-chip" data-symptom="dizziness">😵 Dizziness</div>
                                <div class="symptom-chip" data-symptom="body ache">💪 Body Ache</div>
                                <div class="symptom-chip" data-symptom="runny nose">👃 Runny Nose</div>
                                <div class="symptom-chip" data-symptom="shortness of breath">😮‍💨 Shortness of Breath</div>
                                <div class="symptom-chip" data-symptom="chest pain">💔 Chest Pain</div>
                                <div class="symptom-chip" data-symptom="stomach pain">🤰 Stomach Pain</div>
                                <div class="symptom-chip" data-symptom="loss of appetite">🍽️ Loss of Appetite</div>
                                <div class="symptom-chip" data-symptom="vomiting">🤮 Vomiting</div>
                                <div class="symptom-chip" data-symptom="diarrhea">🚽 Diarrhea</div>
                                <div class="symptom-chip" data-symptom="rash">🔴 Rash</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="symptoms">
                                <span>✍️</span> Describe Your Symptoms in Detail *
                            </label>
                            <p class="form-helper">Be as specific as possible. Include severity, location, and any patterns you've noticed.</p>
                            <textarea 
                                id="symptoms" 
                                class="form-control" 
                                rows="6" 
                                required 
                                maxlength="1000"
                                placeholder="Example: I've been experiencing a persistent dry cough for the last 2 days. The cough is worse at night and I have a mild fever around 38°C. I also feel very tired and have body aches..."></textarea>
                            <div class="char-counter">
                                <span id="charCount">0</span> / 1000 characters
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="duration">
                                    <span>⏱️</span> How Long Have You Had These Symptoms? *
                                </label>
                                <input 
                                    id="duration" 
                                    class="form-control" 
                                    placeholder="e.g., 2 days, 1 week, few hours" 
                                    required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="age">
                                    <span>👤</span> Your Age
                                </label>
                                <input 
                                    id="age" 
                                    type="number" 
                                    class="form-control" 
                                    value="<?php echo $patientAge; ?>"
                                    min="1" 
                                    max="120">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="additional">
                                <span>📋</span> Additional Information (Optional)
                            </label>
                            <p class="form-helper">Any relevant medical history, allergies, or current medications</p>
                            <textarea 
                                id="additional" 
                                class="form-control" 
                                rows="3" 
                                placeholder="e.g., I have asthma, allergic to penicillin, currently taking blood pressure medication..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <span>🔍</span> Analyze Symptoms
                            </button>
                            <button type="button" class="btn btn-outline" onclick="resetForm()">
                                <span>🔄</span> Reset Form
                            </button>
                        </div>
                    </form>

                    <div id="resultsContainer"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span>📜</span> Symptom Checker History (<?php echo $totalChecks; ?> total)
                    </h2>
                </div>
                <div class="card-body">
                    <!-- Statistics Section with IDs for Dynamic Updates -->
                    <div class="history-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                        <?php
                        $emergencyCount = 0;
                        $urgentCount = 0;
                        $routineCount = 0;
                        foreach ($recentChecks as $check) {
                            if ($check['urgency_level'] === 'emergency') $emergencyCount++;
                            elseif ($check['urgency_level'] === 'urgent') $urgentCount++;
                            else $routineCount++;
                        }
                        ?>
                        <div class="stat-card" style="background: linear-gradient(135deg, #ffe5e5, #ffcccc); padding: 1.5rem; border-radius: 12px; text-align: center;">
                            <div id="count-emergency" style="font-size: 2rem; font-weight: 700; color: #c0392b;"><?php echo $emergencyCount; ?></div>
                            <div style="font-size: 0.9rem; color: #666;">Emergency</div>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, #fff9e6, #ffecb3); padding: 1.5rem; border-radius: 12px; text-align: center;">
                            <div id="count-urgent" style="font-size: 2rem; font-weight: 700; color: #7d6608;"><?php echo $urgentCount; ?></div>
                            <div style="font-size: 0.9rem; color: #666;">Urgent</div>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); padding: 1.5rem; border-radius: 12px; text-align: center;">
                            <div id="count-routine" style="font-size: 2rem; font-weight: 700; color: #1565c0;"><?php echo $routineCount; ?></div>
                            <div style="font-size: 0.9rem; color: #666;">Routine</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($recentChecks)): ?>
                        <div class="recent-checks-list" id="historyList">
                            <?php foreach ($recentChecks as $check): ?>
                                <div class="recent-check-item">
                                    <div class="check-header">
                                        <div class="check-date">
                                            📅 <?php echo date('M d, Y g:i A', strtotime($check['created_at'])); ?>
                                        </div>
                                        <span class="urgency-badge urgency-<?php echo $check['urgency_level']; ?>">
                                            <?php echo strtoupper($check['urgency_level']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($check['matched_symptoms'])): ?>
                                        <div style="margin-bottom: 0.8rem;">
                                            <small style="color: #666; font-weight: 600;">🔍 Detected: </small>
                                            <small style="color: #26a69a; font-weight: 500;">
                                                <?php echo htmlspecialchars($check['matched_symptoms']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="check-symptoms">
                                        <?php echo htmlspecialchars(substr($check['symptoms'], 0, 120)); ?>
                                        <?php echo strlen($check['symptoms']) > 120 ? '...' : ''; ?>
                                    </div>
                                    
                                    <button class="btn btn-outline btn-sm" onclick="viewCheck(<?php echo $check['id']; ?>)">
                                        👁️ View Full Analysis
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($totalChecks > 10): ?>
                            <div style="text-align: center; margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e0e0e0;">
                                <p style="color: #666;">Showing 10 most recent checks out of <?php echo $totalChecks; ?> total</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div id="emptyState" class="empty-state" style="text-align: center; padding: 3rem 2rem;">
                            <div style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.5;">📋</div>
                            <h3 style="color: #666; font-size: 1.3rem; margin-bottom: 0.5rem;">No Symptom Checks Yet</h3>
                            <p style="color: #999; font-size: 1rem;">Your symptom check history will appear here after you complete your first analysis.</p>
                        </div>
                        
                        <div class="recent-checks-list" id="historyList" style="display:none;"></div>
                    <?php endif; ?>
                </div>
                
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span>💡</span> When to Seek Immediate Medical Attention
                    </h2>
                </div>
                <div class="card-body">
                    <div class="emergency-guidelines">
                        <div class="guideline-item">
                            <div class="guideline-icon">🚨</div>
                            <div class="guideline-content">
                                <h4>Chest Pain or Pressure</h4>
                                <p>Especially if spreading to arms, jaw, or back</p>
                            </div>
                        </div>
                        <div class="guideline-item">
                            <div class="guideline-icon">😮‍💨</div>
                            <div class="guideline-content">
                                <h4>Severe Breathing Difficulty</h4>
                                <p>Shortness of breath that's getting worse</p>
                            </div>
                        </div>
                        <div class="guideline-item">
                            <div class="guideline-icon">🤕</div>
                            <div class="guideline-content">
                                <h4>Sudden Severe Headache</h4>
                                <p>Worst headache of your life</p>
                            </div>
                        </div>
                        <div class="guideline-item">
                            <div class="guideline-icon">😵</div>
                            <div class="guideline-content">
                                <h4>Loss of Consciousness</h4>
                                <p>Fainting, confusion, or inability to wake up</p>
                            </div>
                        </div>
                        <div class="guideline-item">
                            <div class="guideline-icon">🩸</div>
                            <div class="guideline-content">
                                <h4>Severe Bleeding</h4>
                                <p>That doesn't stop with pressure</p>
                            </div>
                        </div>
                        <div class="guideline-item">
                            <div class="guideline-icon">⚡</div>
                            <div class="guideline-content">
                                <h4>Sudden Paralysis</h4>
                                <p>Weakness in face, arm, or leg</p>
                            </div>
                        </div>
                    </div>
                    <div class="emergency-footer">
                        <strong>🚑 Emergency Contact:</strong> 999 | 
                        <a href="appointment.php" class="link-primary">📅 Book Non-Emergency Appointment</a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="detailModal" class="modal">
        <div class="modal-backdrop" onclick="closeModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h3>📋 Symptom Check Details</h3>
                <button class="modal-close" onclick="closeModal()">✖</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script src="symptomChecker.js"></script>
</body>
</html>