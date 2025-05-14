<?php
namespace Telehealth\Hooks;

// Define constants for event names in case the classes don't exist
define('TELEHEALTH_SUMMARY_RENDER_EVENT', 'patient.summary.card.render');

// Only use the classes if they exist
if (class_exists('\\OpenEMR\\Events\\Patient\\Summary\\Card\\RenderEvent')) {
    class_alias('\\OpenEMR\\Events\\Patient\\Summary\\Card\\RenderEvent', 'Telehealth\\Hooks\\SummaryRenderEventAlias');
} else {
    // Create a placeholder class if the OpenEMR class doesn't exist
    class SummaryRenderEventAlias {
        const EVENT_HANDLE = TELEHEALTH_SUMMARY_RENDER_EVENT;
    }
}

/**
 * Hooks into the patient summary page to add telehealth badges
 * 
 * This version is designed to be compatible with different OpenEMR versions.
 */
class SummaryHooks
{
    /**
     * Register with the global event dispatcher if available
     */
    public static function register()
    {
        global $eventDispatcher;
        // Only register if the event dispatcher exists and is an object
        if (isset($eventDispatcher) && is_object($eventDispatcher) && method_exists($eventDispatcher, 'addListener')) {
            // Priority 10 so it runs after core prep, but before other modules that may append
            $eventDispatcher->addListener(SummaryRenderEventAlias::EVENT_HANDLE, [self::class, 'injectBadge'], 10);
        } else {
            error_log('Telehealth Module: Event dispatcher not available, summary hooks not registered');
        }
    }

    /**
     * Inject telehealth badge into the patient summary
     * 
     * @param mixed $event The event object (type varies by OpenEMR version)
     * @return void
     */
    public static function injectBadge($event)
    {
        // Check if the event has the getCard method
        if (!is_object($event) || !method_exists($event, 'getCard')) {
            return;
        }
        
        // Only inject into the appointments card (could change to demographics etc.)
        if ($event->getCard() !== 'appointments') {
            return;
        }

        // Get the encounter ID from the session
        $encounter = 0;
        
        // Try to use SessionUtil if available
        if (class_exists('\\OpenEMR\\Common\\Session\\SessionUtil') && 
            method_exists('\\OpenEMR\\Common\\Session\\SessionUtil', 'sessionVal')) {
            $encounter = \OpenEMR\Common\Session\SessionUtil::sessionVal('encounter');
        } else {
            // Fallback to global session variable
            global $encounter;
        }
        
        if (!$encounter) {
            return;
        }

        $row = sqlQuery('SELECT meeting_url FROM telehealth_vc WHERE encounter_id = ?', [$encounter]);
        if (!$row || empty($row['meeting_url'])) {
            return;
        }
        $meetingUrl = $row['meeting_url'];

        // Anonymous class implementing RenderInterface to provide Twig template
        $badgeObj = new class($meetingUrl) implements RenderInterface {
            private string $url;
            public function __construct(string $url)
            {
                $this->url = $url;
            }
            public function getTemplateFile(): string
            {
                // Twig loader will search relative to modules path as well
                return '@telehealth/badge.html.twig';
            }
            public function getVariables(): array
            {
                return ['url' => $this->url];
            }
        };

        // Append badge at end of card
        $event->addAppendedData($badgeObj);
    }
}
