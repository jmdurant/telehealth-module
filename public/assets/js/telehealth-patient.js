/**
 * Patient TeleHealth methods for launching telehealth sessions from the patient portal.
 *
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function(window) {
    // Create namespace if it doesn't exist
    window.telehealth = window.telehealth || {};
    
    // Store translations for error messages
    let translations = {
        SESSION_LAUNCH_FAILED: window.xl ? window.xl('Failed to launch telehealth session') : 'Failed to launch telehealth session'
    };

    /**
     * Launch telehealth dialog when a button is clicked
     */
    function launchDialog(evt) {
        evt.preventDefault();
        
        let target = evt.currentTarget;
        if (!(target && target.dataset['pc_eid'])) {
            // If something happens inside the dialog launch its already handled
            alert(translations.SESSION_LAUNCH_FAILED);
            console.error("Event target was empty or missing data-pc_eid property");
            return;
        }
        
        var appointmentEventId = target.dataset['pc_eid'];
        launchDialogForEid(appointmentEventId);
    }

    /**
     * Launch telehealth session for a specific appointment ID
     */
    function launchDialogForEid(appointmentEventId) {
        try {
            if (!appointmentEventId) {
                throw new Error("No appointment event ID found, cannot start session");
            }
            
            // Get the base URL for telehealth
            let modulePath = window.globals.webRoot + '/interface/modules/custom_modules/telehealth-module/public';
            
            // Open telehealth window
            let telehealthUrl = modulePath + '/index.php?action=start&role=patient&eid=' + appointmentEventId;
            
            // Open in a new window
            window.open(telehealthUrl, '_blank', 'width=1000,height=800,resizable=yes');
            
        } catch (error) {
            alert(translations.SESSION_LAUNCH_FAILED);
            console.error(error);
        }
    }

    /**
     * Initialize telehealth button handlers
     */
    function init() {
        // Add event listeners to any telehealth launch buttons
        let launchButtons = document.querySelectorAll(".btn-telehealth-launch");
        for (let i = 0; i < launchButtons.length; i++) {
            launchButtons[i].addEventListener('click', launchDialog);
        }
    }

    // Initialize when page loads
    window.addEventListener('load', init);
    
    // Expose function to launch dialog by EID (for third-party integration)
    window.telehealth.launchTelehealthSession = launchDialogForEid;
    
})(window); 