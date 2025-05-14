<?php

/**
 * Telehealth Module Bootstrap
 *
 * @package   OpenEMR\Modules\Telehealth
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Kernel;
use OpenEMR\Services\AppointmentService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthCalendarController;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthPatientPortalController;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthEncounterController;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthClinicalNotesController;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthHeaderController;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthSummaryController;

class Bootstrap implements ModuleInterface
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "Telehealth Virtual Care";
    const MODULE_VERSION = 'v0.0.1';
    
    /**
     * @var SystemLogger
     */
    private $logger;
    
    /**
     * @var TelehealthGlobalConfig
     */
    private $globalsConfig;
    
    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;
    
    /**
     * @var string
     */
    private $modulePath;
    
    /**
     * @var TeleHealthCalendarController
     */
    private $calendarController;
    
    /**
     * @var TeleHealthPatientPortalController
     */
    private $patientPortalController;
    
    /**
     * @var TeleHealthEncounterController
     */
    private $encounterController;
    
    /**
     * @var TeleHealthClinicalNotesController
     */
    private $clinicalNotesController;
    
    /**
     * @var TeleHealthHeaderController
     */
    private $headerController;
    
    /**
     * @var TeleHealthSummaryController
     */
    private $summaryController;
    
    public function __construct()
    {
        $this->modulePath = $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH . "telehealth-module/";
        $this->logger = new SystemLogger();
    }
    
    /**
     * @return TelehealthGlobalConfig
     */
    public function getGlobalConfig()
    {
        if (empty($this->globalsConfig)) {
            // TODO: extract this to a DI Container when we have one
            $this->globalsConfig = new TelehealthGlobalConfig();
        }
        return $this->globalsConfig;
    }
    
    /**
     * @return TeleHealthCalendarController
     */
    public function getCalendarController()
    {
        if (empty($this->calendarController)) {
            $this->calendarController = new TeleHealthCalendarController(
                $this->getGlobalConfig(),
                $this->logger,
                $this->getTwigEnvironment()
            );
        }
        return $this->calendarController;
    }
    
    /**
     * @return TeleHealthPatientPortalController
     */
    public function getPatientPortalController()
    {
        if (empty($this->patientPortalController)) {
            $this->patientPortalController = new TeleHealthPatientPortalController(
                $this->getGlobalConfig(),
                $this->logger,
                $this->getTwigEnvironment()
            );
        }
        return $this->patientPortalController;
    }
    
    /**
     * @return TeleHealthEncounterController
     */
    public function getEncounterController()
    {
        if (empty($this->encounterController)) {
            $this->encounterController = new TeleHealthEncounterController(
                $this->getGlobalConfig(),
                $this->logger
            );
        }
        return $this->encounterController;
    }
    
    /**
     * @return TeleHealthClinicalNotesController
     */
    public function getClinicalNotesController()
    {
        if (empty($this->clinicalNotesController)) {
            $this->clinicalNotesController = new TeleHealthClinicalNotesController(
                $this->getGlobalConfig(),
                $this->logger
            );
        }
        return $this->clinicalNotesController;
    }
    
    /**
     * @return TeleHealthHeaderController
     */
    public function getHeaderController()
    {
        if (empty($this->headerController)) {
            $this->headerController = new TeleHealthHeaderController(
                $this->getGlobalConfig(),
                $this->logger,
                $this->getTwigEnvironment()
            );
        }
        return $this->headerController;
    }
    
    /**
     * @return TeleHealthSummaryController
     */
    public function getSummaryController()
    {
        if (empty($this->summaryController)) {
            $appointmentService = new AppointmentService();
            $this->summaryController = new TeleHealthSummaryController(
                $this->getGlobalConfig(),
                $this->logger,
                $this->getTwigEnvironment(),
                $appointmentService
            );
        }
        return $this->summaryController;
    }
    
    /**
     * @return \Twig\Environment
     */
    private function getTwigEnvironment()
    {
        $twigEnv = null;
        if ($GLOBALS['kernel']) {
            /** @var Kernel */
            $kernel = $GLOBALS['kernel'];
            $twigEnv = $kernel->getEventDispatcher()->getContainer()->get('twig');
        }
        return $twigEnv;
    }
    
    /**
     * @param EventDispatcher $eventDispatcher
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }
    
    /**
     * Setup the module
     * This is the main entry point for the module system
     * 
     * @param EventDispatcherInterface|null $eventDispatcher The event dispatcher (may be null in some OpenEMR versions)
     * @return void
     */
    public function setup(EventDispatcherInterface $eventDispatcher = null): void
    {
        try {
            // Store event dispatcher if provided
            if ($eventDispatcher !== null) {
                $this->eventDispatcher = $eventDispatcher;
            }
            
            // Setup database tables if needed
            $this->setupDatabase();
            
            // Subscribe to events
            if ($this->eventDispatcher && method_exists($this->eventDispatcher, 'addListener')) {
                $this->subscribeToEvents();
            }
            
        } catch (\Exception $e) {
            // Log the error but don't crash OpenEMR
            $this->logger->error("Telehealth Module: Error during setup", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Setup the module database
     */
    private function setupDatabase()
    {
        if (!function_exists('sqlQuery') || !function_exists('sqlStatement')) {
            $this->logger->error("Telehealth Module: SQL functions not available, skipping database setup");
            return;
        }
        
        // Check if telehealth_vc table exists, create it if not
        $tableExists = sqlQuery("SHOW TABLES LIKE 'telehealth_vc'");
        if (empty($tableExists)) {
            $sqlPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . "sql" . DIRECTORY_SEPARATOR . "table.sql";
            if (file_exists($sqlPath)) {
                $sqlContent = file_get_contents($sqlPath);
                $sqlStatements = explode(';', $sqlContent);
                
                foreach ($sqlStatements as $sql) {
                    $sql = trim($sql);
                    if (!empty($sql)) {
                        sqlStatement($sql);
                    }
                }
                
                $this->logger->debug("Telehealth Module: Created telehealth_vc table");
            } else {
                $this->logger->error("Telehealth Module: SQL file not found", ['path' => $sqlPath]);
            }
        }
        
        // Check if backend_id column exists in telehealth_vc table
        $backendIdExists = sqlQuery("SHOW COLUMNS FROM `telehealth_vc` LIKE 'backend_id'");
        if (empty($backendIdExists)) {
            sqlStatement("ALTER TABLE `telehealth_vc` ADD COLUMN `backend_id` VARCHAR(255) NULL AFTER `meeting_url`");
        }
        
        $this->logger->debug("Telehealth Module: Database setup complete");
    }
    
    /**
     * Subscribe to all events
     */
    public function subscribeToEvents()
    {
        $this->logger->debug("Telehealth Module: Subscribing to events");
        
        if (!$this->eventDispatcher) {
            $this->logger->error("Telehealth Module: Cannot subscribe to events - event dispatcher not available");
            return;
        }
        
        try {
            // Add global settings
            $this->addGlobalSettings();
            
            // Only proceed if telehealth is configured
            if ($this->getGlobalConfig()->isTelehealthConfigured()) {
                // Existing controllers
                $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
                $this->getPatientPortalController()->subscribeToEvents($this->eventDispatcher);
                
                // New controllers
                $this->getEncounterController()->subscribeToEvents($this->eventDispatcher);
                $this->getClinicalNotesController()->subscribeToEvents($this->eventDispatcher);
                $this->getHeaderController()->subscribeToEvents($this->eventDispatcher);
                $this->getSummaryController()->subscribeToEvents($this->eventDispatcher);
                
                // Register menu events
                if (class_exists('\\OpenEMR\\Menu\\MenuEvent')) {
                    $this->eventDispatcher->addListener(\OpenEMR\Menu\MenuEvent::MENU_UPDATE, [$this, 'addMenuItems']);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Telehealth Module: Error subscribing to events", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Add global settings for telehealth
     */
    private function addGlobalSettings()
    {
        // Set telehealth_enabled to 1 by default
        $GLOBALS['telehealth_enabled'] = 1;
        $GLOBALS['telehealth_provider_access'] = 1;
        $GLOBALS['telehealth_patient_access'] = 1;
        
        // Register global settings
        $this->globalsConfig = $this->getGlobalConfig();
        
        $this->logger->debug("Telehealth Module: Global settings added", [
            'enabled' => $GLOBALS['telehealth_enabled']
        ]);
    }
    
    /**
     * Add menu items to the main menu
     * @param \OpenEMR\Menu\MenuEvent $event
     * @return \OpenEMR\Menu\MenuEvent
     */
    public function addMenuItems($event)
    {
        $menu = $event->getMenu();
        
        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = '_blank';
        $menuItem->url = $this->modulePath . "public/index.php";
        $menuItem->menu_id = 'telehealth0';
        $menuItem->label = xl("TeleHealth");
        $menuItem->children = [];
        
        /**
         * Three modes
         * 1. Do nothing
         * 2. Add as a new menu item
         * 3. Add as a submenu to the specified menu
         */
        foreach ($menu as $item) {
            if ($item->menu_id == 'admimg') {
                $item->children[] = $menuItem;
                break;
            }
        }
        
        $event->setMenu($menu);
        return $event;
    }
}
