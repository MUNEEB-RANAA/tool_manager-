<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

// Enforce auth
$currentUser = requireAuthWeb();
$pdo = getDBConnection();

// Fetch all projects the user is a member or owner of
$stmtProjects = $pdo->prepare("
    SELECT 
        p.*,
        u.username AS owner_name,
        u.avatar_color AS owner_avatar_color,
        (SELECT COUNT(*) FROM `tasks` t 
         JOIN `columns` c ON t.column_id = c.id 
         WHERE c.project_id = p.id) AS total_tasks,
        (SELECT COUNT(*) FROM `tasks` t 
         JOIN `columns` c ON t.column_id = c.id 
         WHERE c.project_id = p.id AND LOWER(c.name) LIKE '%done%') AS done_tasks
    FROM `projects` p
    JOIN `users` u ON p.owner_id = u.id
    LEFT JOIN `project_members` pm ON p.id = pm.project_id
    WHERE p.owner_id = :uid1 OR pm.user_id = :uid2
    GROUP BY p.id
    ORDER BY p.updated_at DESC
");
$stmtProjects->execute([
    ':uid1' => $currentUser['id'],
    ':uid2' => $currentUser['id']
]);
$projects = $stmtProjects->fetchAll();

// Project Members map for avatars display (Secure Prepared Query)
$projectIds = array_column($projects, 'id');
$projectMembersMap = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $stmtMembers = $pdo->prepare("
        SELECT pm.project_id, u.id, u.username, u.avatar_color, pm.role
        FROM `project_members` pm
        JOIN `users` u ON pm.user_id = u.id
        WHERE pm.project_id IN ($placeholders)
        ORDER BY pm.role ASC, u.username ASC
    ");
    $stmtMembers->execute($projectIds);
    while ($row = $stmtMembers->fetch()) {
        $projectMembersMap[$row['project_id']][] = $row;
    }
}

// User-level statistics
$totalProjects = count($projects);

// Count pending tasks assigned to current user across all projects
$stmtAssigned = $pdo->prepare("
    SELECT COUNT(t.id) 
    FROM `task_assignments` ta
    JOIN `tasks` t ON ta.task_id = t.id
    JOIN `columns` c ON t.column_id = c.id
    WHERE ta.user_id = :uid AND LOWER(c.name) NOT LIKE '%done%'
");
$stmtAssigned->execute([':uid' => $currentUser['id']]);
$pendingAssignedTasks = (int)$stmtAssigned->fetchColumn();

// Urgent tasks count
$stmtUrgent = $pdo->prepare("
    SELECT COUNT(t.id) 
    FROM `task_assignments` ta
    JOIN `tasks` t ON ta.task_id = t.id
    JOIN `columns` c ON t.column_id = c.id
    WHERE ta.user_id = :uid AND t.priority IN ('urgent', 'high') AND LOWER(c.name) NOT LIKE '%done%'
");
$stmtUrgent->execute([':uid' => $currentUser['id']]);
$urgentTasks = (int)$stmtUrgent->fetchColumn();

// Unread notifications count
$stmtNotifs = $pdo->prepare("
    SELECT COUNT(*) FROM `notifications` 
    WHERE `user_id` = :uid AND `is_read` = FALSE
");
$stmtNotifs->execute([':uid' => $currentUser['id']]);
$unreadNotifs = (int)$stmtNotifs->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace Dashboard | Tool Manager</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($currentUser['csrf_token']); ?>">
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="navbar">
        <a href="dashboard.php" class="nav-brand">
            <div class="brand-icon-wrap">
                <img src="assets/img/logo.svg" alt="Tool Manager">
            </div>
            <span class="brand-title-text">Tool Manager</span>
            <span class="brand-tag">Flow</span>
        </a>

        <div class="nav-center">
            <span class="realtime-badge" id="realtime-status-pill">
                <span class="pulse-dot"></span> Live Synced
            </span>
        </div>

        <div class="nav-actions">
            <!-- Notifications Dropdown -->
            <div class="dropdown-wrapper">
                <button type="button" class="btn btn-secondary btn-icon" id="notif-dropdown-btn" title="Notifications" aria-label="Notifications" style="position:relative;">
                    <span>🔔</span>
                    <span class="notif-counter" id="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>">
                        <?php echo $unreadNotifs > 99 ? '99+' : $unreadNotifs; ?>
                    </span>
                </button>

                <div class="dropdown-menu" id="notif-dropdown-menu">
                    <div class="dropdown-header">
                        <span>Notifications</span>
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-mark-notifs-read" style="font-size:0.75rem; padding:0.2rem 0.4rem;">Mark all read</button>
                    </div>
                    <div class="notification-list" id="notif-list-container">
                        <!-- Loaded via JS -->
                    </div>
                </div>
            </div>

            <!-- Current User Badge & Profile Modal Opener -->
            <button type="button" class="btn btn-ghost" id="btn-user-profile" title="Account & Security Settings" style="padding:0.35rem 0.65rem; border-radius:var(--radius-md); display:flex; align-items:center; gap:0.65rem;">
                <div class="avatar" id="nav-user-avatar" style="background-color:<?php echo htmlspecialchars($currentUser['avatar_color']); ?>;">
                    <?php echo strtoupper(substr($currentUser['username'], 0, 2)); ?>
                </div>
                <div style="display:flex; flex-direction:column; text-align:left;">
                    <span id="nav-user-name" style="font-weight:700; font-size:0.875rem; line-height:1.2; color:var(--text-primary);"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                    <span style="font-size:0.725rem; color:var(--text-muted);">Settings ⚙️</span>
                </div>
            </button>

            <a href="logout.php" class="btn btn-ghost btn-sm" title="Sign out" style="color:var(--text-muted); margin-left:0.25rem;">
                Sign out
            </a>
        </div>
    </header>

    <!-- Main Dashboard Body -->
    <main class="main-content">
        <div class="container">

            <!-- Dashboard Welcome Banner -->
            <div class="dashboard-header">
                <div class="dashboard-title-group">
                    <h1>Workspace Dashboard</h1>
                    <p>Welcome back, <strong style="color:var(--text-primary);"><?php echo htmlspecialchars($currentUser['username']); ?></strong> 👋 — Orchestrate your projects with Tool Manager Flow</p>
                </div>
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <button type="button" class="btn btn-flow" id="btn-new-project">
                        <span style="font-size:1.1rem; line-height:1;">+</span>
                        <span>New Project</span>
                    </button>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">Total Projects</div>
                        <div class="stat-value"><?php echo $totalProjects; ?></div>
                    </div>
                    <div class="stat-icon" style="background:rgba(99,102,241,0.15); color:var(--primary);">📁</div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">Pending Assigned Tasks</div>
                        <div class="stat-value"><?php echo $pendingAssignedTasks; ?></div>
                    </div>
                    <div class="stat-icon" style="background:rgba(6,182,212,0.15); color:var(--accent-cyan);">⚡</div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">High Priority Tasks</div>
                        <div class="stat-value"><?php echo $urgentTasks; ?></div>
                    </div>
                    <div class="stat-icon" style="background:rgba(239,68,68,0.15); color:var(--danger);">🔥</div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">Flow Stream</div>
                        <div class="stat-value" style="font-size:1.35rem; color:#10b981;">Online</div>
                    </div>
                    <div class="stat-icon" style="background:rgba(16,185,129,0.15); color:var(--success);">🌐</div>
                </div>
            </div>

            <!-- Projects Section Header & Search Filter -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
                <h2 style="font-size:1.35rem; font-weight:700;">Active Projects</h2>
                <div style="width:280px;">
                    <input type="text" id="project-search" class="form-control" placeholder="🔍 Search projects..." style="padding:0.5rem 0.85rem;" aria-label="Search projects">
                </div>
            </div>

            <!-- Projects Grid -->
            <div class="projects-grid" id="projects-grid">
                <?php if (empty($projects)): ?>
                    <div style="grid-column: 1 / -1; background:var(--bg-surface); padding:3.5rem 2rem; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                        <div class="brand-icon-wrap" style="width:56px; height:56px; margin:0 auto 1.25rem;">
                            <img src="assets/img/logo.svg" alt="Tool Manager">
                        </div>
                        <h3 style="font-size:1.35rem; font-weight:800; margin-bottom:0.5rem;">No active projects</h3>
                        <p style="color:var(--text-secondary); margin-bottom:1.75rem; max-width:450px; margin-left:auto; margin-right:auto;">Get started by creating your first agile project board with Tool Manager.</p>
                        <button type="button" class="btn btn-flow" onclick="document.getElementById('btn-new-project').click();">
                            + Create New Project
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($projects as $p): 
                        $pid = (int)$p['id'];
                        $tot = (int)$p['total_tasks'];
                        $done = (int)$p['done_tasks'];
                        $progress = $tot > 0 ? (int)round(($done / $tot) * 100) : 0;
                        $isOwner = ((int)$p['owner_id'] === $currentUser['id']);
                        $members = $projectMembersMap[$pid] ?? [];
                        
                        // Check if current user is admin in this project
                        $isAdmin = $isOwner;
                        if (!$isAdmin) {
                            foreach ($members as $mem) {
                                if ((int)$mem['id'] === $currentUser['id'] && $mem['role'] === 'admin') {
                                    $isAdmin = true;
                                    break;
                                }
                            }
                        }
                    ?>
                        <div class="project-card" id="project-card-<?php echo $pid; ?>" data-project-id="<?php echo $pid; ?>" tabindex="0" role="link">
                            <div>
                                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:0.5rem;">
                                    <div style="flex:1;">
                                        <div style="display:flex; align-items:center; gap:0.45rem; margin-bottom:0.35rem;">
                                            <span class="badge" style="background:<?php echo $isOwner ? 'rgba(99,102,241,0.15)' : 'rgba(6,182,212,0.15)'; ?>; color:<?php echo $isOwner ? 'var(--primary)' : 'var(--accent-cyan)'; ?>; font-size:0.65rem;">
                                                <?php echo $isOwner ? 'Owner' : ($isAdmin ? 'Admin' : 'Member'); ?>
                                            </span>
                                        </div>
                                        <h3 class="project-title" style="font-size:1.15rem; font-weight:700; color:var(--text-primary); line-height:1.3;">
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </h3>
                                    </div>

                                    <!-- Action Buttons Group -->
                                    <div style="display:flex; align-items:center; gap:0.25rem;">
                                        <?php if ($isAdmin): ?>
                                            <button type="button" class="btn btn-ghost btn-sm btn-icon btn-edit-project" 
                                                    data-project-id="<?php echo $pid; ?>" 
                                                    data-project-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-project-desc="<?php echo htmlspecialchars($p['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="Edit Project Details" aria-label="Edit Project">
                                                <span>✏️</span>
                                            </button>
                                            <button type="button" class="btn btn-ghost btn-sm btn-icon btn-manage-roster" 
                                                    data-project-id="<?php echo $pid; ?>" 
                                                    data-project-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="Manage Team Roster" aria-label="Manage Team">
                                                <span>👥</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($isOwner): ?>
                                            <button type="button" class="btn btn-ghost btn-sm btn-icon btn-delete-project" 
                                                    data-project-id="<?php echo $pid; ?>" 
                                                    data-project-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="Delete Project" aria-label="Delete Project">
                                                <span style="color:var(--danger); font-size:0.95rem;">✕</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <p class="project-desc" style="color:var(--text-secondary); font-size:0.875rem; margin-top:0.6rem; line-height:1.45; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                                    <?php echo htmlspecialchars($p['description'] ?: 'No description provided.'); ?>
                                </p>
                            </div>

                            <div style="margin-top:1.5rem;">
                                <!-- Progress Bar -->
                                <div style="display:flex; justify-content:space-between; font-size:0.775rem; color:var(--text-muted); font-weight:600;">
                                    <span>PROGRESS</span>
                                    <span><?php echo $progress; ?>%</span>
                                </div>
                                <div class="progress-bar-wrap">
                                    <div class="progress-bar-fill" style="width:<?php echo $progress; ?>%;"></div>
                                </div>

                                <!-- Meta & Members Row -->
                                <div class="project-meta-row">
                                    <div style="display:flex; align-items:center; gap:0.65rem;">
                                        <div class="avatar-group">
                                            <?php foreach (array_slice($members, 0, 3) as $m): ?>
                                                <div class="avatar avatar-sm" style="background-color:<?php echo htmlspecialchars($m['avatar_color']); ?>;" title="<?php echo htmlspecialchars($m['username'] . ' (' . $m['role'] . ')'); ?>">
                                                    <?php echo strtoupper(substr($m['username'], 0, 2)); ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($members) > 3): ?>
                                                <div class="avatar avatar-sm" style="background:#334155;">+<?php echo count($members) - 3; ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <button type="button" class="btn btn-ghost btn-sm btn-invite-trigger" 
                                                data-project-id="<?php echo $pid; ?>" 
                                                data-project-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                style="padding:0.2rem 0.45rem; font-size:0.75rem;" title="Invite team member">
                                            + Invite
                                        </button>
                                    </div>

                                    <div style="font-weight:600; font-size:0.8rem; color:var(--text-secondary);">
                                        <span style="color:var(--accent-cyan);"><?php echo $done; ?></span> / <span><?php echo $tot; ?> tasks</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- =========================================================================
         MODALS
         ========================================================================= -->

    <!-- Modal 1: Create New Project -->
    <div class="modal-backdrop" id="modal-new-project">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Create New Project</h3>
                <button type="button" class="modal-close" id="close-modal-project">✕</button>
            </div>
            <form id="form-new-project">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new-proj-name">Project Name *</label>
                        <input type="text" id="new-proj-name" name="name" class="form-control" placeholder="e.g. NextGen Microservices Platform" required autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new-proj-desc">Description</label>
                        <textarea id="new-proj-desc" name="description" class="form-textarea" placeholder="Detail high-level objectives, architectural targets, or sprint goals..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-project">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2: Edit Project Name & Description -->
    <div class="modal-backdrop" id="modal-edit-project">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Edit Project Details</h3>
                <button type="button" class="modal-close" id="close-modal-edit-project">✕</button>
            </div>
            <form id="form-edit-project">
                <input type="hidden" id="edit-project-id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="edit-proj-name">Project Name *</label>
                        <input type="text" id="edit-proj-name" name="name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit-proj-desc">Description</label>
                        <textarea id="edit-proj-desc" name="description" class="form-textarea"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-edit-project">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 3: Invite Member to Project -->
    <div class="modal-backdrop" id="modal-invite-member">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Invite Member to <span id="invite-project-name" style="color:var(--primary);"></span></h3>
                <button type="button" class="modal-close" id="close-modal-invite">✕</button>
            </div>
            <form id="form-invite-member">
                <input type="hidden" id="invite-project-id" value="">
                <input type="hidden" id="invite-selected-user-id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="invite-user-search">Search Users (by username or email)</label>
                        <input type="text" id="invite-user-search" class="form-control" placeholder="Type at least 2 characters...">
                    </div>

                    <div id="invite-selected-user-badge" style="display:none; padding:0.65rem 0.85rem; background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.3); border-radius:var(--radius-md); font-size:0.85rem; margin-bottom:1rem; color:var(--text-primary);">
                    </div>

                    <div id="user-search-results" style="max-height:160px; overflow-y:auto; margin-bottom:1rem;"></div>

                    <div class="form-group">
                        <label class="form-label" for="invite-role-select">Assign Role</label>
                        <select id="invite-role-select" name="role" class="form-select">
                            <option value="member" selected>Member (Can view, create & move tasks)</option>
                            <option value="admin">Admin (Can manage columns & project settings)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-invite">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Member</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 4: Manage Team Roster & Roles -->
    <div class="modal-backdrop" id="modal-team-roster">
        <div class="modal-container modal-lg">
            <div class="modal-header">
                <h3 class="modal-title">Team Roster: <span id="roster-project-name" style="color:var(--primary);"></span></h3>
                <button type="button" class="modal-close" id="close-modal-roster">✕</button>
            </div>
            <div class="modal-body">
                <div id="roster-list-container" style="display:flex; flex-direction:column; gap:0.65rem;">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="close-btn-roster">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal 5: User Profile & Security Settings -->
    <div class="modal-backdrop" id="modal-user-profile">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Account & Security Settings</h3>
                <button type="button" class="modal-close" id="close-modal-profile">✕</button>
            </div>
            <form id="form-user-profile">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="profile-username">Username *</label>
                        <input type="text" id="profile-username" name="username" class="form-control" value="<?php echo htmlspecialchars($currentUser['username']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="profile-email">Email Address *</label>
                        <input type="email" id="profile-email" name="email" class="form-control" value="<?php echo htmlspecialchars($currentUser['email']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Avatar Badge Color</label>
                        <div style="display:flex; gap:0.65rem; margin-top:0.35rem;" id="avatar-color-picker">
                            <?php foreach (['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#06b6d4', '#8b5cf6', '#3b82f6'] as $color): ?>
                                <label style="cursor:pointer; display:flex; align-items:center;">
                                    <input type="radio" name="avatar_color" value="<?php echo $color; ?>" <?php echo $currentUser['avatar_color'] === $color ? 'checked' : ''; ?> style="display:none;">
                                    <span style="width:28px; height:28px; border-radius:50%; background:<?php echo $color; ?>; display:inline-block; border:2px solid <?php echo $currentUser['avatar_color'] === $color ? '#ffffff' : 'transparent'; ?>; box-shadow:0 0 8px rgba(0,0,0,0.4);" class="color-swatch" data-color="<?php echo $color; ?>"></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="border-top:1px solid var(--border-color); padding-top:1rem; margin-top:1rem;">
                        <h4 style="font-size:0.85rem; font-weight:700; color:var(--text-secondary); margin-bottom:0.75rem;">Change Password (leave blank to keep current)</h4>
                        
                        <div class="form-group">
                            <label class="form-label" for="profile-current-pass">Current Password</label>
                            <input type="password" id="profile-current-pass" name="current_password" class="form-control" placeholder="••••••••">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="profile-new-pass">New Password</label>
                            <input type="password" id="profile-new-pass" name="new_password" class="form-control" placeholder="Min 6 characters">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-profile">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-profile">Update Profile</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Global Client Scripts -->
    <script src="assets/js/api.js"></script>
    <script src="assets/js/dashboard.js"></script>
    <script src="assets/js/real_time.js"></script>
</body>
</html>
