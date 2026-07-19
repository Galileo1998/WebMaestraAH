CREATE DATABASE IF NOT EXISTS accion_honduras
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE accion_honduras;

CREATE TABLE IF NOT EXISTS magic_moments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country VARCHAR(80) NOT NULL DEFAULT 'Honduras',
    local_partner VARCHAR(150) NOT NULL DEFAULT 'Acción Honduras',
    community VARCHAR(150) NOT NULL,
    capture_date DATE NOT NULL,
    send_date DATE NULL,
    captured_by VARCHAR(180) NOT NULL,
    capturer_contact VARCHAR(180) NULL,

    full_name VARCHAR(200) NOT NULL,
    age TINYINT UNSIGNED NULL,
    participant_number VARCHAR(80) NULL,
    school_grade VARCHAR(120) NULL,
    relationship_type VARCHAR(120) NULL,

    program_model VARCHAR(80) NULL,
    intermediate_result VARCHAR(150) NULL,
    magic_moment_type VARCHAR(120) NULL,

    media1_type VARCHAR(40) NULL,
    media1_path VARCHAR(255) NULL,
    media1_original_name VARCHAR(255) NULL,
    media1_description TEXT NULL,
    media2_type VARCHAR(40) NULL,
    media2_path VARCHAR(255) NULL,
    media2_original_name VARCHAR(255) NULL,
    media2_description TEXT NULL,
    media3_type VARCHAR(40) NULL,
    media3_path VARCHAR(255) NULL,
    media3_original_name VARCHAR(255) NULL,
    media3_description TEXT NULL,

    testimony_feeling TEXT NULL,
    testimony_learning TEXT NULL,
    testimony_application TEXT NULL,
    testimony_change TEXT NULL,

    consent_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Borrador','Finalizado') NOT NULL DEFAULT 'Borrador',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_capture_date (capture_date),
    INDEX idx_participant_number (participant_number),
    INDEX idx_status (status)
) ENGINE=InnoDB;
