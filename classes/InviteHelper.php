<?php
namespace Telehealth\Classes;

use MyMailer;
use Twilio\Rest\Client as TwilioClient;
use DateTime;

class InviteHelper
{
    public static function email(int $pid, int $encounterId, ?string $meetingUrl = null): array
    {
        if (!$meetingUrl) {
            $mode = strtolower($GLOBALS['telehealth_mode'] ?? 'standalone');
            $field = $mode === 'telesalud' ? 'patient_url' : 'meeting_url';
            $row = sqlQuery("SELECT $field AS url FROM telehealth_vc WHERE encounter_id = ?", [$encounterId]);
            if (!$row) {
                return ['success' => false, 'message' => 'Meeting URL not found'];
            }
            $meetingUrl = $row['url'];
        }

        $patient = sqlQuery('SELECT fname, lname, email FROM patient_data WHERE pid = ?', [$pid]);
        if (!$patient || empty($patient['email'])) {
            return ['success' => false, 'message' => 'Patient email not available'];
        }

        $to = $patient['email'];
        $name = trim($patient['fname'] . ' ' . $patient['lname']);

        $subject = xl('Telehealth Appointment');
        $body = xl('Hello') . " {$name},\n\n" .
            xl('Your telehealth appointment is scheduled. Please join using the link below:') . "\n" .
            $meetingUrl . "\n\n" . xl('Thank you.');

        try {
            $mailer = new MyMailer();
            $mailer->setFrom($GLOBALS['email'] ?? $GLOBALS['user_email'] ?? 'no-reply@localhost', $GLOBALS['facility_name'] ?? 'Clinic');
            $mailer->addAddress($to, $name);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            if (!$mailer->send()) {
                return ['success' => false, 'message' => 'Mailer error: ' . $mailer->ErrorInfo];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        sqlStatement('CREATE TABLE IF NOT EXISTS telehealth_invites (id INT AUTO_INCREMENT PRIMARY KEY, encounter_id INT, pid INT, channel VARCHAR(16), sent_at DATETIME)');
        sqlStatement('INSERT INTO telehealth_invites (encounter_id, pid, channel, sent_at) VALUES (?,?,?,?)', [
            $encounterId,
            $pid,
            'email',
            (new DateTime())->format('Y-m-d H:i:s')
        ]);

        return ['success' => true, 'message' => 'Invite sent'];
    }

    /**
     * Send SMS invite via Twilio (requires oe-module-faxsms / Twilio SDK).
     */
    public static function sms(int $pid, int $encounterId, ?string $meetingUrl = null): array
    {
        if (!$meetingUrl) {
            $mode = strtolower($GLOBALS['telehealth_mode'] ?? 'standalone');
            $field = $mode === 'telesalud' ? 'patient_url' : 'meeting_url';
            $row = sqlQuery("SELECT $field AS url FROM telehealth_vc WHERE encounter_id = ?", [$encounterId]);
            if (!$row) {
                return ['success' => false, 'message' => 'Meeting URL not found'];
            }
            $meetingUrl = $row['url'];
        }

        $patient = sqlQuery('SELECT fname, lname, phone_cell, phone_home FROM patient_data WHERE pid = ?', [$pid]);
        $phone = $patient['phone_cell'] ?? $patient['phone_home'] ?? '';
        if (!$phone) {
            return ['success' => false, 'message' => 'Patient phone not available'];
        }

        // Ensure Twilio credentials present
        $sid = $GLOBALS['twilio_sid'] ?? '';
        $token = $GLOBALS['twilio_token'] ?? '';
        $from = $GLOBALS['twilio_from'] ?? '';
        if (!$sid || !$token || !$from) {
            return ['success' => false, 'message' => 'Twilio credentials not configured'];
        }

        try {
            $twilio = new TwilioClient($sid, $token);
            $twilio->messages->create(
                $phone,
                [
                    'from' => $from,
                    'body' => xl('Telehealth appointment link:') . ' ' . $meetingUrl
                ]
            );
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        sqlStatement('CREATE TABLE IF NOT EXISTS telehealth_invites (id INT AUTO_INCREMENT PRIMARY KEY, encounter_id INT, pid INT, channel VARCHAR(16), sent_at DATETIME)');
        sqlStatement('INSERT INTO telehealth_invites (encounter_id, pid, channel, sent_at) VALUES (?,?,?,?)', [
            $encounterId,
            $pid,
            'sms',
            (new DateTime())->format('Y-m-d H:i:s')
        ]);

        return ['success' => true, 'message' => 'SMS invite sent'];
    }
}
