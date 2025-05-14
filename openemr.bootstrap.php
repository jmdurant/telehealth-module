<?php

/**
 * Telehealth Module OpenEMR Bootstrap
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth;

// Module metadata for registration
// These constants are used for module registration
const MODULE_NAME = 'Telehealth Virtual Care';
const MODULE_VERSION = 'v0.0.1';
const MODULE_TYPE = 0; // 0 = official, 1 = custom
const MODULE_CATEGORY = 'Clinical';

// Ensure vendor directory exists - autoloader will be registered by OpenEMR
$classLoader = null;
if (file_exists(__DIR__ . "/vendor/autoload.php")) {
    $classLoader = require_once __DIR__ . "/vendor/autoload.php";
}

/**
 * Register the module with OpenEMR
 */
function registerTelehealthModule() {
    try {
        // Only attempt to register if the ModuleRegistry class exists
        if (class_exists('\\OpenEMR\\Core\\ModuleRegistry')) {
            $moduleRegistry = new \OpenEMR\Core\ModuleRegistry();
            $moduleRegistry->registerModule(
                'oe-module-telehealth',
                MODULE_VERSION,
                MODULE_TYPE,
                1, // Active
                MODULE_CATEGORY,
                ['admin', 'clinician'],
                'Telehealth integration for OpenEMR with real-time waiting room notifications'
            );
        }
    } catch (\Exception $e) {
        // Log the error but continue - don't crash OpenEMR
        error_log('Error registering Telehealth module: ' . $e->getMessage());
    }
}

// Register the module
registerTelehealthModule();

/**
 * @global \OpenEMR\Core\ModuleContainer $GLOBALS['moduleContainer']
 * @global EventDispatcher $eventDispatcher Injected by the OpenEMR module loader
 */
// Get kernel from globals if available
$kernel = $GLOBALS['kernel'] ?? null;

// Exactly like Comlink - pass event dispatcher directly to Bootstrap
$bootstrap = new Bootstrap($eventDispatcher, $kernel);

// Immediately subscribe to events - just like Comlink does
$bootstrap->subscribeToEvents();

// Add debug log
error_log("Telehealth Module: Bootstrap initialized and events subscribed");

// Register special menu items
if (function_exists('register_special_menu')) {
    // Add main Telehealth menu link
    register_special_menu('telehealth', [
        'label'  => 'Telehealth',
        'target' => '../../modules/custom_modules/oe-module-telehealth/controllers/index.php',
    ]);

    // Add admin-only Settings link
    register_special_menu('telehealth-settings', [
        'label'  => 'Telehealth Settings',
        'target' => '../../modules/custom_modules/oe-module-telehealth/controllers/settings.php',
        'acl'    => 'admin', // only super-admins can view
    ]);
}
