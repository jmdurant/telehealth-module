<?php

namespace OpenEMR\Modules\Telehealth\Controller;

use OpenEMR\Common\Csrf\CsrfUtils;
use Twig\Environment;

class CalendarController
{
    private $twig;
    
    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }
    
    public function renderAppointmentButtons($eid, $pid)
    {
        // Build URLs for our custom module
        $providerUrl = "../../modules/custom_modules/oe-module-telehealth/controllers/index.php?action=start&role=provider&eid=" . urlencode($eid);
        $patientUrl  = "../../modules/custom_modules/oe-module-telehealth/controllers/index.php?action=start&role=patient&eid=" . urlencode($eid);

        // Get CSRF token if available
        $csrf = '';
        if (class_exists('\\OpenEMR\\Common\\Csrf\\CsrfUtils')) {
            $csrf = CsrfUtils::collectCsrfToken();
        }

        return $this->twig->render('calendar/appointment_buttons.html.twig', [
            'eid' => $eid,
            'pid' => $pid,
            'providerUrl' => $providerUrl,
            'patientUrl' => $patientUrl,
            'csrf' => $csrf,
            'apiEndpoint' => "../../modules/custom_modules/oe-module-telehealth/api/invite.php"
        ]);
    }
} 