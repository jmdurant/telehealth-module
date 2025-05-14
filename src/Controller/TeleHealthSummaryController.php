<?php

/**
 * Handles patient summary page events for Telehealth badges and indicators
 *
 * @package   OpenEMR\Modules\Telehealth
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Patient\Summary\Card\RenderEvent;
use OpenEMR\Modules\Telehealth\TelehealthGlobalConfig;
use OpenEMR\Services\AppointmentService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;

class TeleHealthSummaryController
{
    /**
     * @var SystemLogger
     */
    private $logger;

    /**
     * @var TelehealthGlobalConfig
     */
    private $globalsConfig;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var AppointmentService
     */
    private $appointmentService;

    /**
     * TeleHealthSummaryController constructor.
     * @param TelehealthGlobalConfig $globalsConfig
     * @param SystemLogger $logger
     * @param Environment $twig
     * @param AppointmentService $appointmentService
     */
    public function __construct(
        TelehealthGlobalConfig $globalsConfig,
        SystemLogger $logger,
        Environment $twig,
        AppointmentService $appointmentService
    ) {
        $this->globalsConfig = $globalsConfig;
        $this->logger = $logger;
        $this->twig = $twig;
        $this->appointmentService = $appointmentService;
    }

    /**
     * Subscribe to summary page events
     * @param EventDispatcher $eventDispatcher
     */
    public function subscribeToEvents(EventDispatcher $eventDispatcher)
    {
        $this->logger->debug("TeleHealthSummaryController->subscribeToEvents() - Adding summary card event listener");
        $eventDispatcher->addListener(RenderEvent::EVENT_HANDLE, [$this, 'injectBadge']);
    }

    /**
     * Add telehealth badges to patient summary cards
     * @param RenderEvent $event
     * @return RenderEvent
     */
    public function injectBadge(RenderEvent $event)
    {
        if (!$this->globalsConfig->isTelehealthConfigured()) {
            $this->logger->debug("TeleHealthSummaryController->injectBadge() - Telehealth not configured, skipping badge");
            return $event;
        }
        
        $pid = $event->getPid();
        if (empty($pid)) {
            $this->logger->debug("TeleHealthSummaryController->injectBadge() - Missing patient ID, skipping badge");
            return $event;
        }
        
        // Find upcoming telehealth appointments for this patient
        $upcomingAppointments = $this->getUpcomingTelehealthAppointments($pid);
        
        if (empty($upcomingAppointments)) {
            // No telehealth appointments, no badge needed
            return $event;
        }
        
        $badges = $event->getBadges();
        
        try {
            // Render the badge template
            $badgeHtml = $this->twig->render('telehealth/summary_badge.twig', [
                'appointments' => $upcomingAppointments,
                'assetPath' => $GLOBALS['webroot'] . "/interface/modules/custom_modules/telehealth-module/public/assets/",
                'debug' => $this->globalsConfig->isDebugModeEnabled()
            ]);
            
            // Add badge to the list
            $badges[] = $badgeHtml;
            $event->setBadges($badges);
            
            $this->logger->debug("TeleHealthSummaryController->injectBadge() - Added telehealth badge", [
                'pid' => $pid,
                'appointmentCount' => count($upcomingAppointments)
            ]);
        } catch (\Exception $e) {
            $this->logger->error("TeleHealthSummaryController->injectBadge() - Error rendering badge", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $event;
    }
    
    /**
     * Get upcoming telehealth appointments for a patient
     * @param int $pid Patient ID
     * @return array Array of telehealth appointments
     */
    private function getUpcomingTelehealthAppointments($pid)
    {
        // Get appointments for the next 30 days
        $options = [
            'pc_pid' => $pid,
            'datetime' => [
                'start' => date('Y-m-d'),
                'end' => date('Y-m-d', strtotime('+30 days'))
            ],
            'pc_catid' => $this->globalsConfig->getTelehealthCalendarCategoryId(),
        ];
        
        $appointments = $this->appointmentService->search($options);
        
        // Filter out any non-telehealth appointments that might have slipped through
        $telehealthAppointments = [];
        foreach ($appointments as $appointment) {
            if (isset($appointment['pc_catid']) && $appointment['pc_catid'] == $this->globalsConfig->getTelehealthCalendarCategoryId()) {
                $telehealthAppointments[] = $appointment;
            }
        }
        
        return $telehealthAppointments;
    }
} 