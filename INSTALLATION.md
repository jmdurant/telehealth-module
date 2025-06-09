# Telehealth Module Installation Guide

## Overview
This guide describes the proper installation process for the OpenEMR Telehealth Module, which now follows OpenEMR best practices with automatic form registration, database setup, and **automatic permission fixes**.

## Quick Start (Recommended)

### Automated Deployment with Permission Fixes
The easiest way to install the module is using the enhanced deployment script:

```powershell
# Standard deployment with automatic permission fixes
.\deploy-telehealth-module.ps1

# Clean installation (removes existing module first)
.\deploy-telehealth-module.ps1 -Force

# Test the installation process
.\test_module_install.ps1
```

**✅ What the deployment script now does automatically:**
- Copies module files to container
- **Fixes file ownership** (`chown -R apache:apache`)
- **Sets proper directory permissions** (`chmod 755`)
- Runs composer install
- Updates container hosts files
- Verifies installation

## Module Structure
```
oe-module-telehealth/
├── src/
│   └── Bootstrap.php              # Main module bootstrap with installation methods
├── forms/                         # Form files (automatically copied during install)
│   └── telehealth_notes/
│       ├── report.php            # Displays forms in encounters
│       ├── new.php               # Creates new form entries
│       ├── view.php              # Views/edits existing forms
│       └── info.txt              # Form registration info
├── sql/
│   ├── install.sql               # Installation SQL with registry registration
│   └── uninstall.sql             # Cleanup SQL for uninstallation
├── api/
│   └── notifications_simple.php  # Standalone webhook endpoint
├── public/
│   └── start.php                 # Telehealth session starter
└── templates/
    └── encounter_forms/
        └── telehealth_notes.php   # Encounter form template
```

## Installation Process

### Automatic Installation (Recommended)
When the module is installed through OpenEMR's module system, it will automatically:

1. **Execute Database Setup** - Creates all necessary tables:
   - `telehealth_vc` - Main telehealth session data
   - `form_telehealth_notes` - Clinical notes forms
   - `telehealth_vc_log` - Webhook logging
   - `telehealth_vc_topic` - Notification topics

2. **Register Forms** - Adds form to OpenEMR's `registry` table:
   - Form name: "Telehealth Visit Notes"
   - Directory: `telehealth_notes`
   - Category: Clinical

3. **Install Form Files** - Copies form files to OpenEMR's forms directory:
   - Source: `module/forms/telehealth_notes/`
   - Destination: `/interface/forms/telehealth_notes/`

4. **Verify Installation** - Checks that all components are properly installed

### Manual Testing
If you want to test the installation process manually:

```bash
# Run the installation test script
php test_install.php
```

This script will:
- Execute the installation process
- Verify all components are installed correctly
- Report any issues found

## Post-Installation Verification

### 1. Check Form Registration
Go to **Administration → Forms** in OpenEMR:
- Look for "Telehealth Visit Notes" in the forms list
- The form should be active and available

### 2. Verify Database Tables
Check that these tables exist:
```sql
SHOW TABLES LIKE 'telehealth_vc';
SHOW TABLES LIKE 'form_telehealth_notes';
SELECT * FROM registry WHERE directory = 'telehealth_notes';
```

### 3. Test Form Files
Check that form files exist at:
```
/var/www/localhost/htdocs/openemr/interface/forms/telehealth_notes/
├── report.php
├── new.php
├── view.php
└── info.txt
```

### 4. Test Webhook Integration
```bash
# Test the webhook endpoint
php test_webhook.php
```

Expected response:
```json
{"success":true,"message":"Processed videoconsultation-finished for appointment X"}
```

## How It Works

### 1. Starting a Telehealth Visit
- Provider clicks "Start Telehealth (Provider)" on appointment
- `public/start.php` creates OpenEMR encounter and telesalud session
- URLs are stored in `telehealth_vc` table
- Provider is redirected to telesalud video interface

### 2. Conducting the Visit
- Video consultation happens via telesalud platform
- Backend tracks provider/patient attendance
- Clinical consultation takes place

### 3. Finishing the Visit
- Provider clicks "Finish consultation" in telesalud interface
- Backend sends webhook to `api/notifications_simple.php`
- Webhook creates encounter form in `form_telehealth_notes`
- Appointment status updated to completed (~)

### 4. Clinical Documentation
- Encounter forms appear in patient encounter history
- Notes are integrated into OpenEMR's clinical documentation
- Forms can be viewed/edited through OpenEMR interface

## Key Features

### ✅ Proper OpenEMR Integration
- Follows OpenEMR module best practices
- Uses standard form registration process
- Integrates with encounter management

### ✅ Automatic Installation
- No manual file copying required
- Database setup handled automatically
- Form registration during installation

### ✅ Clinical Documentation
- Post-visit notes automatically created
- Integrated with OpenEMR encounters
- Proper form display in patient records

### ✅ Robust Webhook Processing
- Standalone webhook (no session dependencies)
- Handles all telesalud notification types
- Complete audit trail and logging

## Troubleshooting

### Permission Issues (SOLVED ✅)
**Error:** `Installation failed: Could not create forms directory: /var/www/localhost/htdocs/openemr/interface/forms/telehealth_notes`

**Solution:** This is now **automatically fixed** by the deployment script! The script runs:
```bash
# These commands are now automatic in deploy-telehealth-module.ps1
docker exec $ContainerName chown -R apache:apache /var/www/localhost/htdocs/openemr/
docker exec $ContainerName chmod 755 /var/www/localhost/htdocs/openemr/interface/forms/
```

**Manual Fix (if needed):**
```powershell
# Run the enhanced deployment script
.\deploy-telehealth-module.ps1

# Or test installation after deployment
.\test_module_install.ps1
```

### Form Not Appearing in Administration → Forms
- ✅ **Fixed:** ModuleManagerListener.php now uses proper OpenEMR SQL directive processing
- Check registry table: `SELECT * FROM registry WHERE directory = 'telehealth_notes'`
- Re-run installation: The module now auto-installs forms during module installation

### Webhook Returns 404 Error
- Verify webhook URL is accessible
- Check OpenEMR module is properly installed
- Verify file exists: `api/notifications_simple.php`

### No Encounter Forms Created
- Check webhook is being called by backend
- Verify appointment data exists in `telehealth_vc` table
- Check webhook logs: `SELECT * FROM telehealth_vc_log`

### Form Files Missing Errors
- Re-run installation to copy form files
- Check file permissions in `/interface/forms/telehealth_notes/`
- Verify OpenEMR has write access to forms directory

## Development Notes

### Adding New Form Fields
1. Update `sql/install.sql` to add database columns
2. Modify form files in `forms/telehealth_notes/`
3. Update webhook to populate new fields
4. Re-run installation to apply changes

### Extending Clinical Notes
The foundation is now in place to build more sophisticated clinical notes:
- Add structured assessment fields
- Include vital signs integration
- Add billing/coding support
- Implement provider signature requirements

This modular approach makes it easy to enhance the clinical documentation capabilities while maintaining proper OpenEMR integration. 