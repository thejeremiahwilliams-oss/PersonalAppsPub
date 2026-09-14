<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// public/search.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';

// Ensure user is logged in
require_login();
$user_id = $_SESSION['user_id'];
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Initialize DB connection
$db = new Database();
$pdo = $db->getConnection();

$query = $_GET['q'] ?? '';
$results = ['notes' => [], 'kb' => [], 'tasks' => []];
$search_error = '';

if (!empty($query)) {
    $search_term = "%" . $query . "%";
    
    try {
        // Search Notes (using unique placeholders :q1 and :q2)
        $stmt_notes = $pdo->prepare("SELECT id, title, content FROM notes WHERE user_id = :uid AND (title LIKE :q1 OR content LIKE :q2)");
        $stmt_notes->execute([
            ':uid' => $user_id, 
            ':q1' => $search_term, 
            ':q2' => $search_term
        ]);
        $results['notes'] = $stmt_notes->fetchAll();
        
        // Search Knowledge Base Articles with Privacy Restrictions
        if ($is_admin) {
            $stmt_kb = $pdo->prepare("SELECT id, title, content FROM kb_articles WHERE title LIKE :q1 OR content LIKE :q2");
            $stmt_kb->execute([
                ':q1' => $search_term, 
                ':q2' => $search_term
            ]);
        } else {
            $stmt_kb = $pdo->prepare("SELECT id, title, content FROM kb_articles WHERE (is_private = 0 OR author_id = :uid) AND (title LIKE :q1 OR content LIKE :q2)");
            $stmt_kb->execute([
                ':uid' => $user_id,
                ':q1' => $search_term, 
                ':q2' => $search_term
            ]);
        }
        $results['kb'] = $stmt_kb->fetchAll();
        
        // Search Tasks (using unique placeholders :q1 and :q2)
        $stmt_tasks = $pdo->prepare("SELECT id, title, description FROM tasks WHERE user_id = :uid AND (title LIKE :q1 OR description LIKE :q2)");
        $stmt_tasks->execute([
            ':uid' => $user_id, 
            ':q1' => $search_term, 
            ':q2' => $search_term
        ]);
        $results['tasks'] = $stmt_tasks->fetchAll();
        
    } catch (PDOException $e) {
        $search_error = "Database Error: " . $e->getMessage();
    } catch (Exception $e) {
        $search_error = "System Error: " . $e->getMessage();
    }
}

$page_title = "Search - MemoMatrix";
include 'includes/header.php';
?>

<div class="page-header" style="border: none; margin-bottom: 10px;">
    <h2>Global Search</h2>
</div>

<form method="GET" style="display: flex; gap: 10px; margin-bottom: 30px;">
    <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search everything..." style="flex: 1; font-size: 1.1em; padding: 12px 20px; border-radius: 30px; margin: 0;">
    <button type="submit" class="btn" style="border-radius: 30px; padding: 0 25px;">Search</button>
</form>

<?php if ($search_error): ?>
    <div style="padding: 15px; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-radius: 8px; margin-bottom: 20px; font-family: monospace;">
        <strong>Error Caught:</strong> <?= htmlspecialchars($search_error) ?>
    </div>
<?php endif; ?>

<?php if (!empty($query) && empty($search_error)): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">
        
        <?php if (!empty($results['notes'])): ?>
            <div class="card">
                <h3 style="margin-top: 0; color: var(--accent); border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Notes</h3>
                <?php foreach ($results['notes'] as $res): ?>
                    <div style="padding: 10px 0; border-bottom: 1px dashed var(--border-color);">
                        <a href="notes.php?id=<?= $res['id'] ?>" style="color: var(--text-main); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($res['title']) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($results['tasks'])): ?>
            <div class="card">
                <h3 style="margin-top: 0; color: var(--warning); border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Tasks</h3>
                <?php foreach ($results['tasks'] as $res): ?>
                    <div style="padding: 10px 0; border-bottom: 1px dashed var(--border-color);">
                        <a href="tasks.php" style="color: var(--text-main); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($res['title']) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($results['kb'])): ?>
            <div class="card">
                <h3 style="margin-top: 0; color: var(--success); border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Knowledge Base</h3>
                <?php foreach ($results['kb'] as $res): ?>
                    <div style="padding: 10px 0; border-bottom: 1px dashed var(--border-color);">
                        <a href="kb.php?id=<?= $res['id'] ?>" style="color: var(--text-main); text-decoration: none; font-weight: 600;"><?= htmlspecialchars($res['title']) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($results['notes']) && empty($results['tasks']) && empty($results['kb'])): ?>
            <div class="card" style="grid-column: 1 / -1; text-align: center; color: var(--text-muted); padding: 40px;">
                <p>No results found for "<strong><?= htmlspecialchars($query) ?></strong>"</p>
            </div>
        <?php endif; ?>

    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>