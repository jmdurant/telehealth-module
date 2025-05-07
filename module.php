<?php
/**
 * Telehealth Module Descriptor
 * Provides install/uninstall hooks and registers menu.
 */

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

?>
