<?php
// public/admin/logs.php
require_once '../includes/auth_functions.php';
require_once '../config/database.php';

require_admin();
$db = new Database(); $pdo = $db->getConnection();

$sql = "SELECT l.id, l.action_type, l.module, l.details, l.ip_address, l.created_at, u.email 
        FROM audit_logs l LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.created_at DESC LIMIT 200";
$stmt = $pdo->query($sql);
$logs = $stmt->fetchAll();

$page_title = "Audit Logs - Admin";
include '../../includes/header.php';
?>

<div style="margin-bottom: 20px;">
    <a href="users.php" class="btn btn-small" style="background: var(--border-color); color: var(--text-main);">Switch to User Management</a>
</div>

<h2 style="margin-top: 0;">System Audit Logs</h2>
<p style="color: var(--text-muted);">Showing the most recent 200 actions.</p>

<div class="card responsive-table">
    <table>
        <thead>
            <tr>
                <th>Timestamp</th><th>User</th><th>IP Address</th><th>Module</th><th>Action</th><th>Details (JSON)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td data-label="Timestamp" style="font-size: 0.9em;"><?= $log['created_at'] ?></td>
                <td data-label="User"><?= $log['email'] ? htmlspecialchars($log['email']) : '<i>System</i>' ?></td>
                <td data-label="IP Address" style="font-size: 0.9em; color: var(--text-muted);"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></td>
                <td data-label="Module"><?= htmlspecialchars($log['module']) ?></td>
                <td data-label="Action"><strong><?= htmlspecialchars($log['action_type']) ?></strong></td>
                <td data-label="Details" style="font-family: monospace; font-size: 0.85em; max-width: 300px; word-wrap: break-word; color: var(--text-muted);">
                    <?= htmlspecialchars($log['details'] ?? 'None') ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>