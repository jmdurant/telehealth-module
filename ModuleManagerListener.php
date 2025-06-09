<?php

use OpenEMR\Core\AbstractModuleActionListener;

/**
 * Class to be called from Laminas Module Manager for reporting management actions.
 * Handles enable/disable/unregister/install/upgrade actions for the Telehealth module.
 *
 * @package   OpenEMR Modules
 * @link      https://www.open-emr.org
 * @author    (Based on FaxSMS module by Jerry Padgett)
 * @copyright Copyright (c) 2024 James DuRant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class ModuleManagerListener extends AbstractModuleActionListener
{
    /**
     * Required method to return namespace for this module
     * @return string
     */
    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\Telehealth\\';
    }

    private $authUser;

    public function __construct()
    {
        parent::__construct();
        $this->authUser = (int)$this->getSession('authUserID');
    }

    /**
     * Required method to return this class object for Laminas Manager.
     * @return ModuleManagerListener
     */
    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * @param        $methodName
     * @param        $modId
     * @param string $currentActionStatus
     * @return string On method success a $currentAction status should be returned or error string.
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        } else {
            return "Module cleanup method $methodName does not exist.";
        }
    }

    public function enable($modId, $currentActionStatus): mixed
    {
        error_log('[Telehealth enable] Called enable() method for moduleId=' . $modId);
        
        // ✅ SIMPLIFIED: Minimal enable method to avoid segfaults
        // Only do essential database update if functions are available
        if (function_exists('sqlStatement')) {
            try {
                $result = sqlStatement("UPDATE modules SET mod_ui_active = 0 WHERE directory = ? OR directory = ?", ['telehealth', 'oe-module-telehealth']);
                error_log('[Telehealth enable] Updated mod_ui_active successfully');
            } catch (Exception $e) {
                error_log('[Telehealth enable] Database update failed: ' . $e->getMessage());
            }
        } else {
            error_log('[Telehealth enable] sqlStatement function not available');
        }
        
        return 'Success';
    }

    // --- OpenEMR expects this method for module installation lifecycle ---
    public function install($modId, $currentActionStatus): mixed
    {
        error_log('[Telehealth install] Called install() method for moduleId=' . $modId);
        
        try {
            // 1. Execute installation SQL script (handles #IfNotTable directives properly)
            $this->runInstallationSQL();
            
            // 2. Install form files to OpenEMR forms directory
            $this->installFormFiles();
            
            error_log('[Telehealth install] Installation completed successfully');
            return 'Success';
            
        } catch (Exception $e) {
            error_log('[Telehealth install] Installation failed: ' . $e->getMessage());
            return 'Installation failed: ' . $e->getMessage();
        }
    }

    /**
     * Execute the installation SQL script with proper OpenEMR directives
     */
    private function runInstallationSQL()
    {
        error_log('[Telehealth install] Using manual database setup to avoid SQL directive processing issues');
        
        // Use our proven manual database setup method
        // This avoids the array key issues with OpenEMR's SQL directive processing
        $this->setupTelehealthDatabase();
        
        error_log('[Telehealth install] Manual database setup completed successfully');
    }
    
    /**
     * Install form files to the OpenEMR forms directory
     */
    private function installFormFiles()
    {
        $moduleFormsDir = __DIR__ . '/forms/telehealth_notes';
        $openemrFormsDir = $GLOBALS['fileroot'] . '/interface/forms/telehealth_notes';
        
        if (!is_dir($moduleFormsDir)) {
            error_log('[Telehealth install] Module forms directory not found: ' . $moduleFormsDir);
            return; // Not critical if forms don't exist
        }
        
        error_log('[Telehealth install] Installing form files from ' . $moduleFormsDir . ' to ' . $openemrFormsDir);
        
        // Check if target parent directory exists and is writable
        $parentDir = dirname($openemrFormsDir);
        if (!is_dir($parentDir)) {
            throw new Exception('OpenEMR forms parent directory does not exist: ' . $parentDir);
        }
        
        if (!is_writable($parentDir)) {
            throw new Exception('OpenEMR forms parent directory is not writable: ' . $parentDir . ' (Check permissions)');
        }
        
        // Create destination directory using the improved method
        if (!is_dir($openemrFormsDir)) {
            if (!mkdir($openemrFormsDir, 0755, true)) {
                throw new Exception('Could not create forms directory: ' . $openemrFormsDir . ' (Permission denied - check ownership/permissions)');
            }
            error_log('[Telehealth install] Created forms directory: ' . $openemrFormsDir);
        }
        
        // Use improved directory copying method
        $this->copyDirectory($moduleFormsDir, $openemrFormsDir);
        
        error_log('[Telehealth install] Form files installed successfully');
    }
    
    /**
     * Recursively copy directory contents with proper error handling
     */
    private function copyDirectory($src, $dst)
    {
        if (!is_dir($src)) {
            throw new Exception('Source directory does not exist: ' . $src);
        }
        
        // Ensure destination directory exists
        if (!is_dir($dst)) {
            if (!mkdir($dst, 0755, true)) {
                throw new Exception('Could not create destination directory: ' . $dst);
            }
        }
        
        $dir = opendir($src);
        if ($dir === false) {
            throw new Exception('Could not open source directory: ' . $src);
        }
        
        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..') {
                $srcPath = $src . '/' . $file;
                $dstPath = $dst . '/' . $file;
                
                if (is_dir($srcPath)) {
                    // Recursively copy subdirectories
                    $this->copyDirectory($srcPath, $dstPath);
                } else {
                    // Copy file
                    if (!copy($srcPath, $dstPath)) {
                        closedir($dir);
                        throw new Exception('Could not copy file: ' . $file . ' from ' . $srcPath . ' to ' . $dstPath);
                    }
                    
                    // Set proper permissions
                    chmod($dstPath, 0644);
                    error_log('[Telehealth install] Copied form file: ' . $file);
                }
            }
        }
        
        closedir($dir);
    }

    /**
     * Setup the telehealth module database tables and columns
     * This runs ONLY during installation, following Comlink's pattern
     */
    private function setupTelehealthDatabase()
    {
        // Ensure we have database access
        if (!function_exists('sqlQuery') || !function_exists('sqlStatement')) {
            throw new Exception('OpenEMR database functions not available');
        }
        
        // Create base telehealth_vc table if it doesn't exist
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
            error_log('[Telehealth install] Created telehealth_vc table');
        } else {
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
                $columnExists = sqlQuery("SHOW COLUMNS FROM `telehealth_vc` LIKE '$columnName'");
                if (empty($columnExists)) {
                    sqlStatement($alterSql);
                    error_log("[Telehealth install] Added $columnName column");
                }
            }
        }
        
        // Create telehealth_vc_topic table for notification topic mapping
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
            
            // Insert default topic mappings
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
            
            error_log('[Telehealth install] Created telehealth_vc_topic table with default mappings');
        }
        
        // Create telehealth_vc_log table for logging
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
            
            error_log('[Telehealth install] Created telehealth_vc_log table');
        }
        
        // Create form_telehealth_notes table for encounter forms
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
            error_log('[Telehealth install] Created form_telehealth_notes table');
        }
        
        // Register telehealth notes form with OpenEMR
        $registryExists = sqlQuery("SELECT * FROM registry WHERE directory = 'telehealth_notes'");
        if (empty($registryExists)) {
            $sql = "INSERT INTO registry SET 
                name = 'Telehealth Visit Notes',
                directory = 'telehealth_notes',
                sql_run = 1,
                unpackaged = 1,
                state = 1,
                priority = 0,
                category = 'Clinical',
                nickname = 'Telehealth Notes'";
            
            sqlStatement($sql);
            error_log('[Telehealth install] Registered telehealth_notes form in registry');
        }
    }

    private function disable($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on disable
        return $currentActionStatus;
    }

    private function unregister($modId, $currentActionStatus): mixed
    {
        error_log('[Telehealth unregister] Called unregister() method for moduleId=' . $modId);
        
        try {
            // 1. Remove form files from OpenEMR forms directory
            $this->removeFormFiles();
            
            // 2. Execute uninstallation SQL (optional - comment out if you want to preserve data)
            $this->runUninstallationSQL();
            
            error_log('[Telehealth unregister] Uninstallation completed successfully');
            return 'Success';
            
        } catch (Exception $e) {
            error_log('[Telehealth unregister] Uninstallation failed: ' . $e->getMessage());
            return 'Uninstallation failed: ' . $e->getMessage();
        }
    }

    /**
     * Execute the uninstallation SQL script
     */
    private function runUninstallationSQL()
    {
        $sqlFile = __DIR__ . '/sql/uninstall.sql';
        
        if (!file_exists($sqlFile)) {
            error_log('[Telehealth unregister] Uninstallation SQL file not found, skipping SQL cleanup');
            return;
        }
        
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new Exception('Could not read uninstallation SQL file');
        }
        
        error_log('[Telehealth unregister] Executing uninstallation SQL script');
        
        // Split SQL into individual statements and execute each one
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($statements as $statement) {
            if (!empty($statement) && !preg_match('/^#/', $statement)) {
                $result = sqlStatement($statement);
                if ($result === false) {
                    error_log('[Telehealth unregister] Failed to execute SQL statement: ' . $statement);
                }
            }
        }
        
        error_log('[Telehealth unregister] Uninstallation SQL executed successfully');
    }
    
    /**
     * Remove form files from the OpenEMR forms directory
     */
    private function removeFormFiles()
    {
        $openemrFormsDir = $GLOBALS['fileroot'] . '/interface/forms/telehealth_notes';
        
        if (!is_dir($openemrFormsDir)) {
            error_log('[Telehealth unregister] Forms directory doesn\'t exist, nothing to remove');
            return;
        }
        
        error_log('[Telehealth unregister] Removing form files from: ' . $openemrFormsDir);
        
        // Check if directory is writable
        if (!is_writable($openemrFormsDir)) {
            throw new Exception('Forms directory is not writable: ' . $openemrFormsDir . ' (Check permissions)');
        }
        
        // Use improved recursive removal
        $this->removeDirectory($openemrFormsDir);
        
        error_log('[Telehealth unregister] Form files removed successfully');
    }
    
    /**
     * Recursively remove directory and all contents
     */
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        if ($files === false) {
            throw new Exception('Could not scan directory: ' . $dir);
        }
        
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $filePath = $dir . '/' . $file;
                
                if (is_dir($filePath)) {
                    // Recursively remove subdirectories
                    $this->removeDirectory($filePath);
                } else {
                    // Remove file
                    if (!unlink($filePath)) {
                        throw new Exception('Could not remove file: ' . $filePath);
                    }
                    error_log('[Telehealth unregister] Removed form file: ' . $file);
                }
            }
        }
        
        // Remove the directory itself
        if (!rmdir($dir)) {
            throw new Exception('Could not remove directory: ' . $dir);
        }
        
        error_log('[Telehealth unregister] Removed directory: ' . $dir);
    }

    private function install_sql($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on install
        return $currentActionStatus;
    }

    private function upgrade_sql($modId, $currentActionStatus): mixed
    {
        // You can add custom logic here to run on upgrade
        return $currentActionStatus;
    }
}
