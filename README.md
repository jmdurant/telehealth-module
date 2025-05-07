# Telehealth Module for OpenEMR

Comprehensive, **plug-in telehealth add-on** that adds virtual-visit workflows to core OpenEMR without touching core files.  
All integrations are done through OpenEMR’s hook & event system.

---
## Key Features

| Area | Functionality |
|------|---------------|
| Virtual‐Visit Links | • Stores per-encounter meeting URL in `telehealth_vc` table.  |
| Patient Summary     | • Badge shows *Start Tele-visit* when encounter has a meeting.<br>• Links patient directly into visit. |
| Calendar Popup      | • Provider & Patient *Start Tele-visit* buttons.<br>• "Send Invite (Email)" and "Send Invite (SMS)" buttons to notify patient. |
| Real-time Notifications | • WebSocket connection to telesalud backend for live updates.<br>• Toast notifications when patients join waiting room.<br>• One-click access to join meetings from any page in OpenEMR. |
| Email Invites       | • `InviteHelper::email()` uses existing SMTP (MyMailer) settings to send branded invite with meeting URL. |
| SMS Invites         | • `InviteHelper::sms()` uses Twilio via **oe-module-faxsms**.<br>• Sends concise meeting link to patient mobile. |
| Audit Logging       | • `telehealth_invites` table records each invite (channel, datetime). |
| Zero Core Patches   | • Hooks: `SummaryHooks`, `CalendarHooks`, `HeaderHooks`.<br>• Events: `RenderEvent`, `AppointmentRenderEvent`. |

---
## Directory Structure

```
modules/telehealth
├─ api/              # AJAX endpoints (invite.php, upcoming.php)
├─ classes/          # Helpers (InviteHelper, JitsiClient, …)
├─ hooks/            # Hook listeners (SummaryHooks, CalendarHooks, HeaderHooks)
├─ public/           # Public files (start.php, waiting_room.js)
├─ sql/              # 01-telehealth_vc.sql, upgrades, etc.
├─ twig/             # Twig templates (badge.html.twig)
└─ README.md         # You are here
```

---
## Requirements

* OpenEMR 7.0+ (event system).
* Outgoing email configured in *Globals → Notifications*.
* For SMS: install **oe-module-faxsms** (includes Twilio SDK) and set:
  * `twilio_sid`
  * `twilio_token`
  * `twilio_from` (verified sending number)

---
## Installation

1. Copy/clone `modules/telehealth` into your OpenEMR `modules/` directory (keep folder name **telehealth**).
2. Log in as Administrator → **Modules** → *Manage Modules* → click **Install** beside *Telehealth*.
3. The installer will:
   * Run SQL to create `telehealth_vc` & `telehealth_invites` tables.
   * Register hooks/events.
4. (Optional) Install **oe-module-faxsms** the same way to enable SMS feature.

---
## Configuration

| Setting | Location | Purpose |
|---------|----------|---------|
| SMTP host/user/pass/secure | *Globals → Notifications* | Required for email invites |
| `twilio_sid`, `twilio_token`, `twilio_from` | *Globals → Module Settings* (added by oe-module-faxsms) | Required for SMS invites |

No further settings are needed; the module auto-detects these globals.

---
## Usage Walk-Through

1. **Create or open an Encounter** and generate a telehealth meeting (handled by your VC backend, not included here) which writes the URL to `telehealth_vc`.
2. **Patient Summary** now shows a blue *Start Tele-visit* badge.
3. From **Calendar → Edit Appointment**, providers see:
   * *Start Tele-visit (Provider)* – launches meeting as host.
   * *Start Tele-visit (Patient)* – launches meeting in patient mode (for testing).
   * *Send Invite (Email/SMS)* – one-click patient notification. Status alert shown.
4. **Real-time Waiting Room Notifications**:
   * When a patient joins the waiting room, providers receive a toast notification.
   * Notifications appear in the bottom-right corner of any OpenEMR page.
   * Clicking the notification takes the provider directly to the meeting.
   * This feature requires `telehealth_mode = telesalud` in globals.
5. Invite actions are logged; view with SQL or future reporting.

---
## Packaging / Distribution

This module is self-contained; to distribute:

```bash
zip -r telehealth-module.zip telehealth
```

Recipients unzip into `modules/` and follow *Installation* above.

---
## Extending

* Add alternate SMS gateways by extending `InviteHelper`.
* Replace meeting launch endpoints in `public/start.php` to integrate with Zoom, Jitsi, etc.
* Hook into additional events (eg. Clinical Notes) by adding listeners under `hooks/`.

---
## License

Same license as OpenEMR (GNU GPL v3).
