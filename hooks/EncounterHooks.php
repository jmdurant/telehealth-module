<?php
namespace Telehealth\Hooks;

use OpenEMR\Events\EncounterMenuEvent;

class EncounterHooks
{
    public static function register()
    {
        global $eventDispatcher;
        $eventDispatcher->addListener(
            EncounterMenuEvent::MENU_RENDER,
            [self::class, 'addTelehealthMenu']
        );
    }

    public static function addTelehealthMenu(EncounterMenuEvent $event)
    {
        $menu = $event->getMenuData();
        $menu['Telehealth']['children'][] = [
            'href' => '../../modules/telehealth/controllers/C_TSalud_Vc.php',
            'menu_name' => xl('Start Tele-Visit'),
        ];
        $event->setMenuData($menu);
        return $event;
    }
}
