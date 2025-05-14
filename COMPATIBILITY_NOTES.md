# OpenEMR Telehealth Module Compatibility Notes

This document tracks compatibility issues between the Telehealth module and different OpenEMR versions.

## Missing Classes and Functions

The following OpenEMR classes and functions are referenced by our module but may not be available in all OpenEMR versions:

1. `OpenEMR\Core\ModuleRegistry` - Used in openemr.bootstrap.php for module registration
2. `add_action()` - Used in hooks for registering actions (WordPress-style hooks)
3. `OpenEMR\Events\EncounterMenuEvent` - Used in EncounterHooks.php
4. `OpenEMR\Events\Appointments\AppointmentRenderEvent` - Used in CalendarHooks.php

## Compatibility Strategy

For each missing class or function, we're implementing the following strategies:

1. Check if the class/function exists before using it
2. Provide fallback implementations where possible
3. Skip functionality that depends on unavailable components

## Hooks Implementation

Different OpenEMR versions use different hook systems:

- Modern versions (7.0+): Use event dispatcher system with classes like `AppointmentRenderEvent`
- Older versions: May use a WordPress-style hook system with `add_action()` and `do_action()`

Our module needs to support both systems for maximum compatibility.
