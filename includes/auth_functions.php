<?php
// includes/auth_functions.php

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Automatically apply user timezone if logged in, default to America/Chicago
$default_tz = $_SESSION['timezone'] ?? 'America/Chicago';
date_default_timezone_set($default_tz);

/**
 * Ensures the user is logged in. Redirects to login if not.
 */
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /login.php");
        exit();
    }
}

/**
 * Ensures the logged-in user is an admin. Aborts if not.
 */
function require_admin() {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die("403 Forbidden: You do not have permission to access this area.");
    }
}

function can_edit_kb() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor']);
}

/**
 * Checks if the current user has access to a specific feature.
 * Returns true if enabled or if user is an admin, false otherwise.
 */
function has_feature_access($feature_key) {
    // If not logged in, no access
    if (empty($_SESSION['user_id'])) return false;
    
    // Admins bypass feature locks and see everything
    if (!empty($_SESSION['is_admin']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
        return true;
    }

    try {
        require_once __DIR__ . '/../config/database.php';
        $db = new Database();
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare("SELECT is_enabled FROM user_features WHERE user_id = :uid AND feature_key = :key");
        $stmt->execute([':uid' => $_SESSION['user_id'], ':key' => $feature_key]);
        $enabled = $stmt->fetchColumn();

        // If explicitly set to 0 in the database, hide it
        if ($enabled !== false && (int)$enabled === 0) {
            return false;
        }
    } catch (Exception $e) {}
    
    // Default to enabled if no rule exists
    return true; 
}

/**
 * Enforces feature access and kicks the user out if disabled.
 */
function check_feature_access($feature_key) {
    if (!has_feature_access($feature_key)) {
        http_response_code(403);
        include_once 'header.php'; 
        echo "<div style='max-width:600px; margin:80px auto; text-align:center; padding:30px; background:var(--card-bg, #1e293b); border-radius:10px; border:1px solid var(--border-color, #334155); font-family:sans-serif;'>
                <h2 style='color:var(--danger, #ef4444); margin-top:0;'>Access Restricted</h2>
                <p style='color:var(--text-muted, #94a3b8);'>This module has been disabled for your account. Please contact an administrator.</p>
              </div>";
        include_once 'footer.php';
        exit;
    }
}