<?php
namespace Telehealth\Hooks;

// Only use the class if it exists
if (class_exists('\\OpenEMR\\Events\\EncounterMenuEvent')) {
    class_alias('\\OpenEMR\\Events\\EncounterMenuEvent', 'Telehealth\\Hooks\\EncounterMenuEventAlias');
} else {
    // Create a placeholder class if the OpenEMR class doesn't exist
    class EncounterMenuEventAlias {
        const MENU_RENDER = 'encounter.menu.render';
    }
}

class EncounterHooks
{
    public static function register()
    {
        // Only register if the event dispatcher exists
        global $eventDispatcher;
        if (isset($eventDispatcher) && is_object($eventDispatcher) && method_exists($eventDispatcher, 'addListener')) {
            $eventDispatcher->addListener(
                EncounterMenuEventAlias::MENU_RENDER,
                [self::class, 'addTelehealthMenu']
            );
        }
    }

    public static function addTelehealthMenu($event)
    {
        // Check if the event object has the getMenuData method
        if (!method_exists($event, 'getMenuData')) {
            return;
        }
        
        $menu = $event->getMenuData();
        $menu['Telehealth']['children'][] = [
            'href' => '../../modules/custom_modules/oe-module-telehealth/controllers/index.php',
            'menu_name' => xl('Start Tele-Visit'),
        ];
        $event->setMenuData($menu);
        return $event;
    }
}
