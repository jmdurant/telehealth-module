/**
 * Telehealth Waiting Room Notification System
 * 
 * Establishes a WebSocket connection to the telesalud backend to receive
 * real-time notifications when patients join the waiting room.
 */
(function() {
    // Only run in provider context, not patient portal
    if (typeof window.opener !== 'undefined' && window.opener !== null) {
        return; // Don't run in popup windows
    }

    // Configuration
    const config = {
        // Will be populated from the page
        apiUrl: window.TELEHEALTH_API_URL || '',
        apiToken: window.TELEHEALTH_API_TOKEN || '',
        // Cache of backend_id -> encounter mappings
        appointments: {},
        // WebSocket connection
        socket: null,
        // Toast container
        toastContainer: null
    };

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', init);

    /**
     * Initialize the waiting room notification system
     */
    function init() {
        // Create toast container if it doesn't exist
        createToastContainer();
        
        // Fetch provider's upcoming appointments with backend IDs
        fetchAppointments()
            .then(() => {
                // Connect to WebSocket for real-time updates
                connectWebSocket();
            })
            .catch(error => {
                console.error('Failed to initialize telehealth waiting room:', error);
            });
    }

    /**
     * Create a container for toast notifications
     */
    function createToastContainer() {
        config.toastContainer = document.createElement('div');
        config.toastContainer.className = 'telehealth-toast-container';
        config.toastContainer.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 9999;';
        document.body.appendChild(config.toastContainer);
        
        // Add toast styles
        const style = document.createElement('style');
        style.textContent = `
            .telehealth-toast {
                min-width: 300px;
                margin-top: 10px;
                background-color: #fff;
                color: #333;
                padding: 15px;
                border-radius: 4px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                display: flex;
                flex-direction: column;
                border-left: 5px solid #007bff;
                animation: telehealth-toast-in 0.5s ease;
                cursor: pointer;
            }
            .telehealth-toast-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .telehealth-toast-body {
                margin-bottom: 10px;
            }
            .telehealth-toast-close {
                cursor: pointer;
                font-weight: bold;
                opacity: 0.7;
            }
            .telehealth-toast-close:hover {
                opacity: 1;
            }
            @keyframes telehealth-toast-in {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Fetch the provider's upcoming appointments with backend IDs
     */
    async function fetchAppointments() {
        try {
            const response = await fetch('/modules/telehealth/api/upcoming.php');
            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }
            
            const data = await response.json();
            if (data && typeof data === 'object') {
                config.appointments = data;
                console.log('Loaded telehealth appointments:', Object.keys(config.appointments).length);
            }
        } catch (error) {
            console.error('Error fetching telehealth appointments:', error);
        }
    }

    /**
     * Connect to the WebSocket server
     */
    function connectWebSocket() {
        if (!config.apiUrl || !config.apiToken) {
            console.warn('Telehealth WebSocket: Missing API URL or token');
            return;
        }

        // Extract base URL from API URL
        const apiUrlObj = new URL(config.apiUrl);
        const wsProtocol = apiUrlObj.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsUrl = `${wsProtocol}//${apiUrlObj.host}/realtime?token=${config.apiToken}`;
        
        try {
            config.socket = new WebSocket(wsUrl);
            
            config.socket.onopen = () => {
                console.log('Telehealth WebSocket: Connected');
            };
            
            config.socket.onmessage = (event) => {
                handleWebSocketMessage(event);
            };
            
            config.socket.onerror = (error) => {
                console.error('Telehealth WebSocket error:', error);
            };
            
            config.socket.onclose = () => {
                console.log('Telehealth WebSocket: Connection closed');
                // Attempt to reconnect after 5 seconds
                setTimeout(connectWebSocket, 5000);
            };
        } catch (error) {
            console.error('Failed to connect to telehealth WebSocket:', error);
        }
    }

    /**
     * Handle incoming WebSocket messages
     */
    function handleWebSocketMessage(event) {
        try {
            const data = JSON.parse(event.data);
            
            // Handle patient_joined event
            if (data.event === 'patient_joined' && data.vc_id) {
                const backendId = data.vc_id.toString();
                const encounterId = config.appointments[backendId];
                
                if (encounterId) {
                    showPatientWaitingNotification(data.patient_name || 'Patient', encounterId);
                }
            }
        } catch (error) {
            console.error('Error processing telehealth WebSocket message:', error);
        }
    }

    /**
     * Show a notification that a patient is waiting
     */
    function showPatientWaitingNotification(patientName, encounterId) {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = 'telehealth-toast';
        toast.innerHTML = `
            <div class="telehealth-toast-header">
                <span>Telehealth Patient Waiting</span>
                <span class="telehealth-toast-close">&times;</span>
            </div>
            <div class="telehealth-toast-body">
                ${patientName} has joined the waiting room.
                <br>
                <small>Click to join the meeting</small>
            </div>
        `;
        
        // Add click handler to open the telehealth meeting
        toast.addEventListener('click', (e) => {
            if (!e.target.classList.contains('telehealth-toast-close')) {
                window.open(`/modules/telehealth/controllers/start.php?role=provider&eid=${encounterId}`, '_blank');
                config.toastContainer.removeChild(toast);
            }
        });
        
        // Add close button handler
        const closeBtn = toast.querySelector('.telehealth-toast-close');
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            config.toastContainer.removeChild(toast);
        });
        
        // Add to container
        config.toastContainer.appendChild(toast);
        
        // Play notification sound
        playNotificationSound();
        
        // Auto-remove after 30 seconds
        setTimeout(() => {
            if (toast.parentNode === config.toastContainer) {
                config.toastContainer.removeChild(toast);
            }
        }, 30000);
    }

    /**
     * Play a notification sound
     */
    function playNotificationSound() {
        try {
            const audio = new Audio('/modules/telehealth/public/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(e => console.log('Could not play notification sound:', e));
        } catch (e) {
            console.log('Audio notification not supported');
        }
    }
})();
