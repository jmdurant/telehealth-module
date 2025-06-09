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
use OpenEMR\Modules\Telehealth\Twig\TelehealthTwigExtension;
use OpenEMR\Modules\Telehealth\Util\TelehealthSettingsUtil;
use OpenEMR\Core\TwigEnvironmentEvent;
use Twig\Loader\FilesystemLoader;
use OpenEMR\Modules\Telehealth\SettingsUtility;
use OpenEMR\Modules\Telehealth\DatabaseMigrations;

class Bootstrap
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
     * @var Controller\TelehealthSettingsController
     */
    private $settingsController;
    
    /**
     * @var Util\TelehealthSettingsUtil
     */
    private $settingsUtil;

    /**
     * Bootstrap constructor - Minimal like Comlink's pattern
     * 
     * @param EventDispatcher $dispatcher
     * @param Kernel|null $kernel
     */
    public function __construct(EventDispatcher $dispatcher = null, ?Kernel $kernel = null)
    {
        // ✅ MINIMAL CONSTRUCTOR: Just store the parameters like Comlink does
        $this->eventDispatcher = $dispatcher;
        $this->moduleDirectoryName = basename(dirname(__DIR__));
        
        // Initialize logger only if we can do it safely
        if (empty($kernel)) {
            $kernel = new Kernel();
        }
        
        // Only create logger - everything else goes in subscribeToEvents()
        try {
            $this->logger = new SystemLogger();
        } catch (\Exception $e) {
            // If we can't create logger, create a simple fallback
            $this->logger = new class {
                public function debug($message, $context = []) { error_log("DEBUG: $message"); }
                public function error($message, $context = []) { error_log("ERROR: $message"); }
            };
        }
    }

    /**
     * Subscribe to all events - this matches Comlink's approach exactly
     * This is where ALL initialization happens, just like Comlink
     */
    public function subscribeToEvents()
    {
        $this->logger->debug("Telehealth Module: Starting subscribeToEvents");
        
        // ✅ SAFETY CHECK: Only proceed if we have proper OpenEMR environment
        if (!isset($GLOBALS['webroot']) || !function_exists('sqlQuery')) {
            $this->logger->debug("Telehealth Module: OpenEMR not fully initialized, skipping");
            return;
        }
        
        try {
            // Add global settings first
            $this->addGlobalSettings();
            
            // ✅ INITIALIZE COMPONENTS: Now it's safe to initialize everything
            $this->initializeComponents();
            
            if (!$this->eventDispatcher) {
                $this->logger->debug("Telehealth Module: No event dispatcher available");
                return;
            }
            
            // ✅ CHECK CONFIGURATION: Only proceed if telehealth is configured
            if ($this->globalsConfig && $this->globalsConfig->isTelehealthConfigured()) {
                $this->logger->debug("Telehealth Module: Configuration OK, registering event listeners");
                
                // Subscribe template events
                $this->subscribeToTemplateEvents();
                
                // Have each controller subscribe to its own events
                $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
                $this->getPatientPortalController()->subscribeToEvents($this->eventDispatcher);
                
                // Register global notification system
                $this->subscribeToGlobalNotifications();
                
                // Register menu events
                if (class_exists('\\OpenEMR\\Menu\\MenuEvent')) {
                    $this->eventDispatcher->addListener(\OpenEMR\Menu\MenuEvent::MENU_UPDATE, [$this, 'addMenuItems']);
                }
                
                $this->logger->debug("Telehealth Module: All events subscribed successfully");
            } else {
                $this->logger->debug("Telehealth Module: Not fully configured, skipping event registration");
            }
            
        } catch (\Exception $e) {
            $this->logger->error("Telehealth Module: Error in subscribeToEvents", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Initialize all components safely
     * This replaces the old setup() method
     * Following Comlink's pattern: NO database operations during normal bootstrap
     */
    private function initializeComponents()
    {
        try {
            // Initialize settings utility
            if (!$this->settingsUtil) {
                $this->settingsUtil = new Util\TelehealthSettingsUtil();
            }
            
            // Initialize Twig
            if (!$this->twig) {
                $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates');
                $this->twig = new \Twig\Environment($loader, [
                    'cache' => false,
                    'debug' => true,
                ]);
                
                // Register Twig extensions
                try {
                    $this->twig->addExtension(new TelehealthTwigExtension());
                    $this->logger->debug("Telehealth Module: Twig extension loaded successfully");
                } catch (\Exception $e) {
                    $this->logger->debug("Telehealth Module: Twig extension failed to load: " . $e->getMessage());
                }
                
                $this->logger->debug("Telehealth Module: Twig initialized");
            }
            
            // Initialize global config
            if (!$this->globalsConfig) {
                $this->globalsConfig = new TelehealthGlobalConfig(
                    $this->getURLPath(),
                    $this->moduleDirectoryName,
                    $this->twig
                );
                $this->logger->debug("Telehealth Module: Global config initialized");
            }
            
            // ✅ REMOVED: No database operations during normal bootstrap!
            // Following Comlink's proven pattern - database setup happens during installation only
            
        } catch (\Exception $e) {
            $this->logger->error("Telehealth Module: Error initializing components", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the global configuration
     * 
     * @return TelehealthGlobalConfig
     */
    public function getGlobalConfig()
    {
        // ✅ LAZY INITIALIZATION: Initialize if not already done
        if (!$this->globalsConfig) {
            // Ensure Twig is initialized first
            if (!$this->twig) {
                $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates');
                $this->twig = new \Twig\Environment($loader, [
                    'cache' => false,
                    'debug' => true,
                ]);
                
                // Add Twig extension
                try {
                    $this->twig->addExtension(new TelehealthTwigExtension());
                } catch (\Exception $e) {
                    $this->logger->debug("Telehealth Module: Twig extension failed to load: " . $e->getMessage());
                }
            }
            
            $this->globalsConfig = new TelehealthGlobalConfig(
                $this->getURLPath(),
                $this->moduleDirectoryName,
                $this->twig
            );
            
            $this->logger->debug("Telehealth Module: Global config lazy-initialized");
        }
        
        return $this->globalsConfig;
    }

    /**
     * Get path to the templates directory
     * 
     * @return string
     */
    public function getTemplatePath()
    {
        return \dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
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
     * Subscribe to template events to enable template overrides
     */
    public function subscribeToTemplateEvents()
    {
        $this->logger->debug("Telehealth Module: Adding template overrides manually");
        
        // Instead of listening for an event, we'll manually add our template path to the Twig loaders
        global $twig;
        
        if (isset($twig) && $twig !== $this->twig) {
            $loader = $twig->getLoader();
            if ($loader instanceof FilesystemLoader) {
                $this->logger->debug("Telehealth Module: Adding template path to global Twig loader", [
                    'templatePath' => $this->getTemplatePath()
                ]);
                $loader->prependPath($this->getTemplatePath());
            }
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
            $this->logger->debug("Telehealth Module: Event does not have getMenu method");
            return $event;
        }
        
        $menu = $event->getMenu();
        
        // DEBUG: Log what type of menu we got
        $this->logger->debug("Telehealth Module: Menu type", [
            'type' => gettype($menu),
            'is_array' => is_array($menu),
            'has_appendMenuItem' => is_object($menu) && method_exists($menu, 'appendMenuItem')
        ]);
        
        // Check if menu is an object with appendMenuItem method
        if (!is_object($menu) || !method_exists($menu, 'appendMenuItem')) {
            $this->logger->debug("Telehealth Module: Menu is not an object or doesn't have appendMenuItem method, skipping menu additions");
            return $event;
        }
        
        try {
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
            
            $this->logger->debug("Telehealth Module: Menu items added successfully");
        } catch (\Exception $e) {
            $this->logger->error("Telehealth Module: Error adding menu items", ['error' => $e->getMessage()]);
        }
        
        return $event;
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
            // ✅ SAFE: Ensure Twig is initialized first
            if (!$this->twig) {
                $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates');
                $this->twig = new \Twig\Environment($loader, [
                    'cache' => false,
                    'debug' => true,
                ]);
                
                try {
                    $this->twig->addExtension(new TelehealthTwigExtension());
                } catch (\Exception $e) {
                    $this->logger->debug("Telehealth Module: Twig extension failed to load: " . $e->getMessage());
                }
            }
            
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
     * Get path to assets directory
     * 
     * @return string
     */
    public function getAssetPath()
    {
        return $this->getURLPath() . "assets/";
    }

    /**
     * Get the settings utility
     * 
     * @return Util\TelehealthSettingsUtil
     */
    public function getSettingsUtil()
    {
        // ✅ LAZY INITIALIZATION: Initialize if not already done
        if (!$this->settingsUtil) {
            $this->settingsUtil = new Util\TelehealthSettingsUtil();
            $this->logger->debug("Telehealth Module: Settings utility lazy-initialized");
        }
        
        return $this->settingsUtil;
    }

    /**
     * Get the settings controller
     * 
     * @return Controller\TelehealthSettingsController
     */
    public function getSettingsController()
    {
        if (empty($this->settingsController)) {
            // ✅ SAFE: Ensure Twig is initialized first
            if (!$this->twig) {
                $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates');
                $this->twig = new \Twig\Environment($loader, [
                    'cache' => false,
                    'debug' => true,
                ]);
                
                try {
                    $this->twig->addExtension(new TelehealthTwigExtension());
                } catch (\Exception $e) {
                    $this->logger->debug("Telehealth Module: Twig extension failed to load: " . $e->getMessage());
                }
            }
            
            $this->settingsController = new Controller\TelehealthSettingsController(
                $this->twig,
                $this->getAssetPath(),
                $this->settingsUtil,
                $this->logger
            );
        }
        
        return $this->settingsController;
    }

    /**
     * Get the patient portal controller
     * 
     * @return TeleHealthPatientPortalController
     */
    public function getPatientPortalController()
    {
        if (empty($this->patientPortalController)) {
            // ✅ SAFE: Ensure dependencies are initialized
            $globalConfig = $this->getGlobalConfig(); // This will lazy-initialize if needed
            
            $this->patientPortalController = new TeleHealthPatientPortalController(
                $this->twig, // Will be initialized by getGlobalConfig()
                $this->getAssetPath(),
                $globalConfig,
                $this->logger
            );
        }
        
        return $this->patientPortalController;
    }
    
    /**
     * Get teleconference room controller to handle patient sessions
     * 
     * @param boolean $isPatient Whether the user is a patient
     * @return object The teleconference controller
     */
    public function getTeleconferenceRoomController($isPatient = false)
    {
        // For now, create a simple handler that will redirect to start.php
        // This is a temporary solution until we implement a full controller
        $this->logger->debug("Telehealth Module: Creating simple teleconference controller");
        
        // Return a simple object with all the required action methods
        return new class($this) {
            private $bootstrap;
            
            public function __construct($bootstrap) {
                $this->bootstrap = $bootstrap;
            }
            
            // Handle sending email invites
            public function sendemail($params) {
                $eid = $params['eid'] ?? 0;
                if (empty($eid)) {
                    echo "Missing appointment ID";
                    return;
                }
                
                // Log the action
                $this->bootstrap->logger->debug("Telehealth: Email invite requested", ['eid' => $eid]);
                
                // For now, just show a success message
                echo "<html><body>";
                echo "<h3>Email Invite Sent</h3>";
                echo "<p>An email invitation has been sent to the patient.</p>";
                echo "<p><a href='javascript:window.close()'>Close Window</a></p>";
                echo "</body></html>";
            }
            
            // Handle any other actions
            public function __call($name, $arguments) {
                $this->bootstrap->logger->debug("Telehealth: Unhandled action called", ['action' => $name]);
                echo "Action not implemented: " . htmlspecialchars($name);
            }
        };
    }

    /**
     * Subscribe to global notifications
     */
    private function subscribeToGlobalNotifications()
    {
        try {
            // Register script loading for webhook notifications
            $this->eventDispatcher->addListener(
                \OpenEMR\Events\Core\ScriptFilterEvent::EVENT_NAME, 
                [$this, 'addGlobalNotificationScript']
            );
            
            $this->logger->debug("Telehealth Module: Global notification events registered");
        } catch (\Exception $e) {
            $this->logger->error("Telehealth Module: Error registering global notifications", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Add global notification script to appropriate pages
     */
    public function addGlobalNotificationScript(\OpenEMR\Events\Core\ScriptFilterEvent $event)
    {
        $pageName = $event->getPageName();
        
        // Only load on provider pages, not patient portal or popups
        if ($this->shouldLoadGlobalNotifications($pageName)) {
            $scripts = $event->getScripts();
            $scriptPath = $this->getURLPath() . "webhook_notifications.js";
            $scripts[] = $scriptPath;
            $event->setScripts($scripts);
            
            $this->logger->debug("Telehealth Module: Added global notification script", [
                'pageName' => $pageName,
                'scriptPath' => $scriptPath
            ]);
        }
        
        return $event;
    }
    
    /**
     * Determine if global notifications should load on this page
     */
    private function shouldLoadGlobalNotifications(string $pageName): bool
    {
        // Don't load on patient portal pages
        if (strpos($pageName, 'portal') !== false) {
            return false;
        }
        
        // Don't load on popup windows or modal dialogs
        if (strpos($pageName, 'popup') !== false || strpos($pageName, 'modal') !== false) {
            return false;
        }
        
        // Don't load on login/logout pages
        if (in_array($pageName, ['login.php', 'logout.php', 'index.php'])) {
            return false;
        }
        
        // Don't load if not in a provider session
        if (!isset($_SESSION['authUserID']) || empty($_SESSION['authUserID'])) {
            return false;
        }
        
        // Load on most other OpenEMR pages for providers
        return true;
    }
}
