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

// --- AUTO-HEAL DATABASE SCHEMA ---
try {
    $pdo->exec("ALTER TABLE notes ADD COLUMN category VARCHAR(100) DEFAULT 'Uncategorized'");
} catch (Exception $e) {
    // Column likely already exists
}

// Fetch preset categories from the global task_categories table to keep Notes & Tasks synced
$categories = [];
$cat_colors = [];
try {
    $stmt_cats = $pdo->prepare("SELECT id, name, color FROM task_categories ORDER BY name ASC");
    $stmt_cats->execute();
    $categories = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categories as $c) {
        $cat_colors[$c['name']] = $c['color'];
    }
} catch (Exception $e) {}

// Handle POST actions for Creating, Updating, and Deleting Notes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_note') {
            $note_id = !empty($_POST['note_id']) ? (int)$_POST['note_id'] : null;
            $title = trim($_POST['title'] ?? 'Untitled Note');
            $category = trim($_POST['category'] ?? 'Uncategorized');
            $content_raw = trim($_POST['content'] ?? '');

            $content = $content_raw;
            if (!empty($content_raw)) {
                $decoded = base64_decode($content_raw, true);
                if ($decoded !== false) {
                    $content = $decoded;
                }
            }

            if (!empty($title) || !empty($content)) {
                if ($note_id) {
                    $stmt = $pdo->prepare("UPDATE notes SET title = :title, content = :content, category = :category WHERE id = :id AND user_id = :uid");
                    $stmt->execute([':title' => $title, ':content' => $content, ':category' => $category, ':id' => $note_id, ':uid' => $user_id]);
                    log_audit_action($pdo, $user_id, 'UPDATE_NOTE', 'Notes', ['note_id' => $note_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, category) VALUES (:uid, :title, :content, :category)");
                    $stmt->execute([':uid' => $user_id, ':title' => $title, ':content' => $content, ':category' => $category]);
                    $note_id = $pdo->lastInsertId();
                    log_audit_action($pdo, $user_id, 'CREATE_NOTE', 'Notes', ['note_id' => $note_id]);
                }
                header("Location: notes.php");
                exit();
            } else {
                $message = "Note title or content cannot be empty.";
            }
        } elseif ($action === 'delete_note') {
            $note_id = (int)$_POST['note_id'];
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $note_id, ':uid' => $user_id]);
            log_audit_action($pdo, $user_id, 'DELETE_NOTE', 'Notes', ['note_id' => $note_id]);
            header("Location: notes.php");
            exit();
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Fetch all notes for the logged-in user
$stmt = $pdo->prepare("SELECT id, title, content, category, created_at FROM notes WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute([':uid' => $user_id]);
$notes = $stmt->fetchAll();

$notes_json = json_encode($notes);

$page_title = "Notes - PersonalApps";
include 'includes/header.php';
?>

<!-- Quill Rich Text Editor CSS & JS CDN -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

<style>
    /* Native Page Elements */
    input, textarea, select { color: var(--text-main) !important; background-color: var(--bg-color) !important; font-family: inherit; }
    input::placeholder, textarea::placeholder { color: var(--text-muted) !important; opacity: 0.7; }
    
    /* Quill Dark Mode Fixes & Mobile Scrolling Constraints */
    .ql-editor { color: var(--text-main) !important; background-color: var(--card-bg) !important; min-height: 250px; max-height: 50vh; overflow-y: auto; font-size: 16px; }
    .ql-editor.ql-blank::before { color: var(--text-muted) !important; font-style: normal; opacity: 0.7; }
    .ql-snow .ql-stroke { stroke: var(--text-main) !important; }
    .ql-snow .ql-fill { fill: var(--text-main) !important; }
    .ql-snow .ql-picker { color: var(--text-main) !important; }
    .ql-toolbar.ql-snow { background-color: var(--card-bg); border-color: var(--border-color) !important; border-top-left-radius: 8px; border-top-right-radius: 8px; position: sticky; top: 0; z-index: 10; }
    .ql-container.ql-snow { border-color: var(--border-color) !important; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; background-color: var(--card-bg); }

    /* Custom highlight none label */
    .ql-snow .ql-picker.ql-background .ql-picker-item[data-value=""]::before { content: "None"; }

    /* Custom Note Modal Overrides */
    .notes-modal { max-width: 900px !important; width: 100%; padding: 0 !important; overflow: hidden !important; }
    .modal-header-flex { display: flex; justify-content: space-between; align-items: center; padding: 20px 25px; border-bottom: 1px solid var(--border-color); background: var(--card-bg); flex-wrap: wrap; gap: 15px; }
    .close-modal { background: none; border: none; font-size: 1.5em; color: var(--text-muted); cursor: pointer; padding: 0; line-height: 1; margin-left: auto; }

    .modal-footer { display: flex; justify-content: space-between; align-items: center; padding: 15px 25px; border-top: 1px solid var(--border-color); background: var(--bg-color); gap: 15px; flex-wrap: wrap; }
    .modal-btn-group { display: flex; gap: 10px; flex-wrap: wrap; }
    .modal-right { margin-left: auto; }

    /* Dashboard Grid */
    .notes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
    .note-card-item { margin-bottom: 0; cursor: pointer; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s; }

    /* Search & Filter Bar */
    .notes-toolbar { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
    .notes-search { flex-grow: 1; padding: 10px 15px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-size: 1em; outline: none; transition: 0.2s; }
    .notes-search:focus { border-color: var(--accent); }
    .notes-filter { padding: 10px 15px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-size: 1em; outline: none; min-width: 180px; }

    /* Responsive Mobile UI Fixes */
    @media (max-width: 768px) {
        .notes-grid { grid-template-columns: 1fr; }
        .modal-header-flex { padding: 15px; }
        
        .modal-overlay { overflow-y: auto !important; align-items: flex-start !important; padding: 10px !important; }
        .modal-content.notes-modal { height: auto !important; max-height: none !important; margin: 0 auto 40px auto !important; overflow: visible !important; }
        
        .modal-footer { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 10px !important; padding: 15px !important; }
        .modal-btn-group { display: contents !important; }
        .modal-footer .btn { width: 100% !important; margin: 0 !important; }
        .modal-footer button[type="submit"] { grid-column: 1 / -1 !important; font-size: 1.1em !important; padding: 12px !important; background: var(--success); }
    }
</style>

<div class="page-header">
    <h2>My Notes</h2>
    <button onclick="openNoteModal()" class="btn">+ New Note</button>
</div>

<?php if ($message): ?>
    <div style="padding: 15px; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-radius: 8px; margin-bottom: 20px;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Search and Filter Toolbar -->
<div class="notes-toolbar">
    <input type="text" id="note-search" class="notes-search" placeholder="🔍 Search notes by title or content..." onkeyup="filterNotes()">
    <select id="note-category-filter" class="notes-filter" onchange="filterNotes()">
        <option value="">All Categories</option>
        <option value="Uncategorized">Uncategorized</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Notes Grid -->
<div class="notes-grid" id="notes-grid">
    <?php if (empty($notes)): ?>
        <p style="color: var(--text-muted); font-style: italic; grid-column: 1 / -1;" id="no-notes-msg">No notes found. Create your first note!</p>
    <?php endif; ?>

    <?php foreach ($notes as $note): 
        $preview = mb_substr(trim(strip_tags($note['content'])), 0, 120) . '...';
        $full_text = strip_tags($note['content']);
        $cat_display = htmlspecialchars($note['category'] ?? 'Uncategorized');
        
        // Match the note category string to the Tasks category color, fallback to default accent
        $cat_color = $cat_colors[$cat_display] ?? 'var(--accent)';
    ?>
        <div class="card note-card-item" 
             data-title="<?= htmlspecialchars(strtolower($note['title'])) ?>" 
             data-content="<?= htmlspecialchars(strtolower($full_text)) ?>" 
             data-category="<?= $cat_display ?>"
             style="border-left: 4px solid <?= htmlspecialchars($cat_color) ?>;"
             onclick="openNoteModal(<?= $note['id'] ?>)" 
             onmouseover="this.style.transform='translateY(-2px)'; this.style.borderColor='<?= htmlspecialchars($cat_color) ?>';" 
             onmouseout="this.style.transform='none'; this.style.borderColor='var(--border-color)';">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 0.75em; background: transparent; color: <?= htmlspecialchars($cat_color) ?>; border: 1px solid <?= htmlspecialchars($cat_color) ?>; padding: 3px 8px; border-radius: 4px; font-weight: bold;">
                    <?= $cat_display ?>
                </span>
                <span style="font-size: 0.75em; color: var(--text-muted);"><?= date('M j, Y', strtotime($note['created_at'])) ?></span>
            </div>
            <h4 style="margin: 0 0 10px 0; font-size: 1.1em; color: var(--text-main);"><?= htmlspecialchars($note['title'] ?: 'Untitled Note') ?></h4>
            <div style="font-size: 0.9em; color: var(--text-muted); line-height: 1.5; flex-grow: 1; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
                <?= htmlspecialchars($preview) ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal for Note Viewing/Editing -->
<div id="note-modal-overlay" class="modal-overlay">
    <div class="modal-content notes-modal">
        
        <form id="note-form" method="POST" style="display: flex; flex-direction: column; margin: 0; height: 100%;">
            <input type="hidden" name="action" value="save_note">
            <input type="hidden" name="note_id" id="editor-note-id" value="">
            <input type="hidden" name="content" id="hidden-content">

            <!-- Modal Header / Title Input / Category Selector -->
            <div class="modal-header-flex">
                <input type="text" name="title" id="editor-title" placeholder="Note Title..." required style="flex-grow: 1; min-width: 200px; border: none; background: transparent; font-size: 1.5em; font-weight: bold; color: var(--text-main); padding: 0; outline: none; box-shadow: none;">
                
                <select name="category" id="editor-category" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); font-size: 0.9em; outline: none;">
                    <option value="Uncategorized">Uncategorized</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="close-modal" onclick="closeNoteModal()">✕</button>
            </div>

            <!-- Quill Editor Container -->
            <div style="background: var(--card-bg); padding: 20px;">
                <div id="editor-container" style="border-radius: 6px;"></div>
            </div>

            <!-- Modal Footer / Actions -->
            <div class="modal-footer">
                <div class="modal-btn-group">
                    <button type="button" id="btn-delete" class="btn btn-danger" style="display: none;" onclick="deleteCurrentNote()">Delete</button>
                    <button type="button" id="btn-share" class="btn" style="display: none; background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="generateShareLink('note', document.getElementById('editor-note-id').value)">Share</button>
                </div>
                <div class="modal-btn-group modal-right">
                    <input type="file" id="file-upload-input" style="display: none;" onchange="uploadEditorFile(this)">
                    <button type="button" class="btn" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="document.getElementById('file-upload-input').click()">📎 Attach</button>
                    <button type="button" class="btn" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="closeNoteModal()">Cancel</button>
                    <button type="submit" class="btn">Save Note</button>
                </div>
            </div>
        </form>

        <!-- Hidden Delete Form -->
        <form id="delete-form" method="POST" style="display: none;">
            <input type="hidden" name="action" value="delete_note">
            <input type="hidden" name="note_id" id="delete-note-id" value="">
        </form>

    </div>
</div>

<script>
    const notesData = <?= $notes_json ?: '[]' ?>;
    
    // Configure custom color palette including a "None" clear option for backgrounds
    const colors = ['#000000', '#e60000', '#ff9900', '#ffff00', '#008a00', '#0066cc', '#9933ff', '#ffffff', '#facccc', '#ffebcc', '#ffffcc', '#cce8cc', '#cce0f5', '#ebd6ff', '#bbbbbb', '#f06666', '#ffc266', '#ffff66', '#66b966', '#66a3e3', '#c285ff', '#888888', '#a10000', '#b26b00', '#b2b200', '#006100', '#0047b2', '#6b24b2', '#444444', '#5c0000', '#663d00', '#666600', '#003700', '#002966', '#3d1466', false];

    var quill = new Quill('#editor-container', {
        theme: 'snow',
        placeholder: 'Write your note here...',
        modules: {
            toolbar: {
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
            }
        }
    });

    // Real-Time Search & Filter Logic
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

        // Toggle "No Notes Found" message if search turns up empty
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

    window.uploadEditorFile = function(input) {
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        const formData = new FormData();
        formData.append('file', file);

        const attachBtn = input.nextElementSibling;
        const originalText = attachBtn.innerHTML;
        attachBtn.innerHTML = '⏳...';
        attachBtn.disabled = true;

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
                appAlert("Upload failed: " + data.error);
            }
        })
        .catch(err => { console.error(err); appAlert("Network error during upload."); })
        .finally(() => { 
            input.value = ''; 
            attachBtn.innerHTML = originalText; 
            attachBtn.disabled = false; 
        });
    }

    document.getElementById('note-form').addEventListener('submit', function(e) {
        var rawHtml = quill.root.innerHTML;
        document.getElementById('hidden-content').value = btoa(unescape(encodeURIComponent(rawHtml)));
    });

    function openNoteModal(id = null) {
        document.getElementById('note-modal-overlay').style.display = 'flex';
        
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
                
                quill.root.innerHTML = contentToLoad;
                document.getElementById('btn-delete').style.display = 'inline-block';
                document.getElementById('btn-share').style.display = 'inline-block';
            }
        } else {
            document.getElementById('editor-note-id').value = '';
            document.getElementById('editor-title').value = '';
            document.getElementById('editor-category').value = 'Uncategorized';
            quill.root.innerHTML = '';
            document.getElementById('btn-delete').style.display = 'none';
            document.getElementById('btn-share').style.display = 'none';
        }
    }

    function closeNoteModal() {
        document.getElementById('note-modal-overlay').style.display = 'none';
    }

    async function deleteCurrentNote() {
        const id = document.getElementById('editor-note-id').value;
        if (id) {
            const confirmed = await appConfirm('Are you sure you want to permanently delete this note?');
            if (confirmed) {
                document.getElementById('delete-note-id').value = id;
                document.getElementById('delete-form').submit();
            }
        }
    }
</script>

<?php include 'includes/footer.php'; ?>