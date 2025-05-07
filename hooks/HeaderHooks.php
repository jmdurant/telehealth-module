<?php
namespace Telehealth\Hooks;

use OpenEMR\Core\Header;

/**
 * Hooks into the OpenEMR header system to inject the waiting room notification
 * JavaScript for providers.
 */
class HeaderHooks
{
    /**
     * Register with the global event dispatcher
     */
    public static function register(): void
    {
        // Add our JS file to every page for providers
        // This is done by hooking into the Header::setupHeader method
        // which is called on every page in OpenEMR
        add_action('header_setupheader_post', [self::class, 'injectWaitingRoomScript']);
    }

    /**
     * Inject the waiting room notification script for providers
     * 
     * Only injects when:
     * 1. User is logged in
     * 2. User has access to patients (clinical staff)
     * 3. Telehealth mode is set to telesalud
     */
    public static function injectWaitingRoomScript(): void
    {
        global $GLOBALS;

        // Only for logged-in users with patient access
        if (!isset($_SESSION['authUserID']) || empty($_SESSION['authUserID'])) {
            return;
        }

        // Only when telehealth mode is telesalud
        $telehealth_mode = $GLOBALS['telehealth_mode'] ?? '';
        if ($telehealth_mode !== 'telesalud') {
            return;
        }

        // Check if user has access to patients (clinical staff)
        $hasAccess = acl_check('patients', '', $_SESSION['authUserID']);
        if (!$hasAccess) {
            return;
        }

        // Get API URL and token from globals
        $apiUrl = $GLOBALS['telesalud_api_url'] ?? '';
        $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
        
        if (empty($apiUrl) || empty($apiToken)) {
            error_log("Telehealth: Missing API URL or token for waiting room notifications");
            return;
        }

        // Inject configuration variables
        echo "<script>
            window.TELEHEALTH_API_URL = " . json_encode($apiUrl) . ";
            window.TELEHEALTH_API_TOKEN = " . json_encode($apiToken) . ";
        </script>";
        
        // Inject the waiting room script
        echo "<script src='" . $GLOBALS['rootdir'] . "/../modules/telehealth/public/waiting_room.js'></script>";
    }
}
