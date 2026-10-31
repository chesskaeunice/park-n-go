CREATE TABLE IF NOT EXISTS exit_kiosks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    status ENUM('pending', 'active', 'revoked') NOT NULL DEFAULT 'pending',
    credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    created_by BIGINT UNSIGNED NOT NULL,
    activated_by BIGINT UNSIGNED NULL,
    revoked_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    INDEX idx_exit_kiosk_facility_status (facility_id, status),
    CONSTRAINT fk_exit_kiosk_facility FOREIGN KEY (facility_id) REFERENCES parking_facilities(id),
    CONSTRAINT fk_exit_kiosk_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_exit_kiosk_activated_by FOREIGN KEY (activated_by) REFERENCES users(id),
    CONSTRAINT fk_exit_kiosk_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kiosk_activation_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kiosk_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kiosk_activation_expiry (kiosk_id, expires_at, consumed_at),
    CONSTRAINT fk_kiosk_activation_kiosk FOREIGN KEY (kiosk_id) REFERENCES exit_kiosks(id),
    CONSTRAINT fk_kiosk_activation_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
