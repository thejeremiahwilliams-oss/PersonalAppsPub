<?php
// public/reset_password.php
session_start();
require_once 'config/database.php';

$message = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$user_id = null;

$db = new Database();
$pdo = $db->getConnection();

// Verify the token
if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_expires > NOW()");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $valid_token = true;
        $user_id = $user['id'];
    } else {
        $message = "This password reset link is invalid or has expired.";
    }
} else {
    header("Location: login.php");
    exit();
}

// Handle the new password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (strlen($new_password) < 8) {
        $message = "Password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
    } else {
        // Hash the new password and clear the reset token
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password_hash = :password, reset_token = NULL, reset_expires = NULL WHERE id = :id");
        $update->execute([':password' => $hashed_password, ':id' => $user_id]);
        
        $_SESSION['success_msg'] = "Your password has been successfully reset. You may now log in.";
        header("Location: login.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose New Password - PersonalApps</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; border: 1px solid #334155; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        input { width: 100%; padding: 12px; margin: 10px 0 20px; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 6px; box-sizing: border-box; }
        input:focus { outline: none; border-color: #3b82f6; }
        .btn { width: 100%; background: #3b82f6; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #2563eb; }
        .msg { padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9em; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin-top: 0; text-align: center;">New Password</h2>
        
        <?php if ($message): ?>
            <div class="msg"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($valid_token): ?>
            <form method="POST">
                <label>New Password</label>
                <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters">
                
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required placeholder="Type it again">
                
                <button type="submit" class="btn">Update Password</button>
            </form>
        <?php else: ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="forgot_password.php" class="btn" style="display: block; text-decoration: none;">Request a new link</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>