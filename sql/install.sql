-- Telehealth module installation SQL
-- Creates table to store videoconference URLs per encounter

#IfNotTable telehealth_vc
CREATE TABLE IF NOT EXISTS `telehealth_vc` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `pc_eid` INT UNSIGNED NOT NULL COMMENT 'Calendar event ID',
    `data_id` VARCHAR(255) NULL COMMENT 'Backend video consultation ID',
    `encounter_id` INT NULL,
    `meeting_url` TEXT NULL,
    `medic_url` TEXT NULL,
    `patient_url` TEXT NULL,
    `medic_id` VARCHAR(255) NULL COMMENT 'Backend medic identifier',
    `medic_secret` VARCHAR(255) NULL COMMENT 'Backend medic secret for webhook processing',
    `encounter` INT NULL COMMENT 'OpenEMR encounter ID',
    `active` TINYINT(1) DEFAULT 1,
    `evolution` TEXT NULL,
    `finished_at` DATETIME NULL,
    `backend_id` VARCHAR(255) NULL COMMENT 'Backend video consultation ID',
    `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_pc_eid` (`pc_eid`),
    INDEX `idx_backend_id` (`backend_id`),
    INDEX `idx_data_id` (`data_id`)
) ENGINE = InnoDB;
#EndIf

#IfNotTable telehealth_vc_topic
CREATE TABLE IF NOT EXISTS `telehealth_vc_topic` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `topic` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_topic` (`topic`)
) ENGINE = InnoDB;
#EndIf

#IfNotTable telehealth_vc_log
CREATE TABLE IF NOT EXISTS `telehealth_vc_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `data_id` VARCHAR(255) NULL,
    `status` VARCHAR(100) NULL,
    `response` TEXT NULL,
    `created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_data_id` (`data_id`)
) ENGINE = InnoDB;
#EndIf

#IfNotTable form_telehealth_notes
CREATE TABLE IF NOT EXISTS `form_telehealth_notes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `pid` INT NOT NULL COMMENT 'Patient ID',
    `encounter` INT NOT NULL COMMENT 'Encounter ID',
    `user` VARCHAR(255) NOT NULL DEFAULT 'telehealth-system',
    `groupname` VARCHAR(255) NOT NULL DEFAULT 'Default',
    `activity` TINYINT(1) NOT NULL DEFAULT 1,
    `evolution_text` TEXT NULL COMMENT 'Clinical notes from telehealth visit',
    `backend_id` VARCHAR(255) NULL COMMENT 'Backend consultation ID',
    `visit_type` VARCHAR(255) NULL DEFAULT 'Telehealth Consultation',
    `authorized` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    INDEX `idx_encounter` (`encounter`),
    INDEX `idx_pid` (`pid`),
    INDEX `idx_backend_id` (`backend_id`)
) ENGINE = InnoDB;
#EndIf

#IfNotRow registry directory telehealth_notes
INSERT INTO `registry` (
    `name`, 
    `state`, 
    `sql_run`, 
    `date`, 
    `directory`, 
    `nickname`, 
    `category`, 
    `priority`, 
    `menu_name`
) VALUES (
    'Telehealth Visit Notes', 
    1, 
    1, 
    NOW(), 
    'telehealth_notes', 
    'TelehealthNotes', 
    'Clinical', 
    1, 
    'Telehealth Notes'
);
#EndIf

# Insert initial notification topics
#IfNotRow telehealth_vc_topic topic videoconsultation-started
INSERT INTO `telehealth_vc_topic` (`topic`, `description`) VALUES 
('videoconsultation-started', 'Video consultation session has started'),
('videoconsultation-finished', 'Video consultation session has finished'),
('medic-set-attendance', 'Provider has joined the consultation'),
('medic-unset-attendance', 'Provider has left the consultation'),
('patient-set-attendance', 'Patient has joined the consultation');
#EndIf
