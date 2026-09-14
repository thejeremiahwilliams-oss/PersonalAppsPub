<?php
// public/logout.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

// Log the logout action before destroying the session
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $pdo = $db->getConnection();
    log_audit_action($pdo, $_SESSION['user_id'], 'LOGOUT_SUCCESS', 'Auth');
}

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie securely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session entirely
session_destroy();

// Redirect back to the login page
header("Location: login.php");
exit();
?>