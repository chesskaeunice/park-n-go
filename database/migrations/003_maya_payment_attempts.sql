CREATE TABLE payment_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(36) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NULL,
 parking_session_id BIGINT UNSIGNED NULL,
 payment_type ENUM('INITIAL_RESERVATION','PARKING_EXTENSION','OVERTIME_SETTLEMENT') NOT NULL,
 amount_centavos INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'PHP',
 maya_checkout_id VARCHAR(191) NULL UNIQUE,
 maya_request_reference VARCHAR(36) NOT NULL UNIQUE,
 local_status ENUM('created','checkout_created','pending_verification','verified','failed','expired','cancelled','reconciliation') NOT NULL DEFAULT 'created',
 provider_status VARCHAR(60) NULL,
 provider_completed_at DATETIME NULL,
 verified_at DATETIME NULL,
 checkout_expires_at DATETIME NULL,
 failure_reason VARCHAR(255) NULL,
 reconciliation_reason VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_payment_attempt_owner (user_id, created_at),
 INDEX idx_payment_attempt_reservation (reservation_id, local_status),
 INDEX idx_payment_attempt_session (parking_session_id, local_status),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 FOREIGN KEY (parking_session_id) REFERENCES parking_sessions(id),
 CHECK (amount_centavos > 0)
) ENGINE=InnoDB;

CREATE TABLE payment_attempt_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 payment_attempt_id BIGINT UNSIGNED NULL,
 provider_event_id VARCHAR(191) NOT NULL,
 provider_status VARCHAR(60) NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_payment_attempt_event (provider_event_id),
 FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id)
) ENGINE=InnoDB;
