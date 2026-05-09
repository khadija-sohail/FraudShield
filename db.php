<?php
session_start();

define('DB_HOST', getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'fraudshield');

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
        $server = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server->exec('USE `' . str_replace('`', '``', DB_NAME) . '`');

        installSchema($server);
        seedCsvSources($server);
        $pdo = $server;
        return $pdo;
    } catch (PDOException $e) {
        jsonOut(['error' => 'Database connection failed: ' . $e->getMessage()], 500);
    }
}

function installSchema(PDO $pdo): void {
    $schemaPath = __DIR__ . '/fraudshield_schema.sql';
    if (!is_readable($schemaPath)) {
        jsonOut(['error' => 'Missing fraudshield_schema.sql'], 500);
    }

    $schemaHash = hash_file('sha256', $schemaPath);
    try {
        $currentHash = $pdo->query("SELECT seed_value FROM seed_meta WHERE seed_key='schema_hash'")->fetchColumn();
        if ($currentHash === $schemaHash) return;
    } catch (PDOException $e) {
        $currentHash = null;
    }

    foreach (splitSqlFile(file_get_contents($schemaPath)) as $sql) {
        $trimmed = trim($sql);
        if ($trimmed === '' || stripos($trimmed, 'CREATE DATABASE') === 0 || stripos($trimmed, 'USE ') === 0) continue;
        $pdo->exec($trimmed);
    }

    $stmt = $pdo->prepare("INSERT INTO seed_meta (seed_key, seed_value) VALUES ('schema_hash', ?) ON DUPLICATE KEY UPDATE seed_value=VALUES(seed_value)");
    $stmt->execute([$schemaHash]);
}

function splitSqlFile(string $sql): array {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $lines = preg_split('/\R/', $sql);
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (stripos($trimmed, 'DELIMITER ') === 0) {
            $delimiter = trim(substr($trimmed, 10));
            continue;
        }

        if ($delimiter !== ';') {
            if (str_ends_with($trimmed, $delimiter)) {
                $buffer .= substr($line, 0, strrpos($line, $delimiter));
                $statements[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $line . "\n";
            }
            continue;
        }

        $buffer .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $statements[] = substr($buffer, 0, strrpos($buffer, ';'));
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') $statements[] = $buffer;
    return $statements;
}

function seedCsvSources(PDO $pdo): void {
    $seedDir = __DIR__ . '/seed_data_csv';
    $files = [
        'source_users' => ['file' => 'gen_users.csv', 'columns' => ['user_id','full_name','email','password_hash','phone','source_role','source_status','created_at']],
        'source_analysts' => ['file' => 'gen_analysts.csv', 'columns' => ['analyst_id','user_id','department','productivity_score']],
        'source_accounts' => ['file' => 'gen_accounts.csv', 'columns' => ['account_id','user_id','account_number','balance','account_type','created_at']],
        'source_transactions' => ['file' => 'gen_transactions.csv', 'columns' => ['transaction_id','user_id','account_id','method','merchant','location','device','ip_address','amount','fraud_probability','source_status','created_at']],
        'source_fraud_cases' => ['file' => 'gen_fraud_cases.csv', 'columns' => ['case_id','user_id','transaction_id','priority','fraud_type','fraud_amount','assigned_analyst_id','source_status','resolution','recovery_amount','created_at','closed_at']],
        'source_alerts' => ['file' => 'gen_alerts.csv', 'columns' => ['alert_id','transaction_id','severity','alert_type','queue_status','analyst_id','created_at']],
        'source_notes' => ['file' => 'gen_notes.csv', 'columns' => ['note_id','case_id','analyst_id','note_text','created_at']],
        'source_recoveries' => ['file' => 'gen_recoveries.csv', 'columns' => ['recovery_id','case_id','amount','recovered_date']],
        'source_login_activity' => ['file' => 'gen_login_activity.csv', 'columns' => ['login_id','user_id','ip','device','login_time','success']],
        'source_fraud_rules' => ['file' => 'gen_fraud_rules.csv', 'columns' => ['rule_id','rule_name','threshold','active']],
    ];

    if (!is_dir($seedDir)) return;

    $sourceHash = '';
    foreach ($files as $meta) {
        $path = $seedDir . '/' . $meta['file'];
        if (!is_readable($path)) return;
        $sourceHash .= $meta['file'] . ':' . hash_file('sha256', $path) . ';';
    }
    $sourceHash = hash('sha256', $sourceHash);

    $currentHash = $pdo->query("SELECT seed_value FROM seed_meta WHERE seed_key='csv_hash'")->fetchColumn();
    if ($currentHash === $sourceHash && (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn() > 0) return;

    $pdo->exec('CALL sp_reset_source_data()');
    foreach ($files as $table => $meta) {
        importCsv($pdo, $seedDir . '/' . $meta['file'], $table, $meta['columns']);
    }
    $pdo->exec('CALL sp_refresh_app_from_sources()');
    $stmt = $pdo->prepare("INSERT INTO seed_meta (seed_key, seed_value) VALUES ('csv_hash', ?) ON DUPLICATE KEY UPDATE seed_value=VALUES(seed_value)");
    $stmt->execute([$sourceHash]);
}

function importCsv(PDO $pdo, string $path, string $table, array $columns): void {
    $handle = fopen($path, 'r');
    if (!$handle) return;

    $headers = fgetcsv($handle);
    if ($headers) $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $columnList = implode(',', array_map(fn($col) => "`$col`", $columns));
    $stmt = $pdo->prepare("INSERT INTO `$table` ($columnList) VALUES ($placeholders)");

    while (($row = fgetcsv($handle)) !== false) {
        if (!$headers || count($row) !== count($headers)) continue;
        $assoc = array_combine($headers, $row);
        $values = [];
        foreach ($columns as $column) {
            $sourceColumn = match ($column) {
                'source_role' => 'role',
                'source_status' => 'status',
                default => $column,
            };
            $value = $assoc[$sourceColumn] ?? null;
            if (in_array($column, ['created_at','recovered_date','login_time'], true)) {
                $value = normalizeDateTime($value);
            }
            $values[] = $value === '' ? null : $value;
        }
        $stmt->execute($values);
    }

    fclose($handle);
}

function normalizeDateTime($value): string {
    $value = trim((string)$value);
    if ($value === '') return date('Y-m-d H:i:s');
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})$/', $value, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:00";
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

function riskFromScore(int $score): string {
    return $score >= 81 ? 'Critical' : ($score >= 61 ? 'High' : ($score >= 31 ? 'Medium' : 'Low'));
}

function audit(PDO $pdo, string $action, string $user = 'system'): void {
    $stmt = $pdo->prepare('INSERT INTO audit_log (action,user_name) VALUES (?,?)');
    $stmt->execute([$action, $user]);
}

function logLogin(PDO $pdo, ?array $user, string $email, bool $success): void {
    $stmt = $pdo->prepare('INSERT INTO login_history (user_id,email,role,success,ip) VALUES (?,?,?,?,?)');
    $stmt->execute([$user['id'] ?? null, $email, $user['role'] ?? 'unknown', $success ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? 'local']);
}

function rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function one(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

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
            $email = trim($_POST['email'] ?? '');
            $pass = $_POST['password'] ?? '';
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
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
            $name=trim($_POST['name']??'');
            $email=trim($_POST['email']??'');
            $pass=$_POST['password']??'';
            if (!$name || !$email || !$pass) jsonOut(['success'=>false,'error'=>'All fields are required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['success'=>false,'error'=>'Invalid email address']);
            if (strlen($pass)<6) jsonOut(['success'=>false,'error'=>'Password must be at least 6 characters']);
            try {
                $pdo->prepare('INSERT INTO users (full_name,email,password,role,status) VALUES (?,?,?,?,?)')->execute([$name,$email,password_hash($pass,PASSWORD_BCRYPT),'analyst','active']);
                audit($pdo, 'New analyst added: ' . $name, 'system');
                jsonOut(['success'=>true]);
            } catch (PDOException $e) {
                jsonOut(['success'=>false,'error'=>$e->getCode()==='23000'?'Email already registered':'Registration failed']);
            }

        case 'stats':
            $stats = $pdo->query('SELECT * FROM v_dashboard_stats')->fetch();
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
            $page=max(1,(int)($_GET['page']??1));
            $limit=max(1,min(500,(int)($_GET['limit']??25)));
            $offset=($page-1)*$limit;
            $where='WHERE (id LIKE ? OR custName LIKE ? OR custId LIKE ?)';
            $search='%'.($_GET['search']??'').'%';
            $params=[$search,$search,$search];
            foreach (['method','risk','status'] as $f) {
                if (!empty($_GET[$f])) { $where .= " AND $f=?"; $params[]=$_GET[$f]; }
            }
            if (isset($_GET['minScore']) && $_GET['minScore'] !== '') { $where.=' AND score>=?'; $params[]=(int)$_GET['minScore']; }
            $total=(int)one($pdo,"SELECT COUNT(*) FROM transactions $where",$params);
            $stmt=$pdo->prepare("SELECT * FROM transactions $where ORDER BY dt DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            jsonOut(['data'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);

        case 'cases':
            $page=max(1,(int)($_GET['page']??1));
            $limit=20;
            $offset=($page-1)*$limit;
            $search='%'.($_GET['search']??'').'%';
            $where='WHERE (id LIKE ? OR custId LIKE ? OR txId LIKE ?)';
            $params=[$search,$search,$search];
            if (!empty($_GET['status'])) { $where.=' AND status=?'; $params[]=$_GET['status']; }
            if (!empty($_GET['priority'])) { $where.=' AND priority=?'; $params[]=$_GET['priority']; }
            $total=(int)one($pdo,"SELECT COUNT(*) FROM cases $where",$params);
            $stmt=$pdo->prepare("SELECT * FROM cases $where ORDER BY FIELD(priority,'Critical','High','Medium','Low'), opened DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            jsonOut(['data'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);

        case 'alerts':
            $page=max(1,(int)($_GET['page']??1));
            $limit=25;
            $offset=($page-1)*$limit;
            $search='%'.($_GET['search']??'').'%';
            $filter=$_GET['filter']??'';
            $where='WHERE (id LIKE ? OR custName LIKE ? OR txId LIKE ?)';
            $params=[$search,$search,$search];
            if ($filter) { $where.=' AND (severity=? OR queueStatus=?)'; $params[]=$filter; $params[]=$filter; }
            $total=(int)one($pdo,"SELECT COUNT(*) FROM alerts $where",$params);
            $stmt=$pdo->prepare("SELECT * FROM alerts $where ORDER BY FIELD(severity,'Critical','High','Medium','Low'), dt DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            jsonOut(['data'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);

        case 'customer':
            $q=trim($_GET['q']??'');
            if ($q==='random') $q=(string)one($pdo,'SELECT custId FROM transactions ORDER BY RAND() LIMIT 1');
            $like='%'.$q.'%';
            $tx=rows($pdo,'SELECT * FROM transactions WHERE custId=? OR custName LIKE ? ORDER BY dt DESC LIMIT 40',[$q,$like]);
            if (!$tx) jsonOut(['data'=>null]);
            $custId=$tx[0]['custId'];
            $cases=rows($pdo,'SELECT * FROM cases WHERE custId=? ORDER BY opened DESC LIMIT 1',[$custId]);
            $timeline=rows($pdo,'SELECT dt, id, method, merchant, amount, score, status FROM transactions WHERE custId=? ORDER BY dt DESC LIMIT 8',[$custId]);
            $trend=rows($pdo,'SELECT DATE(dt) day, ROUND(AVG(score),1) score FROM transactions WHERE custId=? AND dt>=DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(dt) ORDER BY day',[$custId]);
            $heat=rows($pdo,'SELECT HOUR(dt) hr, COUNT(*) value FROM transactions WHERE custId=? GROUP BY HOUR(dt)',[$custId]);
            jsonOut(['customer'=>['custId'=>$custId,'custName'=>$tx[0]['custName'],'current_case'=>$cases[0]['id']??'None Active'],'transactions'=>$tx,'timeline'=>$timeline,'trend'=>$trend,'heatmap'=>$heat]);

        case 'admin':
            requireAdmin();
            jsonOut([
                'rules'=>rows($pdo,'SELECT id,name,threshold_value threshold,active,description FROM fraud_rules ORDER BY id'),
                'settings'=>rows($pdo,'SELECT setting_key,setting_value FROM app_settings'),
                'audit'=>rows($pdo,'SELECT action,user_name,created_at FROM audit_log ORDER BY created_at DESC LIMIT 10'),
                'analysts'=>rows($pdo,'SELECT * FROM v_admin_analysts'),
                'login_history'=>rows($pdo,'SELECT * FROM v_recent_login_history LIMIT 15'),
                'tasks'=>rows($pdo,'SELECT * FROM v_assigned_tasks LIMIT 20'),
                'imports'=>rows($pdo,'SELECT * FROM v_source_import_counts')
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
            audit($pdo,"Transaction $id added",'analyst');
            jsonOut(['success'=>true,'id'=>$id]);

        case 'insert_case':
            $priority=$_POST['priority']??'High';
            $fraudAmt=(float)($_POST['fraudAmt']??0);
            $current = currentUser();
            $analyst = $_POST['analyst'] ?? ($current['name'] ?? 'Sarah Abadi');
            $stmt=$pdo->prepare('CALL sp_insert_case(?,?,?,?,?,?,?,@new_case_id)');
            $stmt->execute([$_POST['custId']??'CUST-0000',$_POST['txId']??'MANUAL',$_POST['status']??'Open',$priority,$_POST['fraudType']??'Card Fraud',$fraudAmt,$analyst]);
            $stmt->closeCursor();
            $id=$pdo->query('SELECT @new_case_id')->fetchColumn();
            audit($pdo,"Case $id created",$current['name'] ?? 'analyst');
            jsonOut(['success'=>true,'id'=>$id]);

        case 'insert_alert':
            $score=max(0,min(99,(int)($_POST['score']??75)));
            $stmt=$pdo->prepare('CALL sp_insert_alert(?,?,?,?,?,?,@new_alert_id)');
            $stmt->execute([$_POST['txId']??'MANUAL',$_POST['custName']??'New Customer',$_POST['type']??'Behavioral',$_POST['severity']??riskFromScore($score),$score,$_POST['analyst']??'Unassigned']);
            $stmt->closeCursor();
            $id=$pdo->query('SELECT @new_alert_id')->fetchColumn();
            audit($pdo,"Alert $id created",'analyst');
            jsonOut(['success'=>true,'id'=>$id]);

        case 'update_alert':
            $id=$_POST['id']??'';
            $status=$_POST['status']??'';
            if (!$id) jsonOut(['success'=>false,'error'=>'Missing alert id']);
            $pdo->prepare('UPDATE alerts SET queueStatus=? WHERE id=?')->execute([$status,$id]);
            audit($pdo,"Alert $id changed to $status",'analyst');
            jsonOut(['success'=>true]);

        case 'update_setting':
            requireAdmin();
            $key=$_POST['key']??'';
            $value=$_POST['value']??'';
            $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);
            audit($pdo,"Setting $key changed to $value",'admin');
            jsonOut(['success'=>true]);

        case 'update_rule':
            requireAdmin();
            $id=(int)($_POST['id']??0);
            $active=(int)($_POST['active']??0);
            $threshold=(float)($_POST['threshold']??0);
            $pdo->prepare('UPDATE fraud_rules SET active=?, threshold_value=? WHERE id=?')->execute([$active,$threshold,$id]);
            audit($pdo,"Rule #$id updated",'admin');
            jsonOut(['success'=>true]);

        case 'save_note':
            $pdo->prepare('INSERT INTO analyst_notes (case_id,customer_id,note,analyst) VALUES (?,?,?,?)')->execute([$_POST['case_id']??null,$_POST['customer_id']??null,$_POST['note']??'',$_POST['analyst']??'Sarah Abadi']);
            audit($pdo,'Analyst note saved','Sarah Abadi');
            jsonOut(['success'=>true]);

        default:
            jsonOut(['error'=>'Unknown action'], 404);
    }
}
