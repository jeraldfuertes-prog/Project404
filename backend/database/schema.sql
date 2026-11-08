-- BillGuard / IT 107: Development schema (no personal data or test accounts)
-- Run this file in phpMyAdmin SQL/Import or the MySQL command-line client.
-- MySQL 8.x; use a dedicated database account in the application.
-- Existing BillGuard databases: DO NOT re-import. Apply the relevant migrations once.
CREATE DATABASE IF NOT EXISTS billguard_dev
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE billguard_dev;

CREATE TABLE IF NOT EXISTS users (
    id_number CHAR(9) NOT NULL PRIMARY KEY, -- auto-generated internal ID, xxxx-xxxx
    first_name VARCHAR(30) NOT NULL,
    middle_name VARCHAR(30) NULL,
    family_name VARCHAR(30) NOT NULL,
    name_extension VARCHAR(10) NULL,
    birthdate DATE NULL,
    home_address VARCHAR(180) NULL,
    street VARCHAR(50) NULL,
    barangay VARCHAR(50) NULL,
    city VARCHAR(50) NULL,
    province VARCHAR(50) NULL,
    country VARCHAR(50) NULL,
    zip_code CHAR(4) NULL,
    email VARCHAR(100) NOT NULL,
    username VARCHAR(32) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    account_status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    consented_at DATETIME NOT NULL,
    -- Retained for future utility management, not collected during simplified signup.
    utility_account_ciphertext VARBINARY(512) NULL,
    utility_account_iv VARBINARY(16) NULL,
    utility_account_tag VARBINARY(16) NULL,
    primary_utility_type ENUM('Electricity','Water','Internet') NULL,
    service_start_date DATE NULL,
    monthly_utility_budget DECIMAL(12,2) NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    lockout_until DATETIME NULL,
    last_alerted_tier TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_status (account_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_answers (
    user_id CHAR(9) NOT NULL,
    question_id TINYINT UNSIGNED NOT NULL,
    answer_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, question_id),
    CONSTRAINT fk_answers_user FOREIGN KEY (user_id)
        REFERENCES users(id_number) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS one_time_passwords (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(9) NOT NULL,
    purpose ENUM('register','login','reset') NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id)
        REFERENCES users(id_number) ON DELETE RESTRICT,
    KEY idx_otp_user_purpose (user_id, purpose, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(9) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_user FOREIGN KEY (user_id)
        REFERENCES users(id_number) ON DELETE RESTRICT,
    KEY idx_history_user_created (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    username_typed VARCHAR(255) NULL,
    user_id CHAR(9) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id)
        REFERENCES users(id_number) ON DELETE RESTRICT,
    KEY idx_log_created (created_at),
    KEY idx_log_type_created (event_type, created_at),
    KEY idx_log_ip_created (ip_address, created_at),
    KEY idx_log_username_created (username_typed(80), created_at)
) ENGINE=InnoDB;
-- Application must only INSERT into activity_log; never UPDATE/DELETE rows.

CREATE TABLE IF NOT EXISTS ip_blocks (
    ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
    blocked_until DATETIME NOT NULL,
    blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_ip_blocks_until (blocked_until)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(64) NOT NULL,
    target VARCHAR(190) NOT NULL,
    severity ENUM('LOW','MEDIUM','HIGH') NOT NULL,
    details JSON NULL,
    flagged_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flag_day (pattern, target, flagged_date),
    KEY idx_flags_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS utility_bills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(9) NOT NULL,
    utility_type ENUM('Electricity','Water','Internet','Other') NOT NULL,
    custom_utility_name VARCHAR(80) NULL,
    description VARCHAR(120) NOT NULL,
    billing_month DATE NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bill_user FOREIGN KEY (user_id)
        REFERENCES users(id_number) ON DELETE RESTRICT,
    KEY idx_bills_owner_due (user_id, due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS utility_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid_on DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_bill FOREIGN KEY (bill_id)
        REFERENCES utility_bills(id) ON DELETE RESTRICT,
    KEY idx_payments_bill (bill_id, paid_on)
) ENGINE=InnoDB;
