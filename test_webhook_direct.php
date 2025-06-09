<?php
/**
 * Direct webhook test - simulates POST data and includes the webhook directly
 */

echo "Testing webhook directly...\n";

// Simulate POST data
$test_data = [
    'topic' => 'videoconsultation-finished',
    'vc' => [
        'id' => '2646bcc8db42933e4f6f65ba2c08ed81e6b7df33', // Use actual backend_id from database
        'evolution' => 'Test consultation completed. Patient appeared well.'
    ]
];

echo "Test data: " . json_encode($test_data, JSON_PRETTY_PRINT) . "\n";

// Simulate PHP input for the webhook
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Capture the JSON input that the webhook expects
$json_input = json_encode($test_data);

// Override php://input for testing
$old_contents = file_get_contents('php://input');

// Temporarily create a test input file
file_put_contents('/tmp/test_input.json', $json_input);

// Set up environment to simulate the webhook call
putenv('REQUEST_METHOD=POST');

// Buffer output to capture the webhook response
ob_start();

// Include the webhook file directly with the test data
$GLOBALS['test_input'] = $json_input;

// Mock php://input for the webhook
if (!function_exists('file_get_contents_original')) {
    function file_get_contents_original($filename, $use_include_path = false, $context = null, $offset = 0, $maxlen = null) {
        if ($filename === 'php://input') {
            return $GLOBALS['test_input'] ?? '';
        }
        return file_get_contents($filename, $use_include_path, $context, $offset, $maxlen);
    }
}

// Include the webhook
echo "Calling webhook...\n";
try {
    include '/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';
    $output = ob_get_contents();
    echo "Webhook output: $output\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

ob_end_clean();

echo "Test completed. Check database for results.\n";
?> 