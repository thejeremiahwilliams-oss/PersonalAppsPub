<?php
// public/notes.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();
$message = '';

// --- API ENDPOINTS ---

// 1. Auto-Save API
if (isset($_GET['api']) && $_GET['api'] === 'save_note') {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);
    
    $note_id = !empty($payload['note_id']) ? (int)$payload['note_id'] : null;
    $title = trim($payload['title'] ?? 'Untitled Note');
    $category = trim($payload['category'] ?? 'Uncategorized');
    $content_raw = trim($payload['content'] ?? '');
    
    $content = $content_raw;
    if (!empty($content_raw)) {
        $decoded = base64_decode($content_raw, true);
        if ($decoded !== false) {
            $content = $decoded;
        }
    }

    if (empty($title) && empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Empty note']);
        exit;
    }

    if ($note_id) {
        $stmt = $pdo->prepare("UPDATE notes SET title = :title, content = :content, category = :category WHERE id = :id AND user_id = :uid");
        $stmt->execute([':title' => $title, ':content' => $content, ':category' => $category, ':id' => $note_id, ':uid' => $user_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, category) VALUES (:uid, :title, :content, :category)");
        $stmt->execute([':uid' => $user_id, ':title' => $title, ':content' => $content, ':category' => $category]);
        $note_id = $pdo->lastInsertId();
    }
    
    echo json_encode(['success' => true, 'note_id' => $note_id]);
    exit;
}

// 2. Web Clipper API
if (isset($_GET['api']) && $_GET['api'] === 'clip') {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);
    $url = filter_var($payload['url'] ?? '', FILTER_SANITIZE_URL);
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid URL provided.']);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_ENCODING, ""); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.5",
        "Cache-Control: no-cache",
        "Connection: keep-alive"
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) {
        echo json_encode(['success' => false, 'error' => 'Could not fetch the webpage.']);
        exit;
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    
    $xpath = new DOMXPath($doc);
    $junkNodes = $xpath->query('//script | //style | //nav | //footer | //header | //aside | //noscript | //iframe | //svg | //form | //button');
    for ($j = $junkNodes->length - 1; $j >= 0; $j--) {
        $node = $junkNodes->item($j);
        $node->parentNode->removeChild($node);
    }
    
    $title = '';
    $nodes = $doc->getElementsByTagName('title');
    if ($nodes->length > 0) $title = $nodes->item(0)->nodeValue;

    $description = '';
    $image = '';
    $metas = $doc->getElementsByTagName('meta');
    for ($i = 0; $i < $metas->length; $i++) {
        $meta = $metas->item($i);
        $name = strtolower($meta->getAttribute('name'));
        $property = strtolower($meta->getAttribute('property'));
        $content = trim($meta->getAttribute('content'));
        
        if (($name === 'description' || $property === 'og:description' || $name === 'twitter:description') && empty($description)) $description = $content;
        if (($property === 'og:image' || $name === 'twitter:image') && empty($image)) {
            $image = $content;
            if ($image && !preg_match("~^(?:f|ht)tps?://~i", $image)) {
                $parsed_url = parse_url($url);
                $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : 'https://';
                $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
                $image = $scheme . $host . (substr($image, 0, 1) === '/' ? '' : '/') . $image;
            }
        }
        if (($property === 'og:title' || $name === 'twitter:title') && empty($title)) $title = $content;
    }

    $paragraphs = '';
    $contentNodes = $xpath->query('//article//p | //article//h2 | //article//h3 | //article//li | //main//p | //main//h2 | //main//h3 | //div[contains(@class, "content") or contains(@class, "article") or contains(@class, "post")]//p');
    if ($contentNodes->length === 0) $contentNodes = $xpath->query('//p | //h2 | //h3');

    foreach($contentNodes as $node) {
        $text = trim(preg_replace('/\s+/', ' ', $node->nodeValue)); 
        if (strlen($text) > 40 || in_array(strtolower($node->nodeName), ['h2', 'h3', 'li'])) { 
            $tag = strtolower($node->nodeName);
            if ($tag === 'h2') $paragraphs .= '<h2>' . htmlspecialchars($text) . '</h2>';
            elseif ($tag === 'h3') $paragraphs .= '<h3>' . htmlspecialchars($text) . '</h3>';
            elseif ($tag === 'li') $paragraphs .= '<ul><li>' . htmlspecialchars($text) . '</li></ul>';
            else $paragraphs .= '<p>' . htmlspecialchars($text) . '</p>';
        }
    }

    if (empty($paragraphs)) {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $rawText = trim(preg_replace('/\s+/', ' ', strip_tags($body->nodeValue)));
            $previewText = mb_substr($rawText, 0, 1000) . '...';
            if (strlen($previewText) > 20) {
                 $paragraphs = '<p><em>Raw Text Fallback:</em><br>' . nl2br(htmlspecialchars($previewText)) . '</p>';
            }
        }
    }
    libxml_clear_errors();

    $clipHtml = "<blockquote><p><strong>🌐 Clipped from:</strong> <a href=\"" . htmlspecialchars($url) . "\" target=\"_blank\">" . htmlspecialchars($url) . "</a></p>";
    if ($image) $clipHtml .= "<p><img src=\"" . htmlspecialchars($image) . "\" style=\"max-width:400px; border-radius:6px; display:block; margin: 10px 0;\"></p>";
    if ($description) $clipHtml .= "<p><em>" . htmlspecialchars($description) . "</em></p></blockquote>";
    else $clipHtml .= "</blockquote>";
    $clipHtml .= "<hr><br>" . $paragraphs . "<p><br></p>";

    echo json_encode(['success' => true, 'title' => trim($title ?: 'Web Clip'), 'html' => $clipHtml]);
    exit;
}

// Handle Traditional Deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_note') {
    $note_id = (int)$_POST['note_id'];
    $stmt = $pdo->prepare("DELETE FROM notes WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $note_id, ':uid' => $user_id]);
    log_audit_action($pdo, $user_id, 'DELETE_NOTE', 'Notes', ['note_id' => $note_id]);
    header("Location: notes.php");
    exit();
}

// Fetch Preset Categories
$categories = [];
$cat_colors = [];
try {
    $stmt_cats = $pdo->prepare("SELECT id, name, color FROM task_categories ORDER BY name ASC");
    $stmt_cats->execute();
    $categories = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categories as $c) $cat_colors[$c['name']] = $c['color'];
} catch (Exception $e) {}

// Fetch Notes
$stmt = $pdo->prepare("SELECT id, title, content, category, created_at FROM notes WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute([':uid' => $user_id]);
$notes = $stmt->fetchAll();
$notes_json = json_encode($notes);

$page_title = "Notes - PersonalApps";
include 'includes/header.php';
?>

<!-- PWA & Mobile Web App Tags -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0f172a">

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

<style>
    /* Mobile-First Base Styles */
    input, textarea, select { color: var(--text-main) !important; background-color: var(--bg-color) !important; font-family: inherit; font-size: 16px !important; }
    input::placeholder, textarea::placeholder { color: var(--text-muted) !important; opacity: 0.7; }
    
    .btn { padding: 10px 20px; font-weight: bold; border-radius: 8px; }
    
    .notes-toolbar { display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px; }
    .notes-search, .notes-filter { width: 100%; padding: 14px 15px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); outline: none; }
    .notes-search:focus { border-color: var(--accent); }

    .notes-grid { display: grid; grid-template-columns: 1fr; gap: 15px; padding-bottom: 80px; }
    .note-card-item { margin-bottom: 0; cursor: pointer; display: flex; flex-direction: column; padding: 20px; border-radius: 12px; background: var(--card-bg); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s; }

    /* Floating Action Button */
    .fab-new-note { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background-color: var(--accent); color: white; display: flex; align-items: center; justify-content: center; font-size: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 100; cursor: pointer; border: none; transition: transform 0.2s; }
    .fab-new-note:active { transform: scale(0.95); }

    /* ----------------------------------------------------
       THE FIX: NATIVE SCROLL MODAL FOR MOBILE
       ---------------------------------------------------- */
    .modal-overlay { 
        position: fixed; inset: 0; background: var(--bg-color); 
        display: none; z-index: 1000; 
        overflow-y: auto; /* Let the entire modal scroll natively! */
        -webkit-overflow-scrolling: touch; 
    }
    .modal-content.notes-modal { 
        width: 100%; min-height: 100%; /* Will grow as long as the text is */
        margin: 0; border-radius: 0; display: flex; flex-direction: column; 
        background: var(--bg-color); border: none; 
    }
    
    .modal-header-flex { display: flex; flex-direction: column; gap: 10px; padding: 15px; border-bottom: 1px solid var(--border-color); background: var(--card-bg); }
    .modal-header-top { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 10px; }
    
    .close-modal { background: none; border: none; font-size: 1.5em; color: var(--text-muted); cursor: pointer; padding: 5px 15px 5px 0; }
    #editor-title { width: 100%; border: none; background: transparent; font-size: 1.4em; font-weight: bold; color: var(--text-main); padding: 5px 0; outline: none; box-shadow: none; }
    
    .status-indicator { font-size: 0.85em; font-weight: bold; color: var(--text-muted); opacity: 0; transition: opacity 0.3s; white-space: nowrap; }
    .status-indicator.visible { opacity: 1; }

    /* Mobile Action Buttons underneath Title */
    .modal-actions-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    #editor-category { flex-grow: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-color); outline: none; max-width: 150px; }
    .action-icons { display: flex; gap: 8px; align-items: center; }
    .action-icons button { background: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-main); font-size: 1.1em; display: flex; align-items: center; justify-content: center; min-width: 38px; min-height: 38px; border-radius: 6px; cursor: pointer; }
    .action-icons button:active { background: rgba(255,255,255,0.1); }

    /* Sticky Quill Toolbar (Stays at top while scrolling down) */
    .ql-toolbar.ql-snow { 
        position: sticky; top: 0; z-index: 50; 
        background-color: var(--card-bg); border: none !important; 
        border-bottom: 1px solid var(--border-color) !important; 
        overflow-x: auto; white-space: nowrap; padding: 10px; 
        -webkit-overflow-scrolling: touch; display: block; 
    }
    .ql-toolbar.ql-snow .ql-formats { display: inline-block; margin-right: 15px; margin-bottom: 0; }

    /* Auto-expanding editor */
    .ql-container.ql-snow { border: none !important; background-color: var(--bg-color); font-size: 16px; height: auto; padding-bottom: 50vh; /* Extra padding so keyboard never hides bottom lines */ }
    .ql-editor { color: var(--text-main) !important; padding: 15px; overflow-y: visible; /* CRITICAL for native scroll */ }
    .ql-editor.ql-blank::before { color: var(--text-muted) !important; font-style: normal; opacity: 0.7; left: 15px; }
    .ql-snow .ql-stroke { stroke: var(--text-main) !important; }
    .ql-snow .ql-fill { fill: var(--text-main) !important; }
    .ql-snow .ql-picker { color: var(--text-main) !important; }

    /* Desktop Overrides */
    @media (min-width: 768px) {
        .fab-new-note { display: none; }
        .desktop-new-btn { display: inline-block !important; }
        
        .notes-toolbar { flex-direction: row; align-items: center; }
        .notes-search { flex-grow: 1; }
        .notes-filter { width: auto; min-width: 200px; }
        .notes-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); padding-bottom: 0; }
        
        .modal-overlay { background: rgba(0,0,0,0.6); padding: 40px 20px; align-items: center; }
        .modal-content.notes-modal { max-width: 900px; min-height: auto; max-height: 85vh; margin: auto; border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden; }
        
        .modal-header-flex { flex-direction: row; align-items: center; padding: 20px 25px; gap: 20px; }
        .modal-header-top { width: auto; flex-grow: 1; }
        #editor-title { font-size: 1.6em; }
        #editor-category { max-width: 200px; }
        
        .ql-toolbar.ql-snow { position: static; border: 1px solid var(--border-color) !important; border-top-left-radius: 8px; border-top-right-radius: 8px; white-space: normal; display: block; overflow-x: visible; margin: 20px 20px 0 20px; }
        .ql-toolbar.ql-snow .ql-formats { margin-bottom: 8px; }
        .ql-container.ql-snow { border: 1px solid var(--border-color) !important; border-top: none !important; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; margin: 0 20px 20px 20px; background-color: var(--card-bg); flex-grow: 1; height: 0; padding-bottom: 0; display: flex; flex-direction: column; }
        .ql-editor { overflow-y: auto; min-height: 350px; padding-bottom: 20px; }
    }
    
    .desktop-new-btn { display: none; }
</style>

<div class="page-header">
    <h2>My Notes</h2>
    <button onclick="openNoteModal()" class="btn desktop-new-btn">+ New Note</button>
</div>

<button onclick="openNoteModal()" class="fab-new-note" aria-label="Create New Note">+</button>

<div class="notes-toolbar">
    <input type="text" id="note-search" class="notes-search" placeholder="🔍 Search notes..." onkeyup="filterNotes()">
    <select id="note-category-filter" class="notes-filter" onchange="filterNotes()">
        <option value="">All Categories</option>
        <option value="Uncategorized">Uncategorized</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="notes-grid" id="notes-grid">
    <?php if (empty($notes)): ?>
        <p style="color: var(--text-muted); font-style: italic; grid-column: 1 / -1;" id="no-notes-msg">No notes found. Create your first note!</p>
    <?php endif; ?>

    <?php foreach ($notes as $note): 
        $preview = mb_substr(trim(strip_tags($note['content'])), 0, 120) . '...';
        $full_text = strip_tags($note['content']);
        $cat_display = htmlspecialchars($note['category'] ?? 'Uncategorized');
        $cat_color = $cat_colors[$cat_display] ?? 'var(--accent)';
    ?>
        <div class="card note-card-item" 
             data-title="<?= htmlspecialchars(strtolower($note['title'])) ?>" 
             data-content="<?= htmlspecialchars(strtolower($full_text)) ?>" 
             data-category="<?= $cat_display ?>"
             style="border-left: 4px solid <?= htmlspecialchars($cat_color) ?>;"
             onclick="openNoteModal(<?= $note['id'] ?>)">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 0.75em; color: <?= htmlspecialchars($cat_color) ?>; border: 1px solid <?= htmlspecialchars($cat_color) ?>; padding: 3px 8px; border-radius: 4px; font-weight: bold;">
                    <?= $cat_display ?>
                </span>
                <span style="font-size: 0.75em; color: var(--text-muted);"><?= date('M j, Y', strtotime($note['created_at'])) ?></span>
            </div>
            <h4 style="margin: 0 0 10px 0; font-size: 1.1em; color: var(--text-main);"><?= htmlspecialchars($note['title'] ?: 'Untitled Note') ?></h4>
            <div style="font-size: 0.9em; color: var(--text-muted); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
                <?= htmlspecialchars($preview) ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- NATIVE SCROLL MODAL -->
<div id="note-modal-overlay" class="modal-overlay">
    <div class="modal-content notes-modal">
        <input type="hidden" id="editor-note-id" value="">

        <div class="modal-header-flex">
            <!-- Top Row: Back, Title, Save Status -->
            <div class="modal-header-top">
                <button type="button" class="close-modal" aria-label="Close Note" onclick="closeNoteModal()">❮</button>
                <input type="text" id="editor-title" placeholder="Note Title...">
                <span id="auto-save-status" class="status-indicator"></span>
            </div>
            <!-- Bottom Row: Category & Action Buttons -->
            <div class="modal-actions-row">
                <select id="editor-category">
                    <option value="Uncategorized">Uncategorized</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="action-icons">
                    <button type="button" onclick="clipWebpage()" title="Web Clipper">✂️</button>
                    <input type="file" id="file-upload-input" style="display: none;" onchange="uploadEditorFile(this)">
                    <button type="button" onclick="document.getElementById('file-upload-input').click()" title="Attach File">📎</button>
                    <button type="button" id="btn-share" style="display: none; color: var(--accent);" onclick="generateShareLink('note', document.getElementById('editor-note-id').value)" title="Share Link">🔗</button>
                    <button type="button" id="btn-delete" style="display: none; color: var(--danger);" onclick="deleteCurrentNote()" title="Delete Note">🗑️</button>
                </div>
            </div>
        </div>

        <!-- Sticky Toolbar + Expanding Editor -->
        <div id="editor-container"></div>
        
        <form id="delete-form" method="POST" style="display: none;">
            <input type="hidden" name="action" value="delete_note">
            <input type="hidden" name="note_id" id="delete-note-id" value="">
        </form>
    </div>
</div>

<script>
    const notesData = <?= $notes_json ?: '[]' ?>;
    const colors = ['#000000', '#e60000', '#ff9900', '#ffff00', '#008a00', '#0066cc', '#9933ff', '#ffffff', '#facccc', '#ffebcc', '#ffffcc', '#cce8cc', '#cce0f5', '#ebd6ff', '#bbbbbb', '#f06666', '#ffc266', '#ffff66', '#66b966', '#66a3e3', '#c285ff', '#888888', '#a10000', '#b26b00', '#b2b200', '#006100', '#0047b2', '#6b24b2', '#444444', '#5c0000', '#663d00', '#666600', '#003700', '#002966', '#3d1466', false];

    var quill = new Quill('#editor-container', {
        theme: 'snow',
        placeholder: 'Tap here to start writing...',
        modules: {
            toolbar: {
                container: [
                    [{ header: [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ color: colors }, { background: colors }], 
                    [{ list: 'ordered' }, { list: 'bullet' }, 'blockquote', 'code-block'],
                    ['link', 'clean']
                ]
            }
        }
    });

    // --- AUTO-SAVE & OFFLINE SYNC ENGINE ---
    let autoSaveTimer = null;
    const statusEl = document.getElementById('auto-save-status');

    function triggerAutoSave() {
        clearTimeout(autoSaveTimer);
        statusEl.innerText = 'Typing...';
        statusEl.classList.add('visible');
        autoSaveTimer = setTimeout(saveNoteAjax, 1500);
    }

    async function saveNoteAjax() {
        const title = document.getElementById('editor-title').value;
        const category = document.getElementById('editor-category').value;
        const rawHtml = quill.root.innerHTML;
        const content = btoa(unescape(encodeURIComponent(rawHtml)));
        let noteId = document.getElementById('editor-note-id').value;
        
        statusEl.innerText = 'Saving...';
        
        try {
            const res = await fetch('notes.php?api=save_note', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ note_id: noteId, title, category, content })
            });
            const data = await res.json();
            
            if(data.success) {
                if(!noteId && data.note_id) {
                    document.getElementById('editor-note-id').value = data.note_id;
                    document.getElementById('btn-share').style.display = 'flex';
                    document.getElementById('btn-delete').style.display = 'flex';
                }
                statusEl.innerText = 'Saved';
                setTimeout(() => { statusEl.classList.remove('visible'); }, 2000);
                
                // Clear offline cache if it exists
                localStorage.removeItem('offline_note_' + (noteId || 'new'));
            }
        } catch (e) {
            // Offline Fallback
            statusEl.innerText = 'Offline (Saved Locally)';
            localStorage.setItem('offline_note_' + (noteId || 'new'), JSON.stringify({title, category, content: rawHtml}));
        }
    }

    // Attach listeners for Auto-Save
    quill.on('text-change', () => { if(document.getElementById('note-modal-overlay').style.display === 'flex') triggerAutoSave(); });
    document.getElementById('editor-title').addEventListener('input', triggerAutoSave);
    document.getElementById('editor-category').addEventListener('change', triggerAutoSave);

    // --- MODAL CONTROLS ---
    function openNoteModal(id = null) {
        document.getElementById('note-modal-overlay').style.display = 'block';
        document.body.style.overflow = 'hidden';
        statusEl.classList.remove('visible');
        clearTimeout(autoSaveTimer);
        
        // Scroll overlay to top
        document.getElementById('note-modal-overlay').scrollTop = 0;
        
        if (id) {
            const note = notesData.find(n => n.id == id);
            if (note) {
                document.getElementById('editor-note-id').value = note.id;
                document.getElementById('editor-title').value = note.title;
                document.getElementById('editor-category').value = note.category || 'Uncategorized';
                
                let contentToLoad = note.content || '';
                try {
                    if (/^[a-zA-Z0-9+/]*={0,2}$/.test(contentToLoad) && contentToLoad.length % 4 === 0) {
                        contentToLoad = decodeURIComponent(escape(atob(contentToLoad)));
                    }
                } catch (e) {}
                
                // Check offline cache first
                const offlineData = localStorage.getItem('offline_note_' + id);
                if (offlineData) {
                    const parsed = JSON.parse(offlineData);
                    contentToLoad = parsed.content;
                    document.getElementById('editor-title').value = parsed.title;
                    document.getElementById('editor-category').value = parsed.category;
                    statusEl.innerText = 'Restored Offline Draft';
                    statusEl.classList.add('visible');
                }
                
                quill.root.innerHTML = contentToLoad;
                document.getElementById('btn-delete').style.display = 'flex';
                document.getElementById('btn-share').style.display = 'flex';
            }
        } else {
            document.getElementById('editor-note-id').value = '';
            document.getElementById('editor-title').value = '';
            document.getElementById('editor-category').value = 'Uncategorized';
            
            let contentToLoad = '';
            const offlineData = localStorage.getItem('offline_note_new');
            if (offlineData) {
                const parsed = JSON.parse(offlineData);
                contentToLoad = parsed.content;
                document.getElementById('editor-title').value = parsed.title;
                document.getElementById('editor-category').value = parsed.category;
            }
            
            quill.root.innerHTML = contentToLoad;
            document.getElementById('btn-delete').style.display = 'none';
            document.getElementById('btn-share').style.display = 'none';
        }
    }

    function closeNoteModal() {
        document.getElementById('note-modal-overlay').style.display = 'none';
        document.body.style.overflow = '';
        // Since we auto-save, we reload the page on close to update the grid view
        window.location.reload();
    }

    async function deleteCurrentNote() {
        const id = document.getElementById('editor-note-id').value;
        if (id) {
            if (confirm('Are you sure you want to permanently delete this note?')) {
                localStorage.removeItem('offline_note_' + id);
                document.getElementById('delete-note-id').value = id;
                document.getElementById('delete-form').submit();
            }
        }
    }

    // --- UTILITIES ---
    function filterNotes() {
        const query = document.getElementById('note-search').value.toLowerCase();
        const categoryFilter = document.getElementById('note-category-filter').value;
        const cards = document.querySelectorAll('.note-card-item');
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

        let msgEl = document.getElementById('no-notes-msg');
        if (visibleCount === 0 && cards.length > 0) {
            if (!msgEl) {
                msgEl = document.createElement('p');
                msgEl.id = 'no-notes-msg';
                msgEl.style.cssText = "color: var(--text-muted); font-style: italic; grid-column: 1 / -1;";
                msgEl.innerText = "No notes match your search or filter.";
                document.getElementById('notes-grid').appendChild(msgEl);
            }
            msgEl.style.display = 'block';
        } else if (msgEl) {
            msgEl.style.display = 'none';
        }
    }

    function clipWebpage() {
        const url = prompt("Enter URL to clip:");
        if (!url || url.trim() === '') return;
        
        statusEl.innerText = 'Clipping...';
        statusEl.classList.add('visible');

        fetch('notes.php?api=clip', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: url.trim() })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const titleInput = document.getElementById('editor-title');
                if (titleInput.value === '' || titleInput.value === 'Untitled Note') titleInput.value = data.title;
                let range = quill.getSelection();
                let insertIndex = range ? range.index : quill.getLength();
                quill.clipboard.dangerouslyPasteHTML(insertIndex, data.html);
                triggerAutoSave();
            } else {
                alert("Clip failed: " + data.error);
            }
        })
        .catch(err => { console.error(err); alert("Network error clipping webpage."); })
        .finally(() => { setTimeout(() => { statusEl.classList.remove('visible'); }, 2000); });
    }

    window.uploadEditorFile = function(input) {
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        const formData = new FormData();
        formData.append('file', file);

        statusEl.innerText = 'Uploading...';
        statusEl.classList.add('visible');

        fetch('api_upload.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                let range = quill.getSelection();
                let insertIndex = range ? range.index : quill.getLength();
                if (data.is_image) quill.insertEmbed(insertIndex, 'image', data.url);
                else quill.insertText(insertIndex, '📄 ' + data.name, 'link', data.url);
                quill.setSelection(insertIndex + (data.is_image ? 1 : data.name.length + 3));
                triggerAutoSave();
            } else {
                alert("Upload failed: " + data.error);
            }
        })
        .catch(err => { console.error(err); alert("Network error during upload."); })
        .finally(() => { 
            input.value = ''; 
            setTimeout(() => { statusEl.classList.remove('visible'); }, 2000); 
        });
    }
</script>

<?php include 'includes/footer.php'; ?>