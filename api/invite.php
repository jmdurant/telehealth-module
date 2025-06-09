<?php
require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/Classes/InviteHelper.php';

use Telehealth\Classes\InviteHelper;
use OpenEMR\Common\Csrf\CsrfUtils;

// CSRF check
CsrfUtils::verifyCsrf();

$encounterId = intval($_POST['encounter_id'] ?? 0);
$pid = intval($_POST['pid'] ?? 0);
$channel = $_POST['channel'] ?? 'email';

if (!$encounterId || !$pid) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

switch ($channel) {
    case 'email':
        $result = InviteHelper::email($pid, $encounterId);
        break;
    case 'sms':
        $result = InviteHelper::sms($pid, $encounterId);
        break;
    default:
        $result = ['success' => false, 'message' => 'Unsupported channel'];
}

echo json_encode($result);
