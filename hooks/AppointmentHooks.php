<?php
namespace Telehealth\Hooks;

use OpenEMR\Events\Appointments\AppointmentSetEvent;
use Telehealth\Classes\JitsiClient;

/**
 * Listens for a freshly created appointment. When the category corresponds to
 * Telehealth we automatically create a Jitsi meeting and store it in the
 * `telehealth_vc` table so the rest of the module (badges, invites, etc.) can
 * light up without manual intervention.
 */
class AppointmentHooks
{
    /**
     * Register listener with the global event dispatcher.
     */
    public static function register(): void
    {
        global $eventDispatcher;
        if (isset($eventDispatcher)) {
            $eventDispatcher->addListener(AppointmentSetEvent::EVENT_HANDLE, [self::class, 'onAppointmentSet'], 10);
        }
    }

    /**
     * Handle the appointment.set event.
     */
    public static function onAppointmentSet(AppointmentSetEvent $event): void
    {
        // Appointment ID is stuffed in by add_edit_event.php after save.
        $eid = (int) ($event->eid ?? 0);
        if ($eid <= 0) {
            return;
        }

        // Fetch appointment details
        $appt = sqlQuery('SELECT pc_catid, pc_pid FROM openemr_postcalendar_events WHERE pc_eid = ?', [$eid]);
        if (!$appt) {
            return; // nothing found
        }

        $catRow = sqlQuery('SELECT pc_catname FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$appt['pc_catid']]);
        $catName = strtolower($catRow['pc_catname'] ?? '');

        // Only continue for telehealth-type categories (adjust keywords as needed)
        if (strpos($catName, 'telehealth') === false && strpos($catName, 'teleconsulta') === false && strpos($catName, 'telesalud') === false) {
            return;
        }

        // Make sure our VC table exists (light schema)
        sqlStatement('CREATE TABLE IF NOT EXISTS telehealth_vc (id INT AUTO_INCREMENT PRIMARY KEY, encounter_id INT UNIQUE, meeting_url VARCHAR(255), medic_url VARCHAR(255), patient_url VARCHAR(255), created DATETIME DEFAULT NOW())');

        // If a meeting already exists, do not recreate
        $row = sqlQuery('SELECT meeting_url FROM telehealth_vc WHERE encounter_id = ?', [$eid]);
        if ($row && !empty($row['meeting_url'])) {
            return;
        }

        // Build names & appointment date for backend if needed
        $patient = sqlQuery('SELECT fname, lname FROM patient_data WHERE pid = ?', [$appt['pc_pid']]);
        $patientName = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));

        $apptDateRow = sqlQuery('SELECT pc_eventDate, pc_startTime FROM openemr_postcalendar_events WHERE pc_eid = ?', [$eid]);
        $appointmentDate = $apptDateRow ? ($apptDateRow['pc_eventDate'] . ' ' . $apptDateRow['pc_startTime']) : date('Y-m-d H:i:s');

        $providerName = $_SESSION['authUser'] ?? 'Provider';

        // Generate meeting link via helper
        $result = JitsiClient::createMeeting($eid, $appointmentDate, $providerName, $patientName);
        if (!$result['success']) {
            // Log or ignore for now
            return;
        }
        $medicUrl   = $result['medic_url'] ?? $result['meeting_url'];
        $patientUrl = $result['patient_url'] ?? $result['meeting_url'];

        // Persist
        sqlStatement('INSERT INTO telehealth_vc (encounter_id, meeting_url, medic_url, patient_url) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE meeting_url=VALUES(meeting_url), medic_url=VALUES(medic_url), patient_url=VALUES(patient_url)', [$eid, $medicUrl, $medicUrl, $patientUrl]);

        // Optional: surface link in appointment comments (for legacy workflows)
        $comment = "Telehealth link: <a href=\"{$medicUrl}\" target=\"_blank\">Start</a> | <a href=\"{$patientUrl}\" target=\"_blank\">Patient</a>";
        sqlStatement('UPDATE openemr_postcalendar_events SET pc_hometext = ? WHERE pc_eid = ?', [$comment, $eid]);

        // Send invite immediately (email + optional SMS)
        require_once __DIR__ . '/../classes/InviteHelper.php';
        $sendEmailResult = \Telehealth\Classes\InviteHelper::email($appt['pc_pid'], $eid, $patientUrl);

        if (!empty($GLOBALS['rem_sms'])) {
            \Telehealth\Classes\InviteHelper::sms($appt['pc_pid'], $eid, $patientUrl);
        }
    }
}
