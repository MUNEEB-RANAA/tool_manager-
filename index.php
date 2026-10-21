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
    $action = $_POST['auth_action'] ?? 'login';

    if ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
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
    } elseif ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
            $error = 'All fields are required.';
        } elseif (strlen($username) < 3) {
            $error = 'Username must be at least 3 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } elseif (strlen($password) < 6) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Manager | Enterprise Workflow & Project Management</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <style>
        .auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.5rem;
            position: relative;
            background: 
                radial-gradient(circle at 10% 20%, rgba(6, 182, 212, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(99, 102, 241, 0.1) 0%, transparent 45%),
                #090a10;
        }

        .auth-container {
            width: 100%;
            max-width: 1180px;
            display: grid;
            grid-template-columns: 1.18fr 0.82fr;
            background: rgba(18, 21, 36, 0.82);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: 0 30px 70px -15px rgba(0, 0, 0, 0.8), 0 0 35px rgba(99, 102, 241, 0.12);
            backdrop-filter: blur(24px);
        }

        /* Left Real Product Showcase Pane */
        .showcase-pane {
            padding: 2.75rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            background: linear-gradient(155deg, rgba(22, 26, 46, 0.7) 0%, rgba(13, 16, 28, 0.9) 100%);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }

        .showcase-pane::before {
            content: '';
            position: absolute;
            top: -120px;
            left: -120px;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.15) 0%, transparent 70%);
            pointer-events: none;
        }

        .showcase-brand {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            margin-bottom: 1.75rem;
        }

        .showcase-brand-logo {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: 0 0 18px rgba(6, 182, 212, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: #0f1322;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .showcase-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Real Interactive Product Window Mockup */
        .live-preview-window {
            background: #111422;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.65), 0 0 20px rgba(99, 102, 241, 0.1);
            margin: 1.5rem 0;
            position: relative;
        }

        .window-chrome {
            background: #171b2d;
            padding: 0.65rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .window-dots {
            display: flex;
            gap: 6px;
        }

        .window-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-red { background: #ef4444; }
        .dot-yellow { background: #f59e0b; }
        .dot-green { background: #10b981; }

        .window-title {
            font-size: 0.725rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .live-pulse {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
        }

        /* Mini Kanban Board Container inside Window */
        .preview-board-wrap {
            padding: 1rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
            background: rgba(14, 17, 30, 0.95);
        }

        .preview-column {
            background: rgba(22, 26, 46, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: var(--radius-md);
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }

        .preview-column-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.725rem;
            font-weight: 700;
            color: var(--text-secondary);
            padding-bottom: 0.4rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .preview-card {
            background: rgba(30, 35, 60, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            transition: transform 0.2s;
        }

        .preview-card:hover {
            transform: translateY(-2px);
            border-color: rgba(99, 102, 241, 0.4);
        }

        .preview-card-title {
            font-size: 0.825rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.3;
        }

        .preview-card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 0.2rem;
            padding-top: 0.45rem;
            border-top: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 0.7rem;
        }

        .preview-tag {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.15rem 0.45rem;
            border-radius: var(--radius-sm);
            text-transform: uppercase;
        }

        .tag-urgent { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); }
        .tag-high { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
        .tag-done { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }

        .showcase-features-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
            margin-top: 1.25rem;
        }

        .feature-card-mini {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: var(--radius-md);
            padding: 0.85rem 1rem;
        }

        .feature-card-mini .title {
            font-size: 0.825rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .feature-card-mini .desc {
            font-size: 0.725rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
            line-height: 1.35;
        }

        /* Right Auth Pane */
        .auth-card-pane {
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--bg-surface);
        }

        .auth-tabs {
            display: flex;
            background: var(--bg-secondary);
            padding: 4px;
            border-radius: var(--radius-md);
            margin-bottom: 1.75rem;
            border: 1px solid var(--border-color);
        }

        .auth-tab {
            flex: 1;
            text-align: center;
            padding: 0.65rem 0;
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-secondary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .auth-tab.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.45);
        }

        .auth-alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .alert-danger {
            background: var(--danger-bg);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .alert-success {
            background: var(--success-bg);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .demo-bar {
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border-color);
        }

        .demo-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
            font-weight: 700;
            text-align: center;
        }

        @media (max-width: 900px) {
            .auth-container {
                grid-template-columns: 1fr;
            }
            .showcase-pane {
                display: none;
            }
            .auth-card-pane {
                padding: 2.25rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-page">
        <div class="auth-container">
            <!-- Left Pane: Authentic Real UI Showcase -->
            <div class="showcase-pane">
                <div>
                    <div class="showcase-brand">
                        <div class="showcase-brand-logo">
                            <img src="assets/img/logo.svg" alt="Tool Manager Logo">
                        </div>
                        <div>
                            <div style="font-size:1.35rem; font-weight:800; font-family:'Outfit', sans-serif; line-height:1.1;">
                                <span class="brand-title-text">Tool Manager</span>
                            </div>
                            <div style="font-size:0.75rem; color:var(--accent-cyan); font-weight:600; letter-spacing:0.04em;">ENTERPRISE FLOW ENGINE</div>
                        </div>
                    </div>

                    <h2 style="font-size:1.7rem; font-weight:800; line-height:1.25; color:var(--text-primary); margin-bottom:0.75rem;">
                        Agile Project Orchestration with <span style="background:var(--gradient-flow); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">Seamless Flow</span>
                    </h2>
                    <p style="color:var(--text-secondary); font-size:0.875rem; line-height:1.5;">
                        Zero-lag Kanban collaboration, instant SSE multi-client synchronization, and automated project workflows built for high-performance engineering teams.
                    </p>

                    <!-- Real Product Window Mockup -->
                    <div class="live-preview-window">
                        <div class="window-chrome">
                            <div class="window-dots">
                                <div class="window-dot dot-red"></div>
                                <div class="window-dot dot-yellow"></div>
                                <div class="window-dot dot-green"></div>
                            </div>
                            <div class="window-title">
                                <span class="live-pulse"></span> toolmanager / sprint-24 / live-board
                            </div>
                            <div style="font-size:0.675rem; color:var(--accent-cyan); font-weight:700;">SSE CONNECTED</div>
                        </div>

                        <div class="preview-board-wrap">
                            <!-- Column 1: In Progress -->
                            <div class="preview-column">
                                <div class="preview-column-header">
                                    <span>In Progress</span>
                                    <span style="color:var(--accent-cyan); font-weight:800;">2</span>
                                </div>

                                <div class="preview-card">
                                    <div class="preview-card-title">Deploy real-time SSE event pipeline</div>
                                    <div style="font-size:0.725rem; color:var(--text-secondary);">Sub-second live streaming updates</div>
                                    <div class="preview-card-meta">
                                        <span class="preview-tag tag-urgent">Urgent</span>
                                        <div style="display:flex; align-items:center; gap:0.4rem;">
                                            <span style="color:var(--text-muted);">💬 4</span>
                                            <div class="avatar avatar-sm" style="background:#6366f1; width:20px; height:20px; font-size:0.55rem;">AD</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="preview-card">
                                    <div class="preview-card-title">Multi-region VPC peering & route tables</div>
                                    <div style="font-size:0.725rem; color:var(--text-secondary);">Terraform infrastructure target</div>
                                    <div class="preview-card-meta">
                                        <span class="preview-tag tag-high">High</span>
                                        <div style="display:flex; align-items:center; gap:0.4rem;">
                                            <span style="color:var(--text-muted);">💬 2</span>
                                            <div class="avatar avatar-sm" style="background:#10b981; width:20px; height:20px; font-size:0.55rem;">ML</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Column 2: Review & Done -->
                            <div class="preview-column">
                                <div class="preview-column-header">
                                    <span>Review & QA</span>
                                    <span style="color:#a855f7; font-weight:800;">1</span>
                                </div>

                                <div class="preview-card">
                                    <div class="preview-card-title">Audit zero-trust IAM permission boundaries</div>
                                    <div style="font-size:0.725rem; color:var(--text-secondary);">CI least-privilege policies</div>
                                    <div class="preview-card-meta">
                                        <span class="preview-tag tag-high">High</span>
                                        <div style="display:flex; align-items:center; gap:0.4rem;">
                                            <span style="color:var(--text-muted);">💬 5</span>
                                            <div class="avatar avatar-sm" style="background:#ec4899; width:20px; height:20px; font-size:0.55rem;">SP</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="preview-card" style="opacity:0.85;">
                                    <div class="preview-card-title" style="text-decoration:line-through; color:var(--text-secondary);">Containerize auth service</div>
                                    <div class="preview-card-meta">
                                        <span class="preview-tag tag-done">Done</span>
                                        <span style="color:#34d399; font-size:0.7rem;">✓ Verified</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Feature Mini Cards -->
                <div class="showcase-features-grid">
                    <div class="feature-card-mini">
                        <div class="title"><span>📋</span> Drag & Drop Flow</div>
                        <div class="desc">Optimistic HTML5 card movement with zero interface lag.</div>
                    </div>
                    <div class="feature-card-mini">
                        <div class="title"><span>⚡</span> Real-time Sync</div>
                        <div class="desc">Server-Sent Events propagate column and task edits instantly.</div>
                    </div>
                </div>
            </div>

            <!-- Right Pane: Sign In & Registration Card -->
            <div class="auth-card-pane">
                <div style="text-align: center; margin-bottom: 1.75rem;">
                    <div class="brand-icon-wrap" style="width:48px; height:48px; margin:0 auto 0.85rem; box-shadow:var(--shadow-glow); background:#0f1322;">
                        <img src="assets/img/logo.svg" alt="Tool Manager">
                    </div>
                    <h1 style="font-size: 1.6rem; font-weight: 800; color: var(--text-primary);">Tool Manager</h1>
                    <p style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.35rem;">Sign in to your collaborative workspace</p>
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

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="name@company.com" required autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-with-toggle">
                            <input type="password" id="login-password" name="password" class="form-control" placeholder="••••••••" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('login-password', this)" title="Show password">👁️‍🗨️</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-flow" style="width: 100%; padding: 0.75rem; margin-top: 0.5rem;">
                        Sign In to Workspace →
                    </button>
                </form>

                <!-- 2. Registration Form -->
                <form id="form-register" method="POST" action="index.php" style="display: none;">
                    <input type="hidden" name="auth_action" value="register">

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="alex_dev" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="alex@example.com" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-with-toggle">
                            <input type="password" id="reg-password" name="password" class="form-control" placeholder="Min 6 characters" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('reg-password', this)" title="Show password">👁️‍🗨️</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-with-toggle">
                            <input type="password" id="reg-confirm" name="password_confirm" class="form-control" placeholder="Repeat password" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('reg-confirm', this)" title="Show password">👁️‍🗨️</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-flow" style="width: 100%; padding: 0.75rem; margin-top: 0.5rem;">
                        Create Workspace Account →
                    </button>
                </form>

                <!-- Quick Demo Accounts for 1-Click Testing -->
                <div class="demo-bar">
                    <div class="demo-title">⚡ 1-Click Demo Accounts (pass: password123)</div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <button type="button" class="demo-pill-btn" onclick="fillDemoUser('alex@example.com', 'password123')">
                            <span class="avatar avatar-sm" style="background:#6366f1;">AD</span>
                            <span style="font-weight:600;">Alex Dev</span>
                            <span style="font-size:0.7rem; color:var(--text-muted); margin-left:auto;">Lead Owner</span>
                        </button>
                        <button type="button" class="demo-pill-btn" onclick="fillDemoUser('sarah@example.com', 'password123')">
                            <span class="avatar avatar-sm" style="background:#ec4899;">SP</span>
                            <span style="font-weight:600;">Sarah PM</span>
                            <span style="font-size:0.7rem; color:var(--text-muted); margin-left:auto;">Product Lead</span>
                        </button>
                        <button type="button" class="demo-pill-btn" onclick="fillDemoUser('marcus@example.com', 'password123')">
                            <span class="avatar avatar-sm" style="background:#10b981;">ML</span>
                            <span style="font-weight:600;">Marcus Lead</span>
                            <span style="font-size:0.7rem; color:var(--text-muted); margin-left:auto;">Tech Lead</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/auth.js"></script>
</body>
</html>
