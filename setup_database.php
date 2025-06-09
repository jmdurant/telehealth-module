<?php
/**
 * Telehealth Module Database Setup Script
 * 
 * Run this script to set up the database tables needed for telehealth functionality
 * This is separated from the bootstrap to avoid segfaults during module loading
 * 
 * Usage: php setup_database.php (from the module directory)
 * Or visit: https://your-openemr-site/interface/modules/custom_modules/oe-module-telehealth/setup_database.php
 */

// Include OpenEMR globals
require_once __DIR__ . "/../../../globals.php";

/**
 * Setup the telehealth module database tables and columns
 */
function setupTelehealthDatabase()
{
    $errors = [];
    
    try {
        echo "<h2>Telehealth Module Database Setup</h2>\n";
        echo "<pre>\n";
        
        // First, create the base telehealth_vc table if it doesn't exist
        echo "Checking telehealth_vc table... ";
        $tableExists = sqlQuery("SHOW TABLES LIKE 'telehealth_vc'");
        if (empty($tableExists)) {
            $sql = "CREATE TABLE IF NOT EXISTS `telehealth_vc` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `pc_eid` INT(11) NULL COMMENT 'Foreign key reference to openemr_postcalendar_events.pc_eid',
                `data_id` VARCHAR(255) NULL COMMENT 'Backend video consultation ID',
                `encounter_id` INT(11) NULL COMMENT 'Foreign key reference to encounter',
                `meeting_url` VARCHAR(255) NULL,
                `backend_id` VARCHAR(255) NULL,
                `medic_url` VARCHAR(255) NULL,
                `patient_url` VARCHAR(255) NULL,
                `medic_id` VARCHAR(255) NULL,
                `medic_secret` VARCHAR(255) NULL COMMENT 'Secret for webhook processing',
                `encounter` INT(11) NULL COMMENT 'Link to OpenEMR encounter',
                `active` TINYINT(1) DEFAULT 1 COMMENT 'Whether consultation is active',
                `evolution` TEXT NULL COMMENT 'Consultation notes/evolution',
                `finished_at` TIMESTAMP NULL COMMENT 'When consultation was finished',
                `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
                PRIMARY KEY (`id`),
                KEY `pc_eid` (`pc_eid`),
                KEY `data_id` (`data_id`),
                KEY `encounter_id` (`encounter_id`),
                KEY `encounter` (`encounter`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            sqlStatement($sql);
            echo "✅ Created telehealth_vc table\n";
        } else {
            echo "✅ Already exists\n";
            
            // Check and add missing columns to existing table
            $columns = [
                'pc_eid' => "ALTER TABLE `telehealth_vc` ADD COLUMN `pc_eid` INT(11) NULL COMMENT 'Foreign key reference to openemr_postcalendar_events.pc_eid' AFTER `id`",
                'data_id' => "ALTER TABLE `telehealth_vc` ADD COLUMN `data_id` VARCHAR(255) NULL COMMENT 'Backend video consultation ID' AFTER `pc_eid`",
                'backend_id' => "ALTER TABLE `telehealth_vc` ADD COLUMN `backend_id` VARCHAR(255) NULL AFTER `meeting_url`",
                'medic_url' => "ALTER TABLE `telehealth_vc` ADD COLUMN `medic_url` VARCHAR(255) NULL AFTER `backend_id`",
                'patient_url' => "ALTER TABLE `telehealth_vc` ADD COLUMN `patient_url` VARCHAR(255) NULL AFTER `medic_url`",
                'medic_id' => "ALTER TABLE `telehealth_vc` ADD COLUMN `medic_id` VARCHAR(255) NULL AFTER `patient_url`",
                'medic_secret' => "ALTER TABLE `telehealth_vc` ADD COLUMN `medic_secret` VARCHAR(255) NULL COMMENT 'Secret for webhook processing' AFTER `medic_id`",
                'encounter' => "ALTER TABLE `telehealth_vc` ADD COLUMN `encounter` INT(11) NULL COMMENT 'Link to OpenEMR encounter' AFTER `medic_secret`",
                'active' => "ALTER TABLE `telehealth_vc` ADD COLUMN `active` TINYINT(1) DEFAULT 1 COMMENT 'Whether consultation is active' AFTER `encounter`",
                'evolution' => "ALTER TABLE `telehealth_vc` ADD COLUMN `evolution` TEXT NULL COMMENT 'Consultation notes/evolution' AFTER `active`",
                'finished_at' => "ALTER TABLE `telehealth_vc` ADD COLUMN `finished_at` TIMESTAMP NULL COMMENT 'When consultation was finished' AFTER `evolution`",
                'created' => "ALTER TABLE `telehealth_vc` ADD COLUMN `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp' AFTER `finished_at`"
            ];
            
            foreach ($columns as $columnName => $alterSql) {
                echo "Checking $columnName column... ";
                $columnExists = sqlQuery("SHOW COLUMNS FROM `telehealth_vc` LIKE '$columnName'");
                if (empty($columnExists)) {
                    sqlStatement($alterSql);
                    echo "✅ Added $columnName column\n";
                } else {
                    echo "✅ Already exists\n";
                }
            }
        }
        
        // Create telehealth_vc_topic table for notification topic mapping (like original)
        echo "Checking telehealth_vc_topic table... ";
        $topicTableExists = sqlQuery("SHOW TABLES LIKE 'telehealth_vc_topic'");
        if (empty($topicTableExists)) {
            $sql = "CREATE TABLE `telehealth_vc_topic` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `topic` varchar(255) NOT NULL,
                `value` varchar(255) NOT NULL,
                `description` text,
                PRIMARY KEY (`id`),
                UNIQUE KEY `topic` (`topic`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            sqlStatement($sql);
            
            // Insert default topic mappings like the original
            $topics = [
                ['medic-set-attendance', 'checkin', 'El médico ingresa a la videoconsulta'],
                ['medic-unset-attendance', 'noshow', 'El médico cierra la pantalla de videoconsulta'],
                ['videoconsultation-started', 'checkin', 'Se da por iniciada la videoconsulta'],
                ['videoconsultation-finished', 'complete', 'El médico presiona el botón Finalizar consulta'],
                ['patient-set-attendance', 'confirmed', 'El paciente anuncia su presencia']
            ];
            
            foreach ($topics as $topic) {
                sqlStatement(
                    "INSERT INTO telehealth_vc_topic (topic, value, description) VALUES (?, ?, ?)",
                    $topic
                );
            }
            
            echo "✅ Created with default mappings\n";
        } else {
            echo "✅ Already exists\n";
        }
        
        // Create telehealth_vc_log table for logging (like original)
        echo "Checking telehealth_vc_log table... ";
        $logTableExists = sqlQuery("SHOW TABLES LIKE 'telehealth_vc_log'");
        if (empty($logTableExists)) {
            $sql = "CREATE TABLE `telehealth_vc_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `data_id` varchar(255) NOT NULL,
                `status` varchar(255) NOT NULL,
                `response` text,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `data_id` (`data_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            sqlStatement($sql);
            
            echo "✅ Created telehealth_vc_log table\n";
        } else {
            echo "✅ Already exists\n";
        }
        
        // Create form_telehealth_notes table for encounter forms
        echo "Checking form_telehealth_notes table... ";
        $formTableExists = sqlQuery("SHOW TABLES LIKE 'form_telehealth_notes'");
        if (empty($formTableExists)) {
            $sql = "CREATE TABLE IF NOT EXISTS form_telehealth_notes (
                id int(11) NOT NULL AUTO_INCREMENT,
                date datetime DEFAULT NULL,
                pid bigint(20) DEFAULT NULL,
                encounter bigint(20) DEFAULT NULL,
                user varchar(255) DEFAULT NULL,
                groupname varchar(255) DEFAULT NULL,
                activity tinyint(4) NOT NULL DEFAULT 1,
                evolution_text text,
                backend_id varchar(255) DEFAULT NULL,
                visit_type varchar(255) DEFAULT 'Telehealth Consultation',
                authorized tinyint(4) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY pid (pid),
                KEY encounter (encounter),
                KEY date (date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            sqlStatement($sql);
            echo "✅ Created form_telehealth_notes table\n";
        } else {
            echo "✅ Already exists\n";
        }
        
        // Register telehealth notes form with OpenEMR
        echo "Checking registry entry... ";
        $registryExists = sqlQuery("SELECT * FROM registry WHERE directory = 'telehealth_notes'");
        if (empty($registryExists)) {
            $sql = "INSERT INTO registry SET 
                directory = 'telehealth_notes',
                sql_run = 1,
                unpackaged = 1,
                state = 1,
                priority = 0,
                category = 'Clinical',
                nickname = 'Telehealth Notes'";
            
            sqlStatement($sql);
            echo "✅ Registered telehealth_notes form in registry\n";
        } else {
            echo "✅ Already exists\n";
        }
        
        echo "\n✅ Database setup completed successfully!\n";
        echo "</pre>\n";
        
        return true;
        
    } catch (Exception $e) {
        $errors[] = "Database setup error: " . $e->getMessage();
        echo "❌ Error: " . $e->getMessage() . "\n";
        echo "</pre>\n";
        return false;
    }
}

// Check if this is being run from command line or web
if (php_sapi_name() === 'cli') {
    // Command line execution
    echo "Telehealth Module Database Setup\n";
    echo "================================\n\n";
    
    if (setupTelehealthDatabase()) {
        echo "\nSetup completed successfully!\n";
        exit(0);
    } else {
        echo "\nSetup failed!\n";
        exit(1);
    }
} else {
    // Web execution
    echo "<!DOCTYPE html>\n";
    echo "<html><head><title>Telehealth Database Setup</title></head><body>\n";
    
    if (setupTelehealthDatabase()) {
        echo "<p><strong>✅ Setup completed successfully!</strong></p>\n";
        echo "<p>You can now use the telehealth module.</p>\n";
    } else {
        echo "<p><strong>❌ Setup failed!</strong></p>\n";
        echo "<p>Please check the errors above and try again.</p>\n";
    }
    
    echo "</body></html>\n";
}
?> 