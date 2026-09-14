<?php
// index.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$db = new Database();
$pdo = $db->getConnection();
$current_time = date('Y-m-d H:i:s');

// Handle Quick Complete for Tasks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_complete_task') {
    $task_id = (int)$_POST['task_id'];
    $stmt = $pdo->prepare("UPDATE tasks SET status = 'completed' WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $task_id, ':user_id' => $user_id]);
    log_audit_action($pdo, $user_id, 'COMPLETE_TASK_DASH', 'Tasks', ['task_id' => $task_id]);
    header("Location: index.php");
    exit();
}

// 1. Fetch Top Tasks
$stmt = $pdo->prepare("SELECT id, title, status, due_date, task_category FROM tasks WHERE user_id = :uid AND status != 'completed' ORDER BY due_date ASC, created_at DESC LIMIT 3");
$stmt->execute([':uid' => $user_id]);
$tasks = $stmt->fetchAll();

// 2. Fetch Upcoming Meetings
$stmt = $pdo->prepare("SELECT id, title, meeting_date FROM meetings WHERE user_id = :uid AND meeting_date >= :now ORDER BY meeting_date ASC LIMIT 3");
$stmt->execute([':uid' => $user_id, ':now' => $current_time]);
$meetings = $stmt->fetchAll();

// 3. Fetch Recent Notes
$stmt = $pdo->prepare("SELECT id, title, created_at FROM notes WHERE user_id = :uid ORDER BY created_at DESC LIMIT 3");
$stmt->execute([':uid' => $user_id]);
$notes = $stmt->fetchAll();

// 4. Fetch Recent KB Articles
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, title, category, is_private, updated_at FROM kb_articles ORDER BY updated_at DESC LIMIT 3");
    $kb_articles = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, title, category, is_private, updated_at FROM kb_articles WHERE (is_private = 0 OR author_id = :uid) ORDER BY updated_at DESC LIMIT 3");
    $stmt->execute([':uid' => $user_id]);
    $kb_articles = $stmt->fetchAll();
}

// 5. Fetch Activity Momentum
$activity_data = [];
try {
    $stmt = $pdo->prepare("SELECT DATE(created_at) as log_date, COUNT(*) as count FROM audit_logs WHERE user_id = :uid AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY) GROUP BY DATE(created_at)");
    $stmt->execute([':uid' => $user_id]);
    foreach ($stmt->fetchAll() as $row) { $activity_data[$row['log_date']] = $row['count']; }
} catch (Exception $e) {}

// 6. Fetch Active Timesheet Draft
$stmt_ts = $pdo->prepare("SELECT active_draft FROM users WHERE id = :uid");
$stmt_ts->execute([':uid' => $user_id]);
$ts_row = $stmt_ts->fetch();
$active_timesheet = (!empty($ts_row['active_draft'])) ? json_decode($ts_row['active_draft'], true) : null;

$ts_work = 0; $ts_pto = 0; $ts_personal = 0; $ts_dates = 'Current Week';
if ($active_timesheet && isset($active_timesheet['rows'])) {
    $ts_dates = $active_timesheet['meta']['dates'] ?? 'Current Week';
    if (empty(trim($ts_dates))) $ts_dates = 'Current Week';
    foreach ($active_timesheet['rows'] as $r) {
        $val = (float)($r['total'] ?? 0);
        $type = $r['type'] ?? 'Work';
        if ($type === 'PTO') $ts_pto += $val;
        elseif ($type === 'Personal') $ts_personal += $val;
        else $ts_work += $val;
    }
}
$ts_grand_total = $ts_work + $ts_pto + $ts_personal;

// 7. Fetch Today's Daily Plan
$today_date = date('Y-m-d');
$today_plan = null;
try {
    $stmt_plan = $pdo->prepare("SELECT plan_data FROM daily_plans WHERE user_id = :uid AND plan_date = :pdate");
    $stmt_plan->execute([':uid' => $user_id, ':pdate' => $today_date]);
    $plan_row = $stmt_plan->fetch(PDO::FETCH_ASSOC);
    if ($plan_row && !empty($plan_row['plan_data'])) {
        $today_plan = json_decode($plan_row['plan_data'], true);
    }
} catch (Exception $e) {}
$plan_priorities = $today_plan['priorities'] ?? [];

// 8. Fetch Finance Data (Ledger & Budget)
$current_year = (int)date('Y');
$current_month_num = (int)date('n');
$tab_map = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'June', 7=>'July', 8=>'Aug', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
$current_month_tab = $tab_map[$current_month_num];

$upcoming_ledger_items = [];
$ledger_json = null;
try {
    $stmt_ledger = $pdo->prepare("SELECT ledger_data FROM yearly_ledgers WHERE user_id = :uid AND ledger_year = :year");
    $stmt_ledger->execute([':uid' => $user_id, ':year' => $current_year]);
    $ledger_row = $stmt_ledger->fetch(PDO::FETCH_ASSOC);
    if ($ledger_row && !empty($ledger_row['ledger_data'])) {
        $ledger_json = json_decode($ledger_row['ledger_data'], true);
        if (isset($ledger_json['months'][$current_month_tab])) {
            foreach ($ledger_json['months'][$current_month_tab] as $row) {
                $item_name = strtolower(trim($row['item'] ?? ''));
                $completed_status = strtolower(trim($row['completed'] ?? ''));
                if (!empty($row['item']) && $item_name !== 'open' && $completed_status !== 'x' && $completed_status !== 'yes') {
                    $upcoming_ledger_items[] = $row;
                }
            }
        }
    }
} catch (Exception $e) {}

$budget_inc = 0;
$budget_exp = 0;
$category_progress = [];
try {
    $stmt_budget = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = :year AND budget_month = :month");
    $stmt_budget->execute([':uid' => $user_id, ':year' => $current_year, ':month' => $current_month_num]);
    $budget_row = $stmt_budget->fetch(PDO::FETCH_ASSOC);
    
    // If no budget for this month, try to load base template (Year 0, Month 0)
    if (!$budget_row || empty($budget_row['budget_data'])) {
        $stmt_budget = $pdo->prepare("SELECT budget_data FROM budget_plans WHERE user_id = :uid AND budget_year = 0 AND budget_month = 0");
        $stmt_budget->execute([':uid' => $user_id]);
        $budget_row = $stmt_budget->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($budget_row && !empty($budget_row['budget_data'])) {
        $budget_json = json_decode($budget_row['budget_data'], true);
        if (isset($budget_json['income'])) {
            foreach ($budget_json['income'] as $inc) { $budget_inc += (float)($inc['amount'] ?? 0); }
        }
        if (isset($budget_json['expenses'])) {
            foreach ($budget_json['expenses'] as $exp) { 
                $amt = (float)($exp['amount'] ?? 0);
                $budget_exp += $amt; 
                
                $grp = trim($exp['group'] ?? 'Uncategorized');
                if (!isset($category_progress[$grp])) {
                    $category_progress[$grp] = ['planned' => 0, 'actual' => 0];
                }
                $category_progress[$grp]['planned'] += $amt;
            }
        }
    }
} catch (Exception $e) {}

// Calculate Actuals by mapping Ledger to Budget Categories
if (isset($ledger_json['months'][$current_month_tab])) {
    foreach ($ledger_json['months'][$current_month_tab] as $row) {
        $cat = trim($row['category'] ?? '');
        $amt = (float)($row['amount'] ?? 0);
        // If it matches a budget category and it is an expense (negative number in ledger)
        if (!empty($cat) && isset($category_progress[$cat]) && $amt < 0) {
            $category_progress[$cat]['actual'] += abs($amt);
        }
    }
}

$budget_remaining = $budget_inc - $budget_exp;
$budget_pct = $budget_inc > 0 ? ($budget_exp / $budget_inc) * 100 : 0;

$page_title = "Dashboard - PersonalApps";
include 'includes/header.php';
?>

<style>
    .kanban-board { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; align-items: start; margin-top: 25px; }
    .kanban-column { background: rgba(0, 0, 0, 0.1); border: 2px solid var(--border-color); border-radius: 12px; padding: 15px; min-height: 400px; }
    .kanban-header { font-size: 1.1em; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .task-card { transition: transform 0.2s, box-shadow 0.2s; margin-bottom: 15px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; display: flex; flex-direction: column; }
    .task-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
</style>

<div class="page-header">
    <h2>Operations Dashboard</h2>
    <span style="color: var(--text-muted); font-size: 0.9em;"><?= date('l, F j, Y') ?></span>
</div>

<div class="card">
    <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; font-size: 1.1em;">28-Day Activity Momentum</h3>
    <div style="display: flex; gap: 6px; flex-wrap: wrap; padding: 10px 0;">
        <?php
        for ($i = 27; $i >= 0; $i--) {
            $date_str = date('Y-m-d', strtotime("-$i days"));
            $count = $activity_data[$date_str] ?? 0;
            $bg = 'var(--border-color)';
            if ($count > 0) $bg = 'rgba(59, 130, 246, 0.4)';
            if ($count > 3) $bg = 'rgba(59, 130, 246, 0.7)';
            if ($count > 7) $bg = '#3b82f6';
            echo "<div title='{$date_str}: {$count} actions' style='flex: 1; height: 30px; background: {$bg}; border-radius: 4px; min-width: 15px;'></div>";
        }
        ?>
    </div>
</div>

<div class="kanban-board">
    
    <div class="kanban-column" style="grid-column: 1 / -1; min-height: auto;">
        <div class="kanban-header" style="color: #0ea5e9; border-bottom-color: #0ea5e9;">
            <span>📅 Today's Plan <span style="font-size: 0.7em; color: var(--text-muted); font-weight: normal; margin-left: 10px;">(<?= date('M j, Y') ?>)</span></span>
            <a href="planner.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Open Planner →</a>
        </div>
        
        <?php 
        $has_priorities = false;
        foreach ($plan_priorities as $pri) {
            if (!empty(trim($pri['text']))) $has_priorities = true;
        }
        ?>

        <?php if (!$has_priorities): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No priorities set for today.</p>
        <?php else: ?>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <?php foreach ($plan_priorities as $pri): ?>
                    <?php if (!empty(trim($pri['text']))): 
                        $is_done = !empty($pri['done']);
                        $bg = $is_done ? 'rgba(34, 197, 94, 0.05)' : 'var(--card-bg)';
                        $border = $is_done ? 'var(--success)' : '#0ea5e9';
                        $text_decor = $is_done ? 'line-through' : 'none';
                        $text_color = $is_done ? 'var(--text-muted)' : 'var(--text-main)';
                        $icon = $is_done ? '✅' : '🎯';
                    ?>
                    <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid <?= $border ?>; margin-bottom: 0; background: <?= $bg ?>; display: flex; flex-direction: row; align-items: center; gap: 12px; padding: 20px;">
                        <span style="font-size: 1.2em;"><?= $icon ?></span>
                        <span style="font-size: 1.15em; font-weight: 500; color: <?= $text_color ?>; text-decoration: <?= $text_decor ?>;"><?= htmlspecialchars($pri['text']) ?></span>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Active Tasks Panel -->
    <div class="kanban-column">
        <div class="kanban-header" style="color: var(--warning); border-bottom-color: var(--warning);">
            <span>🚀 Active Tasks</span>
            <a href="tasks.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Board →</a>
        </div>
        <?php if(empty($tasks)): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">All caught up!</p>
        <?php else: ?>
            <?php foreach ($tasks as $task): ?>
                <div class="task-card" style="border-left: 4px solid var(--warning);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h4 style="margin: 0 0 8px 0; font-size: 1.05em;">
                                <a href="tasks.php" style="color: var(--text-main); text-decoration: none;"><?= htmlspecialchars($task['title']) ?></a>
                            </h4>
                            <span style="font-size: 0.75em; color: var(--text-muted);">
                                Due: <?= $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'None' ?> 
                                <span style="opacity: 0.5;">|</span> <?= htmlspecialchars($task['task_category']) ?>
                            </span>
                        </div>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="quick_complete_task">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <button type="submit" class="btn btn-small" style="background: var(--success); padding: 4px 8px; border: none;" title="Mark Completed">✓</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recent Notes Panel -->
    <div class="kanban-column">
        <div class="kanban-header" style="color: #a855f7; border-bottom-color: #a855f7;">
            <span>📝 Notes</span>
            <a href="notes.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Open →</a>
        </div>
        <?php if(empty($notes)): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No notes created yet.</p>
        <?php else: ?>
            <?php foreach ($notes as $note): ?>
                <div class="task-card" style="border-left: 4px solid #a855f7;">
                    <h4 style="margin: 0 0 6px 0; font-size: 1.05em;">
                        <a href="notes.php?id=<?= $note['id'] ?>" style="color: var(--text-main); text-decoration: none;"><?= htmlspecialchars($note['title'] ?: 'Untitled') ?></a>
                    </h4>
                    <span style="font-size: 0.75em; color: var(--text-muted);">Updated: <?= date('M j, Y', strtotime($note['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Upcoming Meetings Panel -->
    <div class="kanban-column">
        <div class="kanban-header" style="color: var(--accent); border-bottom-color: var(--accent);">
            <span>📅 Meetings</span>
            <a href="meetings.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Schedule →</a>
        </div>
        <?php if(empty($meetings)): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No upcoming meetings.</p>
        <?php else: ?>
            <?php foreach ($meetings as $m): ?>
                <div class="task-card" style="border-left: 4px solid var(--accent);">
                    <span style="font-size: 0.75em; color: var(--accent); font-weight: bold; margin-bottom: 6px;">
                        <?= date('M j, Y @ g:i A', strtotime($m['meeting_date'])) ?>
                    </span>
                    <h4 style="margin: 0; font-size: 1.05em;">
                        <a href="meetings.php" style="color: var(--text-main); text-decoration: none;"><?= htmlspecialchars($m['title']) ?></a>
                    </h4>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<!-- Latest KB Panel -->
    <div class="kanban-column" style="grid-column: 1 / -1; min-height: auto;">
        <div class="kanban-header" style="color: var(--success); border-bottom-color: var(--success);">
            <span>📚 Knowledge Base</span>
            <a href="kb.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Browse →</a>
        </div>
        <?php if(empty($kb_articles)): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No articles in Knowledge Base.</p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                <?php foreach ($kb_articles as $kb): ?>
                    <div class="task-card" style="border-left: 4px solid var(--success); margin-bottom: 0;">
                        <h4 style="margin: 0 0 6px 0; font-size: 1.05em; display: flex; align-items: center; gap: 6px;">
                            <a href="kb.php?id=<?= $kb['id'] ?>" style="color: var(--text-main); text-decoration: none;"><?= htmlspecialchars($kb['title'] ?: 'Untitled') ?></a>
                            <?php if ($kb['is_private']): ?>
                                <span style="font-size: 0.65em; background: rgba(234, 179, 8, 0.1); color: var(--warning); border: 1px solid var(--warning); padding: 2px 6px; border-radius: 4px;">🔒</span>
                            <?php endif; ?>
                        </h4>
                        <span style="font-size: 0.75em; color: var(--text-muted);">
                            Folder: <span style="color: var(--accent);"><?= htmlspecialchars($kb['category']) ?></span> 
                            <span style="opacity: 0.5;">|</span> <?= date('M j', strtotime($kb['updated_at'])) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Ledger Quick Look -->
    <div class="kanban-column" style="grid-column: 1 / -1; min-height: auto;">
        <div class="kanban-header" style="color: #8e7cc3; border-bottom-color: #8e7cc3;">
            <span>📓 Ledger: Upcoming Bills & Items <span style="font-size: 0.7em; color: var(--text-muted); font-weight: normal; margin-left: 10px;">(<?= $current_month_tab ?> <?= $current_year ?>)</span></span>
            <a href="ledger.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Open Ledger →</a>
        </div>
        
        <?php if (empty($upcoming_ledger_items)): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No upcoming items or bills pending for this month.</p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                <?php foreach ($upcoming_ledger_items as $l_item): 
                    $amt = floatval($l_item['amount'] ?? 0);
                    $amt_display = $amt !== 0.0 ? '$' . number_format(abs($amt), 2) : '-';
                    $color = $amt < 0 ? 'var(--danger)' : ($amt > 0 ? 'var(--success)' : 'var(--text-main)');
                ?>
                    <div class="task-card" style="border-left: 4px solid #8e7cc3; margin-bottom: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px;">
                            <h4 style="margin: 0; font-size: 1.05em; color: var(--text-main);">
                                <?= htmlspecialchars($l_item['item'] ?? '') ?>
                            </h4>
                            <span style="font-weight: bold; color: <?= $color ?>;">
                                <?= $amt < 0 ? '-' : '' ?><?= $amt_display ?>
                            </span>
                        </div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">
                            <?php if (!empty($l_item['date'])): ?>
                                Date: <strong><?= htmlspecialchars($l_item['date']) ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($l_item['desc'])): ?>
                                <span style="opacity: 0.5; margin: 0 5px;">|</span> <?= htmlspecialchars($l_item['desc']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<!-- Budget Planner Quick Look with Category Progress -->
    <div class="kanban-column" style="grid-column: 1 / -1; min-height: auto;">
        <div class="kanban-header" style="color: var(--success); border-bottom-color: var(--success);">
            <span>📊 Zero-Based Budget <span style="font-size: 0.7em; color: var(--text-muted); font-weight: normal; margin-left: 10px;">(<?= date('F Y') ?>)</span></span>
            <a href="budget.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Manage Budget →</a>
        </div>
        
        <?php if ($budget_inc == 0 && $budget_exp == 0): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No budget planned for this month.</p>
        <?php else: ?>
            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
                <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid var(--success); margin-bottom: 0; align-items: center; justify-content: center;">
                    <span style="font-size: 0.85em; color: var(--text-muted); text-transform: uppercase;">Expected Income</span>
                    <span style="font-size: 1.8em; font-weight: bold; color: var(--text-main);">$<?= number_format($budget_inc, 2) ?></span>
                </div>
                <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid var(--danger); margin-bottom: 0; align-items: center; justify-content: center;">
                    <span style="font-size: 0.85em; color: var(--text-muted); text-transform: uppercase;">Assigned Dollars</span>
                    <span style="font-size: 1.8em; font-weight: bold; color: var(--text-main);">$<?= number_format($budget_exp, 2) ?></span>
                </div>
                <?php 
                    $rem_color = 'var(--accent)';
                    $rem_label = 'Unallocated Funds';
                    if ($budget_remaining > 0) { $rem_color = 'var(--warning)'; $rem_label = 'Left to Give a Job'; }
                    if ($budget_remaining < 0) { $rem_color = 'var(--danger)'; $rem_label = 'Over Budget'; }
                    if ($budget_remaining == 0 && $budget_inc > 0) { $rem_color = 'var(--success)'; $rem_label = 'Perfectly Zero-Based!'; }
                ?>
                <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid <?= $rem_color ?>; margin-bottom: 0; align-items: center; justify-content: center;">
                    <span style="font-size: 0.85em; color: <?= $rem_color ?>; text-transform: uppercase; font-weight: bold;"><?= $rem_label ?></span>
                    <span style="font-size: 2em; font-weight: bold; color: <?= $rem_color ?>;">$<?= number_format(abs($budget_remaining), 2) ?></span>
                </div>
            </div>
            
            <?php
                $pct_color = 'var(--accent)';
                if ($budget_pct > 100) $pct_color = 'var(--danger)';
                elseif ($budget_pct == 100) $pct_color = 'var(--success)';
                elseif ($budget_pct > 0) $pct_color = 'var(--warning)';
                $width_pct = min(100, $budget_pct);
            ?>
            <div style="background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; height: 24px; width: 100%; overflow: hidden; position: relative;">
                <div style="background: <?= $pct_color ?>; height: 100%; width: <?= $width_pct ?>%;"></div>
                <div style="position: absolute; width: 100%; text-align: center; top: 3px; font-size: 0.85em; font-weight: bold; color: var(--text-main); mix-blend-mode: difference;"><?= number_format($budget_pct, 1) ?>% Assigned</div>
            </div>

            <!-- Actual vs. Planned Category Tracking -->
            <?php if (!empty($category_progress)): ?>
                <div style="margin-top: 25px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
                    <h4 style="margin: 0 0 15px 0; font-size: 0.95em; color: var(--text-muted); text-transform: uppercase;">Actual vs. Planned Spending</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                        <?php foreach ($category_progress as $cat => $data): 
                            $planned = $data['planned'];
                            $actual = $data['actual'];
                            $pct = $planned > 0 ? ($actual / $planned) * 100 : ($actual > 0 ? 100 : 0);
                            
                            $bar_color = 'var(--success)';
                            if ($pct > 85) $bar_color = 'var(--warning)';
                            if ($pct > 100) $bar_color = 'var(--danger)';
                            $width = min(100, $pct);
                        ?>
                            <div style="background: var(--bg-color); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9em;">
                                    <strong style="color: var(--text-main);"><?= htmlspecialchars($cat) ?></strong>
                                    <span style="color: var(--text-muted);">$<?= number_format($actual, 2) ?> / $<?= number_format($planned, 2) ?></span>
                                </div>
                                <div style="background: var(--card-bg); border-radius: 4px; height: 8px; width: 100%; overflow: hidden;">
                                    <div style="background: <?= $bar_color ?>; height: 100%; width: <?= $width ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Timesheet Quick Look Panel -->
    <div class="kanban-column" style="grid-column: 1 / -1; min-height: auto;">
        <div class="kanban-header" style="color: var(--warning); border-bottom-color: var(--warning);">
            <span>⏱️ Current Timesheet <span style="font-size: 0.7em; color: var(--text-muted); font-weight: normal; margin-left: 10px;">(<?= htmlspecialchars($ts_dates) ?>)</span></span>
            <a href="timesheet.php" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">Open Tracker →</a>
        </div>
        
        <?php if (!$active_timesheet || empty($active_timesheet['rows'])): ?>
            <p style="color: var(--text-muted); font-size: 0.9em; font-style: italic; text-align: center; margin-top: 20px;">No active timesheet data.</p>
        <?php else: ?>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid var(--accent); margin-bottom: 0; align-items: center; justify-content: center;">
                    <span style="font-size: 0.85em; color: var(--text-muted); text-transform: uppercase;">Work Hours</span>
                    <span style="font-size: 1.8em; font-weight: bold; color: var(--text-main);"><?= number_format($ts_work, 2) ?></span>
                </div>
                <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid var(--success); margin-bottom: 0; align-items: center; justify-content: center;">
                    <span style="font-size: 0.85em; color: var(--text-muted); text-transform: uppercase;">PTO / Personal</span>
                    <span style="font-size: 1.8em; font-weight: bold; color: var(--text-main);"><?= number_format($ts_pto + $ts_personal, 2) ?></span>
                </div>
                <div class="task-card" style="flex: 1; min-width: 250px; border-left: 4px solid var(--warning); margin-bottom: 0; align-items: center; justify-content: center; background: rgba(234, 179, 8, 0.05);">
                    <span style="font-size: 0.85em; color: var(--warning); text-transform: uppercase; font-weight: bold;">Grand Total</span>
                    <span style="font-size: 2em; font-weight: bold; color: var(--warning);"><?= number_format($ts_grand_total, 2) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php include 'includes/footer.php'; ?>