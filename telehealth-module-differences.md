# Telehealth Module Implementation Differences

This document outlines key differences between Comlink's telehealth module (which works) and our implementation (which doesn't fully work).

## 1. Bootstrap Initialization

### Comlink Approach
```php
// From oe-module-comlink-telehealth/openemr.bootstrap.php
$bootstrap = new Bootstrap($eventDispatcher, $GLOBALS['kernel']);
$bootstrap->subscribeToEvents();
```

- Simple, direct initialization
- Immediate event subscription
- No additional checks or container registration

### Our Approach
```php
// From openemr.bootstrap.php
if (!empty($GLOBALS['moduleContainer'])) {
    // Get kernel from globals if available
    $kernel = $GLOBALS['kernel'] ?? null;
    
    // Exactly like Comlink - pass event dispatcher directly to Bootstrap
    $bootstrap = new Bootstrap($eventDispatcher, $kernel);
    
    // Register with module container
    $moduleContainer = $GLOBALS['moduleContainer'];
    $moduleContainer->registerInstance($bootstrap);
    
    // Immediately subscribe to events - just like Comlink does
    $bootstrap->subscribeToEvents();
    
    // Add debug log
    error_log("Telehealth Module: Bootstrap initialized and events subscribed");
}
```

- Extra container registration which might not be needed
- Additional checks that could prevent initialization
- More complex approach

## 2. Event Registration Approach

### Comlink Approach
```php
// From oe-module-comlink-telehealth/src/Controller/TeleHealthCalendarController.php
public function subscribeToEvents(EventDispatcher $eventDispatcher)
{
    $eventDispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, [$this, 'filterTelehealthCalendarEvents']);
    $eventDispatcher->addListener(ScriptFilterEvent::EVENT_NAME, [$this, 'addCalendarJavascript']);
    $eventDispatcher->addListener(StyleFilterEvent::EVENT_NAME, [$this, 'addCalendarStylesheet']);
    // Additional event listeners...
}
```

- Uses direct event listener registration with `addListener()`
- Each controller registers its own events directly
- No EventSubscriberInterface or intermediate event subscriber classes
- Explicit event registration during bootstrap

### Our Approach
```php
// From src/Events/TelehealthEventSubscriber.php
class TelehealthEventSubscriber implements EventSubscriberInterface
{
    // ...
    public static function getSubscribedEvents(): array
    {
        return [
            CalendarUserGetEventsFilter::EVENT_NAME => ['filterTelehealthEvents', 10],
            ScriptFilterEvent::EVENT_NAME => ['addCalendarJavascript', 10],
            StyleFilterEvent::EVENT_NAME => ['addCalendarStylesheet', 10],
            AppointmentRenderEvent::RENDER_BELOW_PATIENT => ['renderAppointmentButtons', 10],
        ];
    }
    // ...
}
```

- Uses Symfony's EventSubscriberInterface pattern
- Relies on OpenEMR to discover and register the subscriber class
- Adds an extra layer of indirection
- May not be fully compatible with OpenEMR's event system

## 3. Event Subscription Timing and Flow

### Comlink Approach
```php
// From oe-module-comlink-telehealth/src/Bootstrap.php
public function subscribeToEvents()
{
    $this->addGlobalSettings();
    if ($this->globalsConfig->isTelehealthConfigured()) {
        $this->subscribeToTemplateEvents();
        $this->subscribeToProviderEvents();
        $this->getTeleHealthUserAdminController()->subscribeToEvents($this->eventDispatcher);
        $this->getTeleHealthPatientAdminController()->subscribeToEvents($this->eventDispatcher);
        $this->getPatientPortalController()->subscribeToEvents($this->eventDispatcher);
        $this->getRegistrationController()->subscribeToEvents($this->eventDispatcher);
        $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
    }
}
```

- Each controller registers its own events directly
- Direct method calls to each controller's subscribeToEvents method
- Controllers directly call addListener() on the event dispatcher
- Clear initialization order and explicit dependencies

### Our Approach
```php
// From src/Bootstrap.php
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
            $this->logger->debug("Telehealth Module: Configuration OK, registering event subscriber");
            
            // Create and register the event subscriber
            $subscriber = new Events\TelehealthEventSubscriber(
                $this->getAppointmentService(),
                $this->getCalendarController()
            );
            $this->eventDispatcher->addSubscriber($subscriber);
            
            // Register menu events
            if (class_exists('\\OpenEMR\\Menu\\MenuEvent')) {
                $this->eventDispatcher->addListener(\OpenEMR\Menu\MenuEvent::MENU_UPDATE, [$this, 'addMenuItems']);
            }
        } else {
            $this->logger->debug("Telehealth Module: Not fully configured, skipping event registration");
        }
    } catch (\Exception $e) {
        $this->logger->error("Telehealth Module: Error subscribing to events", ['error' => $e->getMessage()]);
    }
}
```

- Uses a single centralized EventSubscriber for multiple events
- More defensive programming with extra checks
- Relies on the EventSubscriberInterface pattern which may not be working correctly with OpenEMR

## 4. Path Construction for Assets

### Comlink Approach
```php
// From oe-module-comlink-telehealth/public/assets/js/src/telehealth.js
let moduleLocation = comlink.settings.modulePath || '/interface/modules/custom_modules/oe-module-comlink-telehealth/';
```

```php
// From oe-module-comlink-telehealth/src/Bootstrap.php
private function getPublicPathFQDN()
{
    // return the public path with the fully qualified domain name in it
    // qualified_site_addr already has the webroot in it.
    return $GLOBALS['qualified_site_addr'] . self::MODULE_INSTALLATION_PATH . ($this->moduleDirectoryName ?? '') . '/' . 'public' . '/';
}
```

- Simple, consistent path construction
- Fallback default path hardcoded

### Our Approach
```php
// From src/Bootstrap.php
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

- Similar approach but with more conditionals
- More complex path handling logic

## 5. Namespace Differences

### Comlink Approach
```php
namespace Comlink\OpenEMR\Modules\TeleHealthModule;
```

### Our Approach
```php
namespace OpenEMR\Modules\Telehealth;
```

- Different namespace structure
- May affect how OpenEMR discovers and loads the module

## 6. Module Constants and Configuration

### Comlink Approach
```php
// From oe-module-comlink-telehealth/src/Bootstrap.php
const OPENEMR_GLOBALS_LOCATION = "../../../../globals.php";
const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
const MODULE_NAME = "";
const MODULE_MENU_NAME = "TeleHealth";
```

### Our Approach
```php
// From src/Bootstrap.php
const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
const MODULE_NAME = "Telehealth Virtual Care";
const MODULE_VERSION = 'v0.0.1';
```

- Different constants and naming

## 7. JavaScript Integration

### Comlink Approach
- Uses a global namespace for JavaScript integration: `window.comlink`
- Specific initialization patterns for telehealth features in JavaScript

### Our Approach
- Less clear JavaScript integration strategy
- May not properly hook into OpenEMR's JavaScript environment

## Suggested Fixes

1. **Change Event Registration Strategy**:
   - **CRITICAL**: Remove EventSubscriberInterface approach
   - Switch to direct event listener registration as Comlink does
   - Let each controller register its own events directly with addListener()

2. **Simplify Bootstrap Initialization**:
   - Match Comlink's direct bootstrap approach
   - Remove unnecessary container registration if not needed

3. **Fix Asset Path Construction**:
   - Ensure consistent path building that matches Comlink's approach

4. **Module Naming and Discovery**:
   - Verify module name and path match what OpenEMR expects
   - Check namespace compatibility

5. **JavaScript Integration**:
   - Review JavaScript integration to ensure proper script loading

6. **Debugging**:
   - Add additional logging at all critical points
   - Verify event registration and callback execution 