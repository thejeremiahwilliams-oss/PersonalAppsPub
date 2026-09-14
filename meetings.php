<?php
// public/meetings.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';
require_once 'includes/notification_functions.php';

require_login();
$user_id = $_SESSION['user_id'];
$db = new Database();
$pdo = $db->getConnection();
$message = '';

// Auto-Heal Database Schema: Safely attempt to add missing columns if they were missed
try {
    $pdo->exec("ALTER TABLE meetings ADD COLUMN reminder_date DATETIME NULL DEFAULT NULL");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE meetings ADD COLUMN is_reminded TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_meeting') {
            $meeting_id = !empty($_POST['meeting_id']) ? (int)$_POST['meeting_id'] : null;
            $title = trim($_POST['title']);
            $notes = trim($_POST['notes'] ?? '');
            
            // Strictly format the HTML5 datetime string into MySQL format (YYYY-MM-DD HH:MM:SS)
            $meeting_date = !empty($_POST['meeting_date']) ? date('Y-m-d H:i:s', strtotime($_POST['meeting_date'])) : null;
            $reminder_date = !empty($_POST['reminder_date']) ? date('Y-m-d H:i:s', strtotime($_POST['reminder_date'])) : null;
            
            if (!empty($title) && !empty($meeting_date)) {
                if ($meeting_id) {
                    $stmt = $pdo->prepare("UPDATE meetings 
                                           SET title = :title, meeting_date = :mdate, reminder_date = :rdate, notes = :notes, is_reminded = 0 
                                           WHERE id = :id AND user_id = :uid");
                    $stmt->execute([
                        ':title' => $title, 
                        ':mdate' => $meeting_date, 
                        ':rdate' => $reminder_date, 
                        ':notes' => $notes, 
                        ':id' => $meeting_id, 
                        ':uid' => $user_id
                    ]);
                    log_audit_action($pdo, $user_id, 'UPDATE_MEETING', 'Meetings', ['meeting_id' => $meeting_id]);
                    $message = "Meeting updated successfully!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO meetings (user_id, title, meeting_date, reminder_date, notes, is_reminded) 
                                           VALUES (:uid, :title, :mdate, :rdate, :notes, 0)");
                    $stmt->execute([
                        ':uid' => $user_id, 
                        ':title' => $title, 
                        ':mdate' => $meeting_date, 
                        ':rdate' => $reminder_date, 
                        ':notes' => $notes
                    ]);
                    log_audit_action($pdo, $user_id, 'SCHEDULE_MEETING', 'Meetings', ['title' => $title]);
                }
                header("Location: meetings.php");
                exit();
            } else {
                $message = "Title and Date are required.";
            }
        } elseif ($action === 'delete_meeting') {
            $meeting_id = (int)$_POST['meeting_id'];
            $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $meeting_id, ':uid' => $user_id]);
            header("Location: meetings.php");
            exit();
        }
    } catch (Exception $e) {
        $message = "Database Error: " . $e->getMessage();
    }
}

$current_time = date('Y-m-d H:i:s');
$stmt_upcoming = $pdo->prepare("SELECT * FROM meetings WHERE user_id = :uid AND meeting_date >= :now ORDER BY meeting_date ASC");
$stmt_upcoming->execute([':uid' => $user_id, ':now' => $current_time]);
$upcoming_meetings = $stmt_upcoming->fetchAll();

$stmt_past = $pdo->prepare("SELECT * FROM meetings WHERE user_id = :uid AND meeting_date < :now ORDER BY meeting_date DESC LIMIT 10");
$stmt_past->execute([':uid' => $user_id, ':now' => $current_time]);
$past_meetings = $stmt_past->fetchAll();

$meetings_json = json_encode(array_merge($upcoming_meetings, $past_meetings));

$page_title = "Meetings - MemoMatrix";
include 'includes/header.php';
?>

<style>
    /* Native Page Elements */
    input, textarea, select { color: var(--text-main) !important; background-color: var(--bg-color) !important; font-family: inherit; }
    input::placeholder, textarea::placeholder { color: var(--text-muted) !important; opacity: 0.7; }
    
    /* Self-Contained Modal CSS */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: none; align-items: flex-start; justify-content: center; z-index: 1000; padding: 20px; overflow-y: auto; }
    .modal-content { background: var(--card-bg); padding: 30px; border-radius: 12px; width: 100%; max-width: 550px; margin: 40px auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid var(--border-color); box-sizing: border-box; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; }
    .modal-header h3 { margin: 0; font-size: 1.3em; color: var(--text-main); } 
    .close-modal { background: none; border: none; font-size: 1.5em; color: var(--text-muted); cursor: pointer; padding: 0; line-height: 1; }

    /* Form Grid Logic */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
    .form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 15px; }
    .form-group label { font-size: 0.85em; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin: 0; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; margin: 0; }
    
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px; }
    .modal-actions button { width: auto; padding: 10px 20px; }

    /* Responsive Mobile Stacking */
    @media (max-width: 768px) {
        .modal-overlay { padding: 10px; }
        .modal-content { padding: 20px; margin: 10px auto; }
        .form-grid { grid-template-columns: 1fr; gap: 0; }
        .modal-actions { flex-direction: column; }
        .modal-actions button { width: 100%; text-align: center; }
    }
</style>

<div class="page-header">
    <h2>Meeting Schedules</h2>
    <button onclick="openMeetingModal()" class="btn">+ Schedule Meeting</button>
</div>

<?php if ($message): ?>
    <div style="padding: 15px; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--danger);">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<h3 style="margin-top: 0; color: var(--accent); border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">Upcoming</h3>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 40px;">
    <?php if (empty($upcoming_meetings)): ?>
        <p style="color: var(--text-muted); font-style: italic;">No upcoming meetings scheduled.</p>
    <?php else: ?>
        <?php foreach ($upcoming_meetings as $m): ?>
            <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; gap: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.85em; color: var(--text-muted); font-weight: 600;">
                    <span><?= date('F j, Y', strtotime($m['meeting_date'])) ?></span>
                    <span style="color: var(--accent);"><?= date('g:i A', strtotime($m['meeting_date'])) ?></span>
                </div>
                <h4 style="margin: 0; font-size: 1.1em; color: var(--text-main);">
                    <?= htmlspecialchars($m['title']) ?>
                    <?php if (!empty($m['reminder_date']) && !$m['is_reminded']): ?>
                        <span title="Reminder Set" style="font-size: 0.85em; color: var(--warning); margin-left: 5px;">🔔</span>
                    <?php endif; ?>
                </h4>
                <?php if ($m['notes']): ?>
                    <p style="margin: 0; font-size: 0.9em; color: var(--text-muted); line-height: 1.4;"><?= nl2br(htmlspecialchars($m['notes'])) ?></p>
                <?php endif; ?>
                
                <div style="margin-top: auto; padding-top: 15px; border-top: 1px dashed var(--border-color); display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="openMeetingModal(<?= $m['id'] ?>)">Edit</button>
                    <button type="button" class="btn btn-small" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="generateShareLink('meeting', <?= $m['id'] ?>)">Share</button>
                    
                    <form method="POST" onsubmit="return confirm('Cancel this meeting?');" style="margin: 0; margin-left: auto;">
                        <input type="hidden" name="action" value="delete_meeting">
                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger">Cancel</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<h3 style="margin-top: 0; color: var(--text-muted); border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">Past Meetings</h3>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; opacity: 0.8;">
    <?php if (empty($past_meetings)): ?>
        <p style="color: var(--text-muted); font-style: italic;">No past meetings.</p>
    <?php else: ?>
        <?php foreach ($past_meetings as $m): ?>
            <div class="card" style="margin-bottom: 0; background: var(--bg-color); display: flex; flex-direction: column; gap: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.85em; color: var(--text-muted); font-weight: 600;">
                    <span><?= date('F j, Y', strtotime($m['meeting_date'])) ?></span>
                    <span><?= date('g:i A', strtotime($m['meeting_date'])) ?></span>
                </div>
                <h4 style="margin: 0; font-size: 1.1em; color: var(--text-muted);">
                    <?= htmlspecialchars($m['title']) ?>
                </h4>
                <?php if ($m['notes']): ?>
                    <p style="margin: 0; font-size: 0.9em; color: var(--text-muted); line-height: 1.4;"><?= nl2br(htmlspecialchars($m['notes'])) ?></p>
                <?php endif; ?>

                <div style="margin-top: auto; padding-top: 15px; border-top: 1px dashed var(--border-color); display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn btn-small" style="background: transparent; color: var(--accent); border: 1px solid var(--accent);" onclick="openMeetingModal(<?= $m['id'] ?>)">Edit</button>
                    <button type="button" class="btn btn-small" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="generateShareLink('meeting', <?= $m['id'] ?>)">Share</button>
                    
                    <form method="POST" onsubmit="return confirm('Delete record?');" style="margin: 0; margin-left: auto;">
                        <input type="hidden" name="action" value="delete_meeting">
                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger">Remove</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Container -->
<div id="new-meeting-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title">Schedule Meeting</h3>
            <button type="button" class="close-modal" onclick="closeMeetingModal()">✕</button>
        </div>
        
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="save_meeting">
            <input type="hidden" name="meeting_id" id="editor-meeting-id" value="">
            
            <div class="form-group">
                <label>Title / Agenda</label>
                <input type="text" name="title" id="editor-title" required>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Date & Time</label>
                    <input type="datetime-local" name="meeting_date" id="editor-date" required>
                </div>
                <div class="form-group">
                    <label style="color: var(--warning);">🔔 Reminder Time</label>
                    <input type="datetime-local" name="reminder_date" id="editor-reminder-date" style="border-color: var(--warning);">
                </div>
            </div>
            
            <div class="form-group">
                <label>Notes / Links</label>
                <textarea name="notes" id="editor-notes" rows="4"></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn" style="background: transparent; color: var(--text-main); border: 1px solid var(--border-color);" onclick="closeMeetingModal()">Cancel</button>
                <button type="submit" id="submit-btn" class="btn">Schedule</button>
            </div>
        </form>
    </div>
</div>

<script>
    const allMeetings = <?= $meetings_json ?: '[]' ?>;
    
    function openMeetingModal(id = null) {
        document.getElementById('new-meeting-modal').style.display = 'flex';
        
        if (id) {
            const meeting = allMeetings.find(m => m.id == id);
            if (meeting) {
                document.getElementById('editor-meeting-id').value = meeting.id;
                document.getElementById('editor-title').value = meeting.title;
                document.getElementById('editor-date').value = meeting.meeting_date ? meeting.meeting_date.replace(' ', 'T').substring(0, 16) : '';
                document.getElementById('editor-reminder-date').value = meeting.reminder_date ? meeting.reminder_date.replace(' ', 'T').substring(0, 16) : '';
                document.getElementById('editor-notes').value = meeting.notes || '';
                document.getElementById('modal-title').innerText = 'Edit Meeting';
                document.getElementById('submit-btn').innerText = 'Update Meeting';
            }
        } else {
            document.getElementById('editor-meeting-id').value = '';
            document.getElementById('editor-title').value = '';
            
            // Format current date for the input
            const now = new Date(); 
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('editor-date').value = now.toISOString().slice(0, 16);
            
            document.getElementById('editor-reminder-date').value = '';
            document.getElementById('editor-notes').value = '';
            document.getElementById('modal-title').innerText = 'Schedule Meeting';
            document.getElementById('submit-btn').innerText = 'Schedule';
        }
    }
    
    function closeMeetingModal() { 
        document.getElementById('new-meeting-modal').style.display = 'none'; 
    }
</script>

<?php include 'includes/footer.php'; ?>