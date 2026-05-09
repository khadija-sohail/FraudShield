CREATE DATABASE IF NOT EXISTS fraudshield
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE fraudshield;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS source_users (
  user_id INT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(200) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(50) NULL,
  source_role VARCHAR(40) NOT NULL,
  source_status VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_analysts (
  analyst_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  department VARCHAR(80) NOT NULL,
  productivity_score DECIMAL(6,2) NOT NULL,
  INDEX idx_source_analysts_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_accounts (
  account_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  account_number VARCHAR(40) NOT NULL,
  balance DECIMAL(12,2) NOT NULL,
  account_type VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_source_accounts_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_transactions (
  transaction_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  account_id INT NOT NULL,
  method VARCHAR(40) NOT NULL,
  merchant VARCHAR(200) NOT NULL,
  location VARCHAR(150) NOT NULL,
  device VARCHAR(80) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  fraud_probability DECIMAL(8,4) NOT NULL,
  source_status VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_source_transactions_user (user_id),
  INDEX idx_source_transactions_account (account_id),
  INDEX idx_source_transactions_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_fraud_cases (
  case_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  transaction_id INT NOT NULL,
  priority VARCHAR(40) NOT NULL,
  fraud_type VARCHAR(120) NOT NULL,
  fraud_amount DECIMAL(12,2) NOT NULL,
  assigned_analyst_id INT NOT NULL,
  source_status VARCHAR(40) NOT NULL,
  resolution VARCHAR(120) NULL,
  recovery_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  closed_at VARCHAR(80) NULL,
  INDEX idx_source_cases_tx (transaction_id),
  INDEX idx_source_cases_user (user_id),
  INDEX idx_source_cases_analyst (assigned_analyst_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_alerts (
  alert_id INT PRIMARY KEY,
  transaction_id INT NOT NULL,
  severity VARCHAR(40) NOT NULL,
  alert_type VARCHAR(120) NOT NULL,
  queue_status VARCHAR(40) NOT NULL,
  analyst_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_source_alerts_tx (transaction_id),
  INDEX idx_source_alerts_analyst (analyst_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_notes (
  note_id INT PRIMARY KEY,
  case_id INT NOT NULL,
  analyst_id INT NOT NULL,
  note_text TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_source_notes_case (case_id),
  INDEX idx_source_notes_analyst (analyst_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_recoveries (
  recovery_id INT PRIMARY KEY,
  case_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  recovered_date DATETIME NOT NULL,
  INDEX idx_source_recoveries_case (case_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_login_activity (
  login_id INT PRIMARY KEY,
  user_id INT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  device VARCHAR(80) NOT NULL,
  login_time DATETIME NOT NULL,
  success TINYINT(1) NOT NULL,
  INDEX idx_source_login_user (user_id),
  INDEX idx_source_login_time (login_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_fraud_rules (
  rule_id INT PRIMARY KEY,
  rule_name VARCHAR(150) NOT NULL,
  threshold DECIMAL(12,2) NOT NULL,
  active TINYINT(1) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('analyst','admin','manager') NOT NULL DEFAULT 'analyst',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id VARCHAR(20) PRIMARY KEY,
  custId VARCHAR(20) NOT NULL,
  custName VARCHAR(120) NOT NULL,
  account VARCHAR(40) NOT NULL,
  method VARCHAR(40) NOT NULL,
  merchant VARCHAR(120) NOT NULL,
  location VARCHAR(120) NOT NULL,
  device VARCHAR(120) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  risk ENUM('Critical','High','Medium','Low') NOT NULL,
  status ENUM('Blocked','Flagged','Under Review','Cleared') NOT NULL,
  dt DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tx_dt (dt),
  INDEX idx_tx_risk (risk),
  INDEX idx_tx_status (status),
  INDEX idx_tx_customer (custId, custName),
  INDEX idx_tx_method (method)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cases (
  id VARCHAR(20) PRIMARY KEY,
  custId VARCHAR(20) NOT NULL,
  txId VARCHAR(20) NOT NULL,
  opened DATE NOT NULL,
  status ENUM('Open','Under Review','Pending Docs','Closed','Investigating','Escalated') NOT NULL,
  priority ENUM('Critical','High','Medium','Low') NOT NULL,
  fraudType VARCHAR(80) NOT NULL,
  fraudAmt DECIMAL(12,2) NOT NULL DEFAULT 0,
  analyst VARCHAR(120) NOT NULL,
  resolution ENUM('Pending','No Fraud','Fraud Confirmed','Escalated') NOT NULL DEFAULT 'Pending',
  recoveredAmt DECIMAL(12,2) NOT NULL DEFAULT 0,
  sla VARCHAR(40) NOT NULL,
  slaOverdue TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_case_tx (txId),
  INDEX idx_case_customer (custId),
  INDEX idx_case_status (status),
  INDEX idx_case_priority (priority)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
  id VARCHAR(20) PRIMARY KEY,
  txId VARCHAR(20) NOT NULL,
  custName VARCHAR(120) NOT NULL,
  type VARCHAR(80) NOT NULL,
  severity ENUM('Critical','High','Medium','Low') NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  analyst VARCHAR(120) NOT NULL,
  queueStatus ENUM('New','Open','Escalated','Resolved','Pending','Investigating') NOT NULL,
  minsOpen INT NOT NULL DEFAULT 0,
  dt DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alert_tx (txId),
  INDEX idx_alert_severity (severity),
  INDEX idx_alert_queue (queueStatus),
  INDEX idx_alert_dt (dt)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fraud_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  threshold_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  description VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(255) NOT NULL,
  user_name VARCHAR(120) NOT NULL DEFAULT 'system',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analyst_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(20) NULL,
  customer_id VARCHAR(20) NULL,
  note TEXT NOT NULL,
  analyst VARCHAR(120) NOT NULL DEFAULT 'Sarah Abadi',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  email VARCHAR(160) NOT NULL,
  role VARCHAR(40) NOT NULL,
  success TINYINT(1) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_user (user_id),
  INDEX idx_login_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analyst_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  analyst_id INT NOT NULL,
  case_id VARCHAR(20) NULL,
  title VARCHAR(180) NOT NULL,
  details TEXT NULL,
  status ENUM('Assigned','In Progress','Done') NOT NULL DEFAULT 'Assigned',
  assigned_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_task_analyst (analyst_id),
  INDEX idx_task_case (case_id),
  INDEX idx_task_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seed_meta (
  seed_key VARCHAR(40) PRIMARY KEY,
  seed_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW v_source_import_counts AS
SELECT 'gen_users.csv' file_name, COUNT(*) rows_loaded FROM source_users
UNION ALL SELECT 'gen_analysts.csv', COUNT(*) FROM source_analysts
UNION ALL SELECT 'gen_accounts.csv', COUNT(*) FROM source_accounts
UNION ALL SELECT 'gen_transactions.csv', COUNT(*) FROM source_transactions
UNION ALL SELECT 'gen_fraud_cases.csv', COUNT(*) FROM source_fraud_cases
UNION ALL SELECT 'gen_alerts.csv', COUNT(*) FROM source_alerts
UNION ALL SELECT 'gen_notes.csv', COUNT(*) FROM source_notes
UNION ALL SELECT 'gen_recoveries.csv', COUNT(*) FROM source_recoveries
UNION ALL SELECT 'gen_login_activity.csv', COUNT(*) FROM source_login_activity
UNION ALL SELECT 'gen_fraud_rules.csv', COUNT(*) FROM source_fraud_rules;

CREATE OR REPLACE VIEW v_dashboard_stats AS
SELECT
  (SELECT COUNT(*) FROM transactions) AS tx_total,
  (SELECT COALESCE(SUM(amount),0) FROM transactions) AS tx_vol,
  (SELECT COALESCE(SUM(status='Blocked'),0) FROM transactions) AS tx_blocked,
  (SELECT COALESCE(SUM(score>=75),0) FROM transactions) AS fraud_events,
  (SELECT COUNT(*) FROM cases) AS cases_total,
  (SELECT COALESCE(SUM(status IN ('Open','Under Review','Pending Docs','Investigating','Escalated')),0) FROM cases) AS cases_open,
  (SELECT COALESCE(SUM(status='Closed' AND opened=CURDATE()),0) FROM cases) AS closed_today,
  (SELECT COALESCE(SUM(fraudAmt),0) FROM cases) AS cases_fraud_amt,
  (SELECT COALESCE(SUM(recoveredAmt),0) FROM cases) AS cases_recovered,
  (SELECT COUNT(*) FROM alerts) AS alerts_total,
  (SELECT COALESCE(SUM(queueStatus IN ('New','Open','Pending','Investigating')),0) FROM alerts) AS alerts_open,
  (SELECT COALESCE(SUM(severity='Critical'),0) FROM alerts) AS alerts_critical,
  (SELECT COALESCE(SUM(queueStatus='Escalated' AND DATE(dt)=CURDATE()),0) FROM alerts) AS escalated_today;

CREATE OR REPLACE VIEW v_chart_fraud_types AS
SELECT fraudType label, COUNT(*) value
FROM cases
GROUP BY fraudType
ORDER BY value DESC;

CREATE OR REPLACE VIEW v_chart_daily_trend AS
SELECT DATE(dt) day, COUNT(*) tx_count, SUM(score>=75) fraud_count
FROM transactions
WHERE dt >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
GROUP BY DATE(dt)
ORDER BY day;

CREATE OR REPLACE VIEW v_chart_loss_recovery AS
SELECT DATE_FORMAT(opened,'%Y-%m') month_key, DATE_FORMAT(opened,'%b') label,
       SUM(fraudAmt)/1000 losses, SUM(recoveredAmt)/1000 recovered
FROM cases
WHERE opened >= DATE_SUB(CURDATE(), INTERVAL 8 MONTH)
GROUP BY month_key, label
ORDER BY month_key;

CREATE OR REPLACE VIEW v_chart_by_channel AS
SELECT method label, COUNT(*) value
FROM transactions
WHERE score >= 75
GROUP BY method
ORDER BY FIELD(method,'P2P','ATM','Mobile','Wire','InStore','Online','POS','Transfer');

CREATE OR REPLACE VIEW v_cases_weekly AS
SELECT YEARWEEK(opened,1) week_key, CONCAT('W', WEEK(opened,1)) label,
       COUNT(*) opened, SUM(status='Closed') resolved
FROM cases
WHERE opened >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
GROUP BY week_key, label
ORDER BY week_key;

CREATE OR REPLACE VIEW v_recovery_rate AS
SELECT DATE_FORMAT(opened,'%Y-%m') month_key, DATE_FORMAT(opened,'%b') label,
       ROUND((SUM(recoveredAmt)/NULLIF(SUM(fraudAmt),0))*100,1) value
FROM cases
WHERE opened >= DATE_SUB(CURDATE(), INTERVAL 8 MONTH)
GROUP BY month_key, label
ORDER BY month_key;

CREATE OR REPLACE VIEW v_cases_monthly AS
SELECT DATE_FORMAT(opened,'%Y-%m') month_key, DATE_FORMAT(opened,'%b') label,
       COUNT(*) opened, SUM(status='Closed') closed
FROM cases
WHERE opened >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY month_key, label
ORDER BY month_key;

CREATE OR REPLACE VIEW v_analyst_performance AS
SELECT analyst name, COUNT(*) cases, ROUND(70 + LEAST(COUNT(*),25),0) score
FROM cases
GROUP BY analyst
ORDER BY cases DESC;

CREATE OR REPLACE VIEW v_admin_analysts AS
SELECT id, full_name, email, role, status, last_login, created_at
FROM users
WHERE role='analyst'
ORDER BY created_at DESC;

CREATE OR REPLACE VIEW v_recent_login_history AS
SELECT email, role, success, ip, created_at
FROM login_history
ORDER BY created_at DESC;

CREATE OR REPLACE VIEW v_assigned_tasks AS
SELECT t.*, u.full_name analyst_name
FROM analyst_tasks t
JOIN users u ON u.id=t.analyst_id
ORDER BY t.created_at DESC;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_reset_source_data$$
CREATE PROCEDURE sp_reset_source_data()
BEGIN
  TRUNCATE TABLE source_users;
  TRUNCATE TABLE source_analysts;
  TRUNCATE TABLE source_accounts;
  TRUNCATE TABLE source_transactions;
  TRUNCATE TABLE source_fraud_cases;
  TRUNCATE TABLE source_alerts;
  TRUNCATE TABLE source_notes;
  TRUNCATE TABLE source_recoveries;
  TRUNCATE TABLE source_login_activity;
  TRUNCATE TABLE source_fraud_rules;
END$$

DROP PROCEDURE IF EXISTS sp_refresh_app_from_sources$$
CREATE PROCEDURE sp_refresh_app_from_sources()
BEGIN
  TRUNCATE TABLE analyst_tasks;
  TRUNCATE TABLE analyst_notes;
  TRUNCATE TABLE login_history;
  TRUNCATE TABLE audit_log;
  TRUNCATE TABLE alerts;
  TRUNCATE TABLE cases;
  TRUNCATE TABLE transactions;
  TRUNCATE TABLE fraud_rules;
  TRUNCATE TABLE app_settings;
  TRUNCATE TABLE users;

  INSERT INTO users (id, full_name, email, password, role, status, created_at)
  SELECT
    su.user_id,
    su.full_name,
    su.email,
    CASE
      WHEN LOWER(su.source_role) = 'manager' THEN '$2y$10$tklr9o8ZpIJ3ofXWrMsTquJwBC6QPz6YRJ6yue6VLC1fzOHdkJuR6'
      WHEN LOWER(su.source_role) = 'admin' THEN '$2y$10$Xh2K0dBwlHnGo7lcPfPYT.SvbmWZgp9zf8wQMq9sYRSjyG8b.eSJO'
      ELSE '$2y$10$VlpZRw4STgNl1WX8K8wVyuEkgVZt.llC/8qsRHiUkrLohEJGtns3G'
    END,
    CASE
      WHEN LOWER(su.source_role) = 'admin' THEN 'admin'
      WHEN LOWER(su.source_role) = 'manager' THEN 'manager'
      ELSE 'analyst'
    END,
    CASE WHEN LOWER(su.source_status) = 'active' THEN 'active' ELSE 'disabled' END,
    su.created_at
  FROM source_users su
  WHERE LOWER(su.source_role) IN ('admin','manager','analyst','support','investigator')
  ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    role = VALUES(role),
    status = VALUES(status);

  INSERT IGNORE INTO users (full_name, email, password, role, status)
  VALUES ('System Admin', 'admin@fraudshield.com', '$2y$10$Xh2K0dBwlHnGo7lcPfPYT.SvbmWZgp9zf8wQMq9sYRSjyG8b.eSJO', 'admin', 'active');

  INSERT INTO transactions (id,custId,custName,account,method,merchant,location,device,ip,amount,score,risk,status,dt)
  SELECT
    CONCAT('TX-', LPAD(st.transaction_id, 6, '0')),
    CONCAT('CUST-', LPAD(st.user_id, 4, '0')),
    COALESCE(su.full_name, CONCAT('Customer ', st.user_id)),
    COALESCE(sa.account_number, CONCAT('ACC-', LPAD(st.account_id, 6, '0'))),
    CASE LOWER(st.method)
      WHEN 'credit_card' THEN 'POS'
      WHEN 'debit_card' THEN 'POS'
      WHEN 'bank_transfer' THEN 'Wire'
      WHEN 'wire_transfer' THEN 'Wire'
      WHEN 'cash_withdrawal' THEN 'ATM'
      WHEN 'online_payment' THEN 'Online'
      ELSE 'Online'
    END,
    st.merchant,
    st.location,
    CASE LOWER(st.device)
      WHEN 'mobile' THEN 'Mobile'
      WHEN 'desktop' THEN 'Desktop'
      WHEN 'tablet' THEN 'Tablet'
      WHEN 'iphone' THEN 'Mobile'
      WHEN 'android' THEN 'Mobile'
      ELSE 'Web'
    END,
    st.ip_address,
    st.amount,
    LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))),
    CASE
      WHEN LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))) >= 81 THEN 'Critical'
      WHEN LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))) >= 61 THEN 'High'
      WHEN LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))) >= 31 THEN 'Medium'
      ELSE 'Low'
    END,
    CASE
      WHEN LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))) >= 85 THEN 'Blocked'
      WHEN LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))) >= 61 THEN 'Flagged'
      WHEN LEAST(99, GREATEST(0, ROUND(st.fraud_probability * 99))) >= 31 THEN 'Under Review'
      ELSE 'Cleared'
    END,
    st.created_at
  FROM source_transactions st
  LEFT JOIN source_users su ON su.user_id = st.user_id
  LEFT JOIN source_accounts sa ON sa.account_id = st.account_id;

  INSERT INTO cases (id,custId,txId,opened,status,priority,fraudType,fraudAmt,analyst,resolution,recoveredAmt,sla,slaOverdue)
  SELECT
    CONCAT('CASE-', LPAD(sc.case_id, 4, '0')),
    CONCAT('CUST-', LPAD(sc.user_id, 4, '0')),
    CONCAT('TX-', LPAD(sc.transaction_id, 6, '0')),
    DATE(sc.created_at),
    CASE LOWER(sc.source_status)
      WHEN 'closed' THEN 'Closed'
      WHEN 'investigating' THEN 'Investigating'
      WHEN 'under_review' THEN 'Under Review'
      WHEN 'pending_docs' THEN 'Pending Docs'
      WHEN 'escalated' THEN 'Escalated'
      ELSE 'Open'
    END,
    CASE LOWER(sc.priority)
      WHEN 'critical' THEN 'Critical'
      WHEN 'high' THEN 'High'
      WHEN 'low' THEN 'Low'
      ELSE 'Medium'
    END,
    TRIM(REPLACE(REPLACE(sc.fraud_type, '_', ' '), '-', ' ')),
    sc.fraud_amount,
    COALESCE(au.full_name, 'Unassigned'),
    CASE
      WHEN sc.resolution IN ('1','1.0','fraud','Fraud Confirmed') THEN 'Fraud Confirmed'
      WHEN sc.resolution IN ('0','0.0','no_fraud','No Fraud') THEN 'No Fraud'
      WHEN LOWER(sc.source_status) = 'closed' THEN 'Fraud Confirmed'
      ELSE 'Pending'
    END,
    COALESCE(sr.recovered_amount, sc.recovery_amount, 0),
    CASE LOWER(sc.priority)
      WHEN 'critical' THEN '4h'
      WHEN 'high' THEN '12h'
      WHEN 'medium' THEN '48h'
      ELSE '120h'
    END,
    CASE
      WHEN LOWER(sc.source_status) <> 'closed'
       AND TIMESTAMPDIFF(HOUR, sc.created_at, NOW()) >
        CASE LOWER(sc.priority) WHEN 'critical' THEN 4 WHEN 'high' THEN 12 WHEN 'medium' THEN 48 ELSE 120 END
      THEN 1 ELSE 0
    END
  FROM source_fraud_cases sc
  LEFT JOIN source_analysts an ON an.analyst_id = sc.assigned_analyst_id
  LEFT JOIN source_users au ON au.user_id = an.user_id
  LEFT JOIN (
    SELECT case_id, SUM(amount) recovered_amount
    FROM source_recoveries
    GROUP BY case_id
  ) sr ON sr.case_id = sc.case_id;

  INSERT INTO alerts (id,txId,custName,type,severity,score,analyst,queueStatus,minsOpen,dt)
  SELECT
    CONCAT('ALT-', LPAD(al.alert_id, 4, '0')),
    CONCAT('TX-', LPAD(al.transaction_id, 6, '0')),
    COALESCE(su.full_name, 'Customer'),
    TRIM(REPLACE(REPLACE(al.alert_type, '_', ' '), '-', ' ')),
    CASE LOWER(al.severity)
      WHEN 'critical' THEN 'Critical'
      WHEN 'high' THEN 'High'
      WHEN 'low' THEN 'Low'
      ELSE 'Medium'
    END,
    COALESCE(t.score, 60),
    COALESCE(au.full_name, 'Unassigned'),
    CASE LOWER(al.queue_status)
      WHEN 'open' THEN 'Open'
      WHEN 'pending' THEN 'Pending'
      WHEN 'investigating' THEN 'Investigating'
      WHEN 'escalated' THEN 'Escalated'
      WHEN 'resolved' THEN 'Resolved'
      WHEN 'closed' THEN 'Resolved'
      ELSE 'New'
    END,
    GREATEST(0, TIMESTAMPDIFF(MINUTE, al.created_at, NOW())),
    al.created_at
  FROM source_alerts al
  LEFT JOIN source_transactions st ON st.transaction_id = al.transaction_id
  LEFT JOIN source_users su ON su.user_id = st.user_id
  LEFT JOIN transactions t ON t.id = CONCAT('TX-', LPAD(al.transaction_id, 6, '0'))
  LEFT JOIN source_analysts an ON an.analyst_id = al.analyst_id
  LEFT JOIN source_users au ON au.user_id = an.user_id;

  INSERT INTO fraud_rules (id, name, threshold_value, active, description)
  SELECT rule_id, rule_name, threshold, active, 'Loaded from gen_fraud_rules.csv'
  FROM source_fraud_rules
  ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    threshold_value = VALUES(threshold_value),
    active = VALUES(active),
    description = VALUES(description);

  INSERT IGNORE INTO app_settings (setting_key, setting_value)
  VALUES
    ('block_threshold','85'),
    ('alert_threshold','60'),
    ('review_threshold','40'),
    ('model_precision','94.7'),
    ('model_recall','89.3'),
    ('model_f1','91.9'),
    ('model_auc','96.2'),
    ('model_specificity','97.1'),
    ('model_accuracy','94.7');

  INSERT INTO analyst_notes (case_id, customer_id, note, analyst, created_at)
  SELECT
    CONCAT('CASE-', LPAD(sn.case_id, 4, '0')),
    NULL,
    sn.note_text,
    COALESCE(au.full_name, 'Unassigned'),
    sn.created_at
  FROM source_notes sn
  LEFT JOIN source_analysts an ON an.analyst_id = sn.analyst_id
  LEFT JOIN source_users au ON au.user_id = an.user_id;

  INSERT INTO login_history (user_id, email, role, success, ip, created_at)
  SELECT
    CASE WHEN u.id IS NULL THEN NULL ELSE sla.user_id END,
    COALESCE(su.email, CONCAT('user', sla.user_id, '@example.local')),
    COALESCE(su.source_role, 'customer'),
    sla.success,
    sla.ip,
    sla.login_time
  FROM source_login_activity sla
  LEFT JOIN source_users su ON su.user_id = sla.user_id
  LEFT JOIN users u ON u.id = sla.user_id;

  INSERT INTO seed_meta (seed_key, seed_value)
  VALUES ('source', 'seed_data_csv/gen_*.csv')
  ON DUPLICATE KEY UPDATE seed_value = VALUES(seed_value);

  INSERT INTO audit_log (action, user_name)
  VALUES ('Database refreshed from normalized CSV source tables', 'mysql_procedure');
END$$

DROP PROCEDURE IF EXISTS sp_insert_transaction$$
CREATE PROCEDURE sp_insert_transaction(
  IN p_custId VARCHAR(20), IN p_custName VARCHAR(120), IN p_account VARCHAR(40),
  IN p_method VARCHAR(40), IN p_merchant VARCHAR(120), IN p_location VARCHAR(120),
  IN p_device VARCHAR(120), IN p_ip VARCHAR(45), IN p_amount DECIMAL(12,2),
  IN p_score INT, OUT p_new_id VARCHAR(20)
)
BEGIN
  DECLARE next_num INT DEFAULT 1;
  SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(id,'-',-1) AS UNSIGNED)),0)+1 INTO next_num FROM transactions;
  SET p_new_id = CONCAT('TX-', LPAD(next_num, 6, '0'));
  INSERT INTO transactions (id,custId,custName,account,method,merchant,location,device,ip,amount,score,risk,status,dt)
  VALUES (
    p_new_id,
    IFNULL(NULLIF(p_custId,''), CONCAT('CUST-', LPAD(FLOOR(1 + RAND()*9999),4,'0'))),
    p_custName,
    IFNULL(NULLIF(p_account,''), CONCAT('**** **** ', FLOOR(1000 + RAND()*8999))),
    p_method, p_merchant, p_location, p_device, p_ip, p_amount, p_score,
    CASE WHEN p_score>=81 THEN 'Critical' WHEN p_score>=61 THEN 'High' WHEN p_score>=31 THEN 'Medium' ELSE 'Low' END,
    CASE WHEN p_score>=85 THEN 'Blocked' WHEN p_score>=61 THEN 'Flagged' WHEN p_score>=31 THEN 'Under Review' ELSE 'Cleared' END,
    NOW()
  );
END$$

DROP PROCEDURE IF EXISTS sp_insert_case$$
CREATE PROCEDURE sp_insert_case(
  IN p_custId VARCHAR(20), IN p_txId VARCHAR(20), IN p_status VARCHAR(40),
  IN p_priority VARCHAR(20), IN p_fraudType VARCHAR(80), IN p_fraudAmt DECIMAL(12,2),
  IN p_analyst VARCHAR(120), OUT p_new_id VARCHAR(20)
)
BEGIN
  DECLARE next_num INT DEFAULT 1;
  SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(id,'-',-1) AS UNSIGNED)),0)+1 INTO next_num FROM cases;
  SET p_new_id = CONCAT('CASE-', LPAD(next_num, 4, '0'));
  INSERT INTO cases (id,custId,txId,opened,status,priority,fraudType,fraudAmt,analyst,resolution,recoveredAmt,sla,slaOverdue)
  VALUES (
    p_new_id, p_custId, p_txId, CURDATE(), p_status, p_priority, p_fraudType, p_fraudAmt,
    p_analyst, 'Pending', 0,
    CASE WHEN p_priority='Critical' THEN '4h' WHEN p_priority='High' THEN '12h' WHEN p_priority='Medium' THEN '48h' ELSE '120h' END,
    0
  );
END$$

DROP PROCEDURE IF EXISTS sp_insert_alert$$
CREATE PROCEDURE sp_insert_alert(
  IN p_txId VARCHAR(20), IN p_custName VARCHAR(120), IN p_type VARCHAR(80),
  IN p_severity VARCHAR(20), IN p_score INT, IN p_analyst VARCHAR(120), OUT p_new_id VARCHAR(20)
)
BEGIN
  DECLARE next_num INT DEFAULT 1;
  SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(id,'-',-1) AS UNSIGNED)),0)+1 INTO next_num FROM alerts;
  SET p_new_id = CONCAT('ALT-', LPAD(next_num, 4, '0'));
  INSERT INTO alerts (id,txId,custName,type,severity,score,analyst,queueStatus,minsOpen,dt)
  VALUES (p_new_id, p_txId, p_custName, p_type, p_severity, p_score, p_analyst, 'New', 0, NOW());
END$$

DROP PROCEDURE IF EXISTS sp_assign_task$$
CREATE PROCEDURE sp_assign_task(
  IN p_analyst_id INT, IN p_case_id VARCHAR(20), IN p_title VARCHAR(180),
  IN p_details TEXT, IN p_assigned_by INT
)
BEGIN
  INSERT INTO analyst_tasks (analyst_id,case_id,title,details,assigned_by)
  VALUES (p_analyst_id, NULLIF(p_case_id,''), p_title, p_details, p_assigned_by);
  IF p_case_id IS NOT NULL AND p_case_id <> '' THEN
    UPDATE cases SET analyst=(SELECT full_name FROM users WHERE id=p_analyst_id) WHERE id=p_case_id;
  END IF;
END$$

DROP TRIGGER IF EXISTS trg_users_after_insert$$
CREATE TRIGGER trg_users_after_insert AFTER INSERT ON users
FOR EACH ROW
BEGIN
  INSERT INTO audit_log(action,user_name)
  VALUES (CONCAT('New ', NEW.role, ' added: ', NEW.full_name), 'mysql_trigger');
END$$

DROP TRIGGER IF EXISTS trg_tasks_after_insert$$
CREATE TRIGGER trg_tasks_after_insert AFTER INSERT ON analyst_tasks
FOR EACH ROW
BEGIN
  INSERT INTO audit_log(action,user_name)
  VALUES (CONCAT('Task assigned by MySQL: ', NEW.title), 'mysql_trigger');
END$$

DROP TRIGGER IF EXISTS trg_settings_after_update$$
CREATE TRIGGER trg_settings_after_update AFTER UPDATE ON app_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_log(action,user_name)
  VALUES (CONCAT('Setting changed in MySQL: ', NEW.setting_key, ' = ', NEW.setting_value), 'mysql_trigger');
END$$

DELIMITER ;
