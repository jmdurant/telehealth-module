<?php
/**
 * Telehealth Upcoming Appointments API
 * 
 * Returns a mapping of backend_id to encounter_id for the current provider's
 * upcoming telehealth appointments.
 * 
 * @package OpenEMR
 * @subpackage Telehealth
 */

// Set up session and OpenEMR
require_once dirname(__FILE__, 4) . "/interface/globals.php";

// Ensure user is logged in and has appropriate permissions
if (!isset($_SESSION['authUserID']) || empty($_SESSION['authUserID'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

// Get current provider ID
$providerID = $_SESSION['authUserID'];

// Get upcoming telehealth appointments with backend_id for this provider
$sql = "SELECT 
            tv.backend_id, 
            tv.pc_eid AS encounter_id
        FROM 
            telehealth_vc tv
            JOIN openemr_postcalendar_events e ON tv.pc_eid = e.pc_eid
            JOIN openemr_postcalendar_categories c ON e.pc_catid = c.pc_catid
        WHERE 
            e.pc_aid = ? 
            AND tv.backend_id IS NOT NULL
            AND e.pc_eventDate >= CURDATE()
            AND (
                LOWER(c.pc_catname) LIKE '%telehealth%'
                OR LOWER(c.pc_catname) LIKE '%telesalud%'
                OR LOWER(c.pc_catname) LIKE '%teleconsulta%'
            )
        ORDER BY 
            e.pc_eventDate, e.pc_startTime";

$result = sqlStatement($sql, [$providerID]);

// Build mapping of backend_id -> encounter_id
$appointments = [];
while ($row = sqlFetchArray($result)) {
    if (!empty($row['backend_id'])) {
        $appointments[$row['backend_id']] = $row['encounter_id'];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($appointments);
