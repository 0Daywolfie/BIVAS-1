-- BIVAS-1 Database Schema (MVP)
-- MySQL 8+ recommended
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Optional: Vehicles
-- Note: Polymorphic ownership is simplified: one of resident_id / visitor_id can be set.
CREATE TABLE vehicles (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Optional: Incident Reports
CREATE TABLE incident_reports (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;