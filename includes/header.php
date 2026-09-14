<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'user';

// Fetch unread notifications
$unread_count = 0;
$notifications = [];

if ($is_logged_in) {
    try {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/notification_functions.php';
        
        $db = new Database();
        $pdo = $db->getConnection();
        
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([':uid' => $_SESSION['user_id']]);
        $notifications = $stmt->fetchAll();
        $unread_count = count($notifications);
    } catch (Exception $e) {}
}

$planning_pages = ['tasks.php', 'planner.php', 'meetings.php'];
$is_planning_open = in_array($current_page, $planning_pages);

$finance_pages = ['budget.php', 'ledger.php', 'portfolio.php'];
$is_finance_open = in_array($current_page, $finance_pages);

$knowledge_pages = ['notes.php', 'kb.php'];
$is_knowledge_open = in_array($current_page, $knowledge_pages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $page_title ?? 'PersonalApps' ?></title>
    
    <link rel="icon" href="/favicon.svg" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --accent: #3b82f6;
            --success: #22c55e;
            --warning: #eab308;
            --danger: #ef4444;
        }
        .light-theme {
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
            --accent: #2563eb;
            --success: #16a34a;
            --warning: #ca8a04;
            --danger: #dc2626;
        }
        
        *, *::before, *::after { box-sizing: border-box; }
        html { background-color: var(--bg-color); color: var(--text-main); }
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: var(--bg-color); color: var(--text-main); margin: 0; padding: 0; display: flex; min-height: 100vh; transition: background-color 0.3s, color 0.3s; -webkit-tap-highlight-color: transparent; }

        .sidebar { width: 260px; background: var(--card-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; padding: 20px; box-sizing: border-box; position: fixed; top: 0; left: -260px; height: 100vh; z-index: 1000; transition: left 0.3s ease; box-shadow: 5px 0 15px rgba(0,0,0,0.3); overflow-y: auto; }
        .sidebar.open { left: 0; }
        .sidebar h1 { font-size: 1.4em; margin-top: 0; margin-bottom: 25px; font-weight: 800; letter-spacing: 0.5px; color: var(--text-main); flex-shrink: 0; }
        .sidebar h1 span { color: var(--accent); }
        .nav-links { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; flex-grow: 1; }
        .nav-links a { color: var(--text-muted); text-decoration: none; padding: 10px 15px; border-radius: 6px; font-weight: 500; transition: background 0.2s, color 0.2s; display: block; }
        .nav-links a:hover, .nav-links a.active { background: rgba(59, 130, 246, 0.1); color: var(--accent); }

        .sidebar-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 999; display: none; opacity: 0; transition: opacity 0.3s; backdrop-filter: blur(3px); }
        html.mobile-menu-open .sidebar-overlay { display: block; opacity: 1; }
        html.mobile-menu-open body { overflow: hidden; } 

        .nav-group-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; color: var(--text-main); font-weight: 600; cursor: pointer; border-radius: 6px; transition: background 0.2s; user-select: none; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.8; }
        .nav-group-header:hover { background: rgba(0, 0, 0, 0.1); opacity: 1; }
        .nav-group-header .chevron { transition: transform 0.3s ease; font-size: 0.8em; }
        .nav-group-header.open .chevron { transform: rotate(180deg); }
        
        .submenu { list-style: none; padding: 0; margin: 0; display: none; flex-direction: column; gap: 5px; padding-left: 15px; margin-top: 5px; border-left: 2px solid var(--border-color); margin-left: 15px; }
        .submenu.open { display: flex; }
        .submenu a { padding: 8px 15px; font-size: 0.95em; }

        .pin-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1em; transition: color 0.2s, transform 0.2s; opacity: 0.7; }
        .pin-btn:hover { opacity: 1; color: var(--accent); }
        html.sidebar-pinned .sidebar { left: 0; box-shadow: none; }
        html.sidebar-pinned .main-content { margin-left: 260px; width: calc(100% - 260px); }
        html.sidebar-pinned .close-sidebar-btn { display: none; }
        html.sidebar-pinned .pin-btn { color: var(--accent); opacity: 1; transform: rotate(-45deg); }

        .menu-toggle { display: inline-block; background: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-main); padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 1.2em; }

        .main-content { flex: 1; display: flex; flex-direction: column; overflow-x: hidden; width: 100%; background-color: var(--bg-color); transition: margin-left 0.3s ease, width 0.3s ease; }
        .top-bar { background: var(--card-bg); border-bottom: 1px solid var(--border-color); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; gap: 20px; position: relative; }
        .container { padding: 30px; max-width: 1200px; width: 100%; box-sizing: border-box; flex: 1; margin: 0 auto; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; box-sizing: border-box; margin-bottom: 20px; color: var(--text-main); }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; }
        .page-header h2 { margin: 0; color: var(--text-main); }

        .btn { background: var(--accent); color: white; border: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: opacity 0.2s; box-sizing: border-box; text-align: center; }
        .btn:hover { opacity: 0.9; }
        .btn-small { padding: 8px 14px; font-size: 0.9em; }
        .btn-danger { background: var(--danger); color: white; }

        table { width: 100%; border-collapse: collapse; color: var(--text-main); background-color: transparent; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid var(--border-color); color: var(--text-main); }
        th { color: var(--text-muted); font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; }

        .notif-wrapper { position: relative; display: inline-block; }
        .notif-bell { background: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-muted); padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 1.1em; position: relative; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; }
        .notif-bell:hover { border-color: var(--accent); color: var(--accent); background: rgba(59, 130, 246, 0.05); }
        .notif-badge { position: absolute; top: -6px; right: -6px; background: var(--accent); color: white; font-size: 0.7em; padding: 2px 6px; border-radius: 50%; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3); }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 320px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); z-index: 1100; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border-color); font-weight: bold; font-size: 0.9em; display: flex; justify-content: space-between; align-items: center; background: var(--bg-color); color: var(--text-main); }
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px dashed var(--border-color); font-size: 0.85em; color: var(--text-main); display: block; text-decoration: none; transition: background 0.2s; }
        .notif-item:hover { background: rgba(59, 130, 246, 0.1); }
        .notif-item p { margin: 0 0 4px 0; }
        .notif-time { color: var(--text-muted); font-size: 0.75em; }

        .ql-snow .ql-stroke { stroke: var(--text-main) !important; }
        .ql-snow .ql-fill { fill: var(--text-main) !important; }
        .ql-snow .ql-picker { color: var(--text-main) !important; }
        .ql-editor { color: var(--text-main) !important; background: var(--bg-color); }
        .ql-toolbar.ql-snow { background-color: var(--card-bg); border-color: var(--border-color) !important; }
        .ql-container.ql-snow { border-color: var(--border-color) !important; background: var(--card-bg); }

        /* Global Modals */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 10000; padding: 15px; backdrop-filter: blur(2px); }
        .modal-content { background: var(--card-bg); padding: 30px; border-radius: 12px; width: 715px; max-width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid var(--border-color); box-sizing: border-box; }
        
        .sys-dialog-content { width: 400px; padding: 25px; }
        .sys-dialog-content h3 { margin: 0 0 15px 0; color: var(--text-main); }
        .sys-dialog-content p { color: var(--text-muted); margin-bottom: 20px; line-height: 1.5; }

        @media (max-width: 900px) {
            html.sidebar-pinned .sidebar { left: -260px; box-shadow: 5px 0 15px rgba(0,0,0,0.3); }
            html.sidebar-pinned .main-content { margin-left: 0; width: 100%; }
            html.sidebar-pinned .sidebar.open { left: 0; }
            html.sidebar-pinned .close-sidebar-btn { display: inline-block; }
            .pin-btn { display: none; }
        }

        @media (max-width: 768px) {
            input, select, textarea { font-size: 16px !important; }
            input:not([type="checkbox"]):not([type="radio"]), select, button, .btn { min-height: 44px; }
            .sheet-input { min-height: 36px !important; }

            .container { padding: 12px; }
            .top-bar { padding: 12px 15px; gap: 10px; }
            .card, .kanban-column, .budget-panel, .account-card, .summary-card { padding: 15px; margin-bottom: 15px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 15px; padding-bottom: 10px; }
            .page-header h2 { font-size: 1.3em; }

            .user-email-display { display: none; }
            #header-clock { padding-right: 10px; border-right: none; font-size: 0.8em; }
            .notif-dropdown { width: 280px; right: -50px; }

            .form-row, .budget-grid, .scenario-manager, .kanban-board { grid-template-columns: 1fr !important; display: flex !important; flex-direction: column !important; gap: 12px; }
            .kanban-column, .task-card { min-width: 0; width: 100%; }
            .button-group, .date-selectors { flex-direction: column; width: 100%; }
            .button-group button, .date-selectors select, .date-selectors button { width: 100%; }
            
            .toolbar-top, .budget-header-area { flex-direction: column; align-items: stretch !important; gap: 12px; }
            .toolbar-top > div, .budget-header-area > div { width: 100%; justify-content: flex-start; flex-wrap: wrap; gap: 8px; }
            #ledger-search { width: 100% !important; }
            .global-total-box { flex: 1; min-width: 45%; }
            
            /* Responsive Table Cards */
            .mobile-card-table thead { display: none; }
            .mobile-card-table tbody tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-color); padding: 5px 15px; }
            .mobile-card-table tbody td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed var(--border-color); padding: 10px 0; text-align: right; }
            .mobile-card-table tbody td:last-child { border-bottom: none; }
            .mobile-card-table tbody td::before { content: attr(data-label); font-weight: bold; color: var(--text-muted); font-size: 0.85em; text-transform: uppercase; margin-right: 15px; }

            .modal-overlay { padding: 10px !important; align-items: flex-start !important; }
            .modal-content { margin: 20px auto !important; padding: 20px 15px !important; width: 100% !important; height: auto !important; max-height: none !important; }
            .modal-content input:not([type="checkbox"]):not([type="radio"]), .modal-content select, .modal-content textarea, .modal-content .btn { width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; }
            .ql-toolbar.ql-snow { display: flex !important; flex-wrap: wrap !important; }
            
            /* Fix Quill Toolbar Buttons inheriting 44px mobile height */
            .ql-toolbar.ql-snow button { min-height: 28px !important; width: 28px !important; height: 28px !important; padding: 3px !important; }
            .ql-toolbar.ql-snow .ql-picker { min-height: 28px !important; height: 28px !important; }
        }
    </style>
    <script>
        if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-theme'); }
        if (localStorage.getItem('sidebarPinned') === 'true') { document.documentElement.classList.add('sidebar-pinned'); }

        function toggleSidebar() { 
            if (!document.documentElement.classList.contains('sidebar-pinned') || window.innerWidth <= 900) {
                document.getElementById('app-sidebar').classList.toggle('open'); 
                document.documentElement.classList.toggle('mobile-menu-open');
            }
        }
        
        function togglePin() {
            const isPinned = document.documentElement.classList.toggle('sidebar-pinned');
            localStorage.setItem('sidebarPinned', isPinned ? 'true' : 'false');
            if (isPinned) { 
                document.getElementById('app-sidebar').classList.remove('open'); 
                document.documentElement.classList.remove('mobile-menu-open');
            }
        }

        function toggleSubmenu(element) {
            element.classList.toggle('open');
            let submenu = element.nextElementSibling;
            if (submenu.classList.contains('open')) {
                submenu.classList.remove('open');
            } else {
                submenu.classList.add('open');
            }
        }

        function toggleNotifications(event) { event.stopPropagation(); document.getElementById('notif-dropdown').classList.toggle('show'); }
        window.addEventListener('click', function() { const dropdown = document.getElementById('notif-dropdown'); if (dropdown && dropdown.classList.contains('show')) { dropdown.classList.remove('show'); } });
        
        const userTimeZone = '<?= htmlspecialchars($_SESSION['timezone'] ?? 'America/Chicago', ENT_QUOTES, 'UTF-8') ?>';
        
        function updateClock() {
            const now = new Date();
            const clockEl = document.getElementById('header-clock');
            if (clockEl) {
                try {
                    clockEl.innerText = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: userTimeZone });
                } catch (e) {
                    clockEl.innerText = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                }
            }
        }
        setInterval(updateClock, 1000);
        window.addEventListener('DOMContentLoaded', updateClock);

        // --- GLOBAL CUSTOM DIALOGS ---
        function appAlert(msg, title = 'Alert') {
            return new Promise(resolve => {
                const overlay = document.getElementById('sys-dialog-overlay');
                document.getElementById('sys-dialog-title').innerText = title;
                document.getElementById('sys-dialog-msg').innerText = msg;
                document.getElementById('sys-dialog-input').style.display = 'none';
                document.getElementById('sys-dialog-cancel').style.display = 'none';
                overlay.style.display = 'flex';

                const btnOk = document.getElementById('sys-dialog-ok');
                const cleanup = () => { overlay.style.display = 'none'; btnOk.replaceWith(btnOk.cloneNode(true)); };
                btnOk.addEventListener('click', () => { cleanup(); resolve(true); });
            });
        }

        function appConfirm(msg, title = 'Confirm') {
            return new Promise(resolve => {
                const overlay = document.getElementById('sys-dialog-overlay');
                document.getElementById('sys-dialog-title').innerText = title;
                document.getElementById('sys-dialog-msg').innerText = msg;
                document.getElementById('sys-dialog-input').style.display = 'none';
                document.getElementById('sys-dialog-cancel').style.display = 'block';
                overlay.style.display = 'flex';

                const btnOk = document.getElementById('sys-dialog-ok');
                const btnCancel = document.getElementById('sys-dialog-cancel');
                
                const cleanup = () => { 
                    overlay.style.display = 'none'; 
                    btnOk.replaceWith(btnOk.cloneNode(true)); 
                    btnCancel.replaceWith(btnCancel.cloneNode(true)); 
                };

                btnOk.addEventListener('click', () => { cleanup(); resolve(true); });
                btnCancel.addEventListener('click', () => { cleanup(); resolve(false); });
            });
        }

        function appPrompt(msg, defaultVal = '', title = 'Input Required') {
            return new Promise(resolve => {
                const overlay = document.getElementById('sys-dialog-overlay');
                const input = document.getElementById('sys-dialog-input');
                document.getElementById('sys-dialog-title').innerText = title;
                document.getElementById('sys-dialog-msg').innerText = msg;
                document.getElementById('sys-dialog-cancel').style.display = 'block';
                
                input.style.display = 'block';
                input.value = defaultVal;
                overlay.style.display = 'flex';
                input.focus();

                const btnOk = document.getElementById('sys-dialog-ok');
                const btnCancel = document.getElementById('sys-dialog-cancel');

                const cleanup = () => { 
                    overlay.style.display = 'none'; 
                    btnOk.replaceWith(btnOk.cloneNode(true)); 
                    btnCancel.replaceWith(btnCancel.cloneNode(true)); 
                };

                btnOk.addEventListener('click', () => { cleanup(); resolve(input.value); });
                btnCancel.addEventListener('click', () => { cleanup(); resolve(null); });
            });
        }
    </script>
</head>
<body>

    <!-- System Dialog Modal -->
    <div id="sys-dialog-overlay" class="modal-overlay">
        <div class="modal-content sys-dialog-content">
            <h3 id="sys-dialog-title">System</h3>
            <p id="sys-dialog-msg"></p>
            <input type="text" id="sys-dialog-input" style="width: 100%; padding: 10px; margin-bottom: 20px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
            <div style="display:flex; gap: 10px; justify-content: flex-end;">
                <button id="sys-dialog-cancel" class="btn" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Cancel</button>
                <button id="sys-dialog-ok" class="btn">OK</button>
            </div>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

    <?php if (isset($_SESSION['impersonator_id'])): ?>
        <div style="background: var(--warning); color: #000; padding: 10px 20px; text-align: center; font-weight: bold; font-size: 0.9em; display: flex; justify-content: center; align-items: center; gap: 15px; position: fixed; top: 0; left: 0; width: 100%; z-index: 9999; box-sizing: border-box;">
            <span>⚠️ You are currently impersonating user: <u><?= htmlspecialchars($_SESSION['email']) ?></u></span>
            <a href="<?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] ?>/admin/impersonate.php?action=stop" style="background: #000; color: #fff; padding: 4px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em;">Exit Impersonation</a>
        </div>
        <style>body { padding-top: 42px; }</style>
    <?php endif; ?>

    <?php if ($is_logged_in): ?>
        <div class="sidebar" id="app-sidebar">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-shrink: 0;">
                <h1 style="margin: 0;">Personal<span>Apps</span></h1>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button onclick="togglePin()" class="pin-btn" title="Pin Sidebar">📌</button>
                    <button onclick="toggleSidebar()" class="close-sidebar-btn" style="background: transparent; border: none; color: var(--text-muted); font-size: 1.2em; cursor: pointer;" title="Close Menu">✕</button>
                </div>
            </div>
            
            <ul class="nav-links">
                <li><a href="/index.php" class="<?= $current_page === 'index.php' ? 'active' : '' ?>">Dashboard</a></li>
                
                <li>
                    <div class="nav-group-header <?= $is_planning_open ? 'open' : '' ?>" onclick="toggleSubmenu(this)">
                        <span>Planning</span>
                        <span class="chevron">▼</span>
                    </div>
                    <ul class="submenu <?= $is_planning_open ? 'open' : '' ?>">
                        <li><a href="/planner.php" class="<?= $current_page === 'planner.php' ? 'active' : '' ?>">Daily Planner</a></li>
                        <li><a href="/tasks.php" class="<?= $current_page === 'tasks.php' ? 'active' : '' ?>">Tasks & Plans</a></li>
                        <li><a href="/meetings.php" class="<?= $current_page === 'meetings.php' ? 'active' : '' ?>">Meetings</a></li>
                    </ul>
                </li>

                <li>
                    <div class="nav-group-header <?= $is_knowledge_open ? 'open' : '' ?>" onclick="toggleSubmenu(this)">
                        <span>Knowledge</span>
                        <span class="chevron">▼</span>
                    </div>
                    <ul class="submenu <?= $is_knowledge_open ? 'open' : '' ?>">
                        <li><a href="/notes.php" class="<?= $current_page === 'notes.php' ? 'active' : '' ?>">Notes</a></li>
                        <li><a href="/kb.php" class="<?= $current_page === 'kb.php' ? 'active' : '' ?>">Knowledge Base</a></li>
                    </ul>
                </li>
                
                <li>
                    <div class="nav-group-header <?= $is_finance_open ? 'open' : '' ?>" onclick="toggleSubmenu(this)">
                        <span>Finance</span>
                        <span class="chevron">▼</span>
                    </div>
                    <ul class="submenu <?= $is_finance_open ? 'open' : '' ?>">
                        <li><a href="/ledger.php" class="<?= $current_page === 'ledger.php' ? 'active' : '' ?>">Annual Ledger</a></li>
                        <li><a href="/budget.php" class="<?= $current_page === 'budget.php' ? 'active' : '' ?>">Budget Planner</a></li>
                        <li><a href="/portfolio.php" class="<?= $current_page === 'portfolio.php' ? 'active' : '' ?>">Portfolio Calculator</a></li>
                    </ul>
                </li>

                
                
                <li><a href="/timesheet.php" class="<?= $current_page === 'timesheet.php' ? 'active' : '' ?>">Time Tracker</a></li>
                <li><a href="/search.php" class="<?= $current_page === 'search.php' ? 'active' : '' ?>">Global Search</a></li>
                
                <li style="margin-top: auto; padding-top: 15px; border-top: 1px solid var(--border-color); display: flex; gap: 8px;">
                    <a href="/settings.php" class="<?= $current_page === 'settings.php' ? 'active' : '' ?>" style="flex: 1; text-align: center; font-size: 1.3em; padding: 8px;" title="Settings">⚙️</a>
                    <?php if ($user_role === 'admin'): ?>
                        <a href="/admin/users.php" class="<?= strpos($current_page, 'user') !== false || strpos($current_page, 'admin') !== false ? 'active' : '' ?>" style="flex: 1; text-align: center; font-size: 1.3em; padding: 8px;" title="Admin Panel">🛡️</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    <?php endif; ?>

    <div class="main-content">
        <?php if ($is_logged_in): ?>
            <div class="top-bar">
                <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle Navigation">☰ Menu</button>
                
                <div style="display: flex; align-items: center; gap: 20px; margin-left: auto;">
                    <span id="header-clock" style="font-size: 0.9em; font-weight: bold; color: var(--text-muted); border-right: 1px solid var(--border-color); padding-right: 15px;"></span>

                    <div class="notif-wrapper">
                        <button class="notif-bell" onclick="toggleNotifications(event)" aria-label="Notifications">
                            🔔
                            <?php if ($unread_count > 0): ?>
                                <span class="notif-badge"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-dropdown" id="notif-dropdown" onclick="event.stopPropagation()">
                            <div class="notif-header">
                                <span>Notifications</span>
                                <?php if ($unread_count > 0): ?>
                                    <a href="/notifications.php?action=mark_all_read" style="font-size: 0.8em; color: var(--accent);">Mark all read</a>
                                <?php endif; ?>
                            </div>
                            <div class="notif-body">
                                <?php if (empty($notifications)): ?>
                                    <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.85em;">No new notifications</div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notif): ?>
                                        <a href="/notifications.php" class="notif-item">
                                            <p><strong><?= htmlspecialchars($notif['title'] ?? 'Alert') ?></strong></p>
                                            <p style="color: var(--text-muted);"><?= htmlspecialchars($notif['message'] ?? '') ?></p>
                                            <span class="notif-time"><?= date('M j, g:i a', strtotime($notif['created_at'])) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <span class="user-email-display" style="font-size: 0.9em; color: var(--text-muted);"><?= htmlspecialchars($_SESSION['email']) ?></span>
                    <a href="/logout.php" class="btn btn-small btn-danger">Logout</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="container">