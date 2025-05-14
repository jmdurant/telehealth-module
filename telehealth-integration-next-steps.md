# OpenEMR Telehealth Module Integration: Next Steps

## Overview

We have successfully integrated the Telehealth Module with OpenEMR's event system for:
- Calendar view and appointment scheduling
- Patient portal integration

The following hooks still need to be updated to match the established integration patterns:

1. ClinicalNotesHooks.php
2. SummaryHooks.php
3. HeaderHooks.php
4. EncounterHooks.php
5. PortalHooks.php (to be replaced by our new implementation)

## Integration Approach

Based on our successful integration of the calendar and patient portal components, we will apply the same patterns to the remaining hooks:

### Common Patterns to Apply

1. **Controller-Based Architecture**:
   - Create dedicated controller classes for each functional area
   - Move static methods to instance methods
   - Initialize controllers through Bootstrap

2. **Direct Event Registration**:
   - Use `$eventDispatcher->addListener()` directly
   - Avoid class aliases and static registration

3. **Twig Template Integration**:
   - Use Twig templates for all UI components
   - Reference templates with proper paths
   - Pass data through controller methods

4. **Bootstrap Initialization**:
   - Register all controllers through the Bootstrap class
   - Use dependency injection for services and config

5. **Asset Management**:
   - Use proper asset paths for JavaScript and CSS
   - Include through event system rather than direct echo

## Specific Implementation Details

### 1. ClinicalNotesHooks.php

**Current Functionality:**
- Hooks into the form save process
- Ensures date values are saved without time for telehealth forms

**Migration Plan:**
1. Create `TeleHealthClinicalNotesController` class
2. Move functionality to instance methods
3. Register with Bootstrap using:
   ```php
   $this->getClinicalNotesController()->subscribeToEvents($this->eventDispatcher);
   ```
4. Subscribe to FormSaveEvent with:
   ```php
   $eventDispatcher->addListener(\OpenEMR\Events\FormSaveEvent::POST_SAVE, [$this, 'onClinicalNoteSave']);
   ```

### 2. SummaryHooks.php

**Current Functionality:**
- Injects telehealth badges into patient summary cards
- Uses complex class aliasing for compatibility

**Migration Plan:**
1. Create `TeleHealthSummaryController` class
2. Replace class aliasing with direct event references
3. Move badge rendering to a Twig template in `templates/telehealth/summary_badge.twig`
4. Subscribe through Bootstrap with:
   ```php
   $eventDispatcher->addListener(\OpenEMR\Events\Patient\Summary\Card\RenderEvent::EVENT_HANDLE, [$this, 'injectBadge']);
   ```

### 3. HeaderHooks.php

**Current Functionality:**
- Injects waiting room notification scripts for providers
- Uses WordPress-style hook system

**Migration Plan:**
1. Create `TeleHealthHeaderController` class
2. Replace WordPress hooks with Symfony EventDispatcher
3. Move script loading to a proper event listener:
   ```php
   $eventDispatcher->addListener(\OpenEMR\Events\Core\ScriptFilterEvent::EVENT_NAME, [$this, 'addWaitingRoomScript']);
   ```
4. Create a dedicated method for generating configuration:
   ```php
   public function addWaitingRoomScript(ScriptFilterEvent $event)
   {
       // Add script to the list of scripts
       $scripts = $event->getScripts();
       $scripts[] = $this->assetPath . "js/waiting_room.js";
       $event->setScripts($scripts);
       return $event;
   }
   ```

### 4. EncounterHooks.php

**Current Functionality:**
- Adds telehealth menu items to the encounter menu
- Uses class aliasing for compatibility

**Migration Plan:**
1. Create `TeleHealthEncounterController` class
2. Replace class aliasing with direct event references
3. Update menu item paths to match new module structure
4. Subscribe through Bootstrap with:
   ```php
   $eventDispatcher->addListener(\OpenEMR\Events\EncounterMenuEvent::MENU_RENDER, [$this, 'addTelehealthMenu']);
   ```

### 5. PortalHooks.php

**Current Status:**
- Placeholder file with no active hooks

**Migration Plan:**
- This functionality is now handled by our `TeleHealthPatientPortalController`
- Remove this file as it's been superseded

## Implementation Order

Suggested implementation order based on complexity and dependencies:

1. **EncounterHooks** - Simplest to migrate, adds menu items
2. **ClinicalNotesHooks** - Straightforward form processing
3. **HeaderHooks** - Asset management for waiting room
4. **SummaryHooks** - More complex UI integration with badges
5. **PortalHooks** - Remove after confirming all functionality is in `TeleHealthPatientPortalController`

## Bootstrap Updates

The `Bootstrap.php` file will need updates to instantiate and register these new controllers:

```php
/**
 * Subscribe to all events
 */
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
            // Existing controllers
            $this->getCalendarController()->subscribeToEvents($this->eventDispatcher);
            $this->getPatientPortalController()->subscribeToEvents($this->eventDispatcher);
            
            // New controllers to add
            $this->getEncounterController()->subscribeToEvents($this->eventDispatcher);
            $this->getClinicalNotesController()->subscribeToEvents($this->eventDispatcher);
            $this->getHeaderController()->subscribeToEvents($this->eventDispatcher);
            $this->getSummaryController()->subscribeToEvents($this->eventDispatcher);
            
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

## Conclusion

By following the established patterns from our calendar and patient portal integrations, we can successfully migrate the remaining hooks to the new architecture. This will ensure consistent behavior across all integration points and provide a more maintainable module structure.

The migration will complete the transformation from static hooks to a proper object-oriented MVC architecture aligned with OpenEMR's event system. 