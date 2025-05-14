<?php

/**
 * Handles header-related events for Telehealth waiting room notifications
 *
 * @package   OpenEMR\Modules\Telehealth
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Modules\Telehealth\TelehealthGlobalConfig;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;

class TeleHealthHeaderController
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
     * @var Environment
     */
    private $twig;

    /**
     * TeleHealthHeaderController constructor.
     * @param TelehealthGlobalConfig $globalsConfig
     * @param SystemLogger $logger
     * @param Environment $twig
     */
    public function __construct(TelehealthGlobalConfig $globalsConfig, SystemLogger $logger, Environment $twig)
    {
        $this->globalsConfig = $globalsConfig;
        $this->logger = $logger;
        $this->twig = $twig;
        $this->assetPath = $GLOBALS['webroot'] . "/interface/modules/custom_modules/telehealth-module/public/assets/";
    }

    /**
     * Subscribe to header-related events
     * @param EventDispatcher $eventDispatcher
     */
    public function subscribeToEvents(EventDispatcher $eventDispatcher)
    {
        $this->logger->debug("TeleHealthHeaderController->subscribeToEvents() - Adding header event listeners");
        
        // Add script and style assets
        $eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, [$this, 'addWaitingRoomScript']);
        $eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, [$this, 'addWaitingRoomStyles']);
    }

    /**
     * Add waiting room notification scripts to the header
     * @param ScriptFilterEvent $event
     * @return ScriptFilterEvent
     */
    public function addWaitingRoomScript(ScriptFilterEvent $event)
    {
        // Only proceed for providers in the main interface (not portal)
        if (!isset($_SESSION['authUserID']) || !acl_check('admin', 'super')) {
            return $event;
        }
        
        if (!$this->globalsConfig->isTelehealthConfigured()) {
            $this->logger->debug("TeleHealthHeaderController->addWaitingRoomScript() - Telehealth not configured, skipping");
            return $event;
        }
        
        // Add waiting room notification script
        $scripts = $event->getScripts();
        
        // Add the main telehealth script (minified or debug based on configuration)
        if ($this->globalsConfig->isDebugModeEnabled()) {
            $scripts[] = $this->assetPath . "js/telehealth.js";
        } else {
            $scripts[] = $this->assetPath . "js/telehealth.min.js";
        }
        
        // Add the waiting room notification script
        $scripts[] = $this->assetPath . "js/waiting_room.js";
        
        // Set the updated scripts
        $event->setScripts($scripts);
        
        $this->logger->debug("TeleHealthHeaderController->addWaitingRoomScript() - Added waiting room notification scripts");
        
        return $event;
    }

    /**
     * Add waiting room notification styles to the header
     * @param StyleFilterEvent $event
     * @return StyleFilterEvent
     */
    public function addWaitingRoomStyles(StyleFilterEvent $event)
    {
        // Only proceed for providers in the main interface (not portal)
        if (!isset($_SESSION['authUserID']) || !acl_check('admin', 'super')) {
            return $event;
        }
        
        if (!$this->globalsConfig->isTelehealthConfigured()) {
            return $event;
        }
        
        // Add waiting room notification styles
        $styles = $event->getStyles();
        $styles[] = $this->assetPath . "css/telehealth.css";
        $event->setStyles($styles);
        
        $this->logger->debug("TeleHealthHeaderController->addWaitingRoomStyles() - Added waiting room notification styles");
        
        return $event;
    }
} 