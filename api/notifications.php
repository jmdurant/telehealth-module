<?php
/**
 * Telehealth Notifications Webhook Endpoint
 * 
 * COMPLETELY STANDALONE - No OpenEMR session dependencies
 * Direct database access only to avoid segfaults and session issues
 * 
 * @package OpenEMR
 * @subpackage Telehealth
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
        $stmt = $conn->prepare("INSERT INTO telehealth_vc_log (data_id, status, response, created) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $data_id, $topic, $message);
        $stmt->execute();
        $stmt->close();
        $conn->close();
}
    error_log("Telehealth webhook: [$topic] $message");
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
        case 'videoconsultation-started':
            updateAppointmentStatus($pc_eid, '@'); // Checked in
            logWebhookEvent($backend_id, $topic, "Visit started for appointment $pc_eid");
            break;
            
        case 'videoconsultation-finished':
            updateAppointmentStatus($pc_eid, '~'); // Completed
            
            // TODO: Get evolution/notes from telesalud API here
            // For now, create a basic note
            $notes = "Telehealth consultation completed via telesalud platform.\nConsultation ID: $backend_id\nCompleted: " . date('Y-m-d H:i:s');
            
            createEncounterForm($pid, $encounter_id, $notes, $backend_id);
            logWebhookEvent($backend_id, $topic, "Visit completed for appointment $pc_eid, encounter $encounter_id");
            break;
            
        case 'medic-set-attendance':
            logWebhookEvent($backend_id, $topic, "Provider joined visit $pc_eid");
            break;
            
        case 'medic-unset-attendance':
            logWebhookEvent($backend_id, $topic, "Provider left visit $pc_eid");
            break;
            
        case 'patient-set-attendance':
            logWebhookEvent($backend_id, $topic, "Patient joined visit $pc_eid");
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
