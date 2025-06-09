<?php
/**
 * Test Evolution Data Integration
 * Tests the enhanced webhook system with evolution data
 */

$webhook_url = 'http://vc-staging.localhost/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';

// Test webhook payload simulating videoconsultation-finished
$test_payload = [
    'topic' => 'videoconsultation-finished',
    'vc' => [
        'id' => 'test-evolution-123',
        'secret' => 'test-evolution-123'
        // Note: No evolution data in webhook - should retrieve from database
    ]
];

echo "Testing Evolution Data Integration\n";
echo "=================================\n\n";

echo "1. Sending videoconsultation-finished webhook...\n";
echo "   Backend ID: test-evolution-123\n";
echo "   Evolution data should be retrieved from database\n\n";

// Send webhook
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhook_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Webhook Response:\n";
echo "HTTP Code: $http_code\n";
echo "Response: $response\n\n";

echo "2. Check the encounter form creation in OpenEMR\n";
echo "   - Look for 'Telehealth Visit Notes' form in encounter\n";
echo "   - Should contain the clinical notes from evolution field\n\n";

echo "Test completed!\n";
?> 