<?php
/**
 * Telehealth Module for OpenEMR
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Module metadata for OpenEMR module system
$GLOBALS['moduleDirectories']['telehealth'] = __DIR__;

// Set module directory name - this should match the directory name in custom_modules
$moduleDirName = basename(dirname(__FILE__));

use OpenEMR\Core\Kernel\Exception\ModuleException;
use OpenEMR\Common\Logging\SystemLogger;

// Module metadata required for registration
$moduleName = 'Telehealth Virtual Care';
$moduleVersion = 'v0.0.1';
$moduleType = 0; // 0 = official, 1 = custom
$moduleCategory = 'Clinical';
$moduleActive = 1;
$moduleACL = array('admin', 'clinician');
$moduleDescription = 'Telehealth integration for OpenEMR with real-time waiting room notifications and comprehensive telesalud backend integration.';

// Register the module with OpenEMR
try {
    // Use the module directory name to determine the module name for registration
    // This ensures the module works regardless of the directory name used
    $registrationName = 'telehealth';
    if (strpos($moduleDirName, 'oe-module-') === 0) {
        // If using the oe-module-xxx naming convention, extract the module name
        $registrationName = substr($moduleDirName, 10); // Remove 'oe-module-' prefix
    }
    
    $moduleRegistry = new \OpenEMR\Core\ModuleRegistry();
    $moduleRegistry->registerModule(
        $registrationName,
        $moduleVersion,
        $moduleType,
        $moduleActive,
        $moduleCategory,
        $moduleACL,
        $moduleDescription
    );
} catch (ModuleException $e) {
    // Log the error but continue - this allows the module to work even if registration fails
    error_log('Error registering Telehealth module: ' . $e->getMessage());
}

function telehealth_install() {
    // Database table now created via modules/telehealth/sql/install.sql migration
    
    // Add backend_id column to telehealth_vc table if it doesn't exist
    $backendIdExists = sqlQuery("SHOW COLUMNS FROM `telehealth_vc` LIKE 'backend_id'");
    if (empty($backendIdExists)) {
        sqlStatement("ALTER TABLE `telehealth_vc` ADD COLUMN `backend_id` VARCHAR(255) NULL AFTER `url`");
    }

    // Register hooks
    require_once __DIR__ . '/hooks/EncounterHooks.php';
    \Telehealth\Hooks\EncounterHooks::register();

    // Register clinical notes hooks
    require_once __DIR__ . '/hooks/ClinicalNotesHooks.php';
    \Telehealth\Hooks\ClinicalNotesHooks::register();

    // Register calendar hooks
    require_once __DIR__ . '/hooks/CalendarHooks.php';
    \Telehealth\Hooks\CalendarHooks::register();

    // Register patient summary badge hooks
    require_once __DIR__ . '/hooks/SummaryHooks.php';
    \Telehealth\Hooks\SummaryHooks::register();

    // Register patient-portal hooks (Join button)
    require_once __DIR__ . '/hooks/PortalHooks.php';
    \Telehealth\Hooks\PortalHooks::register();

    // Register appointment-set hooks (auto-create Jitsi links)
    require_once __DIR__ . '/hooks/AppointmentHooks.php';
    \Telehealth\Hooks\AppointmentHooks::register();
    
    // Register header hooks (for waiting room notifications)
    require_once __DIR__ . '/hooks/HeaderHooks.php';
    \Telehealth\Hooks\HeaderHooks::register();

    // Add main Telehealth menu link
    register_special_menu('telehealth', [
        'label'  => 'Telehealth',
        'target' => '../../modules/telehealth/controllers/index.php',
    ]);

    // Add admin-only Settings link
    register_special_menu('telehealth-settings', [
        'label'  => 'Telehealth Settings',
        'target' => '../../modules/telehealth/controllers/settings.php',
        'acl'    => 'admin', // only super-admins can view
    ]);
}

function telehealth_uninstall() {
    // Remove menu and tables if desired
    unregister_special_menu('telehealth');
    unregister_special_menu('telehealth-settings');
}

return [
    'id' => 'telehealth',
    'name' => 'Telehealth Virtual Care',
    'description' => 'Telehealth integration for OpenEMR with real-time waiting room notifications and comprehensive telesalud backend integration.',
    'version' => '0.0.1',
    'category' => 'Clinical',
    'settings' => [
        'path' => '/interface/modules/custom_modules/oe-module-telehealth/moduleConfig.php'
    ],
    // You can add other metadata as needed
];

?>
