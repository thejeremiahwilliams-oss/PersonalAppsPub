<?php
// public/budget.php

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];$db = new Database();
$pdo =$db->getConnection();

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

    if ($_GET['api'] === 'load') {$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');$forceBase = isset($_GET['base']) &&$_GET['base'] == 1;

        if ($forceBase) { $year = 0; $month = 0; }

        $stmt =$pdo->prepare("SELECT id, budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = :year AND budget_month = :month");
        $stmt->execute([':uid' =>$user_id, ':year' => $year, ':month' =>$month]);
        $row =$stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['budget_data'])) {
            echo json_encode([
                'id' => $row['id'],
                'data' => json_decode($row['budget_data'], true)
            ]);
        } else {
            if (!$forceBase) {
                $stmtBase =$pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = 0 AND budget_month = 0");
                $stmtBase->execute([':uid' =>$user_id]);
                $baseRow =$stmtBase->fetch(PDO::FETCH_ASSOC);
                if ($baseRow && !empty($baseRow['budget_data'])) {
                    echo json_encode([
                        'id' => null, 
                        'data' => json_decode($baseRow['budget_data'], true)
                    ]);
                    exit;
                }
            }
            echo json_encode([
                'id' => null,
                'data' => [
                    'income' => [['id' => uniqid('inc_'), 'name' => 'Primary Income', 'amount' => 0]],
                    'expenses' => [
                        ['id' => uniqid('exp_'), 'group' => 'Housing', 'name' => 'Mortgage/Rent', 'amount' => 0],
                        ['id' => uniqid('exp_'), 'group' => 'Utilities', 'name' => 'Electricity', 'amount' => 0]
                    ],
                    'yearly_subs' => []
                ]
            ]);
        }
        exit;
    }

    if ($_GET['api'] === 'save') {$payload = json_decode(file_get_contents('php://input'), true);
        $year = isset($payload['year']) ? (int)$payload['year'] : (int)date('Y');$month = isset($payload['month']) ? (int)$payload['month'] : (int)date('n');
        $data = json_encode($payload['data'] ?? []);

        $stmt =$pdo->prepare("INSERT INTO budget_plans (user_id, budget_year, budget_month, budget_data) 
                               VALUES (:uid, :year, :month, :data) 
                               ON DUPLICATE KEY UPDATE budget_data = :data_update");
        $stmt->execute([
            ':uid' => $user_id, ':year' => $year, ':month' =>$month, ':data' => $data, ':data_update' =>$data
        ]);
        
        $stmt2 =$pdo->prepare("SELECT id FROM budget_plans WHERE user_id = :uid AND budget_year = :year AND budget_month = :month");
        $stmt2->execute([':uid' =>$user_id, ':year' => $year, ':month' =>$month]);
        $budget_id =$stmt2->fetchColumn();

        echo json_encode(['status' => 'success', 'id' => $budget_id]);
        exit;
    }

    if ($_GET['api'] === 'save_base') {$payload = json_decode(file_get_contents('php://input'), true);
        $data = json_encode($payload['data'] ?? []);
        $stmt =$pdo->prepare("INSERT INTO budget_plans (user_id, budget_year, budget_month, budget_data) 
                               VALUES (:uid, 0, 0, :data) 
                               ON DUPLICATE KEY UPDATE budget_data = :data_update");
        $stmt->execute([':uid' =>$user_id, ':data' => $data, ':data_update' =>$data]);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

$page_title = "Budget Planner - PersonalApps";
include 'includes/header.php';

$current_year = date('Y');$current_month = date('n');
?>

<style>
    /* Expand the core container so 3 columns have room to breathe */
    .container { max-width: 1400px !important; padding: 20px !important; }

    .budget-header-area { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
    .date-selectors { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .date-selectors select { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-weight: bold; font-size: 1em; outline: none; cursor: pointer; }
    
    .budget-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
    .summary-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; text-align: center; transition: border-color 0.3s; }
    .summary-card h3 { margin: 0 0 10px 0; font-size: 0.9em; text-transform: uppercase; color: var(--text-muted); letter-spacing: 1px; transition: color 0.3s; }
    .summary-card .amount { font-size: 2em; font-weight: bold; color: var(--text-main); transition: color 0.3s; }
    
    .progress-container { background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; height: 24px; width: 100%; overflow: hidden; margin-bottom: 25px; position: relative; }
    .progress-fill { background: var(--accent); height: 100%; width: 0%; transition: width 0.4s ease, background-color 0.4s ease; }
    .progress-text { position: absolute; width: 100%; text-align: center; top: 3px; font-size: 0.85em; font-weight: bold; color: var(--text-main); mix-blend-mode: difference; }

    /* Strict 3-column layout */
    .budget-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; align-items: start; }
    
    .budget-panel { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; }
    .budget-panel h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    
    /* Cleaned up input rows to prevent squishing */
    .budget-item { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; background: var(--bg-color); padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); }
    .budget-item input[type="text"] { flex-grow: 1; border: none; background: transparent; color: var(--text-main); font-weight: 500; font-size: 0.95em; outline: none; width: 100%; min-width: 50px; }
    .budget-item input[type="number"] { width: 85px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); padding: 6px; border-radius: 4px; text-align: right; font-weight: bold; outline: none; font-size: 0.95em; }
    
    .del-btn { background: transparent; border: none; color: var(--text-muted); font-size: 1.2em; cursor: pointer; padding: 0 5px; transition: color 0.2s; }
    .del-btn:hover { color: var(--danger); }
    
    .group-header { margin: 20px 0 10px 0; font-size: 0.9em; font-weight: bold; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border-color); padding-bottom: 4px; display: flex; justify-content: space-between; align-items: flex-end; }
    .add-btn { background: rgba(59, 130, 246, 0.1); color: var(--accent); border: 1px dashed var(--accent); width: 100%; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.2s; margin-top: 10px; }
    
    #save-status { font-size: 0.9em; color: var(--text-muted); font-weight: bold; opacity: 0; transition: opacity 0.3s; }
    #save-status.visible { opacity: 1; }

    /* Modals */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 15px; }
    .modal-content { background: var(--card-bg); padding: 30px; border-radius: 12px; width: 500px; max-width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid var(--border-color); box-sizing: border-box; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 13px; }
    .modal-header h3 { margin: 0; font-size: 1.3em; color: var(--text-main); } 
    .close-modal { background: none; border: none; font-size: 1.5em; color: var(--text-muted); cursor: pointer; padding: 0; }

    /* Responsive fallbacks */
    @media (max-width: 1100px) {
        .budget-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 768px) {
        .budget-grid { grid-template-columns: 1fr; }
        .budget-summary { grid-template-columns: 1fr; }
    }
</style>

<div class="budget-header-area">
    <div style="display: flex; align-items: center; gap: 15px;">
        <h2 style="margin:0; font-size:1.5em; color:var(--text-main);">Budget Planner</h2>
        <span id="save-status">💾 Saving...</span>
    </div>
    <div class="date-selectors">
        <div style="display: flex; gap: 8px; border-right: 1px solid var(--border-color); border-left: 1px solid var(--border-color); padding: 0 10px; margin: 0 5px;">
            <button class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" onclick="applyBaseTemplate()">📥 Apply Base</button>
            <button class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="saveAsBaseTemplate()">💾 Set as Base</button>
            <button class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="shareBudget()">🔗 Share</button>
        </div>
        <select id="budget-month" onchange="loadBudget()">
            <?php 
            $months = [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'];
            foreach($months as$num => $name) {$sel = ($num ==$current_month) ? 'selected' : '';
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

<div class="budget-grid">
    <!-- Panel 1: Income -->
    <div class="budget-panel">
        <h3 style="color: var(--success);">💰 Income Streams</h3>
        <div id="income-container"></div>
        <button class="add-btn" onclick="addIncome()">+ Add Income Stream</button>
    </div>

    <!-- Panel 2: Expenses -->
    <div class="budget-panel">
        <h3 style="color: var(--danger);">💸 Job Assignments (Expenses)</h3>
        <div id="expense-container"></div>
        
        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <input type="text" id="new-group-name" placeholder="New Category (e.g. Health)" style="flex-grow: 1; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); font-size: 0.95em;">
            <button class="btn" onclick="addExpenseGroup()" style="white-space: nowrap;">Add Category</button>
        </div>
    </div>

    <!-- Panel 3: Yearly Subs -->
    <div class="budget-panel">
        <h3 style="color: var(--warning); margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <span>📅 Yearly Subscriptions <small style="font-size:0.6em; color:var(--text-muted); display:block; margin-top:2px;">(Monthly Set-Aside)</small></span>
            <button class="del-btn" style="font-size:0.65em; color: var(--accent);" onclick="openBulkPasteModal('YEARLY_SUBS')">📋 Bulk Paste</button>
        </h3>
        <div id="yearly-sub-container"></div>
        <button class="add-btn" style="color: var(--warning); border-color: var(--warning); background: rgba(234, 179, 8, 0.1);" onclick="addYearlySub()">+ Add Yearly Sub</button>
    </div>
</div>

<!-- Bulk Paste Modal -->
<div id="bulkPasteModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bulk Paste Items</h3>
            <button class="close-modal" onclick="closeBulkPasteModal()">✕</button>
        </div>
        <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 15px;">
            Paste multiple lines directly from Excel or a text list. <br>
            Expected format: <code>Item Name [Tab or Comma] Amount</code>
        </p>
        <input type="hidden" id="bulkPasteGroup">
        <textarea id="bulkPasteText" rows="10" placeholder="Netflix   15.99&#10;Water Bill   45.00&#10;Groceries   250" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); margin-bottom: 15px; outline: none; font-family: monospace;"></textarea>
        <button class="btn" style="width: 100%; background: var(--accent); color: white;" onclick="processBulkPaste()">Import Items</button>
    </div>
</div>

<script>
    let budgetData = { income: [], expenses: [], yearly_subs: [] };
    let currentBudgetId = null;
    let saveTimeout = null;

    document.addEventListener("DOMContentLoaded", () => {
        loadBudget();
    });

    function loadBudget() {
        const year = document.getElementById('budget-year').value;
        const month = document.getElementById('budget-month').value;
        
        fetch(`budget.php?api=load&year=${year}&month=${month}`)
            .then(res => res.json())
            .then(resData => {
                currentBudgetId = resData.id;
                budgetData = resData.data;
                if (!budgetData.yearly_subs) budgetData.yearly_subs = [];
                renderUI();
            });
    }

    function shareBudget() {
        if (!currentBudgetId) {
            appAlert("Please wait for the budget to save before sharing.");
            triggerSave();
            return;
        }
        if (typeof generateShareLink === 'function') {
            generateShareLink('budget', currentBudgetId);
        } else {
            appAlert("Sharing is not available right now.");
        }
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
        fetch(`budget.php?api=load&base=1`).then(res => res.json()).then(resData => {
            budgetData = resData.data; 
            if (!budgetData.yearly_subs) budgetData.yearly_subs = [];
            renderUI(); 
            triggerSave(); 
        });
    }

    function renderUI() {
        renderIncome();
        renderExpenses();
        renderYearlySubs();
        calculateTotals();
    }

    function renderIncome() {
        const container = document.getElementById('income-container');
        container.innerHTML = '';
        if (!budgetData.income) budgetData.income = [];
        budgetData.income.forEach((item, index) => {
            container.innerHTML += `
                <div class="budget-item">
                    <input type="text" value="${item.name}" placeholder="Income Name" oninput="updateIncome(${index}, 'name', this.value)">
                    <div style="display:flex; align-items:center; gap:5px; flex-shrink: 0;">
                        <span style="color:var(--text-muted); font-size:0.9em;">$</span>
                        <input type="number" step="0.01" min="0" value="${item.amount || ''}" placeholder="0.00" oninput="updateIncome(${index}, 'amount', this.value)">
                        <button class="del-btn" onclick="removeIncome(${index})" title="Remove">✕</button>
                    </div>
                </div>
            `;
        });
    }

    function addIncome() {
        if (!budgetData.income) budgetData.income = [];
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
        if (!budgetData.expenses) budgetData.expenses = [];
        
        const grouped = {};
        budgetData.expenses.forEach((item, index) => {
            let grp = item.group || 'Uncategorized';
            if (!grouped[grp]) grouped[grp] = [];
            item.originalIndex = index; 
            grouped[grp].push(item);
        });

        for (const [groupName, items] of Object.entries(grouped)) {
            let html = `
                <div class="group-header">
                    <span>${groupName}</span>
                    <div style="display:flex; gap:8px;">
                        <button class="del-btn" style="font-size:0.85em; color: var(--accent);" onclick="openBulkPasteModal('${groupName.replace(/'/g, "\\'")}')">📋 Paste</button>
                        <button class="del-btn" style="font-size:0.9em;" onclick="addExpenseItem('${groupName.replace(/'/g, "\\'")}')">+ Add</button>
                    </div>
                </div>`;
                
            items.forEach(item => {
                html += `
                    <div class="budget-item">
                        <input type="text" value="${item.name}" placeholder="Expense Name" oninput="updateExpense(${item.originalIndex}, 'name', this.value)">
                        <div style="display:flex; align-items:center; gap:5px; flex-shrink: 0;">
                            <span style="color:var(--text-muted); font-size:0.9em;">$</span>
                            <input type="number" step="0.01" min="0" value="${item.amount || ''}" placeholder="0.00" oninput="updateExpense(${item.originalIndex}, 'amount', this.value)">
                            <button class="del-btn" onclick="removeExpense(${item.originalIndex})" title="Remove">✕</button>
                        </div>
                    </div>
                `;
            });
            container.innerHTML += html;
        }
    }

    function addExpenseGroup() {
        const input = document.getElementById('new-group-name');
        if (!input.value.trim()) return;
        if (!budgetData.expenses) budgetData.expenses = [];
        budgetData.expenses.push({ id: 'exp_' + Date.now(), group: input.value.trim(), name: '', amount: 0 });
        input.value = ''; renderUI(); triggerSave();
    }

    function addExpenseItem(groupName) {
        if (!budgetData.expenses) budgetData.expenses = [];
        budgetData.expenses.push({ id: 'exp_' + Date.now(), group: groupName, name: '', amount: 0 });
        renderUI(); triggerSave();
    }

    function removeExpense(index) {
        budgetData.expenses.splice(index, 1);
        renderUI(); triggerSave();
    }

    function updateExpense(index, field, value) {
        budgetData.expenses[index][field] = field === 'amount' ? parseFloat(value) || 0 : value;
        if (field === 'amount') calculateTotals();
        triggerSave();
    }

    function renderYearlySubs() {
        const container = document.getElementById('yearly-sub-container');
        container.innerHTML = '';
        if (!budgetData.yearly_subs) budgetData.yearly_subs = [];

        budgetData.yearly_subs.forEach((item, index) => {
            let monthly = (parseFloat(item.annual_amount) || 0) / 12;
            container.innerHTML += `
                <div class="budget-item" style="flex-direction: column; align-items: stretch; gap: 8px; padding: 12px;">
                    <div style="display: flex; gap: 8px; align-items: center; width: 100%;">
                        <input type="text" value="${item.name || ''}" placeholder="Subscription Name" oninput="updateYearlySub(${index}, 'name', this.value)" style="flex-grow: 1; font-weight: bold;">
                        <button class="del-btn" onclick="removeYearlySub(${index})" title="Remove">✕</button>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; width: 100%;">
                        <input type="month" value="${item.renewal_month || ''}" onchange="updateYearlySub(${index}, 'renewal_month', this.value)" style="padding: 4px; border-radius: 4px; background: var(--card-bg); color: var(--text-main); border: 1px solid var(--border-color); font-size: 0.85em; outline: none; flex-grow: 1;" title="Renewal Month">
                        <div style="display:flex; align-items:center; gap:4px; flex-shrink: 0;">
                            <span style="color:var(--text-muted); font-size:0.85em;">Yr: $</span>
                            <input type="number" step="0.01" min="0" value="${item.annual_amount || ''}" placeholder="0.00" oninput="updateYearlySub(${index}, 'annual_amount', this.value)">
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 0.85em; color: var(--warning); font-weight: bold; margin-top: 4px;">
                        Monthly Set-Aside: +$${monthly.toFixed(2)}
                    </div>
                </div>
            `;
        });
    }

    function addYearlySub() {
        if (!budgetData.yearly_subs) budgetData.yearly_subs = [];
        budgetData.yearly_subs.push({ id: 'sub_' + Date.now(), name: '', annual_amount: 0, renewal_month: '' });
        renderUI(); triggerSave();
    }

    function removeYearlySub(index) {
        budgetData.yearly_subs.splice(index, 1);
        renderUI(); triggerSave();
    }

    function updateYearlySub(index, field, value) {
        budgetData.yearly_subs[index][field] = field === 'annual_amount' ? parseFloat(value) || 0 : value;
        if (field === 'annual_amount') calculateTotals();
        triggerSave();
    }

    function openBulkPasteModal(groupName) {
        document.getElementById('bulkPasteGroup').value = groupName;
        document.getElementById('bulkPasteText').value = '';
        document.getElementById('bulkPasteModal').style.display = 'flex';
    }

    function closeBulkPasteModal() {
        document.getElementById('bulkPasteModal').style.display = 'none';
        document.getElementById('bulkPasteText').value = '';
    }

    function processBulkPaste() {
        const groupName = document.getElementById('bulkPasteGroup').value;
        const text = document.getElementById('bulkPasteText').value;
        if (!text.trim()) return;

        const lines = text.split('\n');
        let added = 0;

        lines.forEach(line => {
            line = line.trim();
            if (!line) return;

            let name = line;
            let amount = 0;

            let parts = line.split('\t');
            if (parts.length >= 2) {
                name = parts[0].trim();
                amount = parseFloat(parts[parts.length - 1].replace(/[^0-9.-]+/g,"")) || 0;
            } else {
                parts = line.split(',');
                if (parts.length >= 2) {
                    let possibleAmt = parseFloat(parts[parts.length - 1].replace(/[^0-9.-]+/g,""));
                    if (!isNaN(possibleAmt)) {
                        amount = possibleAmt;
                        parts.pop();
                        name = parts.join(',').trim();
                    }
                } else {
                    let match = line.match(/(.+?)\s+\$?([0-9,.]+)$/);
                    if (match) {
                        name = match[1].trim();
                        amount = parseFloat(match[2].replace(/[^0-9.-]+/g,"")) || 0;
                    }
                }
            }

            if (groupName === 'YEARLY_SUBS') {
                if (!budgetData.yearly_subs) budgetData.yearly_subs = [];
                budgetData.yearly_subs.push({ 
                    id: 'sub_' + Date.now() + Math.random(), 
                    name: name, 
                    annual_amount: amount, 
                    renewal_month: ''
                });
            } else {
                if (!budgetData.expenses) budgetData.expenses = [];
                budgetData.expenses.push({ 
                    id: 'exp_' + Date.now() + Math.random(), 
                    group: groupName, 
                    name: name, 
                    amount: amount
                });
            }
            added++;
        });

        if (added > 0) {
            renderUI();
            triggerSave();
            closeBulkPasteModal();
        }
    }

    function calculateTotals() {
        let totalInc = 0; 
        let totalExp = 0;

        if (budgetData.income) {
            budgetData.income.forEach(i => totalInc += parseFloat(i.amount) || 0);
        }
        
        if (budgetData.expenses) {
            budgetData.expenses.forEach(e => {
                let amt = parseFloat(e.amount) || 0;
                totalExp += amt;
            });
        }
        
        if (budgetData.yearly_subs) {
            budgetData.yearly_subs.forEach(sub => {
                let mo_amt = (parseFloat(sub.annual_amount) || 0) / 12;
                totalExp += mo_amt;
            });
        }
        
        let remaining = totalInc - totalExp;
        document.getElementById('sum-income').innerText = '$' + totalInc.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sum-expenses').innerText = '$' + totalExp.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
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
                currentBudgetId = res.id;
                const statusEl = document.getElementById('save-status');
                statusEl.innerText = "💾 Saved";
                setTimeout(() => { statusEl.classList.remove('visible'); }, 2000);
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>