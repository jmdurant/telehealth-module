(function() {
    'use strict';

    function initTelehealthCalendar() {
        // Log that telehealth calendar is initialized
        if (window.console && console.log) {
            console.log('Telehealth calendar initialized');
        }
        
        // Find all telehealth events
        const telehealthEvents = document.querySelectorAll('.event_telehealth');
        if (telehealthEvents.length > 0 && window.console && console.log) {
            console.log('Found ' + telehealthEvents.length + ' telehealth events');
        }

        // Process each telehealth event to replace user icon with video camera icon
        telehealthEvents.forEach(function(telehealthNode) {
            if (telehealthNode.clientHeight <= 20) {
                // Add condensed class for small appointments
                telehealthNode.classList.add("event_condensed");
            }

            let linkTitle = telehealthNode.querySelector('.link_title');
            if (!linkTitle) return;

            // Create video camera icon - following Comlink's exact approach
            var btn = document.createElement("i");
            btn.className = "fa fa-video mr-1 ml-1";
            
            // Set different colors based on appointment status
            if (telehealthNode.classList.contains('event_telehealth_active')) {
                btn.className = "fa fa-video text-success mr-1 ml-1"; // Green for active
            } else if (telehealthNode.classList.contains('event_telehealth_completed')) {
                btn.className = "fa fa-video text-muted mr-1 ml-1"; // Gray for completed
            } else {
                btn.className = "fa fa-video text-warning mr-1 ml-1"; // Yellow for inactive
            }

            // Find and replace the existing user icon
            let userPictureIcon = linkTitle.querySelector('.fas.fa-user, img');
            if (userPictureIcon) {
                // Copy any mouse events from the original icon
                if (userPictureIcon.onmouseover) {
                    btn.onmouseover = userPictureIcon.onmouseover;
                }
                if (userPictureIcon.onmouseout) {
                    btn.onmouseout = userPictureIcon.onmouseout;
                }
                if (userPictureIcon.title) {
                    btn.title = userPictureIcon.title;
                }
                
                // Replace the user icon with video camera icon
                userPictureIcon.parentNode.replaceChild(btn, userPictureIcon);
            }
        });
    }

    // Initialize when calendar loads
    document.addEventListener('DOMContentLoaded', initTelehealthCalendar);

    // Re-initialize when calendar updates (for AJAX updates)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                initTelehealthCalendar();
            }
        });
    });

    // Start observing calendar container
    const calendarContainer = document.getElementById('bigCal');
    if (calendarContainer) {
        observer.observe(calendarContainer, { childList: true, subtree: true });
    }
})(); 