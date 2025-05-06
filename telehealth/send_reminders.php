<?php
//Telehealth reminder sender script
//Schedule: */5 * * * * php /path/to/openemr/modules/telehealth/send_reminders.php


use Telehealth\Classes\InviteHelper;

$root = realpath(__DIR__ . '/../..'); // openemr root
require_once $root . '/interface/globals.php';
require_once __DIR__ . '/classes/InviteHelper.php';

if (php_sapi_name() !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

function th_log(string $msg): void { echo date('Y-m-d H:i:s') . " - $msg\n"; }

$sendDay  = !empty($GLOBALS['rem_day']);
$sendHour = !empty($GLOBALS['rem_hour']);
$sendSms  = !empty($GLOBALS['rem_sms']);
$dayTime  = $GLOBALS['rem_day_time'] ?? '17:00';

if (!$sendDay && !$sendHour) {
    th_log('Reminder disabled');
    exit;
}

// Ensure tracking table
sqlStatement('CREATE TABLE IF NOT EXISTS telehealth_reminders (id INT AUTO_INCREMENT PRIMARY KEY, encounter_id INT, type VARCHAR(16), sent_at DATETIME)');

$now     = new DateTime('now', new DateTimeZone('America/New_York'));
$timeNow = $now->format('H:i');

$mark = function(int $eid,string $type){ sqlStatement('INSERT INTO telehealth_reminders (encounter_id,type,sent_at) VALUES (?,?,NOW())',[$eid,$type]); };

// day-before at configured time (5-minute window)
if ($sendDay && $timeNow >= $dayTime && $timeNow < date('H:i', strtotime($dayTime)+300)) {
    $target = (clone $now)->modify('+1 day')->format('Y-m-d');
    $events = sqlStatement('SELECT pc_eid, pc_pid FROM openemr_postcalendar_events WHERE pc_eventDate = ?', [$target]);
    while ($e = sqlFetchArray($events)) {
        $eid = (int)$e['pc_eid'];
        if (sqlQuery('SELECT 1 FROM telehealth_reminders WHERE encounter_id=? AND type="day"', [$eid])) continue;
        $row = sqlQuery('SELECT patient_url url, meeting_url FROM telehealth_vc WHERE encounter_id = ?', [$eid]);
        $url = $row['url'] ?: $row['meeting_url'];
        InviteHelper::email((int)$e['pc_pid'], $eid, $url);
        if ($sendSms) InviteHelper::sms((int)$e['pc_pid'], $eid, $url);
        $mark($eid,'day');
        th_log("Sent day-before for $eid");
    }
}

// hour-before (55-65 min window)
if ($sendHour) {
    $start = (clone $now)->modify('+55 minutes')->format('Y-m-d H:i:s');
    $end   = (clone $now)->modify('+65 minutes')->format('Y-m-d H:i:s');
    $events = sqlStatement('SELECT pc_eid, pc_pid, CONCAT(pc_eventDate, " ", pc_startTime) as dt FROM openemr_postcalendar_events WHERE dt BETWEEN ? AND ?', [$start,$end]);
    while ($e = sqlFetchArray($events)) {
        $eid = (int)$e['pc_eid'];
        if (sqlQuery('SELECT 1 FROM telehealth_reminders WHERE encounter_id=? AND type="hour"', [$eid])) continue;
        $row = sqlQuery('SELECT patient_url url, meeting_url FROM telehealth_vc WHERE encounter_id = ?', [$eid]);
        $url = $row['url'] ?: $row['meeting_url'];
        InviteHelper::email((int)$e['pc_pid'], $eid, $url);
        if ($sendSms) InviteHelper::sms((int)$e['pc_pid'], $eid, $url);
        $mark($eid,'hour');
        th_log("Sent hour-before for $eid");
    }
}
