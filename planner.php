<?php
// public/planner.php

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();

// --- AUTO-HEAL DATABASE SCHEMA ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        plan_date DATE NOT NULL,
        plan_data LONGTEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_date (user_id, plan_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// --- API ENDPOINTS FOR AJAX SAVING/LOADING ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    if ($_GET['api'] === 'load') {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
            $date = date('Y-m-d');
        }

        $stmt = $pdo->prepare("SELECT plan_data FROM daily_plans WHERE user_id = :uid AND plan_date = :pdate");
        $stmt->execute([':uid' => $user_id, ':pdate' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['plan_data'])) {
            echo $row['plan_data'];
        } else {
            // Return empty skeleton matching the new image layout
            echo json_encode([
                'priorities' => [['text'=>'', 'done'=>false], ['text'=>'', 'done'=>false], ['text'=>'', 'done'=>false]],
                'appointments' => [['text'=>'', 'done'=>false], ['text'=>'', 'done'=>false], ['text'=>'', 'done'=>false], ['text'=>'', 'done'=>false]],
                'schedule' => new stdClass(),
                'goal' => '',
                'notes' => ''
            ]);
        }
        exit;
    }

    if ($_GET['api'] === 'save') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $date = $payload['date'] ?? date('Y-m-d');
        $data = json_encode($payload['data'] ?? []);

        if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
            $stmt = $pdo->prepare("INSERT INTO daily_plans (user_id, plan_date, plan_data) 
                                   VALUES (:uid, :pdate, :data) 
                                   ON DUPLICATE KEY UPDATE plan_data = :data_update");
            $stmt->execute([
                ':uid' => $user_id,
                ':pdate' => $date,
                ':data' => $data,
                ':data_update' => $data
            ]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid date']);
        }
        exit;
    }
}

$page_title = "Daily Planner - PersonalApps";
include 'includes/header.php';
?>

<style>
    /* Global Inputs */
    input, textarea { 
        color: var(--text-main) !important; 
        background-color: var(--bg-color) !important; 
        font-family: inherit; 
        border: 1px solid var(--border-color); 
        border-radius: 6px; 
        padding: 10px; 
        width: 100%; 
        box-sizing: border-box; 
    }
    input:focus, textarea:focus { outline: none; border-color: var(--accent); }
    input::placeholder, textarea::placeholder { color: var(--text-muted) !important; opacity: 0.5; }

    /* Date Controller Layout */
    .date-controller { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: var(--card-bg); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); }
    .date-controller h2 { margin: 0; font-size: 1.4em; color: var(--text-main); display: flex; align-items: center; gap: 15px; }
    .nav-btn { background: transparent; border: 1px solid var(--border-color); color: var(--text-main); padding: 8px 15px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-weight: bold; }
    .nav-btn:hover { background: var(--bg-color); color: var(--accent); border-color: var(--accent); }
    #date-picker { padding: 8px; width: auto; background: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-main); border-radius: 6px; font-weight: bold; cursor: pointer; }

    /* Planner Layout Grid */
    .planner-grid { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        gap: 30px; 
        align-items: start; 
    }
    
    .planner-section {
        background: var(--card-bg); 
        border: 1px solid var(--border-color); 
        border-radius: 12px; 
        padding: 25px;
    }

    h3.section-title { 
        margin-top: 0; 
        padding-bottom: 8px; 
        color: var(--text-main); 
        font-size: 0.95em; 
        text-transform: uppercase; 
        letter-spacing: 1px;
        margin-bottom: 15px;
    }

    /* Schedule Table Formatting */
    .schedule-table {
        display: flex;
        flex-direction: column;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        overflow: hidden;
    }
    .s-row {
        display: flex;
        border-bottom: 1px solid var(--border-color);
    }
    .s-row:last-child { border-bottom: none; }
    .s-time {
        width: 100px;
        flex-shrink: 0;
        padding: 12px;
        border-right: 1px solid var(--border-color);
        font-size: 0.85em;
        font-weight: bold;
        color: var(--text-main);
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-color);
    }
    .s-input {
        flex-grow: 1;
    }
    .s-input input {
        border: none;
        border-radius: 0;
        height: 100%;
        padding: 12px 15px;
        background: transparent !important;
    }

    /* Checklist Items (Priorities & Appointments) */
    .checklist-item { 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        margin-bottom: 8px;
    }
    .checklist-item input[type="checkbox"] { 
        width: 18px; 
        height: 18px; 
        cursor: pointer; 
        accent-color: var(--accent); 
        flex-shrink: 0; 
    }
    .checklist-item input[type="text"] { 
        flex-grow: 1; 
        font-size: 0.95em; 
        border: none;
        border-bottom: 1px solid var(--border-color);
        border-radius: 0;
        background: transparent !important;
        padding: 8px 5px;
    }
    .checklist-item input[type="text"]:focus {
        border-bottom-color: var(--accent);
    }
    .checklist-item.done input[type="text"] { 
        text-decoration: line-through; 
        color: var(--text-muted) !important; 
    }

    /* Saving Indicator */
    #save-status { font-size: 0.85em; color: var(--text-muted); display: flex; align-items: center; gap: 5px; opacity: 0; transition: opacity 0.3s; }
    #save-status.visible { opacity: 1; }

    /* Responsive Mobile Layout */
    @media (max-width: 900px) {
        .planner-grid { grid-template-columns: 1fr; }
        .date-controller { flex-direction: column; gap: 15px; align-items: stretch; }
        .date-controller > div { display: flex; justify-content: space-between; width: 100%; }
        .date-controller h2 { justify-content: center; width: 100%; }
        #date-picker { width: 100%; text-align: center; }
    }
</style>

<div class="page-header" style="border: none; margin-bottom: 0;">
    <h2>Daily Planner</h2>
</div>

<!-- Date Navigation -->
<div class="date-controller">
    <div>
        <button class="nav-btn" onclick="changeDate(-1)">← Prev</button>
        <button class="nav-btn" onclick="goToToday()">Today</button>
    </div>
    <h2>
        <input type="date" id="date-picker" onchange="loadDate(this.value)">
    </h2>
    <div>
        <span id="save-status">💾 Saved</span>
        <button class="nav-btn" onclick="changeDate(1)">Next →</button>
    </div>
</div>

<!-- Main Layout Grid -->
<div class="planner-grid">
    
    <!-- LEFT COLUMN: Schedule -->
    <div class="planner-section">
        <h3 class="section-title">Today's Schedule</h3>
        <div class="schedule-table" id="schedule-container">
            <!-- Populated via JS -->
        </div>
    </div>

    <!-- RIGHT COLUMN: Priorities, Goals, Appointments -->
    <div style="display: flex; flex-direction: column; gap: 30px;">
        
        <div class="planner-section">
            <h3 class="section-title">Top Priorities</h3>
            <div id="priorities-container">
                <?php for($i = 0; $i < 3; $i++): ?>
                <div class="checklist-item" id="priority-<?= $i ?>">
                    <input type="checkbox" onchange="toggleChecklist(this); triggerSave();">
                    <input type="text" oninput="triggerSave()">
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="planner-section">
            <h3 class="section-title">Today's Goal</h3>
            <textarea id="today-goal" rows="4" style="resize: vertical;" oninput="triggerSave()"></textarea>
        </div>

        <div class="planner-section">
            <h3 class="section-title">Appointment</h3>
            <div id="appointments-container">
                <?php for($i = 0; $i < 4; $i++): ?>
                <div class="checklist-item" id="appt-<?= $i ?>">
                    <input type="checkbox" onchange="toggleChecklist(this); triggerSave();">
                    <input type="text" oninput="triggerSave()">
                </div>
                <?php endfor; ?>
            </div>
        </div>

    </div>
</div>

<!-- BOTTOM SECTION: Notes -->
<div class="planner-section" style="margin-top: 30px;">
    <h3 class="section-title">Notes</h3>
    <textarea id="daily-notes" rows="6" style="resize: vertical;" oninput="triggerSave()"></textarea>
</div>

<script>
    let currentDate = new Date().toLocaleDateString('en-CA'); 
    let saveTimeout = null;

    // Generate Schedule Rows (6 AM to 8-9 PM)
    function generateScheduleGrid() {
        const container = document.getElementById('schedule-container');
        container.innerHTML = '';
        
        for (let i = 6; i <= 20; i++) {
            let startH = i > 12 ? i - 12 : i;
            let endH = (i + 1) > 12 ? (i + 1) - 12 : (i + 1);
            
            // Format AM/PM properly (11-12 is typically AM, 12-1 is PM)
            let ampm = (i >= 12 && i < 24) ? 'PM' : 'AM';
            // Specific edge case for the "11-12" label bridging noon
            if (i === 11) ampm = 'AM'; 
            
            let label = `${startH}-${endH} ${ampm}`;
            let key = i.toString().padStart(2, '0') + ':00';
            
            container.innerHTML += `
                <div class="s-row">
                    <div class="s-time">${label}</div>
                    <div class="s-input">
                        <input type="text" id="time-${key}" oninput="triggerSave()">
                    </div>
                </div>
            `;
        }
    }

    // Initialize Page
    document.addEventListener('DOMContentLoaded', () => {
        generateScheduleGrid();
        goToToday();
    });

    // Date Navigation Functions
    function goToToday() {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        loadDate(`${yyyy}-${mm}-${dd}`);
    }

    function changeDate(daysOffset) {
        let d = new Date(currentDate + 'T12:00:00'); 
        d.setDate(d.getDate() + daysOffset);
        
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        loadDate(`${yyyy}-${mm}-${dd}`);
    }

    function loadDate(dateString) {
        currentDate = dateString;
        document.getElementById('date-picker').value = currentDate;
        
        // Clear UI before loading new data
        document.querySelectorAll('input[type="text"], textarea').forEach(el => el.value = '');
        document.querySelectorAll('input[type="checkbox"]').forEach(el => {
            el.checked = false;
            el.closest('.checklist-item').classList.remove('done');
        });

        // Fetch data
        fetch(`planner.php?api=load&date=${currentDate}`)
            .then(res => res.json())
            .then(data => populateUI(data))
            .catch(err => console.error("Error loading planner data:", err));
    }

    function populateUI(data) {
        // Priorities
        if (data.priorities && Array.isArray(data.priorities)) {
            for (let i = 0; i < 3; i++) {
                if (data.priorities[i]) {
                    const row = document.getElementById(`priority-${i}`);
                    if (row) {
                        const cb = row.querySelector('input[type="checkbox"]');
                        const txt = row.querySelector('input[type="text"]');
                        txt.value = data.priorities[i].text || '';
                        cb.checked = data.priorities[i].done || false;
                        if (cb.checked) row.classList.add('done');
                    }
                }
            }
        }

        // Appointments
        if (data.appointments && Array.isArray(data.appointments)) {
            for (let i = 0; i < 4; i++) {
                if (data.appointments[i]) {
                    const row = document.getElementById(`appt-${i}`);
                    if (row) {
                        const cb = row.querySelector('input[type="checkbox"]');
                        const txt = row.querySelector('input[type="text"]');
                        txt.value = data.appointments[i].text || '';
                        cb.checked = data.appointments[i].done || false;
                        if (cb.checked) row.classList.add('done');
                    }
                }
            }
        }

        // Schedule
        if (data.schedule) {
            for (const [timeKey, text] of Object.entries(data.schedule)) {
                const input = document.getElementById(`time-${timeKey}`);
                if (input) input.value = text;
            }
        }

        // Goal & Notes
        document.getElementById('today-goal').value = data.goal || '';
        document.getElementById('daily-notes').value = data.notes || '';
    }

    // Toggle strikethrough styling visually instantly
    function toggleChecklist(checkbox) {
        const row = checkbox.closest('.checklist-item');
        if (checkbox.checked) row.classList.add('done');
        else row.classList.remove('done');
    }

    // Auto-Save Logic
    function triggerSave() {
        clearTimeout(saveTimeout);
        const statusEl = document.getElementById('save-status');
        statusEl.innerText = "⏳ Saving...";
        statusEl.classList.add('visible');

        saveTimeout = setTimeout(() => {
            saveData();
        }, 1000);
    }

    function saveData() {
        const payload = {
            date: currentDate,
            data: {
                priorities: [],
                appointments: [],
                schedule: {},
                goal: document.getElementById('today-goal').value,
                notes: document.getElementById('daily-notes').value
            }
        };

        // Gather Priorities
        for (let i = 0; i < 3; i++) {
            const row = document.getElementById(`priority-${i}`);
            payload.data.priorities.push({
                text: row.querySelector('input[type="text"]').value,
                done: row.querySelector('input[type="checkbox"]').checked
            });
        }

        // Gather Appointments
        for (let i = 0; i < 4; i++) {
            const row = document.getElementById(`appt-${i}`);
            payload.data.appointments.push({
                text: row.querySelector('input[type="text"]').value,
                done: row.querySelector('input[type="checkbox"]').checked
            });
        }

        // Gather Schedule
        document.querySelectorAll('.schedule-table input').forEach(input => {
            if (input.value.trim() !== '') {
                const timeKey = input.id.replace('time-', '');
                payload.data.schedule[timeKey] = input.value;
            }
        });

        // Send to Server
        fetch('planner.php?api=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(res => {
            const statusEl = document.getElementById('save-status');
            if (res.status === 'success') {
                statusEl.innerText = "💾 Saved";
                setTimeout(() => { statusEl.classList.remove('visible'); }, 2000);
            } else {
                statusEl.innerText = "❌ Error";
            }
        })
        .catch(err => {
            document.getElementById('save-status').innerText = "❌ Offline";
        });
    }
</script>

<?php include 'includes/footer.php'; ?>