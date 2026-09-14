<?php
// public/login.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_once 'includes/audit_logger.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db = new Database(); $pdo = $db->getConnection();
        $stmt = $pdo->prepare("SELECT id, password_hash, role, status FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['status'] === 'pending') {
                $error = "Account pending admin approval.";
                log_audit_action($pdo, $user['id'], 'LOGIN_FAILED_PENDING', 'Auth');
            } elseif ($user['status'] === 'suspended') {
                $error = "Account suspended.";
                log_audit_action($pdo, $user['id'], 'LOGIN_FAILED_SUSPENDED', 'Auth');
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id']; $_SESSION['role'] = $user['role']; $_SESSION['email'] = $email;
                log_audit_action($pdo, $user['id'], 'LOGIN_SUCCESS', 'Auth');
                header("Location: index.php"); exit();
            }
        } else {
            $error = "Invalid credentials.";
            log_audit_action($pdo, 0, 'LOGIN_FAILED_CREDENTIALS', 'Auth', ['attempted_email' => $email]);
        }
    }
}

$page_title = "Login - PersonalApps";
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
        }
        .light-theme {
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
            --accent: #2563eb;
            --danger: #dc2626;
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
            color: #ffffff;
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
        <p style="text-align: center; color: var(--text-muted); margin-bottom: 25px; margin-top: 0;">System Login</p>
        
        <?php if ($error): ?>
            <p style="color: var(--danger); font-weight: bold; text-align: center; background: rgba(239,68,68,0.1); padding: 10px; border-radius: 6px; border: 1px solid var(--danger); margin-bottom: 20px;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <form method="POST">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="name@example.com">
            
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">
            
            <button type="submit" class="btn">Log In</button>
        </form>
        
        <div style="text-align: center; margin-top: 20px; font-size: 0.9em;">
            <a href="register.php">Need an account? Register here.</a>
        </div>
        <div style="text-align: center; margin-top: 10px; font-size: 0.9em;">
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </div>
</div>

</body>
</html>