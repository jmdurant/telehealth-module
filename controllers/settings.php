<?php
/**
 * Telehealth module settings page
 */
require_once __DIR__ . '/../../../interface/globals.php';
require_once $GLOBALS['srcdir'] . '/formdata.inc.php';

// Only admin users
if (!acl_check_core('admin', 'super')) {
    die(xl('Not authorized')); // basic guard
}

// Convenience to get/update globals
function th_get($key, $default = '') {
    return isset($GLOBALS[$key]) ? $GLOBALS[$key] : $default;
}

function th_set($key, $value) {
    sqlStatement('REPLACE INTO globals (gl_name, gl_value) VALUES (?,?)', [$key, $value]);
    $GLOBALS[$key] = $value;
}

// Test connection to telesalud backend
function testTelesaludConnection($url, $token) {
    if (empty($url) || empty($token)) {
        return ['success' => false, 'message' => 'API URL and token are required'];
    }
    
    // Ensure URL ends with a slash
    if (substr($url, -1) !== '/') {
        $url .= '/';
    }
    
    // Create a test request to the API
    $ch = curl_init($url . 'status');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing only
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'message' => 'Connection error: ' . $error];
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Connection successful!'];
    } else {
        return ['success' => false, 'message' => 'API returned error code: ' . $httpCode];
    }
}

// Handle save or test connection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Test connection if requested
    if (isset($_POST['test_connection'])) {
        $apiUrl = trim($_POST['telesalud_api_url'] ?? '');
        $apiToken = trim($_POST['telesalud_api_token'] ?? '');
        $testResult = testTelesaludConnection($apiUrl, $apiToken);
        // Don't save settings, just show test result
        $testMessage = $testResult['message'];
        $testSuccess = $testResult['success'];
    } else {
        // Regular save
        $settings = [
            'telehealth_mode'                    => $_POST['telehealth_mode'] ?? 'standalone',
            'telehealth_provider'                => $_POST['telehealth_provider'] ?? 'jitsi',
            'jitsi_base_url'                     => trim($_POST['jitsi_base_url'] ?? ''),
            'telehealth_template_url'            => trim($_POST['telehealth_template_url'] ?? ''),
            'telesalud_api_url'                  => trim($_POST['telesalud_api_url'] ?? ''),
            'telesalud_api_token'                => trim($_POST['telesalud_api_token'] ?? ''),
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
$mode    = th_get('telehealth_mode', 'standalone');
$prov    = th_get('telehealth_provider', 'jitsi');

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Telehealth Settings'); ?></title>
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot'] ?>/assets/bootstrap/css/bootstrap.min.css" />
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
                <p class="card-text">Configure the connection to the telesalud backend to enable real-time waiting room notifications and other advanced features.</p>
                <ul>
                    <li><strong>API URL</strong>: The base URL of your telesalud backend API (corresponds to TELEHEALTH_BASE_URL in .env)</li>
                    <li><strong>API Token</strong>: Authentication token generated using <code>php artisan token:issue</code> in the telesalud backend (corresponds to TELEHEALTH_API_TOKEN in .env)</li>
                </ul>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">API URL</label>
            <input type="text" name="telesalud_api_url" class="form-control" value="<?php echo attr(th_get('telesalud_api_url','')); ?>" placeholder="https://meet.telesalud.example.org:32443/api">
            <small class="text-muted">Example: https://meet.telesalud.example.org:32443/api</small>
        </div>
        <div class="mb-3">
            <label class="form-label">API Token</label>
            <input type="text" name="telesalud_api_token" class="form-control" value="<?php echo attr(th_get('telesalud_api_token','')); ?>" placeholder="1|OB00LDC8eGEHCAhKMjtDRUXu9buxOm2SREHzQqPz">
            <small class="text-muted">Generate with <code>docker-compose exec app php artisan token:issue</code> in the telesalud backend</small>
        </div>
        <div class="mb-3">
            <label class="form-label">Days before expiration</label>
            <input type="number" name="telesalud_days_before_expiration" class="form-control" value="<?php echo attr(th_get('telesalud_days_before_expiration',3)); ?>">
            <small class="text-muted">Number of days before a meeting link expires</small>
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
// initial
toggleMode();
toggleProvider();
</script>
</body>
</html>
