<?php

/**
 * Responsible for rendering TeleHealth features on the patient portal
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\PatientPortal\AppointmentFilterEvent;
use OpenEMR\Events\PatientPortal\RenderEvent;
use OpenEMR\Modules\Telehealth\TelehealthGlobalConfig;
use OpenEMR\Modules\Telehealth\Util\CalendarUtils;
use OpenEMR\Services\AppointmentService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Twig\Environment;

class TeleHealthPatientPortalController
{
    /**
     * @var Environment
     */
    private $twig;
    
    /**
     * @var string
     */
    private $assetPath;
    
    /**
     * @var TelehealthGlobalConfig
     */
    private $config;
    
    /**
     * @var SystemLogger
     */
    private $logger;

    /**
     * @param Environment $twig
     * @param string $assetPath
     * @param TelehealthGlobalConfig $config
     * @param SystemLogger $logger
     */
    public function __construct(Environment $twig, $assetPath, TelehealthGlobalConfig $config, SystemLogger $logger)
    {
        $this->twig = $twig;
        $this->assetPath = $assetPath;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Subscribe to portal events
     * 
     * @param EventDispatcher $eventDispatcher
     */
    public function subscribeToEvents(EventDispatcher $eventDispatcher): void
    {
        $this->logger->debug("TeleHealthPatientPortalController: Subscribing to events");
        
        // Register the event listeners directly - exactly like Comlink does
        $eventDispatcher->addListener(AppointmentFilterEvent::EVENT_NAME, [$this, 'filterPatientAppointment']);
        $eventDispatcher->addListener(RenderEvent::EVENT_SECTION_RENDER_POST, [$this, 'renderTeleHealthPatientAssets']);
    }

    /**
     * Add telehealth scripts and CSS to the patient portal
     * 
     * @param GenericEvent $event
     */
    public function renderTeleHealthPatientAssets(GenericEvent $event): void
    {
        $this->logger->debug("TeleHealthPatientPortalController: Rendering telehealth assets");
        
        $data = [
            'assetPath' => $this->assetPath,
            'debug' => $this->config->isDebugModeEnabled()
        ];
        
        // Render template with assets - same approach as Comlink
        echo $this->twig->render('telehealth/patient-portal.twig', $data);
    }

    /**
     * Filter patient appointments to add telehealth information
     * 
     * @param AppointmentFilterEvent $event
     * @return void
     */
    public function filterPatientAppointment(AppointmentFilterEvent $event): void
    {
        $this->logger->debug("TeleHealthPatientPortalController: Filtering patient appointment");
        
        $dbRecord = $event->getDbRecord();
        $appointment = $event->getAppointment();
        
        // Parse appointment date and time
        $dateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $dbRecord['pc_eventDate'] . " " . $dbRecord['pc_startTime']);
        
        $apptService = new AppointmentService();
        
        // Default to not showing telehealth
        $appointment['showTelehealth'] = false;
        
        // Check if this is a telehealth appointment by category
        $isTelehealthAppointment = false;
        if (!empty($dbRecord['pc_catid'])) {
            // Check if the appointment is a telehealth appointment by category
            // This logic may need adjustment based on your categories
            if ($this->config->isTelehealthCategory($dbRecord['pc_catid'])) {
                $isTelehealthAppointment = true;
            }
        }
        
        if (
            $isTelehealthAppointment &&
            $dateTime !== false && 
            CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)
        ) {
            // Only show the button if the appointment is active (not checked out or pending)
            if (
                $apptService->isCheckOutStatus($dbRecord['pc_apptstatus']) ||
                $apptService->isPendingStatus($dbRecord['pc_apptstatus'])
            ) {
                $appointment['showTelehealth'] = false;
            } else {
                $appointment['showTelehealth'] = true;
            }
        }
        
        $event->setAppointment($appointment);
    }
} 