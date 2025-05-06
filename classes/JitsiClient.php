<?php
namespace Telehealth\Classes;

use Exception;

/**
 * Lightweight helper to generate Jitsi meeting URLs.  We replicate the legacy
 * behaviour of the original TeleSalud modifications: a deterministic prefix
 * plus a random slug so the link is unique and hard to guess.
 *
 * If a custom base URL is required (self-hosted Jitsi instance), define the
 * global `$GLOBALS['jitsi_base_url']` in OpenEMR Globals (or via
 * sites/…/sqlconf.php).  Otherwise we fall back to the public meet.jit.si.
 */
class JitsiClient
{
    /**
     * Create a brand-new meeting link for an encounter.
     *
     * @param int    $encounterId
     * @param string $appointmentDate  Y-m-d H:i:s (local timezone)
     * @param string $medicName
     * @param string $patientName
     * @return array [success => bool, meeting_url => string, message => string]
     */
    public static function createMeeting(int $encounterId, string $appointmentDate, string $medicName, string $patientName): array
    {
        $mode = strtolower($GLOBALS['telehealth_mode'] ?? 'standalone');

        if ($mode === 'telesalud') {
            $apiUrl   = rtrim($GLOBALS['telesalud_api_url'] ?? '', '/');
            $apiToken = $GLOBALS['telesalud_api_token'] ?? '';
            if (!$apiUrl || !$apiToken) {
                // Mis-configured – fall back silently
                $mode = 'standalone';
            }
        }

        // --- Remote telesalud backend ------------------------------------
        if ($mode === 'telesalud') {
            try {
                $endpoint = $apiUrl . '/videoconsultation';

                $payload = http_build_query([
                    'appointment_date'       => $appointmentDate,
                    'days_before_expiration' => (int)($GLOBALS['telesalud_days_before_expiration'] ?? 3),
                    'medic_name'             => $medicName,
                    'patient_name'           => $patientName,
                    'id_turno'               => $encounterId,
                ]);

                $headers = [
                    'Authorization: Bearer ' . $apiToken,
                    'Content-Type: application/x-www-form-urlencoded',
                ];

                $ch = curl_init($endpoint);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);

                $response = curl_exec($ch);
                $errno    = curl_errno($ch);
                $error    = curl_error($ch);
                curl_close($ch);

                if ($errno !== 0) {
                    throw new Exception('cURL error: ' . $error);
                }

                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

                return [
                    'success'     => true,
                    'meeting_url' => $data['medic_url'] ?? $data['patient_url'] ?? '',
                    'medic_url'   => $data['medic_url'] ?? null,
                    'patient_url' => $data['patient_url'] ?? null,
                    'message'     => 'Meeting generated via telesalud backend',
                ];
            } catch (Exception $e) {
                // Fall back to standalone generation if API fails
                $mode = 'standalone';
            }
        }

        // --- Stand-alone meet.jit.si fallback ----------------------------
        try {
            $provider = strtolower($GLOBALS['telehealth_provider'] ?? 'jitsi');
            $slug     = bin2hex(random_bytes(5)); // 10 chars

            if ($provider === 'google_meet') {
                // Meet codes are 3 blocks of 3/4 letters; generate slug*2 to get 10 chars then format abc-defg-hij
                $code = substr($slug, 0, 3) . '-' . substr($slug, 3, 4) . '-' . substr($slug, 7, 3);
                $meetingUrl = 'https://meet.google.com/' . $code;
            } elseif ($provider === 'doxy_me') {
                $meetingUrl = $GLOBALS['doxy_room_url'] ?? '';
                if (!$meetingUrl) {
                    throw new Exception('Doxy.me room URL not configured');
                }
            } elseif ($provider === 'doximity') {
                $meetingUrl = $GLOBALS['doximity_room_url'] ?? '';
                if (!$meetingUrl) {
                    throw new Exception('Doximity room URL not configured');
                }
            } elseif ($provider === 'template') {
                $tpl = $GLOBALS['telehealth_template_url'] ?? '';
                if (empty($tpl)) {
                    $provider = 'jitsi'; // fallback if template missing
                } else {
                    if (strpos($tpl, '{{slug}}') !== false) {
                        $meetingUrl = str_replace('{{slug}}', $slug, $tpl);
                    } else {
                        $meetingUrl = rtrim($tpl, '/') . '/' . $slug;
                    }
                }
            }

            if ($provider === 'jitsi') {
                $base = $GLOBALS['jitsi_base_url'] ?? 'https://meet.jit.si';
                $base = rtrim($base, '/');
                $meetingUrl = sprintf('%s/EMRTelevisit-%d-%s', $base, $encounterId, $slug);
            }

            return [
                'success'     => true,
                'meeting_url' => $meetingUrl,
                'medic_url'   => $meetingUrl,
                'patient_url' => $meetingUrl,
                'message'     => 'Meeting generated locally',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
