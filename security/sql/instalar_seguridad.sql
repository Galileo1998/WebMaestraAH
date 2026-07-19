CREATE TABLE IF NOT EXISTS ah_security_scans (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running','finished','failed') NOT NULL DEFAULT 'running',
    mode ENUM('quick','full','clamav') NOT NULL DEFAULT 'quick',
    files_scanned BIGINT NOT NULL DEFAULT 0,
    bytes_scanned BIGINT NOT NULL DEFAULT 0,
    findings_count INT NOT NULL DEFAULT 0,
    critical_count INT NOT NULL DEFAULT 0,
    high_count INT NOT NULL DEFAULT 0,
    medium_count INT NOT NULL DEFAULT 0,
    low_count INT NOT NULL DEFAULT 0,
    errors_count INT NOT NULL DEFAULT 0,
    initiated_by VARCHAR(190) NULL,
    summary_json LONGTEXT NULL,
    INDEX idx_security_scans_date (started_at),
    INDEX idx_security_scans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_security_findings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    scan_id BIGINT NOT NULL,
    path VARCHAR(1000) NOT NULL,
    sha256 CHAR(64) NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    modified_at DATETIME NULL,
    severity ENUM('critical','high','medium','low') NOT NULL,
    rule_code VARCHAR(100) NOT NULL,
    rule_label VARCHAR(255) NOT NULL,
    evidence TEXT NULL,
    status ENUM('open','ignored','quarantined','restored') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_security_findings_scan (scan_id),
    INDEX idx_security_findings_status (status,severity),
    INDEX idx_security_findings_path (path(190)),
    CONSTRAINT fk_security_findings_scan FOREIGN KEY (scan_id) REFERENCES ah_security_scans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_security_quarantine (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    finding_id BIGINT NOT NULL,
    original_path VARCHAR(1000) NOT NULL,
    quarantine_path VARCHAR(1200) NOT NULL,
    sha256 CHAR(64) NULL,
    metadata_json LONGTEXT NULL,
    quarantined_by VARCHAR(190) NULL,
    quarantined_at DATETIME NOT NULL,
    restored_at DATETIME NULL,
    restored_by VARCHAR(190) NULL,
    INDEX idx_security_quarantine_finding (finding_id),
    INDEX idx_security_quarantine_date (quarantined_at),
    CONSTRAINT fk_security_quarantine_finding FOREIGN KEY (finding_id) REFERENCES ah_security_findings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_security_backups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    path VARCHAR(1200) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    status ENUM('creating','ready','failed','deleted','pruned') NOT NULL DEFAULT 'creating',
    created_by VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    notes TEXT NULL,
    INDEX idx_security_backups_date (created_at),
    INDEX idx_security_backups_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_security_actions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_label VARCHAR(190) NULL,
    action VARCHAR(100) NOT NULL,
    target VARCHAR(1200) NULL,
    status VARCHAR(30) NOT NULL,
    details_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_security_actions_date (created_at),
    INDEX idx_security_actions_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_security_baseline (
    path VARCHAR(1000) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    modified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (path(500)),
    INDEX idx_security_baseline_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
