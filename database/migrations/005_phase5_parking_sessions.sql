ALTER TABLE reservations
 MODIFY status ENUM('pending','confirmed','cancelled','expired','no_show','completed') NOT NULL DEFAULT 'pending',
 ADD COLUMN no_show_at DATETIME NULL AFTER cancelled_at;

ALTER TABLE parking_rates
 ADD COLUMN overtime_hour_centavos INT UNSIGNED NULL AFTER succeeding_hour_centavos;

ALTER TABLE parking_sessions
 ADD COLUMN purchased_minutes SMALLINT UNSIGNED NULL AFTER entry_at,
 ADD COLUMN paid_until DATETIME NULL AFTER purchased_minutes;

ALTER TABLE qr_tickets
 ADD COLUMN manual_code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER token_hash,
 ADD COLUMN status ENUM('active','revoked','completed') NOT NULL DEFAULT 'active' AFTER manual_code_hash,
 ADD COLUMN last_issued_at DATETIME NULL AFTER created_at,
 ADD UNIQUE KEY uq_ticket_manual_code (manual_code_hash);

CREATE TABLE attendant_facilities (
 attendant_id BIGINT UNSIGNED NOT NULL,
 facility_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (attendant_id, facility_id),
 FOREIGN KEY (attendant_id) REFERENCES users(id),
 FOREIGN KEY (facility_id) REFERENCES parking_facilities(id)
) ENGINE=InnoDB;
