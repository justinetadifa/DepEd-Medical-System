CREATE DATABASE IF NOT EXISTS deped_medical_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE deped_medical_v2;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_no VARCHAR(24) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  office VARCHAR(120) NOT NULL,
  contact_no VARCHAR(40) NOT NULL DEFAULT '',
  birth_date DATE NULL,
  sex VARCHAR(30) NOT NULL DEFAULT '',
  position_title VARCHAR(120) NOT NULL DEFAULT '',
  employment_status VARCHAR(40) NOT NULL DEFAULT '',
  active TINYINT NOT NULL DEFAULT 1,
  INDEX employee_name (full_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  role ENUM('personnel','nurse','doctor','ict') NOT NULL,
  employee_id INT UNSIGNED NULL UNIQUE,
  enabled TINYINT NOT NULL DEFAULT 1,
  FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_codes (
  account_id INT UNSIGNED NOT NULL PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  requested_at INT NOT NULL,
  expires_at INT NOT NULL,
  attempts TINYINT NOT NULL DEFAULT 0,
  FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ape_profiles (
  employee_id INT UNSIGNED NOT NULL,
  ape_year SMALLINT NOT NULL,
  profile_received_at DATETIME NULL,
  assessment_date DATE NULL,
  systolic SMALLINT NULL,
  diastolic SMALLINT NULL,
  pulse SMALLINT NULL,
  weight_kg DECIMAL(6,2) NULL,
  height_m DECIMAL(4,2) NULL,
  blood_type VARCHAR(12) NOT NULL DEFAULT '',
  allergies VARCHAR(1000) NOT NULL DEFAULT '',
  conditions_reported VARCHAR(1000) NOT NULL DEFAULT '',
  medications VARCHAR(1000) NOT NULL DEFAULT '',
  family_history VARCHAR(1000) NOT NULL DEFAULT '',
  surgery_hospitalization VARCHAR(1000) NOT NULL DEFAULT '',
  lifestyle VARCHAR(1000) NOT NULL DEFAULT '',
  screening_history VARCHAR(1000) NOT NULL DEFAULT '',
  clinical_assessment VARCHAR(3000) NOT NULL DEFAULT '',
  assessed_at DATETIME NULL,
  assessed_by INT UNSIGNED NULL,
  PRIMARY KEY (employee_id, ape_year),
  FOREIGN KEY (employee_id) REFERENCES employees (id),
  FOREIGN KEY (assessed_by) REFERENCES accounts (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  ape_year SMALLINT NULL,
  report_no VARCHAR(60) NULL,
  report_date DATE NULL,
  laboratory VARCHAR(120) NOT NULL DEFAULT '',
  review_status ENUM('needs_review','reviewed') NOT NULL DEFAULT 'needs_review',
  storage_key VARCHAR(60) NULL,
  source_mime VARCHAR(32) NULL,
  source_name VARCHAR(140) NULL,
  source_hash CHAR(64) NULL,
  extraction_method VARCHAR(24) NOT NULL DEFAULT 'manual',
  extraction_note TEXT NULL,
  source_identity VARCHAR(255) NOT NULL DEFAULT '',
  review_message VARCHAR(600) NOT NULL DEFAULT '',
  submitted_by INT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  legacy_reviewer VARCHAR(120) NULL,
  reviewed_at DATETIME NULL,
  received_by INT UNSIGNED NULL,
  paper_received_at DATETIME NULL,
  edit_version INT NOT NULL DEFAULT 0,
  legacy_id INT UNSIGNED NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees (id),
  FOREIGN KEY (submitted_by) REFERENCES accounts (id),
  FOREIGN KEY (reviewed_by) REFERENCES accounts (id),
  FOREIGN KEY (received_by) REFERENCES accounts (id),
  UNIQUE (employee_id, source_hash),
  INDEX reports_employee_year (employee_id, ape_year),
  INDEX reports_by_status (review_status, report_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS report_results (
  report_id INT UNSIGNED NOT NULL,
  row_order INT UNSIGNED NOT NULL,
  test_name VARCHAR(120) NOT NULL,
  result_value VARCHAR(200) NOT NULL,
  unit VARCHAR(40) NOT NULL DEFAULT '',
  PRIMARY KEY (report_id, row_order),
  FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
  INDEX result_test (test_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  office VARCHAR(120) NOT NULL,
  visit_date DATE NOT NULL,
  age_at_visit SMALLINT NULL,
  sex_at_visit VARCHAR(30) NOT NULL DEFAULT '',
  reason VARCHAR(120) NOT NULL,
  chief_complaint VARCHAR(1000) NOT NULL DEFAULT '',
  subjective_findings VARCHAR(2000) NOT NULL DEFAULT '',
  objective_findings VARCHAR(2000) NOT NULL DEFAULT '',
  case_category VARCHAR(120) NOT NULL DEFAULT '',
  assessment VARCHAR(2000) NOT NULL DEFAULT '',
  plan VARCHAR(2000) NOT NULL DEFAULT '',
  treatment VARCHAR(2000) NOT NULL DEFAULT '',
  prescription VARCHAR(1000) NOT NULL DEFAULT '',
  disposition VARCHAR(80) NOT NULL,
  followup_date DATE NULL,
  share_guidance VARCHAR(1000) NOT NULL DEFAULT '',
  systolic SMALLINT NULL,
  diastolic SMALLINT NULL,
  pulse SMALLINT NULL,
  respiration SMALLINT NULL,
  temperature DECIMAL(5,2) NULL,
  spo2 SMALLINT NULL,
  bmi DECIMAL(5,2) NULL,
  created_by INT UNSIGNED NOT NULL,
  provider_name VARCHAR(120) NOT NULL,
  provider_role VARCHAR(20) NOT NULL,
  license_no VARCHAR(40) NOT NULL DEFAULT '',
  ptr_no VARCHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees (id),
  FOREIGN KEY (created_by) REFERENCES accounts (id),
  INDEX visits_employee_date (employee_id, visit_date),
  INDEX visits_by_date (visit_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS record_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  record_kind VARCHAR(32) NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  event_name VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB;
