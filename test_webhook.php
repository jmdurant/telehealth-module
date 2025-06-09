<?php
/**
 * Test webhook manually with sample data
 */

// Sample webhook data that should come from backend
$webhookData = [
    'topic' => 'videoconsultation-finished',
    'vc' => [
        'id' => 'cf361716d4feff06276a3e77416d9a0945695b53', // Use the current backend_id
        'secret' => 'cf361716d4feff06276a3e77416d9a0945695b53'
    ]
];

// Convert to JSON
$jsonData = json_encode($webhookData);

echo "Testing webhook with data:\n";
echo $jsonData . "\n\n";

// Make POST request to our webhook - use the correct staging domain
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://staging-notes.localhost/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
if ($error) {
    echo "Error: $error\n";
}

// Additional status check
if ($httpCode == 200) {
    echo "✅ Webhook test successful!\n";
} else {
    echo "❌ Webhook test failed with HTTP $httpCode\n";
}
?> 