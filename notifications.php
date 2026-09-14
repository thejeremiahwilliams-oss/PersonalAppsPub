<?php
// public/notifications.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();

// Handle GET Actions (like clicking "Mark all read" from the header dropdown bell)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        header("Location: notifications.php");
        exit();
    }
}

// Handle POST Actions (from buttons on the actual notification page)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read') {
        $notif_id = (int)$_POST['notification_id'];
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $notif_id, ':uid' => $user_id]);
    } elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
    } elseif ($action === 'clear_all') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
    }
    
    header("Location: notifications.php");
    exit();
}

// Fetch all notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute([':uid' => $user_id]);
$notifications = $stmt->fetchAll();

$page_title = "Notifications - PersonalApps";
include 'includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <h2 style="margin: 0;">Notification Center</h2>
    <div style="display: flex; gap: 10px;">
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-small">Mark All Read</button>
        </form>
        <form method="POST" onsubmit="return confirm('Clear all notifications?');" style="margin: 0;">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="btn btn-small btn-danger">Clear All</button>
        </form>
    </div>
</div>

<?php if (empty($notifications)): ?>
    <div class="card">
        <p style="color: var(--text-muted); margin: 0; font-style: italic;">No notifications found.</p>
    </div>
<?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 15px;">
        <?php foreach ($notifications as $n): ?>
            <div class="card" style="margin-bottom: 0; border-left: 4px solid <?= $n['is_read'] ? 'var(--border-color)' : 'var(--accent)' ?>; background: <?= $n['is_read'] ? 'var(--card-bg)' : 'rgba(59, 130, 246, 0.05)' ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <h4 style="margin: 0; font-size: 1.1em; color: var(--text-main);"><?= htmlspecialchars($n['title']) ?></h4>
                    <span style="font-size: 0.8em; color: var(--text-muted);"><?= date('F j, Y, g:i A', strtotime($n['created_at'])) ?></span>
                </div>
                <p style="margin: 0 0 15px 0; color: var(--text-muted); font-size: 0.95em; word-wrap: break-word;"><?= htmlspecialchars($n['message']) ?></p>
                
                <?php if (!$n['is_read']): ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn btn-small" style="background: transparent; border: 1px solid var(--border-color); color: var(--text-main);">Mark as Read</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>