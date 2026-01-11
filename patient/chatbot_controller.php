<?php
if (!function_exists('logDebug')) {
    function logDebug($message) {
        $logFile = __DIR__ . '/logs/chatbot_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
}

class ChatbotScopeController {
    private $db;
    
    public function __construct($db_connection) {
        $this->db = $db_connection;
        logDebug("Controller initialized");
    }
    
    /* Get menu option to scope ID mapping */
    private function getMenuScopeMapping() {
        return [
            '1' => 3,  // Book Appointment
            '2' => 6,  // Clinic Hours & Location  
            '3' => 10, // Find Doctor
            '4' => 8,  // Services We Offer
            '5' => 17, // Payment Methods
            '6' => 9,  // Contact Information
            '7' => 12, // General Health Tips
            '8' => 13, // Vaccination Information
            '9' => 2   // Help & Capabilities (Talk to Staff)
        ];
    }
    
    /* Check if message contains restricted content */
    private function checkRestrictions($message) {
        logDebug("Checking restrictions for: " . substr($message, 0, 50));
        
        try {
            $result = $this->db->query("
                SELECT restriction_id, topic_name, keywords, restriction_reason, redirect_message, severity, log_attempt
                FROM chatbot_restricted_topics
                WHERE is_active = TRUE
                ORDER BY severity DESC
            ");
            
            $restrictions = [];
            while ($row = $result->fetch_assoc()) {
                $restrictions[] = $row;
            }
            
            logDebug("Found " . count($restrictions) . " restrictions");
            
            $message_lower = strtolower($message);
            
            foreach ($restrictions as $restriction) {
                $keywords = explode(',', strtolower($restriction['keywords']));
                foreach ($keywords as $keyword) {
                    $keyword = trim($keyword);
                    if (!empty($keyword) && strpos($message_lower, $keyword) !== false) {
                        logDebug("Restriction matched: " . $restriction['topic_name']);
                        return array(
                            'is_restricted' => true,
                            'restriction' => $restriction
                        );
                    }
                }
            }
        } catch (Exception $e) {
            logDebug("Restriction check error: " . $e->getMessage());
        }
        
        logDebug("No restrictions matched");
        return array('is_restricted' => false, 'restriction' => null);
    }
    
    /* Find matching scope in database */
    private function findMatchingScope($message, $is_logged_in) {
        logDebug("Finding scope for message, logged_in: " . ($is_logged_in ? 'yes' : 'no'));
        
        try {
            // UPDATE: Changed 'id' to 'chatbot_scope_id'
            $result = $this->db->query("
                SELECT chatbot_scope_id, category, topic, keywords, allowed_response_type, 
                       response_template, max_detail_level,
                       requires_login, priority
                FROM chatbot_scope
                WHERE is_active = TRUE
                ORDER BY priority DESC
            ");
            
            $scopes = [];
            while ($row = $result->fetch_assoc()) {
                $scopes[] = $row;
            }
            
            logDebug("Found " . count($scopes) . " scopes");
            
            $message_lower = strtolower($message);
            $best_match = null;
            $best_match_count = 0;
            
            foreach ($scopes as $scope) {
                if ($scope['requires_login'] && !$is_logged_in) {
                    continue;
                }
                
                $keywords = explode(',', strtolower($scope['keywords']));
                $match_count = 0;
                
                foreach ($keywords as $keyword) {
                    $keyword = trim($keyword);
                    if (!empty($keyword) && strpos($message_lower, $keyword) !== false) {
                        $match_count++;
                    }
                }
                
                if ($match_count > $best_match_count) {
                    $best_match_count = $match_count;
                    $best_match = $scope;
                }
            }
            
            if ($best_match) {
                logDebug("Matched scope: " . $best_match['topic'] . " (matches: $best_match_count)");
            } else {
                logDebug("No scope matched");
            }
            
            return $best_match;
        } catch (Exception $e) {
            logDebug("Scope matching error: " . $e->getMessage());
            return null;
        }
    }
    
    /* Get interactive menu for unmatched queries */
    private function getInteractiveMenu() {
        return "I'm here to help! Please choose from the following options:\n\n" .
            "1️⃣ Book an Appointment\n" .
            "2️⃣ Clinic Hours & Location\n" .
            "3️⃣ Find a Doctor\n" .
            "4️⃣ Services We Offer\n" .
            "5️⃣ Payment Methods\n" .
            "6️⃣ Contact Information\n" .
            "7️⃣ General Health Tips\n" .
            "8️⃣ Vaccination Information\n" .
            "9️⃣ Talk to a Staff Member\n\n" .
            "💬 Just type the number (1-9) or ask your question directly!";
    }
    
    /* Handle menu number selections (1-9) */
    private function handleMenuSelection($number, $is_logged_in) {
        $response = array(
            'reply' => '',
            'scope_id' => null
        );
        
        $scopeMap = $this->getMenuScopeMapping();
        $response['scope_id'] = $scopeMap[$number] ?? null;
        
        switch ($number) {
            case '1':
                $response['reply'] = $is_logged_in 
                    ? "📅 To book an appointment:\n\n1️⃣ Go to 'Book Appointment'\n2️⃣ Select a doctor and specialty\n3️⃣ Choose available date and time\n4️⃣ Confirm booking\n\nYou'll receive a confirmation with QR code! 📧"
                    : "📅 To book an appointment, please log in first!\n\n1️⃣ Log in to your account\n2️⃣ Go to 'Book Appointment'\n3️⃣ Select a doctor and specialty\n4️⃣ Choose available date and time";
                break;
                
            case '2':
                $response['reply'] = "🏥 CarePlus Clinic Hours:\n\n📆 Monday - Saturday: 9:00 AM - 8:00 PM\n🚫 Sunday & Public Holidays: Closed\n\n📍 Location:\nKlinik Careclinics Ipoh\n1, Jln Sultan Nazrin Shah,\nMedan Gopeng, 31350 Ipoh, Perak";
                break;
                
            case '3':
                $response['reply'] = "👨‍⚕️ Find a Doctor:\n\n1️⃣ Visit our 'Find a Doctor' page\n2️⃣ Filter by specialty or name\n3️⃣ View doctor profiles and ratings\n4️⃣ Book directly with your preferred doctor\n\nWhat specialty are you looking for?";
                break;
                
            case '4':
                $response['reply'] = "🏥 Our Services:\n\n✅ General Consultation\n✅ Specialist Care\n✅ Diagnostic Services\n✅ Lab Tests & X-Ray\n✅ Preventive Health Checkups\n✅ Vaccination Services\n✅ Minor Procedures\n✅ Medical Certificates\n\nWhich service would you like to know more about?";
                break;
                
            case '5':
               $response['reply'] = "💳 Payment Methods:\n\n💵 Cash\n📱 E-Wallets:\n  • Touch n Go eWallet\n   • Boost\n  💡 Payment is collected at the reception after consultation.\n📝 Receipts are provided for all transactions.";
                break;
                
            case '6':
                $response['reply'] = "📞 Contact CarePlus Clinic:\n\n☎️ Phone: +60 12-345 6789\n📧 Email: support@careplus.com\n💬 Live Chat: Available on website\n\n⏰ Office Hours:\nMon-Sat: 9:00 AM - 8:00 PM";
                break;
                
            case '7':
                $response['reply'] = "💪 General Health Tips:\n\n🥗 Balanced diet with fruits & vegetables\n🏃 Exercise 150 min/week\n💧 Drink 8 glasses of water daily\n😴 Get 7-9 hours of sleep\n🩺 Regular health checkups\n🧘 Manage stress levels\n🚭 Avoid smoking & excessive alcohol\n\n⚠️ For personalized advice, book a consultation!";
                break;
                
            case '8':
                $response['reply'] = "💉 Vaccination Services:\n\nWe offer:\n1️⃣ Flu vaccine (Annual)\n2️⃣ COVID-19 vaccines & boosters\n3️⃣ Hepatitis A & B\n4️⃣ Travel vaccines\n5️⃣ Childhood immunizations\n6️⃣ Pneumonia vaccine (65+)\n\n📅 Book an appointment to discuss your vaccination needs!";
                break;
                
            case '9':
                $response['reply'] = "👋 I'll connect you with our team!\n\nA staff member will reach out shortly. Meanwhile:\n\n📞 Call: +60 12-345 6789\n📧 Email: support@careplus.com\n\nYour inquiry has been logged! ✅\n\n⏰ Response time: Within 2-4 hours during office hours.";
                break;
                
            default:
                $response['reply'] = $this->getInteractiveMenu();
                $response['scope_id'] = null;
        }
        
        logDebug("Menu selection: $number -> Scope ID: " . ($response['scope_id'] ?? 'NULL'));
        return $response;
    }
    
    /* Log conversation to database */
    private function logToDatabase($patient_id, $session_id, $user_message, $bot_response, $matched_scope_id, $is_restricted, $restriction_reason) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO chatbot_logs (
                    patient_id, session_id, user_message, bot_response, 
                    matched_scope_id, is_restricted, restriction_reason, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $is_restricted_int = $is_restricted ? 1 : 0;
            
            // Debug log
            logDebug("Saving to DB - Scope ID: " . ($matched_scope_id ?? 'NULL') . 
                     ", Restricted: " . $is_restricted_int . 
                     ", Reason: " . ($restriction_reason ?? 'NULL'));
            
            $stmt->bind_param(
                "isssiis",
                $patient_id,
                $session_id,
                $user_message,
                $bot_response,
                $matched_scope_id,
                $is_restricted_int,
                $restriction_reason
            );
            
            $stmt->execute();
            $log_id = $stmt->insert_id;
            $stmt->close();
            
            logDebug("✅ Saved to database, log_id: " . $log_id);
            return $log_id;
        } catch (Exception $e) {
            logDebug("❌ DB save failed: " . $e->getMessage());
            return null;
        }
    }
    
    /* Main processing method */
    public function processMessage($user_message, $session_id, $patient_id) {
        logDebug("Processing message: " . substr($user_message, 0, 50));
        
        // Check restrictions first
        $restriction_check = $this->checkRestrictions($user_message);
        
        if ($restriction_check['is_restricted'] === true && 
            !empty($restriction_check['restriction']) && 
            is_array($restriction_check['restriction'])) {
            
            $restriction_array = $restriction_check['restriction'];
            $redirect_msg = $restriction_array['redirect_message'] ?? 'This topic is restricted.';
            $restriction_reason = $restriction_array['restriction_reason'] ?? 'general';
            
            $log_id = $this->logToDatabase(
                $patient_id,
                $session_id,
                $user_message,
                $redirect_msg,
                null,
                true,
                $restriction_reason
            );
            
            logDebug("Returning restricted response, log_id: $log_id");
            return array(
                'reply' => $redirect_msg,
                'is_restricted' => true,
                'restriction_reason' => $restriction_reason,
                'log_id' => $log_id
            );
        }
        
        // Initialize variables
        $is_logged_in = !empty($patient_id);
        $reply = '';
        $matched_scope_id = null;
        $matched_scope = null;
        
        // Check for menu number (1-9)
        $message_trimmed = trim($user_message);
        if (preg_match('/^[1-9]$/', $message_trimmed)) {
            // Handle menu selection
            $menuResponse = $this->handleMenuSelection($message_trimmed, $is_logged_in);
            $reply = $menuResponse['reply'];
            $matched_scope_id = $menuResponse['scope_id'];
            
            logDebug("Menu selection handled: $message_trimmed -> Scope ID: " . ($matched_scope_id ?? 'NULL'));
        }
        else {
            // Find matching scope
            $matched_scope = $this->findMatchingScope($user_message, $is_logged_in);
            
            if ($matched_scope && isset($matched_scope['response_template']) && !empty($matched_scope['response_template'])) {
                $reply = $matched_scope['response_template'];
                
                // UPDATE: Changed 'id' to 'chatbot_scope_id'
                $matched_scope_id = $matched_scope['chatbot_scope_id'];
                
                // Check if login required
                if (isset($matched_scope['requires_login']) && $matched_scope['requires_login'] && !$is_logged_in) {
                    $reply = "🔒 To access this information, please log in to your account.\n\nIf you don't have an account yet, you can register on our website!";
                    logDebug("Login required, redirecting to login message");
                }
                
                logDebug("Using database template, Scope ID: $matched_scope_id, length: " . strlen($reply));
            }
            else {
                // Show menu for unmatched
                $reply = $this->getInteractiveMenu();
                $matched_scope_id = null;
                logDebug("Showing interactive menu (no scope matched)");
            }
        }
        
        // Log to database
        $log_id = $this->logToDatabase(
            $patient_id,
            $session_id,
            $user_message,
            $reply,
            $matched_scope_id,
            false,
            null
        );
        
        logDebug("Returning successful response, log_id: $log_id, scope_id: " . ($matched_scope_id ?? 'NULL'));
        
        return array(
            'reply' => $reply,
            'matched_scope' => ($matched_scope && isset($matched_scope['topic'])) ? $matched_scope['topic'] : 'General',
            'scope_category' => ($matched_scope && isset($matched_scope['category'])) ? $matched_scope['category'] : 'General',
            'log_id' => $log_id,
            'scope_id' => $matched_scope_id
        );
    }
}
?>