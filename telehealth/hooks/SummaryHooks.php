<?php
namespace Telehealth\Hooks;

use OpenEMR\Events\Patient\Summary\Card\RenderEvent;
use OpenEMR\Events\Patient\Summary\Card\RenderInterface;
use OpenEMR\Common\Session\SessionUtil;

class SummaryHooks
{
    public static function register(): void
    {
        global $eventDispatcher;
        if (isset($eventDispatcher)) {
            // Priority 10 so it runs after core prep, but before other modules that may append
            $eventDispatcher->addListener(RenderEvent::EVENT_HANDLE, [self::class, 'injectBadge'], 10);
        }
    }

    public static function injectBadge(RenderEvent $event): void
    {
        // Only inject into the appointments card (could change to demographics etc.)
        if ($event->getCard() !== 'appointments') {
            return;
        }

        $encounter = SessionUtil::sessionVal('encounter');
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
