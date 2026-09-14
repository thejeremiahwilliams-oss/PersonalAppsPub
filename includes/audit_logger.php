<?php
// includes/audit_logger.php

/**
 * Logs user actions to the audit_logs table.
 *
 * @param PDO $pdo The active database connection
 * @param int $user_id The ID of the user performing the action
 * @param string $action_type e.g., 'LOGIN_SUCCESS', 'CREATE_TASK', 'UPDATE_KB'
 * @param string $module e.g., 'Auth', 'Tasks', 'KnowledgeBase'
 * @param array $details Optional associative array of changes (e.g., ['task_id' => 12])
 * @return bool True on success, False on failure
 */
function log_audit_action($pdo, $user_id, $action_type, $module, $details = []) {
    
    // Retrieve IP address, checking common proxy headers first
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    // Convert the details array to JSON for the text column
    $json_details = !empty($details) ? json_encode($details) : null;

    $sql = "INSERT INTO audit_logs (user_id, action_type, module, details, ip_address) 
            VALUES (:user_id, :action_type, :module, :details, :ip_address)";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id'     => $user_id,
            ':action_type' => $action_type,
            ':module'      => $module,
            ':details'     => $json_details,
            ':ip_address'  => $ip_address
        ]);
        return true;
    } catch (PDOException $e) {
        // Log the failure to the server log so it doesn't fail silently
        error_log("Audit Log Failure: " . $e->getMessage());
        return false;
    }
}