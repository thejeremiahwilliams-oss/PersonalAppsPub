<?php
// public/forgot_password.php
session_start();
require_once 'config/database.php';
require_once 'includes/mailer.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (!empty($email)) {
        $db = new Database();
        $pdo = $db->getConnection();
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate a secure, unique token that expires in 1 hour
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $update = $pdo->prepare("UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id");
            $update->execute([':token' => $token, ':expires' => $expires, ':id' => $user['id']]);
            
            // Build the reset link (Adjust domain/path if necessary)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $reset_link = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            // Send Email
            $subject = "[PersonalApps] Password Reset Request";
            $html_message = "
                <div style='font-family: sans-serif; padding: 20px; background: #0f172a; color: #f8fafc; border-radius: 8px;'>
                    <h3 style='color: #3b82f6;'>Password Reset</h3>
                    <p>We received a request to reset your PersonalApps password.</p>
                    <p>Click the button below to choose a new password. This link expires in 1 hour.</p>
                    <a href='{$reset_link}' style='display: inline-block; padding: 10px 20px; background: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 15px 0;'>Reset Password</a>
                    <p style='font-size: 0.8em; color: #94a3b8;'>If you did not request this, you can safely ignore this email.</p>
                </div>
            ";
            
            send_memo_email($user['email'], $subject, $html_message);
        }
        
        // Always show the same success message to prevent email enumeration (security best practice)
        $message = "If an account with that email exists, a password reset link has been sent.";
        $message_type = "success";
    } else {
        $message = "Please enter your email address.";
        $message_type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - PersonalApps</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; border: 1px solid #334155; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        input { width: 100%; padding: 12px; margin: 10px 0 20px; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 6px; box-sizing: border-box; }
        input:focus { outline: none; border-color: #3b82f6; }
        .btn { width: 100%; background: #3b82f6; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #2563eb; }
        .msg { padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9em; }
        .msg.success { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid #22c55e; }
        .msg.danger { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin-top: 0; text-align: center;">Reset Password</h2>
        
        <?php if ($message): ?>
            <div class="msg <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="Enter your login email">
            <button type="submit" class="btn">Send Reset Link</button>
        </form>
        
        <div style="text-align: center; margin-top: 20px; font-size: 0.9em;">
            <a href="login.php" style="color: #3b82f6; text-decoration: none;">Back to Login</a>
        </div>
    </div>
</body>
</html>