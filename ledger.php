<?php
// public/ledger.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
check_feature_access('finance_ledger'); // Enforce feature toggle

$logged_in_user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();

// --- 100% RELIABLE EMAIL FETCH ---
$stmt_email = $pdo->prepare("SELECT email FROM users WHERE id = :uid");
$stmt_email->execute([':uid' => $logged_in_user_id]);
$logged_in_email = trim($stmt_email->fetchColumn());

// --- SHARING & PERMISSIONS LOGIC ---
$target_user_id = $logged_in_user_id; 
$permission_level = 'owner'; // 'owner', 'write', or 'read'

// Check if accessing a shared ledger (Case Insensitive)
if (isset($_GET['shared_from']) && is_numeric($_GET['shared_from']) && $_GET['shared_from'] != $logged_in_user_id) {
    $stmt = $pdo->prepare("SELECT permission_level FROM shared_access WHERE owner_id = :owner AND LOWER(shared_with_email) = LOWER(:email) AND app_type = 'ledger'");
    $stmt->execute([':owner' => $_GET['shared_from'], ':email' => $logged_in_email]);
    $shared_perm = $stmt->fetchColumn();
    
    if ($shared_perm) {
        $target_user_id = (int)$_GET['shared_from'];
        $permission_level = $shared_perm; // 'read' or 'write'
    } else {
        die("<div style='padding:50px; text-align:center; color:red; font-family:sans-serif;'>Access Denied. This ledger has not been shared with you.</div>");
    }
}

$is_read_only = ($permission_level === 'read');

// Fetch Ledgers Shared WITH this user to populate the dropdown
$stmt_shares = $pdo->prepare("
    SELECT sa.owner_id, sa.permission_level, u.email 
    FROM shared_access sa 
    JOIN users u ON sa.owner_id = u.id 
    WHERE LOWER(sa.shared_with_email) = LOWER(:email) AND sa.app_type = 'ledger'
");
$stmt_shares->execute([':email' => $logged_in_email]);
$shared_with_me = $stmt_shares->fetchAll(PDO::FETCH_ASSOC);

// --- DYNAMIC DATE LOGIC ---
$current_year = (int)date('Y');
$tab_map = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'June', 7=>'July', 8=>'Aug', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
$current_month_tab = $tab_map[(int)date('n')];

// --- AUTO-HEAL DATABASE SCHEMA ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS yearly_ledgers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ledger_year INT NOT NULL,
        ledger_data LONGTEXT,
        view_count INT DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_year (user_id, ledger_year),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

try { $pdo->exec("ALTER TABLE yearly_ledgers ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ledger_year INT NOT NULL,
        ledger_data LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// Fetch active years for the dropdown (from TARGET user)
$stmt = $pdo->prepare("SELECT ledger_year FROM yearly_ledgers WHERE user_id = :uid AND is_deleted = 0 ORDER BY ledger_year ASC");
$stmt->execute([':uid' => $target_user_id]);
$saved_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($saved_years)) {
    $saved_years = [$current_year];
} elseif (!in_array($current_year, $saved_years)) {
    $saved_years[] = $current_year;
    sort($saved_years);
}

// --- API ENDPOINTS FOR AJAX SPREADSHEET SAVING/LOADING ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // API: SHARING MANAGEMENT
    if ($_GET['api'] === 'get_shares' && $permission_level === 'owner') {
        $stmt = $pdo->prepare("SELECT id, shared_with_email, permission_level FROM shared_access WHERE owner_id = :uid AND app_type = 'ledger'");
        $stmt->execute([':uid' => $logged_in_user_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($_GET['api'] === 'add_share' && $permission_level === 'owner') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("INSERT INTO shared_access (owner_id, shared_with_email, app_type, permission_level) VALUES (:uid, :email, 'ledger', :perm)");
        $stmt->execute([':uid' => $logged_in_user_id, ':email' => trim($payload['email']), ':perm' => $payload['permission']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_GET['api'] === 'remove_share' && $permission_level === 'owner') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("DELETE FROM shared_access WHERE id = :id AND owner_id = :uid");
        $stmt->execute([':id' => $payload['id'], ':uid' => $logged_in_user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['api'] === 'budget_data') {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

        $stmt = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = :year AND budget_month = :month");
        $stmt->execute([':uid' => $target_user_id, ':year' => $year, ':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['budget_data'])) {
            $stmt = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = 0 AND budget_month = 0");
            $stmt->execute([':uid' => $target_user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $budgetResponse = ['categories' => [], 'incomes' => []];

        if ($row && !empty($row['budget_data'])) {
            $data = json_decode($row['budget_data'], true);
            $sections = ['expenses', 'debts', 'debt', 'savings', 'saving'];
            foreach ($sections as $sec) {
                if (isset($data[$sec]) && is_array($data[$sec])) {
                    foreach ($data[$sec] as $item) {
                        $grp = trim($item['group'] ?? 'Uncategorized');
                        if (empty($grp)) $grp = 'Uncategorized';
                        $amt = (float)($item['amount'] ?? 0);

                        $actual_grp = $grp;
                        foreach (array_keys($budgetResponse['categories']) as $existing_cat) {
                            if (strcasecmp($existing_cat, $grp) === 0) { $actual_grp = $existing_cat; break; }
                        }
                        $budgetResponse['categories'][$actual_grp] = ($budgetResponse['categories'][$actual_grp] ?? 0) + $amt;
                    }
                }
            }
            if (isset($data['yearly_subs']) && is_array($data['yearly_subs'])) {
                foreach ($data['yearly_subs'] as $sub) {
                    $grp = 'Yearly Subscriptions';
                    $amt = (float)($sub['annual_amount'] ?? 0) / 12;
                    $budgetResponse['categories'][$grp] = ($budgetResponse['categories'][$grp] ?? 0) + $amt;
                }
            }
            if (isset($data['income']) && is_array($data['income'])) {
                foreach ($data['income'] as $inc) {
                    $name = trim($inc['name'] ?? 'Income');
                    $amt = (float)($inc['amount'] ?? 0);
                    if (!empty($name)) { $budgetResponse['incomes'][$name] = ($budgetResponse['incomes'][$name] ?? 0) + $amt; }
                }
            }
        }
        echo json_encode($budgetResponse);
        exit;
    }

    if ($_GET['api'] === 'load') {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $stmt = $pdo->prepare("SELECT ledger_data FROM yearly_ledgers WHERE user_id = :uid AND ledger_year = :year AND is_deleted = 0");
        $stmt->execute([':uid' => $target_user_id, ':year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['ledger_data'])) {
            echo $row['ledger_data'];
        } else {
            $defaultColumns = [
                ['id' => 'date', 'name' => 'DATE', 'width' => '130px'],
                ['id' => 'item', 'name' => 'ITEM', 'width' => '150px'],
                ['id' => 'method', 'name' => 'ACCOUNT', 'width' => '120px'],
                ['id' => 'category', 'name' => 'CATEGORY / INCOME', 'width' => '160px'],
                ['id' => 'desc', 'name' => 'DESCRIPTION', 'width' => '140px'],
                ['id' => 'amount', 'name' => 'AMOUNT', 'width' => '100px'],
                ['id' => 'total', 'name' => 'TOTAL', 'width' => '100px'],
                ['id' => 'completed', 'name' => 'STATUS', 'width' => '110px']
            ];
            $months = ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec"];
            $emptyData = ['checking_start' => 0, 'cc_start' => 0, 'columns' => $defaultColumns, 'months' => []];
            foreach ($months as $m) {
                $emptyRows = [];
                for($i=0; $i<30; $i++) { $emptyRows[] = ["date"=>"", "item"=>"", "method"=>"Checking", "category"=>"", "desc"=>"", "amount"=>"", "total"=>"", "completed"=>""]; }
                $emptyData['months'][$m] = $emptyRows;
            }
            echo json_encode($emptyData);
        }
        exit;
    }

    if ($_GET['api'] === 'save') {
        if ($is_read_only) { echo json_encode(['status' => 'error', 'message' => 'Read only access.']); exit; }

        $payload = json_decode(file_get_contents('php://input'), true);
        $year = isset($payload['year']) ? (int)$payload['year'] : (int)date('Y');
        
        if (!isset($payload['data']) || !isset($payload['data']['months']) || !is_array($payload['data']['months'])) {
            echo json_encode(['status' => 'error', 'message' => 'Malformed data rejected.']);
            exit;
        }

        $data = json_encode($payload['data']);

        $stmt = $pdo->prepare("INSERT INTO yearly_ledgers (user_id, ledger_year, ledger_data, is_deleted) 
                               VALUES (:uid, :year, :data, 0) 
                               ON DUPLICATE KEY UPDATE ledger_data = :data_update, is_deleted = 0");
        $stmt->execute([':uid' => $target_user_id, ':year' => $year, ':data' => $data, ':data_update' => $data]);
        
        $stmt_hist = $pdo->prepare("SELECT MD5(ledger_data) FROM ledger_history WHERE user_id = :uid AND ledger_year = :year ORDER BY id DESC LIMIT 1");
        $stmt_hist->execute([':uid' => $target_user_id, ':year' => $year]);
        $last_hist_hash = $stmt_hist->fetchColumn();
        $current_hash = md5($data);
        
        if ($last_hist_hash !== $current_hash) {
            $stmt_ins = $pdo->prepare("INSERT INTO ledger_history (user_id, ledger_year, ledger_data) VALUES (:uid, :year, :data)");
            $stmt_ins->execute([':uid' => $target_user_id, ':year' => $year, ':data' => $data]);
            
            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM ledger_history WHERE user_id = :uid AND ledger_year = :year");
            $stmt_count->execute([':uid' => $target_user_id, ':year' => $year]);
            if ($stmt_count->fetchColumn() > 24) {
                $pdo->prepare("DELETE FROM ledger_history WHERE user_id = :uid AND ledger_year = :year ORDER BY id ASC LIMIT 1")->execute([':uid' => $target_user_id, ':year' => $year]);
            }
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['api'] === 'copy') {
        if ($is_read_only) { echo json_encode(['status' => 'error', 'message' => 'Read only access.']); exit; }
        $payload = json_decode(file_get_contents('php://input'), true);
        $source_year = (int)$payload['source_year'];
        $target_year = (int)$payload['target_year'];

        $stmt = $pdo->prepare("SELECT ledger_data FROM yearly_ledgers WHERE user_id = :uid AND ledger_year = :year");
        $stmt->execute([':uid' => $target_user_id, ':year' => $source_year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['ledger_data'])) {
            $data = json_decode($row['ledger_data'], true);
            $data['checking_start'] = 0;
            $data['cc_start'] = 0;
            if (isset($data['months'])) {
                foreach ($data['months'] as $month => &$rows) {
                    foreach ($rows as &$r) {
                        if (isset($r['date']) && !empty(trim($r['date']))) {
                            if (preg_match('/^\d{4}-(\d{2}-\d{2})$/', trim($r['date']), $matches)) {
                                $r['date'] = $target_year . '-' . $matches[1];
                            } else {
                                $r['date'] = '';
                            }
                        }
                        if (isset($r['completed'])) $r['completed'] = 'Scheduled';
                    }
                }
            }

            $new_data = json_encode($data);
            $stmt_insert = $pdo->prepare("INSERT INTO yearly_ledgers (user_id, ledger_year, ledger_data, is_deleted) 
                                          VALUES (:uid, :year, :data, 0) 
                                          ON DUPLICATE KEY UPDATE ledger_data = :data_update, is_deleted = 0");
            $stmt_insert->execute([':uid' => $target_user_id, ':year' => $target_year, ':data' => $new_data, ':data_update' => $new_data]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No data found.']);
        }
        exit;
    }

    if ($_GET['api'] === 'delete_year') {
        if ($is_read_only) { echo json_encode(['status' => 'error', 'message' => 'Read only access.']); exit; }
        $payload = json_decode(file_get_contents('php://input'), true);
        $year = (int)($payload['year'] ?? 0);
        if ($year > 0) {
            $stmt = $pdo->prepare("UPDATE yearly_ledgers SET is_deleted = 1 WHERE user_id = :uid AND ledger_year = :year");
            $stmt->execute([':uid' => $target_user_id, ':year' => $year]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid year.']);
        }
        exit;
    }

    if ($_GET['api'] === 'deleted_years_list') {
        $stmt = $pdo->prepare("SELECT ledger_year FROM yearly_ledgers WHERE user_id = :uid AND is_deleted = 1 ORDER BY ledger_year ASC");
        $stmt->execute([':uid' => $target_user_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        exit;
    }

    if ($_GET['api'] === 'restore_deleted_year') {
        if ($is_read_only) { echo json_encode(['status' => 'error', 'message' => 'Read only access.']); exit; }
        $payload = json_decode(file_get_contents('php://input'), true);
        $year = (int)($payload['year'] ?? 0);
        if ($year > 0) {
            $stmt = $pdo->prepare("UPDATE yearly_ledgers SET is_deleted = 0 WHERE user_id = :uid AND ledger_year = :year");
            $stmt->execute([':uid' => $target_user_id, ':year' => $year]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid year.']);
        }
        exit;
    }

    if ($_GET['api'] === 'history_list') {
        $year = (int)$_GET['year'];
        $stmt = $pdo->prepare("SELECT id, created_at FROM ledger_history WHERE user_id = :uid AND ledger_year = :year ORDER BY id DESC LIMIT 24");
        $stmt->execute([':uid' => $target_user_id, ':year' => $year]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['api'] === 'load_history') {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT ledger_data FROM ledger_history WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $target_user_id]);
        echo $stmt->fetchColumn();
        exit;
    }
}

$page_title = "Annual Ledger - PersonalApps";
include 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    :root {
        color-scheme: dark; 
        --sheet-open: rgba(59, 130, 246, 0.2);
        --sheet-neg: rgba(239, 68, 68, 0.2);
        --sheet-pos: rgba(34, 197, 94, 0.2);
        --sheet-header: var(--accent);
        --sheet-header-text: #ffffff;
        --sheet-total-box: rgba(168, 85, 247, 0.2);
        --sheet-border-heavy: var(--border-color);
    }
    .light-theme {
        color-scheme: light;
        --sheet-open: #a4c2f4;
        --sheet-neg: #ea9999;
        --sheet-pos: #b6d7a8;
        --sheet-header: #4a86e8;
        --sheet-header-text: #000000;
        --sheet-total-box: #8e7cc3;
        --sheet-border-heavy: #000000;
    }

    .container { max-width: 100% !important; padding: 15px !important; }
    
    .spreadsheet-wrapper { background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 6px; font-family: Arial, sans-serif; display: flex; flex-direction: column; height: 60vh; overflow: hidden; }

    /* Updated Toolbar Organizing CSS */
    .toolbar-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
    .toolbar-group { display: flex; align-items: center; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 6px; padding: 2px; }
    .toolbar-group button { margin: 0; }
    .toolbar-group button:hover { background: rgba(128, 128, 128, 0.1) !important; }
    .toolbar-divider { width: 1px; height: 18px; background: var(--border-color); margin: 0 4px; }
    
    #save-status { font-size: 13px; color: var(--text-muted); opacity: 0; transition: opacity 0.3s; font-weight: bold; }
    #save-status.visible { opacity: 1; }
    
    .toolbar-stats { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .global-total-box { background: var(--sheet-total-box); border: 1px solid var(--sheet-border-heavy); padding: 0; display: flex; flex-direction: column; text-align: right; min-width: 100px; border-radius: 4px; overflow: hidden; }
    .global-total-box .title { border-bottom: 1px solid var(--sheet-border-heavy); font-weight: bold; padding: 3px 6px; font-size: 11px; text-align: left; color: var(--text-main); }
    .global-total-box .val { padding: 4px 6px; font-weight: bold; font-size: 14px; color: var(--text-main); }

    .budget-hud { display: flex; gap: 8px; flex-wrap: wrap; padding: 10px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; align-items: stretch; max-height: 140px; overflow-y: auto; margin-bottom: 12px; }
    .budget-hud::-webkit-scrollbar { width: 6px; }
    .budget-hud::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }

    .table-container { flex-grow: 1; overflow: auto; background: var(--bg-color); position: relative; }
    .excel-table { border-collapse: collapse; table-layout: fixed; font-size: 13px; width: max-content; min-width: 100%; }
    .excel-table th, .excel-table td { border: 1px solid var(--border-color); padding: 0; vertical-align: middle; height: 28px; transition: background 0.2s; }
    .header-row td { background: var(--sheet-header); color: var(--sheet-header-text); font-weight: bold; text-align: center; border: 1px solid var(--sheet-border-heavy); padding: 4px 0; position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 0 var(--border-color); }
    .header-row td a { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 4px; border-radius: 4px; transition: 0.2s; }
    .header-row td a:hover { background: rgba(0,0,0,0.2); }

    .excel-table tbody tr:hover td { background-color: rgba(59, 130, 246, 0.05); }

    input.sheet-input, select.sheet-input { width: 100% !important; height: 100% !important; border: none !important; border-radius: 0 !important; padding: 0 6px !important; margin: 0 !important; box-sizing: border-box !important; background: transparent !important; color: var(--text-main) !important; font-family: inherit !important; font-size: 13px !important; }
    input.sheet-input:focus, select.sheet-input:focus { outline: 2px solid var(--accent) !important; outline-offset: -2px !important; background: var(--card-bg) !important; z-index: 20; position: relative; }
    input.sheet-input:disabled, select.sheet-input:disabled { background: transparent !important; cursor: default; }

    select.sheet-input { appearance: none; -webkit-appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="gray"><polygon points="0,0 10,0 5,5"/></svg>') !important; background-repeat: no-repeat !important; background-position: right 6px center !important; padding-right: 20px !important; }
    select.sheet-input:disabled { background-image: none !important; padding-right: 6px !important; }

    select.sheet-input.status-Scheduled { color: var(--text-muted) !important; }
    select.sheet-input.status-Requested { color: #eab308 !important; font-weight: bold; }
    select.sheet-input.status-Complete { color: #22c55e !important; font-weight: bold; }

    input[type="date"].sheet-input::-webkit-calendar-picker-indicator { cursor: pointer; }
    input[type="date"].sheet-input:disabled::-webkit-calendar-picker-indicator { display: none; }

    .bg-open { background-color: var(--sheet-open) !important; }
    .bg-neg { background-color: var(--sheet-neg) !important; }
    .bg-pos { background-color: var(--sheet-pos) !important; }
    .bg-tot-net { background-color: rgba(168, 85, 247, 0.1) !important; } 

    .action-btn { background: transparent; border: none; font-size: 14px; font-weight: bold; color: var(--text-muted); cursor: pointer; padding: 0 6px; transition: 0.2s; outline: none; }
    .action-btn:hover { color: var(--accent); }
    .action-btn.delete:hover { color: var(--danger); }
    .action-btn:disabled { opacity: 0.3; cursor: not-allowed; }

    .tabs-bar { height: 40px; background: var(--card-bg); border-top: 1px solid var(--border-color); display: flex; align-items: center; padding: 0 10px; gap: 5px; overflow-x: auto; flex-shrink: 0; }
    .tab-btn { background: var(--card-bg); border: 1px solid var(--border-color); border-bottom: none; padding: 8px 15px; cursor: pointer; font-size: 13px; color: var(--text-muted); white-space: nowrap; border-radius: 6px 6px 0 0; transition: 0.2s; }
    .tab-btn.active { background: var(--bg-color); color: var(--accent); font-weight: bold; box-shadow: 0 -3px 0 0 var(--accent) inset; border-color: var(--border-color); }
    .tab-btn:hover:not(.active) { background: var(--bg-color); color: var(--text-main); }
    
    .summary-card-yr { flex: 1; min-width: 200px; padding: 20px; background: var(--card-bg); border-radius: 12px; text-align: center; border: 1px solid var(--border-color); }
    .summary-card-yr h3 { color: var(--text-muted); margin: 0 0 10px 0; font-size: 0.9em; text-transform: uppercase; }
    .summary-card-yr .val { font-size: 2.2em; font-weight: bold; }
    
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 15px; }
    .modal-content { background: var(--card-bg); padding: 30px; border-radius: 12px; width: 500px; max-width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid var(--border-color); box-sizing: border-box; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 13px; }
    .modal-header h3 { margin: 0; font-size: 1.3em; color: var(--text-main); } 
    .close-modal { background: none; border: none; font-size: 1.5em; color: var(--text-muted); cursor: pointer; padding: 0; }
    
    #historyList, #shareList { list-style: none; padding: 0; margin: 0; }
    #historyList li, #shareList li { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid var(--border-color); }
    #historyList li:last-child, #shareList li:last-child { border-bottom: none; }
</style>

<div class="toolbar-top">
    <div style="display:flex; gap:12px; align-items:center; flex-wrap: wrap;">
        <h2 style="margin:0; font-size:1.4em; color:var(--text-main);">
            <?= $permission_level === 'owner' ? 'Annual Ledger' : 'Shared Ledger (Read-Only)' ?>
        </h2>
        
        <?php if (!empty($shared_with_me) || isset($_GET['shared_from'])): ?>
        <select onchange="window.location.href=this.value" style="padding: 5px 8px; border-radius: 6px; background:var(--card-bg); color:var(--text-main); border:1px solid var(--border-color); outline:none; font-weight:bold;">
            <option value="ledger.php">👤 My Ledger</option>
            <?php foreach($shared_with_me as $share): 
                $d_name = explode('@', $share['email'])[0]; // Friendly display name
                $is_selected = (isset($_GET['shared_from']) && $_GET['shared_from'] == $share['owner_id']) ? 'selected' : '';
            ?>
                <option value="ledger.php?shared_from=<?= $share['owner_id'] ?>" <?= $is_selected ?>>
                    🤝 <?= htmlspecialchars(ucfirst($d_name)) ?>'s Ledger (<?= ucfirst($share['permission_level']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div style="display: flex; gap: 5px; align-items: center;">
            <select id="year-select" style="padding: 5px 8px; border-radius: 6px; background:var(--card-bg); color:var(--text-main); border:1px solid var(--border-color); outline:none;" onchange="loadYear()">
                <?php foreach($saved_years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $current_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <?php if(!$is_read_only): ?>
            <button onclick="addNewYear()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" title="Add a blank year">➕ Add</button>
            <button onclick="copyYear()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" title="Copy columns and layout to a new year">📋 Copy</button>
            <?php endif; ?>
        </div>
        
        <div style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;" id="standard-toolbar">
            
            <?php if(!$is_read_only): ?>
            <!-- Group 1: History & Settings -->
            <div class="toolbar-group">
                <button onclick="undo()" id="btn-undo" class="btn btn-small" style="font-size:11px; padding: 5px 8px; background: transparent; color: var(--text-main); border: none;" disabled title="Undo (Local)">↩️</button>
                <button onclick="redo()" id="btn-redo" class="btn btn-small" style="font-size:11px; padding: 5px 8px; background: transparent; color: var(--text-main); border: none;" disabled title="Redo (Local)">↪️</button>
                <div class="toolbar-divider"></div>
                <button onclick="openHistoryModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;" title="View Database Version History">🕒 History</button>
                <button onclick="openSettingsModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;" title="Ledger Settings & Danger Zone">⚙️ Settings</button>
                <?php if($permission_level === 'owner'): ?>
                    <button onclick="openShareModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: #22c55e; border: none; font-weight: bold;">🤝 Share</button>
                <?php endif; ?>
            </div>

            <!-- Group 2: Tools -->
            <div class="toolbar-group">
                <button onclick="sortLedgerBy('date', true)" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;" title="Force strictly chronological order">↕️ Sort</button>
                <div class="toolbar-divider"></div>
                <button onclick="openAutoFillModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: #a855f7; border: none; font-weight: bold;">🪄 Auto-Fill</button>
                <button onclick="openSetDateModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;">📅 Set Dates</button>
                <button onclick="openSetStatusModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;">✅ Set Status</button>
            </div>
            
            <!-- Group 3: Data & Search -->
            <div class="toolbar-group">
                <button onclick="document.getElementById('csv-upload').click()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;">📥 Import</button>
                <button onclick="handleCSVExport()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;">📤 Export</button>
                <input type="file" id="csv-upload" accept=".csv" style="display:none;" onchange="handleCSVImport(event)">
                <div class="toolbar-divider"></div>
                <input type="text" id="ledger-search" placeholder="🔍 Search..." oninput="filterTable(this.value)" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); width: 120px; outline:none; font-size: 11px;">
            </div>

            <!-- Group 4: Batch Actions (Hidden by Default) -->
            <div class="toolbar-group" id="batch-action-group" style="display:none; background: rgba(239, 68, 68, 0.1); border-color: var(--danger);">
                <button id="btn-batch-edit" onclick="openBatchEditModal()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; border: none; background: transparent; color: var(--text-main); font-weight: bold;">✏️ Category</button>
                <button id="btn-delete-selected" onclick="deleteSelectedRows()" class="btn btn-small btn-danger" style="font-size:11px; padding: 5px 10px; border: none; font-weight: bold; margin-left:4px;">🗑 Delete</button>
            </div>
            
            <?php else: ?>
            <!-- Read Only Toolbar -->
            <div class="toolbar-group">
                <button onclick="sortLedgerBy('date', true)" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;" title="Force strictly chronological order">↕️ Sort</button>
                <div class="toolbar-divider"></div>
                <button onclick="handleCSVExport()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-main); border: none;">📤 Export</button>
                <div class="toolbar-divider"></div>
                <input type="text" id="ledger-search" placeholder="🔍 Search..." oninput="filterTable(this.value)" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); width: 120px; outline:none; font-size: 11px;">
            </div>
            <?php endif; ?>
        </div>

        <?php if(!$is_read_only): ?><span id="save-status">💾 Saving...</span><?php endif; ?>
    </div>
    
    <div class="toolbar-stats" id="standard-stats">
        <div style="display:flex; align-items:center; gap:6px; color:var(--text-main); font-size: 13px;">
            <strong>CHK Start:</strong>
            <input type="number" id="chk-start" value="0" onchange="pushState(); triggerSave(true)" <?= $is_read_only ? 'disabled' : '' ?> style="padding:4px; width:80px; border-radius:6px; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-main); font-weight:bold; outline:none; font-size: 13px;">
            <strong style="margin-left:5px;">CC Start:</strong>
            <input type="number" id="cc-start" value="0" onchange="pushState(); triggerSave(true)" <?= $is_read_only ? 'disabled' : '' ?> style="padding:4px; width:80px; border-radius:6px; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-main); font-weight:bold; outline:none; font-size: 13px;" title="Enter CC debt as a negative number">
        </div>
        <div class="global-total-box" style="margin-left: auto;">
            <div class="title" id="month-total-title">MONTH NET</div>
            <div class="val" id="month-total-cell">0</div>
        </div>
        <div class="global-total-box" style="background: rgba(34, 197, 94, 0.1);">
            <div class="title">CHK END</div>
            <div class="val" id="chk-end" style="color: #22c55e;">0</div>
        </div>
        <div class="global-total-box" style="background: rgba(239, 68, 68, 0.1);">
            <div class="title">CC END</div>
            <div class="val" id="cc-end" style="color: #ef4444;">0</div>
        </div>
    </div>
</div>

<div class="budget-hud" id="budget-hud-container"></div>

<div class="spreadsheet-wrapper">
    <div class="table-container" id="spreadsheet-view">
        <table class="excel-table" id="ledger-table">
            <thead></thead>
            <tbody id="ledger-body"></tbody>
        </table>
        <?php if(!$is_read_only): ?>
        <div id="master-add-row-container" style="padding: 15px; text-align: center; border-top: 1px dashed var(--border-color);">
            <button onclick="addRow(currentTab, ledgerData.months[currentTab] ? ledgerData.months[currentTab].length - 1 : 0)" class="btn btn-small" style="background: transparent; border: 1px solid var(--border-color); color: var(--text-main);">+ Add New Row</button>
        </div>
        <?php endif; ?>
    </div>
    
    <div id="annual-review-view" style="display:none; padding: 20px; overflow-y: auto; flex-grow: 1; background: var(--bg-color);">
        <p style="text-align:center; font-size:12px; color:var(--text-muted); margin-top:0;">
            <em>Note: Credit Card transactions and internal transfers are excluded from these charts.</em>
        </p>
        <div style="display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap;" id="annual-summary-cards"></div>
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="flex: 1; min-width: 300px; background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); height: 320px;">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 300px; background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); height: 320px;">
                <canvas id="incomeChart"></canvas>
            </div>
            <div style="flex: 1; min-width: 300px; background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); height: 320px;">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
    <div class="tabs-bar" id="tabs-container"></div>
</div>

<?php if(!$is_read_only): ?>
<!-- Share Modal -->
<div id="shareModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">🤝 Share Ledger</h3>
            <button class="close-modal" onclick="closeShareModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">Grant other users access to view or edit this ledger.</p>
        
        <div style="display: flex; gap: 10px; margin-bottom: 20px;">
            <input type="email" id="shareEmail" placeholder="User's Email Address" style="flex:1; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); outline: none;">
            <select id="sharePermission" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); outline: none;">
                <option value="read">Read Only</option>
                <option value="write">Read & Write</option>
            </select>
            <button class="btn" style="background: var(--success); color: white;" onclick="addShare()">Grant</button>
        </div>

        <h4 style="margin-bottom: 10px; color: var(--text-main); border-bottom: 1px solid var(--border-color); padding-bottom: 5px;">Active Shares</h4>
        <div style="background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; max-height: 200px; overflow-y: auto;">
            <ul id="shareList"></ul>
        </div>
    </div>
</div>

<!-- Bulk Set Date Modal -->
<div id="setDateModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">📅 Set Dates</h3>
            <button class="close-modal" onclick="closeSetDateModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">
            Apply a specific date to rows in <strong id="setDateMonthName"></strong>.
        </p>
        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Select Date:</label>
        <input type="date" id="bulkDateInput" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none; box-sizing: border-box;">
        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Apply To:</label>
        <select id="bulkDateScope" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;">
            <option value="all_populated">All Populated Rows in Month</option>
            <option value="empty">Empty Date Rows Only</option>
            <option value="selected">Selected Rows Only</option>
        </select>
        <button class="btn" style="width: 100%; background: var(--accent); color: white;" onclick="applyBulkDate()">Apply Date</button>
    </div>
</div>

<!-- Bulk Set Status Modal -->
<div id="setStatusModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">✅ Set Status</h3>
            <button class="close-modal" onclick="closeSetStatusModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">
            Apply a specific status to rows in <strong id="setStatusMonthName"></strong>.
        </p>
        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Select Status:</label>
        <select id="bulkStatusInput" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;">
            <option value="">-- Clear Status --</option>
            <option value="Scheduled">Scheduled</option>
            <option value="Requested">Requested</option>
            <option value="Complete">Complete</option>
        </select>
        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Apply To:</label>
        <select id="bulkStatusScope" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;">
            <option value="all_populated">All Populated Rows in Month</option>
            <option value="empty">Empty Status Rows Only</option>
            <option value="selected">Selected Rows Only</option>
        </select>
        <button class="btn" style="width: 100%; background: var(--accent); color: white;" onclick="applyBulkStatus()">Apply Status</button>
    </div>
</div>

<!-- Auto-Fill Modal -->
<div id="autoFillModal" class="modal-overlay">
    <div class="modal-content" style="width: 480px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">🪄 Auto-Fill Ledger</h3>
            <button class="close-modal" onclick="closeAutoFillModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-top: 0; margin-bottom: 15px;">
            Automatically generate scheduled ledger entries.
        </p>
        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Apply To:</label>
        <select id="autoFillScope" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;">
            <option value="current">Current Month Only</option>
            <option value="all">All 12 Months</option>
        </select>
        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Data Source:</label>
        <select id="autoFillSource" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;" onchange="toggleCustomFillArea()">
            <option value="budget">Sync from Budget Planner</option>
            <option value="custom">Custom Paste / List</option>
        </select>
        <div id="customFillArea" style="display: none;">
            <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 0.9em;">Paste from Excel/Numbers:</label>
            <textarea id="autoFillText" rows="7" placeholder="Format: Date (Optional) | Item | Account | Amount&#10;&#10;05/15     Netflix     Credit Card    -15.99&#10;05/20     Paycheck    Checking       2000" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none; font-family: monospace; white-space: pre;"></textarea>
        </div>
        <button class="btn" style="width: 100%; background: var(--accent); color: white;" onclick="processAutoFill()">Generate Items</button>
    </div>
</div>

<!-- Batch Edit Modal -->
<div id="batchEditModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">Batch Edit Category</h3>
            <button class="close-modal" onclick="closeBatchEditModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">
            Select a new category for the <span id="batch-edit-count"></span> selected rows.
        </p>
        <select id="batchEditCategory" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;"></select>
        <button class="btn" style="width: 100%; background: var(--accent); color: white;" onclick="applyBatchEdit()">Apply to Selected</button>
    </div>
</div>

<!-- Settings Modal -->
<div id="settingsModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">⚙️ Ledger Settings</h3>
            <button class="close-modal" onclick="closeSettingsModal()">✕</button>
        </div>
        <h4 style="margin-bottom: 5px; color: var(--text-main); font-size: 1em;">Danger Zone</h4>
        <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid var(--danger); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <button onclick="clearYear()" class="btn" style="width: 100%; margin-bottom: 10px; background: transparent; color: var(--danger); border: 1px solid var(--danger);">🧹 Clear Year Data</button>
            <p style="font-size: 11px; color: var(--text-muted); margin-top: 0; margin-bottom: 15px; text-align: center;">Erases all entries for this year but keeps your columns.</p>
            <button onclick="deleteYear()" class="btn btn-danger" style="width: 100%;">🗑️ Delete Year</button>
            <p style="font-size: 11px; color: var(--text-muted); margin-top: 5px; margin-bottom: 0; text-align: center;">Soft deletes this year and hides it from the dropdown.</p>
        </div>
        <h4 style="margin-bottom: 5px; color: var(--text-main); font-size: 1em;">Recovery</h4>
        <div style="background: var(--bg-color); border: 1px solid var(--border-color); padding: 15px; border-radius: 8px;">
            <button onclick="openRecoveryModal()" class="btn" style="width: 100%; background: var(--card-bg); color: var(--text-main); border: 1px solid var(--border-color);">♻️ Recover Deleted Year</button>
        </div>
    </div>
</div>

<!-- Recovery Modal -->
<div id="recoveryModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px; z-index: 1001;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">♻️ Recover Year</h3>
            <button class="close-modal" onclick="closeRecoveryModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">Select a previously deleted year to restore it.</p>
        <select id="recoverYearSelect" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none;"></select>
        <button class="btn" style="width: 100%; background: var(--accent); color: white;" onclick="recoverYear()">Restore Year</button>
    </div>
</div>

<!-- DB History Modal -->
<div id="historyModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.3em; color: var(--text-main);">🕒 Version History</h3>
            <button class="close-modal" onclick="closeHistoryModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">Restore the ledger to a previous auto-saved database snapshot.</p>
        <div style="background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; max-height: 300px; overflow-y: auto;">
            <ul id="historyList"></ul>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // --- THIS IS THE FIX: Automatically append the share ID to all AJAX requests ---
    const sharedFromParam = "<?= isset($_GET['shared_from']) ? '&shared_from=' . (int)$_GET['shared_from'] : '' ?>";
    
    const isReadOnly = <?= $is_read_only ? 'true' : 'false' ?>;
    const tabs = ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec", "All", "Review"];
    const tabMap = {"Jan":1, "Feb":2, "Mar":3, "Apr":4, "May":5, "June":6, "July":7, "Aug":8, "Sept":9, "Oct":10, "Nov":11, "Dec":12};
    let currentTab = "<?= $current_month_tab ?>"; 
    
    const defaultColumns = [
        { id: 'date', name: 'DATE', width: '130px' },
        { id: 'item', name: 'ITEM', width: '150px' },
        { id: 'method', name: 'ACCOUNT', width: '120px' },
        { id: 'category', name: 'CATEGORY / INCOME', width: '160px' },
        { id: 'desc', name: 'DESCRIPTION', width: '140px' },
        { id: 'amount', name: 'AMOUNT', width: '100px' },
        { id: 'total', name: 'TOTAL', width: '100px' },
        { id: 'completed', name: 'STATUS', width: '110px' }
    ];

    let ledgerData = { checking_start: 0, cc_start: 0, columns: [], months: {} };
    let monthStartBalances = {}; 
    let budgetData = { categories: {}, incomes: {} };
    let saveTimeout = null;
    let sortStates = {}; 
    
    // JS UNDO / REDO SYSTEM
    let historyStack = [];
    let historyIndex = -1;
    let isUndoRedo = false;
    
    let monthlyChartInst = null;
    let categoryChartInst = null;
    let incomeChartInst = null;

    // The core smart-filter that protects math and charts from double-counting
    function isCCPaymentCategory(cat) {
        if (!cat) return false;
        let c = String(cat).toLowerCase();
        return c.includes('cc payment') || c.includes('credit card payment') || c === '💳 cc payment';
    }

    document.addEventListener("DOMContentLoaded", () => {
        renderTabs();
        loadYear();
    });

    <?php if($permission_level === 'owner'): ?>
    // --- SHARE MANAGER LOGIC ---
    function openShareModal() {
        document.getElementById('shareModal').style.display = 'flex';
        loadShares();
    }
    
    function closeShareModal() {
        document.getElementById('shareModal').style.display = 'none';
    }

    function loadShares() {
        fetch('ledger.php?api=get_shares')
            .then(res => res.json())
            .then(shares => {
                let html = '';
                shares.forEach(s => {
                    let perm = s.permission_level === 'read' ? 'Read Only' : 'Read/Write';
                    html += `<li>
                        <div style="color:var(--text-main); font-size:13px;">
                            <strong>${s.shared_with_email}</strong> <span style="color:var(--text-muted);">(${perm})</span>
                        </div>
                        <button onclick="removeShare(${s.id})" class="btn btn-small" style="padding:4px 8px; font-size:11px; border:1px solid var(--danger); background:transparent; color:var(--danger);">Revoke</button>
                    </li>`;
                });
                document.getElementById('shareList').innerHTML = html || '<li style="color:var(--text-muted); font-size:12px;">No active shares.</li>';
            });
    }

    function addShare() {
        const email = document.getElementById('shareEmail').value.trim();
        const perm = document.getElementById('sharePermission').value;
        if (!email) return;
        
        fetch('ledger.php?api=add_share', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, permission: perm })
        }).then(res => res.json()).then(res => {
            if(res.status === 'success') {
                document.getElementById('shareEmail').value = '';
                loadShares();
            }
        });
    }

    function removeShare(id) {
        if(!confirm("Revoke this user's access?")) return;
        fetch('ledger.php?api=remove_share', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(res => res.json()).then(res => {
            if(res.status === 'success') loadShares();
        });
    }
    <?php endif; ?>

    // --- ENFORCED DEFAULT SORT BY DATE ---
    function forceSortAllMonthsByDate() {
        if(isReadOnly) return;
        tabs.forEach(m => {
            if (m !== 'Review' && m !== 'All' && ledgerData.months[m]) {
                ledgerData.months[m].sort((a, b) => {
                    if (!a && !b) return 0;
                    if (!a) return 1;
                    if (!b) return -1;
                    
                    let isEmptyA = (!a.item && !a.amount && !a.date && !a.desc && !a.category && (!a.completed || a.completed==='Scheduled'));
                    let isEmptyB = (!b.item && !b.amount && !b.date && !b.desc && !b.category && (!b.completed || b.completed==='Scheduled'));
                    if (isEmptyA && !isEmptyB) return 1;
                    if (!isEmptyA && isEmptyB) return -1;
                    if (isEmptyA && isEmptyB) return 0;

                    let dateA = a.date && String(a.date).trim() !== '' ? String(a.date) : "9999-99-99";
                    let dateB = b.date && String(b.date).trim() !== '' ? String(b.date) : "9999-99-99";
                    return dateA.localeCompare(dateB);
                });
            }
        });
    }

    // --- BULK DATE ENGINE ---
    function openSetDateModal() {
        if (currentTab === 'Review' || currentTab === 'All') {
            if(typeof appAlert === 'function') appAlert("Please switch to a specific month tab to bulk-set dates."); else alert("Please switch to a specific month tab to bulk-set dates.");
            return;
        }
        document.getElementById('setDateMonthName').innerText = currentTab;
        
        let yearNum = document.getElementById('year-select').value;
        let monthNum = String(tabMap[currentTab]).padStart(2, '0');
        document.getElementById('bulkDateInput').value = `${yearNum}-${monthNum}-01`;
        
        const checked = document.querySelectorAll('.row-select-cb:checked');
        if (checked.length > 0) {
            document.getElementById('bulkDateScope').value = 'selected';
        } else {
            document.getElementById('bulkDateScope').value = 'all_populated';
        }

        document.getElementById('setDateModal').style.display = 'flex';
    }

    function closeSetDateModal() {
        document.getElementById('setDateModal').style.display = 'none';
    }

    function applyBulkDate() {
        if(isReadOnly) return;
        const newDate = document.getElementById('bulkDateInput').value;
        const scope = document.getElementById('bulkDateScope').value;

        if (!newDate) {
            if(typeof appAlert === 'function') appAlert("Please select a valid date."); else alert("Please select a valid date.");
            return;
        }

        pushState(); // Save state for Undo
        let updatedCount = 0;

        if (scope === 'selected') {
            const checked = document.querySelectorAll('.row-select-cb:checked');
            checked.forEach(cb => {
                let m = cb.getAttribute('data-month');
                let i = parseInt(cb.getAttribute('data-idx'));
                if(ledgerData.months[m] && ledgerData.months[m][i]) {
                    ledgerData.months[m][i].date = newDate;
                    updatedCount++;
                }
            });
        } else {
            let rows = ledgerData.months[currentTab] || [];
            rows.forEach(row => {
                if (!row) return;
                let hasData = (row.item && String(row.item).trim() !== '') || (row.amount && String(row.amount).trim() !== '') || (row.desc && String(row.desc).trim() !== '');
                let hasDate = (row.date && String(row.date).trim() !== '');

                if (scope === 'all_populated' && hasData) {
                    row.date = newDate;
                    updatedCount++;
                } else if (scope === 'empty' && hasData && !hasDate) {
                    row.date = newDate;
                    updatedCount++;
                }
            });
        }

        closeSetDateModal();
        
        if (updatedCount > 0) {
            forceSortAllMonthsByDate();
            calculateAllTotals();
            renderTable();
            triggerSave(false);
            updateMultiDeleteUI();
            if(typeof appAlert === 'function') appAlert(`Successfully set date for ${updatedCount} rows!`); 
        } else {
            if(typeof appAlert === 'function') appAlert("No rows matched your criteria.");
        }
    }

    // --- BULK STATUS ENGINE ---
    function openSetStatusModal() {
        if (currentTab === 'Review' || currentTab === 'All') {
            if(typeof appAlert === 'function') appAlert("Please switch to a specific month tab to bulk-set statuses."); else alert("Please switch to a specific month tab to bulk-set statuses.");
            return;
        }
        document.getElementById('setStatusMonthName').innerText = currentTab;
        
        const checked = document.querySelectorAll('.row-select-cb:checked');
        if (checked.length > 0) {
            document.getElementById('bulkStatusScope').value = 'selected';
        } else {
            document.getElementById('bulkStatusScope').value = 'all_populated';
        }

        document.getElementById('setStatusModal').style.display = 'flex';
    }

    function closeSetStatusModal() {
        document.getElementById('setStatusModal').style.display = 'none';
    }

    function applyBulkStatus() {
        if(isReadOnly) return;
        const newStatus = document.getElementById('bulkStatusInput').value;
        const scope = document.getElementById('bulkStatusScope').value;

        pushState(); // Save state for Undo
        let updatedCount = 0;

        if (scope === 'selected') {
            const checked = document.querySelectorAll('.row-select-cb:checked');
            checked.forEach(cb => {
                let m = cb.getAttribute('data-month');
                let i = parseInt(cb.getAttribute('data-idx'));
                if(ledgerData.months[m] && ledgerData.months[m][i]) {
                    ledgerData.months[m][i].completed = newStatus;
                    updatedCount++;
                }
            });
        } else {
            let rows = ledgerData.months[currentTab] || [];
            rows.forEach(row => {
                if (!row) return;
                let hasData = (row.item && String(row.item).trim() !== '') || (row.amount && String(row.amount).trim() !== '') || (row.desc && String(row.desc).trim() !== '');
                let hasStatus = (row.completed && String(row.completed).trim() !== '');

                if (scope === 'all_populated' && hasData) {
                    row.completed = newStatus;
                    updatedCount++;
                } else if (scope === 'empty' && hasData && !hasStatus) {
                    row.completed = newStatus;
                    updatedCount++;
                }
            });
        }

        closeSetStatusModal();
        
        if (updatedCount > 0) {
            calculateAllTotals();
            renderTable(); 
            triggerSave(false);
            updateMultiDeleteUI();
            if(typeof appAlert === 'function') appAlert(`Successfully set status for ${updatedCount} rows!`); 
        } else {
            if(typeof appAlert === 'function') appAlert("No rows matched your criteria.");
        }
    }

    // --- UNDO / REDO ENGINE ---
    function pushState() {
        if (isUndoRedo || isReadOnly) return;
        let currentState = JSON.stringify(ledgerData);
        if (historyIndex >= 0 && historyStack[historyIndex] === currentState) return; 
        
        historyStack = historyStack.slice(0, historyIndex + 1);
        historyStack.push(currentState);
        if (historyStack.length > 30) historyStack.shift(); 
        historyIndex = historyStack.length - 1;
        updateUndoRedoUI();
    }

    function undo() {
        if (isReadOnly) return;
        if (historyIndex > 0) {
            let currentState = JSON.stringify(ledgerData);
            if (historyIndex === historyStack.length - 1 && historyStack[historyIndex] !== currentState) {
                historyStack.push(currentState);
                historyIndex++; 
            }
            historyIndex--;
            isUndoRedo = true;
            loadStateFromStack(historyStack[historyIndex]);
            isUndoRedo = false;
            updateUndoRedoUI();
        }
    }

    function redo() {
        if (isReadOnly) return;
        if (historyIndex < historyStack.length - 1) {
            historyIndex++;
            isUndoRedo = true;
            loadStateFromStack(historyStack[historyIndex]);
            isUndoRedo = false;
            updateUndoRedoUI();
        }
    }

    function updateUndoRedoUI() {
        if(isReadOnly) return;
        if(document.getElementById('btn-undo')) document.getElementById('btn-undo').disabled = historyIndex <= 0;
        if(document.getElementById('btn-redo')) document.getElementById('btn-redo').disabled = historyIndex >= historyStack.length - 1;
    }

    function loadStateFromStack(stateStr) {
        ledgerData = processLedgerSchema(stateStr);
        if(document.getElementById('chk-start')) document.getElementById('chk-start').value = ledgerData.checking_start || 0;
        if(document.getElementById('cc-start')) document.getElementById('cc-start').value = ledgerData.cc_start || 0;
        calculateAllTotals();
        if (currentTab === 'Review') renderAnnualReview();
        else { renderHeaders(); renderTable(); }
        triggerSave(false);
    }

    function processLedgerSchema(rawText) {
        let data = rawText;
        let parseAttempts = 0;
        while (typeof data === 'string' && parseAttempts < 5) {
            try { data = JSON.parse(data); } catch(e) { break; }
            parseAttempts++;
        }
        if (!data || typeof data !== 'object' || Array.isArray(data)) { data = { checking_start: 0, cc_start: 0, columns: [], months: {} }; }
        
        if (data.annual_start !== undefined && data.checking_start === undefined) {
            data.checking_start = data.annual_start;
            data.cc_start = 0;
            delete data.annual_start;
        }
        if (data.checking_start === undefined) data.checking_start = 0;
        if (data.cc_start === undefined) data.cc_start = 0;

        if (!data.months || typeof data.months !== 'object') data.months = {};
        if (!data.columns || !Array.isArray(data.columns) || data.columns.length === 0) {
            data.columns = JSON.parse(JSON.stringify(defaultColumns));
        }
        
        data.columns = data.columns.filter(c => c && c.id !== 'income_source' && c.id !== 'check');
        
        if (!data.columns.find(c => c && c.id === 'method')) {
            let itemIndex = data.columns.findIndex(c => c && c.id === 'item');
            if(itemIndex === -1) itemIndex = 0;
            data.columns.splice(itemIndex + 1, 0, { id: 'method', name: 'ACCOUNT', width: '120px' });
        }
        if (!data.columns.find(c => c && c.id === 'category')) {
            let itemIndex = data.columns.findIndex(c => c && c.id === 'method');
            if(itemIndex === -1) itemIndex = 1;
            data.columns.splice(itemIndex + 1, 0, { id: 'category', name: 'CATEGORY / INCOME', width: '160px' });
        } else {
            let catCol = data.columns.find(c => c && c.id === 'category');
            if(catCol) { catCol.name = 'CATEGORY / INCOME'; catCol.width = '160px'; }
        }
        if (!data.columns.find(c => c && c.id === 'completed')) {
            data.columns.push({ id: 'completed', name: 'STATUS', width: '110px' });
        } else {
            let compCol = data.columns.find(c => c && c.id === 'completed');
            if(compCol) { compCol.name = 'STATUS'; compCol.width = '110px'; }
        }
        let dateCol = data.columns.find(c => c && c.id === 'date');
        if (dateCol) {
            let widthInt = parseInt(dateCol.width);
            if (isNaN(widthInt) || widthInt < 130) dateCol.width = '130px';
        }
        return data;
    }

    function fetchBudgetData() {
        if(currentTab === 'Review' || currentTab === 'All') return Promise.resolve();
        const year = document.getElementById('year-select').value;
        const monthNum = tabMap[currentTab];
        return fetch(`ledger.php?api=budget_data&year=${year}&month=${monthNum}${sharedFromParam}`)
            .then(res => res.json())
            .then(data => { budgetData = data; });
    }

    function updateBudgetHUD() {
        const hud = document.getElementById('budget-hud-container');
        if (!hud) return;
        if (currentTab === 'Review' || currentTab === 'All') { hud.style.display = 'none'; return; }
        hud.style.display = 'flex';
        
        let cats = budgetData.categories || {};
        let incomes = budgetData.incomes || {};
        
        if (Object.keys(cats).length === 0 && Object.keys(incomes).length === 0) {
            hud.innerHTML = '<div style="color:var(--text-muted); font-size:0.9em; padding:5px; width:100%; text-align:center;">No budget planned for this month.</div>';
            return;
        }
        
        let actuals = {};
        let actualIncomes = {};
        let rows = ledgerData.months[currentTab] || [];

        let budgetCatsMap = {};
        for (let k in cats) budgetCatsMap[k.toLowerCase()] = k;

        let budgetIncomesMap = {};
        for (let k in incomes) budgetIncomesMap[k.toLowerCase()] = k;

        if (Array.isArray(rows)) {
            rows.forEach(row => {
                if (!row) return;
                
                // IGNORE TRANSFERS IN THE BUDGET
                if (isCCPaymentCategory(row.category)) return;

                let rawCat = trimStr(row.category).toLowerCase();
                let amt = parseFloat(row.amount);
                
                if (!isNaN(amt)) {
                    if (amt < 0) {
                        let absAmt = Math.abs(amt);
                        if (budgetCatsMap[rawCat]) {
                            actuals[budgetCatsMap[rawCat]] = (actuals[budgetCatsMap[rawCat]] || 0) + absAmt;
                        }
                    } else if (amt > 0) {
                        if (budgetIncomesMap[rawCat]) {
                            actualIncomes[budgetIncomesMap[rawCat]] = (actualIncomes[budgetIncomesMap[rawCat]] || 0) + amt;
                        }
                    }
                }
            });
        }
        
        let html = '';
        for (const [inc, planned] of Object.entries(incomes)) {
            let actual = actualIncomes[inc] || 0;
            html += `
                <div style="flex: 1 1 180px; max-width: 240px; background: var(--bg-color); border: 1px solid var(--success); border-radius: 6px; padding: 8px;">
                    <div style="display:flex; justify-content:space-between; font-size:0.8em;">
                        <strong style="color:var(--success); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${inc}">💰 ${inc}</strong>
                        <span style="color:var(--text-muted); font-weight:bold;">$${actual.toFixed(0)} / $${parseFloat(planned).toFixed(0)}</span>
                    </div>
                </div>
            `;
        }

        for (const [cat, planned] of Object.entries(cats)) {
            let actual = actuals[cat] || 0;
            let plannedAmt = parseFloat(planned);
            let pct = plannedAmt > 0 ? (actual / plannedAmt) * 100 : (actual > 0 ? 100 : 0);
            
            let barColor = 'var(--success)';
            if (pct > 85) barColor = 'var(--warning)';
            if (pct > 100) barColor = 'var(--danger)';
            let width = Math.min(100, pct);
            
            html += `
                <div style="flex: 1 1 180px; max-width: 240px; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 6px; padding: 8px;">
                    <div style="display:flex; justify-content:space-between; font-size:0.8em; margin-bottom:4px;">
                        <strong style="color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${cat}">${cat}</strong>
                        <span style="color:var(--text-muted); font-weight:bold;">$${actual.toFixed(0)} / $${plannedAmt.toFixed(0)}</span>
                    </div>
                    <div style="background:var(--card-bg); height:5px; border-radius:3px; overflow:hidden; width:100%;">
                        <div style="background:${barColor}; height:100%; width:${width}%;"></div>
                    </div>
                </div>
            `;
        }
        hud.innerHTML = html;
    }

    function trimStr(str) { return str ? String(str).trim() : ''; }

    function renderTabs() {
        const container = document.getElementById('tabs-container');
        container.innerHTML = ''; 
        tabs.forEach(tab => {
            const btn = document.createElement('button');
            btn.className = `tab-btn ${tab === currentTab ? 'active' : ''}`;
            if(tab === 'Review') { btn.innerHTML = '📊 Review'; btn.style.marginLeft = 'auto'; } 
            else { btn.innerText = tab; }
            btn.onclick = () => { document.getElementById('ledger-search').value = ''; switchTab(tab); };
            container.appendChild(btn);
        });
    }

    function loadYear() {
        const year = document.getElementById('year-select').value;
        document.getElementById('ledger-body').innerHTML = '<tr><td style="padding:20px; text-align:center;">Loading Year...</td></tr>';
        
        fetch(`ledger.php?api=load&year=${year}${sharedFromParam}`)
            .then(res => res.text())
            .then(text => {
                ledgerData = processLedgerSchema(text);
                
                forceSortAllMonthsByDate();
                
                if(document.getElementById('chk-start')) document.getElementById('chk-start').value = ledgerData.checking_start || 0;
                if(document.getElementById('cc-start')) document.getElementById('cc-start').value = ledgerData.cc_start || 0;
                calculateAllTotals();
                
                historyStack = [JSON.stringify(ledgerData)];
                historyIndex = 0;
                updateUndoRedoUI();
                
                if(currentTab === 'Review') {
                    if(document.getElementById('month-total-title')) document.getElementById('month-total-title').innerText = 'YEAR TO DATE';
                    renderAnnualReview();
                } else if (currentTab === 'All') {
                    if(document.getElementById('month-total-title')) document.getElementById('month-total-title').innerText = 'YEAR TO DATE';
                    renderHeaders();
                    renderTable();
                    updateBudgetHUD();
                } else {
                    if(document.getElementById('month-total-title')) document.getElementById('month-total-title').innerText = currentTab.toUpperCase() + ' NET';
                    fetchBudgetData().then(() => {
                        renderHeaders();
                        renderTable();
                        updateBudgetHUD();
                    }).catch(err => {
                        console.error("Budget Fetch Error:", err);
                        renderHeaders();
                        renderTable();
                    });
                }
            })
            .catch(err => {
                console.error("Load Error:", err);
                document.getElementById('ledger-body').innerHTML = '<tr><td style="padding:20px; text-align:center; color: var(--danger);">Failed to load year data.</td></tr>';
            });
    }

    function switchTab(tabName) {
        currentTab = tabName;
        renderTabs();
        
        if (currentTab === 'Review') {
            document.getElementById('spreadsheet-view').style.display = 'none';
            if(!isReadOnly && document.getElementById('standard-toolbar')) document.getElementById('standard-toolbar').style.display = 'none';
            document.getElementById('standard-stats').style.display = 'none';
            document.getElementById('budget-hud-container').style.display = 'none';
            document.getElementById('annual-review-view').style.display = 'block';
            renderAnnualReview();
        } else if (currentTab === 'All') {
            document.getElementById('annual-review-view').style.display = 'none';
            document.getElementById('spreadsheet-view').style.display = 'block';
            if(!isReadOnly && document.getElementById('standard-toolbar')) document.getElementById('standard-toolbar').style.display = 'flex';
            document.getElementById('standard-stats').style.display = 'flex';
            if(document.getElementById('month-total-title')) document.getElementById('month-total-title').innerText = 'YEAR TO DATE';
            renderHeaders();
            renderTable();
            updateBudgetHUD();
        } else {
            document.getElementById('annual-review-view').style.display = 'none';
            document.getElementById('spreadsheet-view').style.display = 'block';
            if(!isReadOnly && document.getElementById('standard-toolbar')) document.getElementById('standard-toolbar').style.display = 'flex';
            document.getElementById('standard-stats').style.display = 'flex';
            if(document.getElementById('month-total-title')) document.getElementById('month-total-title').innerText = currentTab.toUpperCase() + ' NET';
            
            if (ledgerData.months[currentTab] && !isReadOnly) {
                ledgerData.months[currentTab].sort((a, b) => {
                    if (!a && !b) return 0;
                    if (!a) return 1;
                    if (!b) return -1;
                    
                    let isEmptyA = (!a.item && !a.amount && !a.date && !a.desc && !a.category && (!a.completed || a.completed==='Scheduled'));
                    let isEmptyB = (!b.item && !b.amount && !b.date && !b.desc && !b.category && (!b.completed || b.completed==='Scheduled'));
                    if (isEmptyA && !isEmptyB) return 1;
                    if (!isEmptyA && isEmptyB) return -1;
                    if (isEmptyA && isEmptyB) return 0;
                    let dateA = a.date && String(a.date).trim() !== '' ? String(a.date) : "9999-99-99";
                    let dateB = b.date && String(b.date).trim() !== '' ? String(b.date) : "9999-99-99";
                    return dateA.localeCompare(dateB);
                });
            }

            fetchBudgetData().then(() => {
                renderHeaders();
                renderTable();
                updateBudgetHUD();
            });
        }
    }

    function renderAnnualReview() {
        let totalIncome = 0; let totalExpense = 0; let catTotals = {}; let incTotals = {};
        let monthLabels = ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec"];
        let monthlyIncome = []; let monthlyExpense = [];

        monthLabels.forEach(m => {
            let mInc = 0; let mExp = 0;
            if(ledgerData.months[m] && Array.isArray(ledgerData.months[m])) {
                ledgerData.months[m].forEach(row => {
                    if (!row) return;
                    
                    // SMART FILTER: Exclude internal transfers and CC transactions from charts
                    if (isCCPaymentCategory(row.category)) return;
                    if (row.method === 'Credit Card') return; 
                    
                    let amt = parseFloat(row.amount);
                    if(!isNaN(amt)) {
                        if(amt > 0) {
                            mInc += amt; totalIncome += amt;
                            let incCat = trimStr(row.category) || 'Uncategorized';
                            incTotals[incCat] = (incTotals[incCat] || 0) + amt;
                        } else if(amt < 0) {
                            let absAmt = Math.abs(amt);
                            mExp += absAmt; totalExpense += absAmt;
                            let cat = trimStr(row.category) || 'Uncategorized';
                            catTotals[cat] = (catTotals[cat] || 0) + absAmt;
                        }
                    }
                });
            }
            monthlyIncome.push(mInc);
            monthlyExpense.push(mExp);
        });
        
        let net = totalIncome - totalExpense;
        let netColor = net >= 0 ? 'var(--success)' : 'var(--danger)';

        document.getElementById('annual-summary-cards').innerHTML = `
            <div class="summary-card-yr" style="border-top-color: var(--success);">
                <h3>Total Income</h3>
                <div class="val" style="color: var(--success);">$${totalIncome.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
            </div>
            <div class="summary-card-yr" style="border-top-color: var(--danger);">
                <h3>Total Spent</h3>
                <div class="val" style="color: var(--danger);">$${totalExpense.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
            </div>
            <div class="summary-card-yr" style="border-top-color: ${netColor};">
                <h3>Net Savings</h3>
                <div class="val" style="color: ${netColor};">$${net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
            </div>
        `;

        if(monthlyChartInst) monthlyChartInst.destroy();
        if(categoryChartInst) categoryChartInst.destroy();
        if(incomeChartInst) incomeChartInst.destroy();

        const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
        monthlyChartInst = new Chart(ctxMonthly, {
            type: 'bar',
            data: { labels: monthLabels, datasets: [{ label: 'Income', data: monthlyIncome, backgroundColor: '#22c55e', borderRadius: 4 }, { label: 'Expenses', data: monthlyExpense, backgroundColor: '#ef4444', borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8' } }, title: { display: true, text: 'Monthly Cash Flow', color: '#f8fafc', font: { size: 16 } } }, scales: { y: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } }, x: { ticks: { color: '#94a3b8' }, grid: { display: false } } } }
        });

        let chartColors = ['#3b82f6', '#ef4444', '#f59e0b', '#10b981', '#8b5cf6', '#06b6d4', '#f43f5e', '#84cc16', '#d946ef', '#14b8a6', '#64748b', '#eab308'];
        let incLabels = Object.keys(incTotals).sort((a,b) => incTotals[b] - incTotals[a]);
        let incData = incLabels.map(l => incTotals[l]);
        let incColors = ['#10b981', '#3b82f6', '#06b6d4', '#8b5cf6', '#64748b', '#eab308'];

        const ctxIncome = document.getElementById('incomeChart').getContext('2d');
        incomeChartInst = new Chart(ctxIncome, {
            type: 'doughnut',
            data: { labels: incLabels, datasets: [{ data: incData, backgroundColor: incColors, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', boxWidth: 12 } }, title: { display: true, text: 'Income Breakdown', color: '#f8fafc', font: { size: 16 } } } }
        });

        let catLabels = Object.keys(catTotals).sort((a,b) => catTotals[b] - catTotals[a]);
        let catData = catLabels.map(l => catTotals[l]);

        const ctxCategory = document.getElementById('categoryChart').getContext('2d');
        categoryChartInst = new Chart(ctxCategory, {
            type: 'doughnut',
            data: { labels: catLabels, datasets: [{ data: catData, backgroundColor: chartColors, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', boxWidth: 12 } }, title: { display: true, text: 'Expense Breakdown', color: '#f8fafc', font: { size: 16 } } } }
        });
    }

    function openSettingsModal() { document.getElementById('settingsModal').style.display = 'flex'; }
    function closeSettingsModal() { document.getElementById('settingsModal').style.display = 'none'; }

    async function addNewYear() {
        if(isReadOnly) return;
        const newYear = await appPrompt("Enter the new year to add (e.g., 2026):");
        if (!newYear || isNaN(newYear) || newYear.length !== 4) return;
        let yearSelect = document.getElementById('year-select');
        if (!Array.from(yearSelect.options).some(opt => opt.value === newYear)) {
            yearSelect.add(new Option(newYear, newYear));
            let opts = Array.from(yearSelect.options);
            opts.sort((a,b) => parseInt(a.value) - parseInt(b.value));
            yearSelect.innerHTML = '';
            opts.forEach(o => yearSelect.add(o));
        }
        yearSelect.value = newYear;
        loadYear();
    }

    async function clearYear() {
        if(isReadOnly) return;
        const year = document.getElementById('year-select').value;
        const confirmed = await appConfirm(`Are you absolutely sure you want to DELETE ALL DATA for ${year}?\n\nThis will erase all months, amounts, and statuses for this year. This action CANNOT be undone.`);
        if (!confirmed) return;
        
        pushState();
        closeSettingsModal();
        ledgerData.checking_start = 0;
        ledgerData.cc_start = 0;
        if(document.getElementById('chk-start')) document.getElementById('chk-start').value = 0;
        if(document.getElementById('cc-start')) document.getElementById('cc-start').value = 0;
        
        tabs.forEach(m => {
            if (m !== 'Review' && m !== 'All') {
                let emptyRows = [];
                for(let i=0; i<30; i++) {
                    let newRow = {};
                    ledgerData.columns.forEach(col => { 
                        if(col.id === 'method') newRow[col.id] = 'Checking';
                        else if(col.id === 'completed') newRow[col.id] = 'Scheduled';
                        else if(col.id !== 'total') newRow[col.id] = ''; 
                    });
                    emptyRows.push(newRow);
                }
                ledgerData.months[m] = emptyRows;
            }
        });
        calculateAllTotals();
        if (currentTab === 'Review') renderAnnualReview(); else { renderHeaders(); renderTable(); }
        triggerSave(false);
        if(typeof appAlert === 'function') appAlert(`All data for ${year} has been cleared.`); 
    }

    async function deleteYear() {
        if(isReadOnly) return;
        const year = document.getElementById('year-select').value;
        const confirmed = await appConfirm(`Are you sure you want to DELETE the entire ledger for ${year}?\n\nIt will be hidden from the menu, but can be recovered in Settings.`);
        if (!confirmed) return;
        
        closeSettingsModal();
        fetch('ledger.php?api=delete_year' + sharedFromParam, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ year: year })
        }).then(res => res.json()).then(res => {
            if (res.status === 'success') {
                let yearSelect = document.getElementById('year-select');
                yearSelect.remove(yearSelect.selectedIndex);
                if (yearSelect.options.length === 0) {
                    let currentY = new Date().getFullYear();
                    yearSelect.add(new Option(currentY, currentY));
                }
                yearSelect.selectedIndex = 0; 
                loadYear();
                if(typeof appAlert === 'function') appAlert(`Ledger for ${year} deleted.`);
            }
        });
    }

    function openRecoveryModal() {
        closeSettingsModal();
        fetch('ledger.php?api=deleted_years_list' + sharedFromParam)
            .then(res => res.json())
            .then(list => {
                let sel = document.getElementById('recoverYearSelect');
                sel.innerHTML = '';
                if (list.length === 0) {
                    sel.innerHTML = '<option value="">No deleted years found</option>';
                } else {
                    list.forEach(y => sel.add(new Option(y, y)));
                }
                document.getElementById('recoveryModal').style.display = 'flex';
            });
    }
    
    function closeRecoveryModal() { document.getElementById('recoveryModal').style.display = 'none'; }

    function recoverYear() {
        if(isReadOnly) return;
        let year = document.getElementById('recoverYearSelect').value;
        if (!year) return;
        fetch('ledger.php?api=restore_deleted_year' + sharedFromParam, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ year: year })
        }).then(res => res.json()).then(res => {
            if(res.status === 'success') {
                closeRecoveryModal();
                let yearSelect = document.getElementById('year-select');
                if (!Array.from(yearSelect.options).some(opt => opt.value === year)) {
                    yearSelect.add(new Option(year, year));
                    let opts = Array.from(yearSelect.options);
                    opts.sort((a,b) => parseInt(a.value) - parseInt(b.value));
                    yearSelect.innerHTML = '';
                    opts.forEach(o => yearSelect.add(o));
                }
                yearSelect.value = year;
                loadYear();
                if(typeof appAlert === 'function') appAlert(`Successfully recovered ${year}!`);
            }
        });
    }

    function openHistoryModal() {
        const year = document.getElementById('year-select').value;
        fetch(`ledger.php?api=history_list&year=${year}${sharedFromParam}`)
            .then(res => res.json())
            .then(list => {
                let html = '';
                list.forEach(h => {
                    let d = new Date(h.created_at + ' UTC'); 
                    html += `<li>
                        <span style="color:var(--text-main); font-size:13px;">${d.toLocaleString()}</span>
                        <button onclick="restoreHistory(${h.id})" class="btn btn-small" style="padding:4px 8px; font-size:11px; border:1px solid var(--border-color); background:transparent; color:var(--text-main);" ${isReadOnly ? 'disabled' : ''}>Restore</button>
                    </li>`;
                });
                document.getElementById('historyList').innerHTML = html || '<li style="color:var(--text-muted);">No history snapshots found.</li>';
                document.getElementById('historyModal').style.display = 'flex';
            });
    }

    function closeHistoryModal() { document.getElementById('historyModal').style.display = 'none'; }

    async function restoreHistory(id) {
        if(isReadOnly) return;
        if(!(await appConfirm("Restore this database version? Unsaved changes will be lost."))) return;
        fetch(`ledger.php?api=load_history&id=${id}${sharedFromParam}`)
            .then(res => res.text())
            .then(text => {
                pushState();
                closeHistoryModal();
                ledgerData = processLedgerSchema(text);
                if(document.getElementById('chk-start')) document.getElementById('chk-start').value = ledgerData.checking_start || 0;
                if(document.getElementById('cc-start')) document.getElementById('cc-start').value = ledgerData.cc_start || 0;
                calculateAllTotals();
                if (currentTab === 'Review') renderAnnualReview();
                else { renderHeaders(); renderTable(); }
                triggerSave(false);
                if(typeof appAlert === 'function') appAlert("Version Restored.");
            });
    }

    async function copyYear() {
        if(isReadOnly) return;
        const sourceYear = document.getElementById('year-select').value;
        const targetYear = await appPrompt(`Copy ${sourceYear} ledger structure and items to which year? (e.g., 2027)`);
        if (!targetYear || isNaN(targetYear) || targetYear.length !== 4) return;
        
        const confirmed = await appConfirm(`This will copy all columns, items, descriptions, and amounts from ${sourceYear} to ${targetYear} (Dates updated to ${targetYear}, checkmarks cleared). This will OVERWRITE any existing ${targetYear} ledger. Proceed?`);
        if (!confirmed) return;

        fetch('ledger.php?api=copy' + sharedFromParam, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_year: sourceYear, target_year: targetYear })
        })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                let yearSelect = document.getElementById('year-select');
                if (!Array.from(yearSelect.options).some(opt => opt.value === targetYear)) {
                    yearSelect.add(new Option(targetYear, targetYear));
                    let opts = Array.from(yearSelect.options);
                    opts.sort((a,b) => parseInt(a.value) - parseInt(b.value));
                    yearSelect.innerHTML = '';
                    opts.forEach(o => yearSelect.add(o));
                }
                yearSelect.value = targetYear;
                loadYear();
                appAlert(`Successfully cloned layout and items to ${targetYear}!`);
            } else {
                appAlert(res.message || "Error copying year.");
            }
        });
    }

    function handleCSVExport() {
        let monthsToExport = currentTab === 'All' ? ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec"] : [currentTab];
        let csvContent = "";
        let headers = ledgerData.columns.map(col => `"${col.name}"`);
        csvContent += headers.join(",") + "\n";
        
        monthsToExport.forEach(m => {
            if (!ledgerData.months[m]) return;
            ledgerData.months[m].forEach(row => {
                if (!row) return;
                if ((!row.item || row.item.trim() === '') && (!row.amount || row.amount === '')) return;
                let rowData = ledgerData.columns.map(col => {
                    let val = row[col.id] || "";
                    val = val.toString().replace(/"/g, '""');
                    return `"${val}"`;
                });
                csvContent += rowData.join(",") + "\n";
            });
        });

        if (csvContent.split('\n').length <= 1) {
            if (typeof appAlert === 'function') appAlert("No data to export."); return;
        }

        let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        let url = URL.createObjectURL(blob);
        let link = document.createElement("a");
        let year = document.getElementById('year-select').value;
        link.setAttribute("href", url);
        link.setAttribute("download", `Ledger_${currentTab}_${year}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function parseCSVLine(str) {
        let arr = []; let inQuote = false; let col = "";
        for (let i = 0; i < str.length; i++) {
            let c = str[i];
            if (inQuote && c === '"' && str[i+1] === '"') { col += '"'; i++; } 
            else if (c === '"') { inQuote = !inQuote; } 
            else if (c === ',' && !inQuote) { arr.push(col.trim()); col = ""; } 
            else { col += c; }
        }
        arr.push(col.trim());
        return arr;
    }

    async function handleCSVImport(event) {
        if(isReadOnly) return;
        const file = event.target.files[0];
        if (!file) return;

        let scopeChoice = "";
        if (typeof appPrompt === 'function') {
            scopeChoice = await appPrompt(`Import to ALL 12 months or just ${currentTab === 'All' ? 'Jan' : currentTab}?\nType 'ALL' or '1'`, "1");
        } else {
            scopeChoice = prompt(`Import to ALL 12 months or just ${currentTab === 'All' ? 'Jan' : currentTab}?\nType 'ALL' or '1'`, "1");
        }
        
        if (!scopeChoice) { event.target.value = ''; return; }
        
        pushState(); 
        
        const isAll = scopeChoice.trim().toUpperCase() === 'ALL';
        const targetTab = currentTab === 'All' ? 'Jan' : currentTab;

        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const lines = text.split(/\r?\n/);
            if (lines.length < 2) return; 

            let rawHeaders = parseCSVLine(lines[0]).map(h => h.toUpperCase());
            let colMap = [];

            rawHeaders.forEach((headerText, i) => {
                let matchingCol = ledgerData.columns.find(c => c.name.toUpperCase() === headerText);
                if (matchingCol) { colMap[i] = matchingCol.id; } 
                else {
                    if (headerText.includes('DATE')) colMap[i] = 'date';
                    else if (headerText.includes('PAYEE') || headerText.includes('NAME') || headerText.includes('DESC') || headerText.includes('TRANSACTION') || headerText.includes('MEMO') || headerText.includes('TITLE')) {
                        if (!colMap.includes('item')) colMap[i] = 'item'; else if (!colMap.includes('desc')) colMap[i] = 'desc';
                    }
                    else if (headerText.includes('AMOUNT') || headerText.includes('VALUE') || headerText.includes('COST')) {
                        if (!colMap.includes('amount')) colMap[i] = 'amount';
                    }
                    else if (headerText.includes('ACCOUNT') || headerText.includes('METHOD') || headerText.includes('CARD') || headerText.includes('SOURCE')) {
                        if (!colMap.includes('method')) colMap[i] = 'method';
                    }
                    else if (headerText.includes('DEBIT')) colMap[i] = 'debit_fallback';
                    else if (headerText.includes('CREDIT')) colMap[i] = 'credit_fallback';
                    else if (headerText.includes('CAT') || headerText.includes('TYPE') || headerText.includes('GROUP')) colMap[i] = 'category';
                    else if (headerText.includes('STATUS') || headerText.includes('STATE') || headerText.includes('CLEAR')) colMap[i] = 'completed';
                }
            });

            let startIndex = 1;
            if (!colMap.includes('item') && !colMap.includes('amount')) {
                ledgerData.columns.forEach((c, idx) => { colMap[idx] = c.id; });
                if (/^\d/.test(rawHeaders[0]) || !isNaN(parseFloat(rawHeaders[4]))) startIndex = 0; 
            }

            let importedRows = [];
            for (let i = startIndex; i < lines.length; i++) {
                let line = lines[i].trim();
                if (!line) continue;
                
                let cols = parseCSVLine(line);
                if (cols.length > 0) {
                    let newRow = {};
                    ledgerData.columns.forEach(c => newRow[c.id] = (c.id === 'method' ? 'Checking' : ''));
                    let tempDebit = null; let tempCredit = null;

                    cols.forEach((val, idx) => {
                        let colId = colMap[idx];
                        if (colId) {
                            if (colId === 'date' && val !== '') {
                                if (!/^\d{4}-\d{2}-\d{2}$/.test(val)) {
                                    let cleanVal = val.replace(/-/g, '/'); 
                                    let d = new Date(cleanVal);
                                    if (!isNaN(d.getTime())) {
                                        let mm = String(d.getMonth() + 1).padStart(2, '0');
                                        let dd = String(d.getDate()).padStart(2, '0');
                                        val = `${d.getFullYear()}-${mm}-${dd}`;
                                    } else { val = ''; }
                                }
                            } else if (colId === 'debit_fallback') { tempDebit = val; return; } 
                              else if (colId === 'credit_fallback') { tempCredit = val; return; } 
                              else if (colId === 'amount' && val !== '') { val = val.replace(/[^0-9.-]+/g, ""); }
                            newRow[colId] = val;
                        }
                    });

                    if (!colMap.includes('amount')) {
                        if (tempDebit) newRow['amount'] = "-" + Math.abs(parseFloat(tempDebit.replace(/[^0-9.-]+/g, "")) || 0);
                        else if (tempCredit) newRow['amount'] = Math.abs(parseFloat(tempCredit.replace(/[^0-9.-]+/g, "")) || 0);
                    }

                    if (!newRow.item && newRow.desc) { newRow.item = newRow.desc; newRow.desc = ''; }
                    if (!newRow.method) newRow.method = 'Checking';
                    if (newRow.item || newRow.amount || newRow.desc) importedRows.push(newRow);
                }
            }

            if (importedRows.length > 0) {
                let targetMonths = isAll ? tabs.filter(t => t !== 'Review' && t !== 'All') : [targetTab];
                let yearNum = document.getElementById('year-select').value;
                
                targetMonths.forEach(m => {
                    if (!ledgerData.months[m]) ledgerData.months[m] = [];
                    let rowsToInsert = JSON.parse(JSON.stringify(importedRows));
                    let monthNum = String(tabMap[m]).padStart(2, '0');
                    let maxDays = new Date(yearNum, tabMap[m], 0).getDate();
                    
                    rowsToInsert.forEach(row => {
                        if (row.date && row.date !== '') {
                            let parts = row.date.split('-');
                            if (parts.length === 3) {
                                let dayNum = parseInt(parts[2], 10);
                                if (dayNum > maxDays) dayNum = maxDays;
                                let safeDay = String(dayNum).padStart(2, '0');
                                row.date = `${yearNum}-${monthNum}-${safeDay}`;
                            }
                        }
                    });
                    
                    let emptyIdx = ledgerData.months[m].findIndex(r => (!r.item || String(r.item).trim() === '') && (!r.amount || String(r.amount).trim() === ''));
                    if (emptyIdx !== -1) ledgerData.months[m].splice(emptyIdx, rowsToInsert.length, ...rowsToInsert);
                    else ledgerData.months[m].push(...rowsToInsert);
                });

                forceSortAllMonthsByDate();
                calculateAllTotals(); renderTable(); triggerSave(false);
                if(typeof appAlert === 'function') appAlert(`Successfully imported ${importedRows.length} items to ${isAll ? 'ALL months' : targetTab}!`); 
            } else {
                if(typeof appAlert === 'function') appAlert("No valid rows found to import.");
            }
            event.target.value = ''; 
        };
        reader.readAsText(file);
    }

    function openAutoFillModal() {
        if(currentTab === 'Review' || currentTab === 'All') {
            if(typeof appAlert === 'function') appAlert("Please switch to a specific month tab to auto-fill."); else alert("Please switch to a specific month tab to auto-fill.");
            return;
        }
        document.getElementById('autoFillModal').style.display = 'flex';
        document.getElementById('autoFillSource').value = 'budget';
        toggleCustomFillArea();
    }
    
    function closeAutoFillModal() { document.getElementById('autoFillModal').style.display = 'none'; }
    function toggleCustomFillArea() {
        const src = document.getElementById('autoFillSource').value;
        document.getElementById('customFillArea').style.display = src === 'custom' ? 'block' : 'none';
    }
    
    function processAutoFill() {
        if(isReadOnly) return;
        const scope = document.getElementById('autoFillScope').value;
        const source = document.getElementById('autoFillSource').value;
        let itemsToAdd = [];
        
        if (source === 'budget') {
            if (budgetData && budgetData.incomes) {
                for (const [incName, amt] of Object.entries(budgetData.incomes)) {
                    itemsToAdd.push({ date: '', item: incName, method: 'Checking', category: incName, amount: Math.abs(parseFloat(amt) || 0) });
                }
            }
            if (budgetData && budgetData.categories) {
                for (const [catName, amt] of Object.entries(budgetData.categories)) {
                    itemsToAdd.push({ date: '', item: catName, method: 'Checking', category: catName, amount: -Math.abs(parseFloat(amt) || 0) });
                }
            }
        } else {
            const text = document.getElementById('autoFillText').value;
            const lines = text.split('\n');
            lines.forEach(line => {
                line = line.trim();
                if (!line) return;
                
                let date = '';
                let name = '';
                let account = 'Checking';
                let amountStr = '';
                let amount = 0;
                
                if (line.includes('\t')) {
                    let parts = line.split('\t').map(p => p.trim());
                    if (parts.length >= 4) {
                        date = parts[0];
                        name = parts[1];
                        account = parts[2];
                        amountStr = parts[3];
                    } else if (parts.length === 3) {
                        date = parts[0];
                        name = parts[1];
                        amountStr = parts[2];
                    } else if (parts.length === 2) {
                        name = parts[0];
                        amountStr = parts[1];
                    } else {
                        name = parts[0];
                    }
                } else {
                    let tempLine = line;
                    
                    // Match Date at the beginning (e.g. 10/15, 2026-10-15, Oct 15)
                    let dateMatch = tempLine.match(/^(\d{1,4}[-/]\d{1,2}(?:[-/]\d{2,4})?|[A-Za-z]{3,9}\s+\d{1,2}(?:,?\s+\d{4})?)\s+/);
                    if (dateMatch) {
                        date = dateMatch[1];
                        tempLine = tempLine.substring(dateMatch[0].length).trim();
                    }
                    
                    // Match Amount at the end (e.g. -15.99, (50.00), 1200)
                    let amtMatch = tempLine.match(/\s+\$?(-?[\d,]+(?:\.\d{2})?|\([\d,]+(?:\.\d{2})?\))$/);
                    if (amtMatch) {
                        amountStr = amtMatch[1];
                        tempLine = tempLine.substring(0, tempLine.length - amtMatch[0].length).trim();
                    }
                    
                    // Match Account near the end
                    let accMatch = tempLine.match(/\s+(Checking|Credit Card|Credit|Visa|Mastercard|CC|Debit)$/i);
                    if (accMatch) {
                        account = accMatch[1];
                        name = tempLine.substring(0, tempLine.length - accMatch[0].length).trim();
                    } else {
                        name = tempLine.trim();
                    }
                }

                if (typeof amountStr === 'string' && amountStr !== '') {
                    if (amountStr.includes('(') && amountStr.includes(')')) {
                        amount = "-" + amountStr.replace(/[^\d.]/g, '');
                    } else {
                        let sign = amountStr.includes('-') ? '-' : '';
                        amount = sign + amountStr.replace(/[^\d.]/g, '');
                    }
                } else if (typeof amountStr === 'number') {
                    amount = amountStr;
                }
                amount = parseFloat(amount) || 0;

                if (/credit|cc|visa|master/i.test(account)) account = 'Credit Card';
                else account = 'Checking';

                if (date !== '') {
                    let cleanVal = date.replace(/-/g, '/');
                    let mmddMatch = cleanVal.match(/^(\d{1,2})[\/](\d{1,2})$/);
                    let currentYearVal = document.getElementById('year-select') ? document.getElementById('year-select').value : new Date().getFullYear();
                    
                    if (mmddMatch) {
                        // Only Month and Day provided
                        let d = new Date(`${currentYearVal}/${mmddMatch[1]}/${mmddMatch[2]}`);
                        if (!isNaN(d.getTime())) {
                            let mm = String(d.getMonth() + 1).padStart(2, '0');
                            let dd = String(d.getDate()).padStart(2, '0');
                            date = `${d.getFullYear()}-${mm}-${dd}`;
                        } else {
                            date = '';
                        }
                    } else {
                        let d = new Date(cleanVal);
                        if (!isNaN(d.getTime())) {
                            let mm = String(d.getMonth() + 1).padStart(2, '0');
                            let dd = String(d.getDate()).padStart(2, '0');
                            let yy = d.getFullYear();
                            if (yy === 2001 && !cleanVal.includes('2001') && !cleanVal.includes('01')) {
                                yy = currentYearVal;
                            }
                            date = `${yy}-${mm}-${dd}`;
                        } else {
                            date = '';
                        }
                    }
                }

                itemsToAdd.push({ date: date, item: name, method: account, category: '', amount: amount });
            });
        }
        
        if (itemsToAdd.length === 0) {
            if(typeof appAlert === 'function') appAlert("No items found to add. Have you created your Budget Planner yet?"); else alert("No items found to add.");
            return;
        }

        pushState(); 

        let targetMonths = scope === 'all' ? tabs.filter(t => t !== 'Review' && t !== 'All') : [currentTab];
        
        targetMonths.forEach(m => {
            if (!ledgerData.months[m]) ledgerData.months[m] = [];
            itemsToAdd.forEach(newItem => {
                let emptyIdx = ledgerData.months[m].findIndex(r => (!r.item || String(r.item).trim() === '') && (!r.amount || String(r.amount).trim() === ''));
                let rowObj = {};
                ledgerData.columns.forEach(col => {
                    if (col.id === 'date') rowObj[col.id] = newItem.date || '';
                    else if (col.id === 'item') rowObj[col.id] = newItem.item;
                    else if (col.id === 'amount') rowObj[col.id] = newItem.amount;
                    else if (col.id === 'method') rowObj[col.id] = newItem.method;
                    else if (col.id === 'category') rowObj[col.id] = newItem.category;
                    else if (col.id === 'completed') rowObj[col.id] = 'Scheduled';
                    else rowObj[col.id] = '';
                });
                if (emptyIdx !== -1) ledgerData.months[m][emptyIdx] = rowObj;
                else ledgerData.months[m].push(rowObj);
            });
        });
        
        closeAutoFillModal(); 
        forceSortAllMonthsByDate();
        calculateAllTotals(); 
        renderTable(); 
        triggerSave(false);
        if(typeof appAlert === 'function') appAlert(`Successfully generated ${itemsToAdd.length} items!`); 
    }

    function sortLedgerBy(colId, forceAscending = false) {
        if (currentTab === 'Review' || currentTab === 'All') return;
        
        if (forceAscending) {
            sortStates[colId] = true;
        } else {
            if (sortStates[colId] === undefined) sortStates[colId] = true;
            else sortStates[colId] = !sortStates[colId];
        }
        
        let asc = sortStates[colId];

        if (!ledgerData.months[currentTab]) return;

        pushState(); 

        ledgerData.months[currentTab].sort((a, b) => {
            if (!a && !b) return 0;
            if (!a) return 1;
            if (!b) return -1;

            let isEmptyA = (!a.item && !a.amount && !a.date && !a.desc && !a.category && (!a.completed || a.completed==='Scheduled'));
            let isEmptyB = (!b.item && !b.amount && !b.date && !b.desc && !b.category && (!b.completed || b.completed==='Scheduled'));
            if (isEmptyA && !isEmptyB) return 1;
            if (!isEmptyA && isEmptyB) return -1;
            if (isEmptyA && isEmptyB) return 0;

            if (colId === 'amount') {
                let numA = parseFloat(valA) || 0; let numB = parseFloat(valB) || 0;
                return asc ? numA - numB : numB - numA;
            }
            if (colId === 'date') {
                let valA = a.date && String(a.date).trim() !== '' ? String(a.date) : "9999-99-99";
                let valB = b.date && String(b.date).trim() !== '' ? String(b.date) : "9999-99-99";
                return asc ? valA.localeCompare(valB) : valB.localeCompare(valA);
            }
            let valA = a[colId] || ''; let valB = b[colId] || '';
            return asc ? String(valA).localeCompare(String(valB)) : String(valB).localeCompare(String(valA));
        });
        
        calculateAllTotals(); renderTable(); renderHeaders(); triggerSave(false);
    }

    function renderHeaders() {
        const thead = document.querySelector('#ledger-table thead');
        let headHtml = '<tr class="header-row">';
        headHtml += `<td style="width: 30px; min-width: 30px; background: var(--card-bg); text-align: center; border-right: 1px solid var(--sheet-border-heavy);"><input type="checkbox" id="select-all-cb" onclick="toggleAllRows(this)" style="cursor:pointer; width: 14px; height: 14px; accent-color: var(--accent);" ${isReadOnly ? 'disabled' : ''}></td>`;

        ledgerData.columns.forEach((col, cIdx) => {
            let colNameDisplay = col.name;
            if (currentTab !== 'All' && col.id !== 'desc' && col.id !== 'total') {
                let arrow = sortStates[col.id] === false ? '↓' : (sortStates[col.id] === true ? '↑' : '↕️');
                colNameDisplay = `<a href="javascript:void(0)" onclick="sortLedgerBy('${col.id}')" style="color: inherit; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 4px;" title="Click to Sort by ${col.name}">${col.name} <span style="font-size: 0.8em; opacity: 0.7;">${arrow}</span></a>`;
            }
            headHtml += `<td style="width:${col.width || '100px'}; min-width:${col.width || '100px'};"><div style="display:flex; justify-content:space-between; align-items:center; padding: 0 4px;"><span style="flex-grow:1; text-align:center; display: flex; justify-content: center;">${colNameDisplay}</span>`;
            if(!isReadOnly) {
                headHtml += `<button onclick="removeColumn(${cIdx})" class="action-btn delete" style="color:var(--sheet-header-text); font-size:11px; padding:0;" title="Delete Column">✕</button>`;
            }
            headHtml += `</div></td>`;
        });
        
        headHtml += `<td style="width: 70px; min-width: 70px; background: transparent; border: none;">`;
        if(!isReadOnly) {
            headHtml += `<button onclick="addColumn()" class="action-btn" style="color:var(--accent); background:var(--bg-color); border:1px solid var(--border-color); border-radius:4px; padding:4px 8px; margin-left: 5px;" title="Add Column">+ Col</button>`;
        }
        headHtml += `</td></tr>`;
        thead.innerHTML = headHtml;
    }

    async function addColumn() {
        if(isReadOnly) return;
        let name = await appPrompt("Enter new column name:");
        if (!name) return;
        pushState();
        ledgerData.columns.push({ id: 'col_' + Date.now(), name: name.toUpperCase(), width: '120px' });
        renderHeaders(); renderTable(); triggerSave(true);
    }

    async function removeColumn(index) {
        if(isReadOnly) return;
        const col = ledgerData.columns[index];
        if (col.id === 'amount' || col.id === 'total' || col.id === 'method') {
            if(!(await appConfirm(`Warning: Deleting the ${col.name} column may disable math or tracking features. Are you sure?`))) return;
        } else {
            if(!(await appConfirm(`Delete the ${col.name} column? Data will be lost.`))) return;
        }
        pushState();
        ledgerData.columns.splice(index, 1);
        renderHeaders(); renderTable(); triggerSave(true);
    }

    function toggleAllRows(source) {
        document.querySelectorAll('.row-select-cb').forEach(cb => { if (cb.closest('tr').style.display !== 'none' && !cb.disabled) cb.checked = source.checked; });
        updateMultiDeleteUI();
    }

    function updateMultiDeleteUI() {
        const checked = document.querySelectorAll('.row-select-cb:checked');
        const btnDel = document.getElementById('btn-delete-selected');
        const btnEdit = document.getElementById('btn-batch-edit');
        if (checked.length > 0) {
            if(btnDel) { btnDel.style.display = 'inline-block'; btnDel.innerText = `🗑 Delete (${checked.length})`; }
            if(btnEdit) { btnEdit.style.display = 'inline-block'; btnEdit.innerText = `✏️ Category (${checked.length})`; }
        } else {
            if(btnDel) btnDel.style.display = 'none'; 
            if(btnEdit) btnEdit.style.display = 'none';
        }
        const selectAllCb = document.getElementById('select-all-cb');
        if(selectAllCb) selectAllCb.checked = (checked.length > 0 && checked.length === document.querySelectorAll('.row-select-cb:not([disabled])').length);
    }

    async function deleteSelectedRows() {
        if(isReadOnly) return;
        const checked = document.querySelectorAll('.row-select-cb:checked');
        if (checked.length === 0 || !(await appConfirm(`Delete ${checked.length} selected row(s)?`))) return;
        
        pushState(); 
        
        let toDelete = {};
        checked.forEach(cb => {
            let m = cb.getAttribute('data-month');
            let i = parseInt(cb.getAttribute('data-idx'));
            if(!toDelete[m]) toDelete[m] = [];
            toDelete[m].push(i);
        });
        
        for(let m in toDelete) {
            toDelete[m].sort((a,b) => b - a).forEach(idx => {
                ledgerData.months[m].splice(idx, 1);
            });
        }
        
        calculateAllTotals(); renderTable(); triggerSave(false); updateMultiDeleteUI();
    }

    function openBatchEditModal() {
        if(isReadOnly) return;
        const checked = document.querySelectorAll('.row-select-cb:checked');
        if(checked.length === 0) return;
        
        document.getElementById('batch-edit-count').innerText = checked.length;
        
        let combinedOptions = ['💳 CC Payment'];
        let cats = Object.keys(budgetData.categories || {});
        let incs = Object.keys(budgetData.incomes || {});
        cats.forEach(c => { if(!combinedOptions.includes(c)) combinedOptions.push(c); });
        incs.forEach(i => { if(!combinedOptions.includes(i)) combinedOptions.push(i); });
        combinedOptions.sort((a,b) => a.localeCompare(b));
        
        let selectHtml = '<option value="">-- Clear Category --</option>';
        combinedOptions.forEach(opt => { selectHtml += `<option value="${opt}">${opt}</option>`; });
        
        document.getElementById('batchEditCategory').innerHTML = selectHtml;
        document.getElementById('batchEditCategory').value = '';
        document.getElementById('batchEditModal').style.display = 'flex';
    }

    function closeBatchEditModal() { document.getElementById('batchEditModal').style.display = 'none'; }

    function applyBatchEdit() {
        if(isReadOnly) return;
        const checked = document.querySelectorAll('.row-select-cb:checked');
        const newCat = document.getElementById('batchEditCategory').value;
        
        pushState(); 
        
        checked.forEach(cb => {
            let m = cb.getAttribute('data-month');
            let i = parseInt(cb.getAttribute('data-idx'));
            if(ledgerData.months[m][i]) ledgerData.months[m][i].category = newCat;
        });
        closeBatchEditModal(); calculateAllTotals(); renderTable(); triggerSave(false); updateMultiDeleteUI();
    }

    function addRow(month, index) {
        if(isReadOnly) return;
        pushState(); 
        let newRow = {};
        ledgerData.columns.forEach(col => { 
            if(col.id === 'method') {
                newRow[col.id] = 'Checking';
            } else if(col.id !== 'total') {
                if (col.id === 'completed') newRow[col.id] = 'Scheduled'; else newRow[col.id] = '';
            }
        });
        ledgerData.months[month].splice(index + 1, 0, newRow);
        calculateAllTotals(); renderTable(); triggerSave(false);
    }

    async function removeRow(month, index) {
        if(isReadOnly) return;
        if (!(await appConfirm("Delete this row?"))) return;
        pushState(); 
        ledgerData.months[month].splice(index, 1);
        calculateAllTotals(); renderTable(); triggerSave(false);
    }

    function calculateAllTotals() {
        let chk = 0; let cc = 0;
        if (document.getElementById('chk-start')) chk = parseFloat(document.getElementById('chk-start').value) || 0;
        if (document.getElementById('cc-start')) cc = parseFloat(document.getElementById('cc-start').value) || 0;
        
        ledgerData.checking_start = chk;
        ledgerData.cc_start = cc;

        tabs.forEach(tab => {
            if(tab === 'Review' || tab === 'All') return;
            monthStartBalances[tab] = { chk: chk, cc: cc };
            if (ledgerData.months[tab] && Array.isArray(ledgerData.months[tab])) {
                ledgerData.months[tab].forEach(row => {
                    if (!row) return;
                    let amount = parseFloat(row.amount);
                    if (!isNaN(amount) && row.amount !== "") {
                        let isCCPayment = isCCPaymentCategory(row.category);
                        if (row.method === 'Credit Card') {
                            if (isCCPayment) { cc += amount; } 
                            else { cc += amount; }
                        } else {
                            chk += amount;
                            if (isCCPayment) { cc -= amount; }
                        }
                    }
                });
            }
        });
        
        if (document.getElementById('chk-end')) document.getElementById('chk-end').innerText = chk.toFixed(0);
        if (document.getElementById('cc-end')) document.getElementById('cc-end').innerText = cc.toFixed(0);
        
        updateBudgetHUD();
    }

    function renderTable() {
        const tbody = document.getElementById('ledger-body');
        tbody.innerHTML = '';
        
        let rowsToRender = [];
        let isAllTab = currentTab === 'All';
        
        let runChk = 0; let runCC = 0;

        if (isAllTab) {
            if(document.getElementById('chk-start')) runChk = parseFloat(document.getElementById('chk-start').value) || 0;
            if(document.getElementById('cc-start')) runCC = parseFloat(document.getElementById('cc-start').value) || 0;
            const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec"];
            monthNames.forEach(m => {
                if (ledgerData.months[m]) {
                    ledgerData.months[m].forEach((row, idx) => {
                        if (!row) return;
                        if (row.item || String(row.amount).trim() !== '' || row.date || row.desc) {
                            rowsToRender.push({ row: row, month: m, idx: idx });
                        }
                    });
                }
            });
            rowsToRender.sort((a, b) => {
                let dateA = a.row.date && String(a.row.date).trim() !== '' ? String(a.row.date) : "9999-99-99";
                let dateB = b.row.date && String(b.row.date).trim() !== '' ? String(b.row.date) : "9999-99-99";
                return dateA.localeCompare(dateB);
            });
        } else {
            if (!ledgerData.months[currentTab] || !Array.isArray(ledgerData.months[currentTab])) {
                let emptyRows = [];
                for(let i=0; i<30; i++) {
                    let newRow = {};
                    ledgerData.columns.forEach(col => { 
                        if(col.id === 'method') {
                            newRow[col.id] = 'Checking';
                        } else if(col.id !== 'total') {
                            if (col.id === 'completed') newRow[col.id] = 'Scheduled';
                            else newRow[col.id] = '';
                        }
                    });
                    emptyRows.push(newRow);
                }
                ledgerData.months[currentTab] = emptyRows;
            }
            if (monthStartBalances[currentTab]) {
                runChk = monthStartBalances[currentTab].chk;
                runCC = monthStartBalances[currentTab].cc;
            }
            ledgerData.months[currentTab].forEach((row, idx) => {
                if (!row) return;
                rowsToRender.push({ row: row, month: currentTab, idx: idx });
            });
        }

        const totalColors = ['bg-tot-1', 'bg-tot-2', 'bg-tot-3'];
        let colorIndex = 0;

        let combinedOptions = ['💳 CC Payment'];
        let cats = Object.keys(budgetData.categories || {});
        let incs = Object.keys(budgetData.incomes || {});
        cats.forEach(c => { if(!combinedOptions.includes(c)) combinedOptions.push(c); });
        incs.forEach(i => { if(!combinedOptions.includes(i)) combinedOptions.push(i); });
        combinedOptions.sort((a,b) => a.localeCompare(b));

        let currentNetTotal = runChk;
        let finalNetTotal = currentNetTotal;

        rowsToRender.forEach((itemObj, loopIndex) => {
            let row = itemObj.row;
            let month = itemObj.month;
            let index = itemObj.idx;
            
            const tr = document.createElement('tr');
            tr.id = `row-${loopIndex}`;
            
            if (row.date && row.date.trim() !== '') colorIndex = (colorIndex + 1) % totalColors.length;
            let totalBgClass = totalColors[colorIndex];

            let amount = parseFloat(row.amount);
            let hasAmount = !isNaN(amount) && row.amount !== "";
            
            if (hasAmount) {
                let isCCPayment = isCCPaymentCategory(row.category);
                if (row.method === 'Credit Card') {
                    if (isCCPayment) { runCC += amount; } else { runCC += amount; }
                } else {
                    runChk += amount;
                    if (isCCPayment) { runCC -= amount; }
                }
                currentNetTotal = runChk;
            }
            finalNetTotal = currentNetTotal;

            let itemBg = (row.item && row.item.trim().toUpperCase() === "OPEN") ? "bg-open" : "";
            let amountBg = hasAmount ? (amount < 0 ? "bg-neg" : "bg-pos") : "";
            let netBg = "bg-tot-net";
            
            let disabledAttr = isReadOnly ? 'disabled' : '';

            let html = `<td style="text-align:center; background: var(--card-bg); border-right: 1px solid var(--border-color);"><input type="checkbox" class="row-select-cb" data-month="${month}" data-idx="${index}" value="${loopIndex}" onchange="updateMultiDeleteUI()" style="cursor:pointer; width: 14px; height: 14px; accent-color: var(--accent);" ${disabledAttr}></td>`;
            
            ledgerData.columns.forEach(col => {
                let cellValue = row[col.id] || '';
                let align = (col.id === 'amount' || col.id === 'total') ? 'text-align:right;' : (col.id === 'completed' ? 'text-align:center;' : '');
                
                if (col.id === 'date' && cellValue && !/^\d{4}-\d{2}-\d{2}$/.test(cellValue)) {
                    let currentYearVal = document.getElementById('year-select') ? document.getElementById('year-select').value : '';
                    let d = new Date(cellValue + (cellValue.includes(currentYearVal) ? '' : ' ' + currentYearVal));
                    if (!isNaN(d.getTime())) {
                        let mm = String(d.getMonth() + 1).padStart(2, '0');
                        let dd = String(d.getDate()).padStart(2, '0');
                        cellValue = `${d.getFullYear()}-${mm}-${dd}`;
                        row[col.id] = cellValue;
                    }
                }

                if (col.id === 'total') {
                    html += `<td class="cell-total ${netBg}" style="${align} padding-right:4px; font-weight:bold;">${hasAmount || loopIndex === 0 ? currentNetTotal.toFixed(0) : ''}</td>`;
                } else if (col.id === 'amount') {
                    html += `<td class="cell-amount ${amountBg}"><input type="number" step="0.01" class="sheet-input" onfocus="pushState()" value="${cellValue}" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}></td>`;
                } else if (col.id === 'date') {
                    html += `<td><input type="date" class="sheet-input" onfocus="pushState()" value="${cellValue}" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}></td>`;
                } else if (col.id === 'item') {
                    html += `<td class="cell-item ${itemBg}"><input type="text" class="sheet-input" onfocus="pushState()" value="${cellValue}" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}></td>`;
                } else if (col.id === 'method') {
                    html += `<td><select class="sheet-input" data-col="method" onfocus="pushState()" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}>
                        <option value="Checking" ${cellValue === 'Checking' || cellValue === '' ? 'selected' : ''}>Checking</option>
                        <option value="Credit Card" ${cellValue === 'Credit Card' ? 'selected' : ''}>Credit Card</option>
                    </select></td>`;
                } else if (col.id === 'category') {
                    let opts = `<option value=""></option>`;
                    combinedOptions.forEach(opt => { opts += `<option value="${opt}" ${trimStr(cellValue).toLowerCase() === trimStr(opt).toLowerCase() ? 'selected' : ''}>${opt}</option>`; });
                    if (cellValue && !combinedOptions.map(k=>trimStr(k).toLowerCase()).includes(trimStr(cellValue).toLowerCase())) opts += `<option value="${cellValue}" selected>${cellValue}</option>`;
                    html += `<td><select class="sheet-input" data-col="category" onfocus="pushState()" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}>${opts}</select></td>`;
                } else if (col.id === 'completed') {
                    let statuses = ['Scheduled', 'Requested', 'Complete'];
                    let statusClass = cellValue ? 'status-' + cellValue : '';
                    let opts = `<option value=""></option>`;
                    statuses.forEach(s => { opts += `<option value="${s}" ${trimStr(cellValue).toLowerCase() === trimStr(s).toLowerCase() ? 'selected' : ''}>${s}</option>`; });
                    html += `<td><select class="sheet-input ${statusClass}" data-col="completed" onfocus="pushState()" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}>${opts}</select></td>`;
                } else {
                    html += `<td><input type="text" class="sheet-input" onfocus="pushState()" value="${cellValue}" onchange="updateRow('${month}', ${index}, '${col.id}', this.value)" style="${align}" ${disabledAttr}></td>`;
                }
            });

            if (isAllTab) {
                html += `<td style="text-align:center; border:none; background:transparent;">`;
                if(!isReadOnly) html += `<button onclick="removeRow('${month}', ${index})" class="action-btn delete" title="Delete Row">✕</button>`;
                html += `</td>`;
            } else {
                html += `<td style="text-align:center; border:none; background:transparent;">`;
                if(!isReadOnly) {
                    html += `<button onclick="addRow('${month}', ${index})" class="action-btn" title="Add Row Below">+</button>
                             <button onclick="removeRow('${month}', ${index})" class="action-btn delete" title="Delete Row">✕</button>`;
                }
                html += `</td>`;
            }
            tr.innerHTML = html;
            tbody.appendChild(tr);
        });

        const mTotal = document.getElementById('month-total-cell');
        if (mTotal) {
            let initialNet = 0;
            if(document.getElementById('chk-start')) {
                initialNet = (parseFloat(document.getElementById('chk-start').value) || 0);
            }
            if (currentTab !== 'All' && monthStartBalances[currentTab]) {
                initialNet = monthStartBalances[currentTab].chk;
            }
            let netChange = finalNetTotal - initialNet;
            mTotal.innerText = netChange.toFixed(0);
        }
        
        filterTable(document.getElementById('ledger-search').value);
    }

    function updateRow(month, index, field, value) {
        if(isReadOnly) return;
        ledgerData.months[month][index][field] = value;
        if (field === 'amount' || field === 'item' || field === 'date' || field === 'category' || field === 'completed' || field === 'method') {
            calculateAllTotals();
            if (currentTab === 'All') renderTable(); else updateTableVisuals(); 
        }
        triggerSave(false);
    }

    function updateTableVisuals() {
        let currentTotal = monthStartBalances[currentTab] ? monthStartBalances[currentTab].chk : 0;
        let finalTotal = currentTotal;
        const totalColors = ['bg-tot-1', 'bg-tot-2', 'bg-tot-3'];
        let colorIndex = 0;
        
        let runChk = monthStartBalances[currentTab] ? monthStartBalances[currentTab].chk : 0;
        let runCC = monthStartBalances[currentTab] ? monthStartBalances[currentTab].cc : 0;

        let rows = ledgerData.months[currentTab] || [];
        rows.forEach((row, index) => {
            const tr = document.getElementById('row-' + index);
            if (!tr) return;
            if (!row) return;

            if (row.date && row.date.trim() !== '') colorIndex = (colorIndex + 1) % totalColors.length;
            let totalBgClass = totalColors[colorIndex];

            let amount = parseFloat(row.amount);
            let hasAmount = !isNaN(amount) && row.amount !== "";
            
            if (hasAmount) {
                let isCCPayment = isCCPaymentCategory(row.category);
                if (row.method === 'Credit Card') {
                    if (isCCPayment) { runCC += amount; } else { runCC += amount; }
                } else {
                    runChk += amount;
                    if (isCCPayment) { runCC -= amount; }
                }
                currentTotal = runChk;
            }
            finalTotal = currentTotal;

            let itemBg = (row.item && row.item.trim().toUpperCase() === "OPEN") ? "bg-open" : "";
            let amountBg = hasAmount ? (amount < 0 ? "bg-neg" : "bg-pos") : "";

            let cellItem = tr.querySelector('.cell-item');
            if (cellItem) cellItem.className = "cell-item " + itemBg;
            
            let cellAmount = tr.querySelector('.cell-amount');
            if (cellAmount) cellAmount.className = "cell-amount " + amountBg;
            
            let cellTotal = tr.querySelector('.cell-total');
            if (cellTotal) {
                cellTotal.innerText = (hasAmount || index === 0) ? currentTotal.toFixed(0) : '';
            }
            
            let statusSelect = tr.querySelector('select[data-col="completed"]');
            if (statusSelect && row.completed !== undefined) {
                statusSelect.className = "sheet-input " + (row.completed ? 'status-' + row.completed : '');
            }
        });

        const mTotal = document.getElementById('month-total-cell');
        if (mTotal) {
            let initialNet = monthStartBalances[currentTab] ? monthStartBalances[currentTab].chk : 0;
            let netChange = finalTotal - initialNet;
            mTotal.innerText = netChange.toFixed(0);
        }
        updateBudgetHUD();
    }
    
    function filterTable(query) {
        query = query.toLowerCase();
        document.querySelectorAll('#ledger-body tr').forEach(row => {
            if (!row.id || !row.id.startsWith('row-')) { row.style.display = ''; return; }
            let match = false;
            row.querySelectorAll('.sheet-input').forEach(input => { if (input.value.toLowerCase().includes(query)) match = true; });
            row.style.display = match ? '' : 'none';
            if (!match && row.querySelector('.row-select-cb')) row.querySelector('.row-select-cb').checked = false;
        });
        updateMultiDeleteUI();
    }

    function triggerSave(recalcAll = false) {
        if(isReadOnly) return;
        clearTimeout(saveTimeout);
        if(document.getElementById('save-status')) {
            document.getElementById('save-status').innerText = "⏳ Pending...";
            document.getElementById('save-status').classList.add('visible');
        }
        if (recalcAll) { 
            calculateAllTotals(); 
            if (currentTab === 'All') renderTable(); else updateTableVisuals(); 
        }
        saveTimeout = setTimeout(() => { saveData(); }, 500); 
    }

    function saveData() {
        if(isReadOnly) return;
        if(document.getElementById('save-status')) document.getElementById('save-status').innerText = "⏳ Saving...";
        fetch('ledger.php?api=save' + sharedFromParam, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ year: document.getElementById('year-select').value, data: ledgerData })
        })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success' && document.getElementById('save-status')) {
                document.getElementById('save-status').innerText = "💾 Saved";
                setTimeout(() => { 
                    if(document.getElementById('save-status').innerText === "💾 Saved") {
                        document.getElementById('save-status').classList.remove('visible'); 
                    }
                }, 2000);
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>