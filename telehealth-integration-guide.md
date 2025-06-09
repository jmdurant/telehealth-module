# OpenEMR Module Integration Guide: Telehealth Module

This document details how we successfully integrated the Telehealth Module with OpenEMR's event system to properly display telehealth icons in the calendar view and telehealth buttons in appointment views.

## Problem Statement

Initially, our telehealth module was built using static hooks, which weren't correctly integrating with OpenEMR's event system. This resulted in:

1. Missing video icons in the calendar view
2. Missing telehealth buttons in appointment views
3. Inconsistent behavior compared to working modules like Comlink's telehealth

## Key Insights from Comlink's Working Approach

By studying Comlink's telehealth module implementation, we discovered several critical integration patterns:

1. **Object-Oriented Event Registration**: Comlink uses direct event listener registration through controller objects rather than static hooks.

2. **Bootstrap Initialization**: 
   - Immediate subscription to events during initialization
   - Direct event dispatcher usage without additional container registration

3. **Controller Structure**:
   - Each controller handles its own event subscriptions
   - Controllers directly register listeners with `addListener()` method

## Core Changes Made

### 1. Replaced EventSubscriberInterface with Direct Listeners

**Before:**
```php
// Using Symfony EventSubscriberInterface
class TelehealthEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            CalendarUserGetEventsFilter::EVENT_NAME => 'filterTelehealthEvents',
            // Other event subscriptions
        ];
    }
}
```

**After:**
```php
// Direct event listener registration in TeleHealthCalendarController
public function subscribeToEvents($eventDispatcher): void
{
    // Direct event listener registration - exactly like Comlink does it
    $eventDispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, [$this, 'filterTelehealthEvents']);
    $eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, [$this, 'addCalendarJavascript']);
    $eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, [$this, 'addCalendarStylesheet']);
    $eventDispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, [$this, 'renderAppointmentButtons']);
}
```

### 2. Simplified Bootstrap Initialization

**Before:**
```php
if (!empty($GLOBALS['moduleContainer'])) {
    $kernel = $GLOBALS['kernel'] ?? null;
    $bootstrap = new Bootstrap($eventDispatcher, $kernel);
    $moduleContainer = $GLOBALS['moduleContainer'];
    $moduleContainer->registerInstance($bootstrap);
    $bootstrap->subscribeToEvents();
}
```

**After:**
```php
// Get kernel from globals if available
$kernel = $GLOBALS['kernel'] ?? null;

// Exactly like Comlink - pass event dispatcher directly to Bootstrap
$bootstrap = new Bootstrap($eventDispatcher, $kernel);

// Immediately subscribe to events - just like Comlink does
$bootstrap->subscribeToEvents();
```

### 3. Updated Bootstrap Class to Call Controller Subscriptions

```php
public function subscribeToEvents()
{
    $this->logger->debug("Telehealth Module: Subscribing to events");
    
    if (!$this->eventDispatcher) {
        $this->logger->error("Telehealth Module: Cannot subscribe to events - event dispatcher not available");
        return;
    }
    
    try {
        // Add global settings
        $this->addGlobalSettings();
        
        // Only proceed if telehealth is configured
        if ($this->globalsConfig->isTelehealthConfigured()) {
            $this->logger->debug("Telehealth Module: Configuration OK, registering event listeners");
            
            // Have each controller subscribe to its own events directly
            // This follows Comlink's approach exactly
            $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
            
            // Register menu events
            if (class_exists('\\OpenEMR\\Menu\\MenuEvent')) {
                $this->eventDispatcher->addListener(\OpenEMR\Menu\MenuEvent::MENU_UPDATE, [$this, 'addMenuItems']);
            }
        }
    } catch (\Exception $e) {
        $this->logger->error("Telehealth Module: Error subscribing to events", ['error' => $e->getMessage()]);
    }
}
```

### 4. Added CalendarUtils Class for Time-Range Logic

We implemented a CalendarUtils class following Comlink's pattern for determining when appointments are within the active time range:

```php
public static function isAppointmentDateTimeInSafeRange(\DateTime $dateTime)
{
    $beforeTime = (new \DateTime())->sub(new \DateInterval("PT2H"));
    $afterTime = (new \DateTime())->add(new \DateInterval("PT2H"));
    return $dateTime >= $beforeTime && $dateTime <= $afterTime;
}
```

### 5. Fixed Asset Path Construction

```php
private function getAssetPath()
{
    return $this->getURLPath() . "assets/";
}

public function getURLPath()
{
    // Use qualified_site_addr like Comlink does
    if (!empty($GLOBALS['qualified_site_addr'])) {
        return $GLOBALS['qualified_site_addr'] . self::MODULE_INSTALLATION_PATH . $this->moduleDirectoryName . "/public/";
    }
    
    // Fallback to webroot if needed
    $webroot = $GLOBALS['webroot'] ?? "";
    return $webroot . self::MODULE_INSTALLATION_PATH . $this->moduleDirectoryName . "/public/";
}
```

## JavaScript Integration

A critical part of the solution was correctly integrating JavaScript files. The process involves several key components:

### 1. JavaScript File Registration Through Event Listeners

The key to getting JavaScript to load was properly subscribing to the `ScriptFilterEvent`:

```php
$eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, [$this, 'addCalendarJavascript']);
```

This event is triggered by OpenEMR when it's building the page header with script tags. Our listener method adds our JavaScript file to the list:

```php
public function addCalendarJavascript(ScriptFilterEvent $event): ScriptFilterEvent
{
    $pageName = $event->getPageName();
    $this->logger->debug("TeleHealthCalendarController: addCalendarJavascript called", [
        'pageName' => $pageName,
        'isCalendarPage' => $this->isCalendarPage($pageName)
    ]);
    
    if ($this->isCalendarPage($pageName)) {
        $scripts = $event->getScripts();
        $scriptPath = $this->assetPath . "js/telehealth-calendar.js";
        $scripts[] = $scriptPath;
        $event->setScripts($scripts);
        
        $this->logger->debug("TeleHealthCalendarController: Added javascript", [
            'scriptPath' => $scriptPath
        ]);
    }
    return $event;
}
```

### 2. Precise Asset Path Construction for JavaScript

The JavaScript URLs must be constructed properly with the right base path:

```php
$scriptPath = $this->assetPath . "js/telehealth-calendar.js";
```

This works because `$this->assetPath` is constructed correctly in the controller's constructor using the pattern from Comlink:

```php
$this->assetPath = $assetPath; // Passed from Bootstrap's getAssetPath() method
```

### 3. Selective Loading Based on Page Context

We only load the JavaScript on calendar-related pages by checking the page name:

```php
private function isCalendarPage(string $pageName): bool
{
    return in_array($pageName, ['pnuserapi.php', 'pnadmin.php', 'add_edit_event.php']);
}
```

This ensures our JavaScript doesn't load unnecessarily on other pages.

### 4. JavaScript Functionality for Calendar Icons

The JavaScript file (telehealth-calendar.js) performs several important functions:

1. Adds video camera icons to telehealth appointments in the calendar
2. Handles click events for telehealth appointments
3. Applies special styling to active telehealth sessions

The JavaScript relies on the CSS classes we add in the PHP event handler:

```php
// In filterTelehealthEvents() method
$eventViewClasses = ["event_appointment", "event_telehealth"];
if ($dateTime !== false && CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
    $eventViewClasses[] = "event_telehealth_active";
}
$eventsByDay[$key][$i]['eventViewClass'] = implode(" ", $eventViewClasses);
```

These classes are then used by both CSS and JavaScript to style and enable functionality.

### 5. Dynamic Button Generation

Rather than using JavaScript to create buttons, we use PHP event handlers to output HTML directly:

```php
// In renderAppointmentButtons() method
echo '<button onclick="window.open(\'' . attr($providerUrl) . '\')" class="btn btn-primary mr-2"><i class="fa fa-video-camera mr-1"></i> ' 
    . xlt('Start Telehealth (Provider)') . '</button>';
```

This ensures compatibility with OpenEMR's translation system (xlt) and security functions (attr).

## Positioning and Display of Icons and Buttons

Getting telehealth buttons and icons to appear in the right places required hooking into OpenEMR's specific event points and understanding how the elements are rendered:

### 1. Calendar Icons Placement

The calendar view in OpenEMR uses a specialized event filter for rendering appointment cells:

```php
$eventDispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, [$this, 'filterTelehealthEvents']);
```

Our `filterTelehealthEvents` method modifies the appointment data before it's rendered:

```php
public function filterTelehealthEvents(CalendarUserGetEventsFilter $event): CalendarUserGetEventsFilter
{
    $eventsByDay = $event->getEventsByDays();
    // ... process events ...
    
    foreach ($keys as $key) {
        $eventCount = count($eventsByDay[$key]);
        for ($i = 0; $i < $eventCount; $i++) {
            // ... check if it's a telehealth appointment ...
            
            if (in_array($catRow['pc_constant_id'], ['telehealth_new_patient', 'telehealth_established_patient'])) {
                $eventViewClasses = ["event_appointment", "event_telehealth"];
                
                // ... add more classes based on status ...
                
                $eventsByDay[$key][$i]['eventViewClass'] = implode(" ", $eventViewClasses);
            }
        }
    }
    
    $event->setEventsByDays($eventsByDay);
    return $event;
}
```

The key points:

1. We modify `eventViewClass` which OpenEMR uses for the HTML class attribute on calendar cells
2. The `event_telehealth` class is targeted by our CSS to add the video camera icon
3. The `event_telehealth_active` class changes the icon styling to indicate an active session

The CSS applies the video camera icon using a pseudo-element:

```css
/* In telehealth.css */
.event_telehealth:after {
    content: "\f03d"; /* Font Awesome video camera icon */
    font-family: 'FontAwesome';
    position: absolute;
    top: 2px;
    right: 2px;
}

.event_telehealth_active:after {
    color: #00cc00; /* Green for active sessions */
}
```

### 2. Appointment Button Placement

For the appointment buttons, we hook into a specific render point provided by OpenEMR:

```php
$eventDispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, [$this, 'renderAppointmentButtons']);
```

This event is triggered when rendering the appointment detail view, specifically in the area below patient information. Our handler outputs HTML directly at this exact position:

```php
public function renderAppointmentButtons(AppointmentRenderEvent $event): void
{
    // ... check if telehealth is enabled and it's a telehealth appointment ...
    
    if (CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
        // Build URLs
        $modulePublicPath = $GLOBALS['webroot'] . "/interface/modules/custom_modules/" . $this->moduleDirectory . "/public";
        $providerUrl = $modulePublicPath . "/index.php?action=start&role=provider&eid=" . attr($appt['pc_eid']);
        $patientUrl = $modulePublicPath . "/index.php?action=start&role=patient&eid=" . attr($appt['pc_eid']);

        // Output HTML directly at the event's render point
        echo '<div class="mt-2">';
        echo '<button onclick="window.open(\'' . attr($providerUrl) . '\')" class="btn btn-primary mr-2"><i class="fa fa-video-camera mr-1"></i> ' 
            . xlt('Start Telehealth (Provider)') . '</button>';
        echo '<button onclick="window.open(\'' . attr($patientUrl) . '\')" class="btn btn-primary"><i class="fa fa-video-camera mr-1"></i> ' 
            . xlt('Start Telehealth (Patient)') . '</button>';
        echo '</div>';
    } else {
        // Show "not available" message
        echo "<button class='mt-2 btn btn-secondary' disabled><i class='fa fa-video-camera mr-2'></i>" 
            . xlt("TeleHealth Session Not Available") . "</button>";
        echo "<p class='text-muted'>" . xlt("Session can only be launched 2 hours before or after the appointment time") . "</p>";
    }
}
```

The key aspects that make this work:

1. Using the right event hook (`RENDER_BELOW_PATIENT`) ensures our buttons appear in the correct location
2. Directly outputting HTML at this event point places content inline exactly where OpenEMR expects it
3. Using Bootstrap spacing classes (`mt-2`, `mr-2`) ensures proper margins and spacing
4. Including FontAwesome icons (`fa-video-camera`) maintains UI consistency with OpenEMR
5. Using OpenEMR's translation function (`xlt()`) ensures proper localization
6. Using security functions (`attr()`) prevents XSS vulnerabilities

### 3. CSS Styling for Proper Display

We also needed to ensure our CSS was properly loaded:

```php
$eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, [$this, 'addCalendarStylesheet']);
```

Our handler adds the telehealth.css stylesheet:

```php
public function addCalendarStylesheet(StyleFilterEvent $event): StyleFilterEvent
{
    $pageName = $event->getPageName();
    
    if ($this->isCalendarPage($pageName)) {
        $styles = $event->getStyles();
        $stylePath = $this->assetPath . "css/telehealth.css";
        $styles[] = $stylePath;
        $event->setStyles($styles);
    }
    return $event;
}
```

This CSS file contains the styling needed for both the calendar icons and appointment buttons, ensuring they appear consistently across the application.

## Critical Integration Concepts

1. **Event Timing**: OpenEMR needs event subscriptions to happen during initial bootstrap, before the page rendering starts.

2. **Object Lifetime**: Controller objects must persist for the duration of the request to allow event callbacks to work.

3. **Event Dispatcher Reference**: The event dispatcher must be passed directly to controllers, not retrieved later.

4. **Direct Callbacks**: Event callbacks must be methods on the controller object, not static methods.

5. **Path Construction**: Asset paths must be built using proper constants and GLOBALS context.

## Twig Template Integration

While we didn't have to modify the Twig template structure, we ensured our controllers were correctly wired to render Twig templates in event handlers:

```php
public function renderAppointmentButtons(AppointmentRenderEvent $event): void
{
    // ... logic to determine if buttons should show ...
    
    if (CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
        // Build URLs with proper paths
        $modulePublicPath = $GLOBALS['webroot'] . "/interface/modules/custom_modules/" . $this->moduleDirectory . "/public";
        $providerUrl = $modulePublicPath . "/index.php?action=start&role=provider&eid=" . attr($appt['pc_eid']);
        $patientUrl = $modulePublicPath . "/index.php?action=start&role=patient&eid=" . attr($appt['pc_eid']);

        echo '<div class="mt-2">';
        echo '<button onclick="window.open(\'' . attr($providerUrl) . '\')" class="btn btn-primary mr-2"><i class="fa fa-video-camera mr-1"></i> ' 
            . xlt('Start Telehealth (Provider)') . '</button>';
        echo '<button onclick="window.open(\'' . attr($patientUrl) . '\')" class="btn btn-primary"><i class="fa fa-video-camera mr-1"></i> ' 
            . xlt('Start Telehealth (Patient)') . '</button>';
        echo '</div>';
    }
}
```

## Testing Your Integration

To verify correct integration, check the following:

1. **Calendar Icons**: Telehealth appointments should display video camera icons in calendar view.

2. **Appointment Buttons**: Telehealth buttons should appear in appointment detail view when within the 2-hour window.

3. **Debug Logs**: Confirm that event subscription logs show up during initialization.

4. **CSS/JS Loading**: Verify that telehealth CSS and JavaScript files are loaded on calendar pages.

## Applying This Pattern to Other Modules

When converting other static hooks to use this event-based pattern:

1. Create controller classes for different functional areas (calendar, patient portal, admin, etc.)

2. Implement `subscribeToEvents()` methods in each controller

3. Update Bootstrap to initialize controllers and call their `subscribeToEvents()` methods

4. Ensure proper asset path construction using consistent constants

5. Use direct event listener registration with `addListener()` rather than implementing `EventSubscriberInterface`

## Patient Portal Integration

### Template Override System for Patient Portal Buttons

To integrate telehealth buttons into the patient portal, we use OpenEMR's template override system. This allows our module to inject telehealth functionality without modifying core files.

#### Step 1: Set up Template Override Loader

In your `Bootstrap.php` file, add the template directory to OpenEMR's Twig loader directly:

```php
use Twig\Loader\FilesystemLoader;

// In your Bootstrap class
/**
 * Subscribe to template events to enable template overrides
 */
public function subscribeToTemplateEvents()
{
    $this->logger->debug("Telehealth Module: Adding template overrides manually");
    
    // Instead of listening for an event, we'll manually add our template path to the Twig loaders
    global $twig;
    
    if (isset($twig) && $twig !== $this->twig) {
        $loader = $twig->getLoader();
        if ($loader instanceof FilesystemLoader) {
            $this->logger->debug("Telehealth Module: Adding template path to global Twig loader", [
                'templatePath' => $this->getTemplatePath()
            ]);
            $loader->prependPath($this->getTemplatePath());
        }
    }
}

/**
 * Get path to the templates directory
 * 
 * @return string
 */
public function getTemplatePath()
{
    return \dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
}
```

Make sure to call this method in your constructor:

```php
public function __construct(EventDispatcher $dispatcher = null, ?Kernel $kernel = null)
{
    // ... other initialization code ...
    
    // Add template overrides immediately after initialization
    $this->subscribeToTemplateEvents();
}
```

#### Step 2: Create a Portal Controller

Create a patient portal controller that subscribes to the appropriate events:

```php
class TeleHealthPatientPortalController
{
    // ... properties and constructor ...
    
    /**
     * Subscribe to portal events
     * 
     * @param EventDispatcher $eventDispatcher
     */
    public function subscribeToEvents(EventDispatcher $eventDispatcher): void
    {
        // Register the event listeners
        $eventDispatcher->addListener(AppointmentFilterEvent::EVENT_NAME, [$this, 'filterPatientAppointment']);
        $eventDispatcher->addListener(RenderEvent::EVENT_SECTION_RENDER_POST, [$this, 'renderTeleHealthPatientAssets']);
    }
    
    /**
     * Add telehealth scripts and CSS to the patient portal
     * 
     * @param GenericEvent $event
     */
    public function renderTeleHealthPatientAssets(GenericEvent $event): void
    {
        // Render template with assets
        echo $this->twig->render('telehealth/patient-portal.twig', [
            'assetPath' => $this->assetPath,
            'debug' => $this->config->isDebugModeEnabled()
        ]);
    }
    
    /**
     * Filter patient appointments to add telehealth information
     * 
     * @param AppointmentFilterEvent $event
     */
    public function filterPatientAppointment(AppointmentFilterEvent $event): void
    {
        $dbRecord = $event->getDbRecord();
        $appointment = $event->getAppointment();
        
        // Check if this is a telehealth appointment and add flag
        $appointment['showTelehealth'] = false;
        
        if ($this->config->isTelehealthCategory($dbRecord['pc_catid'])) {
            // Set additional checks for date/time and status
            $appointment['showTelehealth'] = true;
        }
        
        $event->setAppointment($appointment);
    }
}
```

#### Step 3: Create Override Templates

Create an `appointment-item.html.twig` file in your module's `templates/portal/` directory:

```twig
{#
 # This overrides the original template file in core
 # @see /templates/portal/appointment-item.html.twig for original
 #}
<div class="card p-2">
    <div class="card-header">
        <a href="#" onclick="editAppointment({{ appt.mode | attr_js }},{{ appt.pc_eid | attr_js }})" title="{{ appt.etitle | attr }}">
            <i class='float-right fa fa-edit {% if appt.pc_recurrtype > 0 %} text-danger {% else %} text-success {% endif %} bg-light'></i>
        </a>
    </div>
    <div class="body font-weight-bold">
        <p>
            {{ appt.appointmentDate | text }}<br>
            {{ appt.appointmentType | text }}<br>
            {{ appt.provider | text }}<br>
            {{ appt.status | text }}<br />
            {% if appt.showTelehealth %}
            <button class="btn btn-primary btn-telehealth-launch mt-2" data-pc_eid="{{ appt.pc_eid|attr }}">
                <i class="fa fa-video-camera mr-1" aria-hidden="true"></i> {{ "Start Telehealth"|xlt }}
            </button>
            {% endif %}
        </p>
    </div>
</div>
```

Create a `telehealth/patient-portal.twig` to add your JavaScript and CSS:

```twig
{#
 # Patient portal telehealth assets
 #}
<link rel="stylesheet" href="{{ assetPath|attr }}css/telehealth.css?v={{ assetPath|attr }}">
<script src="{{ assetPath|attr }}js/telehealth-patient.js"></script>
{% if debug %}
<script>
    window.telehealth = window.telehealth || {};
    window.telehealth.debug = true;
</script>
{% endif %}
```

#### Step 4: Implement JavaScript for Button Functionality

Create a `telehealth-patient.js` file in your `public/assets/js/` directory to handle button clicks:

```javascript
document.addEventListener('DOMContentLoaded', function() {
    // Listen for telehealth button clicks
    document.querySelectorAll('.btn-telehealth-launch').forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            
            const appointmentId = this.getAttribute('data-pc_eid');
            if (!appointmentId) {
                console.error('No appointment ID found');
                return;
            }
            
            // Launch telehealth session for this appointment
            window.open(`/interface/modules/custom_modules/oe-module-telehealth/public/index.php?appointmentId=${appointmentId}`, 
                'telehealthSession', 
                'width=1200,height=900,resizable=yes,scrollbars=yes');
        });
    });
});
```

By following this template override approach, you can seamlessly integrate telehealth functionality into the patient portal without modifying core OpenEMR files.

## Calendar Video Camera Icons: JavaScript Icon Replacement Implementation

### Problem Statement
Initially, our telehealth module was not displaying video camera icons on calendar appointments, even though CSS classes were being applied correctly and the module was loading properly.

### Root Cause Analysis
1. **Initial CSS Pseudo-Element Approach Failed**: We initially tried using CSS pseudo-elements (`:after`) to overlay video camera icons, but this didn't work reliably with FontAwesome.
2. **Comlink Research Revealed Better Approach**: By examining the working Comlink telehealth module, we discovered they use a completely different approach.

### Solution: JavaScript Icon Replacement (Following Comlink Pattern)

**Key Insight**: Instead of adding icons via CSS, Comlink **replaces** the existing user icon (`fas fa-user`) with a video camera icon (`fa fa-video`) using JavaScript.

#### Implementation Details:

**1. Event Flow:**
```
OpenEMR Calendar Load → CalendarUserGetEventsFilter Event → 
TeleHealthCalendarController.filterTelehealthEvents() → 
Adds CSS classes (event_telehealth, event_telehealth_active) → 
JavaScript loads → telehealth-calendar.js processes icons
```

**2. JavaScript Icon Replacement Logic:**
```javascript
// Find all telehealth appointments
const telehealthEvents = document.querySelectorAll('.event_telehealth');

telehealthEvents.forEach(function(telehealthNode) {
    let linkTitle = telehealthNode.querySelector('.link_title');
    
    // Create video camera icon - following Comlink's exact approach
    var btn = document.createElement("i");
    btn.className = "fa fa-video mr-1 ml-1";
    
    // Status-based styling
    if (telehealthNode.classList.contains('event_telehealth_active')) {
        btn.className = "fa fa-video text-success mr-1 ml-1"; // Green for active
    } else if (telehealthNode.classList.contains('event_telehealth_completed')) {
        btn.className = "fa fa-video text-muted mr-1 ml-1"; // Gray for completed
    } else {
        btn.className = "fa fa-video text-warning mr-1 ml-1"; // Yellow for inactive
    }

    // Find and replace the existing user icon
    let userPictureIcon = linkTitle.querySelector('.fas.fa-user, img');
    if (userPictureIcon) {
        // Copy mouse events (hover effects for patient photos)
        if (userPictureIcon.onmouseover) btn.onmouseover = userPictureIcon.onmouseover;
        if (userPictureIcon.onmouseout) btn.onmouseout = userPictureIcon.onmouseout;
        if (userPictureIcon.title) btn.title = userPictureIcon.title;
        
        // Replace the user icon with video camera icon
        userPictureIcon.parentNode.replaceChild(btn, userPictureIcon);
    }
});
```

**3. Status-Based Icon Colors:**
- 🟢 **Green** (`text-success`): Active telehealth sessions (within 2-hour window)
- 🟡 **Yellow** (`text-warning`): Inactive telehealth sessions (outside time window)
- ⚫ **Gray** (`text-muted`): Completed telehealth sessions

**4. CSS Classes Applied by PHP (Already Working):**
```php
// In TeleHealthCalendarController.filterTelehealthEvents()
$eventViewClasses = ["event_appointment", "event_telehealth"];

if ($appointmentService->isCheckOutStatus($eventsByDay[$key][$i]['apptstatus'])) {
    $eventViewClasses[] = "event_telehealth_completed";
} else if (CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime)) {
    $eventViewClasses[] = "event_telehealth_active";
}

$eventsByDay[$key][$i]['eventViewClass'] = implode(" ", $eventViewClasses);
```

**5. Key Technical Details:**
- Uses `fa fa-video` (not `fas fa-video`) to match Comlink's approach
- Includes `mr-1 ml-1` Bootstrap margin classes for proper spacing
- Preserves original hover effects for patient photo functionality
- Handles both `img` and `fas fa-user` icon types
- Adds condensed styling for small appointments (`event_condensed` class)

### Files Modified for Calendar Icons:
1. **`public/assets/js/telehealth-calendar.js`** - Icon replacement logic
2. **`public/assets/css/telehealth.css`** - Removed failed pseudo-element approach
3. **`src/Controller/TeleHealthCalendarController.php`** - CSS class application (already working)

### Debugging Steps That Led to Solution:
1. **Verified module loading** - Bootstrap logs showed module was initializing
2. **Confirmed CSS loading** - Browser dev tools showed `telehealth.css` present
3. **Checked JavaScript execution** - Console showed "Telehealth calendar initialized" and "Found X telehealth events"
4. **Examined HTML structure** - CSS classes were being applied correctly
5. **Researched Comlink implementation** - Discovered they use JavaScript replacement, not CSS pseudo-elements

## Patient Portal Icons Implementation

### Current Implementation Status: ✅ **COMPLETE**

The patient portal telehealth icons are properly implemented using a **template override approach** rather than JavaScript icon replacement.

### Patient Portal Implementation Details:

**1. Template Override System:**
- **File**: `templates/portal/appointment-item.html.twig`
- **Approach**: Overrides OpenEMR's core appointment template
- **Icon**: Uses `<i class="fa fa-video-camera mr-1">` directly in the template

**2. Icon Display Logic:**
```twig
{% if appt.showTelehealth %}
<button class="btn btn-primary btn-telehealth-launch mt-2" data-pc_eid="{{ appt.pc_eid|attr }}">
    <i class="fa fa-video-camera mr-1" aria-hidden="true"></i> {{ "Start Telehealth"|xlt }}
</button>
{% endif %}
```

**3. Backend Logic (TeleHealthPatientPortalController):**
```php
public function filterPatientAppointment(AppointmentFilterEvent $event): void
{
    $dbRecord = $event->getDbRecord();
    $appointment = $event->getAppointment();
    
    // Check if telehealth category and within time range
    if ($this->config->isTelehealthCategory($dbRecord['pc_catid']) &&
        CalendarUtils::isAppointmentDateTimeInSafeRange($dateTime) &&
        !$apptService->isCheckOutStatus($dbRecord['pc_apptstatus'])) {
        
        $appointment['showTelehealth'] = true;
    }
    
    $event->setAppointment($appointment);
}
```

**4. JavaScript Event Handling:**
```javascript
// In telehealth-patient.js
function init() {
    let launchButtons = document.querySelectorAll(".btn-telehealth-launch");
    for (let i = 0; i < launchButtons.length; i++) {
        launchButtons[i].addEventListener('click', launchDialog);
    }
}
```

### Key Differences: Calendar vs Patient Portal

| Aspect | Calendar Implementation | Patient Portal Implementation |
|--------|------------------------|------------------------------|
| **Approach** | JavaScript icon replacement | Template override |
| **Icon Type** | Replaces user icon with video camera | Shows video camera button |
| **Display** | Icon only (`fa fa-video`) | Full button with icon + text |
| **Styling** | Status-based colors (green/yellow/gray) | Primary button styling |
| **Event Handling** | Complex click handlers for providers | Simple launch dialog |

### Why Different Approaches?

1. **Calendar**: Must work with existing OpenEMR calendar structure that has user icons to replace
2. **Patient Portal**: Has full control over appointment template, so can directly include telehealth buttons

### Patient Portal Icon Features:
- 📹 **Video camera icon** with "Start Telehealth" text
- 🔵 **Primary button styling** (blue)
- ⏰ **Time-based visibility** (only shows within 2-hour window)
- 🚫 **Status filtering** (hidden for completed/pending appointments)
- 🖱️ **Click handling** opens telehealth session in new window

### Files Involved in Patient Portal:
1. **`src/Controller/TeleHealthPatientPortalController.php`** - Backend logic
2. **`templates/portal/appointment-item.html.twig`** - Template override
3. **`public/assets/js/telehealth-patient.js`** - Click handling
4. **`templates/telehealth/patient-portal.twig`** - Asset loading

## UI Modification Patterns for OpenEMR Modules

### Pattern 1: JavaScript Icon Replacement (Calendar)
**Use When**: Need to modify existing UI elements without changing core templates
**Approach**: 
1. Use event listeners to add CSS classes to target elements
2. Use JavaScript to find and replace specific DOM elements
3. Preserve existing functionality (hover effects, click handlers)

### Pattern 2: Template Override (Patient Portal)
**Use When**: Have control over template rendering and can override core templates
**Approach**:
1. Create template files in module's `templates/` directory
2. Use Twig template override system to replace core templates
3. Add conditional logic directly in templates

### Pattern 3: Event-Based HTML Injection (Appointment Buttons)
**Use When**: Need to add content at specific render points
**Approach**:
1. Subscribe to specific render events (e.g., `AppointmentRenderEvent::RENDER_BELOW_PATIENT`)
2. Output HTML directly at event trigger points
3. Use OpenEMR's security and translation functions

### Best Practices for UI Modifications:
1. **Follow existing patterns** - Study working modules like Comlink
2. **Preserve functionality** - Copy event handlers and attributes when replacing elements
3. **Use proper spacing** - Include Bootstrap margin/padding classes (`mr-1`, `ml-1`, `mt-2`)
4. **Status-based styling** - Use semantic colors (success, warning, muted)
5. **Responsive design** - Handle different appointment sizes (`event_condensed`)
6. **Security** - Always use `attr()` and `xlt()` functions
7. **Debugging** - Add console logging to verify JavaScript execution

## Conclusion

The key insight is that OpenEMR's event system works best with object-oriented direct event registration rather than a static subscriber pattern. By following Comlink's approach of direct event registration, we effectively integrated our telehealth module with OpenEMR's calendar and appointment systems.

For UI modifications, the approach depends on the context:
- **Calendar**: JavaScript replacement for existing elements
- **Patient Portal**: Template overrides for full control
- **Appointment Details**: Event-based HTML injection

This pattern should be applied to all module integrations to ensure consistent behavior and reliable event handling. 