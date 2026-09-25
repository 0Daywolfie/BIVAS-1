-- BIVAS-1 Migration 002: authentication + hashed access codes
-- Run AFTER 001_schema.sql.
--
-- What this fixes:
--   1. Residents and guards had no way to log in -> adds credential columns.
--   2. access_code was stored in plaintext -> replaced with an HMAC hash.
--   3. Codes could collide -> unique per estate while active. Used/expired
--      codes get their hash set to NULL, and MySQL allows many NULLs in a
--      UNIQUE index, so the 6-digit code space gets recycled safely.
--   4. Adds bearer-token sessions (works for web now, mobile/guard tablet later).
--   5. Adds verify-attempt tracking so a guard device can't brute-force codes.

SET NAMES utf8mb4;

-- ---- Residents log in with phone + password -----------------------------
ALTER TABLE residents
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER email,
  DROP INDEX idx_residents_phone,
  ADD UNIQUE KEY uk_residents_phone (phone);

-- ---- Security staff log in with phone + PIN (fast to type at a gate) ----
ALTER TABLE security_staff
  ADD COLUMN pin_hash VARCHAR(255) NULL AFTER phone,
  DROP INDEX idx_staff_phone,
  ADD UNIQUE KEY uk_staff_phone (phone);

-- ---- Access codes: plaintext out, keyed hash in -------------------------
ALTER TABLE visit_requests
  DROP COLUMN access_code,
  ADD COLUMN access_code_hash CHAR(64) NULL AFTER valid_to,
  ADD UNIQUE KEY uk_visit_estate_code (estate_id, access_code_hash);

-- ---- API tokens (only the SHA-256 of the token is stored) ---------------
CREATE TABLE api_tokens (
  token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  user_type ENUM('resident','staff') NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uk_token_hash (token_hash),
  KEY idx_token_user (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Code verification attempts (rate limiting + audit) -----------------
CREATE TABLE verify_attempts (
  attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_id BIGINT UNSIGNED NOT NULL,
  estate_id BIGINT UNSIGNED NOT NULL,
  success BOOLEAN NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (attempt_id),
  KEY idx_attempt_staff_time (staff_id, attempted_at),
  CONSTRAINT fk_attempt_staff
    FOREIGN KEY (staff_id) REFERENCES security_staff(staff_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
