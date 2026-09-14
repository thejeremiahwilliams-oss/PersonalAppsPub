<?php
// timesheet.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$db = new Database();
$pdo = $db->getConnection();

// --- API ENDPOINTS FOR SAVING/LOADING DATA ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // -- Session Ping Endpoint --
    if ($_GET['api'] == 'check_session') {
        echo json_encode(['status' => 'active']);
        exit;
    }

    // -- Standard API Endpoints --
    if ($_GET['api'] == 'save_draft') {
        $json_data = file_get_contents('php://input');
        $stmt = $pdo->prepare("UPDATE users SET active_draft = ? WHERE id = ?");
        $stmt->execute([$json_data, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['api'] == 'save_template') {
        $json_data = file_get_contents('php://input');
        $stmt = $pdo->prepare("UPDATE users SET template_data = ? WHERE id = ?");
        $stmt->execute([$json_data, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_GET['api'] == 'save_history') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $history_id = !empty($payload['id']) ? (int)$payload['id'] : null;

        if ($history_id) {
            // Update existing record
            $stmt = $pdo->prepare("UPDATE saved_timesheets SET sheet_name = ?, sheet_data = ?, date_saved = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$payload['name'], json_encode($payload['data']), $payload['dateSaved'], $history_id, $user_id]);
            log_audit_action($pdo, $user_id, 'UPDATE_TIMESHEET', 'Timesheet', ['sheet_name' => $payload['name']]);
            echo json_encode(['status' => 'success', 'id' => $history_id]);
        } else {
            // Insert new record
            $stmt = $pdo->prepare("INSERT INTO saved_timesheets (user_id, sheet_name, date_saved, sheet_data) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $payload['name'], $payload['dateSaved'], json_encode($payload['data'])]);
            $new_id = $pdo->lastInsertId();
            log_audit_action($pdo, $user_id, 'SAVE_TIMESHEET', 'Timesheet', ['sheet_name' => $payload['name']]);
            echo json_encode(['status' => 'success', 'id' => $new_id]);
        }
        exit;
    }

    if ($_GET['api'] == 'delete_history') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("DELETE FROM saved_timesheets WHERE id = ? AND user_id = ?");
        $stmt->execute([$payload['id'], $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_GET['api'] == 'load') {
        $stmt = $pdo->prepare("SELECT active_draft, template_data FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $activeData = $user['active_draft'] ? json_decode($user['active_draft']) : [];
        $templateData = $user['template_data'] ? json_decode($user['template_data']) : null;

        $stmt = $pdo->prepare("SELECT id, sheet_name as name, date_saved as dateSaved, sheet_data as data FROM saved_timesheets WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $historyData = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['data'] = json_decode($row['data']);
            $historyData[] = $row;
        }

        echo json_encode([
            'activeData' => $activeData, 
            'historyData' => $historyData, 
            'templateData' => $templateData
        ]);
        exit;
    }

    if ($_GET['api'] == 'import_backup') {
        $payload = json_decode(file_get_contents('php://input'), true);
        
        if (isset($payload['activeData'])) {
            $stmt = $pdo->prepare("UPDATE users SET active_draft = ? WHERE id = ?");
            $stmt->execute([json_encode($payload['activeData']), $user_id]);
        }
        
        if (isset($payload['historyData'])) {
            $stmt = $pdo->prepare("INSERT INTO saved_timesheets (user_id, sheet_name, date_saved, sheet_data) VALUES (?, ?, ?, ?)");
            foreach($payload['historyData'] as $hist) {
                $stmt->execute([$user_id, $hist['name'], $hist['dateSaved'], json_encode($hist['data'])]);
            }
        }
        log_audit_action($pdo, $user_id, 'IMPORT_TIMESHEET_BACKUP', 'Timesheet');
        echo json_encode(['status' => 'success']);
        exit;
    }
}

$page_title = "Timesheet - PersonalApps";
include 'includes/header.php';
?>

<!-- Include html2pdf library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    /* Native PersonalApps UI Integrations */
    .ts-header-area { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px; width: 100%; }
    .ts-controls-container { display: flex; flex-direction: row; flex-wrap: wrap; justify-content: space-between; width: 100%; gap: 12px; }
    .ts-controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    
    .ts-btn { padding: 10px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9em; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; color: white; }
    .btn-clock { background-color: var(--warning); } .btn-clock:hover { opacity: 0.8; }
    .btn-add { background-color: var(--accent); } .btn-add:hover { opacity: 0.8; }
    .btn-save { background-color: var(--success); } .btn-save:hover { opacity: 0.8; }
    .btn-template { background-color: #8b5cf6; } .btn-template:hover { opacity: 0.8; }
    .btn-template-save { background-color: #a855f7; } .btn-template-save:hover { opacity: 0.8; }
    .btn-history { background-color: #0ea5e9; } .btn-history:hover { opacity: 0.8; }
    .btn-pdf { background-color: #6366f1; } .btn-pdf:hover { opacity: 0.8; }
    .btn-clear { background-color: var(--danger); } .btn-clear:hover { opacity: 0.8; }

    .timesheet-meta { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap; background: rgba(59, 130, 246, 0.05); padding: 15px; border-radius: 10px; border: 1px solid var(--border-color); }
    .meta-group { display: flex; flex-direction: column; flex: 1; min-width: 150px; }
    .meta-group label { font-size: 0.8em; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; }
    .meta-group input { padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1em; background: var(--bg-color); color: var(--text-main); }

    .ts-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 40px; color: var(--text-main); }
    .ts-table th, .ts-table td { border-bottom: 1px solid var(--border-color); padding: 12px 8px; text-align: center; vertical-align: middle; }
    .ts-table th { background-color: var(--bg-color); color: var(--text-muted); font-weight: 600; font-size: 0.85em; text-transform: uppercase; }
    
    .ts-table input, .ts-table select { width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-family: inherit; font-size: 0.95em; color: var(--text-main); background-color: var(--bg-color); }
    select option { background-color: var(--bg-color); color: var(--text-main); }
    
    .time-group { display: inline-flex; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 6px; overflow: hidden; width: 100%; }
    .time-group select { flex: 1; border: none; background: transparent; padding: 8px 2px; font-family: inherit; font-size: 0.9em; color: var(--text-main); cursor: pointer; outline: none; text-align: center; }
    .time-group select:not(:last-child) { border-right: 1px solid var(--border-color); }

    .total-cell { font-weight: 700; color: var(--text-main); font-size: 1.1em; }
    .action-btns { display: flex; justify-content: center; gap: 5px; flex-wrap: wrap; }
    .icon-btn { background: transparent; border: 1px solid var(--border-color); border-radius: 5px; color: var(--text-muted); cursor: pointer; padding: 4px 6px; font-size: 1.1em; display: flex; align-items: center; justify-content: center; }
    .icon-btn:hover { background-color: var(--bg-color); color: var(--text-main); }
    .icon-btn.delete:hover { color: var(--danger); border-color: var(--danger); background-color: rgba(239, 68, 68, 0.1); }
    
    .summary-box { display: flex; justify-content: flex-end; gap: 15px; font-size: 1.1em; flex-wrap: wrap; }
    .summary-item { background: var(--bg-color); padding: 14px 20px; border-radius: 10px; border: 1px solid var(--border-color); color: var(--text-muted); }
    .summary-item span { font-weight: 700; color: var(--text-main); margin-left: 8px; font-size: 1.2em; }
    .summary-grand { background: rgba(59, 130, 246, 0.1); border-color: var(--accent); color: var(--accent); }
    .summary-grand span { color: var(--accent); }

    /* Modals */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 15px; }
    .modal-content { background: var(--card-bg); padding: 30px; border-radius: 12px; width: 715px; max-width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid var(--border-color); box-sizing: border-box; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 13px; }
    .modal-header h3 { margin: 0; font-size: 1.3em; color: var(--text-main); } 
    .close-modal { background: none; border: none; font-size: 1.5em; color: var(--text-muted); cursor: pointer; padding: 0; }
    
    .history-save-area { display: flex; gap: 13px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px dashed var(--border-color); flex-wrap: wrap; }
    .history-save-area input { flex-grow: 1; min-width: 200px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-color); color: var(--text-main); }
    .history-list { list-style: none; padding: 0; margin: 0; margin-bottom: 20px; }
    .history-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 10px; flex-wrap: wrap; gap: 10px; }
    .history-info strong { display: block; color: var(--text-main); margin-bottom: 5px; }
    .history-info span { font-size: 0.85em; color: var(--text-muted); } 
    .history-empty { text-align: center; color: var(--text-muted); font-style: italic; padding: 26px 0; }
    
    .backup-controls { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; background: rgba(0,0,0,0.1); padding: 15px; border-radius: 10px; border: 1px dashed var(--border-color); }
    .backup-btn { flex: 1; background: var(--bg-color); border: 1px solid var(--border-color); padding: 10px; border-radius: 6px; cursor: pointer; font-size: 0.9em; font-weight: 600; color: var(--text-main); transition: 0.2s; }
    .backup-btn:hover { border-color: var(--accent); }
    .hidden-input { display: none; }

    /* PDF Overrides */
    .pdf-mode { padding: 20px !important; background: #fff !important; color: #000 !important; }
    .pdf-mode * { color: #000 !important; }
    .pdf-mode input, .pdf-mode select { border: none !important; appearance: none !important; -webkit-appearance: none !important; background: transparent !important; }
    .pdf-mode .timesheet-meta { border: none !important; background: none !important; padding: 0 !important; margin-bottom: 25px !important; display: flex !important; flex-direction: row !important; gap: 40px !important; }
    .pdf-mode table { display: table !important; width: 100% !important; border: 1px solid #aaa !important; border-collapse: collapse !important; }
    
    /* Ensure Header reads clearly on PDF */
    .pdf-mode th { background-color: #f1f5f9 !important; color: #000 !important; font-weight: bold !important; border: 1px solid #ccc !important; padding: 8px !important; }
    .pdf-mode td { border: 1px solid #ccc !important; padding: 8px !important; color: #000 !important; }
    
    .pdf-title { text-align: left; font-size: 28px; font-weight: bold; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; color: #000 !important; }
    
    /* Ensure PDF Totals match correctly */
    .pdf-mode .summary-box { display: flex !important; justify-content: flex-end !important; gap: 20px !important; margin-top: 20px !important; }
    .pdf-mode .summary-item { padding: 10px 15px !important; border: 1px solid #aaa !important; border-radius: 6px !important; background: #f8fafc !important; }
    .pdf-mode .summary-grand { background: #e0f2fe !important; border: 2px solid #000 !important; font-weight: bold !important; }

    @media (max-width: 850px) {
        .ts-header-area { flex-direction: column; align-items: flex-start; gap: 15px; }
        .ts-controls-container { width: 100%; align-items: stretch; justify-content: center; }
        .ts-controls { justify-content: center; width: 100%; }
        .ts-btn { flex: 1 1 calc(50% - 10px); }
        .ts-table, .ts-table thead, .ts-table tbody, .ts-table th, .ts-table td, .ts-table tr { display: block; width: 100% !important; }
        .ts-table thead tr { position: absolute; top: -9999px; left: -9999px; }
        .ts-table tr { margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-color); padding: 5px 0 15px 0; }
        .ts-table td { border: none; border-bottom: 1px solid var(--border-color); position: relative; padding: 10px 15px 10px 40%; text-align: right; min-height: 55px; display: flex; align-items: center; justify-content: flex-end; }
        .ts-table td:last-child { border-bottom: 0; justify-content: center; padding-left: 15px; padding-top: 20px; }
        .ts-table td::before { content: attr(data-label); position: absolute; left: 15px; width: 35%; text-align: left; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 0.8em; }
        .summary-box { flex-direction: column; align-items: stretch; gap: 10px; }
    }
</style>

<div class="page-header" style="border: none; margin-bottom: 0;">
    <h2>Time Tracker</h2>
</div>

<div class="card" id="printable-area">
    <div class="ts-header-area">
        <div class="ts-controls-container">
            <div class="ts-controls">
                <button class="ts-btn btn-clock" onclick="punchClock()">⏱ Clock In / Out</button>
                <button class="ts-btn btn-add" onclick="addRow()">+ Add Day</button>
                <button class="ts-btn btn-pdf" onclick="exportPDF()">Export PDF</button>
                <button class="ts-btn btn-clear" onclick="clearData()">Clear All</button>
            </div>
            <div class="ts-controls">
                <button class="ts-btn btn-save" id="saveBtn" onclick="syncDraftToDB(true)">Save Draft</button>
                <button class="ts-btn btn-history" onclick="openHistoryModal()">Save as Record</button>
                <button class="ts-btn btn-template" onclick="openTemplateModal()">📋 Templates</button>
            </div>
        </div>
    </div>

    <!-- Hidden input to track if we are actively editing a saved history record -->
    <input type="hidden" id="currentHistoryId" value="">

    <div class="timesheet-meta">
        <div class="meta-group" style="flex: 0.5; min-width: 100px;">
            <label>Initials</label>
            <input type="text" id="empInitials" placeholder="JD" maxlength="4" oninput="syncDraftToDB(false)">
        </div>
        <div class="meta-group" style="flex: 2;">
            <label>Employee Name</label>
            <input type="text" id="empName" placeholder="e.g. John Doe" oninput="syncDraftToDB(false)">
        </div>
        <div class="meta-group" style="flex: 1.5;">
            <label>Week/Dates</label>
            <input type="text" id="weekDates" placeholder="e.g. Oct 14 - Oct 20" oninput="syncDraftToDB(false)">
        </div>
    </div>

    <table class="ts-table" id="timeTable">
        <thead>
            <tr>
                <th style="width: 12%;">Date</th>
                <th style="width: 10%;">Type</th>
                <th style="width: 16%;">Project Info</th>
                <th style="width: 17%;">Start Time</th>
                <th style="width: 17%;">End Time</th>
                <th style="width: 7%;">Lunch (Hrs)</th>
                <th style="width: 7%;">Breaks (Hrs)</th>
                <th style="width: 6%;">Total (Hrs)</th>
                <th style="width: 8%;" class="action-col">Manage</th>
            </tr>
        </thead>
        <tbody id="tableBody"></tbody>
    </table>
    
    <div class="summary-box">
        <div class="summary-item">Work: <span id="totalWork">0.00</span></div>
        <div class="summary-item">PTO: <span id="totalPTO">0.00</span></div>
        <div class="summary-item">Holiday: <span id="totalHoliday">0.00</span></div>
        <div class="summary-item">Personal: <span id="totalPersonal">0.00</span></div>
        <div class="summary-item summary-grand">Total Hours: <span id="grandTotal">0.00</span></div>
    </div>
</div>

<!-- Timesheet Database History Modal -->
<div id="historyModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Database History</h3>
            <button class="close-modal" onclick="closeHistoryModal()">✕</button>
        </div>
        <div class="history-save-area">
            <input type="text" id="historyName" placeholder="Name this sheet (e.g., Week of July 22)">
            <button id="btn-update-record" class="ts-btn btn-clock" style="display:none;" onclick="saveToHistory(true)">Update Record</button>
            <button id="btn-save-new-record" class="ts-btn btn-save" onclick="saveToHistory(false)">Save Record</button>
        </div>
        <ul id="historyList" class="history-list"></ul>
        <div class="backup-controls">
            <button class="backup-btn" onclick="exportBackup()">📥 Export Backup File</button>
            <button class="backup-btn" onclick="document.getElementById('importFile').click()">📤 Import Backup File</button>
            <input type="file" id="importFile" class="hidden-input" accept=".json" onchange="importBackup(event)">
        </div>
    </div>
</div>

<!-- Template Manager Modal -->
<div id="templateModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Template Manager</h3>
            <button class="close-modal" onclick="closeTemplateModal()">✕</button>
        </div>
        <div class="history-save-area">
            <input type="text" id="templateName" placeholder="Name this template (e.g., Normal Week Layout)">
            <button class="ts-btn btn-template-save" onclick="saveNewTemplate()">Save Current as Template</button>
        </div>
        <ul id="templateList" class="history-list"></ul>
    </div>
</div>

<script>
    let appData = {
        activeData: { meta: { name: '', initials: '', dates: '', history_id: '' }, rows: [] },
        historyData: [],
        templates: []
    };

    document.addEventListener("DOMContentLoaded", () => {
        fetchDataFromDB();
    });

    async function fetchDataFromDB() {
        try {
            const response = await fetch('timesheet.php?api=load&t=' + Date.now());
            if (response.redirected && response.url.includes('login.php')) { window.location.href = '/login.php'; return; }
            if (response.status === 401) { window.location.reload(); return; }
            
            const data = await response.json();
            
            // Load Active
            if (Array.isArray(data.activeData)) {
                appData.activeData = { meta: { name: '', initials: '', dates: '', history_id: '' }, rows: data.activeData };
            } else {
                appData.activeData = data.activeData || { meta: {}, rows: [] };
            }
            
            // Load History
            appData.historyData = data.historyData || [];
            
            // Load Templates (Support legacy single-template conversion)
            let tData = data.templateData;
            if (tData) {
                if (Array.isArray(tData)) {
                    appData.templates = tData;
                } else {
                    appData.templates = [{
                        id: Date.now(),
                        name: "Legacy Saved Template",
                        dateSaved: new Date().toLocaleString(),
                        data: tData
                    }];
                }
            } else {
                appData.templates = [];
            }
            
            document.getElementById('empInitials').value = appData.activeData.meta?.initials || '';
            document.getElementById('empName').value = appData.activeData.meta?.name || '';
            document.getElementById('weekDates').value = appData.activeData.meta?.dates || '';
            document.getElementById('currentHistoryId').value = appData.activeData.meta?.history_id || '';

            document.getElementById('tableBody').innerHTML = '';
            
            if (appData.activeData.rows && appData.activeData.rows.length > 0) {
                appData.activeData.rows.forEach(row => addRow(row));
            } else {
                addRow();
            }
            
            if (document.getElementById('historyModal').style.display === 'flex') {
                renderHistoryList();
            }
        } catch (error) {
            console.error("Error loading from DB", error);
            if (document.getElementById('tableBody').children.length === 0) addRow();
        }
    }

    async function syncDraftToDB(showAlert = false) {
        const btn = document.getElementById('saveBtn');
        if(btn) btn.innerText = "Saving...";
        
        appData.activeData = getTimesheetData();
        
        try {
            const res = await fetch('timesheet.php?api=save_draft', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(appData.activeData)
            });
            if (res.redirected && res.url.includes('login.php')) { window.location.href = '/login.php'; return; }
            if (res.status === 401) { window.location.reload(); return; }
            if (showAlert) alert('Draft saved securely to the database!');
        } catch (error) {
            alert('Error connecting to database.');
        } finally {
            if(btn) btn.innerText = "Save Draft";
        }
    }

    // --- Template Modal Functions ---
    function openTemplateModal() {
        document.getElementById('templateModal').style.display = 'flex';
        renderTemplateList();
    }

    function closeTemplateModal() {
        document.getElementById('templateModal').style.display = 'none';
        document.getElementById('templateName').value = '';
    }

    async function saveNewTemplate() {
        let name = document.getElementById('templateName').value.trim() || ('Template ' + new Date().toLocaleDateString());
        
        const newTemplate = {
            id: Date.now(),
            name: name,
            dateSaved: new Date().toLocaleString(),
            data: getTimesheetData()
        };
        
        appData.templates.push(newTemplate);
        await syncTemplatesToDB();
        
        document.getElementById('templateName').value = '';
        renderTemplateList();
    }

    function renderTemplateList() {
        const list = document.getElementById('templateList');
        list.innerHTML = '';
        
        if (!appData.templates || appData.templates.length === 0) {
            list.innerHTML = '<li class="history-empty">No templates saved yet.</li>';
            return;
        }
        
        [...appData.templates].reverse().forEach(temp => {
            const li = document.createElement('li');
            li.className = 'history-item';
            li.innerHTML = `
                <div class="history-info">
                    <strong>${temp.name}</strong>
                    <span>Saved: ${temp.dateSaved || 'Unknown'}</span>
                </div>
                <div class="action-btns">
                    <button class="icon-btn" onclick="loadTemplate('${temp.id}')" title="Load Template">📂 Load</button>
                    <button class="icon-btn delete" onclick="deleteTemplate('${temp.id}')" title="Delete Template">✕</button>
                </div>
            `;
            list.appendChild(li);
        });
    }

    function loadTemplate(id) {
        const template = appData.templates.find(t => String(t.id) === String(id));
        if(!template) return;
        
        if(!confirm("Load '" + template.name + "'? This will replace your current workspace and clear the dates.")) return;
        
        // Deep copy to prevent binding
        let tempData = JSON.parse(JSON.stringify(template.data)); 
        
        document.getElementById('empInitials').value = tempData.meta?.initials || '';
        document.getElementById('empName').value = tempData.meta?.name || '';
        document.getElementById('weekDates').value = ''; 
        
        // Clear active record ID tracking when loading a fresh template
        document.getElementById('currentHistoryId').value = '';
        
        document.getElementById('tableBody').innerHTML = '';
        
        if (Array.isArray(tempData)) {
            tempData.forEach(rData => { rData.date = ''; addRow(rData); });
        } else if (tempData.rows) {
            tempData.rows.forEach(rData => { rData.date = ''; addRow(rData); });
        }
        
        syncDraftToDB(false);
        closeTemplateModal();
    }

    async function deleteTemplate(id) {
        if(!confirm("Permanently delete this template?")) return;
        
        appData.templates = appData.templates.filter(t => String(t.id) !== String(id));
        await syncTemplatesToDB();
        renderTemplateList();
    }

    async function syncTemplatesToDB() {
        try {
            const res = await fetch('timesheet.php?api=save_template', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(appData.templates)
            });
            if (res.redirected && res.url.includes('login.php')) { window.location.href = '/login.php'; return; }
            if (res.status === 401) window.location.reload(); 
        } catch (error) {
            alert("Error saving templates to database.");
        }
    }

    // --- Standard Functions ---
    function getHourOptions(selected) {
        let opts = '<option value="">Hr</option>';
        for (let i = 1; i <= 12; i++) {
            let val = i.toString().padStart(2, '0');
            opts += `<option value="${val}" ${val === selected ? 'selected' : ''}>${val}</option>`;
        }
        return opts;
    }

    function getMinuteOptions(selected) {
        let opts = '<option value="">Min</option>';
        ['00', '15', '30', '45'].forEach(min => {
            opts += `<option value="${min}" ${min === selected ? 'selected' : ''}>${min}</option>`;
        });
        return opts;
    }

    function getAmPmOptions(selected) {
        let opts = '<option value="">--</option>';
        ['AM', 'PM'].forEach(p => {
            opts += `<option value="${p}" ${p === selected ? 'selected' : ''}>${p}</option>`;
        });
        return opts;
    }

    function punchClock() {
        const ctString = new Date().toLocaleString("en-US", {timeZone: "America/Chicago"});
        const now = new Date(ctString);
        
        let h = now.getHours();
        let m = now.getMinutes();
        
        m = Math.round(m / 15) * 15;
        if (m === 60) { m = 0; h = (h + 1) % 24; }
        
        let ampm = h >= 12 ? 'PM' : 'AM';
        let displayH = h % 12 || 12;
        let strH = displayH.toString().padStart(2, '0');
        let strM = m.toString().padStart(2, '0');
        
        let localYear = now.getFullYear();
        let localMonth = (now.getMonth() + 1).toString().padStart(2, '0');
        let localDay = now.getDate().toString().padStart(2, '0');
        let todayStr = `${localYear}-${localMonth}-${localDay}`;

        const rows = document.querySelectorAll('#tableBody tr');
        let targetRow = null;
        let action = '';

        if (rows.length > 0) {
            let lastRow = rows[rows.length - 1];
            let rowDate = lastRow.querySelector('.date').value;
            let startH = lastRow.querySelector('.start-hour').value;
            let endH = lastRow.querySelector('.end-hour').value;

            if (rowDate === todayStr || !rowDate) {
                if (!startH) {
                    targetRow = lastRow; action = 'start';
                    if (!rowDate) lastRow.querySelector('.date').value = todayStr;
                } else if (!endH) {
                    targetRow = lastRow; action = 'end';
                }
            }
        }

        if (!targetRow) {
            addRow({ date: todayStr });
            targetRow = document.querySelector('#tableBody tr:last-child');
            action = 'start';
        }

        targetRow.querySelector(`.${action}-hour`).value = strH;
        targetRow.querySelector(`.${action}-min`).value = strM;
        targetRow.querySelector(`.${action}-ampm`).value = ampm;

        calculateRow(targetRow.querySelector(`.${action}-hour`));
        syncDraftToDB(false);
        
        alert(`Successfully clocked ${action === 'start' ? 'IN' : 'OUT'} at ${strH}:${strM} ${ampm} (CT)`);
    }

    function calculateRow(element) {
        const row = element.closest('tr');
        if(!row) return;

        const sHour = row.querySelector('.start-hour').value;
        const sMin = row.querySelector('.start-min').value;
        const sAmPm = row.querySelector('.start-ampm').value;
        const eHour = row.querySelector('.end-hour').value;
        const eMin = row.querySelector('.end-min').value;
        const eAmPm = row.querySelector('.end-ampm').value;
        
        const rawLunch = row.querySelector('.lunch').value;
        const rawBreaks = row.querySelector('.breaks').value;
        
        const lunchMins = (rawLunch === '' || isNaN(rawLunch)) ? 0 : Number(rawLunch) * 60;
        const breaksMins = (rawBreaks === '' || isNaN(rawBreaks)) ? 0 : Number(rawBreaks) * 60;
        
        const totalCell = row.querySelector('.total-cell');

        if (sHour && sMin && sAmPm && eHour && eMin && eAmPm) {
            let startH24 = parseInt(sHour, 10);
            if (sAmPm === 'PM' && startH24 !== 12) startH24 += 12;
            if (sAmPm === 'AM' && startH24 === 12) startH24 = 0;

            let endH24 = parseInt(eHour, 10);
            if (eAmPm === 'PM' && endH24 !== 12) endH24 += 12;
            if (eAmPm === 'AM' && endH24 === 12) endH24 = 0;

            const startTime = new Date(2000, 0, 1, startH24, parseInt(sMin, 10));
            const endTime = new Date(2000, 0, 1, endH24, parseInt(eMin, 10));
            
            let diffMs = endTime - startTime;
            if (diffMs < 0) diffMs += 24 * 60 * 60 * 1000;

            let grossMins = Math.floor(diffMs / 60000) - lunchMins;
            
            // Calculate Paid Break Threshold
            let allowedPaidBreakMins = 0;
            if (grossMins > 600) {          // Over 10 hours
                allowedPaidBreakMins = 45;
            } else if (grossMins >= 480) {  // 8 to 10 hours
                allowedPaidBreakMins = 30;
            } else if (grossMins >= 240) {  // 4 to 8 hours
                allowedPaidBreakMins = 15;
            } else {                        // Less than 4 hours
                allowedPaidBreakMins = 0;
            }

            let unpaidBreakMins = Math.max(0, breaksMins - allowedPaidBreakMins);

            let totalMins = grossMins - unpaidBreakMins;
            let totalHoursNum = totalMins / 60;
            
            if (totalHoursNum < 0) totalHoursNum = 0;
            
            totalCell.innerText = totalHoursNum.toFixed(2);
        } else {
            totalCell.innerText = "0.00";
        }
        calculateGrandTotal();
    }

    function calculateGrandTotal() {
        let workHours = 0, ptoHours = 0, personalHours = 0, holidayHours = 0;
        document.querySelectorAll('#tableBody tr').forEach(row => {
            const type = row.querySelector('.row-type').value;
            const hours = parseFloat(row.querySelector('.total-cell').innerText) || 0;
            if (type === 'PTO') {
                ptoHours += hours;
            } else if (type === 'Personal') {
                personalHours += hours;
            } else if (type === 'Holiday') {
                holidayHours += hours;
            } else {
                workHours += hours;
            }
        });
        document.getElementById('totalWork').innerText = workHours.toFixed(2);
        document.getElementById('totalPTO').innerText = ptoHours.toFixed(2);
        document.getElementById('totalHoliday').innerText = holidayHours.toFixed(2);
        document.getElementById('totalPersonal').innerText = personalHours.toFixed(2);
        document.getElementById('grandTotal').innerText = (workHours + ptoHours + holidayHours + personalHours).toFixed(2);
    }

    function addRow(data = {}, insertAfterRow = null) {
        const tbody = document.getElementById('tableBody');
        const tr = document.createElement('tr');
        
        let lVal = data.lunch !== undefined ? data.lunch : '0';
        let bVal = data.breaks !== undefined ? data.breaks : '0';
        if (Number(lVal) >= 15) lVal = (Number(lVal) / 60).toFixed(2);
        if (Number(bVal) >= 15) bVal = (Number(bVal) / 60).toFixed(2);
        
        tr.innerHTML = `
            <td data-label="Date"><input type="date" class="date" value="${data.date || ''}" oninput="syncDraftToDB(false)"></td>
            <td data-label="Type">
                <select class="row-type" onchange="calculateGrandTotal(); syncDraftToDB(false)">
                    <option value="Work" ${(!data.type || data.type === 'Work') ? 'selected' : ''}>Work</option>
                    <option value="PTO" ${(data.type === 'PTO') ? 'selected' : ''}>PTO</option>
                    <option value="Holiday" ${(data.type === 'Holiday') ? 'selected' : ''}>Holiday</option>
                    <option value="Personal" ${(data.type === 'Personal') ? 'selected' : ''}>Personal</option>
                </select>
            </td>
            <td data-label="Project Info"><input type="text" class="project-info" placeholder="Project Name" value="${data.project || ''}" oninput="syncDraftToDB(false)"></td>
            <td data-label="Start Time">
                <div class="time-group">
                    <select class="start-hour" onchange="calculateRow(this); syncDraftToDB(false)">${getHourOptions(data.startHour)}</select>
                    <select class="start-min" onchange="calculateRow(this); syncDraftToDB(false)">${getMinuteOptions(data.startMin)}</select>
                    <select class="start-ampm" onchange="calculateRow(this); syncDraftToDB(false)">${getAmPmOptions(data.startAmPm)}</select>
                </div>
            </td>
            <td data-label="End Time">
                <div class="time-group">
                    <select class="end-hour" onchange="calculateRow(this); syncDraftToDB(false)">${getHourOptions(data.endHour)}</select>
                    <select class="end-min" onchange="calculateRow(this); syncDraftToDB(false)">${getMinuteOptions(data.endMin)}</select>
                    <select class="end-ampm" onchange="calculateRow(this); syncDraftToDB(false)">${getAmPmOptions(data.endAmPm)}</select>
                </div>
            </td>
            <td data-label="Lunch (Hrs)"><input type="number" class="lunch" min="0" step="0.25" value="${lVal}" oninput="calculateRow(this); syncDraftToDB(false)"></td>
            <td data-label="Breaks (Hrs)"><input type="number" class="breaks" min="0" step="0.25" value="${bVal}" oninput="calculateRow(this); syncDraftToDB(false)"></td>
            <td data-label="Total (Hrs)" class="total-cell">${data.total || '0.00'}</td>
            <td data-label="Manage" class="action-col">
                <div class="action-btns">
                    <button class="icon-btn" onclick="moveRowUp(this)" title="Move Up">▲</button>
                    <button class="icon-btn" onclick="moveRowDown(this)" title="Move Down">▼</button>
                    <button class="icon-btn" onclick="copyRow(this)" title="Duplicate Row">⧉</button>
                    <button class="icon-btn delete" onclick="deleteRow(this)" title="Delete Row">✕</button>
                </div>
            </td>
        `;
        
        if (insertAfterRow && insertAfterRow.nextSibling) {
            tbody.insertBefore(tr, insertAfterRow.nextSibling);
        } else {
            tbody.appendChild(tr);
        }
        
        calculateRow(tr.querySelector('.start-hour'));
    }

    function copyRow(btn) {
        const row = btn.closest('tr');
        const data = {
            date: row.querySelector('.date').value,
            type: row.querySelector('.row-type').value,
            project: row.querySelector('.project-info').value,
            startHour: row.querySelector('.start-hour').value,
            startMin: row.querySelector('.start-min').value,
            startAmPm: row.querySelector('.start-ampm').value,
            endHour: row.querySelector('.end-hour').value,
            endMin: row.querySelector('.end-min').value,
            endAmPm: row.querySelector('.end-ampm').value,
            lunch: row.querySelector('.lunch').value,
            breaks: row.querySelector('.breaks').value,
            total: row.querySelector('.total-cell').innerText
        };
        addRow(data, row); 
        syncDraftToDB(false);
    }

    function moveRowUp(btn) {
        const row = btn.closest('tr');
        if (row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); syncDraftToDB(false); }
    }
    function moveRowDown(btn) {
        const row = btn.closest('tr');
        if (row.nextElementSibling) { row.parentNode.insertBefore(row.nextElementSibling, row); syncDraftToDB(false); }
    }
    function deleteRow(btn) {
        btn.closest('tr').remove(); calculateGrandTotal(); syncDraftToDB(false); 
    }

    function getTimesheetData() {
        const data = {
            meta: {
                initials: document.getElementById('empInitials').value,
                name: document.getElementById('empName').value,
                dates: document.getElementById('weekDates').value,
                history_id: document.getElementById('currentHistoryId').value // Save active link
            },
            rows: []
        };
        
        document.querySelectorAll('#tableBody tr').forEach(row => {
            data.rows.push({
                date: row.querySelector('.date').value,
                type: row.querySelector('.row-type').value,
                project: row.querySelector('.project-info').value,
                startHour: row.querySelector('.start-hour').value,
                startMin: row.querySelector('.start-min').value,
                startAmPm: row.querySelector('.start-ampm').value,
                endHour: row.querySelector('.end-hour').value,
                endMin: row.querySelector('.end-min').value,
                endAmPm: row.querySelector('.end-ampm').value,
                lunch: row.querySelector('.lunch').value,
                breaks: row.querySelector('.breaks').value,
                total: row.querySelector('.total-cell').innerText
            });
        });
        return data;
    }

    function clearData() {
        if(confirm("Are you sure you want to clear your current workspace?")) {
            document.getElementById('empInitials').value = '';
            document.getElementById('empName').value = '';
            document.getElementById('weekDates').value = '';
            document.getElementById('currentHistoryId').value = ''; // Clear active link
            document.getElementById('tableBody').innerHTML = '';
            addRow();
            syncDraftToDB(false);
        }
    }

    function openHistoryModal() {
        document.getElementById('historyModal').style.display = 'flex';
        
        // Check if we have an active record loaded
        let currentId = document.getElementById('currentHistoryId').value;
        if (currentId) {
            const rec = appData.historyData.find(r => String(r.id) === String(currentId));
            if (rec) {
                document.getElementById('historyName').value = rec.name;
                document.getElementById('btn-update-record').style.display = 'inline-block';
                document.getElementById('btn-save-new-record').innerText = 'Save as New';
            } else {
                document.getElementById('historyName').value = '';
                document.getElementById('btn-update-record').style.display = 'none';
                document.getElementById('btn-save-new-record').innerText = 'Save Record';
            }
        } else {
            document.getElementById('historyName').value = '';
            document.getElementById('btn-update-record').style.display = 'none';
            document.getElementById('btn-save-new-record').innerText = 'Save Record';
        }
        
        renderHistoryList();
    }

    function closeHistoryModal() {
        document.getElementById('historyModal').style.display = 'none';
        document.getElementById('historyName').value = '';
    }

    async function saveToHistory(isUpdate = false) {
        let name = document.getElementById('historyName').value.trim() || ('Timesheet ' + new Date().toLocaleDateString());
        let currentId = document.getElementById('currentHistoryId').value;
        
        const historyRecord = {
            id: (isUpdate && currentId) ? currentId : null,
            name: name,
            dateSaved: new Date().toLocaleString(),
            data: getTimesheetData()
        };
        
        try {
            const response = await fetch('timesheet.php?api=save_history', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(historyRecord)
            });
            if (response.redirected && response.url.includes('login.php')) { window.location.href = '/login.php'; return; }
            if (response.status === 401) { window.location.reload(); return; }
            const result = await response.json();
            
            if (isUpdate && currentId) {
                // Update local array
                appData.historyData = appData.historyData.filter(r => String(r.id) !== String(currentId));
            } else {
                // Was saved as new, link to the new record
                document.getElementById('currentHistoryId').value = result.id;
                syncDraftToDB(false);
            }
            
            appData.historyData.unshift({
                id: result.id || currentId,
                name: historyRecord.name,
                dateSaved: historyRecord.dateSaved,
                data: historyRecord.data
            });
            
            renderHistoryList();
            document.getElementById('btn-update-record').style.display = 'inline-block';
            document.getElementById('btn-save-new-record').innerText = 'Save as New';
            
            alert(isUpdate ? "Record updated successfully!" : "New record saved successfully!");
        } catch (e) {
            alert("Error saving history to database.");
        }
    }

    function renderHistoryList() {
        const list = document.getElementById('historyList');
        list.innerHTML = '';
        
        if (!appData.historyData || appData.historyData.length === 0) {
            list.innerHTML = '<li class="history-empty">No historical data saved yet.</li>';
            return;
        }
        
        [...appData.historyData].forEach(record => {
            const li = document.createElement('li');
            li.className = 'history-item';
            li.innerHTML = `
                <div class="history-info">
                    <strong>${record.name}</strong>
                    <span>Saved: ${record.dateSaved}</span>
                </div>
                <div class="action-btns">
                    <button class="icon-btn" onclick="loadHistory('${record.id}')">📂 Load</button>
                    <button class="icon-btn delete" onclick="deleteHistory('${record.id}')">✕</button>
                </div>
            `;
            list.appendChild(li);
        });
    }

    function loadHistory(id) {
        if(!confirm("Loading this will overwrite your current active timesheet workspace. Continue?")) return;
        const record = appData.historyData.find(r => String(r.id) === String(id));
        
        if (record) {
            let metaData = { name: '', initials: '', dates: '' };
            let rowData = [];
            
            if (Array.isArray(record.data)) {
                rowData = record.data;
            } else if (record.data && record.data.rows) {
                metaData = record.data.meta || metaData;
                rowData = record.data.rows;
            }
            
            document.getElementById('empInitials').value = metaData.initials || '';
            document.getElementById('empName').value = metaData.name || '';
            document.getElementById('weekDates').value = metaData.dates || '';
            
            // Set the active tracker link
            document.getElementById('currentHistoryId').value = id;
            
            document.getElementById('tableBody').innerHTML = '';
            if (rowData.length > 0) {
                rowData.forEach(rData => addRow(rData));
            } else {
                addRow();
            }
            
            syncDraftToDB(false);
            closeHistoryModal();
        }
    }

    async function deleteHistory(id) {
        if(!confirm("Permanently delete this saved record from the database?")) return;
        try {
            const res = await fetch('timesheet.php?api=delete_history', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            if (res.redirected && res.url.includes('login.php')) { window.location.href = '/login.php'; return; }
            if (res.status === 401) { window.location.reload(); return; }
            
            appData.historyData = appData.historyData.filter(r => String(r.id) !== String(id));
            
            // If the deleted record was our active one, unlink it
            if (document.getElementById('currentHistoryId').value === String(id)) {
                document.getElementById('currentHistoryId').value = '';
                document.getElementById('btn-update-record').style.display = 'none';
                document.getElementById('btn-save-new-record').innerText = 'Save Record';
                syncDraftToDB(false);
            }
            
            renderHistoryList();
        } catch (e) {
            alert("Error deleting record.");
        }
    }

    function exportBackup() {
        const backupData = {
            activeData: appData.activeData,
            historyData: appData.historyData
        };
        const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(backupData));
        const downloadAnchorNode = document.createElement('a');
        downloadAnchorNode.setAttribute("href", dataStr);
        downloadAnchorNode.setAttribute("download", "timesheet_db_backup.json");
        document.body.appendChild(downloadAnchorNode);
        downloadAnchorNode.click();
        downloadAnchorNode.remove();
    }

    async function importBackup(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = async function(e) {
            try {
                const importedData = JSON.parse(e.target.result);
                const res = await fetch('timesheet.php?api=import_backup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(importedData)
                });
                if (res.redirected && res.url.includes('login.php')) { window.location.href = '/login.php'; return; }
                if (res.status === 401) { window.location.reload(); return; }
                
                await fetchDataFromDB();
                alert("Backup imported successfully! Database has been restored.");
            } catch (err) {
                alert("Error importing file. Please ensure it is a valid Timesheet backup JSON file.");
            }
            event.target.value = ''; 
        };
        reader.readAsText(file);
    }

    function exportPDF() {
        const initials = document.getElementById('empInitials').value.trim();
        const empName = document.getElementById('empName').value.trim();
        const weekDates = document.getElementById('weekDates').value.trim();
        
        let defaultNameParts = [];
        if (initials) defaultNameParts.push(initials);
        if (empName) defaultNameParts.push(empName);
        if (weekDates) defaultNameParts.push(weekDates);
        
        let defaultName = defaultNameParts.join('_').replace(/[^a-zA-Z0-9_\-]/g, '_');
        if (!defaultName) defaultName = "Weekly_Timesheet";

        let fileName = prompt("Please enter a name for your PDF file:", defaultName);
        if (fileName === null) return;
        if (fileName.trim() === "") fileName = defaultName;
        if (!fileName.toLowerCase().endsWith('.pdf')) fileName += '.pdf';

        const cloneContainer = document.getElementById('printable-area').cloneNode(true);
        cloneContainer.classList.add('pdf-mode'); 
        
        // Remove the top button header entirely for the PDF output
        const headerArea = cloneContainer.querySelector('.ts-header-area');
        if (headerArea) headerArea.remove();
        
        const titleElement = document.createElement('div');
        titleElement.className = 'pdf-title';
        titleElement.innerText = 'Timesheet';
        cloneContainer.insertBefore(titleElement, cloneContainer.firstChild);

        const createSpan = (val) => `<span style="display:inline-block; min-width: 150px; border-bottom: 1px solid #000; color: #000 !important; font-weight: bold; font-size: 16px; padding-bottom: 2px;">${val || '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'}</span>`;
        
        cloneContainer.querySelector('#empInitials').outerHTML = createSpan(document.getElementById('empInitials').value);
        cloneContainer.querySelector('#empName').outerHTML = createSpan(document.getElementById('empName').value);
        cloneContainer.querySelector('#weekDates').outerHTML = createSpan(document.getElementById('weekDates').value);
        
        cloneContainer.querySelector('.action-col').remove();
        
        const originalRows = document.querySelectorAll('#tableBody tr');
        const cloneRows = cloneContainer.querySelectorAll('#tableBody tr');
        
        for (let i = 0; i < originalRows.length; i++) {
            const orig = originalRows[i];
            const clone = cloneRows[i];
            clone.cells[8].remove(); 

            clone.cells[0].innerText = orig.querySelector('.date').value || '-';
            clone.cells[1].innerText = orig.querySelector('.row-type').value;
            clone.cells[2].innerText = orig.querySelector('.project-info').value || '-';
            
            let sH = orig.querySelector('.start-hour').value, sM = orig.querySelector('.start-min').value, sA = orig.querySelector('.start-ampm').value;
            clone.cells[3].innerText = (sH && sM && sA) ? `${sH}:${sM} ${sA}` : '-';
            
            let eH = orig.querySelector('.end-hour').value, eM = orig.querySelector('.end-min').value, eA = orig.querySelector('.end-ampm').value;
            clone.cells[4].innerText = (eH && eM && eA) ? `${eH}:${eM} ${eA}` : '-';
            
            clone.cells[5].innerText = orig.querySelector('.lunch').value || '0';
            clone.cells[6].innerText = orig.querySelector('.breaks').value || '0';
        }

        // Explicitly map the dynamically calculated totals over to the cloned document
        cloneContainer.querySelector('#totalWork').innerText = document.getElementById('totalWork').innerText;
        cloneContainer.querySelector('#totalPTO').innerText = document.getElementById('totalPTO').innerText;
        cloneContainer.querySelector('#totalHoliday').innerText = document.getElementById('totalHoliday').innerText;
        cloneContainer.querySelector('#totalPersonal').innerText = document.getElementById('totalPersonal').innerText;
        cloneContainer.querySelector('#grandTotal').innerText = document.getElementById('grandTotal').innerText;

        html2pdf().set({
            margin: 0.5, filename: fileName, image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'in', format: 'letter', orientation: 'landscape' },
            pagebreak: { mode: ['css', 'legacy'] }
        }).from(cloneContainer).save();
    }
</script>

<?php include 'includes/footer.php'; ?>