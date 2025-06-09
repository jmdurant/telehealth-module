<?php
/**
 * Telehealth meeting launcher
 * Uses settings to determine the correct telehealth provider and launch the appropriate meeting
 * 
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Include OpenEMR globals
require_once __DIR__ . '/../../../../globals.php';
require_once("$srcdir/api.inc");
require_once("$srcdir/forms.inc");

use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Modules\Telehealth\Bootstrap;

// Include module bootstrap
require_once dirname(__DIR__) . "/openemr.bootstrap.php";

// Get parameters from request
$eid  = isset($_GET['eid']) ? intval($_GET['eid']) : 0;
$role = $_GET['role'] ?? 'provider';

// Validate appointment ID
if ($eid <= 0) {
    die('Invalid encounter id');
}

// Session check for providers
if ($role === 'provider' && empty($_SESSION['authUser'])) {
    die('Authentication required.');
}

/**
 * Create encounter for telehealth visit - following Comlink's proven pattern
 */
function createEcounter($pc_eid, $pid, $userID) {
    $appointmentService = new AppointmentService();
    $encounterService = new EncounterService();
    
    // Get or create encounter using OpenEMR's standard system
    $encounter = $appointmentService->getEncounterForAppointment($pc_eid, $pid);
    if (empty($encounter)) {
        // Create encounter for the appointment using OpenEMR's standard approach
        $encounterId = $appointmentService->createEncounterForAppointment($pc_eid);
        
        if (empty($encounterId)) {
            error_log("Telehealth: Failed to create encounter for appointment pc_eid=$pc_eid");
            return null;
        }
        
        error_log("Telehealth: Created new encounter ID: " . $encounterId . " for appointment pc_eid=$pc_eid");
        return $encounterId; // Return just the ID
    } else {
        // Extract the encounter ID from the encounter array
        $encounterId = is_array($encounter) ? $encounter['eid'] : $encounter;
        error_log("Telehealth: Using existing encounter ID: " . $encounterId . " for appointment pc_eid=$pc_eid");
        return $encounterId; // Return just the ID
    }
}

// Get the module bootstrap instance - FIXED to work with our implementation
try {
    // Try to get bootstrap from GLOBALS if it's set there
    if (isset($GLOBALS['telehealth_bootstrap']) && $GLOBALS['telehealth_bootstrap'] instanceof Bootstrap) {
        $bootstrap = $GLOBALS['telehealth_bootstrap'];
    } else {
        // Create a new bootstrap instance if needed
        $eventDispatcher = $GLOBALS['kernel']->getEventDispatcher() ?? null;
        $bootstrap = new Bootstrap($eventDispatcher);
        $GLOBALS['telehealth_bootstrap'] = $bootstrap; // Cache it for future use
    }
} catch (Exception $e) {
    error_log("Telehealth start.php: Error getting bootstrap: " . $e->getMessage());
    die('Telehealth module not properly loaded');
}

if (!$bootstrap) {
    die('Telehealth module not properly loaded');
}

// Get the settings utility
$settingsUtil = $bootstrap->getSettingsUtil();

// Fetch or create meeting link
$mode = $settingsUtil->getMode();

// Determine the appropriate field to use based on mode and role
if ($mode === 'telesalud') {
    $field = ($role === 'provider') ? 'medic_url' : 'patient_url';
} else {
    $field = 'meeting_url';
}

// Check if we already have a meeting URL for this encounter
$row = sqlQuery("SELECT $field AS url, backend_id, medic_id FROM telehealth_vc WHERE encounter = ?", [$eid]);

if ($row && !empty($row['url'])) {
    // Use existing meeting URL
    $meetingUrl = $row['url'];
} else {
    // Generate a new meeting URL based on settings
    
    // Get appointment and patient details
    $apptData = sqlQuery("SELECT pc_eventDate, pc_startTime, pc_aid, pc_pid FROM openemr_postcalendar_events WHERE pc_eid = ?", [$eid]);
    if (empty($apptData)) {
        die("Appointment not found");
    }
    
    // Create or get encounter for this appointment (only for providers)
    $encounterId = null;
    if ($role === 'provider') {
        $encounterId = createEcounter($eid, $apptData['pc_pid'], $_SESSION['authUserID'] ?? 1);
        if (empty($encounterId)) {
            error_log("Telehealth: Warning - Could not create encounter for appointment $eid");
        } else {
            error_log("Telehealth: Successfully created/found encounter $encounterId for appointment $eid");
            // Set the encounter context for the session
            PatientSessionUtil::setPid($apptData['pc_pid']);
            EncounterSessionUtil::setEncounter($encounterId);
        }
    }
    
    // Get provider and patient names
    $providerData = sqlQuery("SELECT fname, lname FROM users WHERE id = ?", [$apptData['pc_aid']]);
    $patientData = sqlQuery("SELECT fname, lname FROM patient_data WHERE pid = ?", [$apptData['pc_pid']]);
    
    $providerName = ($providerData['fname'] ?? '') . ' ' . ($providerData['lname'] ?? '');
    $patientName = ($patientData['fname'] ?? '') . ' ' . ($patientData['lname'] ?? '');
    
    // TEMPORARY FIX: Use hardcoded provider name for testing
    if (empty(trim($providerName)) || $providerName === ' ') {
        $providerName = 'Dr. Test Provider';
        error_log("Telehealth start.php DEBUG - Using fallback provider name: " . $providerName);
    }
    
    // FIX: Clean up provider name - remove extra spaces and ensure proper format
    $providerName = trim($providerName);
    if (empty($providerName)) {
        $providerName = 'Administrator';
    }
    
    // FIX: For "Administrator" users, use a more medical-sounding name for the backend
    if ($providerName === 'Administrator') {
        $providerName = 'Dr. Administrator';
    }
    
    error_log("Telehealth start.php DEBUG - Cleaned provider name: '" . $providerName . "'");
    
    // DEBUG: Log the provider information
    error_log("Telehealth start.php DEBUG - Current session user: " . ($_SESSION['authUser'] ?? 'none'));
    error_log("Telehealth start.php DEBUG - Appointment provider ID (pc_aid): " . ($apptData['pc_aid'] ?? 'none'));
    error_log("Telehealth start.php DEBUG - Provider data from DB: " . json_encode($providerData));
    error_log("Telehealth start.php DEBUG - Final provider name: " . $providerName);
    error_log("Telehealth start.php DEBUG - Patient name: " . $patientName);
    error_log("Telehealth start.php DEBUG - Role: " . $role);
    
    // Generate meeting URL based on the configured mode
    if ($settingsUtil->getMode() === 'telesalud') {
        // For telesalud mode, use the TelesaludClient to create a meeting
        $startTime = $apptData['pc_eventDate'] . ' ' . $apptData['pc_startTime'];
        
        try {
            // This should return an array with patient_url, medic_url, and backend info
            $meetingData = $settingsUtil->createTelesaludMeeting($eid, $providerName, $patientName, $startTime);
        
            if (is_array($meetingData)) {
                // Debug log the meeting data structure
                error_log("Telehealth start.php DEBUG - meetingData structure: " . print_r($meetingData, true));
                
                // Debug log the individual values being inserted
                $dbValues = [
                    $eid,  // pc_eid (appointment ID)
                    is_numeric($encounterId) ? (int)$encounterId : $eid,  // encounter (ensure it's a number)
                    (string)($meetingData['patient_url'] ?? ''), 
                    (string)($meetingData['medic_url'] ?? ''), 
                    (string)($meetingData['patient_url'] ?? ''), 
                    (string)($meetingData['backend_id'] ?? ''), // backend_id - correct field
                    (string)($meetingData['medic_id'] ?? ''), // medic_id - this contains the medic secret value
                    (string)($meetingData['backend_id'] ?? ''), // data_id - same as backend_id
                    (string)($meetingData['medic_id'] ?? '') // medic_secret - same as medic_id
                ];
                error_log("Telehealth start.php DEBUG - Database values: " . print_r($dbValues, true));
                
                // Store all the URLs and critical data in the database
                sqlStatement(
                    "INSERT INTO telehealth_vc (pc_eid, encounter, meeting_url, medic_url, patient_url, backend_id, medic_id, data_id, medic_secret) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE medic_url=VALUES(medic_url), patient_url=VALUES(patient_url), backend_id=VALUES(backend_id), medic_id=VALUES(medic_id), data_id=VALUES(data_id), medic_secret=VALUES(medic_secret)",
                    $dbValues
                );
                
                // Use the appropriate URL for the role
                $meetingUrl = ($role === 'provider') ? $meetingData['medic_url'] : $meetingData['patient_url'];
            } else {
                // Fallback to string URL if createTelesaludMeeting returns just a URL
                $meetingUrl = $meetingData;
                sqlStatement(
                    "INSERT INTO telehealth_vc (pc_eid, encounter, meeting_url, medic_url, patient_url) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE meeting_url=VALUES(meeting_url), medic_url=VALUES(medic_url), patient_url=VALUES(patient_url)",
                    [$eid, $encounterId ?? $eid, $meetingUrl, $meetingUrl, $meetingUrl]
                );
            }
        } catch (Exception $e) {
            error_log("Telehealth start.php: Error creating telesalud meeting: " . $e->getMessage());
            die("Error creating telehealth meeting: " . $e->getMessage());
        }
    } else {
        // For standalone mode, just generate a URL with our settings utility
        
        // Create a unique slug for the meeting
        $slug = bin2hex(random_bytes(5));
        
        // Format: EMRTelevisit-{appointmentId}-{randomHex}
        $roomSlug = 'EMRTelevisit-' . $eid . '-' . $slug;
        
        // Use the settings utility to generate the URL based on the configured provider
        $meetingUrl = $settingsUtil->getMeetingUrl($roomSlug);
        
        // Store the URL in the database (same URL for all roles in standalone mode)
        sqlStatement(
            "INSERT INTO telehealth_vc (pc_eid, encounter, meeting_url, medic_url, patient_url) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE meeting_url=VALUES(meeting_url), medic_url=VALUES(medic_url), patient_url=VALUES(patient_url)",
            [$eid, $encounterId ?? $eid, $meetingUrl, $meetingUrl, $meetingUrl]
        );
    }
}

// Validate the meeting URL before redirecting
if (empty($meetingUrl)) {
    die("Unable to generate meeting URL");
}

// Redirect to the meeting URL
header('Location: ' . $meetingUrl);
exit;
