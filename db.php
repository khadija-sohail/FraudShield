<?php
session_start();
// ============================================================
// FraudShield - MySQL API + self-initializing schema
// ============================================================
define('DB_HOST', getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'fraudshield');
define('DB_AUTO_CREATE', (getenv('DB_AUTO_CREATE') ?: (DB_HOST === 'localhost' ? 'true' : 'false')) === 'true');

function jsonOut($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id' => (int)$_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $_SESSION['user_role'] ?? 'analyst',
    ];
}

function requireAdmin(): void {
    $user = currentUser();
    if (!$user || $user['role'] !== 'admin') {
        jsonOut(['error' => 'Admin login required', 'auth_required' => true], 403);
    }
}
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
        if (!DB_AUTO_CREATE) {
            $dsn .= ';dbname=' . DB_NAME;
        }

        $server = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (DB_AUTO_CREATE) {
            $dbName = str_replace('`', '``', DB_NAME);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $server->exec('USE `' . $dbName . '`');
        }
        $pdo = $server;
        ensureSchema($pdo);
        ensureDatabaseLogic($pdo);
        seedDemoData($pdo);
        return $pdo;
    } catch (PDOException $e) {
        jsonOut(['error' => 'Database connection failed: ' . $e->getMessage()], 500);
    }
}

function ensureSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(160) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('analyst','admin','manager') NOT NULL DEFAULT 'analyst',
        status ENUM('active','disabled') NOT NULL DEFAULT 'active',
        last_login DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    try { $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME NULL"); } catch (PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
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
        INDEX idx_tx_dt (dt), INDEX idx_tx_risk (risk), INDEX idx_tx_status (status), INDEX idx_tx_customer (custId, custName), INDEX idx_tx_method (method)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cases (
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
        INDEX idx_case_tx (txId), INDEX idx_case_customer (custId), INDEX idx_case_status (status), INDEX idx_case_priority (priority)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
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
        INDEX idx_alert_tx (txId), INDEX idx_alert_severity (severity), INDEX idx_alert_queue (queueStatus), INDEX idx_alert_dt (dt)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fraud_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        threshold_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        description VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(255) NOT NULL,
        user_name VARCHAR(120) NOT NULL DEFAULT 'system',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created (created_at)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS analyst_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(20) NULL,
        customer_id VARCHAR(20) NULL,
        note TEXT NOT NULL,
        analyst VARCHAR(120) NOT NULL DEFAULT 'Sarah Abadi',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        email VARCHAR(160) NOT NULL,
        role VARCHAR(40) NOT NULL,
        success TINYINT(1) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_user (user_id),
        INDEX idx_login_created (created_at)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS analyst_tasks (
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
    ) ENGINE=InnoDB");

    $defaults = [
        ['block_threshold','85'], ['alert_threshold','60'], ['review_threshold','40'],
        ['model_precision','94.7'], ['model_recall','89.3'], ['model_f1','91.9'], ['model_auc','96.2'], ['model_specificity','97.1'], ['model_accuracy','94.7']
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $d) $stmt->execute($d);

    $rules = [
        ['Velocity Check',5,1,'Max 5 transactions per hour'], ['Amount Threshold',10000,1,'Flag amounts > $10,000'], ['Geo Anomaly',2,1,'Flag logins from 2+ countries/day'],
        ['IP Blacklist',0,1,'Block known malicious IPs'], ['Device Mismatch',1,0,'Alert on new device login'], ['Night Transaction',2300,1,'Flag txns after 23:00 local time'],
        ['Card Not Present',500,1,'Flag CNP > $500'], ['Foreign Card',0,0,'Flag international card use'], ['Wire > $5000',5000,1,'Manual review for large wires'],
        ['P2P Threshold',1000,1,'Flag P2P transfers > $1000'], ['Failed Login Limit',3,1,'Lock after 3 failed logins'], ['ATM Withdrawal Cap',800,0,'Flag ATM > $800/day']
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO fraud_rules (name, threshold_value, active, description) VALUES (?, ?, ?, ?)');
    foreach ($rules as $r) $stmt->execute($r);

    $adminPass = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->prepare('INSERT IGNORE INTO users (full_name,email,password,role,status) VALUES (?,?,?,?,?)')
        ->execute(['System Admin', 'admin@fraudshield.com', $adminPass, 'admin', 'active']);
}

function ensureDatabaseLogic(PDO $pdo): void {
    // Views keep reporting/analytics logic inside MySQL for the database project.
    $pdo->exec("CREATE OR REPLACE VIEW v_dashboard_stats AS
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
            (SELECT COALESCE(SUM(queueStatus='Escalated' AND DATE(dt)=CURDATE()),0) FROM alerts) AS escalated_today");

    $views = [
        "CREATE OR REPLACE VIEW v_chart_fraud_types AS SELECT fraudType label, COUNT(*) value FROM cases GROUP BY fraudType ORDER BY value DESC",
        "CREATE OR REPLACE VIEW v_chart_daily_trend AS SELECT DATE(dt) day, COUNT(*) tx_count, SUM(score>=75) fraud_count FROM transactions WHERE dt>=DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(dt) ORDER BY day",
        "CREATE OR REPLACE VIEW v_chart_loss_recovery AS SELECT DATE_FORMAT(opened,'%Y-%m') month_key, DATE_FORMAT(opened,'%b') label, SUM(fraudAmt)/1000 losses, SUM(recoveredAmt)/1000 recovered FROM cases WHERE opened>=DATE_SUB(CURDATE(), INTERVAL 8 MONTH) GROUP BY month_key,label ORDER BY month_key",
        "CREATE OR REPLACE VIEW v_chart_by_channel AS SELECT method label, COUNT(*) value FROM transactions WHERE score>=75 GROUP BY method ORDER BY FIELD(method,'P2P','ATM','Mobile','Wire','InStore','Online','POS','Transfer')",
        "CREATE OR REPLACE VIEW v_cases_weekly AS SELECT YEARWEEK(opened,1) week_key, CONCAT('W', WEEK(opened,1)) label, COUNT(*) opened, SUM(status='Closed') resolved FROM cases WHERE opened>=DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY week_key,label ORDER BY week_key",
        "CREATE OR REPLACE VIEW v_recovery_rate AS SELECT DATE_FORMAT(opened,'%Y-%m') month_key, DATE_FORMAT(opened,'%b') label, ROUND((SUM(recoveredAmt)/NULLIF(SUM(fraudAmt),0))*100,1) value FROM cases WHERE opened>=DATE_SUB(CURDATE(), INTERVAL 8 MONTH) GROUP BY month_key,label ORDER BY month_key",
        "CREATE OR REPLACE VIEW v_cases_monthly AS SELECT DATE_FORMAT(opened,'%Y-%m') month_key, DATE_FORMAT(opened,'%b') label, COUNT(*) opened, SUM(status='Closed') closed FROM cases WHERE opened>=DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY month_key,label ORDER BY month_key",
        "CREATE OR REPLACE VIEW v_analyst_performance AS SELECT analyst name, COUNT(*) cases, ROUND(70 + LEAST(COUNT(*),25),0) score FROM cases GROUP BY analyst ORDER BY cases DESC",
        "CREATE OR REPLACE VIEW v_admin_analysts AS SELECT id, full_name, email, role, status, last_login, created_at FROM users WHERE role='analyst' ORDER BY created_at DESC",
        "CREATE OR REPLACE VIEW v_recent_login_history AS SELECT email, role, success, ip, created_at FROM login_history ORDER BY created_at DESC",
        "CREATE OR REPLACE VIEW v_assigned_tasks AS SELECT t.*, u.full_name analyst_name FROM analyst_tasks t JOIN users u ON u.id=t.analyst_id ORDER BY t.created_at DESC"
    ];
    foreach ($views as $sql) $pdo->exec($sql);

    // Stored procedures move runtime inserts/assignment workflow into MySQL.
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_insert_transaction");
    $pdo->exec("CREATE PROCEDURE sp_insert_transaction(
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
            p_new_id, IFNULL(NULLIF(p_custId,''), CONCAT('CUST-', LPAD(FLOOR(1 + RAND()*9999),4,'0'))),
            p_custName, IFNULL(NULLIF(p_account,''), CONCAT('**** **** ', FLOOR(1000 + RAND()*8999))),
            p_method, p_merchant, p_location, p_device, p_ip, p_amount, p_score,
            CASE WHEN p_score>=81 THEN 'Critical' WHEN p_score>=61 THEN 'High' WHEN p_score>=31 THEN 'Medium' ELSE 'Low' END,
            CASE WHEN p_score>=85 THEN 'Blocked' WHEN p_score>=61 THEN 'Flagged' WHEN p_score>=31 THEN 'Under Review' ELSE 'Cleared' END,
            NOW()
        );
    END");

    $pdo->exec("DROP PROCEDURE IF EXISTS sp_insert_case");
    $pdo->exec("CREATE PROCEDURE sp_insert_case(
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
    END");

    $pdo->exec("DROP PROCEDURE IF EXISTS sp_insert_alert");
    $pdo->exec("CREATE PROCEDURE sp_insert_alert(
        IN p_txId VARCHAR(20), IN p_custName VARCHAR(120), IN p_type VARCHAR(80),
        IN p_severity VARCHAR(20), IN p_score INT, IN p_analyst VARCHAR(120), OUT p_new_id VARCHAR(20)
    )
    BEGIN
        DECLARE next_num INT DEFAULT 1;
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(id,'-',-1) AS UNSIGNED)),0)+1 INTO next_num FROM alerts;
        SET p_new_id = CONCAT('ALT-', LPAD(next_num, 4, '0'));
        INSERT INTO alerts (id,txId,custName,type,severity,score,analyst,queueStatus,minsOpen,dt)
        VALUES (p_new_id, p_txId, p_custName, p_type, p_severity, p_score, p_analyst, 'New', 0, NOW());
    END");

    $pdo->exec("DROP PROCEDURE IF EXISTS sp_assign_task");
    $pdo->exec("CREATE PROCEDURE sp_assign_task(
        IN p_analyst_id INT, IN p_case_id VARCHAR(20), IN p_title VARCHAR(180),
        IN p_details TEXT, IN p_assigned_by INT
    )
    BEGIN
        INSERT INTO analyst_tasks (analyst_id,case_id,title,details,assigned_by)
        VALUES (p_analyst_id, NULLIF(p_case_id,''), p_title, p_details, p_assigned_by);
        IF p_case_id IS NOT NULL AND p_case_id <> '' THEN
            UPDATE cases SET analyst=(SELECT full_name FROM users WHERE id=p_analyst_id) WHERE id=p_case_id;
        END IF;
    END");

    // Triggers prove history/audit is maintained by the database too.
    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_after_insert");
    $pdo->exec("CREATE TRIGGER trg_users_after_insert AFTER INSERT ON users
        FOR EACH ROW INSERT INTO audit_log(action,user_name) VALUES (CONCAT('New ', NEW.role, ' added: ', NEW.full_name), 'mysql_trigger')");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_tasks_after_insert");
    $pdo->exec("CREATE TRIGGER trg_tasks_after_insert AFTER INSERT ON analyst_tasks
        FOR EACH ROW INSERT INTO audit_log(action,user_name) VALUES (CONCAT('Task assigned by MySQL: ', NEW.title), 'mysql_trigger')");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_settings_after_update");
    $pdo->exec("CREATE TRIGGER trg_settings_after_update AFTER UPDATE ON app_settings
        FOR EACH ROW INSERT INTO audit_log(action,user_name) VALUES (CONCAT('Setting changed in MySQL: ', NEW.setting_key, ' = ', NEW.setting_value), 'mysql_trigger')");
}

function riskFromScore(int $score): string { return $score >= 81 ? 'Critical' : ($score >= 61 ? 'High' : ($score >= 31 ? 'Medium' : 'Low')); }
function statusFromScore(int $score): string { return $score >= 85 ? 'Blocked' : ($score >= 61 ? 'Flagged' : ($score >= 31 ? 'Under Review' : 'Cleared')); }
function nextId(PDO $pdo, string $table, string $prefix, int $pad): string {
    $stmt = $pdo->query("SELECT id FROM `$table` WHERE id LIKE '$prefix-%' ORDER BY CAST(SUBSTRING_INDEX(id,'-',-1) AS UNSIGNED) DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $n = $last ? ((int)substr($last, strlen($prefix) + 1) + 1) : 1;
    return $prefix . '-' . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}
function audit(PDO $pdo, string $action, string $user = 'system'): void { $pdo->prepare('INSERT INTO audit_log (action,user_name) VALUES (?,?)')->execute([$action,$user]); }
function logLogin(PDO $pdo, ?array $user, string $email, bool $success): void {
    $pdo->prepare('INSERT INTO login_history (user_id,email,role,success,ip) VALUES (?,?,?,?,?)')
        ->execute([$user['id'] ?? null, $email, $user['role'] ?? 'unknown', $success ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? 'local']);
}

function seedDemoData(PDO $pdo): void {
    // Seed metadata: lets us safely switch seed sources once.
    $pdo->exec("CREATE TABLE IF NOT EXISTS seed_meta (
        seed_key VARCHAR(40) PRIMARY KEY,
        seed_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // ── Helper: parse a CSV file into an array of associative rows ──────────
    $readCsv = function(string $path): array {
        if (!is_readable($path)) return [];
        $rows = []; $handle = fopen($path, 'r');
        if (!$handle) return $rows;
        $headers = fgetcsv($handle);
        // strip UTF-8 BOM from first header if present
        if ($headers) $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === count($headers)) $rows[] = array_combine($headers, $line);
        }
        fclose($handle); return $rows;
    };

    // ── Seed source: prefer seed_data_csv/gen_*.csv if present ──────────────
    $baseDir = __DIR__;
    $seedDir = $baseDir . '/seed_data_csv';

    $hasGenSeeds =
        is_readable($seedDir . '/gen_users.csv') &&
        is_readable($seedDir . '/gen_transactions.csv') &&
        is_readable($seedDir . '/gen_fraud_cases.csv') &&
        is_readable($seedDir . '/gen_alerts.csv');

    // Legacy demo seed files (kept for compatibility)
    $csvTransactions = $baseDir . '/transactions.csv';
    $csvCases        = $baseDir . '/cases.csv';
    $csvAlerts       = $baseDir . '/alerts.csv';

    // ── Helpers for gen_* normalization into FraudShield enums ──────────────
    $toDateTime = function($v): string {
        $v = trim((string)$v);
        if ($v === '') return date('Y-m-d H:i:s');
        $ts = strtotime($v);
        if ($ts !== false) return date('Y-m-d H:i:s', $ts);
        // handle dd/mm/yyyy hh:mm (common in gen_users.csv)
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})$/', $v, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:00";
        }
        return date('Y-m-d H:i:s');
    };
    $custIdFromUserId = fn(int $uid): string => 'CUST-' . str_pad((string)$uid, 4, '0', STR_PAD_LEFT);
    $txIdFromInt = fn(int $id): string => 'TX-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $caseIdFromInt = fn(int $id): string => 'CASE-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $alertIdFromInt = fn(int $id): string => 'ALT-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);

    $methodMap = [
        'credit_card' => 'POS',
        'debit_card' => 'POS',
        'bank_transfer' => 'Wire',
        'wire_transfer' => 'Wire',
        'online_payment' => 'Online',
        'cash_withdrawal' => 'ATM',
    ];
    $deviceMap = [
        'mobile' => 'Mobile',
        'desktop' => 'Desktop',
        'tablet' => 'Tablet',
        'web' => 'Web',
        'iphone' => 'Mobile',
        'android' => 'Mobile',
    ];
    $priorityMap = [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ];
    $caseStatusMap = [
        'open' => 'Open',
        'investigating' => 'Investigating',
        'under_review' => 'Under Review',
        'pending_docs' => 'Pending Docs',
        'escalated' => 'Escalated',
        'closed' => 'Closed',
    ];
    $alertSeverityMap = $priorityMap;
    $queueStatusMap = [
        'new' => 'New',
        'open' => 'Open',
        'pending' => 'Pending',
        'investigating' => 'Investigating',
        'escalated' => 'Escalated',
        'resolved' => 'Resolved',
        'closed' => 'Resolved',
    ];
    $fraudTypeMap = [
        'account_takeover' => 'Account Takeover',
        'payment_fraud' => 'Payment Fraud',
        'identity_theft' => 'Identity Theft',
        'stolen_card' => 'Stolen Card',
        'wire_fraud' => 'Wire Transfer Fraud',
    ];

    if ($hasGenSeeds) {
        // If we previously seeded legacy demo data, replace it once with gen_*.
        $currentSeed = null;
        try {
            $stmt = $pdo->prepare("SELECT seed_value FROM seed_meta WHERE seed_key='source' LIMIT 1");
            $stmt->execute();
            $currentSeed = $stmt->fetchColumn() ?: null;
        } catch (PDOException $e) {}

        if ($currentSeed !== 'gen') {
            // Wipe only the app's operational tables before re-seeding.
            // This avoids mixing incompatible demo and gen_* datasets.
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach ([
                'analyst_tasks',
                'analyst_notes',
                'login_history',
                'audit_log',
                'alerts',
                'cases',
                'transactions',
                'fraud_rules',
                'app_settings',
                'users',
            ] as $tbl) {
                try { $pdo->exec("TRUNCATE TABLE `$tbl`"); } catch (PDOException $e) {}
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

            $pdo->prepare("INSERT INTO seed_meta (seed_key, seed_value) VALUES ('source','gen')
                           ON DUPLICATE KEY UPDATE seed_value=VALUES(seed_value)")
                ->execute();
        } else {
            // Already seeded from gen_*; avoid re-seeding on every request.
            if ((int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn() > 0) return;
        }

        // ── Load gen_* sources ──────────────────────────────────────────────
        $genUsers       = $readCsv($seedDir . '/gen_users.csv');
        $genAnalysts    = $readCsv($seedDir . '/gen_analysts.csv');
        $genAccounts    = $readCsv($seedDir . '/gen_accounts.csv');
        $genTx          = $readCsv($seedDir . '/gen_transactions.csv');
        $genCases       = $readCsv($seedDir . '/gen_fraud_cases.csv');
        $genAlerts      = $readCsv($seedDir . '/gen_alerts.csv');
        $genNotes       = $readCsv($seedDir . '/gen_notes.csv');
        $genRecoveries  = $readCsv($seedDir . '/gen_recoveries.csv');
        $genLogins      = $readCsv($seedDir . '/gen_login_activity.csv');
        $genRules       = $readCsv($seedDir . '/gen_fraud_rules.csv');

        // ── Insert users (only staff roles are relevant for app logins) ─────
        $staffInsert = $pdo->prepare('INSERT IGNORE INTO users (id, full_name, email, password, role, status) VALUES (?,?,?,?,?,?)');
        $userNameById = [];
        $userEmailById = [];
        $userRoleById = [];

        foreach ($genUsers as $u) {
            $uid = (int)($u['user_id'] ?? 0);
            if (!$uid) continue;
            $name = trim((string)($u['full_name'] ?? ''));
            $email = trim((string)($u['email'] ?? ''));
            $roleRaw = strtolower(trim((string)($u['role'] ?? '')));
            $statusRaw = strtolower(trim((string)($u['status'] ?? 'active')));

            $userNameById[$uid] = $name ?: ('User ' . $uid);
            $userEmailById[$uid] = $email ?: ('user' . $uid . '@example.local');
            $userRoleById[$uid] = $roleRaw ?: 'customer';

            // map dataset roles → FraudShield roles
            $role = 'analyst';
            if (in_array($roleRaw, ['admin','manager'], true)) $role = $roleRaw;
            elseif (in_array($roleRaw, ['analyst','support','investigator'], true)) $role = 'analyst';
            else continue; // customers are not inserted into app `users`

            $status = ($statusRaw === 'active') ? 'active' : 'disabled';
            // Seeded passwords: set a known default so logins work
            $pass = password_hash('admin123', PASSWORD_BCRYPT);
            if ($role === 'analyst') $pass = password_hash('analyst123', PASSWORD_BCRYPT);
            if ($role === 'manager') $pass = password_hash('manager123', PASSWORD_BCRYPT);

            $staffInsert->execute([$uid, $userNameById[$uid], $userEmailById[$uid], $pass, $role, $status]);
        }

        // ensure documented admin account exists
        $adminPass = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare('INSERT IGNORE INTO users (full_name,email,password,role,status) VALUES (?,?,?,?,?)')
            ->execute(['System Admin', 'admin@fraudshield.com', $adminPass, 'admin', 'active']);

        // ── Analysts lookup (analyst_id -> analyst_name) ────────────────────
        $analystNameByAnalystId = [];
        foreach ($genAnalysts as $a) {
            $aid = (int)($a['analyst_id'] ?? 0);
            $uid = (int)($a['user_id'] ?? 0);
            if ($aid && $uid && isset($userNameById[$uid])) {
                $analystNameByAnalystId[$aid] = $userNameById[$uid];
            }
        }

        // ── Accounts lookup (account_id -> account_number) ──────────────────
        $accountNumberById = [];
        foreach ($genAccounts as $a) {
            $aid = (int)($a['account_id'] ?? 0);
            if (!$aid) continue;
            $accountNumberById[$aid] = (string)($a['account_number'] ?? '');
        }

        // ── Insert transactions into app table ──────────────────────────────
        $txStmt = $pdo->prepare(
            'INSERT IGNORE INTO transactions
             (id,custId,custName,account,method,merchant,location,device,ip,amount,score,risk,status,dt)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $txScoreByIntId = [];
        foreach ($genTx as $t) {
            $tidInt = (int)($t['transaction_id'] ?? 0);
            if (!$tidInt) continue;
            $uid = (int)($t['user_id'] ?? 0);
            $acctId = (int)($t['account_id'] ?? 0);

            $prob = (float)($t['fraud_probability'] ?? 0);
            $score = (int)round(max(0, min(0.9999, $prob)) * 99);
            $txScoreByIntId[$tidInt] = $score;

            $methodRaw = strtolower(trim((string)($t['method'] ?? 'online_payment')));
            $method = $methodMap[$methodRaw] ?? 'Online';

            $deviceRaw = strtolower(trim((string)($t['device'] ?? 'web')));
            $device = $deviceMap[$deviceRaw] ?? 'Web';

            $dt = $toDateTime($t['created_at'] ?? '');

            $txStmt->execute([
                $txIdFromInt($tidInt),
                $custIdFromUserId($uid ?: $tidInt),
                $userNameById[$uid] ?? ('Customer ' . ($uid ?: $tidInt)),
                $accountNumberById[$acctId] ?? ('ACC-' . str_pad((string)$acctId, 6, '0', STR_PAD_LEFT)),
                $method,
                (string)($t['merchant'] ?? 'Unknown'),
                (string)($t['location'] ?? 'Unknown'),
                $device,
                (string)($t['ip_address'] ?? '0.0.0.0'),
                (float)($t['amount'] ?? 0),
                $score,
                riskFromScore($score),
                statusFromScore($score),
                $dt,
            ]);
        }

        // ── Insert cases into app table ─────────────────────────────────────
        $caseStmt = $pdo->prepare(
            'INSERT IGNORE INTO cases
             (id,custId,txId,opened,status,priority,fraudType,fraudAmt,analyst,resolution,recoveredAmt,sla,slaOverdue)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($genCases as $c) {
            $cidInt = (int)($c['case_id'] ?? 0);
            if (!$cidInt) continue;
            $uid = (int)($c['user_id'] ?? 0);
            $tidInt = (int)($c['transaction_id'] ?? 0);
            $priorityRaw = strtolower(trim((string)($c['priority'] ?? 'medium')));
            $priority = $priorityMap[$priorityRaw] ?? 'Medium';

            $statusRaw = strtolower(trim((string)($c['status'] ?? 'open')));
            $status = $caseStatusMap[$statusRaw] ?? 'Open';

            $fraudTypeRaw = strtolower(trim((string)($c['fraud_type'] ?? 'payment_fraud')));
            $fraudType = $fraudTypeMap[$fraudTypeRaw] ?? ucwords(str_replace('_', ' ', $fraudTypeRaw));

            $opened = date('Y-m-d', strtotime($toDateTime($c['created_at'] ?? '')));
            $analystId = (int)($c['assigned_analyst_id'] ?? 0);
            $analystName = $analystNameByAnalystId[$analystId] ?? 'Sarah Abadi';

            $sla = ($priority === 'Critical') ? '4h' : (($priority === 'High') ? '12h' : (($priority === 'Medium') ? '48h' : '120h'));
            $caseStmt->execute([
                $caseIdFromInt($cidInt),
                $custIdFromUserId($uid ?: $cidInt),
                $txIdFromInt($tidInt ?: 1),
                $opened,
                $status,
                $priority,
                $fraudType,
                (float)($c['fraud_amount'] ?? 0),
                $analystName,
                'Pending',
                (float)($c['recovery_amount'] ?? 0),
                $sla,
                0,
            ]);
        }

        // ── Insert alerts into app table ────────────────────────────────────
        $alertStmt = $pdo->prepare(
            'INSERT IGNORE INTO alerts
             (id,txId,custName,type,severity,score,analyst,queueStatus,minsOpen,dt)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($genAlerts as $a) {
            $aidInt = (int)($a['alert_id'] ?? 0);
            if (!$aidInt) continue;
            $tidInt = (int)($a['transaction_id'] ?? 0);
            $severityRaw = strtolower(trim((string)($a['severity'] ?? 'medium')));
            $severity = $alertSeverityMap[$severityRaw] ?? 'Medium';

            $queueRaw = strtolower(trim((string)($a['queue_status'] ?? 'new')));
            $queueStatus = $queueStatusMap[$queueRaw] ?? 'New';

            $analystId = (int)($a['analyst_id'] ?? 0);
            $analystName = $analystNameByAnalystId[$analystId] ?? 'Unassigned';

            $dt = $toDateTime($a['created_at'] ?? '');
            $score = $txScoreByIntId[$tidInt] ?? 60;

            $alertStmt->execute([
                $alertIdFromInt($aidInt),
                $txIdFromInt($tidInt ?: 1),
                'Customer',
                ucwords(str_replace('_',' ', (string)($a['alert_type'] ?? 'behavioral'))),
                $severity,
                $score,
                $analystName,
                $queueStatus,
                0,
                $dt,
            ]);
        }

        // ── Fraud rules (replace defaults with dataset if provided) ─────────
        if ($genRules) {
            $pdo->exec('DELETE FROM fraud_rules');
            $ruleStmt = $pdo->prepare('INSERT INTO fraud_rules (id,name,threshold_value,active,description) VALUES (?,?,?,?,?)');
            foreach ($genRules as $r) {
                $rid = (int)($r['rule_id'] ?? 0);
                if (!$rid) continue;
                $ruleStmt->execute([
                    $rid,
                    (string)($r['rule_name'] ?? ('Rule ' . $rid)),
                    (float)($r['threshold'] ?? 0),
                    (int)($r['active'] ?? 1),
                    'Seeded from gen_fraud_rules.csv',
                ]);
            }
        }

        // ── Analyst notes (map to analyst_notes) ────────────────────────────
        if ($genNotes) {
            $noteStmt = $pdo->prepare('INSERT INTO analyst_notes (case_id,customer_id,note,analyst) VALUES (?,?,?,?)');
            foreach ($genNotes as $n) {
                $caseInt = (int)($n['case_id'] ?? 0);
                $analystId = (int)($n['analyst_id'] ?? 0);
                $noteStmt->execute([
                    $caseInt ? $caseIdFromInt($caseInt) : null,
                    null,
                    (string)($n['note_text'] ?? ''),
                    $analystNameByAnalystId[$analystId] ?? 'Sarah Abadi',
                ]);
            }
        }

        // ── Login activity (map to login_history) ───────────────────────────
        if ($genLogins) {
            $loginStmt = $pdo->prepare('INSERT INTO login_history (user_id,email,role,success,ip,created_at) VALUES (?,?,?,?,?,?)');
            foreach ($genLogins as $l) {
                $uid = (int)($l['user_id'] ?? 0);
                $loginStmt->execute([
                    $uid ?: null,
                    $userEmailById[$uid] ?? ('user' . $uid . '@example.local'),
                    $userRoleById[$uid] ?? 'unknown',
                    (int)($l['success'] ?? 0),
                    (string)($l['ip'] ?? '0.0.0.0'),
                    $toDateTime($l['login_time'] ?? ''),
                ]);
            }
        }

        // Recoveries: also update case recoveredAmt already set from cases CSV
        audit($pdo, 'Database seeded from seed_data_csv/gen_*.csv');
        return;
    }

    // If gen_* seeds are not present, keep legacy behavior (seed only if empty).
    if ((int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn() > 0) return;

    // ── 1. TRANSACTIONS ──────────────────────────────────────────────────────
    $txRows = $readCsv($csvTransactions);
    if ($txRows) {
        $txStmt = $pdo->prepare(
            'INSERT IGNORE INTO transactions
             (id,custId,custName,account,method,merchant,location,device,ip,amount,score,risk,status,dt)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($txRows as $r) {
            // Normalise scientific-notation account numbers back to plain strings
            $account = isset($r['account']) ? (string)$r['account'] : '';
            if (stripos($account, 'E+') !== false || stripos($account, 'E-') !== false) {
                $account = number_format((float)$account, 0, '.', '');
            }
            // Normalise datetime: accept "M/D/YYYY H:MM" or "YYYY-MM-DD HH:MM:SS"
            $dt = $r['dt'] ?? $r['created_at'] ?? date('Y-m-d H:i:s');
            $ts = strtotime($dt);
            $dt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

            $score = (int)($r['score'] ?? 0);
            $txStmt->execute([
                $r['id'],
                $r['custId'],
                $r['custName'],
                $account,
                $r['method'],
                $r['merchant'],
                $r['location'],
                $r['device'],
                $r['ip'],
                (float)$r['amount'],
                $score,
                $r['risk']   ?? riskFromScore($score),
                $r['status'] ?? statusFromScore($score),
                $dt,
            ]);
        }
    }

    // ── 2. CASES ─────────────────────────────────────────────────────────────
    $caseRows = $readCsv($csvCases);
    if ($caseRows) {
        $caseStmt = $pdo->prepare(
            'INSERT IGNORE INTO cases
             (id,custId,txId,opened,status,priority,fraudType,fraudAmt,analyst,resolution,recoveredAmt,sla,slaOverdue)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($caseRows as $r) {
            // Normalise opened date: "M/D/YYYY" → "YYYY-MM-DD"
            $opened = $r['opened'] ?? date('Y-m-d');
            $ts = strtotime($opened);
            $opened = $ts ? date('Y-m-d', $ts) : date('Y-m-d');

            $sla       = $r['sla'] ?? '5 days';
            $slaOverdue = (stripos($sla, 'OVERDUE') !== false) ? 1 : 0;

            $caseStmt->execute([
                $r['id'],
                $r['custId'],
                $r['txId'],
                $opened,
                $r['status'],
                $r['priority'],
                $r['fraudType'],
                (float)$r['fraudAmt'],
                $r['analyst'],
                $r['resolution'] ?? 'Pending',
                (float)($r['recoveredAmt'] ?? 0),
                $sla,
                $slaOverdue,
            ]);
        }
    }

    // ── 3. ALERTS ────────────────────────────────────────────────────────────
    $alertRows = $readCsv($csvAlerts);
    if ($alertRows) {
        $alertStmt = $pdo->prepare(
            'INSERT IGNORE INTO alerts
             (id,txId,custName,type,severity,score,analyst,queueStatus,minsOpen,dt)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($alertRows as $r) {
            $dt = $r['dt'] ?? $r['created_at'] ?? date('Y-m-d H:i:s');
            $ts = strtotime($dt);
            $dt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

            $alertStmt->execute([
                $r['id'],
                $r['txId'],
                $r['custName'],
                $r['type'],
                $r['severity'],
                (int)$r['score'],
                $r['analyst'],
                $r['queueStatus'],
                (int)($r['minsOpen'] ?? 0),
                $dt,
            ]);
        }
    }

    audit($pdo, 'Database seeded from CSV files (transactions, cases, alerts)');
}

function rows(PDO $pdo, string $sql, array $params = []): array { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
function one(PDO $pdo, string $sql, array $params = []) { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $pdo = getDB();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'session':
            jsonOut(['user' => currentUser()]);
        case 'logout':
            session_destroy();
            jsonOut(['success' => true]);
        case 'login':
            $email = trim($_POST['email'] ?? ''); $pass = $_POST['password'] ?? '';
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?'); $stmt->execute([$email]); $user = $stmt->fetch();
            if ($user && $user['status'] === 'active' && password_verify($pass, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $pdo->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
                logLogin($pdo, ['id'=>$user['id'], 'role'=>$user['role']], $email, true);
                audit($pdo, $user['full_name'] . ' logged in', $user['full_name']);
                jsonOut(['success'=>true,'id'=>(int)$user['id'],'name'=>$user['full_name'],'email'=>$user['email'],'role'=>$user['role']]);
            }
            logLogin($pdo, $user ?: null, $email, false);
            jsonOut(['success'=>false,'error'=>'Invalid email or password']);
        case 'signup':
            $name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
            if (!$name || !$email || !$pass) jsonOut(['success'=>false,'error'=>'All fields are required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['success'=>false,'error'=>'Invalid email address']);
            if (strlen($pass)<6) jsonOut(['success'=>false,'error'=>'Password must be at least 6 characters']);
            try {
                $pdo->prepare('INSERT INTO users (full_name,email,password,role,status) VALUES (?,?,?,?,?)')->execute([$name,$email,password_hash($pass,PASSWORD_BCRYPT),'analyst','active']);
                audit($pdo, 'New analyst added: ' . $name, 'system');
                jsonOut(['success'=>true]);
            }
            catch (PDOException $e) { jsonOut(['success'=>false,'error'=>$e->getCode()==='23000'?'Email already registered':'Registration failed']); }
        case 'stats':
            $stats = $pdo->query("SELECT * FROM v_dashboard_stats")->fetch();
            $fraudRate = $stats['tx_total'] ? round(($stats['fraud_events']/$stats['tx_total'])*100, 2) : 0;
            $blockRate = $stats['fraud_events'] ? round(($stats['tx_blocked']/$stats['fraud_events'])*100, 1) : 0;
            jsonOut(['tx_total'=>(int)$stats['tx_total'],'tx_vol'=>(float)$stats['tx_vol'],'fraud_rate'=>$fraudRate,'block_rate'=>$blockRate,'cases_total'=>(int)$stats['cases_total'],'cases_open'=>(int)$stats['cases_open'],'closed_today'=>(int)$stats['closed_today'],'cases_fraud_amt'=>(float)$stats['cases_fraud_amt'],'cases_recovered'=>(float)$stats['cases_recovered'],'alerts_total'=>(int)$stats['alerts_total'],'alerts_open'=>(int)$stats['alerts_open'],'alerts_critical'=>(int)$stats['alerts_critical'],'escalated_today'=>(int)$stats['escalated_today']]);
        case 'charts':
            jsonOut([
                'fraud_types'=>rows($pdo, 'SELECT * FROM v_chart_fraud_types'),
                'daily_trend'=>rows($pdo, 'SELECT * FROM v_chart_daily_trend'),
                'loss_recovery'=>rows($pdo, 'SELECT * FROM v_chart_loss_recovery'),
                'by_channel'=>rows($pdo, 'SELECT * FROM v_chart_by_channel'),
                'cases_weekly'=>rows($pdo, 'SELECT * FROM v_cases_weekly'),
                'recovery_rate'=>rows($pdo, 'SELECT * FROM v_recovery_rate'),
                'cases_monthly'=>rows($pdo, 'SELECT * FROM v_cases_monthly'),
                'analysts'=>rows($pdo, 'SELECT * FROM v_analyst_performance LIMIT 8')
            ]);
        case 'transactions':
            $page=max(1,(int)($_GET['page']??1)); $limit=max(1,min(500,(int)($_GET['limit']??25))); $offset=($page-1)*$limit;
            $where='WHERE (id LIKE ? OR custName LIKE ? OR custId LIKE ?)'; $search='%'.($_GET['search']??'').'%'; $params=[$search,$search,$search];
            foreach (['method','risk','status'] as $f) if (!empty($_GET[$f])) { $where .= " AND $f=?"; $params[]=$_GET[$f]; }
            if (isset($_GET['minScore']) && $_GET['minScore'] !== '') { $where.=' AND score>=?'; $params[]=(int)$_GET['minScore']; }
            $total=(int)one($pdo,"SELECT COUNT(*) FROM transactions $where",$params);
            $stmt=$pdo->prepare("SELECT * FROM transactions $where ORDER BY dt DESC LIMIT $limit OFFSET $offset"); $stmt->execute($params);
            jsonOut(['data'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
        case 'cases':
            $page=max(1,(int)($_GET['page']??1)); $limit=20; $offset=($page-1)*$limit; $search='%'.($_GET['search']??'').'%'; $where='WHERE (id LIKE ? OR custId LIKE ? OR txId LIKE ?)'; $params=[$search,$search,$search];
            if (!empty($_GET['status'])) { $where.=' AND status=?'; $params[]=$_GET['status']; } if (!empty($_GET['priority'])) { $where.=' AND priority=?'; $params[]=$_GET['priority']; }
            $total=(int)one($pdo,"SELECT COUNT(*) FROM cases $where",$params); $stmt=$pdo->prepare("SELECT * FROM cases $where ORDER BY FIELD(priority,'Critical','High','Medium','Low'), opened DESC LIMIT $limit OFFSET $offset"); $stmt->execute($params);
            jsonOut(['data'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
        case 'alerts':
            $page=max(1,(int)($_GET['page']??1)); $limit=25; $offset=($page-1)*$limit; $search='%'.($_GET['search']??'').'%'; $filter=$_GET['filter']??''; $where='WHERE (id LIKE ? OR custName LIKE ? OR txId LIKE ?)'; $params=[$search,$search,$search];
            if ($filter) { $where.=' AND (severity=? OR queueStatus=?)'; $params[]=$filter; $params[]=$filter; }
            $total=(int)one($pdo,"SELECT COUNT(*) FROM alerts $where",$params); $stmt=$pdo->prepare("SELECT * FROM alerts $where ORDER BY FIELD(severity,'Critical','High','Medium','Low'), dt DESC LIMIT $limit OFFSET $offset"); $stmt->execute($params);
            jsonOut(['data'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
        case 'customer':
            $q=trim($_GET['q']??''); if ($q==='random') $q=(string)one($pdo,'SELECT custId FROM transactions ORDER BY RAND() LIMIT 1');
            $like='%'.$q.'%'; $tx=rows($pdo,'SELECT * FROM transactions WHERE custId=? OR custName LIKE ? ORDER BY dt DESC LIMIT 40',[$q,$like]); if (!$tx) jsonOut(['data'=>null]);
            $custId=$tx[0]['custId']; $cases=rows($pdo,'SELECT * FROM cases WHERE custId=? ORDER BY opened DESC LIMIT 1',[$custId]);
            $timeline=rows($pdo,"SELECT dt, id, method, merchant, amount, score, status FROM transactions WHERE custId=? ORDER BY dt DESC LIMIT 8",[$custId]);
            $trend=rows($pdo,"SELECT DATE(dt) day, ROUND(AVG(score),1) score FROM transactions WHERE custId=? AND dt>=DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(dt) ORDER BY day",[$custId]);
            $heat=rows($pdo,"SELECT HOUR(dt) hr, COUNT(*) value FROM transactions WHERE custId=? GROUP BY HOUR(dt)",[$custId]);
            jsonOut(['customer'=>['custId'=>$custId,'custName'=>$tx[0]['custName'],'current_case'=>$cases[0]['id']??'None Active'],'transactions'=>$tx,'timeline'=>$timeline,'trend'=>$trend,'heatmap'=>$heat]);
        case 'admin':
            requireAdmin();
            jsonOut([
                'rules'=>rows($pdo,'SELECT id,name,threshold_value threshold,active,description FROM fraud_rules ORDER BY id'),
                'settings'=>rows($pdo,'SELECT setting_key,setting_value FROM app_settings'),
                'audit'=>rows($pdo,'SELECT action,user_name,created_at FROM audit_log ORDER BY created_at DESC LIMIT 10'),
                'analysts'=>rows($pdo,'SELECT * FROM v_admin_analysts'),
                'login_history'=>rows($pdo,'SELECT * FROM v_recent_login_history LIMIT 15'),
                'tasks'=>rows($pdo,'SELECT * FROM v_assigned_tasks LIMIT 20')
            ]);
        case 'analyst':
            $user = currentUser();
            $name = $user['name'] ?? 'Sarah Abadi';
            $uid = $user['id'] ?? 0;
            jsonOut([
                'cases'=>rows($pdo,'SELECT * FROM cases WHERE analyst=? ORDER BY opened DESC LIMIT 8',[$name]),
                'tasks'=>rows($pdo,"SELECT id,title text,details,status,case_id,created_at FROM analyst_tasks WHERE analyst_id=? ORDER BY FIELD(status,'Assigned','In Progress','Done'), created_at DESC LIMIT 20",[$uid])
            ]);
        case 'assign_task':
            requireAdmin();
            $analystId = (int)($_POST['analyst_id'] ?? 0);
            $caseId = trim($_POST['case_id'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $details = trim($_POST['details'] ?? '');
            if (!$analystId || !$title) jsonOut(['success'=>false,'error'=>'Analyst and task title are required']);
            $stmt = $pdo->prepare('CALL sp_assign_task(?,?,?,?,?)');
            $stmt->execute([$analystId,$caseId,$title,$details,currentUser()['id'] ?? null]);
            $stmt->closeCursor();
            audit($pdo,'Task assigned: ' . $title,currentUser()['name'] ?? 'admin');
            jsonOut(['success'=>true]);
        case 'update_task':
            $user = currentUser();
            if (!$user) jsonOut(['success'=>false,'error'=>'Login required'], 403);
            $taskId = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'In Progress';
            $allowed = ['Assigned','In Progress','Done'];
            if (!$taskId || !in_array($status,$allowed,true)) jsonOut(['success'=>false,'error'=>'Invalid task']);
            $done = $status === 'Done' ? ', completed_at=NOW()' : '';
            $stmt = $pdo->prepare("UPDATE analyst_tasks SET status=? $done WHERE id=? AND analyst_id=?");
            $stmt->execute([$status,$taskId,$user['id']]);
            audit($pdo,'Task #' . $taskId . ' changed to ' . $status,$user['name']);
            jsonOut(['success'=>true]);
        case 'insert_transaction':
            $score=max(0,min(99,(int)($_POST['score']??0)));
            $stmt=$pdo->prepare('CALL sp_insert_transaction(?,?,?,?,?,?,?,?,?,?,@new_tx_id)');
            $stmt->execute([$_POST['custId']??'',trim($_POST['custName']??'New Customer'),$_POST['account']??'',$_POST['method']??'Online',$_POST['merchant']??'Manual Entry',$_POST['location']??'Unknown',$_POST['device']??'Unknown Device',$_POST['ip']??'0.0.0.0',(float)($_POST['amount']??0),$score]);
            $stmt->closeCursor();
            $id=$pdo->query('SELECT @new_tx_id')->fetchColumn();
            audit($pdo,"Transaction $id added",'analyst'); jsonOut(['success'=>true,'id'=>$id]);
        case 'insert_case':
            $priority=$_POST['priority']??'High'; $fraudAmt=(float)($_POST['fraudAmt']??0);
            $current = currentUser();
            $analyst = $_POST['analyst'] ?? ($current['name'] ?? 'Sarah Abadi');
            $stmt=$pdo->prepare('CALL sp_insert_case(?,?,?,?,?,?,?,@new_case_id)');
            $stmt->execute([$_POST['custId']??'CUST-0000',$_POST['txId']??'MANUAL',$_POST['status']??'Open',$priority,$_POST['fraudType']??'Card Fraud',$fraudAmt,$analyst]);
            $stmt->closeCursor();
            $id=$pdo->query('SELECT @new_case_id')->fetchColumn();
            audit($pdo,"Case $id created",$current['name'] ?? 'analyst'); jsonOut(['success'=>true,'id'=>$id]);
        case 'insert_alert':
            $score=max(0,min(99,(int)($_POST['score']??75)));
            $stmt=$pdo->prepare('CALL sp_insert_alert(?,?,?,?,?,?,@new_alert_id)');
            $stmt->execute([$_POST['txId']??'MANUAL',$_POST['custName']??'New Customer',$_POST['type']??'Behavioral',$_POST['severity']??riskFromScore($score),$score,$_POST['analyst']??'Unassigned']);
            $stmt->closeCursor();
            $id=$pdo->query('SELECT @new_alert_id')->fetchColumn();
            audit($pdo,"Alert $id created",'analyst'); jsonOut(['success'=>true,'id'=>$id]);
        case 'update_alert':
            $id=$_POST['id']??''; $status=$_POST['status']??''; if (!$id) jsonOut(['success'=>false,'error'=>'Missing alert id']); $pdo->prepare('UPDATE alerts SET queueStatus=? WHERE id=?')->execute([$status,$id]); audit($pdo,"Alert $id changed to $status",'analyst'); jsonOut(['success'=>true]);
        case 'update_setting':
            requireAdmin();
            $key=$_POST['key']??''; $value=$_POST['value']??''; $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]); audit($pdo,"Setting $key changed to $value",'admin'); jsonOut(['success'=>true]);
        case 'update_rule':
            requireAdmin();
            $id=(int)($_POST['id']??0); $active=(int)($_POST['active']??0); $threshold=(float)($_POST['threshold']??0); $pdo->prepare('UPDATE fraud_rules SET active=?, threshold_value=? WHERE id=?')->execute([$active,$threshold,$id]); audit($pdo,"Rule #$id updated",'admin'); jsonOut(['success'=>true]);
        case 'save_note':
            $pdo->prepare('INSERT INTO analyst_notes (case_id,customer_id,note,analyst) VALUES (?,?,?,?)')->execute([$_POST['case_id']??null,$_POST['customer_id']??null,$_POST['note']??'',$_POST['analyst']??'Sarah Abadi']); audit($pdo,'Analyst note saved','Sarah Abadi'); jsonOut(['success'=>true]);
        default: jsonOut(['error'=>'Unknown action'], 404);
    }
}
