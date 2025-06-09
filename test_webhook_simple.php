<?php
/**
 * Simple webhook test - directly tests the webhook processing
 */

echo "Testing webhook processing function directly...\n";

// Include the webhook file to get access to its functions
include_once '/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';

// Test data using actual backend_id from database
$test_data = [
    'topic' => 'videoconsultation-finished',
    'vc' => [
        'id' => '2646bcc8db42933e4f6f65ba2c08ed81e6b7df33', // Use actual backend_id from database
        'evolution' => 'Test consultation completed successfully via webhook test.'
    ]
];

echo "Test data: " . json_encode($test_data, JSON_PRETTY_PRINT) . "\n";

// Call the processNotification function directly
try {
    $result = processNotification($test_data);
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nChecking if encounter form was created...\n";

// Check if forms were created
$conn = getDbConnection();
if ($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM forms WHERE formdir = 'telehealth_notes'");
    $row = $result->fetch_assoc();
    echo "Total telehealth forms: " . $row['count'] . "\n";
    
    $result = $conn->query("SELECT * FROM telehealth_vc_log ORDER BY id DESC LIMIT 3");
    echo "Recent log entries:\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - " . $row['status'] . ": " . $row['response'] . "\n";
    }
    
    $conn->close();
}

echo "Test completed.\n";
?> 