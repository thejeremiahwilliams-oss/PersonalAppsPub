<?php
// public/tasks.php

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';
require_once 'includes/notification_functions.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();
$message = '';

// Auto-Heal Database Schema
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN task_category INT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN completed_at DATETIME NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN reminder_date DATETIME NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN is_reminded TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_task') {
            $task_id = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $status = $_POST['status'] ?? 'open';
            
            // STRICT DATE FORMATTING TO PREVENT DATABASE REJECTION
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $reminder_date = !empty($_POST['reminder_date']) ? date('Y-m-d H:i:s', strtotime($_POST['reminder_date'])) : null;
            
            $completed_at = ($status === 'completed') ? date('Y-m-d H:i:s') : null;

            if (!empty($title)) {
                if ($task_id) {
                    $sql = "UPDATE tasks 
                            SET title = :title, description = :description, task_category = :category_id, 
                                due_date = :due_date, reminder_date = :reminder_date, status = :status, 
                                completed_at = :completed_at, is_reminded = 0
                            WHERE id = :id AND user_id = :uid";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':title' => $title, ':description' => $description, ':category_id' => $category_id,
                        ':due_date' => $due_date, ':reminder_date' => $reminder_date, ':status' => $status,
                        ':completed_at' => $completed_at, ':id' => $task_id, ':uid' => $user_id
                    ]);
                    log_audit_action($pdo, $user_id, 'UPDATE_TASK', 'Tasks', ['task_id' => $task_id, 'title' => $title]);
                } else {
                    $sql = "INSERT INTO tasks (user_id, task_category, title, description, due_date, reminder_date, status, completed_at, is_reminded) 
                            VALUES (:uid, :category_id, :title, :description, :due_date, :reminder_date, :status, :completed_at, 0)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':uid' => $user_id, ':category_id' => $category_id, ':title' => $title,
                        ':description' => $description, ':due_date' => $due_date, ':reminder_date' => $reminder_date,
                        ':status' => $status, ':completed_at' => $completed_at
                    ]);
                    $task_id = $pdo->lastInsertId();
                    log_audit_action($pdo, $user_id, 'CREATE_TASK', 'Tasks', ['task_id' => $task_id, 'title' => $title]);
                }
                header("Location: tasks.php");
                exit();
            } else {
                $message = "Task title cannot be empty.";
            }
        } elseif ($action === 'toggle_status') {
            $task_id = (int)$_POST['task_id'];
            $new_status = $_POST['new_status'];
            $completed_at = ($new_status === 'completed') ? date('Y-m-d H:i:s') : null;
            
            $sql = "UPDATE tasks SET status = :status, completed_at = :completed_at WHERE id = :id AND user_id = :uid";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':status' => $new_status, ':completed_at' => $completed_at, ':id' => $task_id, ':uid' => $user_id]);
            header("Location: tasks.php");
            exit();
        } elseif ($action === 'delete_task') {
            $task_id = (int)$_POST['task_id'];
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $task_id, ':uid' => $user_id]);
            header("Location: tasks.php");
            exit();
        }
    } catch (Exception $e) { $message = "Error: " . $e->getMessage(); }
}

try { $categories = $pdo->query("SELECT * FROM task_categories ORDER BY name ASC")->fetchAll(); } 
catch (Exception $e) { $categories = []; }

$filter_cat = $_GET['category'] ?? null;
$query = "SELECT t.*, c.name AS category_name, c.color AS category_color 
          FROM tasks t LEFT JOIN task_categories c ON t.task_category = c.id WHERE t.user_id = :uid";
$params = [':uid' => $user_id];
if ($filter_cat) { $query .= " AND t.task_category = :cat"; $params[':cat'] = $filter_cat; }
$query .= " ORDER BY t.due_date ASC, t.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_tasks = $stmt->fetchAll();
$tasks_json = json_encode($all_tasks);

$open_tasks = []; $inprogress_tasks = []; $completed_tasks = [];
foreach ($all_tasks as $t) {
    $status = strtolower(trim($t['status'] ?? ''));
    if ($status === 'in_progress') { $inprogress_tasks[] = $t; } 
    elseif ($status === 'completed') { $completed_tasks[] = $t; } 
    else { $open_tasks[] = $t; }
}

$page_title = "Tasks & Plans - PersonalApps";
include 'includes/header.php';
?>

<style>
    input, textarea, select { color: var(--text-main) !important; background-color: var(--bg-color) !important; }
    input::placeholder, textarea::placeholder { color: var(--text-muted) !important; opacity: 0.7; }
    
    .task-card { transition: transform 0.2s, box-shadow 0.2s; margin-bottom: 15px; cursor: grab; }
    .task-card:active { cursor: grabbing; }
    .task-card:hover { transform: translateY(-2px); border-color: var(--accent) !important; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
    .kanban-board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; align-items: start; }
    .kanban-column { background: rgba(0, 0, 0, 0.1); border: 2px solid var(--border-color); border-radius: 12px; padding: 15px; min-height: 500px; transition: border-color 0.2s, background 0.2s; }
    .kanban-column.drag-over { border: 2px dashed var(--accent); background: rgba(59, 130, 246, 0.05); }
    .kanban-header { font-size: 1.1em; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    
    /* Dedicated Modal Grid Classes for Perfect Mobile Stacking */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
    .form-group { display: flex; flex-direction: column; gap: 5px; }
    .form-group label { font-size: 0.8em; font-weight: 600; text-transform: uppercase; color: var(--text-muted); }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; }
    
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 15px; border-top: 1px solid var(--border-color); padding-top: 15px; }
    .modal-actions button { width: auto; }
    
    @media (max-width: 900px) { 
        .kanban-board { grid-template-columns: 1fr; } 
        .kanban-column { min-height: auto; } 
    }
    @media (max-width: 768px) {
        /* Force side-by-side grid columns to stack on mobile */
        .form-grid { grid-template-columns: 1fr; gap: 10px; }
        .modal-actions { flex-direction: column; }
        .modal-actions button { width: 100%; text-align: center; margin: 0; }
    }
</style>

<div class="page-header">
    <h2>Tasks & Plans</h2>
    <button onclick="openTaskModal()" class="btn">+ New Task</button>
</div>

<?php if ($message): ?>
    <div style="padding: 15px; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-radius: 8px; margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 25px; align-items: center;">
    <span style="color: var(--text-muted); font-size: 0.85em; text-transform: uppercase;">Filter:</span>
    <a href="tasks.php" class="tag-pill" style="<?= (!$filter_cat) ? 'background: var(--accent); color: white;' : 'background: transparent; border-color: var(--border-color); color: var(--text-muted);' ?>">All Categories</a>
    <?php foreach ($categories as $cat): ?>
        <a href="tasks.php?category=<?= $cat['id'] ?>" class="tag-pill" style="<?= ($filter_cat == $cat['id']) ? 'background: ' . htmlspecialchars($cat['color']) . '; color: white; border-color: ' . htmlspecialchars($cat['color']) . ';' : 'background: transparent; border-color: var(--border-color); color: var(--text-muted);' ?>"><?= htmlspecialchars($cat['name']) ?></a>
    <?php endforeach; ?>
</div>

<!-- Hidden form for drag and drop status updates -->
<form id="drag-drop-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="task_id" id="dd-task-id">
    <input type="hidden" name="new_status" id="dd-new-status">
</form>

<div class="kanban-board">
    
    <!-- PENDING -->
    <div class="kanban-column" id="col-open" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropTask(event, 'open')">
        <div class="kanban-header" style="color: var(--text-main);">
            <span>📝 Pending</span>
            <span class="tag-pill" style="background: var(--bg-color); color: var(--text-muted); border: 1px solid var(--border-color);"><?= count($open_tasks) ?></span>
        </div>
        <?php foreach ($open_tasks as $task): $cat_color = $task['category_color'] ?: '#3b82f6'; ?>
            <div class="card task-card" draggable="true" ondragstart="dragStart(event, <?= $task['id'] ?>)" style="display: flex; flex-direction: column; border-left: 4px solid <?= htmlspecialchars($cat_color) ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span class="tag-pill" style="border-color: <?= htmlspecialchars($cat_color) ?>; color: <?= htmlspecialchars($cat_color) ?>;"><?= htmlspecialchars($task['category_name'] ?: 'General') ?></span>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <?php if (!empty($task['reminder_date']) && !$task['is_reminded']): ?><span title="Reminder Set" style="font-size: 0.85em; color: var(--warning);">🔔</span><?php endif; ?>
                        <?php if (!empty($task['due_date'])): ?><span style="font-size: 0.75em; color: <?= (strtotime($task['due_date']) < time()) ? 'var(--danger)' : 'var(--text-muted)' ?>;">📅 <?= date('M j', strtotime($task['due_date'])) ?></span><?php endif; ?>
                    </div>
                </div>
                <h4 style="margin: 0 0 8px 0; font-size: 1.1em; color: var(--text-main);"><?= htmlspecialchars($task['title']) ?></h4>
                <?php if (!empty($task['description'])): ?><p style="font-size: 0.85em; color: var(--text-muted); line-height: 1.4; margin: 0 0 15px 0; flex-grow: 1; white-space: pre-line;"><?= htmlspecialchars($task['description']) ?></p><?php endif; ?>
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--border-color); padding-top: 10px; margin-top: auto;">
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><input type="hidden" name="new_status" value="in_progress">
                        <button type="submit" class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);">Start →</button>
                    </form>
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" onclick="openTaskModal(<?= $task['id'] ?>)">✎</button>
                        <form method="POST" onsubmit="return confirm('Delete task?');" style="margin: 0;"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><button type="submit" class="btn btn-small btn-danger">✕</button></form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- IN PROGRESS -->
    <div class="kanban-column" id="col-inprogress" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropTask(event, 'in_progress')">
        <div class="kanban-header" style="color: var(--accent); border-bottom-color: var(--accent);">
            <span>🚀 In Progress</span>
            <span class="tag-pill" style="background: rgba(59, 130, 246, 0.1); color: var(--accent); border: 1px solid var(--accent);"><?= count($inprogress_tasks) ?></span>
        </div>
        <?php foreach ($inprogress_tasks as $task): $cat_color = $task['category_color'] ?: '#3b82f6'; ?>
            <div class="card task-card" draggable="true" ondragstart="dragStart(event, <?= $task['id'] ?>)" style="display: flex; flex-direction: column; border-left: 4px solid <?= htmlspecialchars($cat_color) ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span class="tag-pill" style="border-color: <?= htmlspecialchars($cat_color) ?>; color: <?= htmlspecialchars($cat_color) ?>;"><?= htmlspecialchars($task['category_name'] ?: 'General') ?></span>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <?php if (!empty($task['reminder_date']) && !$task['is_reminded']): ?><span title="Reminder Set" style="font-size: 0.85em; color: var(--warning);">🔔</span><?php endif; ?>
                        <?php if (!empty($task['due_date'])): ?><span style="font-size: 0.75em; color: <?= (strtotime($task['due_date']) < time()) ? 'var(--danger)' : 'var(--text-muted)' ?>;">📅 <?= date('M j', strtotime($task['due_date'])) ?></span><?php endif; ?>
                    </div>
                </div>
                <h4 style="margin: 0 0 8px 0; font-size: 1.1em; color: var(--text-main);"><?= htmlspecialchars($task['title']) ?></h4>
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--border-color); padding-top: 10px; margin-top: auto;">
                    <form method="POST" style="margin: 0;"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><input type="hidden" name="new_status" value="completed"><button type="submit" class="btn btn-small" style="background: var(--success); color: white; border: none;">✓ Complete</button></form>
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" onclick="openTaskModal(<?= $task['id'] ?>)">✎</button>
                        <form method="POST" onsubmit="return confirm('Delete task?');" style="margin: 0;"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><button type="submit" class="btn btn-small btn-danger">✕</button></form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- COMPLETED -->
    <div class="kanban-column" id="col-completed" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropTask(event, 'completed')" style="opacity: 0.85;">
        <div class="kanban-header" style="color: var(--success); border-bottom-color: var(--success);">
            <span>✅ Completed</span>
            <span class="tag-pill" style="background: rgba(34, 197, 94, 0.1); color: var(--success); border: 1px solid var(--success);"><?= count($completed_tasks) ?></span>
        </div>
        <?php foreach ($completed_tasks as $task): ?>
            <div class="card task-card" draggable="true" ondragstart="dragStart(event, <?= $task['id'] ?>)" style="display: flex; flex-direction: column; border-left: 4px solid var(--success);">
                <span class="tag-pill" style="border-color: var(--border-color); color: var(--text-muted); align-self: flex-start; margin-bottom: 10px;"><?= htmlspecialchars($task['category_name'] ?: 'General') ?></span>
                <h4 style="margin: 0 0 8px 0; font-size: 1.1em; color: var(--text-muted); text-decoration: line-through;"><?= htmlspecialchars($task['title']) ?></h4>
                <?php if (!empty($task['completed_at'])): ?><div style="font-size: 0.75em; color: var(--success); margin-bottom: 10px; font-weight: 500;">🕒 Finished <?= date('M j \a\t g:i A', strtotime($task['completed_at'])) ?></div><?php endif; ?>
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--border-color); padding-top: 10px; margin-top: auto;">
                    <form method="POST" style="margin: 0;"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><input type="hidden" name="new_status" value="open"><button type="submit" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);">↩ Reopen</button></form>
                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn btn-small" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" onclick="openTaskModal(<?= $task['id'] ?>)">✎</button>
                        <form method="POST" onsubmit="return confirm('Delete task?');" style="margin: 0;"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><button type="submit" class="btn btn-small btn-danger">✕</button></form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div id="task-modal-overlay" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title">Create New Task</h3>
            <button type="button" class="close-modal" onclick="closeTaskModal()">✕</button>
        </div>
        <form method="POST" id="task-form" style="margin: 0;">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="task_id" id="editor-task-id" value="">

            <div class="form-group" style="margin-bottom: 15px;">
                <label>Task Title</label>
                <input type="text" name="title" id="editor-title" required placeholder="e.g., Deploy Timesheet v2 Plugin">
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" id="editor-category">
                        <option value="">-- General / None --</option>
                        <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="editor-status">
                        <option value="open">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date" id="editor-due-date">
                </div>
                <div class="form-group">
                    <label style="color: var(--warning);">🔔 Reminder Date/Time</label>
                    <input type="datetime-local" name="reminder_date" id="editor-reminder-date" style="border-color: var(--warning);">
                </div>
            </div>

            <div class="form-group">
                <label>Description / Notes</label>
                <textarea name="description" id="editor-description" rows="4"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border-color);" onclick="closeTaskModal()">Cancel</button>
                <button type="submit" class="btn" id="submit-btn">Save Task</button>
            </div>
        </form>
    </div>
</div>

<script>
    function dragStart(ev, taskId) { ev.dataTransfer.setData("taskId", taskId); }
    function allowDrop(ev) { ev.preventDefault(); if (ev.currentTarget.classList.contains('kanban-column')) { ev.currentTarget.classList.add('drag-over'); } }
    function leaveDrop(ev) { if (ev.currentTarget.classList.contains('kanban-column')) { ev.currentTarget.classList.remove('drag-over'); } }
    function dropTask(ev, newStatus) {
        ev.preventDefault();
        if (ev.currentTarget.classList.contains('kanban-column')) { ev.currentTarget.classList.remove('drag-over'); }
        const taskId = ev.dataTransfer.getData("taskId");
        if (taskId && newStatus) {
            document.getElementById('dd-task-id').value = taskId;
            document.getElementById('dd-new-status').value = newStatus;
            document.getElementById('drag-drop-form').submit();
        }
    }

    const allTasks = <?= $tasks_json ?: '[]' ?>;
    function openTaskModal(id = null) {
        document.getElementById('task-modal-overlay').style.display = 'flex';
        if (id) {
            const task = allTasks.find(t => t.id == id);
            if (task) {
                document.getElementById('editor-task-id').value = task.id;
                document.getElementById('editor-title').value = task.title;
                document.getElementById('editor-category').value = task.task_category || '';
                document.getElementById('editor-due-date').value = task.due_date ? task.due_date.substring(0, 10) : '';
                document.getElementById('editor-reminder-date').value = task.reminder_date ? task.reminder_date.replace(' ', 'T').substring(0, 16) : '';
                const status = task.status ? task.status.toLowerCase() : 'open';
                document.getElementById('editor-status').value = ['in_progress', 'completed'].includes(status) ? status : 'open';
                document.getElementById('editor-description').value = task.description || '';
                document.getElementById('modal-title').innerText = 'Edit Task';
                document.getElementById('submit-btn').innerText = 'Update Task';
            }
        } else {
            document.getElementById('task-form').reset();
            document.getElementById('editor-task-id').value = '';
            document.getElementById('modal-title').innerText = 'Create New Task';
            document.getElementById('submit-btn').innerText = 'Save Task';
        }
    }
    function closeTaskModal() { document.getElementById('task-modal-overlay').style.display = 'none'; }
</script>

<?php include 'includes/footer.php'; ?>