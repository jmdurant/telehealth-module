<?php
/**
 * Telehealth module settings page
 */
require_once __DIR__ . '/../../../../globals.php';
// Defensive: Set $GLOBALS['srcdir'] if not set
if (empty($GLOBALS['srcdir'])) {
    // Try to resolve the OpenEMR root by walking up directories until we find globals.php
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if (file_exists($dir . '/globals.php')) {
            $GLOBALS['srcdir'] = realpath($dir . '/library');
            break;
        }
        $dir = dirname($dir);
    }
    // Fallback: try the default OpenEMR Docker path
    if (empty($GLOBALS['srcdir']) && file_exists('/var/www/localhost/htdocs/openemr/library/acl.inc')) {
        $GLOBALS['srcdir'] = '/var/www/localhost/htdocs/openemr/library';
    }
}

// Check if this is an AJAX request
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
error_log("DEBUG: AJAX request detected: " . ($isAjax ? 'yes' : 'no'));

// Only admin users (session-based check)
if (empty($_SESSION['authUser']) || $_SESSION['authUser'] !== 'admin') {
    error_log("DEBUG: Auth check failed");
    die('Not authorized'); // basic guard
}

// Convenience to get/update globals
function th_get($key, $default = '') {
    return isset($GLOBALS[$key]) ? $GLOBALS[$key] : $default;
}

function th_set($key, $value) {
    sqlStatement('REPLACE INTO globals (gl_name, gl_value) VALUES (?,?)', [$key, $value]);
    $GLOBALS[$key] = $value;
}

/**
 * Tests the Telesalud connection.
 *
 * @param string $url The base API URL (e.g., https://vc-staging.localhost)
 * @param string $token The API token.
 * @param string $vcApiFromEnv The value from the VC_API environment variable.
 * @param string $portFromEnv The value from the TELEHEALTH_PORT environment variable.
 * @return array ['success' => bool, 'message' => string]
 */
function testTelesaludConnection(string $url, string $token, string $vcApiFromEnv, string $portFromEnv): array
{
    error_log("DEBUG (testTelesaludConnection with libcurl): Received URL: $url, Token (len): " . strlen($token) . ", VC_API: $vcApiFromEnv, Port: $portFromEnv");

    // Construct the final target URL for cURL
    // The $url should be the base (e.g., https://official-staging-telehealth-web-1)
    // The $vcApiFromEnv is the path (e.g., /api/videoconsultation?)
    // The $portFromEnv is the port (e.g., 443)

    $finalUrl = rtrim($url, '/'); // Ensure no trailing slash on base

    if (!empty($portFromEnv) && !parse_url($finalUrl, PHP_URL_PORT)) {
        $finalUrl .= ':' . $portFromEnv;
        error_log("DEBUG (libcurl): Appended port from env: $finalUrl");
    }

    // Append the API path. We need to be careful with the ' ? '
    // If $vcApiFromEnv already contains '?', use it as is.
    // If not, and we intend for this to be a POST that might look like a GET to trick cURL (older strategy), that's different.
    // For a direct POST, the query string on $vcApiFromEnv is usually for actual GET parameters, not for tricking cURL.
    // Let's assume $vcApiFromEnv is the literal path, and POST fields are separate.
    $endpointPath = $vcApiFromEnv; 
    // If the successful test_connection.php used /api/videoconsultation (no ?), we should match that for POST.
    // The VC_API env var is '/api/videoconsultation?'
    // test_connection.php does: $testUrl = $apiUrl . ($endpoint ? rtrim($endpoint, '?') : '/api/videoconsultation');
    // When $endpoint is null (default), it uses '/api/videoconsultation' (no trailing ?).
    // Let's make this libcurl version match that successful behavior more closely.
    $finalUrl .= rtrim($endpointPath, '?'); 

    error_log("DEBUG (libcurl): Final cURL Target URL: $finalUrl");
    error_log("DEBUG (libcurl): API Token (first 10): " . substr($token, 0, 10));

    $testData = json_encode([
        'appointment_date' => date('Y-m-d H:i:s'),
        'days_before_expiration' => (int)th_get('telesalud_days_before_expiration', 3),
        'medic_name' => 'Test Doctor',
        'patient_name' => 'Test Patient',
        'extra' => [
            'test' => 'connection_libcurl'
        ]
    ]);
    error_log("DEBUG (libcurl): Test data: $testData");

    // Check if we're using a .localhost domain and need to resolve it to the Docker IP
    $urlParts = parse_url($finalUrl);
    $hostname = $urlParts['host'] ?? '';
    $scheme = $urlParts['scheme'] ?? 'https';
    $port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : 80);
    
    // If this is a .localhost domain, try to get its IP from the hosts file
    $useResolve = false;
    $dockerIp = null;
    if (strpos($hostname, '.localhost') !== false) {
        // Read the hosts file to get the Docker IP for this hostname
        $hostsContent = file_get_contents('/etc/hosts');
        if ($hostsContent !== false) {
            $lines = explode("\n", $hostsContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') {
                    continue; // Skip comments and empty lines
                }
                
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 2 && in_array($hostname, array_slice($parts, 1))) {
                    $dockerIp = $parts[0];
                    $useResolve = true;
                    error_log("DEBUG (libcurl): Found Docker IP $dockerIp for $hostname in hosts file");
                    break;
                }
            }
        }
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $finalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $testData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: OpenEMR Telehealth Module Test (libcurl)'
    ]);
    
    // If we found a Docker IP for this hostname, use the RESOLVE option
    if ($useResolve && $dockerIp) {
        curl_setopt($ch, CURLOPT_RESOLVE, ["$hostname:$port:$dockerIp"]);
        error_log("DEBUG (libcurl): Using CURLOPT_RESOLVE to map $hostname:$port to $dockerIp");
    }
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Increased timeout slightly
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local/Docker development
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For local/Docker development
    
    curl_setopt($ch, CURLINFO_HEADER_OUT, true); // Enable tracking of request headers
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $sentHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    error_log("DEBUG (libcurl): Effective URL: " . $effectiveUrl);
    error_log("DEBUG (libcurl): Sent Headers:\n" . $sentHeaders);
    error_log("DEBUG (libcurl): HTTP Code: $httpCode");
    error_log("DEBUG (libcurl): cURL Error: " . $curlError);
    error_log("DEBUG (libcurl): Response: " . $response);
    error_log("DEBUG (libcurl): Verbose Log: " . $verboseLog);

    if ($curlError) {
        return ['success' => false, 'message' => 'cURL connection error (libcurl): ' . $curlError];
    }

    // Mimic logic from test_connection.php for success definition
    if ($httpCode === 400 || $httpCode === 422) {
        // Check if it's a validation error from the API (auth success!)
        $responseData = json_decode($response, true);
        if (isset($responseData['data']) && is_array($responseData['data'])) {
             return ['success' => true, 'message' => 'Connection successful! (API responded with expected validation errors - libcurl)'];
        } else if (isset($responseData['message']) && strpos(strtolower($responseData['message']), 'validation') !== false) {
             return ['success' => true, 'message' => 'Connection successful! (API validation error - libcurl)'];
        }
        return ['success' => true, 'message' => 'Connection successful! (Auth verified, HTTP ' . $httpCode . ' - libcurl)'];
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Connection successful! (HTTP ' . $httpCode . ' - libcurl)'];
    }
    if ($httpCode === 401) {
        return ['success' => false, 'message' => 'Authentication failed (HTTP 401 - libcurl). Check API token.'];
    }
    if ($httpCode === 404) {
        return ['success' => false, 'message' => 'API endpoint not found (HTTP 404 - libcurl). Check URL: ' . $effectiveUrl];
    }

    return ['success' => false, 'message' => 'API request failed (libcurl). HTTP Code: ' . $httpCode . '. Response: ' . substr($response, 0, 200)];
}

// Handle save or test connection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Test connection if requested
    if (isset($_POST['test_connection']) && $_POST['test_connection'] === '1') {
        error_log("DEBUG: Starting connection test");
        
        $envApiUrl = getenv('TELEHEALTH_BASE_URL');
        $apiToken = getenv('TELEHEALTH_API_TOKEN');
        $vcApi = getenv('VC_API');
        $port = getenv('TELEHEALTH_PORT');

        error_log("DEBUG: Initial Environment variables - URL: " . ($envApiUrl ?: 'not set') . ", Token: " . ($apiToken ? 'set' : 'not set') . ", VC_API: " . ($vcApi ?: 'not set') . ", Port: " . ($port ?: 'not set'));

        $apiUrlForTest = $envApiUrl; // Start with environment URL

        // If env URL is localhost, try to convert it to container name for inter-container communication
        // COMMENTED OUT: We're now using hosts file mapping to resolve domain names to Docker IPs
        /*
        if (!empty($apiUrlForTest) && (strpos($apiUrlForTest, 'localhost') !== false || strpos($apiUrlForTest, '127.0.0.1') !== false)) {
            error_log("DEBUG: Environment TELEHEALTH_BASE_URL ('$apiUrlForTest') is localhost. Attempting container detection.");
            $detectedContainer = detectTelesaludContainer(); // This function is defined below in the file
            
            if (!empty($detectedContainer)) {
                // Get the scheme (http/https) from the original URL
                $originalScheme = parse_url($apiUrlForTest, PHP_URL_SCHEME) ?: 'http';
                $originalPort = parse_url($apiUrlForTest, PHP_URL_PORT);
                
                // Replace localhost with container name but keep the scheme
                $apiUrlForTest = $originalScheme . '://' . $detectedContainer;
                
                // Add port back if it was in the original URL
                if (!empty($originalPort)) {
                    $apiUrlForTest .= ':' . $originalPort;
                }
                
                error_log("DEBUG: Overrode localhost URL to container URL: $apiUrlForTest (detected: $detectedContainer, original scheme: $originalScheme, original port: $originalPort)");
                // For now, let $port (from env) be passed, but testTelesaludConnection should prioritize port in $apiUrlForTest.
            } else {
                error_log("DEBUG: localhost URL detected but no container found. Using original localhost URL: $apiUrlForTest");
            }
        }
        */

        // If no URL from env (or after potential container conversion), use form value
        if (empty($apiUrlForTest)) {
            $apiUrlForTest = trim($_POST['telesalud_api_url'] ?? '');
            error_log("DEBUG: No env URL, using form URL: $apiUrlForTest");
        }
        
        // Ensure proper URL construction (e.g. rtrim, though parse_url/rebuild is safer)
        $apiUrlForTest = rtrim($apiUrlForTest, '/');
        
        // Default VC_API if not from env
        if (empty($vcApi)) {
            error_log("DEBUG: VC_API not set from env, using default /api/videoconsultation?");
            $vcApi = '/api/videoconsultation?';
        }
        
        // Token: env takes precedence, then form
        if (empty($apiToken)) {
            $apiToken = trim($_POST['telesalud_api_token'] ?? '');
        }
        
        error_log("DEBUG: Final values for test - URL: " . ($apiUrlForTest ?: 'not set') . ", Token: " . ($apiToken ? 'set' : 'not set') . ", VC_API: " . ($vcApi ?: 'not set') . ", Port (from env): " . ($port ?: 'not set'));
        
        if (empty($apiUrlForTest) || empty($apiToken)) {
            $testMessage = xl('Telesalud API URL and token are required for testing the connection');
            $testSuccess = false;
            
            // Send JSON response for AJAX requests if credentials missing
            if ($isAjax) {
                error_log("DEBUG: Sending AJAX JSON response for missing credentials - Success: false, Message: " . $testMessage);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => $testMessage
                ]);
                exit;
            }
        } else {
            // Call the centralized test connection function
            // Note: $port is still from getenv('TELEHEALTH_PORT'). 
            // testTelesaludConnection's shell_exec passes it, but test_connection.php itself also parses port from its TELEHEALTH_BASE_URL env var.
            error_log("DEBUG: Calling testTelesaludConnection function with URL: $apiUrlForTest, VC_API: $vcApi, Port (from env): $port");
            $testResult = testTelesaludConnection($apiUrlForTest, $apiToken, $vcApi, $port ?? ''); // Pass empty string if $port is null
            $testSuccess = $testResult['success'];
            $testMessage = $testResult['message'];
            error_log("DEBUG: testTelesaludConnection returned - Success: " . ($testSuccess ? 'true' : 'false') . ", Message (first 200 chars): " . substr($testMessage, 0, 200));

            // Send JSON response for AJAX requests after test completion
            if ($isAjax) {
                error_log("DEBUG: Sending AJAX JSON response after test - Success: " . ($testSuccess ? 'true' : 'false') . ", Message (first 200 chars): " . substr($testMessage, 0, 200));
                header('Content-Type: application/json');
                // For the message, let's use a cleaner version if it was a shell_exec success.
                // The $testMessage from shell_exec can be very verbose.
                $ajaxMessage = $testMessage;
                if ($testSuccess && strpos($testMessage, "via test_connection.php") !== false) {
                    // Try to get a cleaner summary
                    if (strpos($testMessage, "HTTP Status Code: 200") !== false) {
                         $ajaxMessage = "Connection successful (HTTP 200 via test_connection.php)!";
                    } elseif (strpos($testMessage, "HTTP Status Code: 400") !== false || strpos($testMessage, "HTTP Status Code: 422") !== false) {
                        $ajaxMessage = "Connection successful (API responded with expected validation error via test_connection.php).";
                    } else {
                         $ajaxMessage = "Connection test successful (via test_connection.php). See server logs for full details.";
                    }
                } else if (!$testSuccess && strpos($testMessage, "via test_connection.php") !== false) {
                    if (strpos($testMessage, "HTTP Status Code: 404") !== false) {
                        $ajaxMessage = "Connection failed: API endpoint not found (404 via test_connection.php). Check URL. ";
                    } else if (strpos($testMessage, "Connection Error:") !== false) {
                         $ajaxMessage = "Connection failed: cURL error during test_connection.php. Check network/URL/SSL. ";
                    }
                    else {
                         $ajaxMessage = "Connection test failed (via test_connection.php). See server logs for full details.";
                    }
                }


                echo json_encode([
                    'success' => $testSuccess,
                    'message' => $ajaxMessage // Use the potentially cleaner message
                ]);
                exit;
            }
        }
    } else {
        // Regular save
        $settings = [
            'telehealth_mode'                    => $_POST['telehealth_mode'] ?? 'standalone',
            'telehealth_provider'                => $_POST['telehealth_provider'] ?? 'jitsi',
            'jitsi_base_url'                     => trim($_POST['jitsi_base_url'] ?? ''),
            'telehealth_template_url'            => trim($_POST['telehealth_template_url'] ?? ''),
            'telesalud_api_url'                  => trim($_POST['telesalud_api_url'] ?? ''),
            'telesalud_api_token'                => trim($_POST['telesalud_api_token'] ?? ''),
            'telesalud_notification_token'       => trim($_POST['telesalud_notification_token'] ?? ''),
            'telesalud_days_before_expiration'   => (int)($_POST['telesalud_days_before_expiration'] ?? 3),
            'doxy_room_url'                      => trim($_POST['doxy_room_url'] ?? ''),
            'doximity_room_url'                  => trim($_POST['doximity_room_url'] ?? ''),
            'rem_day'                            => (int)($_POST['rem_day'] ?? 0),
            'rem_day_time'                       => trim($_POST['rem_day_time'] ?? '17:00'),
            'rem_hour'                           => (int)($_POST['rem_hour'] ?? 0),
            'rem_sms'                            => (int)($_POST['rem_sms'] ?? 0),
            'telehealth_log_file'                => trim($_POST['telehealth_log_file'] ?? ''),
        ];
        foreach ($settings as $k => $v) {
            th_set($k, $v);
        }
        // simple redirect to avoid re-submit
        header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
        exit;
    }
}

// Pull current values
$mode    = th_get('telehealth_mode', 'telesalud');
$prov    = th_get('telehealth_provider', 'jitsi');

// Auto-detect telesalud configuration
function autoDetectTelesaludConfig() {
    $config = [
        'api_url' => '',
        'api_token' => '',
        'notification_token' => '',
        'detection_method' => 'none'
    ];
    
    // First check environment variables
    $envApiUrl = getenv('TELEHEALTH_BASE_URL');
    $envApiToken = getenv('TELEHEALTH_API_TOKEN');
    $envNotificationToken = getenv('NOTIFICATION_TOKEN');
    
    // If not found in environment, try to read from mounted .env file
    if (empty($envApiUrl) || empty($envApiToken)) {
        $envFile = '/var/www/telehealth.env';
        if (file_exists($envFile)) {
            $envData = parse_ini_file($envFile);
            if ($envData) {
                $envApiUrl = $envApiUrl ?: ($envData['TELEHEALTH_BASE_URL'] ?? '');
                $envApiToken = $envApiToken ?: ($envData['TELEHEALTH_API_TOKEN'] ?? '');
                $envNotificationToken = $envNotificationToken ?: ($envData['NOTIFICATION_TOKEN'] ?? '');
            }
        }
    }
    
    // Set tokens if found
    if (!empty($envApiToken)) {
        $config['api_token'] = $envApiToken;
        $config['detection_method'] = 'environment';
    }
    
    if (!empty($envNotificationToken)) {
        $config['notification_token'] = $envNotificationToken;
    }
    
    // Auto-detect API URL
    if (!empty($envApiUrl)) {
        // Convert localhost URLs to container names if needed
        $apiUrl = $envApiUrl;
        if (strpos($apiUrl, 'localhost') !== false || strpos($apiUrl, '127.0.0.1') !== false) {
            $detectedContainer = detectTelesaludContainer();
            if ($detectedContainer) {
                // Replace localhost with container name and remove external ports
                $apiUrl = preg_replace('/https?:\/\/[^:]+:\d+/', "http://{$detectedContainer}", $apiUrl);
                $config['detection_method'] = 'container_discovery';
            }
        }
        $config['api_url'] = $apiUrl;
    } else {
        // Try to auto-detect telesalud container
        $detectedContainer = detectTelesaludContainer();
        if ($detectedContainer) {
            $config['api_url'] = "http://{$detectedContainer}/api";
            $config['detection_method'] = 'container_discovery';
        }
    }
    
    // Alternative: try to get config from telesalud container
    if (empty($envApiUrl) || empty($envApiToken)) {
        $containerConfig = getTelesaludContainerConfig();
        if ($containerConfig) {
            $envApiUrl = $envApiUrl ?: $containerConfig['api_url'];
            $envApiToken = $envApiToken ?: $containerConfig['api_token'];
        }
    }
    
    // Fallback: use known working container with OpenEMR environment token
    if (empty($config['api_url'])) {
        $detectedContainer = detectTelesaludContainer();
        if ($detectedContainer) {
            $config['api_url'] = "http://{$detectedContainer}/api";
            $config['detection_method'] = 'container_discovery';
        }
    }
    
    // Check if OpenEMR has its own telehealth token in environment
    $openemrToken = getenv('TELEHEALTH_API_TOKEN');
    if (!empty($openemrToken) && empty($config['api_token'])) {
        $config['api_token'] = $openemrToken;
        $config['detection_method'] = $config['detection_method'] ?: 'openemr_environment';
    }
    
    return $config;
}

function detectTelesaludContainer() {
    try {
        // Try to detect telesalud containers by common naming patterns
        $possibleContainers = [
            'official-staging-telehealth-web-1',
            'official-staging-telehealth-app-1', 
            'telehealth-web-1',
            'telehealth-app-1',
            'telesalud-web',
            'telesalud-app'
        ];
        
        foreach ($possibleContainers as $container) {
            // Try to ping the container to see if it exists and is reachable
            $output = [];
            $returnCode = 0;
            exec("ping -c 1 -W 1 {$container} 2>/dev/null", $output, $returnCode);
            
            if ($returnCode === 0) {
                // Container exists and is reachable, verify it's a web server
                exec("nc -z {$container} 80 2>/dev/null", $output, $returnCode);
                if ($returnCode === 0) {
                    return $container;
                }
            }
        }
        
        // Alternative: try to parse docker ps output if available
        $output = [];
        exec("docker ps --format '{{.Names}}' | grep -i telehealth | grep -i web", $output);
        if (!empty($output)) {
            return trim($output[0]);
        }
        
    } catch (Exception $e) {
        error_log("Auto-detection failed: " . $e->getMessage());
    }
    
    return null;
}

function getTelesaludContainerConfig() {
    try {
        // Try to read environment from telesalud container
        $output = [];
        exec('docker inspect official-staging-telehealth-app-1 2>/dev/null | grep -A 50 "Env"', $output);
        
        $config = ['api_url' => '', 'api_token' => ''];
        
        foreach ($output as $line) {
            if (strpos($line, 'TELEHEALTH_BASE_URL=') !== false) {
                $config['api_url'] = trim(str_replace(['"', ',', 'TELEHEALTH_BASE_URL='], '', $line));
            }
            if (strpos($line, 'TELEHEALTH_API_TOKEN=') !== false) {
                $config['api_token'] = trim(str_replace(['"', ',', 'TELEHEALTH_API_TOKEN='], '', $line));
            }
        }
        
        // Convert localhost URLs to container names
        if (!empty($config['api_url']) && (strpos($config['api_url'], 'localhost') !== false || strpos($config['api_url'], '127.0.0.1') !== false)) {
            $config['api_url'] = 'http://official-staging-telehealth-web-1/api';
        }
        
        return !empty($config['api_url']) ? $config : null;
        
    } catch (Exception $e) {
        error_log("Container config detection failed: " . $e->getMessage());
        return null;
    }
}

// Get auto-detected configuration
$autoConfig = autoDetectTelesaludConfig();

// Apply auto-detected values as defaults if no values are set
if (empty(th_get('telesalud_api_url')) && !empty($autoConfig['api_url'])) {
    th_set('telesalud_api_url', $autoConfig['api_url']);
}
if (empty(th_get('telesalud_api_token')) && !empty($autoConfig['api_token'])) {
    th_set('telesalud_api_token', $autoConfig['api_token']);
}
if (empty(th_get('telesalud_notification_token')) && !empty($autoConfig['notification_token'])) {
    th_set('telesalud_notification_token', $autoConfig['notification_token']);
}

// Remove external URL environment checks
$apiUrlEnv = getenv('TELEHEALTH_BASE_URL');
$apiTokenEnv = getenv('TELEHEALTH_API_TOKEN');
$notificationTokenEnv = getenv('NOTIFICATION_TOKEN');

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Telehealth Settings'); ?></title>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/public/assets/bootstrap/dist/css/bootstrap.min.css" />
</head>
<body class="container mt-4">
<h3><?php echo xlt('Telehealth Settings'); ?></h3>
<?php if (isset($_GET['saved'])) : ?>
    <div class="alert alert-success"><?php echo xlt('Settings saved'); ?></div>
<?php endif; ?>
<?php if (isset($testMessage)) : ?>
    <div class="alert alert-<?php echo $testSuccess ? 'success' : 'danger'; ?>">
        <strong><?php echo xlt('Connection Test:'); ?></strong> <?php echo xlt($testMessage); ?>
    </div>
<?php endif; ?>
<?php if (!empty($apiUrlEnv) || !empty($apiTokenEnv) || !empty($notificationTokenEnv)) : ?>
    <div class="alert alert-info">
        <strong><?php echo xlt('Environment Variables Detected:'); ?></strong>
        <ul class="mb-0">
            <?php if (!empty($apiUrlEnv)) : ?>
                <li><?php echo xlt('TELEHEALTH_BASE_URL'); ?>: <?php echo substr($apiUrlEnv, 0, 25) . '...'; ?></li>
            <?php endif; ?>
            <?php if (!empty($apiTokenEnv)) : ?>
                <li><?php echo xlt('TELEHEALTH_API_TOKEN'); ?>: <?php echo substr($apiTokenEnv, 0, 10) . '...'; ?></li>
            <?php endif; ?>
            <?php if (!empty($notificationTokenEnv)) : ?>
                <li><?php echo xlt('NOTIFICATION_TOKEN'); ?>: <?php echo substr($notificationTokenEnv, 0, 10) . '...'; ?></li>
            <?php endif; ?>
        </ul>
        <p class="mt-2 mb-0"><?php echo xlt('Environment variables take precedence over database settings'); ?></p>
    </div>
<?php endif; ?>
<form method="post" class="mt-3">
    <div class="mb-3">
        <label class="form-label">Mode</label>
        <select name="telehealth_mode" id="telehealth_mode" class="form-select" onchange="toggleMode()">
            <option value="standalone" <?php echo $mode==='standalone'?'selected':''; ?>>Stand-alone</option>
            <option value="telesalud" <?php echo $mode==='telesalud'?'selected':''; ?>>Telesalud backend</option>
        </select>
    </div>

    <div id="standalone_fields">
        <div class="mb-3">
            <label class="form-label">Provider</label>
            <select name="telehealth_provider" id="telehealth_provider" class="form-select" onchange="toggleProvider()">
                <option value="jitsi" <?php echo $prov==='jitsi'?'selected':''; ?>>Jitsi</option>
                <option value="google_meet" <?php echo $prov==='google_meet'?'selected':''; ?>>Google Meet</option>
                <option value="doxy_me" <?php echo $prov==='doxy_me'?'selected':''; ?>>Doxy.me</option>
                <option value="doximity" <?php echo $prov==='doximity'?'selected':''; ?>>Doximity</option>
                <option value="template" <?php echo $prov==='template'?'selected':''; ?>>Custom Template</option>
            </select>
        </div>
        <div id="jitsi_group" class="mb-3">
            <label class="form-label">Jitsi base URL (optional)</label>
            <input type="text" name="jitsi_base_url" class="form-control" value="<?php echo attr(th_get('jitsi_base_url','')); ?>" placeholder="https://meet.jit.si">
        </div>
        <div id="google_group" class="mb-3">
            <!-- no extra fields for Google Meet -->
            <small class="text-muted">Google Meet links will be generated automatically (e.g. https://meet.google.com/abc-defg-hij)</small>
        </div>
        <div id="doxy_group" class="mb-3">
            <label class="form-label">Your Doxy.me room URL</label>
            <input type="text" name="doxy_room_url" class="form-control" value="<?php echo attr(th_get('doxy_room_url','')); ?>" placeholder="https://doxy.me/drsmith">
        </div>
        <div id="doximity_group" class="mb-3">
            <label class="form-label">Your Doximity room URL</label>
            <input type="text" name="doximity_room_url" class="form-control" value="<?php echo attr(th_get('doximity_room_url','')); ?>" placeholder="https://doximity.com/telehealth/room">
        </div>
        <div id="template_group" class="mb-3">
            <label class="form-label">URL template (use {{slug}} placeholder)</label>
            <input type="text" name="telehealth_template_url" class="form-control" value="<?php echo attr(th_get('telehealth_template_url','')); ?>" placeholder="https://meet.google.com/{{slug}}">
        </div>
    </div>

    <div id="telesalud_fields">
        <div class="card mb-3">
            <div class="card-body bg-light">
                <h5 class="card-title">Telesalud Backend Connection</h5>
                
                <?php if ($autoConfig['detection_method'] !== 'none') : ?>
                    <div class="alert alert-info mb-3">
                        <strong>🔍 Auto-Detection Status:</strong>
                        <?php if ($autoConfig['detection_method'] === 'environment') : ?>
                            <span class="text-success">✅ Configuration loaded from environment variables</span>
                        <?php elseif ($autoConfig['detection_method'] === 'container_discovery') : ?>
                            <span class="text-success">✅ Telesalud container auto-detected in Docker network</span>
                        <?php endif; ?>
                        <br>
                        <small class="text-muted">
                            API URL: <code><?php echo $autoConfig['api_url']; ?></code><br>
                            Token: <code><?php echo !empty($autoConfig['api_token']) ? 'Detected (' . substr($autoConfig['api_token'], 0, 8) . '...)' : 'Not detected'; ?></code>
                        </small>
                    </div>
                <?php endif; ?>
                
                <p class="card-text">Configure the connection to the telesalud backend to enable real-time waiting room notifications and other advanced features.</p>
                <ul>
                    <li><strong>API URL</strong>: The base URL of your telesalud backend API (corresponds to TELEHEALTH_BASE_URL in .env)</li>
                    <li><strong>API Token</strong>: Authentication token generated using <code>php artisan token:issue</code> in the telesalud backend (corresponds to TELEHEALTH_API_TOKEN in .env)</li>
                    <li><strong>Notification Token</strong>: Token for securing webhook notifications (corresponds to NOTIFICATION_TOKEN in .env)</li>
                </ul>
                <p class="card-text mt-2"><strong>Important:</strong> To enable webhook notifications, set the following in your telesalud backend's .env file:</p>
                <pre class="bg-dark text-light p-2">NOTIFICATION_URL=https://your-openemr-url/modules/telehealth/api/notifications.php
NOTIFICATION_TOKEN=your-notification-token</pre>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">API URL</label>
            <?php 
            $currentApiUrl = th_get('telesalud_api_url','');
            $suggestedApiUrl = !empty($autoConfig['api_url']) ? $autoConfig['api_url'] : '';
            $showAutoDetected = !empty($suggestedApiUrl) && $suggestedApiUrl !== $currentApiUrl;
            ?>
            <input type="text" name="telesalud_api_url" class="form-control <?php echo !empty($apiUrlEnv) ? 'bg-light' : ''; ?>" 
                value="<?php echo attr($currentApiUrl); ?>" 
                placeholder="<?php echo !empty($suggestedApiUrl) ? $suggestedApiUrl : 'https://meet.telesalud.example.org:32443/api'; ?>"
                <?php echo !empty($apiUrlEnv) ? 'disabled' : ''; ?>>
            
            <?php if (!empty($apiUrlEnv)) : ?>
                <small class="text-success">✅ Using environment value: <?php echo substr($apiUrlEnv, 0, 50) . '...'; ?></small>
                <?php 
                // If env var is localhost, show the internal URL note
                if (strpos($apiUrlEnv, 'localhost') !== false || strpos($apiUrlEnv, '127.0.0.1') !== false) {
                    $detectedContainerForInfo = detectTelesaludContainer();
                    if ($detectedContainerForInfo && strpos($apiUrlEnv, $detectedContainerForInfo) === false) {
                        $urlPartsForInfo = parse_url($apiUrlEnv);
                        $schemeForInfo = $urlPartsForInfo['scheme'] ?? 'http';
                        // Attempt to get port from $apiUrlEnv, fallback to TELEHEALTH_PORT env, then default based on scheme
                        $portForInfo = $urlPartsForInfo['port'] ?? getenv('TELEHEALTH_PORT') ?: ($schemeForInfo === 'https' ? '443' : '80');
                        $internalTestUrl = $schemeForInfo . '://' . $detectedContainerForInfo . ':' . $portForInfo;
                        echo '<div class="mt-1"><small class="text-primary">ℹ️ For internal connection tests, the module will use: <code>' . $internalTestUrl . '</code></small></div>';
                    } elseif ($detectedContainerForInfo) { // Env is localhost, and detected container *matches* what localhost might be pointing to externally (less common but possible if hosts file is set up that way)
                        echo '<div class="mt-1"><small class="text-info">🐳 Telehealth container (<code>' . $detectedContainerForInfo . '</code>) detected. This will be used for internal tests.</small></div>';
                    }
                }
                ?>
            <?php elseif ($showAutoDetected) : // No env var, but form value differs from auto-detected non-localhost URL ?>
                <small class="text-info">🔍 Auto-detected: <?php echo $suggestedApiUrl; ?> 
                    <button type="button" class="btn btn-sm btn-outline-info ms-1" onclick="document.querySelector('input[name=telesalud_api_url]').value='<?php echo $suggestedApiUrl; ?>'">Use This</button>
                </small>
                <?php if ($autoConfig['detection_method'] === 'container_discovery' && $suggestedApiUrl === $autoConfig['api_url']) : ?>
                     <div class="mt-1"><small class="text-info">🐳 Using detected telesalud container: <?php echo $autoConfig['api_url']; ?></small></div>
                <?php endif; ?>
            <?php elseif (!empty($suggestedApiUrl)) : // No env var, form value (or empty) matches auto-detected, or is default suggestion ?>
                <small class="text-success">✅ Auto-detected and applied: <?php echo $suggestedApiUrl; ?></small>
                 <?php if ($autoConfig['detection_method'] === 'container_discovery' && $suggestedApiUrl === $autoConfig['api_url']) : ?>
                     <div class="mt-1"><small class="text-info">🐳 Using detected telesalud container: <?php echo $autoConfig['api_url']; ?></small></div>
                <?php endif; ?>
            <?php else : // No env var, no auto-detection, just placeholder ?>
                <small class="text-muted">Example: https://meet.telesalud.example.org:32443/api</small>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label">API Token</label>
            <?php 
            $currentApiToken = th_get('telesalud_api_token','');
            $suggestedApiToken = !empty($autoConfig['api_token']) ? $autoConfig['api_token'] : '';
            $showAutoDetectedToken = !empty($suggestedApiToken) && $suggestedApiToken !== $currentApiToken;
            ?>
            <input type="text" name="telesalud_api_token" class="form-control <?php echo !empty($apiTokenEnv) ? 'bg-light' : ''; ?>" 
                value="<?php echo attr($currentApiToken); ?>" 
                placeholder="1|OB00LDC8eGEHCAhKMjtDRUXu9buxOm2SREHzQqPz"
                <?php echo !empty($apiTokenEnv) ? 'disabled' : ''; ?>>
            
            <?php if (!empty($apiTokenEnv)) : ?>
                <small class="text-success">✅ Using environment value (token hidden for security)</small>
            <?php elseif ($showAutoDetectedToken) : ?>
                <small class="text-info">🔍 Auto-detected token from environment
                    <button type="button" class="btn btn-sm btn-outline-info ms-1" onclick="document.querySelector('input[name=telesalud_api_token]').value='<?php echo $suggestedApiToken; ?>'">Use This</button>
                </small>
            <?php elseif (!empty($suggestedApiToken)) : ?>
                <small class="text-success">✅ Auto-detected token from environment</small>
            <?php else : ?>
                <small class="text-muted">Generate with <code>docker-compose exec app php artisan token:issue</code> in the telesalud backend</small>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label">Notification Token</label>
            <input type="text" name="telesalud_notification_token" class="form-control <?php echo !empty($notificationTokenEnv) ? 'bg-light' : ''; ?>" 
                value="<?php echo attr(th_get('telesalud_notification_token','')); ?>" 
                placeholder="optional"
                <?php echo !empty($notificationTokenEnv) ? 'disabled' : ''; ?>>
            <?php if (!empty($notificationTokenEnv)) : ?>
                <small class="text-success">Using environment value (token hidden for security)</small>
            <?php else : ?>
                <small class="text-muted">Token for securing webhook notifications (corresponds to NOTIFICATION_TOKEN in .env)</small>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label">Days before expiration</label>
            <input type="number" name="telesalud_days_before_expiration" class="form-control" value="<?php echo attr(th_get('telesalud_days_before_expiration',3)); ?>">
            <small class="text-muted">Number of days before a meeting link expires</small>
        </div>
        
        <div class="card mb-3">
            <div class="card-body bg-light">
                <h6 class="card-title">Client Access URLs (External)</h6>
                <p class="card-text mb-2">Configure how patients and providers access the telehealth meetings from outside your Docker network.</p>
                
                <div class="mb-3">
                    <label class="form-label">External HTTP URL</label>
                    <input type="text" name="telesalud_external_url" class="form-control <?php echo !empty($externalUrlEnv) ? 'bg-light' : ''; ?>" 
                        value="<?php echo attr(th_get('telesalud_external_url','')); ?>" 
                        placeholder="http://localhost:31290 or https://vc.domain.com"
                        <?php echo !empty($externalUrlEnv) ? 'disabled' : ''; ?>>
                    <?php if (!empty($externalUrlEnv)) : ?>
                        <small class="text-success">✅ Using environment value: <?php echo $externalUrlEnv; ?></small>
                    <?php else : ?>
                        <small class="text-muted">The URL clients use to access telehealth meetings (with port if needed)</small>
                    <?php endif; ?>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">External HTTPS URL (optional)</label>
                    <input type="text" name="telesalud_external_https_url" class="form-control <?php echo !empty($externalHttpsUrlEnv) ? 'bg-light' : ''; ?>" 
                        value="<?php echo attr(th_get('telesalud_external_https_url','')); ?>" 
                        placeholder="https://localhost:31453 or https://vc.domain.com"
                        <?php echo !empty($externalHttpsUrlEnv) ? 'disabled' : ''; ?>>
                    <?php if (!empty($externalHttpsUrlEnv)) : ?>
                        <small class="text-success">✅ Using environment value: <?php echo $externalHttpsUrlEnv; ?></small>
                    <?php else : ?>
                        <small class="text-muted">HTTPS URL for secure access (auto-derived from HTTP if not specified)</small>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty(th_get('telesalud_external_url','')) || !empty($externalUrlEnv)) : ?>
                    <div class="alert alert-success">
                        <strong>🌐 Current External URLs:</strong><br>
                        <small>
                            HTTP: <code><?php echo !empty($externalUrlEnv) ? $externalUrlEnv : th_get('telesalud_external_url',''); ?></code><br>
                            HTTPS: <code><?php echo !empty($externalHttpsUrlEnv) ? $externalHttpsUrlEnv : (!empty(th_get('telesalud_external_https_url','')) ? th_get('telesalud_external_https_url','') : 'Auto-derived'); ?></code>
                        </small>
                    </div>
                <?php endif; ?>
                
                <small class="text-info">
                    <strong>💡 For remote telehealth backends:</strong> Set these URLs to match how clients access your telehealth backend from outside your server/Docker network.
                </small>
            </div>
        </div>
        
        <div class="mb-3">
            <button type="submit" name="test_connection" value="1" class="btn btn-secondary">Test Connection</button>
            <small class="text-muted ms-2">Verify your connection to the telesalud backend</small>
        </div>
    </div>

    <h5 class="mt-4">Reminders</h5>
    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" value="1" id="rem_day" name="rem_day" <?php echo th_get('rem_day')?'checked':''; ?>>
        <label class="form-check-label" for="rem_day">Send reminder 1 day before (at)</label>
        <input type="time" name="rem_day_time" value="<?php echo attr(th_get('rem_day_time','17:00')); ?>" class="ms-2">
    </div>
    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" value="1" id="rem_hour" name="rem_hour" <?php echo th_get('rem_hour')?'checked':''; ?>>
        <label class="form-check-label" for="rem_hour">Send reminder 1 hour before</label>
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" value="1" id="rem_sms" name="rem_sms" <?php echo th_get('rem_sms')?'checked':''; ?>>
        <label class="form-check-label" for="rem_sms">Include SMS (requires Twilio)</label>
    </div>

    <h5 class="mt-4">Advanced</h5>
    <div class="mb-3">
        <label class="form-label">Log file path</label>
        <input type="text" name="telehealth_log_file" class="form-control" value="<?php echo attr(th_get('telehealth_log_file','')); ?>" placeholder="/var/log/telehealth.log">
        <small class="text-muted">Leave blank for default telehealth.log inside module folder.</small>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
</form>

<script>
function toggleMode() {
    const mode = document.getElementById('telehealth_mode').value;
    document.getElementById('standalone_fields').style.display = (mode === 'standalone') ? 'block':'none';
    document.getElementById('telesalud_fields').style.display = (mode === 'telesalud') ? 'block':'none';
}
function toggleProvider() {
    const prov = document.getElementById('telehealth_provider').value;
    document.getElementById('jitsi_group').style.display      = (prov === 'jitsi') ? 'block':'none';
    document.getElementById('google_group').style.display     = (prov === 'google_meet') ? 'block':'none';
    document.getElementById('doxy_group').style.display       = (prov === 'doxy_me') ? 'block':'none';
    document.getElementById('doximity_group').style.display   = (prov === 'doximity') ? 'block':'none';
    document.getElementById('template_group').style.display   = (prov === 'template') ? 'block':'none';
}

// Handle test connection via AJAX
document.querySelector('button[name="test_connection"]').addEventListener('click', function(e) {
    e.preventDefault();
    
    // Get form data
    const formData = new FormData();
    formData.append('test_connection', '1');
    formData.append('telesalud_api_url', document.querySelector('input[name="telesalud_api_url"]').value);
    formData.append('telesalud_api_token', document.querySelector('input[name="telesalud_api_token"]').value);
    
    // Show loading state
    const button = this;
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = 'Testing...';
    
    // Make AJAX request
    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Server response:', text);
                throw new Error('Invalid JSON response from server');
            }
        });
    })
    .then(data => {
        // Show result in a Bootstrap alert
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${data.success ? 'success' : 'danger'} alert-dismissible fade show mt-2`;
        alertDiv.innerHTML = `
            ${data.message}
        `;
        
        // Remove any existing alerts
        const existingAlerts = document.querySelectorAll('.alert');
        existingAlerts.forEach(alert => alert.remove());
        
        // Insert the new alert after the button
        button.parentNode.insertBefore(alertDiv, button.nextSibling);
    })
    .catch(error => {
        console.error('Error:', error);
        // Show error in an alert
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-2';
        alertDiv.innerHTML = `
            Error testing connection: ${error.message}
        `;
        
        // Remove any existing alerts
        const existingAlerts = document.querySelectorAll('.alert');
        existingAlerts.forEach(alert => alert.remove());
        
        // Insert the new alert after the button
        button.parentNode.insertBefore(alertDiv, button.nextSibling);
    })
    .finally(() => {
        // Restore button state
        button.disabled = false;
        button.innerHTML = originalText;
    });
});

// initial
toggleMode();
toggleProvider();
</script>
</body>
</html>
