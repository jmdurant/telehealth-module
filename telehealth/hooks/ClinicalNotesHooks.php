<?php
namespace Telehealth\Hooks;

/**
 * Placeholder hooks for Clinical Notes adjustments migrated from old patches.
 *
 * TODO: Replace stub events with real OpenEMR hooks when available (e.g., FormSaveEvent).
 */
class ClinicalNotesHooks
{
    public static function register()
    {
        // Example only – actual event names may differ in OpenEMR core.
        if (class_exists('OpenEMR\\Events\\FormSaveEvent')) {
            global $eventDispatcher;
            $eventDispatcher->addListener(
                \OpenEMR\Events\FormSaveEvent::POST_SAVE,
                [self::class, 'onClinicalNoteSave']
            );
        }
    }

    /**
     * Ensures date value saved without time when telehealth form submits.
     */
    public static function onClinicalNoteSave($event)
    {
        $data = $event->getFormData();
        if (isset($data['date']) && strpos($data['date'], 'T') !== false) {
            $parts = explode('T', $data['date']);
            $data['date'] = $parts[0];
            $event->setFormData($data);
        }
        return $event;
    }
}
