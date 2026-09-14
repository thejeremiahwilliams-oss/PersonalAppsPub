<?php
// public/shared.php
require_once 'config/database.php';

$token = $_GET['t'] ?? '';
$type = $_GET['type'] ?? '';

if (empty($token) || empty($type)) {
    die("Invalid or missing share link.");
}

$db = new Database();
$pdo = $db->getConnection();
$data = null;

try {
    if ($type === 'note') {
        $stmt = $pdo->prepare("SELECT title, content, created_at FROM notes WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch();
    } elseif ($type === 'kb') {
        $stmt = $pdo->prepare("SELECT title, category, content, updated_at as created_at FROM kb_articles WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch();
    } elseif ($type === 'task') {
        $stmt = $pdo->prepare("SELECT title, description as content, task_category as category, status, due_date as created_at FROM tasks WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch();
    } elseif ($type === 'meeting') {
        $stmt = $pdo->prepare("SELECT title, notes as content, meeting_date as created_at FROM meetings WHERE share_token = :t");
        $stmt->execute([':t' => $token]);
        $data = $stmt->fetch();
    }
} catch (Exception $e) {
    die("Database error.");
}

if (!$data) {
    die("This shared link has expired or does not exist.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['title']) ?> - Shared via MemoMatrix</title>
    <!-- Include Quill CSS to perfectly render rich text -->
    <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a; --card-bg: #1e293b; --text-main: #f8fafc; --text-muted: #94a3b8;
            --accent: #3b82f6; --border-color: #334155;
        }
        body { font-family: 'Inter', system-ui, sans-serif; background-color: var(--bg-color); color: var(--text-main); margin: 0; padding: 40px 20px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .header { padding: 30px; border-bottom: 1px solid var(--border-color); }
        .meta { display: flex; gap: 15px; font-size: 0.85em; color: var(--text-muted); margin-bottom: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .title { margin: 0; font-size: 2em; color: var(--text-main); }
        .content { padding: 30px; font-size: 1.05em; }
        .footer { padding: 15px 30px; background: rgba(59, 130, 246, 0.05); text-align: center; border-top: 1px solid var(--border-color); font-size: 0.8em; color: var(--text-muted); }
        
        /* Quill Read-Only Overrides */
        .ql-snow .ql-editor { padding: 0; }
        .ql-editor { font-family: inherit; font-size: inherit; color: inherit; }
        .tag-pill { background: rgba(59, 130, 246, 0.15); color: var(--accent); padding: 4px 12px; border-radius: 20px; font-size: 0.9em; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="meta">
                <span>Shared <?= ucfirst($type) ?></span>
                <?php if (!empty($data['category'])): ?>
                    <span class="tag-pill"><?= htmlspecialchars($data['category']) ?></span>
                <?php endif; ?>
                <?php if (!empty($data['status'])): ?>
                    <span style="color: <?= $data['status'] === 'completed' ? '#22c55e' : '#eab308' ?>;">• <?= ucfirst(str_replace('_', ' ', $data['status'])) ?></span>
                <?php endif; ?>
            </div>
            <h1 class="title"><?= htmlspecialchars($data['title']) ?></h1>
            <div style="margin-top: 10px; color: var(--text-muted); font-size: 0.9em;">
                <?= $type === 'meeting' ? 'Scheduled for: ' : 'Date: ' ?> 
                <?= $data['created_at'] ? date('l, F j, Y', strtotime($data['created_at'])) : 'N/A' ?>
            </div>
        </div>
        
        <div class="content ql-snow">
            <div class="ql-editor">
                <?= $type === 'note' || $type === 'kb' ? $data['content'] : nl2br(htmlspecialchars($data['content'] ?: 'No additional details provided.')) ?>
            </div>
        </div>
        
        <div class="footer">Securely shared via MemoMatrix</div>
    </div>
</body>
</html>