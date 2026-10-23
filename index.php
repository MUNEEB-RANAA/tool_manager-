<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getDBConnection();
$error = '';
$success = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Security session expired or invalid CSRF token. Please refresh the page.';
    } else {
        $action = $_POST['auth_action'] ?? 'login';

        if ($action === 'login') {
            // Rate limiting: 5 attempts per 60 seconds
            if (!checkRateLimit('login', 5, 60)) {
                $error = 'Too many failed attempts. Please wait 60 seconds before trying again.';
            } else {
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';

                if (empty($email) || empty($password)) {
                    $error = 'Please enter both email and password.';
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :email LIMIT 1");
                    $stmt->execute([':email' => $email]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password_hash'])) {
                        resetRateLimit('login');
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['avatar_color'] = $user['avatar_color'] ?? '#6366f1';

                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $error = 'Invalid email or password.';
                    }
                }
            }
        } elseif ($action === 'register') {
            if (!checkRateLimit('register', 3, 60)) {
                $error = 'Too many registration requests. Please wait a minute.';
            } else {
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $confirm = $_POST['password_confirm'] ?? '';

                if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
                    $error = 'All fields are required.';
                } elseif (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
                    $error = 'Username must be between 3 and 50 characters.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please provide a valid email address.';
                } elseif (mb_strlen($password) < 6) {
                    $error = 'Password must be at least 6 characters.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } else {
                    // Check for existing user
                    $stmtCheck = $pdo->prepare("SELECT id FROM `users` WHERE `username` = :u OR `email` = :e LIMIT 1");
                    $stmtCheck->execute([':u' => $username, ':e' => $email]);

                    if ($stmtCheck->fetch()) {
                        $error = 'Username or email already exists.';
                    } else {
                        resetRateLimit('register');
                        $avatarColors = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#06b6d4', '#8b5cf6'];
                        $avatarColor = $avatarColors[array_rand($avatarColors)];
                        $hash = password_hash($password, PASSWORD_DEFAULT);

                        $stmtInsert = $pdo->prepare("
                            INSERT INTO `users` (`username`, `email`, `password_hash`, `avatar_color`, `created_at`)
                            VALUES (:u, :e, :p, :a, NOW())
                        ");
                        $stmtInsert->execute([
                            ':u' => $username,
                            ':e' => $email,
                            ':p' => $hash,
                            ':a' => $avatarColor
                        ]);

                        $userId = (int)$pdo->lastInsertId();

                        // Seed welcome notification
                        createNotification($userId, 'Welcome to Tool Manager! Create or join your first project.', 'dashboard.php', $pdo);

                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['username'] = $username;
                        $_SESSION['email'] = $email;
                        $_SESSION['avatar_color'] = $avatarColor;

                        header('Location: dashboard.php');
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Manager | Sign In</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="stylesheet" href="assets/css/variables.css?v=<?php echo filemtime(__DIR__ . '/assets/css/variables.css'); ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo filemtime(__DIR__ . '/assets/css/layout.css'); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo filemtime(__DIR__ . '/assets/css/components.css'); ?>">
    <style>
        .auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            background: #0b0c10;
        }

        .auth-container {
            width: 100%;
            max-width: 420px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.75rem 1.75rem;
            box-shadow: var(--shadow-lg);
        }

        .auth-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 1.25rem;
        }

        .auth-brand-logo {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background: #171a26;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.65rem;
        }

        .auth-brand-logo img {
            width: 22px;
            height: 22px;
            object-fit: contain;
        }

        .auth-tabs {
            display: flex;
            background: var(--bg-secondary);
            padding: 3px;
            border-radius: var(--radius-sm);
            margin-bottom: 1.25rem;
            border: 1px solid var(--border-color);
        }

        .auth-tab {
            flex: 1;
            text-align: center;
            padding: 0.45rem 0;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            border-radius: var(--radius-xs);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .auth-tab.active {
            background: var(--primary);
            color: #ffffff;
        }

        .auth-alert {
            padding: 0.6rem 0.85rem;
            border-radius: var(--radius-sm);
            font-size: 0.78rem;
            margin-bottom: 1rem;
        }

        .alert-danger {
            background: var(--danger-bg);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }

        .alert-success {
            background: var(--success-bg);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .demo-bar {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .demo-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            font-weight: 600;
            text-align: center;
        }

        .demo-pill-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.4rem 0.65rem;
            color: var(--text-primary);
            font-size: 0.76rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            text-align: left;
        }

        .demo-pill-btn:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.16);
        }
    </style>
    <link rel="stylesheet" href="assets/css/refinement.css?v=<?php echo filemtime(__DIR__ . '/assets/css/refinement.css'); ?>">
</head>
<body>
    <div class="auth-page">
        <div class="auth-container">
            <div class="auth-brand">
                <div class="auth-brand-logo">
                    <img src="assets/img/logo.svg" alt="Tool Manager">
                </div>
                <h1 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">Tool Manager</h1>
                <p style="color: var(--text-secondary); font-size: 0.78rem; margin-top: 0.15rem;">Sign in to your team workspace</p>
            </div>

            <!-- Tab Switcher -->
            <div class="auth-tabs">
                <div class="auth-tab active" id="tab-login">Sign In</div>
                <div class="auth-tab" id="tab-register">Create Account</div>
            </div>

            <!-- Alert Box -->
            <div id="auth-alert" class="auth-alert <?php echo !empty($error) ? 'alert-danger' : (!empty($success) ? 'alert-success' : ''); ?>" style="<?php echo (empty($error) && empty($success)) ? 'display:none;' : ''; ?>">
                <?php echo htmlspecialchars($error ?: $success, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <!-- 1. Sign In Form -->
            <form id="form-login" method="POST" action="index.php">
                <input type="hidden" name="auth_action" value="login">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">

                <div class="form-group">
                    <label class="form-label" for="login-email">Email Address</label>
                    <input type="email" id="login-email" name="email" class="form-control" placeholder="alex@example.com" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="login-password">Password</label>
                    <div class="input-with-toggle">
                        <input type="password" id="login-password" name="password" class="form-control" placeholder="••••••••" required>
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('login-password', this)" title="Show password" aria-label="Toggle password visibility">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-flow" style="width: 100%; padding: 0.55rem; margin-top: 0.35rem;">
                    Sign In
                </button>
            </form>

            <!-- 2. Registration Form -->
            <form id="form-register" method="POST" action="index.php" style="display: none;">
                <input type="hidden" name="auth_action" value="register">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">

                <div class="form-group">
                    <label class="form-label" for="reg-username">Username</label>
                    <input type="text" id="reg-username" name="username" class="form-control" placeholder="alex_dev" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg-email">Email Address</label>
                    <input type="email" id="reg-email" name="email" class="form-control" placeholder="alex@example.com" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg-password">Password</label>
                    <div class="input-with-toggle">
                        <input type="password" id="reg-password" name="password" class="form-control" placeholder="Min 6 characters" required>
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('reg-password', this)" title="Show password" aria-label="Toggle password visibility">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg-confirm">Confirm Password</label>
                    <div class="input-with-toggle">
                        <input type="password" id="reg-confirm" name="password_confirm" class="form-control" placeholder="Repeat password" required>
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('reg-confirm', this)" title="Show password" aria-label="Toggle password visibility">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-flow" style="width: 100%; padding: 0.55rem; margin-top: 0.35rem;">
                    Create Account
                </button>
            </form>

            <!-- Quick Demo Accounts for 1-Click Testing -->
            <div class="demo-bar">
                <div class="demo-title">Test Accounts (pass: password123)</div>
                <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                    <button type="button" class="demo-pill-btn" onclick="fillDemoUser('alex@example.com', 'password123')">
                        <span class="avatar avatar-sm" style="background:#4f46e5; width:18px; height:18px; font-size:0.55rem;">AD</span>
                        <span style="font-weight:600;">Alex Dev</span>
                        <span style="font-size:0.68rem; color:var(--text-muted); margin-left:auto;">Owner</span>
                    </button>
                    <button type="button" class="demo-pill-btn" onclick="fillDemoUser('sarah@example.com', 'password123')">
                        <span class="avatar avatar-sm" style="background:#ec4899; width:18px; height:18px; font-size:0.55rem;">SP</span>
                        <span style="font-weight:600;">Sarah PM</span>
                        <span style="font-size:0.68rem; color:var(--text-muted); margin-left:auto;">Admin</span>
                    </button>
                    <button type="button" class="demo-pill-btn" onclick="fillDemoUser('marcus@example.com', 'password123')">
                        <span class="avatar avatar-sm" style="background:#10b981; width:18px; height:18px; font-size:0.55rem;">ML</span>
                        <span style="font-weight:600;">Marcus Lead</span>
                        <span style="font-size:0.68rem; color:var(--text-muted); margin-left:auto;">Member</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/auth.js?v=<?php echo filemtime(__DIR__ . '/assets/js/auth.js'); ?>"></script>
</body>
</html>
