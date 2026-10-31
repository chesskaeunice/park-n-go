CREATE TABLE admin_permissions (
 user_id BIGINT UNSIGNED NOT NULL,
 permission VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 granted_by BIGINT UNSIGNED NULL,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_by BIGINT UNSIGNED NULL,
 revoked_at DATETIME NULL,
 PRIMARY KEY (user_id, permission),
 CONSTRAINT fk_admin_permission_user FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT fk_admin_permission_granted_by FOREIGN KEY (granted_by) REFERENCES users(id),
 CONSTRAINT fk_admin_permission_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id),
 CONSTRAINT chk_admin_permission_name CHECK (permission IN ('manage_pricing','approve_refunds','active_session_exception','manage_permissions'))
) ENGINE=InnoDB;

CREATE TABLE admin_audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 target_type VARCHAR(50) NOT NULL,
 target_id BIGINT UNSIGNED NULL,
 facility_id BIGINT UNSIGNED NULL,
 reason VARCHAR(1000) NULL,
 before_state LONGTEXT NULL,
 after_state LONGTEXT NULL,
 outcome VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 correlation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_admin_audit_time (created_at),
 INDEX idx_admin_audit_actor (actor_id, created_at),
 INDEX idx_admin_audit_target (target_type, target_id),
 INDEX idx_admin_audit_facility (facility_id, created_at),
 INDEX idx_admin_audit_action (action, created_at),
 CONSTRAINT fk_admin_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id),
 CONSTRAINT fk_admin_audit_facility FOREIGN KEY (facility_id) REFERENCES parking_facilities(id),
 CONSTRAINT chk_admin_audit_outcome CHECK (outcome IN ('succeeded','denied','failed')),
 CONSTRAINT chk_admin_audit_before CHECK (before_state IS NULL OR JSON_VALID(before_state)),
 CONSTRAINT chk_admin_audit_after CHECK (after_state IS NULL OR JSON_VALID(after_state))
) ENGINE=InnoDB;

CREATE TRIGGER trg_admin_audit_no_update BEFORE UPDATE ON admin_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin audit events are append-only.';
CREATE TRIGGER trg_admin_audit_no_delete BEFORE DELETE ON admin_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin audit events are append-only.';

ALTER TABLE parking_rates
 ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER effective_from,
 ADD COLUMN change_reason VARCHAR(500) NULL AFTER created_by,
 ADD CONSTRAINT fk_rate_created_by FOREIGN KEY (created_by) REFERENCES users(id);

CREATE TABLE parking_session_exceptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 parking_session_id BIGINT UNSIGNED NOT NULL UNIQUE,
 facility_id BIGINT UNSIGNED NOT NULL,
 category VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 closed_by BIGINT UNSIGNED NOT NULL,
 closed_at DATETIME NOT NULL,
 paid_until_at_close DATETIME NOT NULL,
 chargeable_from DATETIME NOT NULL,
 outstanding_seconds INT UNSIGNED NOT NULL DEFAULT 0,
 outstanding_rate_centavos INT UNSIGNED NULL,
 outstanding_centavos INT UNSIGNED NULL,
 obligation_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_session_exception_facility (facility_id, closed_at),
 INDEX idx_session_exception_obligation (obligation_status, closed_at),
 CONSTRAINT fk_session_exception_session FOREIGN KEY (parking_session_id) REFERENCES parking_sessions(id),
 CONSTRAINT fk_session_exception_facility FOREIGN KEY (facility_id) REFERENCES parking_facilities(id),
 CONSTRAINT fk_session_exception_closed_by FOREIGN KEY (closed_by) REFERENCES users(id),
 CONSTRAINT chk_session_exception_category CHECK (category IN ('data_inconsistency','emergency_closure','kiosk_unavailable')),
 CONSTRAINT chk_session_exception_obligation CHECK (obligation_status IN ('none','unsettled')),
 CONSTRAINT chk_session_exception_outstanding CHECK ((obligation_status = 'none' AND outstanding_seconds = 0) OR (obligation_status = 'unsettled' AND outstanding_seconds > 0))
) ENGINE=InnoDB;

CREATE TABLE refund_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 payment_attempt_id BIGINT UNSIGNED NOT NULL,
 amount_centavos INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'PHP',
 status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'requested',
 request_reason VARCHAR(1000) NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 review_started_by BIGINT UNSIGNED NULL,
 review_started_at DATETIME NULL,
 decided_by BIGINT UNSIGNED NULL,
 decided_at DATETIME NULL,
 decision_reason VARCHAR(1000) NULL,
 provider_refund_id VARCHAR(191) NULL UNIQUE,
 provider_status VARCHAR(60) NULL,
 provider_recorded_by BIGINT UNSIGNED NULL,
 provider_submitted_at DATETIME NULL,
 provider_checked_at DATETIME NULL,
 provider_confirmed_at DATETIME NULL,
 idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_refund_payment_status (payment_attempt_id, status),
 INDEX idx_refund_status (status, created_at),
 INDEX idx_refund_confirmed (provider_confirmed_at),
 CONSTRAINT fk_refund_payment FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id),
 CONSTRAINT fk_refund_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
 CONSTRAINT fk_refund_review_started_by FOREIGN KEY (review_started_by) REFERENCES users(id),
 CONSTRAINT fk_refund_decided_by FOREIGN KEY (decided_by) REFERENCES users(id),
 CONSTRAINT fk_refund_provider_recorded_by FOREIGN KEY (provider_recorded_by) REFERENCES users(id),
 CONSTRAINT chk_refund_status CHECK (status IN ('requested','under_review','approved','rejected','provider_submitted','provider_confirmed','provider_failed','reconciliation')),
 CONSTRAINT chk_refund_amount CHECK (amount_centavos > 0),
 CONSTRAINT chk_refund_currency CHECK (currency = 'PHP'),
 CONSTRAINT chk_refund_confirmed CHECK (status <> 'provider_confirmed' OR (provider_refund_id IS NOT NULL AND provider_confirmed_at IS NOT NULL AND decided_by IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE reconciliation_actions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 case_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payment_attempt_id BIGINT UNSIGNED NULL,
 session_exception_id BIGINT UNSIGNED NULL,
 action VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 note VARCHAR(1000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_reconciliation_case (case_key, created_at),
 INDEX idx_reconciliation_time (action, created_at),
 CONSTRAINT fk_reconciliation_attempt FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id),
 CONSTRAINT fk_reconciliation_exception FOREIGN KEY (session_exception_id) REFERENCES parking_session_exceptions(id),
 CONSTRAINT fk_reconciliation_actor FOREIGN KEY (actor_id) REFERENCES users(id),
 CONSTRAINT chk_reconciliation_action CHECK (action IN ('note','reviewed','recheck'))
) ENGINE=InnoDB;

CREATE TRIGGER trg_reconciliation_no_update BEFORE UPDATE ON reconciliation_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation actions are append-only.';
CREATE TRIGGER trg_reconciliation_no_delete BEFORE DELETE ON reconciliation_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation actions are append-only.';

ALTER TABLE payment_attempts ADD INDEX idx_payment_attempt_verified (local_status, verified_at);
ALTER TABLE reservations ADD INDEX idx_reservation_created (created_at);
ALTER TABLE parking_sessions ADD INDEX idx_session_exit (exit_at);
