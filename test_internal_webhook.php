<?php
/**
 * Test webhook with proper Docker networking
 */

// Test different possible webhook URLs for container networking
$webhook_urls = [
    'http://localhost/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php',
    'http://127.0.0.1/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php',
    'http://official-staging-openemr-1/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php',
];

$test_data = [
    'topic' => 'videoconsultation-finished',
    'vc' => [
        'id' => '2646bcc8db42933e4f6f65ba2c08ed81e6b7df33',
        'evolution' => 'Test from container network'
    ]
];

foreach ($webhook_urls as $url) {
    echo "Testing URL: $url\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "  HTTP Code: $http_code\n";
    echo "  Response: " . substr($response, 0, 100) . "\n";
    if ($error) {
        echo "  Error: $error\n";
    }
    echo "  " . ($http_code == 200 ? "✅ SUCCESS" : "❌ FAILED") . "\n\n";
}
?> 