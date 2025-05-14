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

## Conclusion

The key insight is that OpenEMR's event system works best with object-oriented direct event registration rather than a static subscriber pattern. By following Comlink's approach of direct event registration, we effectively integrated our telehealth module with OpenEMR's calendar and appointment systems.

This pattern should be applied to all module integrations to ensure consistent behavior and reliable event handling. 