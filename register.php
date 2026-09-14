<?php
// public/register.php
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

$message = '';
$message_type = 'danger'; // Default to danger style for errors, changes to success if registration works

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if ($email && strlen($password) >= 8) {
        $db = new Database(); $pdo = $db->getConnection();
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (:email, :password_hash)");
            // --- START ADMIN EMAIL NOTIFICATION ---
            try {
                // Fetch all administrator emails
                $stmt_admins = $pdo->prepare("SELECT email FROM users WHERE role = 'admin'");
                $stmt_admins->execute();
                $admins = $stmt_admins->fetchAll();

                if (!empty($admins)) {
                    require_once 'includes/mailer.php';
                    
                    // Build the link to the Admin Panel
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                    $admin_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/admin/users.php";
                    
                    $subject = "[PersonalApps] New User Signup Awaiting Approval";
                    
                    $user_email_display = isset($email) ? $email : $_POST['email']; 
                    
                    $html_message = "
                        <div style='font-family: sans-serif; padding: 20px; background: #0f172a; color: #f8fafc; border-radius: 8px;'>
                            <h3 style='color: #eab308;'>Action Required: New Registration</h3>
                            <p>A new user account has just been created.</p>
                            <p><strong>User Email:</strong> " . htmlspecialchars($user_email_display) . "</p>
                            <a href='{$admin_url}' style='display: inline-block; padding: 10px 20px; background: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 15px 0;'>Review in Admin Panel</a>
                        </div>
                    ";
                    
                    // Loop through all admins and send the alert
                    foreach ($admins as $admin) {
                        send_memo_email($admin['email'], $subject, $html_message);
                    }
                }
            } catch (Exception $e) {
                // Silently fail the email so it doesn't break the user's registration process
                error_log("Failed to send admin notification: " . $e->getMessage());
            }
            // --- END ADMIN EMAIL NOTIFICATION ---
            
            $stmt->execute([':email' => $email, ':password_hash' => $password_hash]);
            log_audit_action($pdo, $pdo->lastInsertId(), 'USER_REGISTERED', 'Auth', ['email' => $email]);
            $message = "Registration successful! Pending admin approval.";
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = ($e->getCode() == 23000) ? "Email already exists." : "Registration error.";
        }
    } else {
        $message = "Valid email and 8+ character password required.";
    }
}

$page_title = "Register - PersonalApps";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --accent: #3b82f6;
            --danger: #ef4444;
            --success: #22c55e;
        }
        .light-theme {
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
            --accent: #2563eb;
            --danger: #dc2626;
            --success: #16a34a;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            box-sizing: border-box;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        .card h2 {
            font-size: 1.6em;
            margin-top: 0;
            margin-bottom: 5px;
            font-weight: 800;
            color: var(--text-main);
            text-align: center;
        }
        .card h2 span {
            color: var(--accent);
        }
        label {
            display: block;
            font-size: 0.85em;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 5px;
            margin-top: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 12px;
            border-radius: 6px;
            box-sizing: border-box;
            font-family: inherit;
            font-size: 1em;
        }
        input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 12px 18px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            width: 100%;
            margin-top: 25px;
            font-size: 1em;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        
        .msg-danger {
            color: var(--danger);
            font-weight: bold;
            text-align: center;
            background: rgba(239,68,68,0.1);
            padding: 10px;
            border-radius: 6px;
            border: 1px solid var(--danger);
            margin-bottom: 20px;
        }
        .msg-success {
            color: var(--success);
            font-weight: bold;
            text-align: center;
            background: rgba(34,197,94,0.1);
            padding: 10px;
            border-radius: 6px;
            border: 1px solid var(--success);
            margin-bottom: 20px;
        }
    </style>
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-theme');
        }
    </script>
</head>
<body>

<div class="login-container">
    <div class="card">
        <h2>Personal<span>Apps</span></h2>
        <p style="text-align: center; color: var(--text-muted); margin-bottom: 25px; margin-top: 0;">Create Account</p>
        
        <?php if ($message): ?>
            <div class="<?= $message_type === 'success' ? 'msg-success' : 'msg-danger' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="name@example.com">
            
            <label>Password</label>
            <input type="password" name="password" required minlength="8" placeholder="••••••••">
            
            <button type="submit" class="btn">Register</button>
        </form>
        
        <div style="text-align: center; margin-top: 20px; font-size: 0.9em;">
            <a href="login.php">Already have an account? Log in here.</a>
        </div>
    </div>
</div>

</body>
</html>