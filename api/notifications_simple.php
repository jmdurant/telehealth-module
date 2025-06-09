<?php
/**
 * Telehealth Notifications Webhook Endpoint - Standalone Version
 * 
 * COMPLETELY STANDALONE - No OpenEMR session dependencies
 * Direct database access only to avoid session issues
 */

// Standalone database configuration
$DB_HOST = $_ENV['DB_HOST'] ?? 'mysql';
$DB_USER = $_ENV['DB_USER'] ?? 'openemr';
$DB_PASS = $_ENV['DB_PASS'] ?? 'openemr';
$DB_NAME = $_ENV['DB_NAME'] ?? 'openemr';

// Log all webhook calls for debugging
error_log("Telehealth webhook called: " . file_get_contents('php://input'));

/**
 * Standalone database connection
 */
function getDbConnection()
{
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    
    if ($conn->connect_error) {
        error_log("Telehealth webhook DB error: " . $conn->connect_error);
        return null;
    }
    
    return $conn;
}

/**
 * Log webhook events
 */
function logWebhookEvent($data_id, $topic, $message)
{
    $conn = getDbConnection();
    if ($conn) {
        $stmt = $conn->prepare("INSERT INTO telehealth_vc_log (data_id, status, response) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $data_id, $topic, $message);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
    error_log("Telehealth webhook: [$topic] $message");
}

/**
 * Get uploaded files information for the encounter
 */
function getUploadedFiles($pid, $encounter_id, $backend_id)
{
    $conn = getDbConnection();
    if (!$conn) return [];
    
    // Get documents uploaded during this telehealth consultation
    $stmt = $conn->prepare("
        SELECT d.name, d.type, d.date, d.size, d.url
        FROM documents d 
        JOIN categories c ON d.category_id = c.id 
        WHERE d.foreign_id = ? 
        AND d.docdate >= (
            SELECT DATE(created) 
            FROM telehealth_vc 
            WHERE data_id = ? OR backend_id = ?
        )
        AND c.name = 'Teleconsultas'
        ORDER BY d.date DESC
    ");
    $stmt->bind_param("iss", $pid, $backend_id, $backend_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $files = [];
    while ($row = $result->fetch_assoc()) {
        $files[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $files;
}

/**
 * Get evolution data from telehealth_vc table
 */
function getEvolutionData($backend_id)
{
    $conn = getDbConnection();
    if (!$conn) return '';
    
    $stmt = $conn->prepare("
        SELECT evolution 
        FROM telehealth_vc 
        WHERE data_id = ? OR backend_id = ?
    ");
    $stmt->bind_param("ss", $backend_id, $backend_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $data['evolution'] ?? '';
}

/**
 * Get appointment data from backend ID
 */
function getAppointmentData($backend_id)
{
    $conn = getDbConnection();
    if (!$conn) return null;
    
    $stmt = $conn->prepare("
        SELECT vc.pc_eid, vc.encounter, vc.medic_secret, e.pc_pid 
        FROM telehealth_vc vc 
        JOIN openemr_postcalendar_events e ON vc.pc_eid = e.pc_eid 
        WHERE vc.data_id = ? OR vc.backend_id = ?
    ");
    $stmt->bind_param("ss", $backend_id, $backend_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $data;
}

/**
 * Update appointment status
 */
function updateAppointmentStatus($pc_eid, $status)
{
    $conn = getDbConnection();
    if (!$conn) return false;
    
    $stmt = $conn->prepare("UPDATE openemr_postcalendar_events SET pc_apptstatus = ? WHERE pc_eid = ?");
    $stmt->bind_param("si", $status, $pc_eid);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    
    return $result;
}

/**
 * Create encounter form for telehealth notes
 */
function createEncounterForm($pid, $encounter_id, $notes, $backend_id)
{
    $conn = getDbConnection();
    if (!$conn) return false;
    
    // Insert into form_telehealth_notes table
    $stmt = $conn->prepare("
        INSERT INTO form_telehealth_notes 
        (date, pid, encounter, user, groupname, activity, evolution_text, backend_id, visit_type) 
        VALUES (NOW(), ?, ?, 'telehealth-system', 'Default', 1, ?, ?, 'Telehealth Consultation')
    ");
    $stmt->bind_param("iiss", $pid, $encounter_id, $notes, $backend_id);
    $stmt->execute();
    $form_table_id = $conn->insert_id;
    $stmt->close();
    
    if ($form_table_id) {
        // Insert into forms registry table
        $stmt = $conn->prepare("
            INSERT INTO forms 
            (date, encounter, form_name, form_id, pid, user, groupname, formdir) 
            VALUES (NOW(), ?, 'Telehealth Visit Notes', ?, ?, 'telehealth-system', 'Default', 'telehealth_notes')
        ");
        $stmt->bind_param("iii", $encounter_id, $form_table_id, $pid);
        $stmt->execute();
        $forms_id = $conn->insert_id;
        $stmt->close();
        
        logWebhookEvent($backend_id, 'form_created', "Created encounter form ID: $forms_id for encounter: $encounter_id");
    }
    
    $conn->close();
    return $form_table_id;
}

/**
 * Store real-time notification for frontend toast display
 */
function storeRealtimeNotification($pc_eid, $pid, $encounter_id, $topic, $title, $message, $backend_id)
{
    $conn = getDbConnection();
    if (!$conn) return false;
    
    // Get patient name for the notification
    $stmt = $conn->prepare("
        SELECT CONCAT(fname, ' ', lname) as patient_name
        FROM patient_data 
        WHERE pid = ?
    ");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient_data = $result->fetch_assoc();
    $stmt->close();
    
    $patient_name = $patient_data['patient_name'] ?? 'Unknown Patient';
    
    // Store the notification
    $stmt = $conn->prepare("
        INSERT INTO telehealth_realtime_notifications 
        (pc_eid, pid, encounter_id, backend_id, topic, title, message, patient_name, created_at, is_read) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
    ");
    $stmt->bind_param("iiisssss", $pc_eid, $pid, $encounter_id, $backend_id, $topic, $title, $message, $patient_name);
    $result = $stmt->execute();
    $notification_id = $conn->insert_id;
    $stmt->close();
    $conn->close();
    
    if ($result) {
        logWebhookEvent($backend_id, 'notification_stored', "Stored real-time notification ID: $notification_id - $title");
    }
    
    return $notification_id;
}

/**
 * Process webhook notification
 */
function processNotification($data)
{
    $topic = $data['topic'] ?? '';
    $vc_data = $data['vc'] ?? [];
    
    // Get backend ID from either id or secret field
    $backend_id = $vc_data['id'] ?? $vc_data['secret'] ?? null;
    
    if (!$backend_id) {
        logWebhookEvent('unknown', 'error', 'No backend ID found in webhook data');
        return ['success' => false, 'message' => 'Missing backend ID'];
    }
    
    logWebhookEvent($backend_id, $topic, "Processing notification: $topic");
    
    // Get appointment data
    $appointmentData = getAppointmentData($backend_id);
    if (!$appointmentData) {
        logWebhookEvent($backend_id, 'error', 'Appointment not found for backend ID');
        return ['success' => false, 'message' => 'Appointment not found'];
    }
    
    $pc_eid = $appointmentData['pc_eid'];
    $encounter_id = $appointmentData['encounter'];
    $pid = $appointmentData['pc_pid'];
    
    // Process different notification types
    switch ($topic) {
        case 'patient-set-attendance':
            // Store real-time notification for providers
            storeRealtimeNotification(
                $pc_eid, 
                $pid, 
                $encounter_id, 
                'patient-waiting',
                '🟢 Patient Waiting',
                'Patient has joined the waiting room and is ready to start the consultation.',
                $backend_id
            );
            logWebhookEvent($backend_id, $topic, "Patient joined visit $pc_eid - notification stored");
            break;
            
        case 'medic-set-attendance':
            storeRealtimeNotification(
                $pc_eid, 
                $pid, 
                $encounter_id, 
                'provider-joined',
                '👨‍⚕️ Provider Joined',
                'Healthcare provider has entered the consultation room.',
                $backend_id
            );
            logWebhookEvent($backend_id, $topic, "Provider joined visit $pc_eid - notification stored");
            break;
            
        case 'videoconsultation-started':
            updateAppointmentStatus($pc_eid, '@'); // Checked in
            storeRealtimeNotification(
                $pc_eid, 
                $pid, 
                $encounter_id, 
                'consultation-started',
                '🟡 Consultation Started',
                'The telehealth consultation has officially begun with both participants present.',
                $backend_id
            );
            logWebhookEvent($backend_id, $topic, "Visit started for appointment $pc_eid - notification stored");
            break;
            
        case 'medic-unset-attendance':
            storeRealtimeNotification(
                $pc_eid, 
                $pid, 
                $encounter_id, 
                'provider-left',
                '⚠️ Provider Left',
                'Healthcare provider has left the consultation room.',
                $backend_id
            );
            logWebhookEvent($backend_id, $topic, "Provider left visit $pc_eid - notification stored");
            break;
            
        case 'videoconsultation-finished':
            updateAppointmentStatus($pc_eid, '~'); // Completed
            
            // ✅ ENHANCED: Get evolution data from webhook OR database
            $evolution_text = $vc_data['evolution'] ?? '';
            
            // If no evolution in webhook, try to get from database
            if (empty($evolution_text)) {
                $evolution_text = getEvolutionData($backend_id);
                logWebhookEvent($backend_id, 'evolution_source', "Evolution data retrieved from database");
            } else {
                logWebhookEvent($backend_id, 'evolution_source', "Evolution data found in webhook payload");
            }
            
            if (!empty($evolution_text)) {
                // Use the actual clinical notes from the doctor
                $notes = "TELEHEALTH CONSULTATION NOTES\n";
                $notes .= "=================================\n\n";
                $notes .= "CLINICAL NOTES:\n";
                $notes .= str_repeat("-", 40) . "\n";
                $notes .= $evolution_text . "\n\n";
                
                // Check for uploaded files
                $uploadedFiles = getUploadedFiles($pid, $encounter_id, $backend_id);
                if (!empty($uploadedFiles)) {
                    $notes .= str_repeat("-", 40) . "\n";
                    $notes .= "UPLOADED DOCUMENTS:\n";
                    foreach ($uploadedFiles as $file) {
                        $notes .= "• " . $file['name'] . " (" . $file['type'] . ")\n";
                        $notes .= "  Uploaded: " . date('Y-m-d H:i:s', strtotime($file['date'])) . "\n";
                    }
                    $notes .= "\n";
                    logWebhookEvent($backend_id, 'files_found', "Found " . count($uploadedFiles) . " uploaded files");
                }
                
                $notes .= str_repeat("-", 40) . "\n";
                $notes .= "CONSULTATION DETAILS:\n";
                $notes .= "• Consultation ID: $backend_id\n";
                $notes .= "• Completed: " . date('Y-m-d H:i:s') . "\n";
                $notes .= "• Platform: Telesalud Videoconsultation\n";
                $notes .= "• Notes Source: " . (empty($vc_data['evolution']) ? "Database" : "Real-time") . "\n";
                if (!empty($uploadedFiles)) {
                    $notes .= "• Attachments: " . count($uploadedFiles) . " file(s) uploaded\n";
                }
                
                logWebhookEvent($backend_id, 'clinical_notes', "Clinical notes captured: " . strlen($evolution_text) . " characters");
            } else {
                // Fallback to basic note if no clinical content available
                $notes = "TELEHEALTH CONSULTATION COMPLETED\n";
                $notes .= "=================================\n\n";
                $notes .= "A telehealth consultation was completed via the telesalud platform.\n\n";
                $notes .= "⚠️ No clinical notes were captured during this consultation.\n";
                $notes .= "Please manually add clinical notes if needed.\n\n";
                $notes .= "CONSULTATION DETAILS:\n";
                $notes .= "• Consultation ID: $backend_id\n";
                $notes .= "• Completed: " . date('Y-m-d H:i:s') . "\n";
                $notes .= "• Platform: Telesalud Videoconsultation\n";
                
                logWebhookEvent($backend_id, 'warning', "No clinical notes available from either webhook or database");
            }
            
            createEncounterForm($pid, $encounter_id, $notes, $backend_id);
            
            storeRealtimeNotification(
                $pc_eid, 
                $pid, 
                $encounter_id, 
                'consultation-finished',
                '⚫ Consultation Completed',
                'The telehealth consultation has been completed. Clinical notes have been saved to the patient record.',
                $backend_id
            );
            
            logWebhookEvent($backend_id, $topic, "Visit completed for appointment $pc_eid, encounter $encounter_id. Clinical notes: " . (empty($evolution_text) ? "none captured" : strlen($evolution_text) . " chars") . " - encounter form created");
            break;
            
        default:
            logWebhookEvent($backend_id, 'unknown_topic', "Unknown topic: $topic");
            break;
    }
    
    return ['success' => true, 'message' => "Processed $topic for appointment $pc_eid"];
}

// Main execution
header('Content-Type: application/json');

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    if (!$data) {
        $response = ['success' => false, 'message' => 'Invalid JSON data'];
    } else {
        $response = processNotification($data);
    }
    
} catch (Exception $e) {
    error_log("Telehealth webhook exception: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'Internal error'];
}

echo json_encode($response);
?> 