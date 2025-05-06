<?php
namespace Telehealth\Hooks;

use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Common\Csrf\CsrfUtils;

/**
 * Injects Telehealth meeting links into calendar appointment pop-ups.
 *
 * NOTE: This relies on AppointmentRenderEvent introduced in v7.0+. If running an
 * older core, replace the event class accordingly.
 */
class CalendarHooks
{
    public static function register()
    {
        if (class_exists(AppointmentRenderEvent::class)) {
            global $eventDispatcher;
            // hook into below-patient render so we can show buttons in appointment form
            $eventDispatcher->addListener(
                AppointmentRenderEvent::RENDER_BELOW_PATIENT,
                [self::class, 'addTelehealthLinks']
            );
        }
    }

    /**
     * Add Telehealth URLs (for provider & patient) to the rendered HTML.
     *
     * @param AppointmentRenderEvent $event
     * @return AppointmentRenderEvent
     */
    public static function addTelehealthLinks(AppointmentRenderEvent $event): AppointmentRenderEvent
    {
        $appt = $event->getAppt();
        $eid = $appt['pc_eid'] ?? ($appt['eid'] ?? 0);
        $pid = $appt['pc_pid'] ?? 0;

        // Build URLs
        $providerUrl = "../../modules/telehealth/public/start.php?role=provider&eid=" . urlencode($eid);
        $patientUrl  = "../../modules/telehealth/public/start.php?role=patient&eid=" . urlencode($eid);

        $csrf = CsrfUtils::collectCsrfToken();

        $btns = '<div class="mt-2">';
        $btns .= "<a class='btn btn-primary mr-1' target='_blank' href='{$providerUrl}'>" . xlt('Start Tele-visit (Provider)') . "</a> ";
        $btns .= "<a class='btn btn-primary mr-1' target='_blank' href='{$patientUrl}'>" . xlt('Start Tele-visit (Patient)') . "</a> ";
        $btns .= "<button type='button' class='btn btn-secondary mr-1' id='send_invite_email_{$eid}'>" . xlt('Send Invite (Email)') . "</button>";
        $btns .= "<button type='button' class='btn btn-secondary' id='send_invite_sms_{$eid}'>" . xlt('Send Invite (SMS)') . "</button>";
        $btns .= '</div>';

        // inline JS for send invite
        $script = "<script>(function(){function send(ch){return function(){var btn=this;btn.disabled=true;fetch('../../modules/telehealth/api/invite.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token={$csrf}&encounter_id={$eid}&pid={$pid}&channel='+ch}).then(r=>r.json()).then(d=>{alert(d.message);btn.disabled=false;}).catch(()=>{alert('Error');btn.disabled=false;});};}document.getElementById('send_invite_email_{$eid}').addEventListener('click',send('email'));document.getElementById('send_invite_sms_{$eid}').addEventListener('click',send('sms'));})();</script>";

        $injection = $btns . $script;

        echo $injection; // Output directly since this event happens during template rendering

        return $event;
    }
}
