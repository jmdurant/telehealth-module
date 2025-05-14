<?php
namespace Telehealth\Hooks;

// Define constants for event names in case the classes don't exist
define('TELEHEALTH_APPOINTMENT_RENDER_BELOW_PATIENT', 'appointment.render.below_patient');
define('TELEHEALTH_APPOINTMENT_VIEW_EVENT', 'appointment.view');

// Only use the classes if they exist
if (class_exists('\\OpenEMR\\Events\\Appointments\\AppointmentRenderEvent')) {
    class_alias('\\OpenEMR\\Events\\Appointments\\AppointmentRenderEvent', 'Telehealth\\Hooks\\AppointmentRenderEventAlias');
} else {
    // Create a placeholder class if the OpenEMR class doesn't exist
    class AppointmentRenderEventAlias {
        const RENDER_BELOW_PATIENT = TELEHEALTH_APPOINTMENT_RENDER_BELOW_PATIENT;
    }
}

use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Events\Appointments\CalendarUserGetEventsFilter;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Common\Logging\SystemLogger;

/**
 * Injects Telehealth meeting links into calendar appointment pop-ups.
 *
 * This version is designed to be compatible with multiple OpenEMR versions.
 */
class CalendarHooks
{
    private static $logger;

    public static function register()
    {
        global $eventDispatcher;
        if (isset($eventDispatcher) && is_object($eventDispatcher) && method_exists($eventDispatcher, 'addListener')) {
            self::$logger = new SystemLogger();
            
            // Register event filters
            $eventDispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, [self::class, 'filterTelehealthEvents']);
            $eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, [self::class, 'addCalendarJavascript']);
            $eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, [self::class, 'addCalendarStylesheet']);
            $eventDispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, [self::class, 'renderAppointmentButtons']);
        }
    }

    /**
     * Add Telehealth URLs (for provider & patient) to the rendered HTML.
     *
     * @param mixed $event The event object (type varies by OpenEMR version)
     * @return mixed The event object
     */
    public static function addTelehealthLinks($event)
    {
        // Check if the event has the getAppt method
        if (!method_exists($event, 'getAppt')) {
            return $event;
        }
        
        $appt = $event->getAppt();
        $eid = $appt['pc_eid'] ?? ($appt['eid'] ?? 0);
        $pid = $appt['pc_pid'] ?? 0;

        // Build URLs for our custom module
        $providerUrl = "../../modules/custom_modules/oe-module-telehealth/controllers/index.php?action=start&role=provider&eid=" . urlencode($eid);
        $patientUrl  = "../../modules/custom_modules/oe-module-telehealth/controllers/index.php?action=start&role=patient&eid=" . urlencode($eid);

        // Use CSRF if available
        $csrf = '';
        if (class_exists('\\OpenEMR\\Common\\Csrf\\CsrfUtils') && method_exists('\\OpenEMR\\Common\\Csrf\\CsrfUtils', 'collectCsrfToken')) {
            $csrf = \OpenEMR\Common\Csrf\CsrfUtils::collectCsrfToken();
        }

        // Check if xlt function exists (it's an OpenEMR function)
        $translate = function($text) {
            return function_exists('xlt') ? xlt($text) : $text;
        };
        
        $btns = '<div class="mt-2">';
        $btns .= "<a class='btn btn-primary mr-1' target='_blank' href='{$providerUrl}'>". $translate('Start Tele-visit (Provider)') ."</a> ";
        $btns .= "<a class='btn btn-primary mr-1' target='_blank' href='{$patientUrl}'>". $translate('Start Tele-visit (Patient)') ."</a> ";
        $btns .= "<button type='button' class='btn btn-secondary mr-1' id='send_invite_email_{$eid}'>". $translate('Send Invite (Email)') ."</button>";
        $btns .= "<button type='button' class='btn btn-secondary' id='send_invite_sms_{$eid}'>". $translate('Send Invite (SMS)') ."</button>";
        $btns .= '</div>';

        // Create a safer API endpoint path
        $apiEndpoint = "../../modules/custom_modules/oe-module-telehealth/api/invite.php";
        
        // inline JS for send invite - only add if we have CSRF
        $script = "";
        if (!empty($csrf)) {
            $script = "<script>(function(){
                function send(ch){
                    return function(){
                        var btn=this;
                        btn.disabled=true;
                        fetch('{$apiEndpoint}',{
                            method:'POST',
                            headers:{'Content-Type':'application/x-www-form-urlencoded'},
                            body:'csrf_token={$csrf}&encounter_id={$eid}&pid={$pid}&channel='+ch
                        })
                        .then(r=>r.json())
                        .then(d=>{
                            alert(d.message);
                            btn.disabled=false;
                        })
                        .catch(()=>{
                            alert('Error sending invite');
                            btn.disabled=false;
                        });
                    };
                }
                document.getElementById('send_invite_email_{$eid}').addEventListener('click',send('email'));
                document.getElementById('send_invite_sms_{$eid}').addEventListener('click',send('sms'));
            })();</script>";
        }

        $injection = $btns . $script;

        // Try to output the content - different OpenEMR versions handle this differently
        try {
            echo $injection; // Output directly since this event happens during template rendering
        } catch (\Exception $e) {
            // If direct output fails, try to add it to the event
            if (method_exists($event, 'setHtml')) {
                $event->setHtml($event->getHtml() . $injection);
            }
        }

        return $event;
    }

    public static function filterTelehealthEvents(CalendarUserGetEventsFilter $event)
    {
        $eventsByDay = $event->getEventsByDays();
        $keys = array_keys($eventsByDay);
        
        foreach ($keys as $key) {
            $eventCount = count($eventsByDay[$key]);
            for ($i = 0; $i < $eventCount; $i++) {
                $catId = $eventsByDay[$key][$i]['catid'];
                
                // Check if this is a telehealth category
                $catRow = sqlQuery('SELECT pc_constant_id FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$catId]);
                if (in_array($catRow['pc_constant_id'], ['telehealth_new_patient', 'telehealth_established_patient'])) {
                    $eventViewClasses = ["event_appointment", "event_telehealth"];
                    
                    // Add status-specific classes
                    if ($eventsByDay[$key][$i]['apptstatus'] == '-') {
                        $eventViewClasses[] = "event_telehealth_completed";
                    } else {
                        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", 
                            $eventsByDay[$key][$i]['eventDate'] . " " . $eventsByDay[$key][$i]['startTime']);
                        
                        if ($dateTime !== false) {
                            $now = new \DateTime();
                            $diff = abs($dateTime->getTimestamp() - $now->getTimestamp()) / 3600;
                            if ($diff <= 2) {
                                $eventViewClasses[] = "event_telehealth_active";
                            }
                        }
                    }
                    
                    $eventsByDay[$key][$i]['eventViewClass'] = implode(" ", $eventViewClasses);
                }
            }
        }
        
        $event->setEventsByDays($eventsByDay);
        return $event;
    }

    public static function addTelehealthIcon($event)
    {
        // Get the appointment data
        $appt = $event->appointment ?? null;
        if (!$appt || empty($appt['pc_eid'])) {
            return;
        }

        // Check if this is a telehealth appointment
        $catRow = sqlQuery('SELECT pc_constant_id FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$appt['pc_catid']]);
        $isTelehealth = in_array($catRow['pc_constant_id'], ['telehealth_new_patient', 'telehealth_established_patient']);

        if ($isTelehealth) {
            // Add video icon to the appointment display
            $iconHtml = '<i class="fa fa-video-camera text-success mr-1" title="Telehealth Visit"></i>';
            if (method_exists($event, 'prependContent')) {
                $event->prependContent($iconHtml);
            } else {
                echo $iconHtml;
            }
        }
    }

    public static function addCalendarStylesheet(StyleFilterEvent $event)
    {
        if (self::isCalendarPage($event->getPageName())) {
            $styles = $event->getStyles();
            $styles[] = "../../modules/custom_modules/oe-module-telehealth/public/assets/css/telehealth.css";
            $event->setStyles($styles);
        }
    }

    public static function addCalendarJavascript(ScriptFilterEvent $event)
    {
        if (self::isCalendarPage($event->getPageName())) {
            $scripts = $event->getScripts();
            $scripts[] = "../../modules/custom_modules/oe-module-telehealth/public/assets/js/telehealth-calendar.js";
            $event->setScripts($scripts);
        }
    }

    public static function renderAppointmentJavascript(AppointmentRenderEvent $event)
    {
        $appt = $event->getAppt();
        // Get enabled providers and categories
        $providers = sqlStatement("SELECT id FROM users WHERE active = 1 AND authorized = 1");
        $providerIds = [];
        while ($row = sqlFetchArray($providers)) {
            $providerIds[] = intval($row['id']);
        }

        $categories = sqlStatement("SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_constant_id IN ('telehealth_new_patient', 'telehealth_established_patient')");
        $categoryIds = [];
        while ($row = sqlFetchArray($categories)) {
            $categoryIds[] = intval($row['pc_catid']);
        }

        $jsAppointmentEventNames = [
            'appointmentSetEvent' => 'appointmentSetEvent'
        ];

        // Output the JavaScript
        echo "<script>
            (function(window) {
                window.telehealth = window.telehealth || {};
                window.telehealth.providers = " . json_encode($providerIds) . ";
                window.telehealth.categories = " . json_encode($categoryIds) . ";
                window.telehealth.eventNames = " . json_encode($jsAppointmentEventNames) . ";
            })(window);
        </script>";
    }

    private static function isCalendarPage($pageName)
    {
        return in_array($pageName, ['pnuserapi.php', 'pnadmin.php', 'add_edit_event.php']);
    }

    public static function renderAppointmentButtons(AppointmentRenderEvent $event)
    {
        global $GLOBALS;

        // Check if telehealth is enabled
        if ($GLOBALS['telehealth_enabled'] != '1') {
            return;
        }

        $appt = $event->getAppt();
        if (empty($appt['pc_eid'])) {
            return;
        }

        // Check if this is a telehealth appointment
        $catRow = sqlQuery('SELECT pc_constant_id FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$appt['pc_catid']]);
        if (!in_array($catRow['pc_constant_id'], ['telehealth_new_patient', 'telehealth_established_patient'])) {
            return;
        }

        // Check if appointment is completed
        if ($appt['pc_apptstatus'] == '-') {
            echo "<button class='mt-2 btn btn-secondary' disabled><i class='fa fa-video-camera mr-2'></i>" 
                . xlt("TeleHealth Session Ended") . "</button>";
            echo "<p class='text-muted'>" . xlt("Session has been completed") . "</p>";
            return;
        }

        // Check if appointment is within time range (2 hours before/after)
        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $appt['pc_eventDate'] . " " . $appt['pc_startTime']);
        if ($dateTime === false) {
            self::$logger->error("Invalid appointment date/time", ['eid' => $appt['pc_eid']]);
            return;
        }

        $now = new \DateTime();
        $diff = abs($dateTime->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($diff <= 2) {
            // Check provider access
            $showProviderButton = $GLOBALS['telehealth_provider_access'] == '1';
            $showPatientButton = $GLOBALS['telehealth_patient_access'] == '1';

            // Build URLs
            $providerUrl = "../../modules/custom_modules/oe-module-telehealth/public/index.php?action=start&role=provider&eid=" . attr($appt['pc_eid']);
            $patientUrl = "../../modules/custom_modules/oe-module-telehealth/public/index.php?action=start&role=patient&eid=" . attr($appt['pc_eid']);

            echo '<div class="mt-2">';
            if ($showProviderButton) {
                echo '<button onclick="window.open(\'' . attr($providerUrl) . '\')" class="btn btn-primary mr-2"><i class="fa fa-video-camera mr-1"></i> ' 
                    . xlt('Start Telehealth (Provider)') . '</button>';
            }
            if ($showPatientButton) {
                echo '<button onclick="window.open(\'' . attr($patientUrl) . '\')" class="btn btn-primary"><i class="fa fa-video-camera mr-1"></i> ' 
                    . xlt('Start Telehealth (Patient)') . '</button>';
            }
            echo '</div>';
        } else {
            echo "<button class='mt-2 btn btn-secondary' disabled><i class='fa fa-video-camera mr-2'></i>" 
                . xlt("TeleHealth Session Not Available") . "</button>";
            echo "<p class='text-muted'>" . xlt("Session can only be launched 2 hours before or after the appointment time") . "</p>";
        }
    }
}
