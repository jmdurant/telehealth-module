<?php

/**
 * Telehealth Settings Controller
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\Telehealth\Util\TelehealthSettingsUtil;
use Twig\Environment;

class TelehealthSettingsController
{
    /** @var Environment The Twig environment for rendering templates */
    private $twig;
    
    /** @var SystemLogger For logging errors and debug information */
    private $logger;
    
    /** @var string The path to assets directory */
    private $assetPath;
    
    /** @var TelehealthSettingsUtil Access to telehealth settings */
    private $settingsUtil;
    
    /**
     * Constructor
     *
     * @param Environment $twig The Twig environment for rendering
     * @param string $assetPath Path to assets directory
     * @param TelehealthSettingsUtil $settingsUtil Settings utility
     * @param SystemLogger|null $logger Optional logger instance
     */
    public function __construct(
        Environment $twig,
        string $assetPath,
        TelehealthSettingsUtil $settingsUtil,
        ?SystemLogger $logger = null
    ) {
        $this->twig = $twig;
        $this->assetPath = $assetPath;
        $this->settingsUtil = $settingsUtil;
        $this->logger = $logger ?? new SystemLogger();
    }
    
    /**
     * Handle the request to display meeting settings
     *
     * @param array $params Request parameters
     * @return string The rendered HTML
     */
    public function getMeetingSettings(array $params = []): string
    {
        try {
            // Get the current telehealth mode and provider
            $mode = $this->settingsUtil->getMode();
            $provider = $this->settingsUtil->getProvider();
            
            // Base settings
            $settings = [
                'mode' => $mode,
                'provider' => $provider,
                'assetPath' => $this->assetPath
            ];
            
            // Load settings based on mode
            if ($mode === 'telesalud') {
                // Telesalud backend settings
                $settings['api_url'] = $this->settingsUtil->getTelesaludApiUrl();
                $settings['api_token_set'] = !empty($this->settingsUtil->getTelesaludApiToken());
                $settings['days_before_expiration'] = $this->settingsUtil->getTelesaludDaysBeforeExpiration();
            } else {
                // Standalone provider settings
                switch ($provider) {
                    case 'jitsi':
                        $settings['jitsi_base_url'] = $this->settingsUtil->getSetting('jitsi_base_url', 'https://meet.jit.si');
                        break;
                    case 'doxy_me':
                        $settings['doxy_room_url'] = $this->settingsUtil->getSetting('doxy_room_url', '');
                        break;
                    case 'doximity':
                        $settings['doximity_room_url'] = $this->settingsUtil->getSetting('doximity_room_url', '');
                        break;
                    case 'template':
                        $settings['template_url'] = $this->settingsUtil->getSetting('telehealth_template_url', '');
                        break;
                }
            }
            
            // Get reminder settings
            $settings['reminders'] = [
                'day_before' => [
                    'enabled' => $this->settingsUtil->isDayBeforeReminderEnabled(),
                    'time' => $this->settingsUtil->getDayBeforeReminderTime()
                ],
                'hour_before' => $this->settingsUtil->isHourBeforeReminderEnabled(),
                'sms_enabled' => $this->settingsUtil->isSmsReminderEnabled()
            ];
            
            // Render the template with settings
            return $this->twig->render('telehealth/meeting_settings.twig', $settings);
            
        } catch (\Exception $e) {
            $this->logger->error("Error getting meeting settings", ['error' => $e->getMessage()]);
            return "Error: " . $e->getMessage();
        }
    }
    
    /**
     * Generate a meeting URL based on current settings
     *
     * @param string $roomSlug The unique room identifier
     * @return string The complete meeting URL
     */
    public function generateMeetingUrl(string $roomSlug): string
    {
        try {
            return $this->settingsUtil->getMeetingUrl($roomSlug);
        } catch (\Exception $e) {
            $this->logger->error("Error generating meeting URL", ['error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * Test the connection to the telesalud backend
     * 
     * @return array Response with success status and message
     */
    public function testConnection(): array
    {
        try {
            $success = $this->settingsUtil->testTelesaludConnection();
            return [
                'success' => $success,
                'message' => $success ? 'Connection successful!' : 'Connection failed'
            ];
        } catch (\Exception $e) {
            $this->logger->error("Error testing telesalud connection", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
} 