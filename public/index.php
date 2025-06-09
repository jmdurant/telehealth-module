<?php

/**
 * Main entry point for telehealth session launches
 *
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Modules\Telehealth\Bootstrap;
use OpenEMR\Common\Logging\SystemLogger;

// Determine if we're in patient context or provider context
$isPatient = !empty($_GET['role']) && ($_GET['role'] === 'patient');

if ($isPatient) {
    // For patients, we need portal verification
    $landingpage = "../../../../../portal/index.php";
    $skipLandingPageError = true;
    require_once "../../../../../portal/verify_session.php";
} else {
    // For providers, we need regular authentication
    require_once dirname(__FILE__, 5) . "/globals.php";
    if (!isset($_SESSION['authUserID'])) {
        echo "Authentication required";
        exit;
    }
}

// Get action and appointment ID
$action = $_GET['action'] ?? 'start';
$eid = $_GET['eid'] ?? 0;

// Simple validation
if (empty($eid) || !is_numeric($eid)) {
    echo "Invalid appointment ID";
    exit;
}

if ($action === 'start') {
    // Redirect to the start.php script which has the actual telehealth launch logic
    header("Location: start.php?eid=$eid&role=" . ($_GET['role'] ?? 'provider'));
    exit;
} else {
    // Load the module bootstrap to get access to controllers
    if (!$isPatient) {
        $ignoreAuth = false; // Must be defined for globals.php
    }
    
    $logger = new SystemLogger();
    
    try {
        // Initialize the bootstrap - FIXED to work with our implementation
        try {
            $logger->debug("Initializing telehealth bootstrap", [
                'is_patient' => $isPatient,
                'action' => $action,
                'has_kernel' => isset($GLOBALS['kernel']),
                'has_cached_bootstrap' => isset($GLOBALS['telehealth_bootstrap'])
            ]);
            
            // Try to get bootstrap from GLOBALS if it's set there
            if (isset($GLOBALS['telehealth_bootstrap']) && $GLOBALS['telehealth_bootstrap'] instanceof Bootstrap) {
                $bootstrap = $GLOBALS['telehealth_bootstrap'];
                $logger->debug("Using cached bootstrap instance");
            } else {
                // Create a new bootstrap instance if needed
                $eventDispatcher = $GLOBALS['kernel']->getEventDispatcher() ?? null;
                $bootstrap = new Bootstrap($eventDispatcher);
                $GLOBALS['telehealth_bootstrap'] = $bootstrap; // Cache it for future use
                $logger->debug("Created new bootstrap instance", [
                    'has_event_dispatcher' => !is_null($eventDispatcher)
                ]);
            }
        } catch (Exception $e) {
            $logger->error("Telehealth index.php: Error getting bootstrap", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('Telehealth module not properly loaded: ' . $e->getMessage());
        }

        if (!$bootstrap) {
            throw new Exception('Telehealth module not properly loaded');
        }
        
        $logger->debug("Getting teleconference room controller", [
            'is_patient' => $isPatient
        ]);
        
        $controller = $bootstrap->getTeleconferenceRoomController($isPatient);
        
        // Handle the action
        if (method_exists($controller, $action)) {
            $logger->debug("Executing controller action", [
                'action' => $action,
                'controller_class' => get_class($controller)
            ]);
            $controller->$action($_GET);
        } else {
            $logger->error("Invalid action requested", [
                'action' => $action,
                'available_methods' => get_class_methods($controller)
            ]);
            echo "Invalid action";
        }
    } catch (Exception $e) {
        $logger->error("Telehealth Error", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request' => $_GET
        ]);
        echo "An error occurred: " . $e->getMessage();
    }
} 