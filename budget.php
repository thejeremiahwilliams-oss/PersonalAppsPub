<?php
// public/budget.php

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();

// --- AUTO-HEAL DATABASE SCHEMA ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS budget_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        budget_year INT NOT NULL,
        budget_month INT NOT NULL,
        budget_data LONGTEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_budget (user_id, budget_year, budget_month),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// --- API ENDPOINTS FOR AJAX SAVING/LOADING ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // Automatic Rollover Math API
    if ($_GET['api'] === 'get_rollover') {
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        
        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month === 0) {
            $prev_month = 12;
            $prev_year -= 1;
        }
        
        $stmt = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = :y AND budget_month = :m");
        $stmt->execute([':uid' => $user_id, ':y' => $prev_year, ':m' => $prev_month]);
        $b_row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $surpluses = [];
        
        if ($b_row && !empty($b_row['budget_data'])) {
            $b_data = json_decode($b_row['budget_data'], true);
            $planned = [];
            if(isset($b_data['expenses'])) {
                foreach($b_data['expenses'] as $exp) {
                    $grp = trim($exp['group'] ?? 'Uncategorized');
                    $planned[$grp] = ($planned[$grp] ?? 0) + (float)$exp['amount'];
                }
            }
            
            $stmt2 = $pdo->prepare("SELECT ledger_data FROM yearly_ledgers WHERE user_id = :uid AND ledger_year = :y");
            $stmt2->execute([':uid' => $user_id, ':y' => $prev_year]);
            $l_row = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            $actuals = [];
            if ($l_row && !empty($l_row['ledger_data'])) {
                $l_data = json_decode($l_row['ledger_data'], true);
                $tab_map = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'June', 7=>'July', 8=>'Aug', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
                $prev_tab = $tab_map[$prev_month];
                
                if (isset($l_data['months'][$prev_tab])) {
                    foreach($l_data['months'][$prev_tab] as $row) {
                        $cat = trim($row['category'] ?? '');
                        $amt = (float)($row['amount'] ?? 0);
                        if ($amt < 0 && !empty($cat)) {
                            $actuals[$cat] = ($actuals[$cat] ?? 0) + abs($amt);
                        }
                    }
                }
            }
            
            foreach($planned as $cat => $p_amt) {
                $a_amt = $actuals[$cat] ?? 0;
                $diff = $p_amt - $a_amt;
                if ($diff > 0) {
                    $surpluses[] = ['category' => $cat, 'amount' => $diff];
                }
            }
        }
        echo json_encode($surpluses);
        exit;
    }

    if ($_GET['api'] === 'load') {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $forceBase = isset($_GET['base']) && $_GET['base'] == 1;

        if ($forceBase) { $year = 0; $month = 0; }

        $stmt = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = :year AND budget_month = :month");
        $stmt->execute([':uid' => $user_id, ':year' => $year, ':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['budget_data'])) {
            echo $row['budget_data'];
        } else {
            if (!$forceBase) {
                $stmtBase = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = 0 AND budget_month = 0");
                $stmtBase->execute([':uid' => $user_id]);
                $baseRow = $stmtBase->fetch(PDO::FETCH_ASSOC);
                if ($baseRow && !empty($baseRow['budget_data'])) {
                    echo $baseRow['budget_data'];
                    exit;
                }
            }
            echo json_encode([
                'income' => [['id' => uniqid('inc_'), 'name' => 'Primary Income', 'amount' => 0]],
                'expenses' => [
                    ['id' => uniqid('exp_'), 'group' => 'Housing', 'name' => 'Mortgage/Rent', 'amount' => 0, 'method' => 'Cash/Debit'],
                    ['id' => uniqid('exp_'), 'group' => 'Utilities', 'name' => 'Electricity', 'amount' => 0, 'method' => 'Cash/Debit']
                ]
            ]);
        }
        exit;
    }

    if ($_GET['api'] === 'save') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $year = isset($payload['year']) ? (int)$payload['year'] : (int)date('Y');
        $month = isset($payload['month']) ? (int)$payload['month'] : (int)date('n');
        $data = json_encode($payload['data'] ?? []);

        $stmt = $pdo->prepare("INSERT INTO budget_plans (user_id, budget_year, budget_month, budget_data) 
                               VALUES (:uid, :year, :month, :data) 
                               ON DUPLICATE KEY UPDATE budget_data = :data_update");
        $stmt->execute([
            ':uid' => $user_id, ':year' => $year, ':month' => $month, ':data' => $data, ':data_update' => $data
        ]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['api'] === 'save_base') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $data = json_encode($payload['data'] ?? []);
        $stmt = $pdo->prepare("INSERT INTO budget_plans (user_id, budget_year, budget_month, budget_data) 
                               VALUES (:uid, 0, 0, :data) 
                               ON DUPLICATE KEY UPDATE budget_data = :data_update");
        $stmt->execute([':uid' => $user_id, ':data' => $data, ':data_update' => $data]);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

$page_title = "Budget Planner - PersonalApps";
include 'includes/header.php';

$current_year = date('Y');
$current_month = date('n');
?>

<style>
    .budget-header-area { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
    .date-selectors { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .date-selectors select { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-weight: bold; font-size: 1em; outline: none; cursor: pointer; }
    
    .budget-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
    .summary-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; text-align: center; transition: border-color 0.3s; }
    .summary-card h3 { margin: 0 0 10px 0; font-size: 0.9em; text-transform: uppercase; color: var(--text-muted); letter-spacing: 1px; transition: color 0.3s; }
    .summary-card .amount { font-size: 2em; font-weight: bold; color: var(--text-main); transition: color 0.3s; }
    
    .method-breakdown { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
    .method-card { flex: 1; min-width: 150px; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 10px; padding: 15px; text-align: center; }
    .method-card h3 { margin: 0 0 5px 0; font-size: 0.85em; text-transform: uppercase; color: var(--text-muted); }
    .method-card .amount { font-size: 1.4em; font-weight: bold; color: var(--text-main); }
    
    .progress-container { background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; height: 24px; width: 100%; overflow: hidden; margin-bottom: 25px; position: relative; }
    .progress-fill { background: var(--accent); height: 100%; width: 0%; transition: width 0.4s ease, background-color 0.4s ease; }
    .progress-text { position: absolute; width: 100%; text-align: center; top: 3px; font-size: 0.85em; font-weight: bold; color: var(--text-main); mix-blend-mode: difference; }

    .budget-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
    @media (max-width: 900px) { .budget-grid { grid-template-columns: 1fr; } }
    
    .budget-panel { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; }
    .budget-panel h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    
    .budget-item { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; background: var(--bg-color); padding: 8px; border-radius: 8px; border: 1px solid var(--border-color); flex-wrap: wrap; }
    .budget-item input[type="text"] { flex-grow: 1; border: none; background: transparent; color: var(--text-main); font-weight: 500; font-size: 1em; outline: none; min-width: 120px; }
    .budget-item input[type="number"] { width: 100px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); padding: 6px; border-radius: 4px; text-align: right; font-weight: bold; outline: none; }
    
    select.method-select { width: auto; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-muted); font-size: 0.85em; outline: none; transition: 0.2s; }
    .del-btn { background: transparent; border: none; color: var(--text-muted); font-size: 1.2em; cursor: pointer; padding: 0 5px; transition: color 0.2s; margin-left: auto; }
    .del-btn:hover { color: var(--danger); }
    
    .group-header { margin: 20px 0 10px 0; font-size: 0.9em; font-weight: bold; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border-color); padding-bottom: 4px; display: flex; justify-content: space-between; }
    .add-btn { background: rgba(59, 130, 246, 0.1); color: var(--accent); border: 1px dashed var(--accent); width: 100%; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.2s; margin-top: 10px; }
    
    #save-status { font-size: 0.9em; color: var(--text-muted); font-weight: bold; opacity: 0; transition: opacity 0.3s; }
    #save-status.visible { opacity: 1; }
    
    .rollover-btn { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid var(--success); font-weight: bold; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 5px; }
    .rollover-btn:hover { background: var(--success); color: white; }

    @media (max-width: 768px) {
        .budget-item { gap: 5px; padding: 10px; }
        .budget-item input[type="text"] { width: 100%; margin-bottom: 5px; }
        .budget-item .del-btn { margin-left: auto; }
    }
</style>

<div class="budget-header-area">
    <div style="display: flex; align-items: center; gap: 15px;">
        <h2 style="margin:0; font-size:1.5em; color:var(--text-main);">Budget PLanner</h2>
        <span id="save-status">💾 Saving...</span>
    </div>
    <div class="date-selectors">
        <button class="rollover-btn" onclick="syncRollovers()" title="Pull unspent cash from last month's ledger">
            🔄 Sync Rollover
        </button>
        <div style="display: flex; gap: 8px; border-right: 1px solid var(--border-color); border-left: 1px solid var(--border-color); padding: 0 10px; margin: 0 5px;">
            <button class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" onclick="applyBaseTemplate()">📥 Apply Base</button>
            <button class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="saveAsBaseTemplate()">💾 Set as Base</button>
        </div>
        <select id="budget-month" onchange="loadBudget()">
            <?php 
            $months = [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'];
            foreach($months as $num => $name) {
                $sel = ($num == $current_month) ? 'selected' : '';
                echo "<option value=\"$num\" $sel>$name</option>";
            }
            ?>
        </select>
        <select id="budget-year" onchange="loadBudget()">
            <?php for($y = 2025; $y <= 2035; $y++): ?>
                <option value="<?= $y ?>" <?= $y == $current_year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<div class="budget-summary">
    <div class="summary-card" style="border-top: 4px solid var(--success);">
        <h3>Expected Income</h3>
        <div class="amount" id="sum-income">$0.00</div>
    </div>
    <div class="summary-card" style="border-top: 4px solid var(--danger);">
        <h3>Assigned Dollars</h3>
        <div class="amount" id="sum-expenses">$0.00</div>
    </div>
    <div class="summary-card" id="left-to-budget-card" style="border-top: 4px solid var(--accent);">
        <h3 id="remaining-label">Unallocated Funds</h3>
        <div class="amount" id="sum-remaining">$0.00</div>
        <div style="font-size: 0.8em; color: var(--text-muted); margin-top: 5px; font-weight: bold;">Goal: Exactly $0.00</div>
    </div>
</div>

<div class="progress-container">
    <div class="progress-fill" id="allocation-bar"></div>
    <div class="progress-text" id="allocation-text">0% Assigned</div>
</div>

<!-- New Payment Method Dashboard -->
<div class="method-breakdown">
    <div class="method-card" style="border-top: 3px solid var(--accent);">
        <h3>🏦 Cash / Debit</h3>
        <div class="amount" id="sum-cash">$0.00</div>
    </div>
    <div class="method-card" style="border-top: 3px solid var(--warning);">
        <h3>💳 Credit Card</h3>
        <div class="amount" id="sum-credit">$0.00</div>
    </div>
</div>

<div class="budget-grid">
    <div class="budget-panel">
        <h3 style="color: var(--success);">💰 Income Streams</h3>
        <div id="income-container"></div>
        <button class="add-btn" onclick="addIncome()">+ Add Income Stream</button>
    </div>

    <div class="budget-panel">
        <h3 style="color: var(--danger);">💸 Job Assignments (Expenses & Savings)</h3>
        <div id="expense-container"></div>
        
        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <input type="text" id="new-group-name" placeholder="New Category (e.g. Health)" style="flex-grow: 1; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
            <button class="btn" onclick="addExpenseGroup()" style="white-space: nowrap;">Add Category</button>
        </div>
    </div>
</div>

<script>
    let budgetData = { income: [], expenses: [] };
    let saveTimeout = null;

    document.addEventListener("DOMContentLoaded", () => {
        loadBudget();
    });

    async function syncRollovers() {
        const year = document.getElementById('budget-year').value;
        const month = document.getElementById('budget-month').value;
        
        if (!(await appConfirm(`Fetch your actual unspent cash from last month's ledger and automatically inject it into this month's income?`))) return;
        
        fetch(`budget.php?api=get_rollover&year=${year}&month=${month}`)
            .then(res => res.json())
            .then(surpluses => {
                if (surpluses.length === 0) {
                    appAlert("No unspent funds found in the previous month's ledger to roll over.");
                    return;
                }
                
                let totalFound = 0;
                surpluses.forEach(s => {
                    budgetData.income.push({
                        id: 'inc_' + Date.now() + Math.random(),
                        name: `Rollover: ${s.category}`,
                        amount: parseFloat(s.amount.toFixed(2))
                    });
                    totalFound += s.amount;
                });
                
                renderUI();
                triggerSave();
                appAlert(`Successfully synced! Found $${totalFound.toFixed(2)} in unspent cash from last month.`);
            });
    }

    function loadBudget() {
        const year = document.getElementById('budget-year').value;
        const month = document.getElementById('budget-month').value;
        
        fetch(`budget.php?api=load&year=${year}&month=${month}`)
            .then(res => res.json())
            .then(data => {
                budgetData = data;
                renderUI();
            });
    }

    async function saveAsBaseTemplate() {
        if(!(await appConfirm("Save layout as Base Template?"))) return;
        fetch('budget.php?api=save_base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ data: budgetData })
        }).then(res => res.json()).then(res => { if(res.status === 'success') appAlert("Base Template saved!"); });
    }

    async function applyBaseTemplate() {
        if(!(await appConfirm("Overwrite this month with Base Template?"))) return;
        fetch(`budget.php?api=load&base=1`).then(res => res.json()).then(data => {
            budgetData = data; renderUI(); triggerSave(); 
        });
    }

    function renderUI() {
        renderIncome();
        renderExpenses();
        calculateTotals();
    }

    function renderIncome() {
        const container = document.getElementById('income-container');
        container.innerHTML = '';
        budgetData.income.forEach((item, index) => {
            let isRollover = item.name.includes('Rollover:');
            let highlightClass = isRollover ? 'color: var(--success); font-weight: bold;' : '';
            container.innerHTML += `
                <div class="budget-item">
                    <input type="text" value="${item.name}" placeholder="Income Name" style="${highlightClass}" oninput="updateIncome(${index}, 'name', this.value)">
                    <span style="color:var(--text-muted);">$</span>
                    <input type="number" step="0.01" min="0" value="${item.amount || ''}" placeholder="0.00" oninput="updateIncome(${index}, 'amount', this.value)">
                    <button class="del-btn" onclick="removeIncome(${index})">✕</button>
                </div>
            `;
        });
    }

    function addIncome() {
        budgetData.income.push({ id: 'inc_' + Date.now(), name: '', amount: 0 });
        renderUI(); triggerSave();
    }

    function removeIncome(index) {
        budgetData.income.splice(index, 1);
        renderUI(); triggerSave();
    }

    function updateIncome(index, field, value) {
        budgetData.income[index][field] = field === 'amount' ? parseFloat(value) || 0 : value;
        if (field === 'amount') calculateTotals();
        triggerSave();
    }

    function renderExpenses() {
        const container = document.getElementById('expense-container');
        container.innerHTML = '';
        
        const grouped = {};
        budgetData.expenses.forEach((item, index) => {
            let grp = item.group || 'Uncategorized';
            if (!grouped[grp]) grouped[grp] = [];
            item.originalIndex = index; 
            grouped[grp].push(item);
        });

        for (const [groupName, items] of Object.entries(grouped)) {
            let html = `<div class="group-header"><span>${groupName}</span><button class="del-btn" style="font-size:0.9em;" onclick="addExpenseItem('${groupName}')">+ Add</button></div>`;
            items.forEach(item => {
                let method = item.method || 'Cash/Debit';
                html += `
                    <div class="budget-item">
                        <input type="text" value="${item.name}" placeholder="Expense Name" oninput="updateExpense(${item.originalIndex}, 'name', this.value)">
                        <select class="method-select" onchange="updateExpense(${item.originalIndex}, 'method', this.value)" title="Payment Method">
                            <option value="Cash/Debit" ${method === 'Cash/Debit' ? 'selected' : ''}>Cash/Debit</option>
                            <option value="Credit Card" ${method === 'Credit Card' ? 'selected' : ''}>Credit Card</option>
                        </select>
                        <span style="color:var(--text-muted); margin-left: 5px;">$</span>
                        <input type="number" step="0.01" min="0" value="${item.amount || ''}" placeholder="0.00" oninput="updateExpense(${item.originalIndex}, 'amount', this.value)">
                        <button class="del-btn" onclick="removeExpense(${item.originalIndex})">✕</button>
                    </div>
                `;
            });
            container.innerHTML += html;
        }
    }

    function addExpenseGroup() {
        const input = document.getElementById('new-group-name');
        if (!input.value.trim()) return;
        budgetData.expenses.push({ id: 'exp_' + Date.now(), group: input.value.trim(), name: '', amount: 0, method: 'Cash/Debit' });
        input.value = ''; renderUI(); triggerSave();
    }

    function addExpenseItem(groupName) {
        budgetData.expenses.push({ id: 'exp_' + Date.now(), group: groupName, name: '', amount: 0, method: 'Cash/Debit' });
        renderUI(); triggerSave();
    }

    function removeExpense(index) {
        budgetData.expenses.splice(index, 1);
        renderUI(); triggerSave();
    }

    function updateExpense(index, field, value) {
        budgetData.expenses[index][field] = field === 'amount' ? parseFloat(value) || 0 : value;
        if (field === 'amount' || field === 'method') calculateTotals();
        triggerSave();
    }

    function calculateTotals() {
        let totalInc = 0; 
        let totalExp = 0;
        let totalCash = 0; 
        let totalCredit = 0; 

        budgetData.income.forEach(i => totalInc += parseFloat(i.amount) || 0);
        budgetData.expenses.forEach(e => {
            let amt = parseFloat(e.amount) || 0;
            totalExp += amt;
            
            let method = e.method || 'Cash/Debit';
            if (method === 'Credit Card') totalCredit += amt;
            else totalCash += amt;
        });
        
        let remaining = totalInc - totalExp;
        document.getElementById('sum-income').innerText = '$' + totalInc.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sum-expenses').innerText = '$' + totalExp.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        // Update Payment Method Dashboard
        document.getElementById('sum-cash').innerText = '$' + totalCash.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sum-credit').innerText = '$' + totalCredit.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const remCard = document.getElementById('left-to-budget-card');
        const remText = document.getElementById('sum-remaining');
        const remLabel = document.getElementById('remaining-label');
        
        remText.innerText = '$' + Math.abs(remaining).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        if (remaining > 0) {
            remCard.style.borderTopColor = 'var(--warning)'; remText.style.color = 'var(--warning)'; remLabel.innerText = "Left to Give a Job"; remLabel.style.color = 'var(--warning)';
        } else if (remaining < 0) {
            remCard.style.borderTopColor = 'var(--danger)'; remText.style.color = 'var(--danger)'; remLabel.innerText = "Over Budget"; remLabel.style.color = 'var(--danger)';
        } else if (remaining === 0 && totalInc > 0) {
            remCard.style.borderTopColor = 'var(--success)'; remText.style.color = 'var(--success)'; remLabel.innerText = "Perfectly Zero-Based!"; remLabel.style.color = 'var(--success)';
        } else {
            remCard.style.borderTopColor = 'var(--accent)'; remText.style.color = 'var(--text-main)'; remLabel.innerText = "Unallocated Funds"; remLabel.style.color = 'var(--text-muted)';
        }

        const bar = document.getElementById('allocation-bar');
        const pctText = document.getElementById('allocation-text');
        
        if (totalInc > 0) {
            let pct = (totalExp / totalInc) * 100;
            pctText.innerText = pct.toFixed(1) + '% Assigned';
            if (pct > 100) { bar.style.width = '100%'; bar.style.backgroundColor = 'var(--danger)'; }
            else if (pct === 100) { bar.style.width = '100%'; bar.style.backgroundColor = 'var(--success)'; }
            else { bar.style.width = pct + '%'; bar.style.backgroundColor = 'var(--warning)'; }
        } else {
            bar.style.width = '0%'; pctText.innerText = '0% Assigned'; bar.style.backgroundColor = 'var(--accent)';
        }
    }

    function triggerSave() {
        clearTimeout(saveTimeout);
        const statusEl = document.getElementById('save-status');
        statusEl.innerText = "⏳ Saving..."; statusEl.classList.add('visible');
        saveTimeout = setTimeout(() => { saveData(); }, 1000); 
    }

    function saveData() {
        fetch('budget.php?api=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                year: document.getElementById('budget-year').value,
                month: document.getElementById('budget-month').value,
                data: budgetData
            })
        })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                const statusEl = document.getElementById('save-status');
                statusEl.innerText = "💾 Saved";
                setTimeout(() => { statusEl.classList.remove('visible'); }, 2000);
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>