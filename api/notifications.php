<?php
/**
 * Telehealth Notifications Webhook Endpoint
 * 
 * Receives webhook notifications from the telesalud backend about
 * videoconsultation status changes.
 * 
 * This endpoint should be configured as the NOTIFICATION_URL in the
 * telesalud backend's .env file.
 * 
 * @package OpenEMR
 * @subpackage Telehealth
 */

// Set up session and OpenEMR
require_once dirname(__FILE__, 4) . "/interface/globals.php";

// Include logger
require_once dirname(__FILE__, 2) . "/classes/Logger.php";
use Telehealth\Classes\Logger;

// Define notification topics
define('TOPIC_MEDIC_SET_ATTENDANCE', 'medic-set-attendance');
define('TOPIC_MEDIC_UNSET_ATTENDANCE', 'medic-unset-attendance');
define('TOPIC_VC_STARTED', 'videoconsultation-started');
define('TOPIC_VC_FINISHED', 'videoconsultation-finished');
define('TOPIC_PATIENT_SET_ATTENDANCE', 'patient-set-attendance');

/**
 * Process a notification from the telesalud backend
 * 
 * @param array $data The notification data
 * @return void
 */
function processNotification($data) {
    $logger = new Logger();
    
    // Validate required fields
    if (!isset($data['topic']) || !isset($data['vc'])) {
        $logger->error("Invalid notification data: " . json_encode($data));
        return;
    }
    
    $topic = $data['topic'];
    $vc = $data['vc'];
    
    // Log the notification
    $logger->info("Received notification: $topic for VC ID: " . ($vc['id'] ?? 'unknown'));
    
    // Get encounter ID from backend_id
    $encounterId = getEncounterIdFromBackendId($vc['id'] ?? null);
    if (!$encounterId) {
        $logger->error("Could not find encounter for backend_id: " . ($vc['id'] ?? 'unknown'));
        return;
    }
    
    // Process based on topic
    switch ($topic) {
        case TOPIC_PATIENT_SET_ATTENDANCE:
            handlePatientJoined($encounterId, $vc);
            break;
            
        case TOPIC_MEDIC_SET_ATTENDANCE:
            handleProviderJoined($encounterId, $vc);
            break;
            
        case TOPIC_VC_STARTED:
            handleConsultationStarted($encounterId, $vc);
            break;
            
        case TOPIC_VC_FINISHED:
            handleConsultationFinished($encounterId, $vc);
            break;
            
        case TOPIC_MEDIC_UNSET_ATTENDANCE:
            handleProviderLeft($encounterId, $vc);
            break;
            
        default:
            $logger->warning("Unknown notification topic: $topic");
            break;
    }
}

/**
 * Get encounter ID from backend_id
 * 
 * @param string $backendId The backend ID
 * @return int|null The encounter ID or null if not found
 */
function getEncounterIdFromBackendId($backendId) {
    if (!$backendId) {
        return null;
    }
    
    $sql = "SELECT pc_eid FROM telehealth_vc WHERE backend_id = ?";
    $result = sqlQuery($sql, [$backendId]);
    
    return $result['pc_eid'] ?? null;
}

/**
 * Handle patient joined notification
 * 
 * @param int $encounterId The encounter ID
 * @param array $vc The videoconsultation data
 * @return void
 */
function handlePatientJoined($encounterId, $vc) {
    $logger = new Logger();
    $logger->info("Patient joined waiting room for encounter: $encounterId");
    
    // Update appointment status to "@" (patient arrived)
    $sql = "UPDATE openemr_postcalendar_events SET pc_apptstatus = '@' WHERE pc_eid = ?";
    sqlStatement($sql, [$encounterId]);
    
    // Log the event
    sqlStatement(
        "INSERT INTO telehealth_vc_log (data_id, status, response) VALUES (?, ?, ?)",
        [$vc['id'] ?? '', 'patient-arrived', json_encode($vc)]
    );
}

/**
 * Handle provider joined notification
 * 
 * @param int $encounterId The encounter ID
 * @param array $vc The videoconsultation data
 * @return void
 */
function handleProviderJoined($encounterId, $vc) {
    $logger = new Logger();
    $logger->info("Provider joined consultation for encounter: $encounterId");
    
    // Log the event
    sqlStatement(
        "INSERT INTO telehealth_vc_log (data_id, status, response) VALUES (?, ?, ?)",
        [$vc['id'] ?? '', 'provider-joined', json_encode($vc)]
    );
}

/**
 * Handle consultation started notification
 * 
 * @param int $encounterId The encounter ID
 * @param array $vc The videoconsultation data
 * @return void
 */
function handleConsultationStarted($encounterId, $vc) {
    $logger = new Logger();
    $logger->info("Consultation started for encounter: $encounterId");
    
    // Update appointment status to ">" (in progress)
    $sql = "UPDATE openemr_postcalendar_events SET pc_apptstatus = '>' WHERE pc_eid = ?";
    sqlStatement($sql, [$encounterId]);
    
    // Log the event
    sqlStatement(
        "INSERT INTO telehealth_vc_log (data_id, status, response) VALUES (?, ?, ?)",
        [$vc['id'] ?? '', 'consultation-started', json_encode($vc)]
    );
}

/**
 * Handle consultation finished notification
 * 
 * @param int $encounterId The encounter ID
 * @param array $vc The videoconsultation data
 * @return void
 */
function handleConsultationFinished($encounterId, $vc) {
    $logger = new Logger();
    $logger->info("Consultation finished for encounter: $encounterId");
    
    // Update appointment status to "$" (completed)
    $sql = "UPDATE openemr_postcalendar_events SET pc_apptstatus = '$' WHERE pc_eid = ?";
    sqlStatement($sql, [$encounterId]);
    
    // Save clinical notes if available
    if (isset($vc['evolution']) && !empty($vc['evolution'])) {
        saveEvolution($encounterId, $vc['evolution']);
    }
    
    // Log the event
    sqlStatement(
        "INSERT INTO telehealth_vc_log (data_id, status, response) VALUES (?, ?, ?)",
        [$vc['id'] ?? '', 'consultation-finished', json_encode($vc)]
    );
}

/**
 * Handle provider left notification
 * 
 * @param int $encounterId The encounter ID
 * @param array $vc The videoconsultation data
 * @return void
 */
function handleProviderLeft($encounterId, $vc) {
    $logger = new Logger();
    $logger->info("Provider left consultation for encounter: $encounterId");
    
    // Log the event
    sqlStatement(
        "INSERT INTO telehealth_vc_log (data_id, status, response) VALUES (?, ?, ?)",
        [$vc['id'] ?? '', 'provider-left', json_encode($vc)]
    );
}

/**
 * Save evolution (clinical notes) to the encounter
 * 
 * @param int $encounterId The encounter ID
 * @param string $evolution The clinical notes
 * @return void
 */
function saveEvolution($encounterId, $evolution) {
    // Get patient ID from encounter
    $sql = "SELECT pc_pid FROM openemr_postcalendar_events WHERE pc_eid = ?";
    $result = sqlQuery($sql, [$encounterId]);
    $pid = $result['pc_pid'] ?? null;
    
    if (!$pid) {
        return;
    }
    
    // Save as a clinical note
    $note = [
        'pid' => $pid,
        'encounter' => $encounterId,
        'note' => $evolution,
        'date' => date('Y-m-d H:i:s'),
        'user' => $_SESSION['authUser'] ?? 'system',
        'groupname' => $_SESSION['authProvider'] ?? 'Default',
        'authorized' => 1,
        'activity' => 1,
        'title' => 'Telehealth Consultation Notes',
    ];
    
    sqlStatement(
        "INSERT INTO pnotes (pid, encounter, note, date, user, groupname, authorized, activity, title) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$note['pid'], $note['encounter'], $note['note'], $note['date'], $note['user'], $note['groupname'], $note['authorized'], $note['activity'], $note['title']]
    );
    
    // Update telehealth_vc table
    sqlStatement(
        "UPDATE telehealth_vc SET evolution = ? WHERE pc_eid = ?",
        [$evolution, $encounterId]
    );
}

// Main execution
$logger = new Logger();

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get notification data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $logger->error("Invalid JSON received: $input");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Verify token if configured
$notificationToken = $GLOBALS['telesalud_notification_token'] ?? '';
if (!empty($notificationToken)) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) || $authHeader !== "Bearer $notificationToken") {
        $logger->error("Invalid token in notification request");
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Process the notification
try {
    processNotification($data);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $logger->error("Error processing notification: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
