<?php
// public/ledger.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();

// --- DYNAMIC DATE LOGIC ---
$current_year = date('Y');
$tab_map = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'June', 7=>'July', 8=>'Aug', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
$current_month_tab = $tab_map[(int)date('n')];

// --- AUTO-HEAL DATABASE SCHEMA FOR ANNUAL SPREADSHEET ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS yearly_ledgers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ledger_year INT NOT NULL,
        ledger_data LONGTEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_year (user_id, ledger_year),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// --- API ENDPOINTS FOR AJAX SPREADSHEET SAVING/LOADING ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // FETCH BUDGET CATEGORIES, INCOME STREAMS, AND METHODS FOR HUD
    if ($_GET['api'] === 'budget_data') {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

        $stmt = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = :year AND budget_month = :month");
        $stmt->execute([':uid' => $user_id, ':year' => $year, ':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['budget_data'])) {
            $stmt = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = 0 AND budget_month = 0");
            $stmt->execute([':uid' => $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $budgetResponse = [
            'categories' => [], 
            'incomes' => [],
            'methods' => ['Cash/Debit' => 0, 'Credit Card' => 0]
        ];

        if ($row && !empty($row['budget_data'])) {
            $data = json_decode($row['budget_data'], true);
            
            // Look through all expense-like sections (Expenses, Debts, Savings)
            $sections = ['expenses', 'debts', 'debt', 'savings', 'saving'];
            foreach ($sections as $sec) {
                if (isset($data[$sec]) && is_array($data[$sec])) {
                    foreach ($data[$sec] as $item) {
                        // Extract ONLY the top-level group header (ignoring the specific item name entirely)
                        $grp = trim($item['group'] ?? '');
                        if (empty($grp) && strpos($sec, 'debt') !== false) {
                            $grp = 'Credit Card';
                        } elseif (empty($grp)) {
                            $grp = 'Uncategorized';
                        }
                        
                        $amt = (float)($item['amount'] ?? 0);
                        $method = trim($item['method'] ?? 'Cash/Debit');

                        // 1. Map and Sum the Group Header Only
                        $actual_grp = $grp;
                        foreach (array_keys($budgetResponse['categories']) as $existing_cat) {
                            if (strcasecmp($existing_cat, $grp) === 0) {
                                $actual_grp = $existing_cat; 
                                break;
                            }
                        }
                        $budgetResponse['categories'][$actual_grp] = ($budgetResponse['categories'][$actual_grp] ?? 0) + $amt;

                        // 2. Map Methods
                        $actual_method = $method;
                        foreach (array_keys($budgetResponse['methods']) as $existing_method) {
                            if (strcasecmp($existing_method, $method) === 0) {
                                $actual_method = $existing_method; 
                                break;
                            }
                        }
                        if (isset($budgetResponse['methods'][$actual_method])) {
                            $budgetResponse['methods'][$actual_method] += $amt;
                        } else {
                            $budgetResponse['methods']['Cash/Debit'] += $amt;
                        }
                    }
                }
            }

            // Income Streams
            if (isset($data['income']) && is_array($data['income'])) {
                foreach ($data['income'] as $inc) {
                    $name = trim($inc['name'] ?? 'Income');
                    $amt = (float)($inc['amount'] ?? 0);
                    if (!empty($name)) {
                        $budgetResponse['incomes'][$name] = ($budgetResponse['incomes'][$name] ?? 0) + $amt;
                    }
                }
            }
        }
        echo json_encode($budgetResponse);
        exit;
    }

    if ($_GET['api'] === 'load') {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        $stmt = $pdo->prepare("SELECT ledger_data FROM yearly_ledgers WHERE user_id = :uid AND ledger_year = :year");
        $stmt->execute([':uid' => $user_id, ':year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['ledger_data'])) {
            echo $row['ledger_data'];
        } else {
            $defaultColumns = [
                ['id' => 'date', 'name' => 'DATE', 'width' => '80px'],
                ['id' => 'item', 'name' => 'ITEM', 'width' => '150px'],
                ['id' => 'category', 'name' => 'CATEGORY / INCOME', 'width' => '160px'],
                ['id' => 'method', 'name' => 'METHOD', 'width' => '110px'],
                ['id' => 'desc', 'name' => 'DESCRIPTION', 'width' => '140px'],
                ['id' => 'amount', 'name' => 'AMOUNT', 'width' => '100px'],
                ['id' => 'total', 'name' => 'TOTAL', 'width' => '100px'],
                ['id' => 'completed', 'name' => 'COMPLETED', 'width' => '110px'],
                ['id' => 'ref', 'name' => 'REFERENCE', 'width' => '90px'],
                ['id' => 'check', 'name' => 'CHECK', 'width' => '90px']
            ];
            
            $months = ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec"];
            $emptyData = ['annual_start' => 0, 'columns' => $defaultColumns, 'months' => []];
            
            foreach ($months as $m) {
                $emptyRows = [];
                for($i=0; $i<50; $i++) {
                    $emptyRows[] = ["date"=>"", "item"=>"", "category"=>"", "method"=>"Cash/Debit", "desc"=>"", "amount"=>"", "completed"=>"", "ref"=>"", "check"=>""];
                }
                $emptyData['months'][$m] = $emptyRows;
            }
            echo json_encode($emptyData);
        }
        exit;
    }

    if ($_GET['api'] === 'save') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $year = isset($payload['year']) ? (int)$payload['year'] : (int)date('Y');
        $data = json_encode($payload['data'] ?? []);

        $stmt = $pdo->prepare("INSERT INTO yearly_ledgers (user_id, ledger_year, ledger_data) 
                               VALUES (:uid, :year, :data) 
                               ON DUPLICATE KEY UPDATE ledger_data = :data_update");
        $stmt->execute([
            ':uid' => $user_id, ':year' => $year, ':data' => $data, ':data_update' => $data
        ]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['api'] === 'copy') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $source_year = (int)$payload['source_year'];
        $target_year = (int)$payload['target_year'];

        $stmt = $pdo->prepare("SELECT ledger_data FROM yearly_ledgers WHERE user_id = :uid AND ledger_year = :year");
        $stmt->execute([':uid' => $user_id, ':year' => $source_year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['ledger_data'])) {
            $data = json_decode($row['ledger_data'], true);
            $data['annual_start'] = 0;
            if (isset($data['months'])) {
                foreach ($data['months'] as $month => &$rows) {
                    foreach ($rows as &$r) {
                        if (isset($r['date'])) $r['date'] = '';
                        if (isset($r['completed'])) $r['completed'] = '';
                        if (isset($r['ref'])) $r['ref'] = '';
                        if (isset($r['check'])) $r['check'] = '';
                    }
                }
            }

            $new_data = json_encode($data);
            $stmt_insert = $pdo->prepare("INSERT INTO yearly_ledgers (user_id, ledger_year, ledger_data) 
                                          VALUES (:uid, :year, :data) 
                                          ON DUPLICATE KEY UPDATE ledger_data = :data_update");
            $stmt_insert->execute([
                ':uid' => $user_id, ':year' => $target_year, ':data' => $new_data, ':data_update' => $new_data
            ]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No data found.']);
        }
        exit;
    }
}

$page_title = "Annual Ledger - PersonalApps";
include 'includes/header.php';
?>

<style>
    :root {
        --sheet-open: rgba(59, 130, 246, 0.2);
        --sheet-neg: rgba(239, 68, 68, 0.2);
        --sheet-pos: rgba(34, 197, 94, 0.2);
        --sheet-tot1: rgba(34, 197, 94, 0.1);
        --sheet-tot2: rgba(234, 179, 8, 0.1);
        --sheet-tot3: rgba(168, 85, 247, 0.1);
        --sheet-header: var(--accent);
        --sheet-header-text: #ffffff;
        --sheet-total-box: rgba(168, 85, 247, 0.2);
        --sheet-border-heavy: var(--border-color);
    }
    .light-theme {
        --sheet-open: #a4c2f4;
        --sheet-neg: #ea9999;
        --sheet-pos: #b6d7a8;
        --sheet-tot1: #b6d7a8;
        --sheet-tot2: #fce5cd;
        --sheet-tot3: #fff2cc;
        --sheet-header: #4a86e8;
        --sheet-header-text: #000000;
        --sheet-total-box: #8e7cc3;
        --sheet-border-heavy: #000000;
    }

    .container { max-width: 100% !important; padding: 15px !important; }
    
    .spreadsheet-wrapper { background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 6px; font-family: Arial, sans-serif; display: flex; flex-direction: column; height: 60vh; overflow: hidden; }

    /* Compact non-scrolling Toolbar Top */
    .toolbar-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
    #save-status { font-size: 13px; color: var(--text-muted); opacity: 0; transition: opacity 0.3s; font-weight: bold; }
    #save-status.visible { opacity: 1; }
    
    .toolbar-stats { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .global-total-box { background: var(--sheet-total-box); border: 1px solid var(--sheet-border-heavy); padding: 0; display: flex; flex-direction: column; text-align: right; min-width: 110px; border-radius: 4px; overflow: hidden; }
    .global-total-box .title { border-bottom: 1px solid var(--sheet-border-heavy); font-weight: bold; padding: 3px 6px; font-size: 11px; text-align: left; color: var(--text-main); }
    .global-total-box .val { padding: 4px 6px; font-weight: bold; font-size: 14px; color: var(--text-main); }

    /* Non-scrolling Wrapping HUD Grid */
    .hud-grid { display: grid; grid-template-columns: 3fr 1fr; gap: 12px; margin-bottom: 12px; }
    @media(max-width: 900px) { .hud-grid { grid-template-columns: 1fr; } }

    .budget-hud { display: flex; gap: 8px; flex-wrap: wrap; padding: 10px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; align-items: stretch; max-height: 140px; overflow-y: auto; }
    .budget-hud::-webkit-scrollbar { width: 6px; }
    .budget-hud::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }

    .cc-reconcile-card { background: var(--card-bg); border: 1px solid var(--warning); border-radius: 8px; padding: 10px 15px; display: flex; flex-direction: column; justify-content: center; }
    .cc-reconcile-card h4 { margin: 0 0 5px 0; font-size: 0.8em; color: var(--warning); text-transform: uppercase; letter-spacing: 0.5px; }
    .cc-reconcile-row { display: flex; justify-content: space-between; font-size: 0.9em; font-weight: bold; }

    .table-container { flex-grow: 1; overflow: auto; background: var(--bg-color); position: relative; }
    .excel-table { border-collapse: collapse; table-layout: fixed; font-size: 13px; width: max-content; min-width: 100%; }
    .excel-table th, .excel-table td { border: 1px solid var(--border-color); padding: 0; vertical-align: middle; height: 28px; }
    .header-row td { background: var(--sheet-header); color: var(--sheet-header-text); font-weight: bold; text-align: center; border: 1px solid var(--sheet-border-heavy); padding: 4px 0; position: sticky; top: 0; z-index: 10; box-shadow: 0 1px 0 var(--border-color); }

    input.sheet-input, select.sheet-input { width: 100% !important; height: 100% !important; border: none !important; border-radius: 0 !important; padding: 0 6px !important; margin: 0 !important; box-sizing: border-box !important; background: transparent !important; color: var(--text-main) !important; font-family: inherit !important; font-size: 13px !important; }
    input.sheet-input:focus, select.sheet-input:focus { outline: 2px solid var(--accent) !important; outline-offset: -2px !important; background: var(--card-bg) !important; z-index: 20; position: relative; }

    select.sheet-input { appearance: none; -webkit-appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="gray"><polygon points="0,0 10,0 5,5"/></svg>') !important; background-repeat: no-repeat !important; background-position: right 6px center !important; padding-right: 20px !important; }

    .bg-open { background-color: var(--sheet-open) !important; }
    .bg-neg { background-color: var(--sheet-neg) !important; }
    .bg-pos { background-color: var(--sheet-pos) !important; }
    .bg-tot-1 { background-color: var(--sheet-tot1) !important; } 
    .bg-tot-2 { background-color: var(--sheet-tot2) !important; } 
    .bg-tot-3 { background-color: var(--sheet-tot3) !important; } 

    .action-btn { background: transparent; border: none; font-size: 14px; font-weight: bold; color: var(--text-muted); cursor: pointer; padding: 0 6px; transition: 0.2s; outline: none; }
    .action-btn:hover { color: var(--accent); }
    .action-btn.delete:hover { color: var(--danger); }

    .tabs-bar { height: 40px; background: var(--card-bg); border-top: 1px solid var(--border-color); display: flex; align-items: center; padding: 0 10px; gap: 5px; overflow-x: auto; flex-shrink: 0; }
    .tab-btn { background: var(--card-bg); border: 1px solid var(--border-color); border-bottom: none; padding: 8px 15px; cursor: pointer; font-size: 13px; color: var(--text-muted); white-space: nowrap; border-radius: 6px 6px 0 0; transition: 0.2s; }
    .tab-btn.active { background: var(--bg-color); color: var(--accent); font-weight: bold; box-shadow: 0 -3px 0 0 var(--accent) inset; border-color: var(--border-color); }
    .tab-btn:hover:not(.active) { background: var(--bg-color); color: var(--text-main); }
</style>

<div class="toolbar-top">
    <div style="display:flex; gap:12px; align-items:center; flex-wrap: wrap;">
        <h2 style="margin:0; font-size:1.4em; color:var(--text-main);">Annual Ledger</h2>
        <div style="display: flex; gap: 5px; align-items: center;">
            <select id="year-select" style="padding: 5px 8px; border-radius: 6px; background:var(--card-bg); color:var(--text-main); border:1px solid var(--border-color); outline:none;" onchange="loadYear()">
                <?php for($y = 2025; $y <= 2035; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $current_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button onclick="copyYear()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" title="Copy columns and layout to a new year">📋 Copy</button>
        </div>
        
        <div style="display:flex; gap:6px; align-items:center; flex-wrap: wrap;">
            <button id="btn-delete-selected" onclick="deleteSelectedRows()" class="btn btn-small btn-danger" style="font-size:11px; display:none; padding: 5px 10px; border-radius: 4px; border: none; font-weight: bold;">🗑 Delete</button>
            <input type="text" id="ledger-search" placeholder="🔍 Search..." oninput="filterTable(this.value)" style="padding: 5px 8px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); width: 120px; outline:none; font-size: 12px;">
            <button onclick="document.getElementById('csv-upload').click()" class="btn btn-small" style="font-size:11px; padding: 5px 10px;">📥 Import</button>
            <button onclick="handleCSVExport()" class="btn btn-small" style="font-size:11px; padding: 5px 10px; background:var(--success);">📤 Export</button>
            <input type="file" id="csv-upload" accept=".csv" style="display:none;" onchange="handleCSVImport(event)">
        </div>

        <span id="save-status">💾 Saving...</span>
    </div>
    
    <div class="toolbar-stats">
        <div style="display:flex; align-items:center; gap:6px; color:var(--text-main); font-size: 13px;">
            <strong>Start:</strong>
            <input type="number" id="annual-start-balance" value="0" oninput="triggerSave(true)" style="padding:4px; width:90px; border-radius:6px; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-main); font-weight:bold; outline:none; font-size: 13px;">
        </div>
        <div class="global-total-box">
            <div class="title" id="month-total-title">MONTH TOTAL</div>
            <div class="val" id="month-total-cell">0</div>
        </div>
        <div class="global-total-box">
            <div class="title">YEAR END</div>
            <div class="val" id="annual-end-total">0</div>
        </div>
    </div>
</div>

<!-- HUD Grid (Wrapping & Non-Scrolling) -->
<div class="hud-grid">
    <div class="budget-hud" id="budget-hud-container">
        <!-- Populated by JS -->
    </div>
    <div class="cc-reconcile-card">
        <h4>💳 Credit Card Month-End</h4>
        <div class="cc-reconcile-row">
            <span style="color:var(--text-muted);">Month Float:</span>
            <span id="cc-actual-val" style="color:var(--warning);">$0.00</span>
        </div>
        <div class="cc-reconcile-row" style="margin-top: 4px; border-top: 1px dashed var(--border-color); padding-top: 4px;">
            <span style="color:var(--text-muted);">Planned Bill:</span>
            <span id="cc-planned-val" style="color:var(--text-main);">$0.00</span>
        </div>
    </div>
</div>

<div class="spreadsheet-wrapper">
    <div class="table-container">
        <table class="excel-table" id="ledger-table">
            <thead></thead>
            <tbody id="ledger-body"></tbody>
        </table>
    </div>
    <div class="tabs-bar" id="tabs-container"></div>
</div>

<script>
    const tabs = ["Jan", "Feb", "Mar", "Apr", "May", "June", "July", "Aug", "Sept", "Oct", "Nov", "Dec"];
    const tabMap = {"Jan":1, "Feb":2, "Mar":3, "Apr":4, "May":5, "June":6, "July":7, "Aug":8, "Sept":9, "Oct":10, "Nov":11, "Dec":12};
    let currentTab = "<?= $current_month_tab ?>"; 
    
    const defaultColumns = [
        { id: 'date', name: 'DATE', width: '80px' },
        { id: 'item', name: 'ITEM', width: '150px' },
        { id: 'category', name: 'CATEGORY / INCOME', width: '160px' },
        { id: 'method', name: 'METHOD', width: '110px' },
        { id: 'desc', name: 'DESCRIPTION', width: '140px' },
        { id: 'amount', name: 'AMOUNT', width: '100px' },
        { id: 'total', name: 'TOTAL', width: '100px' },
        { id: 'completed', name: 'COMPLETED', width: '110px' },
        { id: 'ref', name: 'REFERENCE', width: '90px' },
        { id: 'check', name: 'CHECK', width: '90px' }
    ];

    let ledgerData = { annual_start: 0, columns: [], months: {} };
    let monthStartBalances = {}; 
    let budgetData = { categories: {}, incomes: {}, methods: { 'Cash/Debit': 0, 'Credit Card': 0 } };
    let saveTimeout = null;

    document.addEventListener("DOMContentLoaded", () => {
        renderTabs();
        loadYear();
    });

    function fetchBudgetData() {
        const year = document.getElementById('year-select').value;
        const monthNum = tabMap[currentTab];
        return fetch(`ledger.php?api=budget_data&year=${year}&month=${monthNum}`)
            .then(res => res.json())
            .then(data => {
                budgetData = data;
            });
    }

    function updateBudgetHUD() {
        const hud = document.getElementById('budget-hud-container');
        if (!hud) return;
        
        let cats = budgetData.categories || {};
        let incomes = budgetData.incomes || {};
        
        if (Object.keys(cats).length === 0 && Object.keys(incomes).length === 0) {
            hud.innerHTML = '<div style="color:var(--text-muted); font-size:0.9em; padding:5px; width:100%; text-align:center;">No budget planned for this month. Set up categories in the Budget Planner.</div>';
            document.getElementById('cc-actual-val').innerText = '$0.00';
            document.getElementById('cc-planned-val').innerText = '$0.00';
            return;
        }
        
        let actuals = {};
        let actualIncomes = {};
        let actualMethods = { 'Cash/Debit': 0, 'Credit Card': 0 };
        let rows = ledgerData.months[currentTab] || [];

        // Build case-insensitive maps to ensure Ledger perfectly matches Budget Planner
        let budgetCatsMap = {};
        for (let k in cats) budgetCatsMap[k.toLowerCase()] = k;

        let budgetIncomesMap = {};
        for (let k in incomes) budgetIncomesMap[k.toLowerCase()] = k;

        rows.forEach(row => {
            let rawCat = trimStr(row.category).toLowerCase();
            let rawInc = trimStr(row.income_source).toLowerCase(); // Legacy fallback
            let rawMethod = trimStr(row.method).toLowerCase() || 'cash/debit';
            let amt = parseFloat(row.amount);
            
            if (!isNaN(amt)) {
                if (amt < 0) {
                    let absAmt = Math.abs(amt);
                    if (budgetCatsMap[rawCat]) {
                        let properCat = budgetCatsMap[rawCat];
                        actuals[properCat] = (actuals[properCat] || 0) + absAmt;
                    }
                    
                    let methodKey = 'Cash/Debit';
                    if (rawMethod.includes('credit')) methodKey = 'Credit Card';
                    
                    actualMethods[methodKey] = (actualMethods[methodKey] || 0) + absAmt;
                    
                } else if (amt > 0) {
                    if (budgetIncomesMap[rawCat]) {
                        let properInc = budgetIncomesMap[rawCat];
                        actualIncomes[properInc] = (actualIncomes[properInc] || 0) + amt;
                    } else if (budgetIncomesMap[rawInc]) { 
                        let properInc = budgetIncomesMap[rawInc];
                        actualIncomes[properInc] = (actualIncomes[properInc] || 0) + amt;
                    }
                }
            }
        });
        
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

        let plannedCC = budgetData.methods['Credit Card'] || 0;
        let actualCC = actualMethods['Credit Card'] || 0;
        document.getElementById('cc-actual-val').innerText = '$' + actualCC.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('cc-planned-val').innerText = '$' + parseFloat(plannedCC).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function trimStr(str) {
        return str ? str.toString().trim() : '';
    }

    function renderTabs() {
        const container = document.getElementById('tabs-container');
        container.innerHTML = ''; 
        tabs.forEach(tab => {
            const btn = document.createElement('button');
            btn.className = `tab-btn ${tab === currentTab ? 'active' : ''}`;
            btn.innerText = tab;
            btn.onclick = () => {
                document.getElementById('ledger-search').value = ''; 
                switchTab(tab);
            };
            container.appendChild(btn);
        });
    }

    function loadYear() {
        const year = document.getElementById('year-select').value;
        document.getElementById('ledger-body').innerHTML = '<tr><td style="padding:20px; text-align:center;">Loading Year...</td></tr>';
        
        fetch(`ledger.php?api=load&year=${year}`)
            .then(res => res.json())
            .then(data => {
                ledgerData = data;
                if (!ledgerData.columns || ledgerData.columns.length === 0) {
                    ledgerData.columns = JSON.parse(JSON.stringify(defaultColumns));
                }
                
                // Ensure single combined column exists, remove legacy separate columns if found
                ledgerData.columns = ledgerData.columns.filter(c => c.id !== 'income_source');
                if (!ledgerData.columns.find(c => c.id === 'category')) {
                    let itemIndex = ledgerData.columns.findIndex(c => c.id === 'item');
                    ledgerData.columns.splice(itemIndex + 1, 0, { id: 'category', name: 'CATEGORY / INCOME', width: '160px' });
                } else {
                    let catCol = ledgerData.columns.find(c => c.id === 'category');
                    catCol.name = 'CATEGORY / INCOME';
                    catCol.width = '160px';
                }
                
                if (!ledgerData.columns.find(c => c.id === 'method')) {
                    let catIndex = ledgerData.columns.findIndex(c => c.id === 'category');
                    ledgerData.columns.splice(catIndex + 1, 0, { id: 'method', name: 'METHOD', width: '110px' });
                }
                
                document.getElementById('annual-start-balance').value = ledgerData.annual_start || 0;
                document.getElementById('month-total-title').innerText = currentTab.toUpperCase() + ' TOTAL';
                
                fetchBudgetData().then(() => {
                    calculateAllTotals();
                    renderHeaders();
                    renderTable();
                });
            })
            .catch(err => console.error(err));
    }

    function switchTab(tabName) {
        currentTab = tabName;
        document.getElementById('month-total-title').innerText = currentTab.toUpperCase() + ' TOTAL';
        renderTabs();
        fetchBudgetData().then(() => {
            renderTable();
            updateBudgetHUD();
        });
    }

    async function copyYear() {
        const sourceYear = document.getElementById('year-select').value;
        const targetYear = await appPrompt(`Copy ${sourceYear} ledger structure and items to which year? (e.g., 2027)`);
        if (!targetYear || isNaN(targetYear) || targetYear.length !== 4) return;
        
        const confirmed = await appConfirm(`This will copy all columns, items, descriptions, and amounts from ${sourceYear} to ${targetYear} (Dates and checkmarks cleared). This will OVERWRITE any existing ${targetYear} ledger. Proceed?`);
        if (!confirmed) return;

        fetch('ledger.php?api=copy', {
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
                }
                yearSelect.value = targetYear;
                loadYear();
                appAlert(`Successfully cloned layout and items to ${targetYear}!`);
            } else {
                appAlert(res.message || "Error copying year.");
            }
        });
    }

    function renderHeaders() {
        const thead = document.querySelector('#ledger-table thead');
        let headHtml = '<tr class="header-row">';
        headHtml += `<td style="width: 30px; min-width: 30px; background: var(--card-bg); text-align: center; border-right: 1px solid var(--sheet-border-heavy);"><input type="checkbox" id="select-all-cb" onclick="toggleAllRows(this)" style="cursor:pointer; width: 14px; height: 14px; accent-color: var(--accent);"></td>`;

        ledgerData.columns.forEach((col, cIdx) => {
            headHtml += `<td style="width:${col.width || '100px'}; min-width:${col.width || '100px'};"><div style="display:flex; justify-content:space-between; align-items:center; padding: 0 4px;"><span style="flex-grow:1; text-align:center;">${col.name}</span><button onclick="removeColumn(${cIdx})" class="action-btn delete" style="color:var(--sheet-header-text); font-size:11px; padding:0;" title="Delete Column">✕</button></div></td>`;
        });
        
        headHtml += `<td style="width: 70px; min-width: 70px; background: transparent; border: none;"><button onclick="addColumn()" class="action-btn" style="color:var(--accent); background:var(--bg-color); border:1px solid var(--border-color); border-radius:4px; padding:4px 8px; margin-left: 5px;" title="Add Column">+ Col</button></td></tr>`;
        thead.innerHTML = headHtml;
    }

    async function addColumn() {
        let name = await appPrompt("Enter new column name:");
        if (!name) return;
        ledgerData.columns.push({ id: 'col_' + Date.now(), name: name.toUpperCase(), width: '120px' });
        renderHeaders(); renderTable(); triggerSave(true);
    }

    async function removeColumn(index) {
        const col = ledgerData.columns[index];
        if (col.id === 'amount' || col.id === 'total') {
            if(!(await appConfirm("Warning: Deleting Amount or Total columns disables math. Are you sure?"))) return;
        } else {
            if(!(await appConfirm(`Delete the ${col.name} column? Data will be lost.`))) return;
        }
        ledgerData.columns.splice(index, 1);
        renderHeaders(); renderTable(); triggerSave(true);
    }

    function toggleAllRows(source) {
        document.querySelectorAll('.row-select-cb').forEach(cb => { if (cb.closest('tr').style.display !== 'none') cb.checked = source.checked; });
        updateMultiDeleteUI();
    }

    function updateMultiDeleteUI() {
        const checked = document.querySelectorAll('.row-select-cb:checked');
        const btn = document.getElementById('btn-delete-selected');
        if (checked.length > 0) {
            btn.style.display = 'inline-block';
            btn.innerText = `🗑 Delete (${checked.length})`;
        } else {
            btn.style.display = 'none';
        }
        const selectAllCb = document.getElementById('select-all-cb');
        if(selectAllCb) selectAllCb.checked = (checked.length > 0 && checked.length === document.querySelectorAll('.row-select-cb').length);
    }

    async function deleteSelectedRows() {
        const checked = document.querySelectorAll('.row-select-cb:checked');
        if (checked.length === 0 || !(await appConfirm(`Delete ${checked.length} selected row(s)?`))) return;
        
        let indices = Array.from(checked).map(cb => parseInt(cb.value)).sort((a, b) => b - a);
        indices.forEach(idx => { ledgerData.months[currentTab].splice(idx, 1); });
        
        calculateAllTotals(); renderTable(); triggerSave(false); updateMultiDeleteUI();
    }

    function addRow(index) {
        let newRow = {};
        ledgerData.columns.forEach(col => { 
            if(col.id !== 'total') {
                if (col.id === 'method') newRow[col.id] = 'Cash/Debit';
                else if (col.id === 'completed') newRow[col.id] = 'Scheduled';
                else newRow[col.id] = '';
            }
        });
        ledgerData.months[currentTab].splice(index + 1, 0, newRow);
        calculateAllTotals(); renderTable(); triggerSave(false);
    }

    async function removeRow(index) {
        if (!(await appConfirm("Delete this row?"))) return;
        ledgerData.months[currentTab].splice(index, 1);
        calculateAllTotals(); renderTable(); triggerSave(false);
    }

    function calculateAllTotals() {
        let runningTotal = parseFloat(document.getElementById('annual-start-balance').value) || 0;
        ledgerData.annual_start = runningTotal;

        tabs.forEach(tab => {
            monthStartBalances[tab] = runningTotal;
            if (ledgerData.months[tab]) {
                ledgerData.months[tab].forEach(row => {
                    let amount = parseFloat(row.amount);
                    if (!isNaN(amount) && row.amount !== "") {
                        runningTotal += amount;
                    }
                });
            }
        });
        document.getElementById('annual-end-total').innerText = runningTotal.toFixed(0);
        updateBudgetHUD();
    }

    function renderTable() {
        const tbody = document.getElementById('ledger-body');
        tbody.innerHTML = '';
        
        if (!ledgerData.months[currentTab]) {
            let emptyRows = [];
            for(let i=0; i<30; i++) {
                let newRow = {};
                ledgerData.columns.forEach(col => { 
                    if(col.id !== 'total') {
                        if (col.id === 'method') newRow[col.id] = 'Cash/Debit';
                        else if (col.id === 'completed') newRow[col.id] = 'Scheduled';
                        else newRow[col.id] = '';
                    }
                });
                emptyRows.push(newRow);
            }
            ledgerData.months[currentTab] = emptyRows;
        }

        let rows = ledgerData.months[currentTab];
        let currentTotal = monthStartBalances[currentTab];
        let finalTotal = currentTotal;
        const totalColors = ['bg-tot-1', 'bg-tot-2', 'bg-tot-3'];
        let colorIndex = 0;

        // Combine categories and income streams into a single sorted list for the unified dropdown
        let combinedOptions = [];
        let cats = Object.keys(budgetData.categories || {});
        let incs = Object.keys(budgetData.incomes || {});
        cats.forEach(c => { if(!combinedOptions.includes(c)) combinedOptions.push(c); });
        incs.forEach(i => { if(!combinedOptions.includes(i)) combinedOptions.push(i); });
        combinedOptions.sort((a,b) => a.localeCompare(b));

        rows.forEach((row, index) => {
            const tr = document.createElement('tr');
            tr.id = `row-${index}`;
            
            if (row.date && row.date.trim() !== '') colorIndex = (colorIndex + 1) % totalColors.length;
            let totalBgClass = totalColors[colorIndex];

            let amount = parseFloat(row.amount);
            let hasAmount = !isNaN(amount) && row.amount !== "";
            if (hasAmount) {
                currentTotal += amount;
            }
            finalTotal = currentTotal;

            let itemBg = (row.item && row.item.trim().toUpperCase() === "OPEN") ? "bg-open" : "";
            let amountBg = hasAmount ? (amount < 0 ? "bg-neg" : "bg-pos") : "";
            
            let html = `<td style="text-align:center; background: var(--card-bg); border-right: 1px solid var(--border-color);"><input type="checkbox" class="row-select-cb" value="${index}" onchange="updateMultiDeleteUI()" style="cursor:pointer; width: 14px; height: 14px; accent-color: var(--accent);"></td>`;
            
            ledgerData.columns.forEach(col => {
                let cellValue = row[col.id] || '';
                let align = (col.id === 'amount' || col.id === 'total') ? 'text-align:right;' : (col.id === 'completed' ? 'text-align:center;' : '');
                
                if (col.id === 'total') {
                    html += `<td class="cell-total ${totalBgClass}" style="${align} padding-right:4px;">${hasAmount || index === 0 ? currentTotal.toFixed(0) : ''}</td>`;
                } else if (col.id === 'amount') {
                    html += `<td class="cell-amount ${amountBg}"><input type="number" step="0.01" class="sheet-input" value="${cellValue}" oninput="updateRow(${index}, '${col.id}', this.value)" style="${align}"></td>`;
                } else if (col.id === 'item') {
                    html += `<td class="cell-item ${itemBg}"><input type="text" class="sheet-input" value="${cellValue}" oninput="updateRow(${index}, '${col.id}', this.value)" style="${align}"></td>`;
                } else if (col.id === 'category') {
                    let opts = `<option value=""></option>`;
                    combinedOptions.forEach(opt => { opts += `<option value="${opt}" ${trimStr(cellValue).toLowerCase() === trimStr(opt).toLowerCase() ? 'selected' : ''}>${opt}</option>`; });
                    if (cellValue && !combinedOptions.map(k=>trimStr(k).toLowerCase()).includes(trimStr(cellValue).toLowerCase())) opts += `<option value="${cellValue}" selected>${cellValue}</option>`;
                    html += `<td><select class="sheet-input" onchange="updateRow(${index}, '${col.id}', this.value)" style="${align}">${opts}</select></td>`;
                } else if (col.id === 'method') {
                    let methods = ['Cash/Debit', 'Credit Card'];
                    let opts = '';
                    methods.forEach(m => { opts += `<option value="${m}" ${trimStr(cellValue).toLowerCase() === trimStr(m).toLowerCase() ? 'selected' : ''}>${m}</option>`; });
                    html += `<td><select class="sheet-input" onchange="updateRow(${index}, '${col.id}', this.value)" style="${align}">${opts}</select></td>`;
                } else if (col.id === 'completed') {
                    let statuses = ['Scheduled', 'Requested', 'Complete'];
                    let opts = `<option value=""></option>`;
                    statuses.forEach(s => { opts += `<option value="${s}" ${trimStr(cellValue).toLowerCase() === trimStr(s).toLowerCase() ? 'selected' : ''}>${s}</option>`; });
                    html += `<td><select class="sheet-input" onchange="updateRow(${index}, '${col.id}', this.value)" style="${align}">${opts}</select></td>`;
                } else {
                    html += `<td><input type="text" class="sheet-input" value="${cellValue}" oninput="updateRow(${index}, '${col.id}', this.value)" style="${align}"></td>`;
                }
            });

            html += `<td style="text-align:center; border:none; background:transparent;"><button onclick="addRow(${index})" class="action-btn" title="Add Row Below">+</button><button onclick="removeRow(${index})" class="action-btn delete" title="Delete Row">✕</button></td>`;
            tr.innerHTML = html;
            tbody.appendChild(tr);
        });

        const mTotal = document.getElementById('month-total-cell');
        if (mTotal) mTotal.innerText = finalTotal.toFixed(0);
        
        filterTable(document.getElementById('ledger-search').value);
        updateBudgetHUD();
    }

    function updateTableVisuals() {
        let currentTotal = monthStartBalances[currentTab];
        let finalTotal = currentTotal;
        const totalColors = ['bg-tot-1', 'bg-tot-2', 'bg-tot-3'];
        let colorIndex = 0;

        let rows = ledgerData.months[currentTab] || [];
        rows.forEach((row, index) => {
            const tr = document.getElementById('row-' + index);
            if (!tr) return;

            if (row.date && row.date.trim() !== '') colorIndex = (colorIndex + 1) % totalColors.length;
            let totalBgClass = totalColors[colorIndex];

            let amount = parseFloat(row.amount);
            let hasAmount = !isNaN(amount) && row.amount !== "";
            if (hasAmount) {
                currentTotal += amount;
            }
            finalTotal = currentTotal;

            let itemBg = (row.item && row.item.trim().toUpperCase() === "OPEN") ? "bg-open" : "";
            let amountBg = hasAmount ? (amount < 0 ? "bg-neg" : "bg-pos") : "";

            if (tr.querySelector('.cell-item')) tr.querySelector('.cell-item').className = "cell-item " + itemBg;
            if (tr.querySelector('.cell-amount')) tr.querySelector('.cell-amount').className = "cell-amount " + amountBg;
            if (tr.querySelector('.cell-total')) {
                tr.querySelector('.cell-total').className = "cell-total " + totalBgClass;
                tr.querySelector('.cell-total').innerText = (hasAmount || index === 0) ? currentTotal.toFixed(0) : '';
            }
        });

        const mTotal = document.getElementById('month-total-cell');
        if (mTotal) mTotal.innerText = finalTotal.toFixed(0);
        updateBudgetHUD();
    }

    function updateRow(index, field, value) {
        ledgerData.months[currentTab][index][field] = value;
        if (field === 'amount' || field === 'item' || field === 'date' || field === 'category' || field === 'method' || field === 'completed') {
            calculateAllTotals();
            updateTableVisuals(); 
        }
        triggerSave(false);
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
        clearTimeout(saveTimeout);
        document.getElementById('save-status').innerText = "⏳ Saving...";
        document.getElementById('save-status').classList.add('visible');
        if (recalcAll) { calculateAllTotals(); renderTable(); }
        saveTimeout = setTimeout(() => { saveData(); }, 1000); 
    }

    function saveData() {
        fetch('ledger.php?api=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ year: document.getElementById('year-select').value, data: ledgerData })
        })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('save-status').innerText = "💾 Saved";
                setTimeout(() => { document.getElementById('save-status').classList.remove('visible'); }, 2000);
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>