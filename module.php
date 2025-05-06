<?php
/**
 * Telehealth Module Descriptor
 * Provides install/uninstall hooks and registers menu.
 */

function telehealth_install() {
    // Database table now created via modules/telehealth/sql/install.sql migration

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

    // Register appointment-set hooks (auto-create Jitsi links)
    require_once __DIR__ . '/hooks/AppointmentHooks.php';
    \Telehealth\Hooks\AppointmentHooks::register();

    // Add menu link
    register_special_menu('telehealth', array(
        'label' => 'Telehealth',
        'target' => '../../modules/telehealth/controllers/index.php',
    ));
}

function telehealth_uninstall() {
    // Remove menu and tables if desired
    unregister_special_menu('telehealth');
}

?>
