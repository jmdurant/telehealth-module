<?php
/**
 * Simple IFrame Fix Test - Direct Database Insert
 */

// Simple database connection
$host = 'localhost';
$dbname = 'openemr';
$username = 'openemr';
$password = 'openemr';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Insert test notification directly
    $sql = "INSERT INTO telehealth_realtime_notifications 
            (pc_eid, pid, encounter_id, backend_id, topic, title, message, patient_name, is_read, created_at, provider_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), ?)";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        7009,  // pc_eid
        10,    // pid
        8888,  // encounter_id
        'iframe-fix-test-' . time(),  // backend_id
        'patient-waiting',  // topic
        '🎯 IFRAME FIX TEST',  // title
        'This toast should appear in the MAIN WINDOW (not Message Center)!',  // message
        'IFrame Fix Test Patient',  // patient_name
        1      // provider_id
    ]);
    
    if ($result) {
        echo "✅ SUCCESS: IFrame Fix test notification created!\n";
        echo "📋 Check your OpenEMR main window - the toast should appear there!\n";
        echo "🔍 Topic: patient-waiting\n";
        echo "🔍 Title: 🎯 IFRAME FIX TEST\n";
        echo "\n🎉 If you see the toast in the main window (not Message Center), the iframe fix worked!\n";
    } else {
        echo "❌ ERROR: Failed to insert test notification\n";
    }
    
} catch (PDOException $e) {
    echo "❌ DATABASE ERROR: " . $e->getMessage() . "\n";
}
?> 