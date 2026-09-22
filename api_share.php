<?php
// public/api_share.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$table = '';

// Map the type to the correct database table
if ($type === 'note') $table = 'notes';
elseif ($type === 'kb') $table = 'kb_articles';
elseif ($type === 'task') $table = 'tasks';
elseif ($type === 'meeting') $table = 'meetings';
elseif ($type === 'budget') $table = 'budget_plans';
elseif ($type === 'ledger') $table = 'yearly_ledgers';
else {
    echo json_encode(['success' => false, 'error' => 'Invalid share type']);
    exit;
}

// Auto-heal schema: Ensure the table has a share_token column
try {
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN share_token VARCHAR(64) DEFAULT NULL UNIQUE");
} catch (Exception $e) {
    // Column already exists, proceed normally
}

try {
    // Ensure the user actually owns the item they are sharing (unless it's a global KB article)
    $auth_check = "";
    $params = [':id' => $id];
    
    if ($table !== 'kb_articles') {
        $auth_check = " AND user_id = :uid";
        $params[':uid'] = $_SESSION['user_id'];
    }

    // Check if a link already exists to prevent duplicates
    $stmt = $pdo->prepare("SELECT share_token FROM {$table} WHERE id = :id" . $auth_check);
    $stmt->execute($params);
    $existing_token = $stmt->fetchColumn();

    if ($existing_token) {
        $token = $existing_token;
    } else {
        // Generate a new random 32-character token
        $token = bin2hex(random_bytes(16));
        $update = $pdo->prepare("UPDATE {$table} SET share_token = :token WHERE id = :id" . $auth_check);
        $update_params = [':token' => $token, ':id' => $id];
        if ($table !== 'kb_articles') {
            $update_params[':uid'] = $_SESSION['user_id'];
        }
        $update->execute($update_params);
    }

    // Build the full public URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $link = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/shared.php?t=" . $token . "&type=" . $type;

    echo json_encode(['success' => true, 'link' => $link]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}