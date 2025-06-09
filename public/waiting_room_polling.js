/**
 * Telehealth Waiting Room Notification System (Polling Version)
 * 
 * Polls the OpenEMR backend to detect appointment status changes
 * and shows real-time notifications when patients join waiting rooms.
 */
(function() {
    // Only run in provider context, not patient portal
    if (typeof window.opener !== 'undefined' && window.opener !== null) {
        return; // Don't run in popup windows
    }

    // Configuration
    const config = {
        // Poll interval in milliseconds (5 seconds)
        pollInterval: 5000,
        // Cache of appointment statuses
        appointmentStatuses: {},
        // Current polling timer
        pollTimer: null,
        // Toast container
        toastContainer: null,
        // Notification sound
        notificationSound: null
    };

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', init);

    /**
     * Initialize the waiting room notification system
     */
    function init() {
        console.log('Telehealth: Initializing waiting room notifications');
        
        // Create toast container
        createToastContainer();
        
        // Load notification sound
        loadNotificationSound();
        
        // Start polling for status changes
        startPolling();
        
        console.log('Telehealth: Waiting room notifications initialized');
    }

    /**
     * Create a container for toast notifications
     */
    function createToastContainer() {
        config.toastContainer = document.createElement('div');
        config.toastContainer.className = 'telehealth-toast-container';
        config.toastContainer.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 9999;';
        document.body.appendChild(config.toastContainer);
        
        // Add toast styles if not already present
        if (!document.getElementById('telehealth-toast-styles')) {
            const style = document.createElement('style');
            style.id = 'telehealth-toast-styles';
            style.textContent = `
                .telehealth-toast {
                    min-width: 350px;
                    margin-top: 10px;
                    background-color: #fff;
                    color: #333;
                    padding: 15px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    display: flex;
                    flex-direction: column;
                    border-left: 4px solid #007bff;
                    animation: telehealth-toast-in 0.3s ease;
                    cursor: pointer;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }
                .telehealth-toast:hover {
                    box-shadow: 0 6px 16px rgba(0,0,0,0.2);
                    transform: translateY(-2px);
                    transition: all 0.2s ease;
                }
                .telehealth-toast-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 8px;
                    font-weight: 600;
                    font-size: 14px;
                    color: #007bff;
                }
                .telehealth-toast-body {
                    margin-bottom: 12px;
                    font-size: 13px;
                    line-height: 1.4;
                }
                .telehealth-toast-actions {
                    display: flex;
                    gap: 8px;
                    margin-top: 8px;
                }
                .telehealth-toast-btn {
                    padding: 6px 12px;
                    border: none;
                    border-radius: 4px;
                    font-size: 12px;
                    cursor: pointer;
                    font-weight: 500;
                }
                .telehealth-toast-btn-primary {
                    background-color: #007bff;
                    color: white;
                }
                .telehealth-toast-btn-secondary {
                    background-color: #6c757d;
                    color: white;
                }
                .telehealth-toast-close {
                    cursor: pointer;
                    font-weight: bold;
                    opacity: 0.7;
                    font-size: 16px;
                    color: #999;
                }
                .telehealth-toast-close:hover {
                    opacity: 1;
                    color: #333;
                }
                .telehealth-toast.patient-joined {
                    border-left-color: #28a745;
                }
                .telehealth-toast.consultation-started {
                    border-left-color: #ffc107;
                }
                .telehealth-toast.consultation-finished {
                    border-left-color: #6c757d;
                }
                @keyframes telehealth-toast-in {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        }
    }

    /**
     * Load notification sound
     */
    function loadNotificationSound() {
        try {
            // Try to load a custom notification sound
            config.notificationSound = new Audio('/interface/modules/custom_modules/telehealth-module/public/notification.mp3');
            config.notificationSound.volume = 0.6;
        } catch (e) {
            console.log('Telehealth: Custom notification sound not available, will use system sound');
        }
    }

    /**
     * Start polling for appointment status changes
     */
    function startPolling() {
        // Initial poll
        pollAppointmentStatuses();
        
        // Set up recurring polling
        config.pollTimer = setInterval(pollAppointmentStatuses, config.pollInterval);
        
        // Stop polling when page is hidden/closed
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(config.pollTimer);
            } else {
                startPolling();
            }
        });
    }

    /**
     * Poll for appointment status changes
     */
    async function pollAppointmentStatuses() {
        try {
            const response = await fetch('/interface/modules/custom_modules/telehealth-module/api/upcoming.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const appointments = await response.json();
            
            // Check for status changes
            checkForStatusChanges(appointments);
            
        } catch (error) {
            console.warn('Telehealth: Error polling appointment statuses:', error);
        }
    }

    /**
     * Check for appointment status changes and show notifications
     */
    function checkForStatusChanges(currentAppointments) {
        Object.keys(currentAppointments).forEach(backendId => {
            const current = currentAppointments[backendId];
            const previous = config.appointmentStatuses[backendId];
            
            // New appointment or status change
            if (!previous || previous.status !== current.status) {
                handleStatusChange(backendId, current, previous);
            }
        });
        
        // Update cached statuses
        config.appointmentStatuses = currentAppointments;
    }

    /**
     * Handle appointment status changes
     */
    function handleStatusChange(backendId, current, previous) {
        const encounterID = current.encounter_id;
        const patientName = current.patient_name;
        const appointmentTime = current.appointment_time;
        
        // Show notification based on status change
        switch (current.status) {
            case '@': // Patient arrived
                if (!previous || previous.status !== '@') {
                    showPatientJoinedNotification(patientName, encounterID, appointmentTime);
                }
                break;
                
            case '>': // Consultation started
                if (!previous || previous.status !== '>') {
                    showConsultationStartedNotification(patientName, encounterID);
                }
                break;
                
            case '$': // Consultation finished
                if (!previous || previous.status !== '$') {
                    showConsultationFinishedNotification(patientName, encounterID);
                }
                break;
        }
    }

    /**
     * Show notification that a patient has joined the waiting room
     */
    function showPatientJoinedNotification(patientName, encounterID, appointmentTime) {
        const toast = createToast({
            type: 'patient-joined',
            title: '🟢 Patient Waiting',
            message: `${patientName} has joined the waiting room.`,
            details: `Appointment time: ${appointmentTime}`,
            actions: [
                {
                    text: 'Join Meeting',
                    type: 'primary',
                    action: () => joinMeeting(encounterID, 'provider')
                },
                {
                    text: 'Dismiss',
                    type: 'secondary',
                    action: () => dismissToast(toast)
                }
            ],
            autoHide: 45000 // 45 seconds for patient notifications
        });
        
        playNotificationSound();
    }

    /**
     * Show notification that a consultation has started
     */
    function showConsultationStartedNotification(patientName, encounterID) {
        const toast = createToast({
            type: 'consultation-started',
            title: '🟡 Consultation Started',
            message: `Consultation with ${patientName} has begun.`,
            details: 'Both provider and patient are now in the meeting.',
            autoHide: 15000 // 15 seconds
        });
    }

    /**
     * Show notification that a consultation has finished
     */
    function showConsultationFinishedNotification(patientName, encounterID) {
        const toast = createToast({
            type: 'consultation-finished',
            title: '⚫ Consultation Completed',
            message: `Consultation with ${patientName} has been completed.`,
            details: 'Clinical notes may be available for review.',
            autoHide: 10000 // 10 seconds
        });
    }

    /**
     * Create a toast notification
     */
    function createToast(options) {
        const toast = document.createElement('div');
        toast.className = `telehealth-toast ${options.type || ''}`;
        
        let actionsHtml = '';
        if (options.actions && options.actions.length > 0) {
            actionsHtml = '<div class="telehealth-toast-actions">';
            options.actions.forEach(action => {
                actionsHtml += `<button class="telehealth-toast-btn telehealth-toast-btn-${action.type}" data-action="${action.text}">${action.text}</button>`;
            });
            actionsHtml += '</div>';
        }
        
        toast.innerHTML = `
            <div class="telehealth-toast-header">
                <span>${options.title}</span>
                <span class="telehealth-toast-close">&times;</span>
            </div>
            <div class="telehealth-toast-body">
                <div>${options.message}</div>
                ${options.details ? `<small style="color: #666;">${options.details}</small>` : ''}
            </div>
            ${actionsHtml}
        `;
        
        // Add action handlers
        if (options.actions) {
            options.actions.forEach(action => {
                const btn = toast.querySelector(`[data-action="${action.text}"]`);
                if (btn) {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        action.action();
                    });
                }
            });
        }
        
        // Add close button handler
        const closeBtn = toast.querySelector('.telehealth-toast-close');
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dismissToast(toast);
        });
        
        // Add to container
        config.toastContainer.appendChild(toast);
        
        // Auto-hide if specified
        if (options.autoHide) {
            setTimeout(() => dismissToast(toast), options.autoHide);
        }
        
        return toast;
    }

    /**
     * Dismiss a toast notification
     */
    function dismissToast(toast) {
        if (toast.parentNode === config.toastContainer) {
            toast.style.animation = 'telehealth-toast-in 0.3s ease reverse';
            setTimeout(() => {
                if (toast.parentNode === config.toastContainer) {
                    config.toastContainer.removeChild(toast);
                }
            }, 300);
        }
    }

    /**
     * Join a telehealth meeting
     */
    function joinMeeting(encounterID, role) {
        const url = `/interface/modules/custom_modules/telehealth-module/public/index.php?action=start&role=${role}&eid=${encounterID}`;
        window.open(url, '_blank', 'width=1200,height=800,resizable=yes,scrollbars=yes');
    }

    /**
     * Play notification sound
     */
    function playNotificationSound() {
        try {
            if (config.notificationSound) {
                config.notificationSound.currentTime = 0;
                config.notificationSound.play().catch(e => 
                    console.log('Could not play custom notification sound:', e)
                );
            } else {
                // Fallback: try system notification sound
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('Telehealth Patient Waiting', {
                        body: 'A patient has joined the waiting room',
                        icon: '/interface/modules/custom_modules/telehealth-module/public/icon.png',
                        silent: false
                    });
                }
            }
        } catch (e) {
            console.log('Telehealth: Could not play notification sound:', e);
        }
    }

    // Request notification permission on load
    if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
        Notification.requestPermission();
    }

})(); 