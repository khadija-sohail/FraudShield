CREATE DATABASE IF NOT EXISTS fraudshield
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE fraudshield;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notes;
DROP TABLE IF EXISTS recoveries;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS fraud_cases;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS analysts;
DROP TABLE IF EXISTS login_activity;
DROP TABLE IF EXISTS fraud_rules;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id INT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(200) NOT NULL UNIQUE,
  password_hash CHAR(64) NOT NULL,
  phone VARCHAR(50) NULL,
  role ENUM('customer','analyst','admin') NOT NULL,
  status ENUM('active','suspended') NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_users_role (role),
  INDEX idx_users_status (status),
  INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE analysts (
  analyst_id INT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  department VARCHAR(80) NOT NULL,
  productivity_score DECIMAL(4,2) NOT NULL,
  CONSTRAINT fk_analysts_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_analysts_department (department)
) ENGINE=InnoDB;

CREATE TABLE accounts (
  account_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  account_number VARCHAR(34) NOT NULL UNIQUE,
  balance DECIMAL(12,2) NOT NULL,
  account_type ENUM('Current','Savings','Joint') NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_accounts_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_accounts_user_id (user_id),
  INDEX idx_accounts_type (account_type)
) ENGINE=InnoDB;

CREATE TABLE transactions (
  transaction_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  account_id INT NOT NULL,
  method ENUM('ATM','Online','POS','Wire') NOT NULL,
  merchant VARCHAR(200) NOT NULL,
  location VARCHAR(150) NOT NULL,
  device ENUM('Android','Tablet','Web','iPhone') NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  fraud_probability DECIMAL(5,4) NOT NULL,
  status ENUM('Failed','Flagged','Success') NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_transactions_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_transactions_account
    FOREIGN KEY (account_id) REFERENCES accounts(account_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_transactions_user_id (user_id),
  INDEX idx_transactions_account_id (account_id),
  INDEX idx_transactions_status (status),
  INDEX idx_transactions_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE fraud_cases (
  case_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  transaction_id INT NOT NULL,
  priority ENUM('Critical','High') NOT NULL,
  fraud_type VARCHAR(120) NOT NULL,
  fraud_amount DECIMAL(12,2) NOT NULL,
  assigned_analyst_id INT NOT NULL,
  status ENUM('investigating') NOT NULL,
  resolution VARCHAR(120) NULL,
  recovery_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  CONSTRAINT fk_cases_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_cases_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_cases_analyst
    FOREIGN KEY (assigned_analyst_id) REFERENCES analysts(analyst_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_cases_user_id (user_id),
  INDEX idx_cases_transaction_id (transaction_id),
  INDEX idx_cases_analyst_id (assigned_analyst_id),
  INDEX idx_cases_priority (priority),
  INDEX idx_cases_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE alerts (
  alert_id INT PRIMARY KEY,
  transaction_id INT NOT NULL,
  severity ENUM('Low','Medium','High') NOT NULL,
  alert_type ENUM('IP Mismatch','Large Transfer','Velocity') NOT NULL,
  queue_status ENUM('pending') NOT NULL,
  analyst_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_alerts_transaction
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_alerts_analyst
    FOREIGN KEY (analyst_id) REFERENCES analysts(analyst_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_alerts_transaction_id (transaction_id),
  INDEX idx_alerts_analyst_id (analyst_id),
  INDEX idx_alerts_severity (severity),
  INDEX idx_alerts_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE notes (
  note_id INT PRIMARY KEY,
  case_id INT NOT NULL,
  analyst_id INT NOT NULL,
  note_text TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_notes_case
    FOREIGN KEY (case_id) REFERENCES fraud_cases(case_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_notes_analyst
    FOREIGN KEY (analyst_id) REFERENCES analysts(analyst_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_notes_case_id (case_id),
  INDEX idx_notes_analyst_id (analyst_id),
  INDEX idx_notes_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE recoveries (
  recovery_id INT PRIMARY KEY,
  case_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  recovered_date DATETIME(6) NOT NULL,
  CONSTRAINT fk_recoveries_case
    FOREIGN KEY (case_id) REFERENCES fraud_cases(case_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_recoveries_case_id (case_id),
  INDEX idx_recoveries_recovered_date (recovered_date)
) ENGINE=InnoDB;

CREATE TABLE login_activity (
  login_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  device ENUM('Desktop','Mobile') NOT NULL,
  login_time DATETIME NOT NULL,
  success TINYINT(1) NOT NULL,
  CONSTRAINT fk_login_activity_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  INDEX idx_login_activity_user_id (user_id),
  INDEX idx_login_activity_login_time (login_time),
  INDEX idx_login_activity_success (success)
) ENGINE=InnoDB;

CREATE TABLE fraud_rules (
  rule_id INT PRIMARY KEY,
  rule_name VARCHAR(150) NOT NULL UNIQUE,
  threshold DECIMAL(12,2) NOT NULL,
  active TINYINT(1) NOT NULL,
  INDEX idx_fraud_rules_active (active)
) ENGINE=InnoDB;
