<?php
/**
 * Test webhook accessibility from outside
 */

// Test the webhook endpoint with a simple GET request
$webhook_url = 'https://staging-notes.localhost/openemr/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';

echo "Testing webhook accessibility...\n";
echo "URL: $webhook_url\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhook_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For localhost testing
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $http_code\n";
echo "Response: $response\n";

if ($error) {
    echo "Error: $error\n";
}

if ($http_code == 200) {
    echo "\n✅ Webhook URL is accessible!\n";
} else {
    echo "\n❌ Webhook URL is not accessible. Check configuration.\n";
}
?> 