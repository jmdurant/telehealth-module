<?php

/**
 * Handles API requests for patient portal.
 *
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Landing page definition -- where to go if something goes wrong
// This should trim the following path /interface/modules/custom_modules/telehealth-module/public/
// This should get us to the main openemr directory and include the webroot path if we have it
$originalPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = dirname(dirname(dirname(dirname(dirname(dirname($originalPath))))));
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$redirect = $originalPath . "?";
if (!empty($query)) {
    $redirect .= $query;
}
// Need to retain the webroot if we have one
$landingpage = $basePath . "portal/index.php?site=" . urlencode($_GET['site_id'] ?? '') . "&redirect=" . urlencode($redirect);
$skipLandingPageError = true;

// Since we are working inside the portal we have to use the portal session verification logic here
require_once "../../../../../portal/verify_session.php";

use OpenEMR\Modules\Telehealth\Bootstrap;
use OpenEMR\Common\Logging\SystemLogger;

// Get the kernel and event dispatcher
$kernel = $GLOBALS['kernel'];
$logger = new SystemLogger();

try {
    $bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel);
    $roomController = $bootstrap->getTeleconferenceRoomController(true);
    
    // Process the request
    $action = $_GET['action'] ?? '';
    $queryVars = $_GET ?? [];
    
    // SECURITY: Ensure we're only working with the logged-in patient
    $queryVars['pid'] = $_SESSION['pid'] ?? 0; 
    
    // Add CSRF token if available
    if (!empty($_SERVER['HTTP_APICSRFTOKEN'])) {
        $queryVars['csrf_token'] = $_SERVER['HTTP_APICSRFTOKEN'];
    }
    
    // Handle special action for getting settings
    if ($action === 'get_telehealth_settings') {
        header('Content-Type: application/javascript');
        echo "// Telehealth settings\n";
        echo "window.telehealth = window.telehealth || {};\n";
        echo "window.telehealth.settings = " . json_encode([
            'moduleUrl' => $GLOBALS['web_root'] . "/interface/modules/custom_modules/oe-module-telehealth/public",
            'debug' => $bootstrap->getGlobalConfig()->isDebugModeEnabled()
        ]) . ";\n";
        exit;
    }
    
    // Dispatch to controller
    $roomController->dispatch($action, $queryVars);
} catch (Exception $e) {
    $logger->error("Telehealth Portal Error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'An error occurred processing your request']);
}
exit; 