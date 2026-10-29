CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 phone VARCHAR(24) NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('driver','attendant','admin') NOT NULL DEFAULT 'driver',
 status ENUM('active','disabled') NOT NULL DEFAULT 'active',
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE parking_facilities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 address VARCHAR(255) NOT NULL,
 city VARCHAR(100) NOT NULL,
 description TEXT NULL,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_facility_city (city, is_active)
) ENGINE=InnoDB;

CREATE TABLE parking_floors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(50) NOT NULL,
 sort_order SMALLINT NOT NULL DEFAULT 0,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_floor_label (facility_id, label),
 FOREIGN KEY (facility_id) REFERENCES parking_facilities(id)
) ENGINE=InnoDB;

CREATE TABLE parking_slots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 floor_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(24) NOT NULL,
 status ENUM('available','maintenance','disabled') NOT NULL DEFAULT 'available',
 position_x SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 position_y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_slot_label (floor_id, label),
 INDEX idx_slot_status (floor_id, status),
 FOREIGN KEY (floor_id) REFERENCES parking_floors(id)
) ENGINE=InnoDB;

CREATE TABLE parking_rates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 initial_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
 initial_centavos INT UNSIGNED NOT NULL,
 succeeding_hour_centavos INT UNSIGNED NOT NULL,
 grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
 daily_max_centavos INT UNSIGNED NULL,
 additional_centavos INT UNSIGNED NOT NULL DEFAULT 0,
 cancellation_cutoff_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
 effective_from DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_rate_effective (facility_id, effective_from),
 FOREIGN KEY (facility_id) REFERENCES parking_facilities(id),
 CHECK (initial_minutes > 0)
) ENGINE=InnoDB;

CREATE TABLE reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(32) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL,
 slot_id BIGINT UNSIGNED NOT NULL,
 rate_id BIGINT UNSIGNED NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 status ENUM('pending','confirmed','cancelled','expired','completed') NOT NULL DEFAULT 'pending',
 estimated_centavos INT UNSIGNED NOT NULL,
 rate_snapshot JSON NOT NULL,
 confirmed_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_reservation_overlap (slot_id, status, starts_at, ends_at),
 INDEX idx_reservation_user (user_id, created_at),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (slot_id) REFERENCES parking_slots(id),
 FOREIGN KEY (rate_id) REFERENCES parking_rates(id),
 CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE reservation_holds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 slot_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NULL UNIQUE,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 released_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_hold_overlap (slot_id, expires_at, starts_at, ends_at),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (slot_id) REFERENCES parking_slots(id),
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE parking_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 entered_by BIGINT UNSIGNED NOT NULL,
 exited_by BIGINT UNSIGNED NULL,
 entry_at DATETIME NOT NULL,
 exit_at DATETIME NULL,
 status ENUM('active','completed') NOT NULL DEFAULT 'active',
 final_centavos INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_session_status (status, entry_at),
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 FOREIGN KEY (entered_by) REFERENCES users(id),
 FOREIGN KEY (exited_by) REFERENCES users(id),
 CHECK (exit_at IS NULL OR exit_at >= entry_at)
) ENGINE=InnoDB;

CREATE TABLE qr_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (reservation_id) REFERENCES reservations(id)
) ENGINE=InnoDB;

CREATE TABLE qr_scan_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NULL,
 attendant_id BIGINT UNSIGNED NOT NULL,
 direction ENUM('entry','exit') NOT NULL,
 result VARCHAR(40) NOT NULL,
 message VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_scan_time (created_at),
 FOREIGN KEY (ticket_id) REFERENCES qr_tickets(id),
 FOREIGN KEY (attendant_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE payment_transactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(40) NOT NULL,
 provider_reference VARCHAR(191) NULL,
 idempotency_key VARCHAR(100) NOT NULL UNIQUE,
 purpose ENUM('reservation','additional') NOT NULL,
 method VARCHAR(40) NULL,
 amount_centavos INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'PHP',
 status ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
 is_sandbox BOOLEAN NOT NULL DEFAULT TRUE,
 paid_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_payment (provider, provider_reference),
 INDEX idx_payment_reservation (reservation_id, status),
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 CHECK (amount_centavos > 0)
) ENGINE=InnoDB;

CREATE TABLE payment_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_id BIGINT UNSIGNED NULL,
 provider VARCHAR(40) NOT NULL,
 provider_event_id VARCHAR(191) NOT NULL,
 event_type VARCHAR(100) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 processed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_event (provider, provider_event_id),
 FOREIGN KEY (transaction_id) REFERENCES payment_transactions(id)
) ENGINE=InnoDB;

CREATE TABLE receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
 receipt_number VARCHAR(40) NOT NULL UNIQUE,
 amount_centavos INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'PHP',
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (transaction_id) REFERENCES payment_transactions(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(150) NOT NULL,
 message TEXT NOT NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notification_unread (user_id, read_at, created_at),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 subject_type VARCHAR(50) NULL,
 subject_id BIGINT UNSIGNED NULL,
 ip_hash CHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_time (created_at),
 INDEX idx_audit_actor (actor_id, created_at),
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_reset_user (user_id, expires_at),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
 bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 expires_at DATETIME NOT NULL,
 INDEX idx_limit_expiry (expires_at)
) ENGINE=InnoDB;
