-- One-time compatibility columns for ReserVibe's direct table repositories.
-- Run this in reservibe_db after the original schema has been imported.

USE reservibe_db;

ALTER TABLE accommodations
    MODIFY COLUMN type VARCHAR(40) NOT NULL;

ALTER TABLE temporary_holds
    ADD COLUMN app_metadata LONGTEXT NULL AFTER created_at;

ALTER TABLE reservations
    ADD COLUMN app_status VARCHAR(40) NULL AFTER reservation_status,
    ADD COLUMN app_metadata LONGTEXT NULL AFTER app_status;

ALTER TABLE payment_proofs
    ADD COLUMN app_status VARCHAR(30) NULL AFTER verification_status,
    ADD COLUMN app_metadata LONGTEXT NULL AFTER app_status;

ALTER TABLE payment_ledger
    MODIFY COLUMN payment_method ENUM('gcash', 'maya', 'cash', 'bank_transfer', 'paypal', 'card') NOT NULL,
    ADD COLUMN app_metadata LONGTEXT NULL AFTER recorded_at;

CREATE TABLE login_attempts (
    key_hash CHAR(64) NOT NULL PRIMARY KEY,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at INT UNSIGNED NOT NULL,
    locked_until INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
