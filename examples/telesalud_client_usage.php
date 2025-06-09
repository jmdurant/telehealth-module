<?php
/**
 * Example usage of the TelesaludClient class
 *
 * This file demonstrates how to use the TelesaludClient class to interact
 * with the telesalud backend API in various scenarios.
 *
 * @package OpenEMR
 * @subpackage Telehealth
 */

// Include necessary files
require_once dirname(__FILE__, 3) . "/interface/globals.php";
require_once dirname(__FILE__, 2) . "/src/Classes/TelesaludClient.php";

use Telehealth\Classes\TelesaludClient;
use OpenEMR\Common\Logging\SystemLogger;

/**
 * Example 1: Basic initialization and connection test
 */
function exampleBasicUsage() {
    // Get configuration from globals
    $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
    $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
    
    if (empty($apiUrl) || empty($apiToken)) {
        echo "Telesalud API URL or token not configured.\n";
        return;
    }
    
    // Create logger
    $logger = new SystemLogger();
    
    // Initialize client
    $client = new TelesaludClient($apiUrl, $apiToken, $logger);
    
    // Test connection
    try {
        $isConnected = $client->testConnection();
        echo "Connection test result: " . ($isConnected ? "Success" : "Failed") . "\n";
        
        if ($isConnected) {
            // Get server status
            $status = $client->getServerStatus();
            echo "Server status: " . ($status['status'] ?? 'unknown') . "\n";
            echo "Server version: " . ($status['version'] ?? 'unknown') . "\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

/**
 * Example 2: Creating a meeting
 */
function exampleCreateMeeting($encounterId, $providerName, $patientName, $startTime) {
    // Get configuration from globals
    $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
    $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
    
    // Initialize client
    $client = new TelesaludClient($apiUrl, $apiToken);
    
    try {
        // Create meeting with additional options
        $options = [
            'duration' => 30, // minutes
            'waiting_room' => true,
            'recording' => false,
        ];
        
        $meeting = $client->createMeeting($encounterId, $providerName, $patientName, $startTime, $options);
        
        echo "Meeting created successfully!\n";
        echo "Backend ID: " . $meeting['backend_id'] . "\n";
        echo "Meeting URL: " . $meeting['meeting_url'] . "\n";
        
        // Store meeting details in database
        saveMeetingToDatabase($encounterId, $meeting);
        
    } catch (Exception $e) {
        echo "Error creating meeting: " . $e->getMessage() . "\n";
    }
}

/**
 * Example 3: Retrieving meeting details
 */
function exampleGetMeeting($backendId) {
    // Get configuration from globals
    $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
    $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
    
    // Initialize client
    $client = new TelesaludClient($apiUrl, $apiToken);
    
    try {
        // Get meeting details
        $meeting = $client->getMeeting($backendId);
        
        echo "Meeting details:\n";
        echo "ID: " . ($meeting['id'] ?? 'unknown') . "\n";
        echo "Status: " . ($meeting['status'] ?? 'unknown') . "\n";
        echo "URL: " . ($meeting['url'] ?? 'unknown') . "\n";
        echo "Provider: " . ($meeting['provider_name'] ?? 'unknown') . "\n";
        echo "Patient: " . ($meeting['patient_name'] ?? 'unknown') . "\n";
        echo "Start time: " . ($meeting['start_time'] ?? 'unknown') . "\n";
        
    } catch (Exception $e) {
        echo "Error retrieving meeting: " . $e->getMessage() . "\n";
    }
}

/**
 * Example 4: Finishing a meeting with clinical notes
 */
function exampleFinishMeeting($backendId, $clinicalNotes) {
    // Get configuration from globals
    $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
    $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
    
    // Initialize client
    $client = new TelesaludClient($apiUrl, $apiToken);
    
    try {
        // Finish meeting with clinical notes
        $result = $client->finishMeeting($backendId, $clinicalNotes);
        
        echo "Meeting finished successfully!\n";
        echo "Status: " . ($result['status'] ?? 'unknown') . "\n";
        
        // Update meeting status in database
        updateMeetingStatusInDatabase($backendId, 'finished');
        
    } catch (Exception $e) {
        echo "Error finishing meeting: " . $e->getMessage() . "\n";
    }
}

/**
 * Example 5: Getting WebSocket connection details
 */
function exampleGetWebSocketDetails() {
    // Get configuration from globals
    $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
    $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
    
    // Initialize client
    $client = new TelesaludClient($apiUrl, $apiToken);
    
    try {
        // Get WebSocket connection details
        $wsDetails = $client->getWebSocketDetails();
        
        echo "WebSocket details:\n";
        echo "URL: " . $wsDetails['url'] . "\n";
        echo "Token: " . substr($wsDetails['token'], 0, 10) . "...\n";
        
        // Generate JavaScript to connect to WebSocket
        $jsCode = "
        const socket = new WebSocket('" . $wsDetails['url'] . "');
        socket.onopen = function(e) {
            console.log('WebSocket connection established');
            socket.send(JSON.stringify({
                type: 'authenticate',
                token: '" . $wsDetails['token'] . "'
            }));
        };
        ";
        
        echo "JavaScript code to connect:\n$jsCode\n";
        
    } catch (Exception $e) {
        echo "Error getting WebSocket details: " . $e->getMessage() . "\n";
    }
}

/**
 * Helper function to save meeting to database (example only)
 */
function saveMeetingToDatabase($encounterId, $meeting) {
    // This is just an example - in a real implementation, you would use proper database functions
    echo "Saving meeting to database for encounter $encounterId...\n";
    
    // In reality, you would do something like:
    // sqlStatement(
    //     "INSERT INTO telehealth_vc (pc_eid, vc_url, backend_id) VALUES (?, ?, ?)",
    //     [$encounterId, $meeting['meeting_url'], $meeting['backend_id']]
    // );
}

/**
 * Helper function to update meeting status in database (example only)
 */
function updateMeetingStatusInDatabase($backendId, $status) {
    // This is just an example - in a real implementation, you would use proper database functions
    echo "Updating meeting status to '$status' for backend ID $backendId...\n";
    
    // In reality, you would do something like:
    // sqlStatement(
    //     "UPDATE telehealth_vc SET status = ? WHERE backend_id = ?",
    //     [$status, $backendId]
    // );
}

// Run the examples (commented out to prevent execution)
// exampleBasicUsage();
// exampleCreateMeeting(123, 'Dr. Smith', 'John Doe', '2023-05-10T14:30:00');
// exampleGetMeeting('abc123');
// exampleFinishMeeting('abc123', 'Patient presented with symptoms of...');
// exampleGetWebSocketDetails();

echo "TelesaludClient usage examples - modify and uncomment the function calls to test.\n";
