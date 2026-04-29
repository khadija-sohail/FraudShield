CREATE DATABASE IF NOT EXISTS fraudshield
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE fraudshield;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('analyst','admin','manager') NOT NULL DEFAULT 'analyst',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

-- ============================================================
-- MySQL reporting logic: dashboard and chart views
-- ============================================================

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

-- ============================================================
-- MySQL stored procedures: runtime inserts and assignment
-- ============================================================

DELIMITER $$

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
  VALUES (p_new_id, p_custId, p_txId, CURDATE(), p_status, p_priority, p_fraudType, p_fraudAmt, p_analyst, 'Pending', 0,
    CASE WHEN p_priority='Critical' THEN '4h' WHEN p_priority='High' THEN '12h' WHEN p_priority='Medium' THEN '48h' ELSE '120h' END, 0);
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

-- ============================================================
-- MySQL triggers: audit history maintained by the database
-- ============================================================

DROP TRIGGER IF EXISTS trg_users_after_insert$$
CREATE TRIGGER trg_users_after_insert
AFTER INSERT ON users
FOR EACH ROW
BEGIN
  INSERT INTO audit_log(action,user_name)
  VALUES (CONCAT('New ', NEW.role, ' added: ', NEW.full_name), 'mysql_trigger');
END$$

DROP TRIGGER IF EXISTS trg_tasks_after_insert$$
CREATE TRIGGER trg_tasks_after_insert
AFTER INSERT ON analyst_tasks
FOR EACH ROW
BEGIN
  INSERT INTO audit_log(action,user_name)
  VALUES (CONCAT('Task assigned by MySQL: ', NEW.title), 'mysql_trigger');
END$$

DROP TRIGGER IF EXISTS trg_settings_after_update$$
CREATE TRIGGER trg_settings_after_update
AFTER UPDATE ON app_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_log(action,user_name)
  VALUES (CONCAT('Setting changed in MySQL: ', NEW.setting_key, ' = ', NEW.setting_value), 'mysql_trigger');
END$$

DELIMITER ;
