<?php

namespace OpenEMR\Modules\Telehealth;

/**
 * Database Migration Utility for Telehealth Module
 * 
 * Handles automatic schema updates and ensures database compatibility
 * across different installations and upgrades.
 * 
 * @package OpenEMR\Modules\Telehealth
 */
class DatabaseMigrations
{
    /**
     * Run all necessary database migrations for the telehealth module
     * This method is idempotent - it can be run multiple times safely
     */
    public static function runMigrations()
    {
        self::createTelehealthVcTable();
        self::createRealtimeNotificationsTable();
        self::addMissingColumns();
        self::updateIndexes();
    }

    /**
     * Create the main telehealth_vc table if it doesn't exist
     */
    private static function createTelehealthVcTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS telehealth_vc (
            id INT AUTO_INCREMENT PRIMARY KEY,
            encounter_id INT UNIQUE,
            meeting_url VARCHAR(255),
            created DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        try {
            sqlStatement($sql);
            error_log("Telehealth: Ensured telehealth_vc table exists");
        } catch (Exception $e) {
            error_log("Telehealth: Error creating telehealth_vc table: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create the real-time notifications table for toast notifications
     */
    private static function createRealtimeNotificationsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS telehealth_realtime_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pc_eid INT NOT NULL,
            pid INT NOT NULL,
            encounter_id INT DEFAULT NULL,
            backend_id VARCHAR(255) DEFAULT NULL,
            topic VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT,
            patient_name VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_read TINYINT(1) DEFAULT 0,
            provider_id INT DEFAULT NULL,
            INDEX idx_pc_eid (pc_eid),
            INDEX idx_created_read (created_at, is_read),
            INDEX idx_provider_unread (provider_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        try {
            sqlStatement($sql);
            error_log("Telehealth: Ensured telehealth_realtime_notifications table exists");
        } catch (Exception $e) {
            error_log("Telehealth: Error creating telehealth_realtime_notifications table: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add any missing columns to existing tables
     */
    private static function addMissingColumns()
    {
        $migrations = [
            'telehealth_vc' => [
                'medic_url' => "VARCHAR(255) NULL COMMENT 'URL for provider/medic to join meeting'",
                'patient_url' => "VARCHAR(255) NULL COMMENT 'URL for patient to join meeting'", 
                'backend_id' => "VARCHAR(255) NULL COMMENT 'Backend videoconsultation ID'",
                'medic_id' => "VARCHAR(255) NULL COMMENT 'Backend medic/provider ID'",
                'finished_at' => "DATETIME NULL COMMENT 'When the consultation was marked as finished'"
            ]
        ];

        foreach ($migrations as $tableName => $columns) {
            $existingColumns = self::getTableColumns($tableName);
            
            foreach ($columns as $columnName => $columnDefinition) {
                if (!in_array($columnName, $existingColumns)) {
                    $sql = "ALTER TABLE $tableName ADD COLUMN $columnName $columnDefinition";
                    try {
                        sqlStatement($sql);
                        error_log("Telehealth: Added column '$columnName' to table '$tableName'");
                    } catch (Exception $e) {
                        error_log("Telehealth: Warning - could not add column '$columnName' to '$tableName': " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Update/create necessary database indexes
     */
    private static function updateIndexes()
    {
        // Ensure encounter_id has a unique index (it should from the CREATE TABLE, but just to be safe)
        try {
            $existingIndexes = self::getTableIndexes('telehealth_vc');
            
            // Check if we have a unique index on encounter_id
            $hasUniqueEncounterId = false;
            foreach ($existingIndexes as $index) {
                if ($index['Column_name'] === 'encounter_id' && $index['Non_unique'] == 0) {
                    $hasUniqueEncounterId = true;
                    break;
                }
            }
            
            if (!$hasUniqueEncounterId) {
                sqlStatement("ALTER TABLE telehealth_vc ADD UNIQUE INDEX idx_encounter_id (encounter_id)");
                error_log("Telehealth: Added unique index on encounter_id");
            }
        } catch (Exception $e) {
            error_log("Telehealth: Warning - could not update indexes: " . $e->getMessage());
        }
    }

    /**
     * Get list of columns for a table
     * 
     * @param string $tableName
     * @return array Column names
     */
    private static function getTableColumns($tableName)
    {
        $columns = [];
        try {
            $result = sqlStatement("DESCRIBE $tableName");
            while ($row = sqlFetchArray($result)) {
                $columns[] = $row['Field'];
            }
        } catch (Exception $e) {
            error_log("Telehealth: Warning - could not describe table '$tableName': " . $e->getMessage());
        }
        return $columns;
    }

    /**
     * Get list of indexes for a table
     * 
     * @param string $tableName  
     * @return array Index information
     */
    private static function getTableIndexes($tableName)
    {
        $indexes = [];
        try {
            $result = sqlStatement("SHOW INDEX FROM $tableName");
            while ($row = sqlFetchArray($result)) {
                $indexes[] = $row;
            }
        } catch (Exception $e) {
            error_log("Telehealth: Warning - could not show indexes for table '$tableName': " . $e->getMessage());
        }
        return $indexes;
    }

    /**
     * Check if the database schema is up to date
     * 
     * @return bool True if schema is current, false if migrations needed
     */
    public static function isSchemaUpToDate()
    {
        $requiredColumns = ['id', 'encounter_id', 'meeting_url', 'medic_url', 'patient_url', 'backend_id', 'medic_id', 'finished_at', 'created'];
        $existingColumns = self::getTableColumns('telehealth_vc');
        
        foreach ($requiredColumns as $column) {
            if (!in_array($column, $existingColumns)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get current schema version info
     * 
     * @return array Schema information
     */
    public static function getSchemaInfo()
    {
        $columns = self::getTableColumns('telehealth_vc');
        $indexes = self::getTableIndexes('telehealth_vc');
        
        return [
            'table_exists' => !empty($columns),
            'columns' => $columns,
            'column_count' => count($columns),
            'indexes' => $indexes,
            'is_up_to_date' => self::isSchemaUpToDate()
        ];
    }
} 