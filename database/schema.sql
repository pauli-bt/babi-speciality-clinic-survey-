-- Babi Speciality Dental Clinic: Acquisition & Marketing Analytics
-- Schema v1 (MySQL 8.0.16+ / MariaDB 10.5+). Load order: schema.sql -> triggers.sql -> seeders/reference_data.sql
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Access control ----------
CREATE TABLE roles (
  id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL UNIQUE,            -- admin, receptionist, call_operator, social_tracker, marketing
  name VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(200) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,         -- password_hash(PASSWORD_ARGON2ID / BCRYPT); never plaintext
  role_id TINYINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (                  -- rate limiting
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_user_time (username, attempted_at),
  INDEX idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,                -- SHA-256 of the emailed/issued token
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pr_token (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Reference data (admin-configurable) ----------
CREATE TABLE acquisition_sources (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL UNIQUE,
  category ENUM('social_media','search_online','offline_referral','unknown','other') NOT NULL,
  requires_platform TINYINT(1) NOT NULL DEFAULT 0,   -- show platform picker
  allows_other_text TINYINT(1) NOT NULL DEFAULT 0,   -- show free-text field
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE social_platforms (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE services (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE non_conversion_reasons (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL UNIQUE,
  applies_to ENUM('service_usage','booking','both') NOT NULL DEFAULT 'both',
  allows_other_text TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 100
) ENGINE=InnoDB;

CREATE TABLE campaigns (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  platform_id SMALLINT UNSIGNED NULL,          -- NULL for offline campaigns
  campaign_code VARCHAR(60) NULL UNIQUE,
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('draft','active','paused','ended') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
  FOREIGN KEY (platform_id) REFERENCES social_platforms(id)
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Customers ----------
CREATE TABLE customers (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  customer_code VARCHAR(20) NOT NULL UNIQUE,   -- e.g. BDC-000123, generated server-side; shown to staff
  name VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  phone_normalized VARCHAR(20) NULL,           -- digits only, used for duplicate checks (not unique: families share phones)
  status ENUM('active','archived','merged') NOT NULL DEFAULT 'active',
  merged_into_customer_id INT UNSIGNED NULL,

  -- ORIGINAL ACQUISITION (write-once; changes only via audited correction, enforced by trigger)
  original_source_id SMALLINT UNSIGNED NOT NULL,
  original_social_platform_id SMALLINT UNSIGNED NULL,
  original_source_other VARCHAR(150) NULL,
  original_campaign_id INT UNSIGNED NULL,
  first_acquisition_channel ENUM('reception','phone','social_dm') NOT NULL,
  first_acquisition_at DATETIME NOT NULL,

  created_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_c_phone (phone_normalized),
  INDEX idx_c_name (name),
  INDEX idx_c_first_acq (first_acquisition_at),
  INDEX idx_c_source (original_source_id, first_acquisition_at),
  FOREIGN KEY (original_source_id) REFERENCES acquisition_sources(id),
  FOREIGN KEY (original_social_platform_id) REFERENCES social_platforms(id),
  FOREIGN KEY (original_campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (merged_into_customer_id) REFERENCES customers(id),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE customer_source_corrections (     -- the auditable path for changing original acquisition
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  old_source_id SMALLINT UNSIGNED NOT NULL,
  new_source_id SMALLINT UNSIGNED NOT NULL,
  old_social_platform_id SMALLINT UNSIGNED NULL,
  new_social_platform_id SMALLINT UNSIGNED NULL,
  old_campaign_id INT UNSIGNED NULL,
  new_campaign_id INT UNSIGNED NULL,
  reason VARCHAR(300) NOT NULL,
  corrected_by_user_id INT UNSIGNED NOT NULL,
  corrected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_csc_customer (customer_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (old_source_id) REFERENCES acquisition_sources(id),
  FOREIGN KEY (new_source_id) REFERENCES acquisition_sources(id),
  FOREIGN KEY (corrected_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- Interactions ----------
CREATE TABLE interactions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  channel ENUM('reception','phone','social_dm') NOT NULL,   -- set by the server from the interface used; immutable
  interaction_reason ENUM('first_contact','appointment_followup','another_service','campaign_response') NOT NULL,
  is_first_contact TINYINT(1) NOT NULL DEFAULT 0,           -- 1 only for the interaction that created the customer
  interaction_at DATETIME NOT NULL,

  social_platform_id SMALLINT UNSIGNED NULL,                -- platform of THIS interaction (DM platform or ad platform)
  interaction_source_id SMALLINT UNSIGNED NULL,             -- non-social ad source for campaign_response
  campaign_id INT UNSIGNED NULL,                            -- only when staff identified it; never auto-assigned
  service_id SMALLINT UNSIGNED NULL,                        -- service of interest / used / booked

  booking_outcome ENUM('not_applicable','not_booked','requested','confirmed') NOT NULL DEFAULT 'not_applicable',
  service_usage_outcome ENUM('not_applicable','used','not_used','unknown') NOT NULL DEFAULT 'not_applicable',
  non_conversion_reason_id SMALLINT UNSIGNED NULL,
  non_conversion_other_text VARCHAR(300) NULL,
  notes VARCHAR(1000) NULL,                                 -- restricted: not shown to marketing

  record_status ENUM('complete','needs_review') NOT NULL DEFAULT 'complete',
  recorded_by_user_id INT UNSIGNED NOT NULL,
  submission_token CHAR(32) NULL UNIQUE,                    -- idempotency key: blocks double submits
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Reception measures service usage; phone/DM measure booking. Never mix them.
  CONSTRAINT chk_reception_no_booking CHECK (channel <> 'reception' OR booking_outcome = 'not_applicable'),
  CONSTRAINT chk_remote_no_usage CHECK (channel = 'reception' OR service_usage_outcome = 'not_applicable'),
  CONSTRAINT chk_dm_platform CHECK (channel <> 'social_dm' OR social_platform_id IS NOT NULL),

  INDEX idx_i_customer (customer_id, interaction_at),
  INDEX idx_i_time_channel (interaction_at, channel),
  INDEX idx_i_campaign (campaign_id, interaction_at),
  INDEX idx_i_platform (social_platform_id, interaction_at),
  INDEX idx_i_service (service_id),
  INDEX idx_i_reason (interaction_reason, interaction_at),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (social_platform_id) REFERENCES social_platforms(id),
  FOREIGN KEY (interaction_source_id) REFERENCES acquisition_sources(id),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (service_id) REFERENCES services(id),
  FOREIGN KEY (non_conversion_reason_id) REFERENCES non_conversion_reasons(id),
  FOREIGN KEY (recorded_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- Appointments ----------
CREATE TABLE appointments (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  service_id SMALLINT UNSIGNED NOT NULL,
  appointment_at DATETIME NULL,                              -- may be NULL while only 'requested'
  status ENUM('requested','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'requested',
  origin_interaction_id BIGINT UNSIGNED NULL UNIQUE,         -- the interaction that created it (one booking event, counted once)
  created_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_a_customer (customer_id),
  INDEX idx_a_status_time (status, appointment_at),
  INDEX idx_a_created (created_at),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (service_id) REFERENCES services(id),
  FOREIGN KEY (origin_interaction_id) REFERENCES interactions(id),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Extra table 1: many interactions may reference one appointment (e.g. follow-ups, reception visit that fulfils a booking)
CREATE TABLE interaction_appointments (
  interaction_id BIGINT UNSIGNED NOT NULL,
  appointment_id INT UNSIGNED NOT NULL,
  link_type ENUM('booked','followup','fulfilled','reference') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (interaction_id, appointment_id),
  INDEX idx_ia_appt (appointment_id),
  FOREIGN KEY (interaction_id) REFERENCES interactions(id),
  FOREIGN KEY (appointment_id) REFERENCES appointments(id)
) ENGINE=InnoDB;

-- Extra table 2: status history, so requested -> confirmed -> completed is tracked without double counting
CREATE TABLE appointment_events (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  appointment_id INT UNSIGNED NOT NULL,
  from_status ENUM('requested','confirmed','completed','cancelled','no_show') NULL,
  to_status ENUM('requested','confirmed','completed','cancelled','no_show') NOT NULL,
  interaction_id BIGINT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ae_appt (appointment_id, event_at),
  INDEX idx_ae_status_time (to_status, event_at),
  FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  FOREIGN KEY (interaction_id) REFERENCES interactions(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------- Audit ----------
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,                 -- e.g. customer.create, user.role_change, export.csv
  entity_type VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  old_values LONGTEXT NULL,                    -- JSON, sensitive fields redacted
  new_values LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_al_time (created_at),
  INDEX idx_al_user (user_id, created_at),
  INDEX idx_al_entity (entity_type, entity_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
