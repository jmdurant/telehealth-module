<?php

/**
 * Handles all of the Calendar events and actions for Telehealth
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Events\Appointments\CalendarUserGetEventsFilter;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Services\AppointmentService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;
use OpenEMR\Modules\Telehealth\Util\CalendarUtils;

class TeleHealthCalendarController
{
    /**
     * @var SystemLogger
     */
    private $logger;

    /**
     * @var string
     */
    private $assetPath;

    /**
     * @var int
     */
    private $loggedInUserId;

    /**
     * @var AppointmentService
     */
    private $appointmentService;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var string
     */
    private $moduleDirectory;

    /**
     * TeleHealthCalendarController constructor.
     * 
     * @param Environment $twig
     * @param SystemLogger $logger
     * @param string $assetPath
     * @param int $loggedInUserId
     * @param string $moduleDirectory
     */
    public function __construct(
        Environment $twig,
        SystemLogger $logger,
        string $assetPath,
        int $loggedInUserId,
        string $moduleDirectory
    ) {
        $this->twig = $twig;
        $this->logger = $logger;
        $this->assetPath = $assetPath;
        $this->loggedInUserId = $loggedInUserId;
        $this->moduleDirectory = $moduleDirectory;
    }

    /**
     * Subscribe to OpenEMR events
     * 
     * @param EventDispatcher $eventDispatcher
     * @return void
     */
    public function subscribeToEvents($eventDispatcher): void
    {
        // Direct event listener registration - exactly like Comlink does it
        $eventDispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, [$this, 'filterTelehealthEvents']);
        $eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, [$this, 'addCalendarJavascript']);
        $eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, [$this, 'addCalendarStylesheet']);
        $eventDispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, [$this, 'renderAppointmentButtons']);
        
        $this->logger->debug("TeleHealthCalendarController: Subscribed to events directly");
    }

    /**
     * Get the appointment service
     * 
     * @return AppointmentService
     */
    private function getAppointmentService(): AppointmentService
    {
        if (!isset($this->appointmentService)) {
            $this->appointmentService = new AppointmentService();
        }
        return $this->appointmentService;
    }

    /**
     * Filter telehealth events to add appropriate CSS classes
     * 
     * @param CalendarUserGetEventsFilter $event
     * @return CalendarUserGetEventsFilter
     */
    public function filterTelehealthEvents(CalendarUserGetEventsFilter $event): CalendarUserGetEventsFilter
    {
        $eventsByDay = $event->getEventsByDays();
        $keys = array_keys($eventsByDay);
        
        $this->logger->debug("TeleHealthCalendarController: filterTelehealthEvents called", [
            'eventDayCount' => count($keys),
            'userId' => $this->loggedInUserId
        ]);
        
        // Get appointment service
        $appointmentService = $this->getAppointmentService();
        
        foreach ($keys as $key) {
            $eventCount = count($eventsByDay[$key]);
            for ($i = 0; $i < $eventCount; $i++) {
                $catId = $eventsByDay[$key][$i]['catid'];
                
                // Check if this is a telehealth category
                $catRow = sqlQuery('SELECT pc_constant_id FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$catId]);
                if (in_array($catRow['pc_constant_id'], ['telehealth_new_patient', 'telehealth_established_patient'])) {
                    $eventViewClasses = ["event_appointment", "event_telehealth"];
                    
                    // Add status-specific classes - use AppointmentService method like Comlink does
                    if ($appointmentService->isCheckOutStatus($eventsByDay[$key][$i]['apptstatus'])) {
                        $eventViewClasses[] = "event_telehealth_completed";
                    } else {
                        $dateTimeString = $eventsByDay[$key][$i]['eventDate'] . " " . $eventsByDay[$key][$i]['startTime'];
                        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $dateTimeString);
                        
                        if ($dateTime === false) {
                            // Try alternative format if the first one fails (sometimes OpenEMR stores time without seconds)
                            $dateTime = \DateTime::createFromFormat("Y-m-d H:i", $eventsByDay[$key][$i]['eventDate'] . " " . 
                                substr($eventsByDay[$key][$i]['startTime'], 0, 5));
                            
                            if ($dateTime === false) {
                                $this->logger->error("Invalid event date/time", [
                                    'eid' => $eventsByDay[$key][$i]['eid'] ?? 'unknown',
                                    'date_string' => $dateTimeString
                                ]);
                                continue;
                            }
                        }
                        
                        // Set EST timezone explicitly for appointment time
                        $timezone = new \DateTimeZone('America/New_York'); // EST timezone
                        $dateTime->setTimezone($timezone);
                        
                        if (CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
                            $eventViewClasses[] = "event_telehealth_active";
                        }
                    }
                    
                    $eventsByDay[$key][$i]['eventViewClass'] = implode(" ", $eventViewClasses);
                    
                    $this->logger->debug("TeleHealthCalendarController: Marked telehealth event", [
                        'date' => $eventsByDay[$key][$i]['eventDate'] ?? 'unknown',
                        'catId' => $catId,
                        'catConstant' => $catRow['pc_constant_id'],
                        'classes' => $eventsByDay[$key][$i]['eventViewClass']
                    ]);
                }
            }
        }
        
        $event->setEventsByDays($eventsByDay);
        return $event;
    }

    /**
     * Add stylesheet to calendar pages
     * 
     * @param StyleFilterEvent $event
     * @return StyleFilterEvent
     */
    public function addCalendarStylesheet(StyleFilterEvent $event): StyleFilterEvent
    {
        $pageName = $event->getPageName();
        $this->logger->debug("TeleHealthCalendarController: addCalendarStylesheet called", [
            'pageName' => $pageName,
            'isCalendarPage' => $this->isCalendarPage($pageName)
        ]);
        
        if ($this->isCalendarPage($pageName)) {
            $styles = $event->getStyles();
            $stylePath = $this->assetPath . "css/telehealth.css";
            $styles[] = $stylePath;
            $event->setStyles($styles);
            
            $this->logger->debug("TeleHealthCalendarController: Added stylesheet", [
                'stylePath' => $stylePath
            ]);
        }
        return $event;
    }

    /**
     * Add JavaScript to calendar pages
     * 
     * @param ScriptFilterEvent $event
     * @return ScriptFilterEvent
     */
    public function addCalendarJavascript(ScriptFilterEvent $event): ScriptFilterEvent
    {
        $pageName = $event->getPageName();
        $this->logger->debug("TeleHealthCalendarController: addCalendarJavascript called", [
            'pageName' => $pageName,
            'isCalendarPage' => $this->isCalendarPage($pageName)
        ]);
        
        if ($this->isCalendarPage($pageName)) {
            $scripts = $event->getScripts();
            $scriptPath = $this->assetPath . "js/telehealth-calendar.js";
            $scripts[] = $scriptPath;
            $event->setScripts($scripts);
            
            $this->logger->debug("TeleHealthCalendarController: Added javascript", [
                'scriptPath' => $scriptPath
            ]);
        }
        return $event;
    }

    /**
     * Render telehealth buttons in appointment view
     * 
     * @param AppointmentRenderEvent $event
     * @return void
     */
    public function renderAppointmentButtons(AppointmentRenderEvent $event): void
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

        // Get appointment service if not already set
        $appointmentService = $this->getAppointmentService();

        // Check if appointment is completed - use AppointmentService method like Comlink does
        if ($appointmentService->isCheckOutStatus($appt['pc_apptstatus'])) {
            echo "<button class='mt-2 btn btn-secondary' disabled><i class='fa fa-video-camera mr-2'></i>" 
                . xlt("TeleHealth Session Ended") . "</button>";
            echo "<p class='text-muted'>" . xlt("Session has been completed. Change the appointment status in order to launch this session again.") . "</p>";
            return;
        }

        // Check if appointment is within time range (2 hours before/after)
        $dateTimeString = $appt['pc_eventDate'] . " " . $appt['pc_startTime'];
        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $dateTimeString);
        
        if ($dateTime === false) {
            // Try alternative format if the first one fails (sometimes OpenEMR stores time without seconds)
            $dateTime = \DateTime::createFromFormat("Y-m-d H:i", $appt['pc_eventDate'] . " " . substr($appt['pc_startTime'], 0, 5));
            
            if ($dateTime === false) {
                $this->logger->error("Invalid appointment date/time", [
                    'eid' => $appt['pc_eid'],
                    'date_string' => $dateTimeString,
                    'format_errors' => \DateTime::getLastErrors()
                ]);
                return;
            }
        }
        
        // Create EST timezone for logging current time (do NOT apply to appointment time)
        $timezone = new \DateTimeZone('America/New_York'); // EST timezone
        
        // Add debug logging
        $this->logger->debug("TeleHealthCalendarController: Appointment time check", [
            'pc_eid' => $appt['pc_eid'],
            'pc_eventDate' => $appt['pc_eventDate'],
            'pc_startTime' => $appt['pc_startTime'],
            'datetime_string' => $dateTimeString,
            'datetime_created' => $dateTime->format('Y-m-d H:i:s') . " (unchanged)",
            'current_time_est' => (new \DateTime('now', $timezone))->format('Y-m-d H:i:s'),
            'time_zone' => $timezone->getName()
        ]);

        if (CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
            // Build URLs - use proper path construction
            $modulePublicPath = $GLOBALS['webroot'] . "/interface/modules/custom_modules/" . $this->moduleDirectory . "/public";
            $providerUrl = $modulePublicPath . "/index.php?action=start&role=provider&eid=" . attr($appt['pc_eid']);
            $patientUrl = $modulePublicPath . "/index.php?action=start&role=patient&eid=" . attr($appt['pc_eid']);

            echo '<div class="mt-2">';
            echo '<button onclick="window.open(\'' . attr($providerUrl) . '\')" class="btn btn-primary mr-2"><i class="fa fa-video-camera mr-1"></i> ' 
                . xlt('Start Telehealth (Provider)') . '</button>';
            echo '<button onclick="window.open(\'' . attr($patientUrl) . '\')" class="btn btn-primary"><i class="fa fa-video-camera mr-1"></i> ' 
                . xlt('Start Telehealth (Patient)') . '</button>';
            echo '</div>';
            
            // Add reminder buttons
            $this->renderReminderButtons($appt);
        } else {
            echo "<button class='mt-2 btn btn-secondary' disabled><i class='fa fa-video-camera mr-2'></i>" 
                . xlt("TeleHealth Session Not Available") . "</button>";
            echo "<p class='text-muted'>" . xlt("Session can only be launched 2 hours before or after the appointment time") . "</p>";
            
            // Add reminder buttons even when session is not available
            $this->renderReminderButtons($appt);
        }
    }
    
    /**
     * Render reminder buttons for the appointment
     * 
     * @param array $appt The appointment data
     * @return void
     */
    private function renderReminderButtons(array $appt): void
    {
        global $GLOBALS;
        
        // Get patient data
        $patientData = sqlQuery("SELECT pid, fname, lname, email, phone_cell FROM patient_data WHERE pid = ?", [$appt['pc_pid']]);
        if (empty($patientData)) {
            return;
        }
        
        $hasEmail = !empty($patientData['email']);
        $hasPhone = !empty($patientData['phone_cell']);
        
        // Create URLs for the invitation/reminder system
        $modulePublicPath = $GLOBALS['webroot'] . "/interface/modules/custom_modules/" . $this->moduleDirectory . "/public";
        $emailInviteUrl = $modulePublicPath . "/index.php?action=invite&type=email&eid=" . attr($appt['pc_eid']);
        $smsInviteUrl = $modulePublicPath . "/index.php?action=invite&type=sms&eid=" . attr($appt['pc_eid']);
        
        echo '<div class="mt-3">';
        echo '<h6>' . xlt('Send Appointment Reminders') . '</h6>';
        echo '<div class="btn-group">';
        
        // Email reminder button
        echo '<button onclick="window.open(\'' . attr($emailInviteUrl) . '\')" class="btn btn-info mr-2" ' 
            . (!$hasEmail ? 'disabled title="' . xla('Patient has no email address') . '"' : '') 
            . '><i class="fa fa-envelope mr-1"></i> ' 
            . xlt('Send Email Reminder') . '</button>';
        
        // SMS reminder button (if SMS functionality is available)
        if (class_exists('Twilio\Rest\Client')) {
            echo '<button onclick="window.open(\'' . attr($smsInviteUrl) . '\')" class="btn btn-info" ' 
                . (!$hasPhone ? 'disabled title="' . xla('Patient has no cell phone') . '"' : '') 
                . '><i class="fa fa-mobile-phone mr-1"></i> ' 
                . xlt('Send SMS Reminder') . '</button>';
        }
        
        echo '</div>';
        echo '</div>';
    }

    /**
     * Check if the current page is a calendar page
     * 
     * @param string $pageName
     * @return bool
     */
    private function isCalendarPage(string $pageName): bool
    {
        return in_array($pageName, ['pnuserapi.php', 'pnadmin.php', 'add_edit_event.php']);
    }
} 