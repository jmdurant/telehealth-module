<?php
/**
 * Upcoming Telehealth Appointments API
 * 
 * Returns upcoming telehealth appointments for the current provider
 * to enable real-time waiting room notifications.
 */

require_once dirname(__FILE__, 4) . "/interface/globals.php";

// Only for logged-in users
if (!isset($_SESSION['authUserID']) || empty($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get provider's upcoming telehealth appointments with backend IDs
$providerId = $_SESSION['authUserID'];

$sql = "SELECT 
            e.pc_eid,
            e.pc_pid,
            e.pc_title,
            e.pc_eventDate,
            e.pc_startTime,
            e.pc_apptstatus,
            vc.backend_id,
            vc.medic_id,
            p.fname,
            p.lname
        FROM openemr_postcalendar_events e
        LEFT JOIN telehealth_vc vc ON e.pc_eid = vc.encounter_id
        LEFT JOIN patient_data p ON e.pc_pid = p.pid
        WHERE e.pc_aid = ? 
            AND e.pc_eventDate >= CURDATE()
        AND e.pc_eventDate <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND vc.backend_id IS NOT NULL
        ORDER BY e.pc_eventDate, e.pc_startTime";

$result = sqlStatement($sql, [$providerId]);

$appointments = [];
while ($row = sqlFetchArray($result)) {
    // Map backend_id to encounter_id for the frontend
    $appointments[$row['backend_id']] = [
        'encounter_id' => $row['pc_eid'],
        'patient_name' => $row['fname'] . ' ' . $row['lname'],
        'appointment_date' => $row['pc_eventDate'],
        'appointment_time' => $row['pc_startTime'],
        'status' => $row['pc_apptstatus'],
        'title' => $row['pc_title']
    ];
}

header('Content-Type: application/json');
echo json_encode($appointments);
?>
