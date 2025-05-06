<?php
// Telehealth meeting launcher
// Usage: /modules/telehealth/public/start.php?eid=<encounter_id>&role=provider|patient

require_once __DIR__ . '/../../../globals.php';

$eid  = isset($_GET['eid']) ? intval($_GET['eid']) : 0;
$role = $_GET['role'] ?? 'provider';

if ($eid <= 0) {
    die('Invalid encounter id');
}

// Session check for providers
if ($role === 'provider' && empty($_SESSION['authUser'])) {
    die('Authentication required.');
}

// Ensure table exists (idempotent)
sqlStatement("CREATE TABLE IF NOT EXISTS telehealth_vc (id INT AUTO_INCREMENT PRIMARY KEY, encounter_id INT UNIQUE, meeting_url VARCHAR(255), medic_url VARCHAR(255), patient_url VARCHAR(255), created DATETIME DEFAULT NOW())");

// Fetch or create meeting link
$mode = strtolower($GLOBALS['telehealth_mode'] ?? 'standalone');
$field = $mode === 'telesalud' ? 'medic_url' : 'meeting_url';
$row = sqlQuery("SELECT $field AS url FROM telehealth_vc WHERE encounter_id = ?", [$eid]);
if ($row && !empty($row['url'])) {
    $meetingUrl = $row['url'];
} else {
    $slug = bin2hex(random_bytes(5));
    $meetingUrl = 'https://meet.jit.si/EMRTelevisit-' . $eid . '-' . $slug;
    sqlStatement("INSERT INTO telehealth_vc (encounter_id, meeting_url, medic_url, patient_url) VALUES (?,?,?,?)", [$eid, $meetingUrl, $meetingUrl, $meetingUrl]);
}

header('Location: ' . $meetingUrl);
exit;
