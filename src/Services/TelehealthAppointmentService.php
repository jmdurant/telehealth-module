<?php

/**
 * Telehealth Appointment Service
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    James DuRant <jmdurant@gmail.com>
 * @copyright Copyright (c) 2023-2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\Telehealth\Services;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\AppointmentService;

class TelehealthAppointmentService
{
    /**
     * @var SystemLogger
     */
    private $logger;

    /**
     * @var AppointmentService
     */
    private $appointmentService;

    /**
     * TelehealthAppointmentService constructor.
     */
    public function __construct()
    {
        $this->logger = new SystemLogger();
        $this->appointmentService = new AppointmentService();
    }

    /**
     * Check if an appointment is a telehealth appointment
     *
     * @param int $appointmentId
     * @return bool
     */
    public function isTelehealthAppointment(int $appointmentId): bool
    {
        try {
            $appt = $this->appointmentService->getAppointment($appointmentId);
            if (empty($appt)) {
                return false;
            }

            $catId = $appt['pc_catid'] ?? null;
            if (empty($catId)) {
                return false;
            }

            $catRow = sqlQuery('SELECT pc_constant_id FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$catId]);
            return in_array($catRow['pc_constant_id'], ['telehealth_new_patient', 'telehealth_established_patient']);
        } catch (\Exception $e) {
            $this->logger->error("Error checking telehealth appointment", ['message' => $e->getMessage(), 'appointmentId' => $appointmentId]);
            return false;
        }
    }

    /**
     * Create or update a telehealth meeting for an appointment
     *
     * @param int $appointmentId
     * @return array|null Meeting details or null on failure
     */
    public function createOrUpdateMeeting(int $appointmentId): ?array
    {
        try {
            if (!$this->isTelehealthAppointment($appointmentId)) {
                $this->logger->debug("Not a telehealth appointment", ['appointmentId' => $appointmentId]);
                return null;
            }

            // Check if meeting already exists
            $meeting = $this->getMeetingForAppointment($appointmentId);
            
            if (!empty($meeting)) {
                $this->logger->debug("Meeting already exists", ['appointmentId' => $appointmentId, 'meetingId' => $meeting['id']]);
                return $meeting;
            }

            // Create a new meeting
            $meetingUrl = $this->generateMeetingUrl();
            $meetingId = $this->saveMeetingToDatabase($appointmentId, $meetingUrl);
            
            if ($meetingId) {
                return [
                    'id' => $meetingId,
                    'appointment_id' => $appointmentId,
                    'meeting_url' => $meetingUrl,
                    'created' => date('Y-m-d H:i:s')
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Error creating telehealth meeting", ['message' => $e->getMessage(), 'appointmentId' => $appointmentId]);
            return null;
        }
    }

    /**
     * Get meeting details for an appointment
     *
     * @param int $appointmentId
     * @return array|null Meeting details or null if not found
     */
    public function getMeetingForAppointment(int $appointmentId): ?array
    {
        try {
            $sql = "SELECT * FROM telehealth_vc WHERE encounter_id = ?";
            $result = sqlQuery($sql, [$appointmentId]);
            
            if (empty($result)) {
                return null;
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Error getting telehealth meeting", ['message' => $e->getMessage(), 'appointmentId' => $appointmentId]);
            return null;
        }
    }

    /**
     * Generate a unique meeting URL
     * 
     * @return string
     */
    private function generateMeetingUrl(): string
    {
        // Generate a unique identifier for the meeting
        $uniqueId = uniqid('telehealth_', true);
        return $uniqueId;
    }

    /**
     * Save meeting details to the database
     *
     * @param int $appointmentId
     * @param string $meetingUrl
     * @return int|null Meeting ID or null on failure
     */
    private function saveMeetingToDatabase(int $appointmentId, string $meetingUrl): ?int
    {
        try {
            $sql = "INSERT INTO telehealth_vc (encounter_id, meeting_url, created) VALUES (?, ?, NOW())";
            $result = sqlInsert($sql, [$appointmentId, $meetingUrl]);
            
            if (!$result) {
                $this->logger->error("Failed to save meeting to database", ['appointmentId' => $appointmentId]);
                return null;
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Error saving telehealth meeting", ['message' => $e->getMessage(), 'appointmentId' => $appointmentId]);
            return null;
        }
    }
} 