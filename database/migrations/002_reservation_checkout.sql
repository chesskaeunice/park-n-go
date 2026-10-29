ALTER TABLE reservations
 ADD COLUMN request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD UNIQUE KEY uq_reservation_request (request_key);

ALTER TABLE reservation_holds
 ADD INDEX idx_hold_expiry (expires_at, released_at, slot_id);
