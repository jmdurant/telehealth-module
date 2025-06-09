<?php
namespace Telehealth\Hooks;

use OpenEMR\Modules\Telehealth\Classes\JitsiClient;

// Define constants for event names in case the classes don't exist
define('TELEHEALTH_APPOINTMENT_SET_EVENT', 'appointment.set');

// Only use the classes if they exist
if (class_exists('\\OpenEMR\\Events\\Appointments\\AppointmentSetEvent')) {
    class_alias('\\OpenEMR\\Events\\Appointments\\AppointmentSetEvent', 'Telehealth\\Hooks\\AppointmentSetEventAlias');
} else {
    // Create a placeholder class if the OpenEMR class doesn't exist
    class AppointmentSetEventAlias {
        const EVENT_HANDLE = TELEHEALTH_APPOINTMENT_SET_EVENT;
    }
}

/**
 * Listens for a freshly created appointment. When the category corresponds to
 * Telehealth we automatically create a Jitsi meeting and store it in the
 * `telehealth_vc` table so the rest of the module (badges, invites, etc.) can
 * light up without manual intervention.
 *
 * This version is designed to be compatible with different OpenEMR versions.
 */
class AppointmentHooks
{
    /**
     * Register listener with the global event dispatcher.
     */
    public static function register()
    {
        global $eventDispatcher;
        // Only register if the event dispatcher exists and is an object
        if (isset($eventDispatcher) && is_object($eventDispatcher) && method_exists($eventDispatcher, 'addListener')) {
            $eventDispatcher->addListener(AppointmentSetEventAlias::EVENT_HANDLE, [self::class, 'onAppointmentSet'], 10);
        } else {
            error_log('Telehealth Module: Event dispatcher not available, appointment hooks not registered');
        }
    }

    /**
     * Handle the appointment.set event.
     * 
     * @param mixed $event The event object (type varies by OpenEMR version)
     * @return void
     */
    public static function onAppointmentSet($event)
    {
        // Extract the appointment ID from the event
        // Different OpenEMR versions may have different event structures
        $eid = 0;
        
        // Try to get eid from the event object
        if (is_object($event) && property_exists($event, 'eid')) {
            $eid = (int) $event->eid;
        } elseif (is_object($event) && method_exists($event, 'getEid')) {
            $eid = (int) $event->getEid();
        } elseif (is_array($event) && isset($event['eid'])) {
            $eid = (int) $event['eid'];
        }
        
        if ($eid <= 0) {
            error_log("Telehealth Module: No appointment ID found in event");
            return;
        }

        // Fetch appointment details - NOW INCLUDING the assigned provider ID
        $appt = sqlQuery('SELECT pc_catid, pc_pid, pc_aid FROM openemr_postcalendar_events WHERE pc_eid = ?', [$eid]);
        if (!$appt) {
            return; // nothing found
        }

        $catRow = sqlQuery('SELECT pc_catname FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$appt['pc_catid']]);
        $catName = strtolower($catRow['pc_catname'] ?? '');

        // Only continue for telehealth-type categories (adjust keywords as needed)
        if (strpos($catName, 'telehealth') === false && strpos($catName, 'teleconsulta') === false && strpos($catName, 'telesalud') === false) {
            return;
        }

        // Make sure our VC table exists (light schema) with backend_id and medic_id columns
        sqlStatement('CREATE TABLE IF NOT EXISTS telehealth_vc (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            encounter_id INT UNIQUE, 
            meeting_url VARCHAR(255), 
            medic_url VARCHAR(255), 
            patient_url VARCHAR(255),
            backend_id VARCHAR(255) NULL,
            medic_id VARCHAR(255) NULL,
            finished_at DATETIME NULL,
            created DATETIME DEFAULT NOW()
        )');

        // If a meeting already exists, do not recreate
        $row = sqlQuery('SELECT meeting_url FROM telehealth_vc WHERE encounter_id = ?', [$eid]);
        if ($row && !empty($row['meeting_url'])) {
            return;
        }

        // Build names & appointment date for backend if needed
        $patient = sqlQuery('SELECT fname, lname FROM patient_data WHERE pid = ?', [$appt['pc_pid']]);
        $patientName = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));

        // Get the ACTUAL provider assigned to this appointment (not the logged-in user)
        $provider = sqlQuery('SELECT fname, lname FROM users WHERE id = ?', [$appt['pc_aid']]);
        $providerName = trim(($provider['fname'] ?? '') . ' ' . ($provider['lname'] ?? ''));
        
        // Fallback to logged-in user if provider lookup fails (shouldn't happen in normal cases)
        if (empty($providerName)) {
            $currentUser = sqlQuery('SELECT fname, lname FROM users WHERE username = ?', [$_SESSION['authUser'] ?? '']);
            $providerName = trim(($currentUser['fname'] ?? '') . ' ' . ($currentUser['lname'] ?? '')) ?: 'Provider';
        }

        $apptDateRow = sqlQuery('SELECT pc_eventDate, pc_startTime FROM openemr_postcalendar_events WHERE pc_eid = ?', [$eid]);
        $appointmentDate = $apptDateRow ? ($apptDateRow['pc_eventDate'] . ' ' . $apptDateRow['pc_startTime']) : date('Y-m-d H:i:s');

        // Generate meeting link via helper
        $result = JitsiClient::createMeeting($eid, $appointmentDate, $providerName, $patientName);
        if (!$result['success']) {
            // Log or ignore for now
            return;
        }
        $medicUrl   = $result['medic_url'] ?? $result['meeting_url'];
        $patientUrl = $result['patient_url'] ?? $result['meeting_url'];

        // Get backend_id and medic_id if available  
        $backendId = $result['backend_id'] ?? null;
        $medicId = $result['medic_id'] ?? null;
        
        // Persist with backend_id and medic_id for real-time notifications and future API calls
        sqlStatement(
            'INSERT INTO telehealth_vc (encounter_id, meeting_url, medic_url, patient_url, backend_id, medic_id) 
             VALUES (?,?,?,?,?,?) 
             ON DUPLICATE KEY UPDATE 
                meeting_url=VALUES(meeting_url), 
                medic_url=VALUES(medic_url), 
                patient_url=VALUES(patient_url), 
                backend_id=VALUES(backend_id),
                medic_id=VALUES(medic_id)', 
            [$eid, $medicUrl, $medicUrl, $patientUrl, $backendId, $medicId]
        );

        // Optional: surface link in appointment comments (for legacy workflows)
        $comment = "Telehealth link: <a href=\"{$medicUrl}\" target=\"_blank\">Start</a> | <a href=\"{$patientUrl}\" target=\"_blank\">Patient</a>";
        sqlStatement('UPDATE openemr_postcalendar_events SET pc_hometext = ? WHERE pc_eid = ?', [$comment, $eid]);

        // Send invite immediately (email + optional SMS)
        require_once __DIR__ . '/../src/Classes/InviteHelper.php';
        $sendEmailResult = \Telehealth\Classes\InviteHelper::email($appt['pc_pid'], $eid, $patientUrl);

        if (!empty($GLOBALS['rem_sms'])) {
            \Telehealth\Classes\InviteHelper::sms($appt['pc_pid'], $eid, $patientUrl);
        }
    }
}
