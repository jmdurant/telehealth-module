-- Telehealth module installation SQL
-- Creates table to store videoconference URLs per encounter

CREATE TABLE IF NOT EXISTS telehealth_vc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    encounter_id INT NOT NULL UNIQUE,
    meeting_url VARCHAR(255) NOT NULL,
    created DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
