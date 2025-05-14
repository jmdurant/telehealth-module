(function() {
    'use strict';

    function initTelehealthCalendar() {
        // Find all telehealth events
        const telehealthEvents = document.querySelectorAll('.event_telehealth');
        
        telehealthEvents.forEach(event => {
            // Add video camera icon
            const icon = document.createElement('i');
            icon.className = 'fa fa-video-camera telehealth-icon';
            event.insertBefore(icon, event.firstChild);

            // Add status styling
            if (event.classList.contains('event_telehealth_completed')) {
                event.style.opacity = '0.7';
            } else if (event.classList.contains('event_telehealth_active')) {
                event.style.borderLeft = '3px solid #28a745';
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