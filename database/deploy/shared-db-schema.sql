-- BIVAS-1 deploy schema for a SHARED database (001 + 002 + 003 combined)
-- Differences from the repo: unused tables vehicles / incident_reports are created as
-- bivas_vehicles / bivas_incident_reports, because the host app already has a 'vehicles' table.
-- Run ONCE, as the main cPanel DB user (not the restricted app user). Run shared-db-preflight.sql first.

-- BIVAS-1 Database Schema (MVP)
-- MySQL 8+ or MariaDB 10.5+ (utf8mb4_unicode_ci works on both; 0900_ai_ci is MySQL-only)
-- Notes:
-- 1) In production, store access_code as a HASH, not plaintext.
-- 2) This schema supports both pre-approved visits and walk-ins.
-- 3) Designed for gated estates / controlled facilities.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE estates (
  estate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (estate_id),
  UNIQUE KEY uk_estates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
  unit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  unit_code VARCHAR(50) NOT NULL,   -- e.g., "B12"
  block VARCHAR(50) NULL,
  status ENUM('occupied','vacant','maintenance') NOT NULL DEFAULT 'occupied',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (unit_id),
  UNIQUE KEY uk_units_estate_unitcode (estate_id, unit_code),
  KEY idx_units_estate (estate_id),
  CONSTRAINT fk_units_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE residents (
  resident_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  unit_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  is_primary BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (resident_id),
  KEY idx_residents_unit (unit_id),
  KEY idx_residents_phone (phone),
  CONSTRAINT fk_residents_unit
    FOREIGN KEY (unit_id) REFERENCES units(unit_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_staff (
  staff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  role ENUM('guard','supervisor','admin') NOT NULL DEFAULT 'guard',
  shift VARCHAR(50) NULL, -- e.g., "Night", "Day", "A", "B"
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id),
  KEY idx_staff_estate (estate_id),
  KEY idx_staff_phone (phone),
  CONSTRAINT fk_staff_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visitors (
  visitor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  id_type ENUM('NIN','DriversLicense','Passport','VotersCard','Other') NULL,
  id_number VARCHAR(80) NULL,
  photo_url VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (visitor_id),
  KEY idx_visitors_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visit_requests (
  visit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  created_by_resident_id BIGINT UNSIGNED NOT NULL,

  -- If visitor is already known, store visitor_id.
  -- If inviting a new/unknown visitor, keep visitor_id NULL and store visitor_name/phone.
  visitor_id BIGINT UNSIGNED NULL,
  visitor_name VARCHAR(150) NULL,
  visitor_phone VARCHAR(30) NULL,

  purpose VARCHAR(200) NULL,
  expected_arrival_time DATETIME NULL,
  valid_from DATETIME NOT NULL,
  valid_to DATETIME NOT NULL,

  -- token for OTP/QR; store hashed in real production
  access_code VARCHAR(120) NOT NULL,

  status ENUM('pending','approved','expired','cancelled','used') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (visit_id),

  KEY idx_visit_estate (estate_id),
  KEY idx_visit_unit (unit_id),
  KEY idx_visit_creator (created_by_resident_id),
  KEY idx_visit_visitor (visitor_id),
  KEY idx_visit_status_validto (status, valid_to),

  CONSTRAINT fk_visit_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_visit_unit
    FOREIGN KEY (unit_id) REFERENCES units(unit_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_visit_creator
    FOREIGN KEY (created_by_resident_id) REFERENCES residents(resident_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_visit_visitor
    FOREIGN KEY (visitor_id) REFERENCES visitors(visitor_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gate_entry_logs (
  entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,       -- walk-ins can be NULL
  visitor_id BIGINT UNSIGNED NOT NULL, -- force visitor record even for walk-ins
  staff_id BIGINT UNSIGNED NOT NULL,

  check_in_time DATETIME NOT NULL,
  check_out_time DATETIME NULL,

  vehicle_plate VARCHAR(20) NULL,
  entry_method ENUM('OTP','QR','Manual') NOT NULL DEFAULT 'Manual',
  decision ENUM('allowed','denied') NOT NULL DEFAULT 'allowed',
  notes VARCHAR(500) NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (entry_id),

  KEY idx_entry_estate_time (estate_id, check_in_time),
  KEY idx_entry_visit (visit_id),
  KEY idx_entry_visitor (visitor_id),
  KEY idx_entry_staff (staff_id),
  KEY idx_entry_plate (vehicle_plate),

  CONSTRAINT fk_entry_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_entry_visit
    FOREIGN KEY (visit_id) REFERENCES visit_requests(visit_id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  CONSTRAINT fk_entry_visitor
    FOREIGN KEY (visitor_id) REFERENCES visitors(visitor_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_entry_staff
    FOREIGN KEY (staff_id) REFERENCES security_staff(staff_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Vehicles
-- Note: Polymorphic ownership is simplified: one of resident_id / visitor_id can be set.
CREATE TABLE bivas_vehicles (
  vehicle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resident_id BIGINT UNSIGNED NULL,
  visitor_id BIGINT UNSIGNED NULL,
  plate_number VARCHAR(20) NOT NULL,
  make VARCHAR(60) NULL,
  color VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vehicle_id),
  UNIQUE KEY uk_vehicle_plate (plate_number),
  KEY idx_vehicle_resident (resident_id),
  KEY idx_vehicle_visitor (visitor_id),

  CONSTRAINT fk_vehicle_resident
    FOREIGN KEY (resident_id) REFERENCES residents(resident_id)
    ON DELETE SET NULL ON UPDATE CASCADE,

  CONSTRAINT fk_vehicle_visitor
    FOREIGN KEY (visitor_id) REFERENCES visitors(visitor_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Incident Reports
CREATE TABLE bivas_incident_reports (
  incident_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estate_id BIGINT UNSIGNED NOT NULL,
  reported_by_staff_id BIGINT UNSIGNED NOT NULL,
  related_entry_id BIGINT UNSIGNED NULL,
  category ENUM('trespass','altercation','fraud','property_damage','other') NOT NULL DEFAULT 'other',
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  description TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (incident_id),

  KEY idx_incident_estate_time (estate_id, created_at),
  KEY idx_incident_staff (reported_by_staff_id),
  KEY idx_incident_entry (related_entry_id),

  CONSTRAINT fk_incident_estate
    FOREIGN KEY (estate_id) REFERENCES estates(estate_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_incident_staff
    FOREIGN KEY (reported_by_staff_id) REFERENCES security_staff(staff_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,

  CONSTRAINT fk_incident_entry
    FOREIGN KEY (related_entry_id) REFERENCES gate_entry_logs(entry_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
