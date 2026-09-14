<?php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = trim($_POST['command'] ?? '');
    $user_id = $_SESSION['user_id'];
    $db = new Database(); $pdo = $db->getConnection();

    if (strpos($cmd, '/task ') === 0) {
        $title = trim(substr($cmd, 6));
        if (!empty($title)) {
            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, title, task_category, status) VALUES (:uid, :title, 'daily', 'open')");
            $stmt->execute([':uid' => $user_id, ':title' => $title]);
            log_audit_action($pdo, $user_id, 'CMD_CREATE_TASK', 'Tasks', ['title' => $title]);
        }
    } elseif (strpos($cmd, '/note ') === 0) {
        $content = trim(substr($cmd, 6));
        if (!empty($content)) {
            $stmt = $pdo->prepare("INSERT INTO notes (user_id, content) VALUES (:uid, :content)");
            $stmt->execute([':uid' => $user_id, ':content' => $content]);
            log_audit_action($pdo, $user_id, 'CMD_CREATE_NOTE', 'Notes');
        }
    }
}
header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit();