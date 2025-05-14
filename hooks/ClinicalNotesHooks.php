<?php
namespace Telehealth\Hooks;

/**
 * Placeholder hooks for Clinical Notes adjustments migrated from old patches.
 *
 * This version is designed to be compatible with different OpenEMR versions.
 */
class ClinicalNotesHooks
{
    /**
     * Register with the global event dispatcher if available
     */
    public static function register()
    {
        // Only register if the event dispatcher and required classes exist
        global $eventDispatcher;
        
        // Check if both the event dispatcher and the FormSaveEvent class exist
        if (isset($eventDispatcher) && 
            is_object($eventDispatcher) && 
            method_exists($eventDispatcher, 'addListener') && 
            class_exists('\\OpenEMR\\Events\\FormSaveEvent')) {
            
            $eventDispatcher->addListener(
                \OpenEMR\Events\FormSaveEvent::POST_SAVE,
                [self::class, 'onClinicalNoteSave']
            );
        } else {
            // Log that we're skipping this hook due to missing dependencies
            error_log('Telehealth Module: FormSaveEvent class not available, clinical notes hooks not registered');
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
