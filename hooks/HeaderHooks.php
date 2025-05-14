<?php
namespace Telehealth\Hooks;

/**
 * Hooks into the OpenEMR header system to inject the waiting room notification
 * JavaScript for providers.
 * 
 * This version is designed to be compatible with different OpenEMR versions.
 */
class HeaderHooks
{
    /**
     * Register with the global event dispatcher if available
     */
    public static function register()
    {
        // Check if the WordPress-style hook system is available
        if (function_exists('add_action')) {
            // Add our JS file to every page for providers
            add_action('header_setupheader_post', [self::class, 'injectWaitingRoomScript']);
        } else {
            // For OpenEMR versions without the hook system, we'll use a different approach
            // The script will be included via the module's UI pages instead
            error_log('Telehealth Module: add_action function not available, header hooks not registered');
        }
    }

    /**
     * Inject the waiting room notification script for providers
     * 
     * Only injects when:
     * 1. User is logged in
     * 2. User has access to patients (clinical staff)
     * 3. Telehealth mode is set to telesalud
     */
    public static function injectWaitingRoomScript()
    {
        global $GLOBALS;

        // Only for logged-in users with patient access
        if (!isset($_SESSION['authUserID']) || empty($_SESSION['authUserID'])) {
            return;
        }

        // Only when telehealth mode is telesalud (if configured)
        // Skip this check if the global isn't set to allow for different configurations
        if (isset($GLOBALS['telehealth_mode'])) {
            $telehealth_mode = $GLOBALS['telehealth_mode'];
            if ($telehealth_mode !== 'telesalud') {
                return;
            }
        }

        // Check if user has access to patients (clinical staff)
        // Use acl_check if available, otherwise assume access is granted
        $hasAccess = true;
        if (function_exists('acl_check')) {
            $hasAccess = acl_check('patients', '', $_SESSION['authUserID']);
        }
        if (!$hasAccess) {
            return;
        }

        // Get API URL and token from globals with fallbacks
        $apiUrl = '';
        $apiToken = '';
        
        // Check if the globals are set
        if (isset($GLOBALS['telesalud_api_url'])) {
            $apiUrl = $GLOBALS['telesalud_api_url'];
        }
        
        if (isset($GLOBALS['telesalud_api_token'])) {
            $apiToken = $GLOBALS['telesalud_api_token'];
        }
        
        // Skip if API settings are not configured
        if (empty($apiUrl) || empty($apiToken)) {
            error_log("Telehealth: Missing API URL or token for waiting room notifications");
            return;
        }

        // Inject configuration variables
        echo "<script>
            window.TELEHEALTH_API_URL = " . json_encode($apiUrl) . ";
            window.TELEHEALTH_API_TOKEN = " . json_encode($apiToken) . ";
        </script>";
        
        // Inject the waiting room script - use the correct module path
        $scriptPath = $GLOBALS['rootdir'] . "/../interface/modules/custom_modules/oe-module-telehealth/public/waiting_room.js";
        echo "<script src='" . $scriptPath . "'></script>";
    }
}
