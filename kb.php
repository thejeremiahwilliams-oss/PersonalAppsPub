<?php 
// public/kb.php 
require_once 'includes/auth_functions.php'; 
require_once 'config/database.php'; 
require_once 'includes/audit_logger.php'; 

require_login(); 
$user_id = $_SESSION['user_id']; 
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'); 
$can_edit_kb = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor'])); 

$db = new Database(); 
$pdo = $db->getConnection(); 
$message = ''; 

// 1. Fetch preset categories from the global task_categories table (For Colors & Dropdown Options)
$global_categories = [];
$cat_colors = [];
try {
    $stmt_cats = $pdo->prepare("SELECT id, name, color FROM task_categories ORDER BY name ASC");
    $stmt_cats->execute();
    $global_categories = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
    foreach ($global_categories as $c) {
        $cat_colors[$c['name']] = $c['color'];
    }
} catch (Exception $e) {}

// 2. Fetch ACTIVE categories and counts for the visual Tag Pills
$active_categories = [];
try {
    if ($is_admin) {
        $cat_stmt = $pdo->query("SELECT category, COUNT(id) as count FROM kb_articles GROUP BY category ORDER BY category ASC");
    } else {
        $cat_stmt = $pdo->prepare("SELECT category, COUNT(id) as count FROM kb_articles WHERE is_private = 0 OR author_id = :uid GROUP BY category ORDER BY category ASC");
        $cat_stmt->execute([':uid' => $user_id]);
    }
    $active_categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle POST actions (Restricted to Admins and Editors) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit_kb) { 
    $action = $_POST['action'] ?? ''; 
    try { 
        if ($action === 'save_article') { 
            $article_id = !empty($_POST['article_id']) ? (int)$_POST['article_id'] : null; 
            $title = trim($_POST['title'] ?? 'Untitled Article'); 
            
            // Logic to handle the Dropdown vs Custom Tag input
            $preset_cat = trim($_POST['preset_category'] ?? '');
            $custom_cat = trim($_POST['custom_category'] ?? '');
            $category = ($preset_cat === '__CUSTOM__') ? $custom_cat : $preset_cat;
            if (empty($category)) {
                $category = 'Uncategorized';
            }

            $is_private = isset($_POST['is_private']) ? 1 : 0;
            $content_raw = trim($_POST['content'] ?? ''); 

            $content = $content_raw; 
            if (!empty($content_raw)) { 
                $decoded = base64_decode($content_raw, true); 
                if ($decoded !== false) { 
                    $content = $decoded; 
                } 
            } 

            $stripped_check = strip_tags($content); 
            if (!empty($title) && !empty($content) && !empty(trim($stripped_check))) { 
                
                if ($article_id) { 
                    if (!$is_admin) {
                        $chk = $pdo->prepare("SELECT author_id FROM kb_articles WHERE id = :id");
                        $chk->execute([':id' => $article_id]);
                        $art_owner = $chk->fetch();
                        if (!$art_owner || $art_owner['author_id'] != $user_id) {
                            throw new Exception("Unauthorized to modify this article's privacy settings.");
                        }
                    }

                    $sql = "UPDATE kb_articles SET title = :title, category = :category, content = :content, is_private = :is_private WHERE id = :id"; 
                    $stmt = $pdo->prepare($sql); 
                    $stmt->execute([':title' => $title, ':category' => $category, ':content' => $content, ':is_private' => $is_private, ':id' => $article_id]); 
                    log_audit_action($pdo, $user_id, 'UPDATE_KB_ARTICLE', 'KB', ['article_id' => $article_id]); 
                } else { 
                    $sql = "INSERT INTO kb_articles (author_id, title, category, content, is_private) VALUES (:author_id, :title, :category, :content, :is_private)"; 
                    $stmt = $pdo->prepare($sql); 
                    $stmt->execute([':author_id' => $user_id, ':title' => $title, ':category' => $category, ':content' => $content, ':is_private' => $is_private]); 
                    $article_id = $pdo->lastInsertId(); 
                    log_audit_action($pdo, $user_id, 'CREATE_KB_ARTICLE', 'KB', ['article_id' => $article_id]); 
                } 
                header("Location: kb.php"); 
                exit(); 
            } else { 
                $message = "Title and content cannot be empty."; 
            } 
        } elseif ($action === 'delete_article') { 
            $article_id = (int)$_POST['article_id']; 
            
            if (!$is_admin) {
                $chk = $pdo->prepare("SELECT author_id FROM kb_articles WHERE id = :id");
                $chk->execute([':id' => $article_id]);
                $art_owner = $chk->fetch();
                if (!$art_owner || $art_owner['author_id'] != $user_id) {
                    throw new Exception("Unauthorized to delete this article.");
                }
            }

            $sql = "DELETE FROM kb_articles WHERE id = :id"; 
            $stmt = $pdo->prepare($sql); 
            $stmt->execute([':id' => $article_id]); 
            log_audit_action($pdo, $user_id, 'DELETE_KB_ARTICLE', 'KB', ['article_id' => $article_id]); 
            header("Location: kb.php"); 
            exit(); 
        } 
    } catch (Exception $e) { $message = "Error: " . $e->getMessage(); } 
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$can_edit_kb) { 
    $message = "Unauthorized: Only administrators and editors can modify Knowledge Base articles."; 
} 

// Fetch all articles based on privacy rules
$query_params = []; 
if ($is_admin) {
    $sql = "SELECT id, author_id, title, category, content, is_private, updated_at FROM kb_articles ORDER BY updated_at DESC";
} else {
    $sql = "SELECT id, author_id, title, category, content, is_private, updated_at FROM kb_articles WHERE (is_private = 0 OR author_id = :uid) ORDER BY updated_at DESC";
    $query_params[':uid'] = $user_id;
}

$stmt = $pdo->prepare($sql); 
$stmt->execute($query_params); 
$articles = $stmt->fetchAll(); 

// Encode articles to JSON for instant JS loading 
$articles_json = json_encode($articles); 

$page_title = "Knowledge Base - PersonalApps"; 
include 'includes/header.php'; 
?> 

<!-- Ensure Quill Script is loaded on this page -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

<style>
    /* Dark mode text entry and placeholder contrast fixes */
    input, textarea, select { color: var(--text-main) !important; background-color: var(--bg-color) !important; font-family: inherit; }
    input::placeholder, textarea::placeholder { color: var(--text-muted) !important; opacity: 0.7; }
    
    .ql-editor { color: var(--text-main) !important; background-color: var(--card-bg) !important; min-height: 250px; max-height: 50vh; overflow-y: auto; font-size: 16px; }
    .ql-editor.ql-blank::before { color: var(--text-muted) !important; font-style: normal; opacity: 0.7; }
    .ql-snow .ql-stroke { stroke: var(--text-main) !important; }
    .ql-snow .ql-fill { fill: var(--text-main) !important; }
    .ql-snow .ql-picker { color: var(--text-main) !important; }
    .ql-toolbar.ql-snow { background-color: var(--card-bg); border-color: var(--border-color) !important; border-top-left-radius: 8px; border-top-right-radius: 8px; position: sticky; top: 0; z-index: 10; }
    .ql-container.ql-snow { border-color: var(--border-color) !important; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; background-color: var(--card-bg); }
    
    .ql-snow .ql-picker.ql-background .ql-picker-item[data-value=""]::before { content: "None"; }

    /* Search & Filter Bar */
    .kb-toolbar { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
    .kb-search { flex-grow: 1; padding: 10px 15px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-size: 1em; outline: none; transition: 0.2s; }
    .kb-search:focus { border-color: var(--accent); }
    .kb-filter { padding: 10px 15px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-size: 1em; outline: none; min-width: 180px; }
    
    /* Interactive Tag Pills */
    .tag-pill { font-family: inherit; font-size: 0.85em; padding: 6px 14px; border-radius: 20px; transition: 0.2s; font-weight: bold; }
    .tag-pill:hover { opacity: 0.85; }

    /* Modal Layout */
    .modal-footer { display: flex; justify-content: space-between; align-items: center; padding: 15px 25px; border-top: 1px solid var(--border-color); background: var(--bg-color); gap: 15px; } 
    .modal-btn-group { display: flex; gap: 10px; } 
    .modal-right { margin-left: auto; } 
    
    @media (max-width: 600px) { 
        .modal-footer { flex-direction: column-reverse; align-items: stretch; padding: 15px; } 
        .modal-btn-group { display: grid; grid-template-columns: 1fr 1fr; width: 100%; gap: 10px; } 
        .modal-right { margin-left: 0; } 
        .btn-full-mobile { grid-column: 1 / -1; } 
        .modal-footer .btn { width: 100%; padding: 12px 5px; font-size: 0.9em; box-sizing: border-box; text-align: center; } 
    } 
</style>

<div class="page-header"> 
    <h2>Knowledge Base</h2> 
    <?php if ($can_edit_kb): ?> 
        <button onclick="openKBModal()" class="btn">+ New Article</button> 
    <?php endif; ?> 
</div> 

<?php if ($message): ?> 
    <div style="padding: 15px; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-radius: 8px; margin-bottom: 20px;"> 
        <?= htmlspecialchars($message) ?> 
    </div> 
<?php endif; ?> 

<!-- Search Bar & Dropdown Options -->
<div class="kb-toolbar">
    <input type="text" id="kb-search" class="kb-search" placeholder="🔍 Search articles by title or content..." onkeyup="filterKB()">
    <select id="kb-category-filter" class="kb-filter" onchange="setCategoryFilter(this.value)">
        <option value="">All Categories</option>
        <option value="Uncategorized">Uncategorized</option>
        <?php foreach ($global_categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Category Tag Filters --> 
<div id="kb-tags" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 25px;"> 
    <button type="button" class="tag-pill" data-category="" onclick="setCategoryFilter('')" style="background: var(--accent); color: white; border: 1px solid var(--accent); cursor: pointer;">All Folders</button> 
    <?php foreach ($active_categories as $cat):  
        $cat_name = $cat['category'] ?: 'Uncategorized';
        $c_color = $cat_colors[$cat_name] ?? 'var(--border-color)';
    ?> 
        <button type="button" class="tag-pill" data-category="<?= htmlspecialchars($cat_name) ?>" data-color="<?= htmlspecialchars($c_color) ?>" onclick="setCategoryFilter('<?= htmlspecialchars(addslashes($cat_name)) ?>')" style="background: transparent; border: 1px solid <?= htmlspecialchars($c_color) ?>; color: var(--text-main); cursor: pointer;"> 
            📁 <?= htmlspecialchars($cat_name) ?> (<?= $cat['count'] ?>) 
        </button> 
    <?php endforeach; ?> 
</div> 

<!-- Articles Grid --> 
<div id="kb-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;"> 
    <?php if (empty($articles)): ?> 
        <p style="color: var(--text-muted); font-style: italic; grid-column: 1 / -1;" id="no-kb-msg">No articles found.</p> 
    <?php endif; ?> 

    <?php foreach ($articles as $art):  
        $preview = mb_substr(trim(strip_tags($art['content'])), 0, 120) . '...'; 
        $full_text = strip_tags($art['content']);
        $cat_display = htmlspecialchars($art['category'] ?: 'Uncategorized');
        $cat_color = $cat_colors[$cat_display] ?? 'var(--accent)';
    ?> 
        <div class="card kb-card-item" 
             data-title="<?= htmlspecialchars(strtolower($art['title'])) ?>" 
             data-content="<?= htmlspecialchars(strtolower($full_text)) ?>" 
             data-category="<?= $cat_display ?>"
             style="border-left: 4px solid <?= htmlspecialchars($cat_color) ?>; margin-bottom: 0; cursor: pointer; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;" 
             onclick="openKBModal(<?= $art['id'] ?>)" 
             onmouseover="this.style.transform='translateY(-2px)'; this.style.borderColor='<?= htmlspecialchars($cat_color) ?>';" 
             onmouseout="this.style.transform='none'; this.style.borderColor='var(--border-color)';"> 
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;"> 
                <span style="font-size: 0.75em; background: transparent; color: <?= htmlspecialchars($cat_color) ?>; border: 1px solid <?= htmlspecialchars($cat_color) ?>; padding: 3px 8px; border-radius: 4px; font-weight: bold;">
                    <?= $cat_display ?>
                </span> 
                <span style="font-size: 0.75em; color: var(--text-muted);"><?= date('M j, Y', strtotime($art['updated_at'])) ?></span> 
            </div> 
            
            <h4 style="margin: 0 0 10px 0; font-size: 1.1em; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <?= htmlspecialchars($art['title'] ?: 'Untitled') ?>
                <?php if ($art['is_private']): ?>
                    <span style="font-size: 0.7em; background: rgba(234, 179, 8, 0.1); color: var(--warning); border: 1px solid var(--warning); padding: 2px 6px; border-radius: 4px;" title="Private Article">🔒 Private</span>
                <?php endif; ?>
            </h4> 
            
            <div style="font-size: 0.9em; color: var(--text-muted); line-height: 1.5; flex-grow: 1; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;"> 
                <?= htmlspecialchars($preview) ?> 
            </div> 
        </div> 
    <?php endforeach; ?> 
</div> 

<!-- Large Modal for Article Viewing/Editing --> 
<div id="kb-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 100; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;"> 
    <div style="background: var(--bg-color); width: 100%; max-width: 900px; height: 85vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid var(--border-color);"> 
        
        <?php if (!$can_edit_kb): ?> 
            <div style="padding: 10px 25px; background: rgba(59, 130, 246, 0.1); color: var(--accent); font-size: 0.85em; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 8px;"> 
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg> 
                Read-Only Mode. Only administrators and editors can modify articles. 
            </div> 
        <?php endif; ?> 

        <form id="kb-form" method="POST" style="display: flex; flex-direction: column; height: 100%; margin: 0;"> 
            <input type="hidden" name="action" value="save_article"> 
            <input type="hidden" name="article_id" id="editor-article-id" value=""> 
            <input type="hidden" name="content" id="hidden-content"> 

            <!-- Modal Header / Title / Dropdown & Tagging Form --> 
            <div style="padding: 20px 25px; border-bottom: 1px solid var(--border-color); background: var(--card-bg);"> 
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" name="title" id="editor-title" placeholder="Article Title..." <?= $can_edit_kb ? 'required' : 'readonly' ?> style="width: 100%; border: none; background: transparent; font-size: 1.5em; font-weight: bold; color: var(--text-main); margin-bottom: 10px; padding: 0; outline: none; box-shadow: none;"> 
                        
                        <!-- Dropdown Category Options -->
                        <select name="preset_category" id="editor-category" <?= $can_edit_kb ? 'required' : 'disabled' ?> onchange="checkCustomCategory()" style="width: 100%; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); font-size: 0.9em; outline: none;">
                            <option value="Uncategorized">Uncategorized</option>
                            <?php foreach ($global_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                            <option value="__CUSTOM__" style="font-weight: bold; color: var(--accent);">+ Create New Custom Tag...</option>
                        </select>
                        
                        <!-- Custom Tagging Options Input (Hidden by default) -->
                        <input type="text" name="custom_category" id="editor-custom-category" placeholder="Type new category tag..." <?= $can_edit_kb ? '' : 'readonly' ?> style="display: none; width: 100%; border: 1px dashed var(--accent); background: var(--bg-color); font-size: 0.9em; color: var(--text-main); padding: 6px 12px; border-radius: 6px; outline: none; margin-top: 8px;"> 
                    </div>
                    
                    <!-- Privacy Toggle Checkbox -->
                    <div id="privacy-container" style="display: flex; align-items: center; gap: 8px; background: rgba(0,0,0,0.1); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border-color);">
                        <input type="checkbox" name="is_private" id="editor-is-private" value="1" style="width: auto; margin: 0; transform: scale(1.1); cursor: pointer;">
                        <label for="editor-is-private" style="font-size: 0.85em; font-weight: 600; cursor: pointer; color: var(--text-main); user-select: none;">🔒 Private Article</label>
                    </div>
                </div>
            </div> 

            <!-- Quill Editor Container --> 
            <div style="flex: 1; display: flex; flex-direction: column; overflow: hidden; background: var(--card-bg);"> 
                <div id="editor-container" style="flex: 1; border: none; border-radius: 0;"></div> 
            </div> 

            <!-- Modal Footer / Actions --> 
            <div class="modal-footer"> 
                <?php if ($can_edit_kb): ?> 
                    <div class="modal-btn-group"> 
                        <button type="button" id="btn-delete" class="btn btn-danger" style="display: none;" onclick="deleteCurrentArticle()">Delete</button> 
                        <button type="button" id="btn-share" class="btn" style="display: none; background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="generateShareLink('kb', document.getElementById('editor-article-id').value)">Share</button> 
                    </div> 
                    <div class="modal-btn-group modal-right"> 
                        <input type="file" id="file-upload-input" style="display: none;" onchange="uploadEditorFile(this)"> 
                        <button type="button" class="btn" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="document.getElementById('file-upload-input').click()">📎 Attach</button> 
                        <button type="button" class="btn" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="closeKBModal()">Cancel</button> 
                        <button type="submit" class="btn btn-full-mobile">Publish Update</button> 
                    </div> 
                <?php else: ?> 
                    <div class="modal-btn-group"> 
                        <button type="button" id="btn-share" class="btn btn-full-mobile" style="display: none; background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="generateShareLink('kb', document.getElementById('editor-article-id').value)">Share Link</button> 
                    </div> 
                    <div class="modal-btn-group modal-right"> 
                        <button type="button" class="btn btn-full-mobile" onclick="closeKBModal()">Close</button> 
                    </div> 
                <?php endif; ?> 
            </div> 
        </form> 
        
        <!-- Hidden Delete Form --> 
        <?php if ($can_edit_kb): ?> 
        <form id="delete-form" method="POST" style="display: none;"> 
            <input type="hidden" name="action" value="delete_article"> 
            <input type="hidden" name="article_id" id="delete-article-id" value=""> 
        </form> 
        <?php endif; ?> 

    </div> 
</div> 

<script> 
    const articlesData = <?= $articles_json ?: '[]' ?>; 
    const canEditKb = <?= $can_edit_kb ? 'true' : 'false' ?>; 
    const currentUserId = <?= (int)$user_id ?>;
    const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;
    
    const colors = ['#000000', '#e60000', '#ff9900', '#ffff00', '#008a00', '#0066cc', '#9933ff', '#ffffff', '#facccc', '#ffebcc', '#ffffcc', '#cce8cc', '#cce0f5', '#ebd6ff', '#bbbbbb', '#f06666', '#ffc266', '#ffff66', '#66b966', '#66a3e3', '#c285ff', '#888888', '#a10000', '#b26b00', '#b2b200', '#006100', '#0047b2', '#6b24b2', '#444444', '#5c0000', '#663d00', '#666600', '#003700', '#002966', '#3d1466', false];
    
    var quill = new Quill('#editor-container', { 
        theme: 'snow', 
        readOnly: !canEditKb, 
        placeholder: canEditKb ? 'Write structured documentation...' : 'Loading...', 
        modules: { 
            toolbar: canEditKb ? { 
                container: [ 
                    [{ header: [1, 2, 3, false] }], 
                    ['bold', 'italic', 'underline', 'strike'], 
                    [{ color: colors }, { background: colors }], 
                    ['blockquote', 'code-block'], 
                    [{ list: 'ordered' }, { list: 'bullet' }], 
                    ['link', 'image'], 
                    ['clean'] 
                ], 
                handlers: { 
                    image: function() { 
                        document.getElementById('file-upload-input').click(); 
                    } 
                } 
            } : false 
        } 
    }); 

    // Category Tag Click Logic synced with Dropdown Filter
    let currentCategoryFilter = "";

    function setCategoryFilter(category) {
        currentCategoryFilter = category;
        
        // Update the Dropdown to Match
        document.getElementById('kb-category-filter').value = category;
        
        // Update Tag Pills Visuals
        const tags = document.querySelectorAll('#kb-tags .tag-pill');
        tags.forEach(tag => {
            const tagCat = tag.getAttribute('data-category');
            const tagColor = tag.getAttribute('data-color') || 'var(--border-color)';
            
            if (tagCat === category) {
                if (category === "") {
                    tag.style.background = 'var(--accent)';
                    tag.style.color = 'white';
                    tag.style.borderColor = 'var(--accent)';
                } else {
                    tag.style.background = tagColor;
                    tag.style.color = 'white';
                    tag.style.borderColor = tagColor;
                }
            } else {
                tag.style.background = 'transparent';
                if (!tagCat) { // All Folders button
                    tag.style.borderColor = 'var(--border-color)';
                    tag.style.color = 'var(--text-muted)';
                } else {
                    tag.style.borderColor = tagColor;
                    tag.style.color = 'var(--text-main)';
                }
            }
        });

        filterKB();
    }

    // Check if category is in URL on load
    document.addEventListener("DOMContentLoaded", () => {
        const urlParams = new URLSearchParams(window.location.search);
        const urlCat = urlParams.get('category');
        if (urlCat) {
            setCategoryFilter(urlCat);
        }
    });

    // Real-Time Search & Filter Logic
    function filterKB() {
        const query = document.getElementById('kb-search').value.toLowerCase();
        const categoryFilter = currentCategoryFilter;
        const cards = document.querySelectorAll('.kb-card-item');
        let visibleCount = 0;

        cards.forEach(card => {
            const title = card.getAttribute('data-title');
            const content = card.getAttribute('data-content');
            const category = card.getAttribute('data-category');

            const matchesSearch = title.includes(query) || content.includes(query);
            const matchesCategory = categoryFilter === "" || category === categoryFilter;

            if (matchesSearch && matchesCategory) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        let msgEl = document.getElementById('no-kb-msg');
        if (visibleCount === 0 && cards.length > 0) {
            if (!msgEl) {
                msgEl = document.createElement('p');
                msgEl.id = 'no-kb-msg';
                msgEl.style.cssText = "color: var(--text-muted); font-style: italic; grid-column: 1 / -1;";
                msgEl.innerText = "No articles match your search or filter.";
                document.getElementById('kb-grid').appendChild(msgEl);
            }
            msgEl.style.display = 'block';
        } else if (msgEl) {
            msgEl.style.display = 'none';
        }
    }

    if (canEditKb) { 
        window.uploadEditorFile = function(input) { 
            if (!input.files || input.files.length === 0) return; 
            const file = input.files[0]; 
            const formData = new FormData(); 
            formData.append('file', file); 

            const originalText = input.nextElementSibling.innerHTML; 
            input.nextElementSibling.innerHTML = '⏳...'; 
            input.nextElementSibling.disabled = true; 

            fetch('api_upload.php', { method: 'POST', body: formData }) 
            .then(res => res.json()) 
            .then(data => { 
                if (data.success) { 
                    let range = quill.getSelection(true); 
                    if (data.is_image) { 
                        quill.insertEmbed(range.index, 'image', data.url); 
                    } else { 
                        quill.insertText(range.index, '📄 ' + data.name, 'link', data.url); 
                    } 
                    quill.setSelection(range.index + (data.is_image ? 1 : data.name.length + 3)); 
                } else { 
                    alert("Upload failed: " + data.error); 
                } 
            }) 
            .catch(err => { console.error(err); alert("Network error during upload."); }) 
            .finally(() => { 
                input.value = '';  
                input.nextElementSibling.innerHTML = originalText; 
                input.nextElementSibling.disabled = false; 
            }); 
        } 

        document.getElementById('kb-form').addEventListener('submit', function(e) { 
            var rawHtml = quill.root.innerHTML; 
            document.getElementById('hidden-content').value = btoa(unescape(encodeURIComponent(rawHtml))); 
        }); 
    } 

    function checkCustomCategory() {
        const select = document.getElementById('editor-category');
        const customInput = document.getElementById('editor-custom-category');
        if (select.value === '__CUSTOM__') {
            customInput.style.display = 'block';
            customInput.required = true;
        } else {
            customInput.style.display = 'none';
            customInput.required = false;
        }
    }

    function openKBModal(id = null) { 
        document.getElementById('kb-modal-overlay').style.display = 'flex'; 
        const privacyCheckbox = document.getElementById('editor-is-private');
        const privacyContainer = document.getElementById('privacy-container');
        const select = document.getElementById('editor-category');
        const customInput = document.getElementById('editor-custom-category');
        
        if (id) { 
            const art = articlesData.find(a => a.id == id); 
            if (art) { 
                document.getElementById('editor-article-id').value = art.id; 
                document.getElementById('editor-title').value = art.title; 
                
                // Determine if article's category is in the preset dropdown
                const existingOptions = Array.from(select.options).map(o => o.value);
                const artCat = art.category || 'Uncategorized';
                
                if (existingOptions.includes(artCat)) {
                    select.value = artCat;
                    customInput.value = '';
                } else {
                    select.value = '__CUSTOM__';
                    customInput.value = artCat;
                }
                checkCustomCategory();

                privacyCheckbox.checked = art.is_private == 1; 

                const isOwner = art.author_id == currentUserId;
                if (canEditKb && (isOwner || isAdmin)) {
                    privacyContainer.style.display = 'flex';
                    privacyCheckbox.disabled = false;
                } else {
                    privacyCheckbox.disabled = true;
                }

                quill.root.innerHTML = art.content; 
                if (canEditKb && (isOwner || isAdmin)) {
                    document.getElementById('btn-delete').style.display = 'inline-block'; 
                } else {
                    document.getElementById('btn-delete').style.display = 'none';
                }
                document.getElementById('btn-share').style.display = 'inline-block'; 
            } 
        } else if (canEditKb) { 
            document.getElementById('editor-article-id').value = ''; 
            document.getElementById('editor-title').value = ''; 
            
            select.value = 'Uncategorized';
            customInput.value = '';
            checkCustomCategory();

            privacyCheckbox.checked = false;
            privacyCheckbox.disabled = false;
            privacyContainer.style.display = 'flex';
            
            quill.root.innerHTML = ''; 
            document.getElementById('btn-delete').style.display = 'none'; 
            document.getElementById('btn-share').style.display = 'none'; 
        } 
    } 

    function closeKBModal() { 
        document.getElementById('kb-modal-overlay').style.display = 'none'; 
    } 

    function deleteCurrentArticle() { 
        if (!canEditKb) return; 
        const id = document.getElementById('editor-article-id').value; 
        if (id && confirm('Are you sure you want to delete this article?')) { 
            document.getElementById('delete-article-id').value = id; 
            document.getElementById('delete-form').submit(); 
        } 
    } 
</script> 

<?php include 'includes/footer.php'; ?>