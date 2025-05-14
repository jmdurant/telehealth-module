<?php

namespace OpenEMR\Modules\Telehealth\Services;

class JitsiClient
{
    public static function createMeeting($eid, $appointmentDate, $providerName, $patientName)
    {
        // Generate a unique meeting ID based on the appointment ID
        $meetingId = 'telehealth-' . $eid . '-' . date('Ymd', strtotime($appointmentDate));
        
        // Base URL for your Jitsi server - this should come from module settings
        $jitsiServer = $GLOBALS['telehealth_jitsi_server'] ?? 'meet.jit.si';
        
        // Create meeting URLs
        $baseUrl = "https://" . $jitsiServer . "/" . urlencode($meetingId);
        
        // For now, we'll use the same URL for both provider and patient
        // In a production environment, you might want to add authentication tokens
        return [
            'success' => true,
            'meeting_url' => $baseUrl,
            'medic_url' => $baseUrl . '?userType=provider&name=' . urlencode($providerName),
            'patient_url' => $baseUrl . '?userType=patient&name=' . urlencode($patientName),
            'backend_id' => $meetingId
        ];
    }
} 