<?php
// admin/users.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth_functions.php';
require_once '../config/database.php';
require_once '../includes/audit_logger.php';

require_login();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access. Administrator privileges required.");
}

$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();
$message = '';
$message_type = 'success'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target_user_id = (int)($_POST['user_id'] ?? 0);

    if ($action === 'delete_user') {
        if ($target_user_id === $_SESSION['user_id']) {
            $message = "Safety Block: You cannot delete your own account.";
            $message_type = "danger";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM tasks WHERE user_id = :id")->execute([':id' => $target_user_id]);
                $pdo->prepare("DELETE FROM notes WHERE user_id = :id")->execute([':id' => $target_user_id]);
                $pdo->prepare("DELETE FROM meetings WHERE user_id = :id")->execute([':id' => $target_user_id]);
                try {
                    $pdo->prepare("UPDATE kb_articles SET author_id = :my_id WHERE author_id = :target_id")
                        ->execute([':my_id' => $_SESSION['user_id'], ':target_id' => $target_user_id]);
                } catch(Exception $e) {}
                $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $target_user_id]);
                $pdo->commit();
                $message = "User and all personal data successfully removed.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Database Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } elseif ($action === 'update_role') {
        $new_role = $_POST['role'];
        if ($target_user_id === $_SESSION['user_id'] && $new_role !== 'admin') {
            $message = "Safety Block: You cannot remove your own admin privileges.";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
            $stmt->execute([':role' => $new_role, ':id' => $target_user_id]);
            $message = "User role updated successfully.";
        }
    } elseif ($action === 'toggle_approval') {
        $current_status = strtolower($_POST['current_status']);
        $new_status = ($current_status === 'active') ? 'suspended' : 'active';
        
        if ($target_user_id === $_SESSION['user_id'] && $new_status === 'suspended') {
            $message = "Safety Block: You cannot suspend your own account.";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $new_status, ':id' => $target_user_id]);
            $message = ($new_status === 'active') ? "User account activated!" : "User account suspended.";
        }
    } elseif ($action === 'send_reset') {
        require_once '../includes/mailer.php';
        
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
        $stmt->execute([':id' => $target_user_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours')); 
            
            $update = $pdo->prepare("UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id");
            $update->execute([':token' => $token, ':expires' => $expires, ':id' => $target_user_id]);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $base_dir = str_replace('/admin', '', dirname($_SERVER['PHP_SELF']));
            $reset_link = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . "/reset_password.php?token=" . $token;
            
            $subject = "[PersonalApps] Admin Password Reset Request";
            $html_message = "
                <div style='font-family: sans-serif; padding: 20px; background: #0f172a; color: #f8fafc; border-radius: 8px;'>
                    <h3 style='color: #3b82f6;'>Password Reset</h3>
                    <p>An administrator has requested a password reset for your account.</p>
                    <p>Click the button below to choose a new password. This link expires in 24 hours.</p>
                   <a href='{$reset_link}' style='display: inline-block; padding: 10px 20px; background: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 15px 0;'>Reset Password</a>
               </div>
            ";
            
            if (send_memo_email($user['email'], $subject, $html_message)) {
                $message = "Password reset link sent securely to " . htmlspecialchars($user['email']) . ".";
                $message_type = "success";
            } else {
                $message = "ERROR: Failed to send the email. Check your SMTP credentials in includes/mailer.php.";
                $message_type = "danger";
            }
        } else {
            $message = "ERROR: Could not find that user ID in the database.";
            $message_type = "danger";
        }
    } elseif (isset($_POST['save_category'])) {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        $cat_name = trim($_POST['category_name'] ?? '');
        $cat_color = trim($_POST['category_color'] ?? '#3b82f6');

        if (!empty($cat_name)) {
            if ($cat_id > 0) {
                $stmt = $pdo->prepare("UPDATE task_categories SET name = :name, color = :color WHERE id = :id");
                $stmt->execute([':name' => $cat_name, ':color' => $cat_color, ':id' => $cat_id]);
                log_audit_action($pdo, $user_id, 'UPDATE_TASK_CATEGORY', 'Admin', ['category_id' => $cat_id, 'name' => $cat_name]);
                $message = "Task category updated successfully.";
                $message_type = "success";
            } else {
                $stmt = $pdo->prepare("INSERT INTO task_categories (name, color) VALUES (:name, :color)");
                $stmt->execute([':name' => $cat_name, ':color' => $cat_color]);
                log_audit_action($pdo, $user_id, 'CREATE_TASK_CATEGORY', 'Admin', ['name' => $cat_name]);
                $message = "New task category added successfully.";
                $message_type = "success";
            }
        } else {
            $message = "Category name cannot be empty.";
            $message_type = "danger";
        }
    } elseif (isset($_POST['delete_category'])) {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        if ($cat_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM task_categories WHERE id = :id");
            $stmt->execute([':id' => $cat_id]);
            log_audit_action($pdo, $user_id, 'DELETE_TASK_CATEGORY', 'Admin', ['category_id' => $cat_id]);
            $message = "Task category deleted successfully.";
            $message_type = "success";
        }
    }
}

// Fetch all registered users
$stmt = $pdo->query("SELECT id, email, role, status, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

// Fetch all task categories for display
try {
    $categories = $pdo->query("SELECT * FROM task_categories ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $categories = [];
}

// Fetch recent System Audit Logs safely
try {
    $logs_stmt = $pdo->query("SELECT l.*, u.email FROM audit_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 50");
    $audit_logs = $logs_stmt->fetchAll();
} catch (Exception $e) {
    $audit_logs = [];
}

$page_title = "Admin Panel - PersonalApps";
include '../includes/header.php';
?>

<style>
    input, select, textarea {
        color: var(--text-main) !important;
        background-color: var(--bg-color) !important;
    }
    input::placeholder, textarea::placeholder {
        color: var(--text-muted) !important;
        opacity: 0.7;
    }

    /* Mobile Responsive Tables to Cards */
    @media (max-width: 850px) {
        table, thead, tbody, th, td, tr { display: block; width: 100% !important; }
        thead tr { position: absolute; top: -9999px; left: -9999px; }
        tr { margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-color); padding: 10px; }
        td { display: flex; justify-content: space-between; align-items: center; border: none; border-bottom: 1px dashed var(--border-color); padding: 12px 10px !important; text-align: right; }
        td:last-child { border-bottom: none; flex-wrap: wrap; justify-content: flex-end; gap: 8px; padding-top: 15px !important; }
        td::before { content: attr(data-label); font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 0.8em; text-align: left; margin-right: 15px; flex-shrink: 0; }
        
        /* Overrides for long text columns to prevent squishing */
        .col-stack { flex-direction: column; align-items: flex-start; text-align: left; }
        .col-stack::before { margin-bottom: 8px; }
        .col-stack span, .col-stack div { width: 100%; word-break: break-word; }
        
        .cat-color-cell { justify-content: flex-end; }
        .cat-color-cell::before { margin-right: auto; }
    }
</style>

<div class="page-header">
    <h2>User Management & Admin Center</h2>
</div>

<?php if ($message): ?>
    <div style="padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.1)' : 'rgba(239, 68, 68, 0.1)' ?>; color: <?= $message_type === 'success' ? 'var(--success)' : 'var(--danger)' ?>; border: 1px solid <?= $message_type === 'success' ? 'var(--success)' : 'var(--danger)' ?>;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="overflow-x: auto; margin-bottom: 25px;">
    <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; color: var(--accent);">Registered Users</h3>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="text-align: left;">Email Address</th>
                <th style="text-align: left;">Joined Date</th>
                <th style="text-align: left;">Status</th>
                <th style="text-align: left;">Role</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <?php $current_status = strtolower($u['status'] ?? 'pending'); ?>
                <tr style="border-bottom: 1px dashed var(--border-color);">
                    <td data-label="Email Address" style="padding: 15px; color: var(--text-main);">
                        <strong><?= htmlspecialchars($u['email']) ?></strong>
                        <?php if($u['id'] === $_SESSION['user_id']): ?>
                            <span class="tag-pill" style="margin-left: 10px;">You</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Joined Date" style="padding: 15px; color: var(--text-muted); font-size: 0.9em;">
                        <?= date('M j, Y', strtotime($u['created_at'])) ?>
                    </td>
                    <td data-label="Status" style="padding: 15px;">
                        <?php if ($current_status === 'active'): ?>
                            <span style="background: rgba(34, 197, 94, 0.1); color: var(--success); padding: 4px 10px; border-radius: 20px; font-size: 0.85em; font-weight: bold; border: 1px solid var(--success);">Active</span>
                        <?php elseif ($current_status === 'suspended'): ?>
                            <span style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 4px 10px; border-radius: 20px; font-size: 0.85em; font-weight: bold; border: 1px solid var(--danger);">Suspended</span>
                        <?php else: ?>
                            <span style="background: rgba(234, 179, 8, 0.1); color: var(--warning); padding: 4px 10px; border-radius: 20px; font-size: 0.85em; font-weight: bold; border: 1px solid var(--warning);">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Role" style="padding: 15px;">
                        <form method="POST" style="margin: 0; display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="role" onchange="this.form.submit()" style="margin: 0; padding: 6px 10px; width: 110px; font-size: 0.9em; <?= $u['role'] === 'admin' ? 'border-color: var(--danger); color: var(--danger);' : '' ?>">
                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="editor" <?= $u['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </form>
                    </td>
                    <td data-label="Actions" style="padding: 15px;">
                        <div style="display: flex; justify-content: flex-end; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <?php if($u['id'] !== $_SESSION['user_id']): ?>
                                <form method="GET" action="impersonate.php" style="margin: 0;">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-small" style="background: transparent; color: var(--warning); border: 1px solid var(--warning);" title="Login as this user">Login As</button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="toggle_approval">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="current_status" value="<?= htmlspecialchars($current_status) ?>">
                                <?php if ($current_status === 'active'): ?>
                                    <button type="submit" class="btn btn-small" style="background: transparent; color: var(--warning); border: 1px solid var(--warning);" <?= $u['id'] === $_SESSION['user_id'] ? 'disabled' : '' ?>>Suspend</button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-small" style="background: var(--success); color: white; border: none;">Approve</button>
                                <?php endif; ?>
                            </form>

                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="send_reset">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);">Reset</button>
                            </form>
                            
                            <?php if($u['id'] !== $_SESSION['user_id']): ?>
                                <form method="POST" onsubmit="return confirm('WARNING: This will permanently delete this user and all personal data. Proceed?');" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Task Categories Management -->
<div class="card" style="margin-bottom: 25px;">
    <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; color: var(--accent);">Task Categories Management</h3>
    <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 20px;">Manage custom color-coded task categories globally across PersonalApps.</p>
    
    <form method="POST" id="categoryForm" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 25px; background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); flex-wrap: wrap;">
        <input type="hidden" name="save_category" value="1">
        <input type="hidden" name="category_id" id="categoryId" value="">
        
        <div style="flex: 1; min-width: 200px; margin: 0;">
            <label style="display: block; font-size: 0.85em; margin-bottom: 5px; color: var(--text-muted);">Category Name</label>
            <input type="text" name="category_name" id="categoryName" placeholder="e.g. Marketing, Development..." required style="width: 100%; margin: 0;">
        </div>
        
        <div style="width: 100px; margin: 0;">
            <label style="display: block; font-size: 0.85em; margin-bottom: 5px; color: var(--text-muted);">Color Tag</label>
            <input type="color" name="category_color" id="categoryColor" value="#3b82f6" style="width: 100%; height: 38px; padding: 2px; background: transparent; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer;">
        </div>
        
        <div style="margin: 0; display: flex;">
            <button type="submit" class="btn btn-small" id="submitBtn" style="background: var(--accent); color: white; height: 38px;">Add</button>
            <button type="button" class="btn btn-small" id="cancelEdit" onclick="resetForm()" style="display: none; background: transparent; border: 1px solid var(--border-color); color: var(--text-muted); height: 38px; margin-left: 5px;">Cancel</button>
        </div>
    </form>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: 10px;">Color</th>
                    <th style="text-align: left; padding: 10px;">Category Name</th>
                    <th style="text-align: right; padding: 10px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 20px; color: var(--text-muted);">No task categories found. Create the first category above!</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr style="border-bottom: 1px dashed var(--border-color);">
                            <td data-label="Color" class="cat-color-cell" style="padding: 12px; width: 60px;">
                                <span style="display: inline-block; width: 20px; height: 20px; background: <?= htmlspecialchars($cat['color']) ?>; border-radius: 50%; vertical-align: middle;"></span>
                            </td>
                            <td data-label="Category Name" style="padding: 12px; color: var(--text-main);">
                                <strong><?= htmlspecialchars($cat['name']) ?></strong>
                            </td>
                            <td data-label="Actions" style="padding: 12px; text-align: right;">
                                <button type="button" class="btn btn-small" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cat['color']) ?>')" style="background: transparent; color: var(--accent); border: 1px solid var(--accent); margin-right: 5px;">Edit</button>
                                
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this task category?');" style="display: inline; margin: 0;">
                                    <input type="hidden" name="delete_category" value="1">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- System Audit Logs -->
<div class="card">
    <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; color: var(--accent);">System Audit Logs</h3>
    <p style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 20px;">Recent security and operational actions recorded across PersonalApps.</p>

    <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color); position: sticky; top: 0; background: var(--card-bg);">
                    <th style="padding: 10px;">Timestamp</th>
                    <th style="padding: 10px;">User</th>
                    <th style="padding: 10px;">Action</th>
                    <th style="padding: 10px;">Module</th>
                    <th style="padding: 10px;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($audit_logs)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 20px; color: var(--text-muted);">No audit logs recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($audit_logs as $log): 
                        $log_time = $log['created_at'] ?? $log['timestamp'] ?? date('Y-m-d H:i:s');
                        $log_email = $log['email'] ?? ('User #' . ($log['user_id'] ?? 0));
                        $log_action = $log['action'] ?? $log['action_type'] ?? $log['event_type'] ?? 'UNKNOWN_ACTION';
                        $log_module = $log['module'] ?? $log['category'] ?? 'General';
                        $log_details = $log['details'] ?? $log['description'] ?? '';
                    ?>
                        <tr style="border-bottom: 1px dashed var(--border-color); font-size: 0.9em;">
                            <td data-label="Timestamp" style="padding: 10px; color: var(--text-muted); white-space: nowrap;"><?= htmlspecialchars($log_time) ?></td>
                            <td data-label="User" style="padding: 10px; color: var(--text-main);"><strong><?= htmlspecialchars($log_email) ?></strong></td>
                            <td data-label="Action" style="padding: 10px;"><span class="tag-pill"><?= htmlspecialchars($log_action) ?></span></td>
                            <td data-label="Module" style="padding: 10px; color: var(--text-muted);"><?= htmlspecialchars($log_module) ?></td>
                            <td data-label="Details" class="col-stack" style="padding: 10px; color: var(--text-muted); font-family: monospace; font-size: 0.85em;"><span><?= htmlspecialchars($log_details) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function editCategory(id, name, color) {
        document.getElementById('categoryId').value = id;
        document.getElementById('categoryName').value = name;
        document.getElementById('categoryColor').value = color;
        document.getElementById('submitBtn').innerText = 'Update';
        document.getElementById('cancelEdit').style.display = 'inline-block';
        document.getElementById('categoryName').focus();
    }

    function resetForm() {
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryName').value = '';
        document.getElementById('categoryColor').value = '#3b82f6';
        document.getElementById('submitBtn').innerText = 'Add';
        document.getElementById('cancelEdit').style.display = 'none';
    }
</script>

<?php include '../includes/footer.php'; ?>