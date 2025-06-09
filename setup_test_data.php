<?php
/**
 * Setup Test Data for Evolution Integration
 */

// Database connection
$host = 'localhost';
$dbname = 'openemr';
$username = 'openemr';
$password = 'openemr';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Setting up test data...\n";
    
    // 1. Create calendar appointment
    echo "1. Creating calendar appointment...\n";
    $stmt = $pdo->prepare("
        INSERT INTO openemr_postcalendar_events 
        (pc_eid, pc_pid, pc_aid, pc_catid, pc_title, pc_eventDate, pc_startTime, pc_endTime) 
        VALUES (8001, 10, 1, 16, 'Test Evolution Consultation', CURDATE(), '10:00:00', '10:30:00')
        ON DUPLICATE KEY UPDATE pc_title = 'Test Evolution Consultation'
    ");
    $stmt->execute();
    echo "   ✅ Calendar appointment created (pc_eid: 8001)\n";
    
    // 2. Create/update telehealth_vc record with evolution data
    echo "2. Creating telehealth consultation record...\n";
    $evolution_text = "Patient presented with chief complaint of headache for the past 3 days. Pain described as throbbing, located in the frontal region, 7/10 intensity.

PHYSICAL EXAM:
- Vital signs: BP 130/85, HR 78, Temp 98.6°F
- Head: No tenderness, no swelling  
- Neurological: Alert and oriented x3, no focal deficits

ASSESSMENT:
Tension-type headache, likely stress-related

PLAN:
1. Ibuprofen 400mg every 6 hours as needed
2. Stress management techniques
3. Follow up in 1 week if symptoms persist
4. Return immediately if severe or worsening symptoms";

    $stmt = $pdo->prepare("
        INSERT INTO telehealth_vc 
        (pc_eid, data_id, backend_id, encounter, evolution, active) 
        VALUES (8001, 'test-evolution-123', 'test-evolution-123', 1001, ?, 1)
        ON DUPLICATE KEY UPDATE evolution = ?, backend_id = 'test-evolution-123'
    ");
    $stmt->execute([$evolution_text, $evolution_text]);
    echo "   ✅ Telehealth consultation record created\n";
    
    echo "\n3. Test data setup complete!\n";
    echo "   - pc_eid: 8001\n";
    echo "   - patient: 10\n";
    echo "   - encounter: 1001\n";
    echo "   - backend_id: test-evolution-123\n";
    echo "   - evolution text: " . strlen($evolution_text) . " characters\n\n";
    
    echo "Ready to test webhook!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 