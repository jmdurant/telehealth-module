<?php

/**
 * Telehealth Module Bootstrap
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Kernel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use OpenEMR\Modules\Telehealth\Controller\TeleHealthPatientPortalController;

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
     * @var EventDispatcher The object responsible for sending and subscribing to events through the OpenEMR system
     */
    private $eventDispatcher;

    private $moduleDirectoryName;

    /**
     * @var Environment Twig container
     */
    private $twig;

    /**
     * @var TelehealthGlobalConfig
     */
    private $globalsConfig;

    /**
     * @var Services\TelehealthAppointmentService
     */
    private $appointmentService;

    /**
     * @var Controller\TeleHealthCalendarController
     */
    private $calendarController;
    
    /**
     * @var Controller\TeleHealthPatientPortalController
     */
    private $patientPortalController;

    /**
     * Bootstrap constructor
     * 
     * @param EventDispatcher $dispatcher
     * @param Kernel|null $kernel
     */
    public function __construct(EventDispatcher $dispatcher = null, ?Kernel $kernel = null)
    {
        global $GLOBALS;

        $this->logger = new SystemLogger();
        $this->eventDispatcher = $dispatcher;
        
        // Set module directory name
        $this->moduleDirectoryName = basename(dirname(__DIR__));
        
        // Initialize Twig
        $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates');
        $this->twig = new \Twig\Environment($loader, [
            'cache' => false,
            'debug' => true,
        ]);

        // Initialize global config
        $this->globalsConfig = new TelehealthGlobalConfig(
            $this->getURLPath(),
            $this->moduleDirectoryName,
            $this->twig
        );
        
        $this->logger->debug("Telehealth Module: Bootstrap initialized", [
            'moduleDirectory' => $this->moduleDirectoryName,
            'urlPath' => $this->getURLPath()
        ]);
    }

    /**
     * Get the global configuration
     * 
     * @return TelehealthGlobalConfig
     */
    public function getGlobalConfig()
    {
        return $this->globalsConfig;
    }

    /**
     * Get the template path
     * 
     * @return string
     */
    public function getTemplatePath()
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
    }

    /**
     * Get the URL path for assets
     * 
     * @return string
     */
    public function getURLPath()
    {
        // Use qualified_site_addr like Comlink does
        if (!empty($GLOBALS['qualified_site_addr'])) {
            return $GLOBALS['qualified_site_addr'] . self::MODULE_INSTALLATION_PATH . $this->moduleDirectoryName . "/public/";
        }
        
        // Fallback to webroot if needed
        $webroot = $GLOBALS['webroot'] ?? "";
        return $webroot . self::MODULE_INSTALLATION_PATH . $this->moduleDirectoryName . "/public/";
    }

    /**
     * Subscribe to all events - this matches Comlink's approach
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
            if ($this->globalsConfig->isTelehealthConfigured()) {
                $this->logger->debug("Telehealth Module: Configuration OK, registering event listeners");
                
                // Have each controller subscribe to its own events directly
                // This follows Comlink's approach exactly
                $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
                
                // Register patient portal controller
                $this->getPatientPortalController()->subscribeToEvents($this->eventDispatcher);
                
                // Register menu events
                if (class_exists('\\OpenEMR\\Menu\\MenuEvent')) {
                    $this->eventDispatcher->addListener(\OpenEMR\Menu\MenuEvent::MENU_UPDATE, [$this, 'addMenuItems']);
                }
            } else {
                $this->logger->debug("Telehealth Module: Not fully configured, skipping event registration");
            }
        } catch (\Exception $e) {
            $this->logger->error("Telehealth Module: Error subscribing to events", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Add global settings - required for the module to work
     */
    public function addGlobalSettings()
    {
        // Set telehealth_enabled to 1 by default
        $GLOBALS['telehealth_enabled'] = 1;
        $GLOBALS['telehealth_provider_access'] = 1;
        $GLOBALS['telehealth_patient_access'] = 1;
        
        $this->logger->debug("Telehealth Module: Global settings added", [
            'enabled' => $GLOBALS['telehealth_enabled']
        ]);
    }

    /**
     * Add menu items to the OpenEMR menu
     * 
     * @param mixed $event The menu event
     * @return mixed The event
     */
    public function addMenuItems($event)
    {
        // Check if the event has the getMenu method
        if (!method_exists($event, 'getMenu')) {
            return $event;
        }
        
        $menu = $event->getMenu();
        
        // Add main Telehealth menu link
        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = '../../modules/custom_modules/oe-module-telehealth/controllers/index.php';
        $menuItem->menu_id = 'telehealth';
        $menuItem->label = 'Telehealth';
        $menuItem->url = '/interface/modules/custom_modules/oe-module-telehealth/controllers/index.php';
        $menuItem->children = [];
        $menuItem->acl_req = ['admin', 'clinician'];
        $menuItem->global_req = [];
        $menu->appendMenuItem('modules', $menuItem);
        
        // Add admin-only Settings link
        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = '../../modules/custom_modules/oe-module-telehealth/controllers/settings.php';
        $menuItem->menu_id = 'telehealth-settings';
        $menuItem->label = 'Telehealth Settings';
        $menuItem->url = '/interface/modules/custom_modules/oe-module-telehealth/controllers/settings.php';
        $menuItem->children = [];
        $menuItem->acl_req = ['admin'];
        $menuItem->global_req = [];
        $menu->appendMenuItem('modules', $menuItem);
        
        return $event;
    }

    /**
     * Setup the module database
     */
    public function setupDatabase()
    {
        // Check if backend_id column exists in telehealth_vc table
        $backendIdExists = sqlQuery("SHOW COLUMNS FROM `telehealth_vc` LIKE 'backend_id'");
        if (empty($backendIdExists)) {
            sqlStatement("ALTER TABLE `telehealth_vc` ADD COLUMN `backend_id` VARCHAR(255) NULL AFTER `meeting_url`");
        }
        
        $this->logger->debug("Telehealth Module: Database setup complete");
    }
    
    /**
     * Setup the module
     * This is the main entry point for the module system
     * 
     * @param mixed $eventDispatcher The event dispatcher (may be null in some OpenEMR versions)
     * @return void
     */
    public function setup($eventDispatcher = null): void
    {
        try {
            // Store event dispatcher if provided
            if ($eventDispatcher && !$this->eventDispatcher) {
                $this->eventDispatcher = $eventDispatcher;
            }
            
            // Setup database tables if needed
            if (function_exists('sqlQuery') && function_exists('sqlStatement')) {
                $this->setupDatabase();
            } else {
                $this->logger->error("Telehealth Module: SQL functions not available, skipping database setup");
            }

            // Subscribe to events if we haven't already
            if ($this->eventDispatcher && method_exists($this->eventDispatcher, 'addListener')) {
                $this->subscribeToEvents();
            }
            
        } catch (\Exception $e) {
            // Log the error but don't crash OpenEMR
            $this->logger->error("Telehealth Module: Error during setup", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the appointment service
     * 
     * @return Services\TelehealthAppointmentService
     */
    private function getAppointmentService()
    {
        if (!isset($this->appointmentService)) {
            $this->appointmentService = new Services\TelehealthAppointmentService();
            $this->logger->debug("Telehealth Module: Appointment service created");
        }
        return $this->appointmentService;
    }

    /**
     * Get the calendar controller
     * 
     * @return Controller\TeleHealthCalendarController
     */
    private function getCalendarController()
    {
        if (!isset($this->calendarController)) {
            $this->calendarController = new Controller\TeleHealthCalendarController(
                $this->twig,
                $this->logger,
                $this->getAssetPath(),
                $_SESSION['authUserID'] ?? 0,
                $this->moduleDirectoryName
            );
            $this->logger->debug("Telehealth Module: Calendar controller created", [
                'assetPath' => $this->getAssetPath(),
                'userId' => $_SESSION['authUserID'] ?? 0
            ]);
        }
        return $this->calendarController;
    }

    /**
     * Get the assets path, following Comlink's approach
     * 
     * @return string
     */
    private function getAssetPath()
    {
        return $this->getURLPath() . "assets/";
    }

    /**
     * Get the patient portal controller
     * 
     * @return TeleHealthPatientPortalController
     */
    public function getPatientPortalController()
    {
        if (empty($this->patientPortalController)) {
            $this->patientPortalController = new TeleHealthPatientPortalController(
                $this->twig,
                $this->getAssetPath(),
                $this->globalsConfig,
                $this->logger
            );
        }
        
        return $this->patientPortalController;
    }
    
    /**
     * Get teleconference room controller to handle patient sessions
     * 
     * @param boolean $isPatient Whether the user is a patient
     * @return TeleconferenceRoomController
     */
    public function getTeleconferenceRoomController($isPatient = false)
    {
        // Add implementation or delegate to another class based on your architecture
        // This is a placeholder for the teleconference controller that will handle patient sessions
        return null;
    }
}
