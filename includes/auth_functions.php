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
// Add this helper function to includes/auth_functions.php if you haven't already
function can_edit_kb() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor']);
    }