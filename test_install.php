<?php
/**
 * Test Installation Process for Telehealth Module
 * This script tests the installation methods in ModuleManagerListener.php
 */

// Include OpenEMR bootstrap - adjust path as needed
require_once('/var/www/localhost/htdocs/openemr/interface/globals.php');

// Include our module manager listener
require_once(__DIR__ . '/ModuleManagerListener.php');

echo "Testing Telehealth Module Installation Process\n";
echo "===============================================\n\n";

try {
    // Create ModuleManagerListener instance
    $listener = ModuleManagerListener::initListenerSelf();
    
    echo "1. Testing installation process...\n";
    
    // Test installation using the proper OpenEMR module manager method
    $result = $listener->moduleManagerAction('install', 'oe-module-telehealth', 'Success');
    
    if ($result === 'Success') {
        echo "✅ Installation completed successfully!\n\n";
        
        echo "2. Verifying installation...\n";
        
        // Check if form is registered in registry
        $registryCheck = sqlQuery("SELECT * FROM registry WHERE directory = 'telehealth_notes'");
        if ($registryCheck) {
            echo "✅ Form registered in registry table: " . $registryCheck['name'] . "\n";
        } else {
            echo "❌ Form NOT found in registry table\n";
        }
        
        // Check if form table exists
        $tableCheck = sqlQuery("SHOW TABLES LIKE 'form_telehealth_notes'");
        if ($tableCheck) {
            echo "✅ Form table exists: form_telehealth_notes\n";
        } else {
            echo "❌ Form table NOT found\n";
        }
        
        // Check if form files exist
        $formFile = $GLOBALS['fileroot'] . '/interface/forms/telehealth_notes/report.php';
        if (file_exists($formFile)) {
            echo "✅ Form files installed in: " . dirname($formFile) . "\n";
        } else {
            echo "❌ Form files NOT found at: " . dirname($formFile) . "\n";
        }
        
        // Check telehealth tables
        $vcTableCheck = sqlQuery("SHOW TABLES LIKE 'telehealth_vc'");
        if ($vcTableCheck) {
            echo "✅ Telehealth VC table exists\n";
        } else {
            echo "❌ Telehealth VC table NOT found\n";
        }
        
        echo "\n3. Installation verification complete!\n";
        echo "You can now test the form integration by:\n";
        echo "- Going to Administration → Forms in OpenEMR\n";
        echo "- Looking for 'Telehealth Visit Notes' in the forms list\n";
        echo "- Testing the webhook with: php test_webhook.php\n";
        
    } else {
        echo "❌ Installation failed! Result: $result\n";
    }
    
} catch (Exception $e) {
    echo "❌ Installation test failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nDone!\n";
?> 