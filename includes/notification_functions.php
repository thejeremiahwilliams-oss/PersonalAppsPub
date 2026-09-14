<?php
// includes/notification_functions.php
require_once __DIR__ . '/mailer.php';

/**
 * Dispatches an internal notification and optionally sends an email.
 */
function add_notification($pdo, $user_id, $title, $message) {
    try {
        // 1. Insert into database notification center
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (:user_id, :title, :message)");
        $stmt->execute([':user_id' => $user_id, ':title' => $title, ':message' => $message]);

        // 2. Check if user wants email notifications
        $stmt_user = $pdo->prepare("SELECT email, notification_email, email_notifications FROM users WHERE id = :id");
        $stmt_user->execute([':id' => $user_id]);
        $user = $stmt_user->fetch();

        if ($user && $user['email_notifications'] == 1) {
            $target_email = !empty($user['notification_email']) ? $user['notification_email'] : $user['email'];
            
            $html_body = "
                <div style='font-family: sans-serif; padding: 20px; background: #0f172a; color: #f8fafc; border-radius: 8px;'>
                    <h3 style='color: #3b82f6; margin-top: 0;'>PersonalApps Alert</h3>
                    <h4 style='margin-bottom: 10px;'>" . htmlspecialchars($title) . "</h4>
                    <p style='color: #94a3b8; line-height: 1.5;'>" . nl2br(htmlspecialchars($message)) . "</p>
                    <hr style='border: 0; border-top: 1px solid #334155; margin: 20px 0;'>
                    <p style='font-size: 0.8em; color: #64748b;'>You received this because email notifications are enabled in your PersonalApps settings.</p>
                </div>
            ";
            
            send_memo_email($target_email, "[PersonalApps] " . $title, $html_body);
        }
    } catch (PDOException $e) {
        // Fail silently
    }
}