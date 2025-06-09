<?php
/**
 * Create James DuRant Test Consultation
 */

// Database connection
$host = 'mysql';
$dbname = 'openemr'; 
$username = 'root';
$password = 'root';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Setting up James DuRant consultation test...\n\n";
    
    // Create telehealth consultation record for James DuRant
    echo "1. Creating telehealth consultation for pc_eid 7 (James DuRant)...\n";
    
    $evolution_text = "JAMES DURANT TELEHEALTH CONSULTATION

CHIEF COMPLAINT:
Patient reports fatigue and occasional dizziness for the past week.

HISTORY OF PRESENT ILLNESS:
- Fatigue started approximately 7 days ago
- Describes as feeling tired all the time  
- Dizziness occurs mainly when standing up quickly
- No fever, no chest pain
- Sleep pattern unchanged

PHYSICAL EXAM (Virtual):
- Patient appears alert and oriented
- Speech clear and coherent
- No obvious distress noted
- Vital signs per patient: BP ~140/90, HR ~75

ASSESSMENT:
- Possible orthostatic hypotension
- Rule out dehydration  
- Consider medication review

PLAN:
1. Increase fluid intake to 8-10 glasses per day
2. Rise slowly from sitting/lying positions
3. Blood pressure check in office within 1 week
4. Lab work: CBC, CMP, TSH if symptoms persist
5. Return for follow-up in 2 weeks or sooner if symptoms worsen

Patient questions answered. Plan discussed and agreed upon.";

    $stmt = $pdo->prepare("
        INSERT INTO telehealth_vc 
        (pc_eid, data_id, backend_id, encounter, evolution, active) 
        VALUES (7, 'durant-consultation-530', 'durant-consultation-530', 2001, ?, 1)
        ON DUPLICATE KEY UPDATE evolution = ?, backend_id = 'durant-consultation-530'
    ");
    $stmt->execute([$evolution_text, $evolution_text]);
    echo "   ✅ Telehealth consultation created for James DuRant\n\n";
    
    echo "2. Testing webhook system...\n";
    
    // Test webhook payload
    $webhook_url = 'http://vc-staging.localhost/interface/modules/custom_modules/oe-module-telehealth/api/notifications_simple.php';
    $test_payload = [
        'topic' => 'videoconsultation-finished',
        'vc' => [
            'id' => 'durant-consultation-530',
            'secret' => 'durant-consultation-530'
        ]
    ];
    
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
    
    echo "   Webhook Response: HTTP $http_code\n";
    echo "   Response: $response\n\n";
    
    echo "3. ✅ Test completed!\n";
    echo "   - Check OpenEMR for encounter form with clinical notes\n";
    echo "   - Look for 'Telehealth Visit Notes' form in encounter 2001\n";
    echo "   - Should contain James DuRant's clinical notes\n\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 