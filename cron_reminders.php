<?php
// cron_reminders.php
// This script runs in the background via a server Cron Job to process due notifications.

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notification_functions.php';
require_once __DIR__ . '/includes/mailer.php';

$db = new Database();
$pdo = $db->getConnection();

// Lock timezone to match your local data entry
date_default_timezone_set('America/Chicago');
$now = date('Y-m-d H:i:s');

echo "Running Reminder Check at: $now\n";

try {
    // 1. Process Tasks
    $stmt_tasks = $pdo->prepare("SELECT t.*, u.email FROM tasks t JOIN users u ON t.user_id = u.id WHERE t.reminder_date IS NOT NULL AND t.reminder_date <= :now AND t.is_reminded = 0 AND t.status != 'completed'");
    $stmt_tasks->execute([':now' => $now]);
    
    foreach ($stmt_tasks->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $pdo->prepare("UPDATE tasks SET is_reminded = 1 WHERE id = ?")->execute([$task['id']]);
        
        try { add_notification($pdo, $task['user_id'], 'Task Reminder', "🔔 Reminder: " . $task['title']); } catch (Exception $e) {}
        
        if (function_exists('send_memo_email')) {
            // Format the task date nicely, or fallback to 'None' if it is empty
            $due_display = !empty($task['due_date']) ? date('F j, Y, g:i A', strtotime($task['due_date'])) : 'None';
            
            $subject = "Task Reminder: " . $task['title'];
            $body = "You have an upcoming task reminder:<br><br><strong>Title:</strong> " . htmlspecialchars($task['title']) . "<br><strong>Due Date/Time:</strong> " . $due_display . "<br><br>Log in to PersonalApps to view it.";
            
            send_memo_email($task['email'], $subject, $body);
        }
        echo "Processed Task: " . $task['title'] . "\n";
    }
    
    // 2. Process Meetings
    $stmt_meetings = $pdo->prepare("SELECT m.*, u.email FROM meetings m JOIN users u ON m.user_id = u.id WHERE m.reminder_date IS NOT NULL AND m.reminder_date <= :now AND m.is_reminded = 0");
    $stmt_meetings->execute([':now' => $now]);
    
    foreach ($stmt_meetings->fetchAll(PDO::FETCH_ASSOC) as $meeting) {
        $pdo->prepare("UPDATE meetings SET is_reminded = 1 WHERE id = ?")->execute([$meeting['id']]);
        
        try { add_notification($pdo, $meeting['user_id'], 'Meeting Reminder', "🔔 Upcoming Meeting: " . $meeting['title']); } catch (Exception $e) {}
        
        if (function_exists('send_memo_email')) {
            $subject = "Meeting Reminder: " . $meeting['title'];
            $body = "You have an upcoming meeting:<br><br><strong>Title:</strong> " . htmlspecialchars($meeting['title']) . "<br><strong>Date/Time:</strong> " . date('F j, Y, g:i A', strtotime($meeting['meeting_date'])) . "<br><br>Log in to PersonalApps to view it.";
            
            send_memo_email($meeting['email'], $subject, $body);
        }
        echo "Processed Meeting: " . $meeting['title'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Check complete.\n";
?>