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

namespace Telehealth\Classes;

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
        $logger->debug("Creating meeting for encounter $encounterId");
        
        // Check if we should use telesalud backend
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? '';
        if ($telehealth_mode === 'telesalud') {
            return self::createMeetingViaTelesalud($encounterId, $appointmentDate, $medicName, $patientName);
        }
        
        // Fall back to local Jitsi
        return self::createLocalJitsiMeeting($encounterId, $appointmentDate, $medicName, $patientName);
    }
    
    /**
     * Create a meeting using the telesalud backend
     *
     * @param int $encounterId The encounter ID
     * @param string $appointmentDate The appointment date/time
     * @param string $medicName The provider's name
     * @param string $patientName The patient's name
     * @return array The meeting details including URL and backend_id
     */
    private static function createMeetingViaTelesalud(int $encounterId, string $appointmentDate, string $medicName, string $patientName): array
    {
        $logger = new SystemLogger();
        $logger->debug("Creating meeting via telesalud backend for encounter $encounterId");
        
        // Get configuration from globals
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
        
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
                'duration' => 60, // Default to 60 minutes
                'waiting_room' => true,
                'recording' => false,
            ];
            
            // Create the meeting
            $meeting = $client->createMeeting($encounterId, $medicName, $patientName, $startTime, $options);
            
            $logger->debug("Meeting created successfully via telesalud backend with ID: " . $meeting['backend_id']);
            
            return [
                'url' => $meeting['meeting_url'],
                'backend_id' => $meeting['backend_id'],
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
        $jitsiServer = $GLOBALS['telehealth_jitsi_server'] ?? 'https://meet.jit.si';
        
        // Build the meeting URL
        $meetingUrl = $jitsiServer . '/' . $roomName;
        
        $logger->debug("Local Jitsi meeting created with URL: $meetingUrl");
        
        return [
            'url' => $meetingUrl,
            'backend_id' => null, // No backend ID for local Jitsi meetings
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
        
        // Get configuration from globals
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
        
        if (empty($apiUrl) || empty($apiToken)) {
            $logger->error("Telesalud API URL or token not configured");
            return null;
        }
        
        try {
            // Initialize the telesalud client
            $client = new TelesaludClient($apiUrl, $apiToken, $logger);
            
            // Get the meeting details
            return $client->getMeeting($backendId);
            
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
        
        // Check if we should use telesalud backend
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? '';
        if ($telehealth_mode !== 'telesalud' || empty($backendId)) {
            return false;
        }
        
        // Get configuration from globals
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
        
        if (empty($apiUrl) || empty($apiToken)) {
            $logger->error("Telesalud API URL or token not configured");
            return false;
        }
        
        try {
            // Initialize the telesalud client
            $client = new TelesaludClient($apiUrl, $apiToken, $logger);
            
            // Finish the meeting
            $client->finishMeeting($backendId, $notes);
            return true;
            
        } catch (\Exception $e) {
            $logger->error("Error finishing meeting: " . $e->getMessage());
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
        
        // Check if we should use telesalud backend
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? '';
        if ($telehealth_mode !== 'telesalud') {
            return null;
        }
        
        // Get configuration from globals
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
        
        if (empty($apiUrl) || empty($apiToken)) {
            $logger->error("Telesalud API URL or token not configured");
            return null;
        }
        
        try {
            // Initialize the telesalud client
            $client = new TelesaludClient($apiUrl, $apiToken, $logger);
            
            // Get WebSocket details
            return $client->getWebSocketDetails();
            
        } catch (\Exception $e) {
            $logger->error("Error getting WebSocket details: " . $e->getMessage());
            return null;
        }
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
        
        // Get configuration from globals
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
        
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
