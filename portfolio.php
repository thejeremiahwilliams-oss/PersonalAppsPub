<?php
// public/portfolio.php

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_scenarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        scenario_name VARCHAR(255) NOT NULL,
        plan_data LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // Set headers to explicitly prevent caching
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    if ($_GET['api'] === 'list') {
        $stmt = $pdo->prepare("SELECT id, scenario_name, updated_at FROM portfolio_scenarios WHERE user_id = :uid ORDER BY updated_at DESC");
        $stmt->execute([':uid' => $user_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($_GET['api'] === 'load') {
        $id = $_GET['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("SELECT plan_data, scenario_name FROM portfolio_scenarios WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $id, ':uid' => $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $row ? json_encode(['name' => $row['scenario_name'], 'data' => json_decode($row['plan_data'])]) : json_encode(null);
        } else { echo json_encode(null); }
        exit;
    }
    if ($_GET['api'] === 'save') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $name = trim($payload['name'] ?? 'Untitled Scenario');
        $data = json_encode($payload['data'] ?? []);
        $id = $payload['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("UPDATE portfolio_scenarios SET scenario_name = :name, plan_data = :data WHERE id = :id AND user_id = :uid");
            $stmt->execute([':name' => $name, ':data' => $data, ':id' => $id, ':uid' => $user_id]);
            echo json_encode(['status' => 'success', 'id' => $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO portfolio_scenarios (user_id, scenario_name, plan_data) VALUES (:uid, :name, :data)");
            $stmt->execute([':uid' => $user_id, ':name' => $name, ':data' => $data]);
            echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        }
        exit;
    }
    if ($_GET['api'] === 'delete') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $id = $payload['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM portfolio_scenarios WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $id, ':uid' => $user_id]);
            echo json_encode(['status' => 'success']);
        }
        exit;
    }
}

$page_title = "Portfolio Calculator - PersonalApps";
include 'includes/header.php';
?>

<style>
    .section-title { font-size: 1.1rem; color: var(--text-main); margin: 30px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid var(--border-color); font-weight: 600; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.95rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; background-color: var(--bg-color); color: var(--text-main); font-family: inherit; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); }

    .scenario-manager { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: rgba(0,0,0,0.05); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 25px; }
    .scenario-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .scenario-controls select, .scenario-controls input { padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); font-size: 0.95rem; }
    
    .account-card { background-color: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; margin-bottom: 15px; }
    .account-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color); }
    .account-header h4 { color: var(--accent); margin: 0; font-size: 1.1rem; }
    .remove-btn { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid var(--danger); padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .remove-btn:hover { background-color: var(--danger); color: white; }

    .add-account-btn { width: 100%; padding: 12px; background-color: transparent; color: var(--accent); border: 2px dashed var(--accent); border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-bottom: 25px; }
    .add-account-btn:hover { background-color: rgba(59, 130, 246, 0.1); }

    .button-group { display: flex; gap: 15px; margin-top: 25px; }

    .results-summary { display: none; flex-direction: row; flex-wrap: nowrap; overflow-x: auto; gap: 15px; margin-top: 30px; margin-bottom: 30px; padding-bottom: 10px; }
    .summary-card { flex: 1; min-width: 180px; background-color: var(--card-bg); padding: 20px 15px; border-radius: 8px; border: 1px solid var(--border-color); text-align: center; display: flex; flex-direction: column; justify-content: flex-start; }
    .summary-card h3 { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
    .summary-card .amount { font-size: 1.35rem; font-weight: 700; color: var(--text-main); }
    .summary-card.highlight { background-color: rgba(34, 197, 94, 0.05); border-color: var(--success); }
    .summary-card.highlight .amount { color: var(--success); }
    .summary-card.info { background-color: rgba(59, 130, 246, 0.05); border-color: var(--accent); }
    .summary-card.info .amount { color: var(--accent); }

    .sub-text { font-size: 0.8rem; color: var(--text-muted); margin-top: 5px; font-weight: 500; }
    .account-breakdown { margin-top: 15px; padding-top: 10px; border-top: 1px dashed var(--border-color); text-align: left; width: 100%; display: flex; flex-direction: column; gap: 6px; }
    .breakdown-row { display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-main); font-weight: 500; }

    .table-container { display: none; width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid var(--border-color); border-radius: 8px; }
    tr.retirement-row { background-color: rgba(234, 179, 8, 0.05); }

    .phase-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-left: 8px; text-transform: uppercase; border: 1px solid; }
    .badge-acc { background: rgba(59, 130, 246, 0.1); color: var(--accent); border-color: var(--accent); }
    .badge-ret { background: rgba(234, 179, 8, 0.1); color: var(--warning); border-color: var(--warning); }
    .badge-empty { background: rgba(239, 68, 68, 0.1); color: var(--danger); border-color: var(--danger); }

    #save-status { font-size: 0.9em; color: var(--success); font-weight: bold; opacity: 0; transition: opacity 0.3s; }
    #save-status.visible { opacity: 1; }
    
    #chart-container { display: none; margin-bottom: 25px; padding: 20px; }
</style>

<div class="page-header" style="justify-content: flex-start; gap: 20px;">
    <h2>Retirement Portfolio Calculator</h2>
    <span id="save-status">💾 Saved to Database</span>
</div>

<div class="card">
    <div class="scenario-manager">
        <div class="scenario-controls">
            <select id="scenario-select">
                <option value="">-- Select Saved Scenario --</option>
            </select>
            <button type="button" class="btn btn-small" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="loadSelectedScenario()">Load</button>
            <button type="button" class="btn btn-small btn-danger" onclick="deleteSelectedScenario()">Delete</button>
        </div>
        <div class="scenario-controls">
            <input type="text" id="scenario-name" placeholder="Scenario Name (e.g., Aggressive)">
            <input type="hidden" id="scenario-id" value="">
            <button type="button" class="btn btn-small" style="background: var(--success);" onclick="saveScenario(false)">Update Current</button>
            <button type="button" class="btn btn-small" style="background: var(--accent);" onclick="saveScenario(true)">Save as New</button>
        </div>
    </div>

    <form id="calculator-form">
        <div class="section-title" style="margin-top: 0;">Phase 1: Accumulation</div>
        <div class="form-row" style="margin-bottom: 25px;">
            <div class="form-group">
                <label>Current Age</label>
                <input type="number" id="current-age" value="30" min="18" step="1" required>
            </div>
            <div class="form-group">
                <label>Target Retirement Age</label>
                <input type="number" id="retirement-age" value="62" min="18" step="1" required>
            </div>
        </div>

        <div id="accounts-container"></div>
        <button type="button" class="add-account-btn" onclick="addAccount()">+ Add Another Account</button>

        <div class="section-title">Phase 2: Retirement Drawdown</div>
        <div class="form-row">
            <div class="form-group">
                <label>Initial Withdrawal Rate (%)</label>
                <input type="number" id="withdrawal-rate" value="4" min="0" step="0.1" required>
            </div>
            <div class="form-group">
                <label>Post-Retirement Return (%)</label>
                <input type="number" id="post-rate" value="5" min="0" step="0.1" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Years in Retirement</label>
                <input type="number" id="retirement-years" value="30" min="1" step="1" required>
            </div>
            <div class="form-group">
                <label>Table View</label>
                <select id="view-type">
                    <option value="yearly">Yearly Breakdown</option>
                    <option value="monthly">Monthly Breakdown</option>
                </select>
            </div>
        </div>

        <div class="button-group">
            <button type="submit" class="btn" style="flex: 2; padding: 15px; font-size: 1.1rem;">Run Math & Calculate Timeline</button>
            <button type="button" class="btn" id="export-csv-btn" onclick="exportTableToCSV('retirement_plan.csv')" style="flex: 1; background: var(--success); padding: 15px; font-size: 1.1rem; display: none; justify-content: center; align-items: center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Export CSV
            </button>
        </div>
    </form>
</div>

<div id="results-summary" class="results-summary">
    <div class="summary-card info">
        <h3>Total Invested</h3>
        <div id="total-invested" class="amount">$0</div>
        <div class="sub-text">Out of pocket cash</div>
    </div>
    <div class="summary-card highlight">
        <h3>Nest Egg</h3>
        <div id="nest-egg" class="amount">$0</div>
        <div class="account-breakdown" id="nest-egg-breakdown"></div>
    </div>
    <div class="summary-card">
        <h3>Est. Income</h3>
        <div id="annual-income" class="amount">$0</div>
        <div class="sub-text" id="monthly-income">($0 / month)</div>
    </div>
    <div class="summary-card">
        <h3>Total Withdrawn</h3>
        <div id="total-withdrawn" class="amount">$0</div>
        <div class="sub-text">During retirement</div>
    </div>
    <div class="summary-card">
        <h3>Estate Balance</h3>
        <div id="final-balance" class="amount">$0</div>
        <div class="sub-text">At end of plan</div>
    </div>
</div>

<div id="chart-container" class="card">
    <h3 style="margin-top: 0; color: var(--text-main); font-size: 1.1em; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Growth Projection</h3>
    <canvas id="portfolioChart" style="max-height: 400px; width: 100%;"></canvas>
</div>

<div id="table-container" class="table-container card" style="padding: 0;">
    <table id="results-table" class="mobile-card-table">
        <thead>
            <tr>
                <th id="period-header">Period</th>
                <th>Start Balance</th>
                <th>Contributions</th>
                <th>Withdrawals</th>
                <th>Interest</th>
                <th>End Balance</th>
            </tr>
        </thead>
        <tbody id="breakdown-body"></tbody>
    </table>
</div>

<script>
    const formatter = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
    const typeLabels = { '401k': 'Pre-Tax', 'roth': 'Tax-Free', 'brokerage': 'Taxable' };

    let accountCount = 0;
    const accountsContainer = document.getElementById('accounts-container');
    let chartInstance = null;

    window.addEventListener('DOMContentLoaded', () => {
        loadScenarioList();
        addDefaultAccounts(); 
    });

    // Added a timestamp parameter to force mobile browsers to bypass the cache
    function loadScenarioList(selectedId = null) {
        const cacheBuster = Date.now();
        fetch(`portfolio.php?api=list&t=${cacheBuster}`, { cache: 'no-store' })
            .then(res => res.json())
            .then(list => {
                const select = document.getElementById('scenario-select');
                select.innerHTML = '<option value="">-- Select Saved Scenario --</option>';
                list.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.id;
                    opt.textContent = item.scenario_name;
                    if (selectedId && String(item.id) === String(selectedId)) opt.selected = true;
                    select.appendChild(opt);
                });
            });
    }

    function loadSelectedScenario() {
        const id = document.getElementById('scenario-select').value;
        if (!id) return;
        
        const cacheBuster = Date.now();
        fetch(`portfolio.php?api=load&id=${id}&t=${cacheBuster}`, { cache: 'no-store' })
            .then(res => res.json())
            .then(response => {
                if (response && response.data) {
                    const scenario = response.data;
                    document.getElementById('scenario-id').value = id;
                    document.getElementById('scenario-name').value = response.name;

                    document.getElementById('current-age').value = scenario.currentAge;
                    document.getElementById('retirement-age').value = scenario.retirementAge;
                    document.getElementById('withdrawal-rate').value = scenario.withdrawalRate;
                    document.getElementById('post-rate').value = scenario.postRate;
                    document.getElementById('retirement-years').value = scenario.retirementYears;
                    document.getElementById('view-type').value = scenario.viewType;

                    accountsContainer.innerHTML = '';
                    accountCount = 0;
                    if (scenario.accounts && scenario.accounts.length > 0) {
                        scenario.accounts.forEach(acc => {
                            addAccount(acc.name, acc.type, acc.balance, acc.contrib, acc.rate);
                        });
                    } else {
                        addDefaultAccounts();
                    }
                    
                    // Directly run the math function instead of dispatching a form event
                    calculatePortfolio();
                }
            });
    }

    async function saveScenario(saveAsNew = false) {
        let name = document.getElementById('scenario-name').value.trim();
        if (!name) {
            name = await appPrompt("Please enter a name for this scenario:", "Aggressive Plan");
            if (!name) return;
            document.getElementById('scenario-name').value = name;
        }

        let currentId = document.getElementById('scenario-id').value;
        if (saveAsNew) currentId = '';

        const accountNodes = document.querySelectorAll('.account-card');
        const accountsData = Array.from(accountNodes).map(card => ({
            name: card.querySelector('.acc-name').value,
            type: card.querySelector('.acc-type').value,
            balance: parseFloat(card.querySelector('.acc-balance').value),
            contrib: parseFloat(card.querySelector('.acc-contrib').value),
            rate: parseFloat(card.querySelector('.acc-rate').value)
        }));

        const scenarioData = {
            currentAge: document.getElementById('current-age').value,
            retirementAge: document.getElementById('retirement-age').value,
            withdrawalRate: document.getElementById('withdrawal-rate').value,
            postRate: document.getElementById('post-rate').value,
            retirementYears: document.getElementById('retirement-years').value,
            viewType: document.getElementById('view-type').value,
            accounts: accountsData
        };

        const payload = { id: currentId, name: name, data: scenarioData };

        fetch('portfolio.php?api=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                document.getElementById('scenario-id').value = res.id;
                const statusEl = document.getElementById('save-status');
                statusEl.classList.add('visible');
                setTimeout(() => { statusEl.classList.remove('visible'); }, 2000);
                loadScenarioList(res.id); 
            }
        });
    }

    async function deleteSelectedScenario() {
        const id = document.getElementById('scenario-select').value;
        if (!id) {
            appAlert("Select a scenario to delete first.");
            return;
        }
        
        const confirmed = await appConfirm("Are you sure you want to permanently delete this scenario?");
        if (!confirmed) return;

        fetch('portfolio.php?api=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                document.getElementById('scenario-id').value = '';
                document.getElementById('scenario-name').value = '';
                loadScenarioList();
            }
        });
    }

    function addAccount(defaultName = '', defaultType = '401k', defaultBal = 0, defaultContrib = 0, defaultRate = 8) {
        accountCount++;
        const id = accountCount;
        const name = defaultName || `Account #${id}`;
        
        const div = document.createElement('div');
        div.className = 'account-card';
        div.id = `account-${id}`;
        div.innerHTML = `
            <div class="account-header">
                <h4>${name}</h4>
                ${id > 1 ? `<button type="button" class="remove-btn" onclick="removeAccount(${id})">Remove</button>` : ''}
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Account Name</label>
                    <input type="text" class="acc-name" value="${name}" required>
                </div>
                <div class="form-group">
                    <label>Account Type</label>
                    <select class="acc-type">
                        <option value="401k" ${defaultType === '401k' ? 'selected' : ''}>401(k) / Trad IRA (Pre-Tax)</option>
                        <option value="roth" ${defaultType === 'roth' ? 'selected' : ''}>Roth 401(k) / Roth IRA (Tax-Free)</option>
                        <option value="brokerage" ${defaultType === 'brokerage' ? 'selected' : ''}>Brokerage (Taxable)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Current Savings ($)</label>
                    <input type="number" class="acc-balance" value="${defaultBal}" min="0" step="" required>
                </div>
                <div class="form-group">
                    <label>Monthly Contribution ($)</label>
                    <input type="number" class="acc-contrib" value="${defaultContrib}" min="0" step="1" required>
                </div>
            </div>
            <div class="form-group">
                <label>Pre-Retirement Annual Return (%)</label>
                <input type="number" class="acc-rate" value="${defaultRate}" min="0" step="0.1" required>
            </div>
        `;
        accountsContainer.appendChild(div);
    }

    function removeAccount(id) {
        const el = document.getElementById(`account-${id}`);
        if(el) el.remove();
    }

    function addDefaultAccounts() {
        accountsContainer.innerHTML = '';
        accountCount = 0;
        addAccount('Main 401(k)', '401k', 50000, 800, 8);
        addAccount('Roth IRA', 'roth', 10000, 200, 8);
    }

    function exportTableToCSV(filename) {
        let csv = [];
        const rows = document.querySelectorAll("#results-table tr");
        
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll("td, th");
            for (let j = 0; j < cols.length; j++) {
                let clone = cols[j].cloneNode(true);
                let badge = clone.querySelector('.phase-badge');
                if (badge) clone.removeChild(badge);
                let data = clone.innerText.replace(/(\r\n|\n|\r)/gm, "").trim();
                row.push('"' + data.replace(/"/g, '""') + '"');
            }
            csv.push(row.join(","));
        }

        let csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
        let downloadLink = document.createElement("a");
        downloadLink.download = filename;
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = "none";
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }

    // Abstracted math logic into its own function so it can be called safely
    function calculatePortfolio() {
        const accountNodes = document.querySelectorAll('.account-card');
        let accounts = [];
        let totalCashInvested = 0;

        accountNodes.forEach(card => {
            let initialBal = parseFloat(card.querySelector('.acc-balance').value);
            accounts.push({
                name: card.querySelector('.acc-name').value,
                type: card.querySelector('.acc-type').value,
                balance: initialBal,
                monthlyContrib: parseFloat(card.querySelector('.acc-contrib').value),
                rate: parseFloat(card.querySelector('.acc-rate').value) / 100,
                totalInvested: initialBal
            });
            totalCashInvested += initialBal;
        });

        const currentAge = parseInt(document.getElementById('current-age').value);
        const retirementAge = parseInt(document.getElementById('retirement-age').value);
        const growYears = Math.max(0, retirementAge - currentAge);
        
        const withdrawalRate = parseFloat(document.getElementById('withdrawal-rate').value) / 100;
        const postRate = parseFloat(document.getElementById('post-rate').value) / 100;
        const retYears = parseInt(document.getElementById('retirement-years').value);
        const viewType = document.getElementById('view-type').value;

        const accMonths = growYears * 12;
        const totalMonths = (growYears + retYears) * 12;
        
        let nestEgg = 0;
        let globalTargetMonthlyWithdrawal = 0;
        let totalWithdrawn = 0;
        let nestEggBreakdown = [];
        
        let tableHTML = '';
        let periodStartBalance = accounts.reduce((sum, acc) => sum + acc.balance, 0);
        let periodContrib = 0;
        let periodWithdrawal = 0;
        let periodInterest = 0;
        
        // Chart Data Arrays
        let cLabels = [];
        let cInvested = [];
        let cBalance = [];

        for (let month = 1; month <= totalMonths; month++) {
            let isRetirement = month > accMonths;
            let totalCurrentBalance = accounts.reduce((sum, acc) => sum + acc.balance, 0);

            if (month === accMonths + 1) {
                nestEgg = totalCurrentBalance;
                const annualIncome = nestEgg * withdrawalRate;
                globalTargetMonthlyWithdrawal = annualIncome / 12;
                nestEggBreakdown = accounts.map(a => ({ name: a.name, balance: a.balance, type: a.type }));
            }

            let monthlyWithdrawal = isRetirement ? globalTargetMonthlyWithdrawal : 0;
            if (isRetirement && totalCurrentBalance < monthlyWithdrawal) {
                monthlyWithdrawal = totalCurrentBalance;
            }

            let currentMonthWithdrawalTotal = 0;

            accounts.forEach(acc => {
                let currentRate = isRetirement ? (postRate / 12) : (acc.rate / 12);
                let interestEarned = acc.balance * currentRate;
                periodInterest += interestEarned;
                
                let contrib = isRetirement ? 0 : acc.monthlyContrib;
                periodContrib += contrib;
                if (!isRetirement) {
                    totalCashInvested += contrib;
                }
                
                let withdrawal = 0;
                if (isRetirement && totalCurrentBalance > 0) {
                    let share = acc.balance / totalCurrentBalance;
                    withdrawal = monthlyWithdrawal * share;
                }
                
                currentMonthWithdrawalTotal += withdrawal;
                periodWithdrawal += withdrawal; 
                
                acc.balance += interestEarned + contrib - withdrawal;
                if (acc.balance < 0.01) acc.balance = 0; 
            });

            totalWithdrawn += currentMonthWithdrawalTotal;
            let totalEndBalance = accounts.reduce((sum, acc) => sum + acc.balance, 0);

            let renderRow = false;
            let periodLabel = '';
            let plainLabel = '';
            let isDepleted = isRetirement && totalEndBalance <= 0;

            if (viewType === 'monthly') {
                renderRow = true;
                if (!isRetirement) {
                    plainLabel = `Month ${month}`;
                    periodLabel = `${plainLabel} (Age ${currentAge + Math.floor(month/12)}) <span class="phase-badge badge-acc">Grow</span>`;
                } else {
                    let rMonth = month - accMonths;
                    plainLabel = `Month ${rMonth} (Ret.)`;
                    let badge = isDepleted ? `<span class="phase-badge badge-empty">Depleted</span>` : `<span class="phase-badge badge-ret">Retire</span>`;
                    periodLabel = `${plainLabel} (Age ${retirementAge + Math.floor(rMonth/12)}) ${badge}`;
                }
            } else if (viewType === 'yearly' && month % 12 === 0) {
                renderRow = true;
                const year = month / 12;
                if (!isRetirement) {
                    plainLabel = `Year ${year}`;
                    periodLabel = `${plainLabel} (Age ${currentAge + year}) <span class="phase-badge badge-acc">Grow</span>`;
                } else {
                    let rYear = year - growYears;
                    plainLabel = `Year ${rYear} (Ret.)`;
                    let badge = isDepleted ? `<span class="phase-badge badge-empty">Depleted</span>` : `<span class="phase-badge badge-ret">Retire</span>`;
                    periodLabel = `${plainLabel} (Age ${retirementAge + rYear}) ${badge}`;
                }
            }

            if (renderRow) {
                let rowClass = isRetirement ? 'retirement-row' : '';
                tableHTML += `
                    <tr class="${rowClass}">
                        <td class="period-col" data-label="Period">${periodLabel}</td>
                        <td data-label="Start Balance">${formatter.format(periodStartBalance)}</td>
                        <td data-label="Contributions">${formatter.format(periodContrib)}</td>
                        <td data-label="Withdrawals">${formatter.format(periodWithdrawal)}</td>
                        <td data-label="Interest">${formatter.format(periodInterest)}</td>
                        <td data-label="End Balance">${formatter.format(totalEndBalance)}</td>
                    </tr>
                `;
                
                cLabels.push(plainLabel);
                cInvested.push(totalCashInvested);
                cBalance.push(totalEndBalance);

                periodStartBalance = totalEndBalance;
                periodContrib = 0;
                periodWithdrawal = 0;
                periodInterest = 0;
                
                if (isDepleted && viewType === 'yearly') break;
            }
        }

        if (growYears === 0) {
             nestEgg = accounts.reduce((sum, acc) => sum + acc.totalInvested, 0);
             nestEggBreakdown = accounts.map(a => ({ name: a.name, balance: a.balance, type: a.type }));
        }

        const annualIncomeDisplay = (nestEgg * withdrawalRate);
        
        let breakdownHTML = '';
        nestEggBreakdown.forEach(acc => {
            if (acc.balance > 0) {
                breakdownHTML += `
                    <div class="breakdown-row">
                        <span>${acc.name} <span style="color:var(--text-muted);font-weight:400">(${typeLabels[acc.type]})</span></span>
                        <span>${formatter.format(acc.balance)}</span>
                    </div>
                `;
            }
        });

        document.getElementById('total-invested').textContent = formatter.format(totalCashInvested);
        document.getElementById('nest-egg').textContent = formatter.format(nestEgg);
        document.getElementById('nest-egg-breakdown').innerHTML = breakdownHTML;
        
        document.getElementById('annual-income').textContent = formatter.format(annualIncomeDisplay) + ' / yr';
        document.getElementById('monthly-income').textContent = `(${formatter.format(annualIncomeDisplay / 12)} / month)`;
        document.getElementById('total-withdrawn').textContent = formatter.format(totalWithdrawn);
        document.getElementById('final-balance').textContent = formatter.format(accounts.reduce((sum, acc) => sum + acc.balance, 0));

        document.getElementById('period-header').textContent = viewType === 'monthly' ? 'Period (Monthly)' : 'Period (Yearly)';
        document.getElementById('breakdown-body').innerHTML = tableHTML;
        
        document.getElementById('results-summary').style.display = 'flex';
        document.getElementById('table-container').style.display = 'block';
        document.getElementById('export-csv-btn').style.display = 'flex';
        
        // Render Chart
        document.getElementById('chart-container').style.display = 'block';
        const ctx = document.getElementById('portfolioChart').getContext('2d');
        if (chartInstance) chartInstance.destroy();
        
        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: cLabels,
                datasets: [
                    {
                        label: 'Total End Balance',
                        data: cBalance,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Cash Invested',
                        data: cInvested,
                        borderColor: '#3b82f6',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-main') } },
                    tooltip: { callbacks: { label: function(context) { return formatter.format(context.raw); } } }
                },
                scales: {
                    x: { ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted') }, grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--border-color') } },
                    y: { ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted'), callback: function(value) { return '$' + (value / 1000) + 'k'; } }, grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--border-color') } }
                }
            }
        });
    }

    // Bind form submit to execute the save and the math calculations
    document.getElementById('calculator-form').addEventListener('submit', function(e) {
        e.preventDefault();
        saveScenario();
        calculatePortfolio();
    });
</script>

<?php include 'includes/footer.php'; ?>