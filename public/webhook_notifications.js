/**
 * Webhook-Based Real-time Notification System - PRODUCTION VERSION 1.6 FIXED
 * Last Updated: January 2025 - Dismiss Button Fixed (Direct Call Method)
 * 
 * Polls the real-time notifications API to get notifications triggered by
 * webhook events from the telesalud backend, then displays them as toast notifications.
 */
(function() {
    // Only run in provider context, not patient portal
    if (typeof window.opener !== 'undefined' && window.opener !== null) {
        return; // Don't run in popup windows
    }

    console.log('Telehealth: Loading Production Notification System v1.6 FIXED'); // PRODUCTION VERSION MARKER

    // IFRAME FIX: Always use the top-level window for DOM operations
    const targetWindow = window.top || window.parent || window;
    const targetDocument = targetWindow.document;
    
    console.log('Telehealth: Target window URL:', targetWindow.location.href);
    console.log('Telehealth: Current window URL:', window.location.href);
    console.log('Telehealth: Using top-level window:', targetWindow === window.top);

    // Configuration
    const config = {
        // Poll interval in milliseconds (3 seconds for webhook-based notifications)
        pollInterval: 3000,
        // Current polling timer
        pollTimer: null,
        // Toast container (will be in top-level window)
        toastContainer: null,
        // Notification sound
        notificationSound: null,
        // API endpoint
        apiEndpoint: '/interface/modules/custom_modules/oe-module-telehealth/api/realtime_notifications.php',
        // Target window references
        targetWindow: targetWindow,
        targetDocument: targetDocument
    };

    // Initialize the notification system
    function init() {
        console.log('Telehealth: Initializing notification system');
        
        // Initialize telehealthNotifications object on target window
        config.targetWindow.telehealthNotifications = {
            actions: {},
            dismissToast: function(toastId) {
                const toast = config.targetDocument.getElementById(toastId);
                if (toast) {
                    dismissToast(toast);
                }
            },
            executeAction: function(actionId) {
                const actionData = config.targetWindow.telehealthNotifications.actions[actionId];
                
                if (actionData) {
                    // Extract toast ID from actionId: action-toast-timestamp-random-index -> toast-timestamp-random
                    const toastId = actionId.replace('action-', '').split('-').slice(0, -1).join('-');
                    const toast = config.targetDocument.getElementById(toastId);
                    
                    if (toast) {
                        actionData.action(actionData.notification, toast);
                    }
                }
            }
        };
        
        // Create toast container in target window
        createToastContainer();
        
        // Start polling for notifications
        startNotificationPolling();
        
        // Try to load custom notification sound
        loadNotificationSound();
        
        // Request notification permission if available
        if ('Notification' in config.targetWindow && Notification.permission === 'default') {
            Notification.requestPermission().then(permission => {
                console.log('Telehealth: Notification permission:', permission);
            });
        }
        
        console.log('Telehealth: Notification system ready');
    }

    // Wait for document ready, then initialize
    if (config.targetDocument.readyState === 'loading') {
        config.targetDocument.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * Create the toast container if it doesn't exist (in top-level window)
     */
    function createToastContainer() {
        // Check if container already exists in target window
        if (config.targetDocument.getElementById('telehealth-toast-container')) {
            config.toastContainer = config.targetDocument.getElementById('telehealth-toast-container');
            return;
        }

        // Create container in target document
        const container = config.targetDocument.createElement('div');
        container.id = 'telehealth-toast-container';
        container.className = 'telehealth-toast-container';
        
        // Apply styles with !important to ensure visibility
        container.style.cssText = `
            position: fixed !important;
            top: 20px !important;
            right: 20px !important;
            z-index: 999999 !important;
            pointer-events: none !important;
            max-width: 400px !important;
            width: auto !important;
            display: block !important;
            visibility: visible !important;
        `;

        // Append to target document body
        config.targetDocument.body.appendChild(container);
        config.toastContainer = container;

        console.log('Telehealth: Toast notification system ready');
        
        // Add CSS to target document if not already added
        addToastCSS();
    }

    /**
     * Load notification sound
     */
    function loadNotificationSound() {
        try {
            // Load the custom notification sound
            config.notificationSound = new Audio('/interface/modules/custom_modules/oe-module-telehealth/public/notification.mp3');
            config.notificationSound.volume = 0.6;
            
            // Test if the file loads properly
            config.notificationSound.addEventListener('canplaythrough', () => {
                console.log('Telehealth: Custom notification sound loaded successfully');
            });
            
            config.notificationSound.addEventListener('error', (e) => {
                console.log('Telehealth: Custom notification sound failed to load, using fallback');
                config.notificationSound = null;
            });
            
        } catch (e) {
            console.log('Telehealth: Audio not supported, using browser notifications');
            config.notificationSound = null;
        }
    }

    /**
     * Start polling for notifications
     */
    function startNotificationPolling() {
        // Initial poll
        pollNotifications();
        
        // Set up recurring polling
        config.pollTimer = setInterval(pollNotifications, config.pollInterval);
        
        // Stop polling when page is hidden/closed
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(config.pollTimer);
            } else {
                startNotificationPolling();
            }
        });
    }

    /**
     * Poll for new notifications from the API
     */
    async function pollNotifications() {
        try {
            const response = await fetch(config.apiEndpoint, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.notifications && data.notifications.length > 0) {
                console.log('Telehealth: Processing', data.notifications.length, 'new notifications');
                
                // Process each notification
                data.notifications.forEach((notification) => {
                    showNotificationToast(notification);
                });
                
                // Mark all notifications as read
                markNotificationsAsRead(data.notifications.map(n => n.id));
            }
            
        } catch (error) {
            console.warn('Telehealth: Error polling notifications:', error);
        }
    }

    /**
     * Mark notifications as read
     */
    async function markNotificationsAsRead(notificationIds) {
        try {
            await fetch(config.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    notification_ids: notificationIds
                })
            });
        } catch (error) {
            console.warn('Telehealth: Error marking notifications as read:', error);
        }
    }

    /**
     * Show a notification toast
     */
    function showNotificationToast(notification) {
        console.log('Telehealth: New notification:', notification.topic, '-', notification.title);
        
        // Get notification configuration using the existing function
        const notificationConfig = getToastConfigForTopic(notification);
        if (!notificationConfig) {
            console.warn('Telehealth: No configuration found for notification topic:', notification.topic);
            return;
        }
        
        // Get actions from config
        const actions = notificationConfig.actions ? notificationConfig.actions(notification) : [];
        
        // Create toast options
        const toastOptions = {
            type: notification.topic,
            title: notification.title,
            message: notification.message,
            patientName: notification.patient_name || 'Unknown Patient',
            actions: actions,
            autoHide: notificationConfig.autoHide,
            playSound: notificationConfig.playSound,
            notification: notification  // Pass the full notification for action handlers
        };
        
        // Play sound if configured
        if (toastOptions.playSound) {
            playNotificationSound();
        }
        
        // Ensure container exists in target window
        if (!config.toastContainer || !config.targetDocument.body.contains(config.toastContainer)) {
            createToastContainer();
        }
        
        // Create toast element
        const toast = createToast(toastOptions);
        
        // Add toast to container (in target document)
        config.toastContainer.appendChild(toast);
        
        // Auto-hide if specified
        if (toastOptions.autoHide) {
            setTimeout(() => dismissToast(toast), toastOptions.autoHide);
        }
        
        // Fallback: Browser notification if supported
        if ('Notification' in config.targetWindow && Notification.permission === 'granted') {
            new Notification(notification.title, {
                body: `${notification.patient_name}: ${notification.message}`,
                silent: false
            });
        }
    }

    /**
     * Get toast configuration based on notification topic
     */
    function getToastConfigForTopic(notification) {
        const configs = {
            'patient-waiting': {
                playSound: true,
                autoHide: 45000, // 45 seconds for critical notifications
                actions: (notif) => [
                    {
                        text: 'Join Video Call',
                        type: 'primary',
                        action: () => window.open(notif.meeting_url, '_blank', 'width=1200,height=800')
                    },
                    {
                        text: 'Dismiss',
                        type: 'secondary',
                        action: (notification, toast) => {
                            if (toast) {
                                dismissToast(toast);
                            }
                        }
                    }
                ]
            },
            'patient-set-attendance': {
                playSound: true,
                autoHide: 30000,
                actions: (notif) => [
                    {
                        text: 'Start Consultation',
                        type: 'primary',
                        action: () => window.open(notif.meeting_url, '_blank', 'width=1200,height=800')
                    },
                    {
                        text: 'Dismiss',
                        type: 'secondary',
                        action: (notification, toast) => {
                            if (toast) {
                                dismissToast(toast);
                            }
                        }
                    }
                ]
            },
            'medic-set-attendance': {
                playSound: false,
                autoHide: 15000
            },
            'videoconsultation-started': {
                playSound: false,
                autoHide: 15000
            },
            'provider-joined': {
                playSound: false,
                autoHide: 15000
            },
            'consultation-started': {
                playSound: false,
                autoHide: 15000
            },
            'provider-left': {
                playSound: true,
                autoHide: 20000
            },
            'videoconsultation-finished': {
                playSound: false,
                autoHide: 30000,
                actions: (notif) => [
                    {
                        text: 'View Patient Chart',
                        type: 'primary',
                        action: () => window.open(`/interface/patient_file/summary/demographics.php?pid=${notif.pid}`, '_blank')
                    },
                    {
                        text: 'Create Note',
                        type: 'secondary', 
                        action: () => window.open(`/interface/patient_file/encounter/encounter_top.php?pid=${notif.pid}`, '_blank')
                    }
                ]
            },
            'consultation-finished': {
                playSound: false,
                autoHide: 30000,
                actions: (notif) => [
                    {
                        text: 'View Patient Chart',
                        type: 'primary',
                        action: () => window.open(`/interface/patient_file/summary/demographics.php?pid=${notif.pid}`, '_blank')
                    },
                    {
                        text: 'Create Note',
                        type: 'secondary',
                        action: () => window.open(`/interface/patient_file/encounter/encounter_top.php?pid=${notif.pid}`, '_blank')
                    }
                ]
            }
        };
        
        return configs[notification.topic] || {
            playSound: false,
            autoHide: 20000
        };
    }

    /**
     * Add CSS styles to target document
     */
    function addToastCSS() {
        // Add toast styles if not already present in target document
        if (!config.targetDocument.getElementById('telehealth-toast-styles')) {
            const style = config.targetDocument.createElement('style');
            style.id = 'telehealth-toast-styles';
            style.textContent = `
                .telehealth-toast-container {
                    position: fixed !important;
                    top: 20px !important;
                    right: 20px !important;
                    z-index: 999999 !important;
                    pointer-events: none !important;
                    max-width: 400px !important;
                    width: auto !important;
                    display: block !important;
                    visibility: visible !important;
                }
                .telehealth-toast {
                    min-width: 380px !important;
                    margin-bottom: 10px !important;
                    background-color: #fff !important;
                    color: #333 !important;
                    padding: 16px !important;
                    border-radius: 8px !important;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
                    display: block !important;
                    flex-direction: column !important;
                    border-left: 4px solid #007bff !important;
                    cursor: pointer !important;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                    pointer-events: auto !important;
                    position: relative !important;
                }
                .telehealth-toast:hover {
                    box-shadow: 0 6px 16px rgba(0,0,0,0.2) !important;
                    transform: translateY(-2px) !important;
                    transition: all 0.2s ease !important;
                }
                .telehealth-toast-header {
                    display: flex !important;
                    justify-content: space-between !important;
                    align-items: center !important;
                    margin-bottom: 8px !important;
                    font-weight: 600 !important;
                    font-size: 14px !important;
                    color: #007bff !important;
                }
                .telehealth-toast-body {
                    margin-bottom: 12px !important;
                    font-size: 13px !important;
                    line-height: 1.4 !important;
                }
                .telehealth-toast-patient {
                    font-weight: 600 !important;
                    color: #495057 !important;
                    margin-bottom: 4px !important;
                }
                .telehealth-toast-actions {
                    display: flex !important;
                    gap: 8px !important;
                    margin-top: 8px !important;
                }
                .telehealth-toast-btn {
                    padding: 8px 16px !important;
                    border: none !important;
                    border-radius: 4px !important;
                    font-size: 12px !important;
                    cursor: pointer !important;
                    font-weight: 500 !important;
                    text-decoration: none !important;
                    display: inline-block !important;
                    text-align: center !important;
                }
                .telehealth-toast-btn-primary {
                    background-color: #007bff !important;
                    color: white !important;
                }
                .telehealth-toast-btn-primary:hover {
                    background-color: #0056b3 !important;
                    color: white !important;
                }
                .telehealth-toast-btn-secondary {
                    background-color: #6c757d !important;
                    color: white !important;
                }
                .telehealth-toast-btn-secondary:hover {
                    background-color: #545b62 !important;
                }
                .telehealth-toast-close {
                    cursor: pointer !important;
                    font-weight: bold !important;
                    opacity: 0.7 !important;
                    font-size: 16px !important;
                    color: #999 !important;
                }
                .telehealth-toast-close:hover {
                    opacity: 1 !important;
                    color: #333 !important;
                }
                .telehealth-toast.patient-waiting {
                    border-left-color: #28a745 !important;
                }
                .telehealth-toast.patient-set-attendance {
                    border-left-color: #28a745 !important;
                }
                .telehealth-toast.provider-joined {
                    border-left-color: #17a2b8 !important;
                }
                .telehealth-toast.consultation-started {
                    border-left-color: #ffc107 !important;
                }
                .telehealth-toast.videoconsultation-started {
                    border-left-color: #ffc107 !important;
                }
                .telehealth-toast.provider-left {
                    border-left-color: #dc3545 !important;
                }
                .telehealth-toast.consultation-finished {
                    border-left-color: #6c757d !important;
                }
                .telehealth-toast.videoconsultation-finished {
                    border-left-color: #6c757d !important;
                }
                @keyframes telehealth-toast-in {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes telehealth-toast-out {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            config.targetDocument.head.appendChild(style);
        }
    }

    /**
     * Create a toast notification element
     */
    function createToast(options) {
        // Create toast element in target document
        const toast = config.targetDocument.createElement('div');
        const toastId = 'toast-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        
        toast.className = 'telehealth-toast ' + options.type;
        toast.id = toastId;
        
        // Build action buttons HTML if provided
        let actionsHtml = '';
        if (options.actions && options.actions.length > 0) {
            actionsHtml = '<div class="telehealth-toast-actions">';
            options.actions.forEach((action, index) => {
                const actionId = `action-${toastId}-${index}`;
                const buttonClass = action.type === 'primary' ? 'telehealth-toast-btn-primary' : 'telehealth-toast-btn-secondary';
                
                // Handle dismiss buttons differently - use direct dismissToast call like the X button
                if (action.text === 'Dismiss') {
                    actionsHtml += `<button class="telehealth-toast-btn ${buttonClass}" onclick="window.top.telehealthNotifications && window.top.telehealthNotifications.dismissToast('${toastId}')">${action.text}</button>`;
                } else {
                    // For other actions, use the executeAction method
                    actionsHtml += `<button class="telehealth-toast-btn ${buttonClass}" onclick="window.top.telehealthNotifications && window.top.telehealthNotifications.executeAction('${actionId}')">${action.text}</button>`;
                    
                    // Store action and notification data for later execution
                    config.targetWindow.telehealthNotifications.actions[actionId] = {
                        action: action.action,
                        notification: options.notification || {}
                    };
                }
            });
            actionsHtml += '</div>';
        }
        
        // Set inner HTML
        toast.innerHTML = `
            <div class="telehealth-toast-header">
                <span>${options.title}</span>
                <span class="telehealth-toast-close" onclick="window.top.telehealthNotifications && window.top.telehealthNotifications.dismissToast('${toastId}')">&times;</span>
            </div>
            <div class="telehealth-toast-body">
                <div class="telehealth-toast-patient">${options.patientName}</div>
                <div>${options.message}</div>
            </div>
            ${actionsHtml}
        `;
        
        // Apply animation and visibility styles
        toast.style.cssText = `
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            animation: 0.3s ease 0s 1 normal forwards running telehealth-toast-in !important;
        `;
        
        return toast;
    }

    /**
     * Dismiss a toast notification
     */
    function dismissToast(toast) {
        if (!toast || !toast.parentNode) {
            return;
        }
        
        // Add exit animation
        toast.style.animation = '0.3s ease 0s 1 normal forwards running telehealth-toast-out !important';
        
        // Remove after animation completes
        setTimeout(() => {
            if (toast && toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    /**
     * Play notification sound
     */
    function playNotificationSound() {
        try {
            if (config.notificationSound && config.notificationSound.readyState >= 2) {
                // Audio file is loaded and ready
                config.notificationSound.currentTime = 0;
                config.notificationSound.play().catch(e => {
                    console.log('Telehealth: Could not play custom notification sound:', e.message);
                    playFallbackNotification();
                });
            } else {
                // Audio not ready or not loaded, use fallback
                playFallbackNotification();
            }
        } catch (e) {
            console.log('Telehealth: Error playing notification sound:', e);
            playFallbackNotification();
        }
    }
    
    /**
     * Play fallback notification using browser notification API
     */
    function playFallbackNotification() {
        try {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('Telehealth Notification', {
                    body: 'New telehealth activity detected',
                    silent: false,
                    requireInteraction: false
                });
            } else if ('Notification' in window && Notification.permission === 'default') {
                // Request permission for future notifications
                Notification.requestPermission().then(permission => {
                    if (permission === 'granted') {
                        new Notification('Telehealth Notification', {
                            body: 'New telehealth activity detected',
                            silent: false,
                            requireInteraction: false
                        });
                    }
                });
            }
        } catch (e) {
            console.log('Telehealth: Browser notifications not available');
        }
    }

    /**
     * Global debugging function - accessible via window.debugToasts()
     */
    window.debugToasts = function() {
        console.log('=== TELEHEALTH TOAST DEBUG REPORT ===');
        console.log('Container exists in config:', !!config.toastContainer);
        console.log('Container element:', config.toastContainer);
        console.log('Container in DOM:', config.toastContainer ? document.body.contains(config.toastContainer) : false);
        console.log('Container findable by ID:', !!document.getElementById('telehealth-toast-container'));
        console.log('Container findable by class:', !!document.querySelector('.telehealth-toast-container'));
        console.log('All elements with toast class:', document.querySelectorAll('.telehealth-toast'));
        console.log('Body children count:', document.body.children.length);
        console.log('Body last 3 children:', Array.from(document.body.children).slice(-3));
        
        if (config.toastContainer) {
            console.log('Container computed styles:', {
                display: window.getComputedStyle(config.toastContainer).display,
                visibility: window.getComputedStyle(config.toastContainer).visibility,
                position: window.getComputedStyle(config.toastContainer).position,
                bottom: window.getComputedStyle(config.toastContainer).bottom,
                right: window.getComputedStyle(config.toastContainer).right,
                zIndex: window.getComputedStyle(config.toastContainer).zIndex
            });
            console.log('Container children:', Array.from(config.toastContainer.children));
        }
        console.log('=== END DEBUG REPORT ===');
        
        // Try to make container visible
        if (config.toastContainer) {
            config.toastContainer.style.border = '10px solid lime';
            config.toastContainer.style.backgroundColor = 'rgba(255, 0, 0, 0.5)';
            config.toastContainer.innerHTML += '<div style="color: black; font-weight: bold; padding: 10px;">DEBUG: CONTAINER FOUND</div>';
        }
    };
})(); 