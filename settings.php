<?php
// public/settings.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database(); 
$pdo = $db->getConnection();
$message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Timezone Update
    if (isset($_POST['update_timezone'])) {
        $timezone = $_POST['timezone'] ?? 'America/Chicago';
        if (in_array($timezone, timezone_identifiers_list())) {
            $stmt = $pdo->prepare("UPDATE users SET timezone = :tz WHERE id = :id");
            $stmt->execute([':tz' => $timezone, ':id' => $user_id]);
            $_SESSION['timezone'] = $timezone;
            date_default_timezone_set($timezone);
            log_audit_action($pdo, $user_id, 'UPDATE_TIMEZONE', 'Settings', ['timezone' => $timezone]);
            $message = "Timezone updated.";
        }
    } 
    // Handle Email Settings Update
    elseif (isset($_POST['update_email_settings'])) {
        $notification_email = trim($_POST['notification_email'] ?? '');
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE users SET notification_email = :n_email, email_notifications = :e_notifs WHERE id = :id");
        $stmt->execute([':n_email' => $notification_email, ':e_notifs' => $email_notifications, ':id' => $user_id]);
        log_audit_action($pdo, $user_id, 'UPDATE_EMAIL', 'Settings', []);
        $message = "Email preferences saved.";
    } 
    // Handle Password Change
    elseif (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Fetch user's current password hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current_password, $user['password'])) {
            $error_message = "Your current password is incorrect.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "New password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match.";
        } else {
            // Hash and save the new password
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
            $stmt->execute([':password' => $hashed, ':id' => $user_id]);
            log_audit_action($pdo, $user_id, 'CHANGE_PASSWORD', 'Settings', []);
            $message = "Password updated successfully.";
        }
    }
    // Handle Revoking a Shared Link
    elseif (isset($_POST['revoke_link'])) {
        $revoke_id = (int)$_POST['revoke_id'];
        $revoke_type = $_POST['revoke_type'] ?? '';
        $table = '';
        
        if ($revoke_type === 'note') $table = 'notes';
        elseif ($revoke_type === 'kb') $table = 'kb_articles';
        elseif ($revoke_type === 'task') $table = 'tasks';
        elseif ($revoke_type === 'meeting') $table = 'meetings';
        elseif ($revoke_type === 'budget') $table = 'budget_plans';
        elseif ($revoke_type === 'ledger') $table = 'yearly_ledgers';
        
        if ($table) {
            try {
                // Determine if we need to verify user ownership
                $auth_check = ($table === 'kb_articles') ? "" : " AND user_id = :uid";
                $params = [':id' => $revoke_id];
                if ($auth_check) $params[':uid'] = $user_id;
                
                // Unset the token
                $stmt = $pdo->prepare("UPDATE {$table} SET share_token = NULL WHERE id = :id" . $auth_check);
                if ($stmt->execute($params)) {
                    $message = "Shared link successfully revoked.";
                    log_audit_action($pdo, $user_id, 'REVOKE_LINK', 'Settings', ['type' => $revoke_type, 'id' => $revoke_id]);
                }
            } catch (Exception $e) {
                $error_message = "Error revoking link.";
            }
        }
    }
}

// Fetch current settings for display
$stmt = $pdo->prepare("SELECT timezone, notification_email, email_notifications FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user_settings = $stmt->fetch();

$current_tz = $user_settings['timezone'] ?: 'America/Chicago';
$current_notif_email = $user_settings['notification_email'] ?? '';
$current_email_notifs = $user_settings['email_notifications'] ?? 0;

// Gather all active shared links across the different tables
$shared_links = [];
try {
    // Notes
    $stmt = $pdo->prepare("SELECT id, title, 'note' as type, share_token, COALESCE(view_count, 0) as view_count, created_at FROM notes WHERE user_id = :uid AND share_token IS NOT NULL AND share_token != ''");
    $stmt->execute([':uid' => $user_id]);
    $shared_links = array_merge($shared_links, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // KB Articles (Checking author_id)
    $stmt = $pdo->prepare("SELECT id, title, 'kb' as type, share_token, COALESCE(view_count, 0) as view_count, updated_at as created_at FROM kb_articles WHERE author_id = :uid AND share_token IS NOT NULL AND share_token != ''");
    $stmt->execute([':uid' => $user_id]);
    $shared_links = array_merge($shared_links, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // Tasks
    $stmt = $pdo->prepare("SELECT id, title, 'task' as type, share_token, COALESCE(view_count, 0) as view_count, created_at FROM tasks WHERE user_id = :uid AND share_token IS NOT NULL AND share_token != ''");
    $stmt->execute([':uid' => $user_id]);
    $shared_links = array_merge($shared_links, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Meetings
    $stmt = $pdo->prepare("SELECT id, title, 'meeting' as type, share_token, COALESCE(view_count, 0) as view_count, created_at FROM meetings WHERE user_id = :uid AND share_token IS NOT NULL AND share_token != ''");
    $stmt->execute([':uid' => $user_id]);
    $shared_links = array_merge($shared_links, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // Budget
    $stmt = $pdo->prepare("SELECT id, CONCAT('Budget Plan - ', budget_month, '/', budget_year) as title, 'budget' as type, share_token, COALESCE(view_count, 0) as view_count, updated_at as created_at FROM budget_plans WHERE user_id = :uid AND share_token IS NOT NULL AND share_token != ''");
    $stmt->execute([':uid' => $user_id]);
    $shared_links = array_merge($shared_links, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // Ledger
    $stmt = $pdo->prepare("SELECT id, CONCAT('Annual Ledger - ', ledger_year) as title, 'ledger' as type, share_token, COALESCE(view_count, 0) as view_count, updated_at as created_at FROM yearly_ledgers WHERE user_id = :uid AND share_token IS NOT NULL AND share_token != ''");
    $stmt->execute([':uid' => $user_id]);
    $shared_links = array_merge($shared_links, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // Sort all combined links by newest first
    usort($shared_links, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
} catch (Exception $e) {
    // Fails silently if a table doesn't have the share_token column yet
}

$page_title = "Settings - PersonalApps";
include 'includes/header.php';
?>

<style>
    /* Custom Tag formatting for Link Types */
    .type-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; text-transform: uppercase; border: 1px solid; }
    .badge-note { background: rgba(168, 85, 247, 0.1); color: #a855f7; border-color: #a855f7; }
    .badge-kb { background: rgba(34, 197, 94, 0.1); color: var(--success); border-color: var(--success); }
    .badge-task { background: rgba(234, 179, 8, 0.1); color: var(--warning); border-color: var(--warning); }
    .badge-meeting { background: rgba(59, 130, 246, 0.1); color: var(--accent); border-color: var(--accent); }
    .badge-budget { background: rgba(16, 185, 129, 0.1); color: #10b981; border-color: #10b981; }
    .badge-ledger { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border-color: #f59e0b; }
    
    .share-link-url { color: var(--accent); text-decoration: none; word-break: break-all; font-weight: 500; }
    .share-link-url:hover { text-decoration: underline; }
    
    .view-count { display: inline-flex; align-items: center; gap: 4px; background: var(--bg-color); border: 1px solid var(--border-color); padding: 3px 8px; border-radius: 20px; font-size: 0.8em; color: var(--text-muted); font-weight: bold; }

    /* Responsive Table Settings */
    @media (max-width: 768px) {
        .mobile-card-table { width: 100%; }
        .mobile-card-table thead { display: none; }
        .mobile-card-table tbody tr { display: block; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-color); padding: 5px 15px; }
        .mobile-card-table tbody td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed var(--border-color); padding: 12px 0; text-align: right; }
        .mobile-card-table tbody td:last-child { border-bottom: none; }
        .mobile-card-table tbody td::before { content: attr(data-label); font-weight: bold; color: var(--text-muted); font-size: 0.85em; text-transform: uppercase; margin-right: 15px; text-align: left; }
    }
</style>

<div class="page-header">
    <h2>System Settings</h2>
</div>

<div style="max-width: 850px; display: flex; flex-direction: column; gap: 20px;">
    
    <!-- Success Message -->
    <?php if ($message): ?>
        <div style="padding: 15px; background: rgba(34, 197, 94, 0.1); color: var(--success); border-radius: 8px; border: 1px solid var(--success);">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if ($error_message): ?>
        <div style="padding: 15px; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-radius: 8px; border: 1px solid var(--danger);">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- Appearance & Localization -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Appearance & Localization</h3>
        
        <label style="font-weight: 600; font-size: 0.9em; display: block; margin-bottom: 10px;">Color Theme</label>
        <div style="display: flex; gap: 10px; margin-bottom: 25px;">
            <button type="button" id="theme-btn-dark" onclick="setTheme('dark')" class="btn btn-small" style="background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color);">Dark</button>
            <button type="button" id="theme-btn-light" onclick="setTheme('light')" class="btn btn-small" style="background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color);">Light</button>
        </div>

        <form method="POST">
            <input type="hidden" name="update_timezone" value="1">
            <label style="font-weight: 600; font-size: 0.9em; display: block; margin-bottom: 5px;">Timezone</label>
            <select name="timezone" style="max-width: 300px; width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color);">
                <?php foreach (timezone_identifiers_list() as $tz) {
                    $sel = ($tz === $current_tz) ? 'selected' : '';
                    echo "<option value=\"{$tz}\" {$sel}>{$tz}</option>";
                } ?>
            </select>
            <button type="submit" class="btn btn-small" style="display: block; margin-top: 10px;">Save Timezone</button>
        </form>
    </div>

    <!-- Security: Change Password -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Security</h3>
        
        <form method="POST">
            <input type="hidden" name="update_password" value="1">
            
            <label style="font-weight: 600; font-size: 0.9em; display: block; margin-bottom: 5px;">Current Password</label>
            <input type="password" name="current_password" required placeholder="Enter current password" style="max-width: 300px; width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color); margin-bottom: 15px;">
            
            <label style="font-weight: 600; font-size: 0.9em; display: block; margin-bottom: 5px;">New Password</label>
            <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters" style="max-width: 300px; width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color); margin-bottom: 15px;">
            
            <label style="font-weight: 600; font-size: 0.9em; display: block; margin-bottom: 5px;">Confirm New Password</label>
            <input type="password" name="confirm_password" required placeholder="Type new password again" style="max-width: 300px; width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color);">
            
            <button type="submit" class="btn btn-small" style="display: block; margin-top: 15px;">Update Password</button>
        </form>
    </div>

    <!-- Alerts & Notifications -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Alerts & Notifications</h3>
        <form method="POST">
            <input type="hidden" name="update_email_settings" value="1">
            <label style="font-weight: 600; font-size: 0.9em; display: block; margin-bottom: 5px;">Destination Email</label>
            <input type="email" name="notification_email" value="<?= htmlspecialchars($current_notif_email) ?>" placeholder="Alert email address..." style="max-width: 300px; width: 100%; padding: 10px; border-radius: 6px; background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border-color);">
            
            <label style="display: flex; align-items: center; gap: 10px; margin: 15px 0; cursor: pointer;">
                <input type="checkbox" name="email_notifications" value="1" <?= ($current_email_notifs == 1) ? 'checked' : '' ?> style="width: auto; margin: 0; transform: scale(1.2); accent-color: var(--accent);"> 
                Enable outbound email alerts for system events
            </label>
            <button type="submit" class="btn btn-small">Save Alerts</button>
        </form>
    </div>

    <!-- Active Shared Links -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px;">Active Shared Links</h3>
        
        <?php if (empty($shared_links)): ?>
            <p style="color: var(--text-muted); font-style: italic; text-align: center; padding: 20px;">You do not have any active shared links.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="mobile-card-table">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Item Title</th>
                            <th style="width: 15%;">Type</th>
                            <th style="width: 10%;">Views</th>
                            <th style="width: 25%;">Link</th>
                            <th style="width: 15%; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($shared_links as $link): 
                            $badge_class = 'badge-' . $link['type'];
                            
                            // Reconstruct the exact URL that api_share.php generated
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                            $full_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/shared.php?t=" . $link['share_token'] . "&type=" . $link['type'];
                        ?>
                        <tr>
                            <td data-label="Item Title" style="font-weight: 500;">
                                <?= htmlspecialchars($link['title'] ?: 'Untitled Item') ?>
                            </td>
                            <td data-label="Type">
                                <span class="type-badge <?= $badge_class ?>"><?= htmlspecialchars($link['type']) ?></span>
                            </td>
                            <td data-label="Views">
                                <span class="view-count" title="Number of times this link was opened">👁️ <?= (int)$link['view_count'] ?></span>
                            </td>
                            <td data-label="Link">
                                <a href="<?= $full_url ?>" target="_blank" class="share-link-url" title="Open Link">View ↗</a>
                            </td>
                            <td data-label="Action" style="text-align: right;">
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to revoke this link?\n\nAnyone with the URL will lose access immediately.');">
                                    <input type="hidden" name="revoke_link" value="1">
                                    <input type="hidden" name="revoke_id" value="<?= $link['id'] ?>">
                                    <input type="hidden" name="revoke_type" value="<?= $link['type'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Revoke</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Data Management -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Data Management</h3>
        <p style="font-size: 0.9em; color: var(--text-muted);">Export your system data to a compressed zip archive.</p>
        <form method="POST" action="export_backup.php">
            <div style="display: flex; gap: 15px; margin: 15px 0; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="export_notes" value="1" checked style="width:auto;margin:0;accent-color:var(--accent);"> Notes</label>
                <label style="display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="export_kb" value="1" checked style="width:auto;margin:0;accent-color:var(--accent);"> KB Articles</label>
                <label style="display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="export_tasks" value="1" style="width:auto;margin:0;accent-color:var(--accent);"> Tasks</label>
                <label style="display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="export_planner" value="1" style="width:auto;margin:0;accent-color:var(--accent);"> Daily Planner</label>
                <label style="display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="export_ledger" value="1" style="width:auto;margin:0;accent-color:var(--accent);"> Annual Ledger</label>
            </div>
            <button type="submit" class="btn btn-small" style="background: var(--border-color); color: var(--text-main);">Download Archive</button>
        </form>
    </div>

</div>

<script>
    function setTheme(mode) {
        if (mode === 'light') { document.documentElement.classList.add('light-theme'); localStorage.setItem('theme', 'light'); }
        else { document.documentElement.classList.remove('light-theme'); localStorage.setItem('theme', 'dark'); }
        updateThemeUI();
    }
    function updateThemeUI() {
        var current = localStorage.getItem('theme') || 'dark';
        document.getElementById('theme-btn-dark').style.borderColor = current === 'dark' ? 'var(--accent)' : 'var(--border-color)';
        document.getElementById('theme-btn-light').style.borderColor = current === 'light' ? 'var(--accent)' : 'var(--border-color)';
    }
    document.addEventListener('DOMContentLoaded', updateThemeUI);
</script>

<?php include 'includes/footer.php'; ?>