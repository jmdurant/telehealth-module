<?php
/**
 * Test Webhook Script - IFRAME FIX VERSION
 * Simulates a webhook notification to test the iframe fix
 */

// Include necessary OpenEMR files
require_once(__DIR__ . '/../../../../../interface/globals.php');

// Function to store realtime notification (same as in notifications_simple.php)
function storeRealtimeNotification($pc_eid, $pid, $encounter_id, $backend_id, $topic, $title, $message, $patient_name, $provider_id = null) {
    global $GLOBALS;
    
    $sql = "INSERT INTO telehealth_realtime_notifications 
            (pc_eid, pid, encounter_id, backend_id, topic, title, message, patient_name, is_read, created_at, provider_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), ?)";
    
    $params = [$pc_eid, $pid, $encounter_id, $backend_id, $topic, $title, $message, $patient_name, $provider_id];
    
    return sqlStatement($sql, $params);
}

// Create test notification
$test_data = [
    'pc_eid' => 7008,
    'pid' => 10,
    'encounter_id' => 7777,
    'backend_id' => 'iframe-fix-test-' . time(),
    'topic' => 'patient-waiting',
    'title' => 'IFRAME FIX TEST',
    'message' => 'This toast should now appear in the MAIN WINDOW, not hidden in Message Center!',
    'patient_name' => 'IFrame Fix Test Patient',
    'provider_id' => $_SESSION['authUserID'] ?? 1
];

try {
    $result = storeRealtimeNotification(
        $test_data['pc_eid'],
        $test_data['pid'],
        $test_data['encounter_id'],
        $test_data['backend_id'],
        $test_data['topic'],
        $test_data['title'],
        $test_data['message'],
        $test_data['patient_name'],
        $test_data['provider_id']
    );
    
    echo "✅ SUCCESS: IFrame Fix test notification created!\n";
    echo "Topic: {$test_data['topic']}\n";
    echo "Title: {$test_data['title']}\n";
    echo "Message: {$test_data['message']}\n";
    echo "Patient: {$test_data['patient_name']}\n";
    echo "\n🔍 The toast should now appear in the TOP-LEVEL WINDOW instead of being hidden in Message Center!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: Failed to create test notification: " . $e->getMessage() . "\n";
}
?> 