<?php

/**
 * Handles all Encounter-related events for Telehealth
 *
 * @package   OpenEMR\Modules\Telehealth
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Encounter\EncounterMenuEvent;
use OpenEMR\Modules\Telehealth\TelehealthGlobalConfig;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TeleHealthEncounterController
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
     * @var string
     */
    private $assetPath;

    /**
     * TeleHealthEncounterController constructor.
     * @param TelehealthGlobalConfig $globalsConfig
     * @param SystemLogger $logger
     */
    public function __construct(TelehealthGlobalConfig $globalsConfig, SystemLogger $logger)
    {
        $this->globalsConfig = $globalsConfig;
        $this->logger = $logger;
        $this->assetPath = $GLOBALS['webroot'] . "/interface/modules/custom_modules/telehealth-module/public/assets/";
    }

    /**
     * Subscribe to Encounter-related events
     * @param EventDispatcher $eventDispatcher
     */
    public function subscribeToEvents(EventDispatcher $eventDispatcher)
    {
        $this->logger->debug("TeleHealthEncounterController->subscribeToEvents() - Adding encounter menu event listener");
        $eventDispatcher->addListener(EncounterMenuEvent::MENU_RENDER, [$this, 'addTelehealthMenu']);
    }

    /**
     * Add Telehealth options to the encounter menu
     * @param EncounterMenuEvent $event
     * @return EncounterMenuEvent
     */
    public function addTelehealthMenu(EncounterMenuEvent $event)
    {
        $this->logger->debug("TeleHealthEncounterController->addTelehealthMenu() - Adding telehealth menu items");
        
        if (!$this->globalsConfig->isTelehealthConfigured()) {
            $this->logger->debug("TeleHealthEncounterController->addTelehealthMenu() - Telehealth not configured, skipping menu items");
            return $event;
        }
        
        $pid = $event->getPid();
        $encounter = $event->getEncounter();
        
        if (empty($pid) || empty($encounter)) {
            $this->logger->debug("TeleHealthEncounterController->addTelehealthMenu() - Missing patient ID or encounter, skipping menu items");
            return $event;
        }
        
        // Create menu item
        $menuItem = [
            'label' => xl('Start TeleHealth'),
            'url' => '/interface/modules/custom_modules/telehealth-module/public/index.php?action=join&eid=' . $encounter . '&pid=' . $pid,
            'target' => '_blank',
            'icon' => 'fa-video',
            'order' => 100
        ];
        
        // Add item to menu array
        $menuArray = $event->getMenuItems();
        $menuArray[] = $menuItem;
        $event->setMenuItems($menuArray);
        
        return $event;
    }
} 