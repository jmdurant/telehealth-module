<?php
/**
 * Manual webhook test - simulates what the telesalud backend should send
 */

// Use the backend_id from one of our actual telehealth sessions
$test_data = [
    'topic' => 'videoconsultation-finished',
    'vc' => [
        'id' => '2646bcc8db42933e4f6f65ba2c08ed81e6b7df33', // Use actual backend_id from database
        'evolution' => 'Test consultation completed successfully. Patient vital signs stable. Recommended follow-up in 2 weeks.'
    ]
];

$webhook_url = 'http://localhost/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';

echo "Testing webhook manually...\n";
echo "Webhook URL: $webhook_url\n";
echo "Test data: " . json_encode($test_data, JSON_PRETTY_PRINT) . "\n\n";

// Initialize cURL
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $webhook_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($test_data))
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// Execute request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

curl_close($ch);

echo "Results:\n";
echo "HTTP Code: $http_code\n";
echo "Response: $response\n";

if ($curl_error) {
    echo "cURL Error: $curl_error\n";
}

echo "\nNow check the database for new encounter forms...\n";
?> 