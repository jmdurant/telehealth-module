<?php

/**
 * Telehealth Settings Utility
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Util;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\Telehealth\Classes\TelesaludClient;

class TelehealthSettingsUtil
{
    private $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Get a telehealth setting with default fallback
     * 
     * @param string $key The setting key
     * @param mixed $default Default value if setting not found
     * @return mixed The setting value
     */
    public function getSetting(string $key, $default = '')
    {
        // Use the th_get function from settings.php if available
        if (function_exists('th_get')) {
            return th_get($key, $default);
        }
        
        // Fallback to globals if th_get not available
        global $GLOBALS;
        return isset($GLOBALS[$key]) ? $GLOBALS[$key] : $default;
    }

    /**
     * Get the telehealth mode (standalone or telesalud)
     * 
     * @return string
     */
    public function getMode(): string
    {
        // First check environment variable
        $envValue = getenv('TELEHEALTH_MODE');
        if (!empty($envValue)) {
            return $envValue;
        }
        
        // If TELEHEALTH_BASE_URL is set, assume telesalud mode
        if (getenv('TELEHEALTH_BASE_URL')) {
            $this->logger->debug("Detected TELEHEALTH_BASE_URL environment variable, forcing telesalud mode");
            return 'telesalud';
        }
        
        // Fall back to database setting
        return $this->getSetting('telehealth_mode', 'standalone');
    }

    /**
     * Get the telehealth provider (jitsi, google_meet, etc.)
     * 
     * @return string
     */
    public function getProvider(): string
    {
        return $this->getSetting('telehealth_provider', 'jitsi');
    }

    /**
     * Generate a meeting URL based on current settings and room slug
     * 
     * @param string $roomSlug Unique room identifier
     * @return string The complete meeting URL
     * @throws \Exception when API connection isn't configured in telesalud mode
     */
    public function getMeetingUrl(string $roomSlug): string
    {
        $mode = $this->getMode();
        
        if ($mode === 'telesalud') {
            $apiUrl = $this->getTelesaludApiUrl();
            $apiToken = $this->getTelesaludApiToken();
            
            if (empty($apiUrl) || empty($apiToken)) {
                throw new \Exception('Telesalud API connection not configured. Please configure API URL and token in settings.');
            }
            
            // In a real implementation, this would make an API call to create a meeting
            // For now, just return a message that the telesalud backend would be used
            return rtrim($apiUrl, '/') . '/meeting/' . $roomSlug;
        }
        
        // Standalone mode with various provider options
        $provider = $this->getProvider();
        
        switch ($provider) {
            case 'jitsi':
                $baseUrl = $this->getSetting('jitsi_base_url', 'https://meet.jit.si');
                return rtrim($baseUrl, '/') . '/' . $roomSlug;
                
            case 'google_meet':
                return 'https://meet.google.com/' . $roomSlug;
                
            case 'doxy_me':
                return $this->getSetting('doxy_room_url', '');
                
            case 'doximity':
                return $this->getSetting('doximity_room_url', '');
                
            case 'template':
                $template = $this->getSetting('telehealth_template_url', '');
                return str_replace('{{slug}}', $roomSlug, $template);
                
            default:
                $this->logger->error("Unknown telehealth provider", ['provider' => $provider]);
                return '';
        }
    }

    /**
     * Check if day-before reminders are enabled
     * 
     * @return bool
     */
    public function isDayBeforeReminderEnabled(): bool
    {
        return $this->getSetting('rem_day', 0) == 1;
    }

    /**
     * Get the time for day-before reminders
     * 
     * @return string Time in 'HH:MM' format
     */
    public function getDayBeforeReminderTime(): string
    {
        return $this->getSetting('rem_day_time', '17:00');
    }

    /**
     * Check if hour-before reminders are enabled
     * 
     * @return bool
     */
    public function isHourBeforeReminderEnabled(): bool
    {
        return $this->getSetting('rem_hour', 0) == 1;
    }

    /**
     * Check if SMS reminders are enabled
     * 
     * @return bool
     */
    public function isSmsReminderEnabled(): bool
    {
        return $this->getSetting('rem_sms', 0) == 1;
    }

    /**
     * Get Telesalud API URL
     * 
     * @return string
     */
    public function getTelesaludApiUrl(): string
    {
        // First check environment variable
        $envValue = getenv('TELEHEALTH_BASE_URL');
        if (!empty($envValue)) {
            return $envValue;
        }
        
        // Fall back to database setting
        return $this->getSetting('telesalud_api_url', '');
    }

    /**
     * Get Telesalud API token
     * 
     * @return string
     */
    public function getTelesaludApiToken(): string
    {
        // First check environment variable
        $envValue = getenv('TELEHEALTH_API_TOKEN');
        if (!empty($envValue)) {
            return $envValue;
        }
        
        // Fall back to database setting
        return $this->getSetting('telesalud_api_token', '');
    }

    /**
     * Get Telesalud notification token
     * 
     * @return string
     */
    public function getTelesaludNotificationToken(): string
    {
        // First check environment variable
        $envValue = getenv('NOTIFICATION_TOKEN');
        if (!empty($envValue)) {
            return $envValue;
        }
        
        // Fall back to database setting
        return $this->getSetting('telesalud_notification_token', '');
    }

    /**
     * Get days before expiration for Telesalud meetings
     * 
     * @return int
     */
    public function getTelesaludDaysBeforeExpiration(): int
    {
        return (int)$this->getSetting('telesalud_days_before_expiration', 3);
    }

    /**
     * Get the log file path
     * 
     * @return string
     */
    public function getLogFilePath(): string
    {
        return $this->getSetting('telehealth_log_file', '');
    }

    /**
     * Create a meeting via TelesaludClient
     * 
     * @param int $encounterId The encounter ID
     * @param string $providerName The provider name
     * @param string $patientName The patient name
     * @param string $startTime The meeting start time
     * @return array The meeting data with URLs and backend info
     * @throws \Exception If API connection isn't configured or request fails
     */
    public function createTelesaludMeeting(int $encounterId, string $providerName, string $patientName, string $startTime): array
    {
        // Get the API connection details
        $apiUrl = $this->getTelesaludApiUrl();
        $apiToken = $this->getTelesaludApiToken();
        
        if (empty($apiUrl) || empty($apiToken)) {
            $this->logger->error("TelesaludClient: Missing API URL or token", [
                'apiUrl' => $apiUrl ? 'set' : 'missing',
                'apiToken' => $apiToken ? 'set' : 'missing'
            ]);
            throw new \Exception('Telesalud API URL or token not configured');
        }
        
        // Create the meeting via API
        $client = new TelesaludClient($apiUrl, $apiToken, $this->logger);
        return $client->createMeeting($encounterId, $providerName, $patientName, $startTime, [
            'days_before_expiration' => $this->getTelesaludDaysBeforeExpiration()
        ]);
    }
} 