<?php
/**
 * Real-time Notifications API Endpoint
 * 
 * Retrieves and marks as read the latest notifications for the current provider
 * to be displayed as toast notifications in the frontend.
 * 
 * @package OpenEMR
 * @subpackage Telehealth
 */

require_once __DIR__ . '/../../../../../interface/globals.php';

// Only allow authenticated users
if (!isset($_SESSION['authUserID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

/**
 * Get unread notifications for the current provider
 */
function getUnreadNotifications($provider_id = null)
{
    // If no provider_id specified, get notifications for all providers
    // This allows system-wide notifications
    $sql = "
        SELECT 
            id,
            pc_eid,
            pid,
            encounter_id,
            backend_id,
            topic,
            title,
            message,
            patient_name,
            created_at
        FROM telehealth_realtime_notifications 
        WHERE is_read = 0
    ";
    
    $params = [];
    
    if ($provider_id) {
        $sql .= " AND (provider_id = ? OR provider_id IS NULL)";
        $params[] = $provider_id;
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT 20";
    
    try {
        $result = sqlStatement($sql, $params);
        $notifications = [];
        
        while ($row = sqlFetchArray($result)) {
            $notifications[] = [
                'id' => $row['id'],
                'pc_eid' => $row['pc_eid'],
                'pid' => $row['pid'],
                'encounter_id' => $row['encounter_id'],
                'backend_id' => $row['backend_id'],
                'topic' => $row['topic'],
                'title' => $row['title'],
                'message' => $row['message'],
                'patient_name' => $row['patient_name'],
                'created_at' => $row['created_at'],
                'meeting_url' => generateMeetingUrl($row['encounter_id'], $row['pc_eid'])
            ];
        }
        
        return $notifications;
        
    } catch (Exception $e) {
        error_log("Telehealth notifications API error: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notifications as read
 */
function markNotificationsAsRead($notification_ids)
{
    if (empty($notification_ids)) {
        return true;
    }
    
    $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
    $sql = "UPDATE telehealth_realtime_notifications SET is_read = 1 WHERE id IN ($placeholders)";
    
    try {
        sqlStatement($sql, $notification_ids);
        return true;
    } catch (Exception $e) {
        error_log("Telehealth: Error marking notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate meeting URL for starting telehealth session
 */
function generateMeetingUrl($encounter_id, $pc_eid)
{
    $webroot = $GLOBALS['webroot'] ?? '';
    return $webroot . "/interface/modules/custom_modules/oe-module-telehealth/public/index.php?action=start&role=provider&eid=" . ($encounter_id ?? $pc_eid);
}

// Main execution
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $current_user_id = $_SESSION['authUserID'] ?? null;
    
    switch ($method) {
        case 'GET':
            // Get unread notifications
            $notifications = getUnreadNotifications($current_user_id);
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'count' => count($notifications)
            ]);
            break;
            
        case 'POST':
            // Mark notifications as read
            $input = json_decode(file_get_contents('php://input'), true);
            $notification_ids = $input['notification_ids'] ?? [];
            
            if (!empty($notification_ids)) {
                $success = markNotificationsAsRead($notification_ids);
                echo json_encode([
                    'success' => $success,
                    'marked_read' => count($notification_ids)
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No notification IDs provided'
                ]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Telehealth notifications API exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?> 