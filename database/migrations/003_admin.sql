-- BIVAS-1 Migration 003: estate admins + audit trail
-- Run AFTER 002_auth_and_codes.sql.
--
-- What this adds:
--   1. estate_admins: estate managers who run the admin dashboard. They log in
--      with phone + a real password (not a 6-digit PIN), and their accounts
--      lock for 15 minutes after 5 wrong passwords.
--   2. admin_audit_log: every admin action (who, what, to whom, from where).
--      The most powerful accounts get the most accountability.
--   3. security_staff.lockout_cleared_at: lets an admin clear a guard's
--      wrong-code lockout WITHOUT deleting the failed attempts, which are
--      security evidence. Lockouts only count failures after this time.

SET NAMES utf8mb4;

CREATE TABLE estate_admins (
  admin_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  password_hash VARCHAR(255) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id),
  UNIQUE KEY uk_admins_phone (phone),
  KEY idx_admins_estate (estate_id),
  CONSTRAINT fk_admins_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_audit_log (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,          -- e.g. resident.deactivate, staff.reset_pin
  target_type VARCHAR(30) NULL,         -- resident | staff | invite | entry
  target_id BIGINT UNSIGNED NULL,
  summary VARCHAR(300) NOT NULL,        -- human-readable, e.g. "Deactivated Adaeze Okafor (Unit B12)"
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (audit_id),
  KEY idx_audit_estate_time (estate_id, created_at),
  KEY idx_audit_admin (admin_id),
  CONSTRAINT fk_audit_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_audit_admin
    FOREIGN KEY (admin_id) REFERENCES estate_admins(admin_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE api_tokens
  MODIFY user_type ENUM('resident','staff','admin') NOT NULL;

ALTER TABLE security_staff
  ADD COLUMN lockout_cleared_at DATETIME NULL AFTER is_active;
