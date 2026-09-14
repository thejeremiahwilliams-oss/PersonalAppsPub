<?php
// public/export_backup.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';
require_login();

$user_id = $_SESSION['user_id'];
$db = new Database(); $pdo = $db->getConnection();

$include_notes = isset($_POST['export_notes']);
$include_kb = isset($_POST['export_kb']);
$include_tasks = isset($_POST['export_tasks']);
$include_logs = isset($_POST['export_logs']);
$include_planner = isset($_POST['export_planner']);
$include_ledger = isset($_POST['export_ledger']);

if (!$include_notes && !$include_kb && !$include_tasks && !$include_logs && !$include_planner && !$include_ledger) {
    header("Location: settings.php?error=no_selection");
    exit();
}

$zip_filename = sys_get_temp_dir() . '/personalapps_backup_' . time() . '.zip';
$zip = new ZipArchive();

if ($zip->open($zip_filename, ZipArchive::CREATE) === TRUE) {
    
    // 1. Export Notes
    if ($include_notes) {
        $stmt = $pdo->prepare("SELECT id, title, content, created_at FROM notes WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        while ($note = $stmt->fetch()) {
            $safe_title = !empty($note['title']) ? preg_replace('/[^a-zA-Z0-9-_]/', '_', $note['title']) : "note_{$note['id']}";
            $filename = "notes/{$safe_title}_{$note['id']}.md";
            $zip->addFromString($filename, "# " . ($note['title'] ?? 'Untitled Note') . "\nDate: {$note['created_at']}\n\n" . strip_tags($note['content']));
        }
    }

    // 2. Export Knowledge Base Articles
    if ($include_kb) {
        $stmt = $pdo->query("SELECT id, title, category, content, updated_at FROM kb_articles");
        while ($kb = $stmt->fetch()) {
            $safe_title = preg_replace('/[^a-zA-Z0-9-_]/', '_', $kb['title']);
            $filename = "knowledge_base/{$kb['category']}/{$safe_title}_{$kb['id']}.md";
            $zip->addFromString($filename, "# {$kb['title']}\nCategory: {$kb['category']}\nUpdated: {$kb['updated_at']}\n\n" . strip_tags($kb['content']));
        }
    }

    // 3. Export Tasks
    if ($include_tasks) {
        $stmt = $pdo->prepare("SELECT id, title, description, task_category, status, due_date, created_at FROM tasks WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        $tasks_json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
        $zip->addFromString("tasks/tasks_export.json", $tasks_json);
    }
    
    // 4. Export Daily Planner
    if ($include_planner) {
        $stmt = $pdo->prepare("SELECT plan_date, plan_data, updated_at FROM daily_plans WHERE user_id = :uid ORDER BY plan_date DESC");
        $stmt->execute([':uid' => $user_id]);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode the JSON strings so the export file is formatted nicely instead of escaped strings
        foreach ($plans as &$plan) {
            if (!empty($plan['plan_data'])) {
                $plan['plan_data'] = json_decode($plan['plan_data'], true);
            }
        }
        
        $planner_json = json_encode($plans, JSON_PRETTY_PRINT);
        $zip->addFromString("planner/daily_plans_export.json", $planner_json);
    }
    
    // 5. Export Annual Ledger
    if ($include_ledger) {
        $stmt = $pdo->prepare("SELECT ledger_year, ledger_data, updated_at FROM yearly_ledgers WHERE user_id = :uid ORDER BY ledger_year DESC");
        $stmt->execute([':uid' => $user_id]);
        $ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode the JSON strings so the export file is formatted nicely instead of escaped strings
        foreach ($ledgers as &$ledger) {
            if (!empty($ledger['ledger_data'])) {
                $ledger['ledger_data'] = json_decode($ledger['ledger_data'], true);
            }
        }
        
        $ledger_json = json_encode($ledgers, JSON_PRETTY_PRINT);
        $zip->addFromString("ledger/annual_ledgers_export.json", $ledger_json);
    }

    // 6. Export Audit Logs
    if ($include_logs) {
        $stmt = $pdo->prepare("SELECT action, module, details, created_at FROM audit_logs WHERE user_id = :uid ORDER BY created_at DESC");
        $stmt->execute([':uid' => $user_id]);
        $logs_json = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
        $zip->addFromString("audit_logs/activity_logs.json", $logs_json);
    }

    $zip->close();
    
    // Update audit log to track the new exports
    log_audit_action($pdo, $user_id, 'EXPORT_DATA_ZIP', 'System', [
        'notes' => $include_notes, 
        'kb' => $include_kb, 
        'tasks' => $include_tasks, 
        'planner' => $include_planner, 
        'ledger' => $include_ledger, 
        'logs' => $include_logs
    ]);

    // Stream download and delete temp file
    header('Content-Type: application/zip');
    header('Content-disposition: attachment; filename=personalapps_backup_' . date('Y-m-d') . '.zip');
    header('Content-Length: ' . filesize($zip_filename));
    readfile($zip_filename);
    unlink($zip_filename);
    exit();
} else {
    die("Failed to create ZIP archive.");
}