<?php
/**
 * Jitsi Client
 *
 * This class handles the creation and management of Jitsi meetings,
 * with support for both local Jitsi instances and the telesalud backend.
 *
 * @package OpenEMR
 * @subpackage Telehealth
 * @author OpenEMR Telehealth Module
 * @copyright Copyright (c) 2023
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Classes;

use OpenEMR\Common\Logging\SystemLogger;

class JitsiClient
{
    /**
     * Create a meeting for the given encounter
     *
     * This method will create a meeting using either the telesalud backend
     * or a local Jitsi instance, depending on the configuration.
     *
     * @param int $encounterId The encounter ID
     * @param string $appointmentDate The appointment date/time
     * @param string $medicName The provider's name
     * @param string $patientName The patient's name
     * @return array The meeting details including URL and backend_id
     */
    public static function createMeeting(int $encounterId, string $appointmentDate, string $medicName, string $patientName): array
    {
        $logger = new SystemLogger();
        $logger->debug("Creating meeting for encounter $encounterId", [
            'encounter_id' => $encounterId,
            'appointment_date' => $appointmentDate,
            'medic_name' => $medicName,
            'patient_name' => $patientName,
            'telehealth_mode' => $GLOBALS['telehealth_mode'] ?? 'not_set',
            'jitsi_server' => $GLOBALS['jitsi_server'] ?? 'not_set',
            'telesalud_api_url' => $GLOBALS['telesalud_api_url'] ?? 'not_set'
        ]);
        
        // Check if we should use telesalud backend - check both globals and environment variables
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? getenv('TELEHEALTH_MODE') ?? '';
        
        // If environment variable TELEHEALTH_BASE_URL is set, assume telesalud mode
        if (getenv('TELEHEALTH_BASE_URL') && empty($telehealth_mode)) {
            $telehealth_mode = 'telesalud';
            $logger->debug("Detected TELEHEALTH_BASE_URL environment variable, forcing telesalud mode");
        }
        
        if ($telehealth_mode === 'telesalud') {
            $logger->debug("Using telesalud backend for meeting creation");
            return self::createMeetingViaTelesalud($encounterId, $appointmentDate, $medicName, $patientName);
        }
        
        // Fall back to local Jitsi
        $logger->debug("Using local Jitsi instance for meeting creation");
        return self::createLocalJitsiMeeting($encounterId, $appointmentDate, $medicName, $patientName);
    }
    
    /**
     * Create a meeting using the telesalud backend
     *
     * @param int $encounterId The encounter ID
     * @param string $appointmentDate The appointment date/time
     * @param string $medicName The provider's name
     * @param string $patientName The patient's name
     * @return array The meeting details including URLs and backend_id
     */
    private static function createMeetingViaTelesalud(int $encounterId, string $appointmentDate, string $medicName, string $patientName): array
    {
        $logger = new SystemLogger();
        $logger->debug("Creating meeting via telesalud backend for encounter $encounterId");
        
        // Get configuration from globals with environment variable fallback
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? getenv('TELEHEALTH_BASE_URL') ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? getenv('TELEHEALTH_API_TOKEN') ?? '';
        
        $logger->debug("Using API configuration", [
            'apiUrl' => $apiUrl,
            'apiToken' => substr($apiToken, 0, 10) . '...' // Only log first 10 chars of token for security
        ]);
        
        if (empty($apiUrl) || empty($apiToken)) {
            $logger->error("Telesalud API URL or token not configured, falling back to local Jitsi");
            return self::createLocalJitsiMeeting($encounterId, $appointmentDate, $medicName, $patientName);
        }
        
        try {
            // Initialize the telesalud client
            $client = new TelesaludClient($apiUrl, $apiToken, $logger);
            
            // Format the appointment date for the API
            $startTime = date('c', strtotime($appointmentDate));
            
            // Additional options for the meeting
            $options = [
                'days_before_expiration' => $GLOBALS['telesalud_days_before_expiration'] ?? 7,
            ];
            
            // Create the meeting
            $meeting = $client->createMeeting($encounterId, $medicName, $patientName, $startTime, $options);
            
            $logger->debug("Meeting created successfully via telesalud backend with ID: " . $meeting['backend_id']);
            
            // Return structure that matches what the rest of the system expects
            return [
                'success' => true,
                'url' => $meeting['medic_url'],  // Default URL for backward compatibility
                'meeting_url' => $meeting['medic_url'],  // Provider URL
                'medic_url' => $meeting['medic_url'],
                'patient_url' => $meeting['patient_url'],
                'backend_id' => $meeting['backend_id'],
                'medic_id' => $meeting['medic_id'],  // Store this for future API calls!
                'data_url' => $meeting['data_url'] ?? null,
                'valid_from' => $meeting['valid_from'] ?? null,
                'valid_to' => $meeting['valid_to'] ?? null,
            ];
            
        } catch (\Exception $e) {
            $logger->error("Error creating meeting via telesalud backend: " . $e->getMessage());
            $logger->error("Falling back to local Jitsi");
            
            // Fall back to local Jitsi if the telesalud backend fails
            return self::createLocalJitsiMeeting($encounterId, $appointmentDate, $medicName, $patientName);
        }
    }
    
    /**
     * Create a meeting using a local Jitsi instance
     *
     * @param int $encounterId The encounter ID
     * @param string $appointmentDate The appointment date/time
     * @param string $medicName The provider's name
     * @param string $patientName The patient's name
     * @return array The meeting details including URL
     */
    private static function createLocalJitsiMeeting(int $encounterId, string $appointmentDate, string $medicName, string $patientName): array
    {
        $logger = new SystemLogger();
        $logger->debug("Creating local Jitsi meeting for encounter $encounterId");
        
        // Generate a unique room name based on the encounter ID
        $roomName = self::generateRoomName($encounterId);
        
        // Get Jitsi server URL from globals or use default
        $jitsiServer = $GLOBALS['jitsi_base_url'] ?? 'https://meet.jit.si';
        
        // Build the meeting URL
        $meetingUrl = $jitsiServer . '/' . $roomName;
        
        $logger->debug("Local Jitsi meeting created with URL: $meetingUrl");
        
        return [
            'success' => true,
            'url' => $meetingUrl,
            'meeting_url' => $meetingUrl,
            'medic_url' => $meetingUrl,
            'patient_url' => $meetingUrl,
            'backend_id' => null, // No backend ID for local Jitsi meetings
            'medic_id' => null,   // No medic ID for local Jitsi meetings
        ];
    }
    
    /**
     * Generate a unique room name for a Jitsi meeting
     *
     * @param int $encounterId The encounter ID
     * @return string The room name
     */
    private static function generateRoomName(int $encounterId): string
    {
        // Generate a random string to make the room name unique
        $randomString = substr(md5(uniqid(mt_rand(), true)), 0, 10);
        
        // Combine encounter ID and random string
        return 'openemr-' . $encounterId . '-' . $randomString;
    }
    
    /**
     * Get meeting details for an existing meeting
     *
     * @param string $backendId The backend ID of the meeting
     * @return array|null The meeting details or null if not found
     */
    public static function getMeeting(string $backendId): ?array
    {
        $logger = new SystemLogger();
        $logger->debug("Getting meeting details for backend ID: $backendId");
        
        // Check if we should use telesalud backend
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? '';
        if ($telehealth_mode !== 'telesalud' || empty($backendId)) {
            return null;
        }
        
        // Get configuration from globals with environment variable fallback
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? getenv('TELEHEALTH_BASE_URL') ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? getenv('TELEHEALTH_API_TOKEN') ?? '';
        
        $logger->debug("Using API configuration", [
            'apiUrl' => $apiUrl,
            'apiToken' => substr($apiToken, 0, 10) . '...' // Only log first 10 chars of token for security
        ]);
        
        if (empty($apiUrl) || empty($apiToken)) {
            $logger->error("Telesalud API URL or token not configured");
            return null;
        }
        
        try {
            // First, get the medic_id from our database
            $row = sqlQuery("SELECT medic_id FROM telehealth_vc WHERE backend_id = ?", [$backendId]);
            if (!$row || empty($row['medic_id'])) {
                $logger->error("Medic ID not found in database for backend ID: $backendId");
                return null;
            }
            
            $medicId = $row['medic_id'];
            
            // Initialize the telesalud client
            $client = new TelesaludClient($apiUrl, $apiToken, $logger);
            
            // Get the meeting details using both required parameters
            return $client->getMeeting($backendId, $medicId);
            
        } catch (\Exception $e) {
            $logger->error("Error getting meeting details: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Finish a meeting
     *
     * @param string $backendId The backend ID of the meeting
     * @param string|null $notes Optional clinical notes
     * @return bool True if successful, false otherwise
     */
    public static function finishMeeting(string $backendId, ?string $notes = null): bool
    {
        $logger = new SystemLogger();
        $logger->debug("Finishing meeting with backend ID: $backendId");
        
        // Check if we should use telesalud backend - check both globals and environment variables
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? getenv('TELEHEALTH_MODE') ?? '';
        
        // If environment variable TELEHEALTH_BASE_URL is set, assume telesalud mode
        if (getenv('TELEHEALTH_BASE_URL') && empty($telehealth_mode)) {
            $telehealth_mode = 'telesalud';
            $logger->debug("Detected TELEHEALTH_BASE_URL environment variable, forcing telesalud mode");
        }
        
        if ($telehealth_mode !== 'telesalud' || empty($backendId)) {
            return false;
        }
        
        // Note: The telesalud API doesn't have a /finish endpoint
        // Meetings are automatically marked as finished when participants leave
        $logger->info("Telesalud API doesn't support explicit meeting finish - meetings auto-finish when participants leave");
        
        // We could potentially update our local database to mark it as finished
        try {
            sqlStatement("UPDATE telehealth_vc SET finished_at = NOW() WHERE backend_id = ?", [$backendId]);
            return true;
        } catch (\Exception $e) {
            $logger->error("Error updating local meeting status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get WebSocket connection details for real-time notifications
     *
     * @return array|null The WebSocket details or null if not available
     */
    public static function getWebSocketDetails(): ?array
    {
        $logger = new SystemLogger();
        $logger->debug("Getting WebSocket connection details");
        
        // Check if we should use telesalud backend - check both globals and environment variables
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? getenv('TELEHEALTH_MODE') ?? '';
        
        // If environment variable TELEHEALTH_BASE_URL is set, assume telesalud mode
        if (getenv('TELEHEALTH_BASE_URL') && empty($telehealth_mode)) {
            $telehealth_mode = 'telesalud';
            $logger->debug("Detected TELEHEALTH_BASE_URL environment variable, forcing telesalud mode");
        }
        
        if ($telehealth_mode !== 'telesalud') {
            return null;
        }
        
        // Note: The telesalud API doesn't have WebSocket endpoints
        // Real-time notifications would need to be implemented differently
        $logger->info("Telesalud API doesn't support WebSocket connections - real-time notifications not available");
        
            return null;
    }
    
    /**
     * Test the connection to the telesalud backend
     *
     * @return bool True if connection is successful, false otherwise
     */
    public static function testConnection(): bool
    {
        $logger = new SystemLogger();
        $logger->debug("Testing connection to telesalud backend");
        
        // Get configuration from globals with environment variable fallback
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? getenv('TELEHEALTH_BASE_URL') ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? getenv('TELEHEALTH_API_TOKEN') ?? '';
        
        $logger->debug("Using API configuration", [
            'apiUrl' => $apiUrl,
            'apiToken' => substr($apiToken, 0, 10) . '...' // Only log first 10 chars of token for security
        ]);
        
        if (empty($apiUrl) || empty($apiToken)) {
            $logger->error("Telesalud API URL or token not configured");
            return false;
        }
        
        try {
            // Initialize the telesalud client
            $client = new TelesaludClient($apiUrl, $apiToken, $logger);
            
            // Test the connection
            return $client->testConnection();
            
        } catch (\Exception $e) {
            $logger->error("Error testing connection: " . $e->getMessage());
            return false;
        }
    }
}
