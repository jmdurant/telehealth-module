# Test Module Installation Script
# This script tests if the telehealth module installation process works correctly

param(
    [string]$Project = "official",
    [string]$Environment = "staging"
)

$ContainerName = "$Project-$Environment-openemr-1"
$ContainerModuleDir = "/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-telehealth"

Write-Host "====================================================" -ForegroundColor Cyan
Write-Host "Testing Telehealth Module Installation Process" -ForegroundColor Cyan
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host "Container: $ContainerName" -ForegroundColor Gray
Write-Host ""

# Test 1: Check if module files exist
Write-Host "1. Checking if module files exist..." -ForegroundColor Yellow
$moduleExists = docker exec $ContainerName test -d $ContainerModuleDir
if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✅ Module directory exists" -ForegroundColor Green
} else {
    Write-Host "   ❌ Module directory not found!" -ForegroundColor Red
    exit 1
}

# Test 2: Check forms directory permissions
Write-Host "2. Checking forms directory permissions..." -ForegroundColor Yellow
$formsPerms = docker exec $ContainerName ls -ld /var/www/localhost/htdocs/openemr/interface/forms/
Write-Host "   $formsPerms" -ForegroundColor Gray
if ($formsPerms -like "*apache*apache*") {
    Write-Host "   ✅ Forms directory has correct ownership (apache:apache)" -ForegroundColor Green
} else {
    Write-Host "   ⚠️  Forms directory ownership may be incorrect" -ForegroundColor Yellow
}

# Test 3: Test ModuleManagerListener installation
Write-Host "3. Testing module installation process..." -ForegroundColor Yellow
$testResult = docker exec $ContainerName php $ContainerModuleDir/test_install.php 2>&1

if ($testResult -like "*Installation completed successfully*") {
    Write-Host "   ✅ Module installation test PASSED" -ForegroundColor Green
} elseif ($testResult -like "*Site ID is missing*") {
    Write-Host "   ⚠️  Session error (normal for CLI test) - testing with direct call..." -ForegroundColor Yellow
    
    # Try direct ModuleManagerListener test
    $directTest = docker exec $ContainerName php -r "
        require_once('/var/www/localhost/htdocs/openemr/interface/globals.php');
        require_once('$ContainerModuleDir/ModuleManagerListener.php');
        try {
            `$listener = ModuleManagerListener::initListenerSelf();
            `$result = `$listener->moduleManagerAction('install', 'oe-module-telehealth', 'Success');
            echo 'Result: ' . `$result . PHP_EOL;
        } catch (Exception `$e) {
            echo 'Error: ' . `$e->getMessage() . PHP_EOL;
        }
    " 2>&1
    
    if ($directTest -like "*Success*") {
        Write-Host "   ✅ Direct installation test PASSED" -ForegroundColor Green
    } else {
        Write-Host "   ❌ Installation test FAILED" -ForegroundColor Red
        Write-Host "   Error details: $directTest" -ForegroundColor Red
    }
} else {
    Write-Host "   ❌ Module installation test FAILED" -ForegroundColor Red
    Write-Host "   Error details: $testResult" -ForegroundColor Red
}

# Test 4: Check if forms were created
Write-Host "4. Checking if form files were installed..." -ForegroundColor Yellow
$formFiles = docker exec $ContainerName ls -la /var/www/localhost/htdocs/openemr/interface/forms/telehealth_notes/ 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✅ Form files installed successfully:" -ForegroundColor Green
    Write-Host "   $formFiles" -ForegroundColor Gray
} else {
    Write-Host "   ❌ Form files not found or directory doesn't exist" -ForegroundColor Red
}

# Test 5: Check database tables
Write-Host "5. Checking database setup..." -ForegroundColor Yellow
$dbCheck = docker exec $ContainerName mysql -u openemr -popenemr openemr -e "SHOW TABLES LIKE 'telehealth_%'; SELECT directory FROM registry WHERE directory = 'telehealth_notes';" 2>&1

if ($dbCheck -like "*telehealth_vc*" -and $dbCheck -like "*telehealth_notes*") {
    Write-Host "   ✅ Database tables and form registration found" -ForegroundColor Green
} else {
    Write-Host "   ⚠️  Some database components may be missing" -ForegroundColor Yellow
    Write-Host "   Database check result: $dbCheck" -ForegroundColor Gray
}

Write-Host ""
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host "Installation Test Complete" -ForegroundColor Cyan
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "If all tests passed, you can now:" -ForegroundColor Green
Write-Host "1. Go to OpenEMR → Modules → Manage Modules" -ForegroundColor Yellow
Write-Host "2. Find 'Telehealth Virtual Care' and click Install" -ForegroundColor Yellow
Write-Host "3. The installation should complete without permission errors" -ForegroundColor Yellow 