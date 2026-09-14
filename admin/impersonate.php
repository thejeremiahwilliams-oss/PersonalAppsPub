<?php
// public/admin/impersonate.php
require_once '../includes/auth_functions.php';
require_once '../config/database.php';
require_once '../includes/audit_logger.php';

require_login();

// Verify current user is an admin (or currently an admin who is already impersonating)
$is_original_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$is_impersonating = isset($_SESSION['impersonator_id']);

if (!$is_original_admin && !$is_impersonating) {
    die("Unauthorized access.");
}

$db = new Database();
$pdo = $db->getConnection();

$action = $_GET['action'] ?? '';

if ($action === 'stop') {
    // Stop impersonating and restore original admin session
    if (isset($_SESSION['impersonator_id'])) {
        $admin_id = $_SESSION['impersonator_id'];
        
        $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id = :id");
        $stmt->execute([':id' => $admin_id]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['email'] = $admin['email'];
            $_SESSION['role'] = $admin['role'];
            unset($_SESSION['impersonator_id']);
            
            log_audit_action($pdo, $admin['id'], 'STOP_IMPERSONATION', 'Admin', []);
        }
    }
    header("Location: users.php");
    exit();
}

// Start Impersonation of a Target User
$target_user_id = (int)($_GET['user_id'] ?? 0);
if ($target_user_id > 0) {
    // If already impersonating, use the original admin ID as the root impersonator
    $root_admin_id = $_SESSION['impersonator_id'] ?? $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE id = :id");
    $stmt->execute([':id' => $target_user_id]);
    $target_user = $stmt->fetch();
    
    if ($target_user) {
        // Switch session context to target user
        $_SESSION['user_id'] = $target_user['id'];
        $_SESSION['email'] = $target_user['email'];
        $_SESSION['role'] = $target_user['role']; // Keeps their actual role or lets admin view as them
        $_SESSION['impersonator_id'] = $root_admin_id;
        
        log_audit_action($pdo, $root_admin_id, 'START_IMPERSONATION', 'Admin', ['target_user_id' => $target_user_id]);
        
        header("Location: ../index.php");
        exit();
    }
}

header("Location: users.php");
exit();