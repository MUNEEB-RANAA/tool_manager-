<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$currentUser = requireAuthWeb();
$pdo = getDBConnection();

$projectId = (int)($_GET['id'] ?? 0);
if (!$projectId) {
    header('Location: dashboard.php');
    exit;
}

if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
    echo "<!DOCTYPE html><html><body style='background:#0a0c16;color:#f87171;font-family:sans-serif;padding:3.5rem;text-align:center;'>";
    echo "<h2>Access Denied</h2><p style='color:#94a3b8;margin:1rem 0;'>You do not have permission to view this project board.</p>";
    echo "<a href='dashboard.php' style='color:#6366f1;text-decoration:none;font-weight:600;'>← Return to Dashboard</a></body></html>";
    exit;
}

// Fetch Project Details
$stmt = $pdo->prepare("
    SELECT p.*, u.username AS owner_name, u.avatar_color AS owner_avatar
    FROM `projects` p
    JOIN `users` u ON p.owner_id = u.id
    WHERE p.id = :id
");
$stmt->execute([':id' => $projectId]);
$project = $stmt->fetch();

if (!$project) {
    header('Location: dashboard.php');
    exit;
}

$userRole = getProjectMemberRole($projectId, $currentUser['id'], $pdo);
$isOwner = ($userRole === 'owner');
$isAdmin = in_array($userRole, ['owner', 'admin'], true);

$project['user_role'] = $userRole;
$project['is_owner'] = $isOwner;
$project['is_admin'] = $isAdmin;

// Fetch Project Members
$stmtMembers = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.avatar_color, pm.role
    FROM `project_members` pm
    JOIN `users` u ON pm.user_id = u.id
    WHERE pm.project_id = :pid
    ORDER BY pm.role ASC, u.username ASC
");
$stmtMembers->execute([':pid' => $projectId]);
$members = $stmtMembers->fetchAll();

// Fetch Columns
$stmtCols = $pdo->prepare("
    SELECT id, name, position
    FROM `columns`
    WHERE project_id = :pid
    ORDER BY position ASC, id ASC
");
$stmtCols->execute([':pid' => $projectId]);
$columns = $stmtCols->fetchAll();

// Fetch All Tasks for this Project
$stmtTasks = $pdo->prepare("
    SELECT t.id, t.column_id, t.title, t.description, t.due_date, t.priority, t.position, t.created_at, t.updated_at,
           (SELECT COUNT(*) FROM `comments` c WHERE c.task_id = t.id) AS comments_count
    FROM `tasks` t
    JOIN `columns` col ON t.column_id = col.id
    WHERE col.project_id = :pid
    ORDER BY t.position ASC, t.id ASC
");
$stmtTasks->execute([':pid' => $projectId]);
$allTasks = $stmtTasks->fetchAll();

// Fetch Task Assignments
$stmtAssignments = $pdo->prepare("
    SELECT ta.task_id, u.id AS user_id, u.username, u.avatar_color
    FROM `task_assignments` ta
    JOIN `users` u ON ta.user_id = u.id
    JOIN `tasks` t ON ta.task_id = t.id
    JOIN `columns` c ON t.column_id = c.id
    WHERE c.project_id = :pid
");
$stmtAssignments->execute([':pid' => $projectId]);
$assignments = $stmtAssignments->fetchAll();

$assignmentsByTask = [];
foreach ($assignments as $a) {
    $assignmentsByTask[$a['task_id']][] = [
        'id'           => (int)$a['user_id'],
        'username'     => $a['username'],
        'avatar_color' => $a['avatar_color']
    ];
}

$tasksByColumn = [];
foreach ($columns as $c) {
    $tasksByColumn[$c['id']] = [];
}
foreach ($allTasks as $t) {
    $t['assignees'] = $assignmentsByTask[$t['id']] ?? [];
    $tasksByColumn[$t['column_id']][] = $t;
}
foreach ($columns as &$col) {
    $col['tasks'] = $tasksByColumn[$col['id']] ?? [];
}
unset($col);

// Unread notifications count
$stmtNotifs = $pdo->prepare("SELECT COUNT(*) FROM `notifications` WHERE user_id = :uid AND is_read = FALSE");
$stmtNotifs->execute([':uid' => $currentUser['id']]);
$unreadNotifs = (int)$stmtNotifs->fetchColumn();

// Serialize initial board state for instant hydration
$initialStateJson = json_encode([
    'project'      => $project,
    'members'      => $members,
    'columns'      => $columns,
    'last_updated' => $project['updated_at']
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['name']); ?> | Tool Manager</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($currentUser['csrf_token']); ?>">
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="stylesheet" href="assets/css/variables.css?v=<?php echo filemtime(__DIR__ . '/assets/css/variables.css'); ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo filemtime(__DIR__ . '/assets/css/layout.css'); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo filemtime(__DIR__ . '/assets/css/components.css'); ?>">
    <link rel="stylesheet" href="assets/css/kanban.css?v=<?php echo filemtime(__DIR__ . '/assets/css/kanban.css'); ?>">
    <link rel="stylesheet" href="assets/css/refinement.css?v=<?php echo filemtime(__DIR__ . '/assets/css/refinement.css'); ?>">
</head>
<body class="board-view">

    <!-- Top Navigation Bar -->
    <header class="navbar">
        <div class="nav-left">
            <a href="dashboard.php" class="nav-brand" title="Back to Projects Dashboard">
                <div class="brand-icon-wrap">
                    <img src="assets/img/logo.svg" alt="Tool Manager">
                </div>
                <span class="brand-title-text">Tool Manager</span>
            </a>
            <span class="nav-divider">/</span>
            <div class="nav-project-crumb">
                <span id="board-project-title" class="nav-project-name" title="<?php echo htmlspecialchars($project['name']); ?>">
                    <?php echo htmlspecialchars($project['name']); ?>
                </span>
                <span class="role-badge">
                    <?php echo $isOwner ? 'Owner' : ($isAdmin ? 'Admin' : 'Member'); ?>
                </span>
                <?php if ($isAdmin): ?>
                    <button type="button" class="btn btn-ghost btn-xs btn-icon" id="btn-edit-project-header" title="Rename or Edit Project" aria-label="Edit Project">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-center">
            <span class="realtime-badge" id="realtime-status-pill">
                <span class="pulse-dot"></span> Live
            </span>
        </div>

        <div class="nav-actions">
            <!-- Team Avatars Stack & Management -->
            <div style="display:flex; align-items:center; gap:0.35rem; margin-right:0.35rem;">
                <div class="avatar-group" id="board-avatar-group">
                    <?php foreach (array_slice($members, 0, 3) as $m): ?>
                        <div class="avatar avatar-sm" style="background-color:<?php echo htmlspecialchars($m['avatar_color']); ?>;" title="<?php echo htmlspecialchars($m['username']) . ' (' . $m['role'] . ')'; ?>">
                            <?php echo strtoupper(substr($m['username'], 0, 2)); ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($members) > 3): ?>
                        <div class="avatar avatar-sm" style="background:#262b3d;">+<?php echo count($members) - 3; ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($isAdmin): ?>
                    <button type="button" class="btn btn-secondary btn-xs" id="btn-manage-team-roster" title="Manage Members">
                        Team
                    </button>
                    <button type="button" class="btn btn-secondary btn-xs" id="btn-open-invite-board" title="Invite User">
                        + Invite
                    </button>
                <?php endif; ?>
            </div>

            <!-- Notifications Dropdown -->
            <div class="dropdown-wrapper">
                <button type="button" class="btn btn-ghost btn-xs btn-icon" id="notif-dropdown-btn" title="Notifications" aria-label="Notifications" style="position:relative; width:26px; height:26px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    <span class="notif-counter" id="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>">
                        <?php echo $unreadNotifs > 99 ? '99+' : $unreadNotifs; ?>
                    </span>
                </button>

                <div class="dropdown-menu" id="notif-dropdown-menu">
                    <div class="dropdown-header">
                        <span>Notifications</span>
                        <button type="button" class="btn btn-ghost btn-xs" id="btn-mark-notifs-read" style="font-size:0.7rem; padding:0.15rem 0.35rem;">Mark all read</button>
                    </div>
                    <div class="notification-list" id="notif-list-container">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>

            <!-- User Avatar & Profile Modal -->
            <button type="button" class="btn btn-ghost btn-xs" id="btn-user-profile" title="Account Settings" style="padding:0.15rem 0.35rem; display:flex; align-items:center; gap:0.35rem;">
                <div class="avatar avatar-sm" id="nav-user-avatar" style="background-color:<?php echo htmlspecialchars($currentUser['avatar_color']); ?>;">
                    <?php echo strtoupper(substr($currentUser['username'], 0, 2)); ?>
                </div>
            </button>

            <!-- Dashboard Link & Sign Out -->
            <div style="display:flex; align-items:center; gap:0.25rem; margin-left:0.25rem;">
                <a href="dashboard.php" class="btn btn-ghost btn-xs" title="Back to Dashboard">Dashboard</a>
                <a href="logout.php" class="btn btn-ghost btn-xs" title="Sign out" style="color:var(--text-muted);">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                </a>
            </div>
        </div>
    </header>

    <!-- Sub-Navbar: Filters & Board Actions Toolbar -->
    <div class="board-subnav">
        <div class="board-title-area">
            <!-- Search tasks input -->
            <div style="width:160px;">
                <input type="text" id="board-search-input" class="form-control" placeholder="Search tasks..." style="padding:0.3rem 0.6rem; font-size:0.75rem; height:28px;" aria-label="Search board tasks">
            </div>

            <!-- Priority Filter Dropdown -->
            <div style="width:105px;">
                <select id="board-priority-filter" class="form-select" style="padding:0.3rem 1.4rem 0.3rem 0.55rem; font-size:0.75rem; height:28px;" aria-label="Filter by priority">
                    <option value="all">Priority</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>

            <!-- Assignee Filter Dropdown -->
            <div style="width:115px;">
                <select id="board-assignee-filter" class="form-select" style="padding:0.3rem 1.4rem 0.3rem 0.55rem; font-size:0.75rem; height:28px;" aria-label="Filter by assignee">
                    <option value="all">Assignee</option>
                    <option value="me">Assigned to Me</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Due Filter Dropdown -->
            <div style="width:105px;">
                <select id="board-due-filter" class="form-select" style="padding:0.3rem 1.4rem 0.3rem 0.55rem; font-size:0.75rem; height:28px;" aria-label="Filter by due status">
                    <option value="all">Due Date</option>
                    <option value="overdue">Overdue</option>
                    <option value="today">Due Today</option>
                    <option value="upcoming">Upcoming</option>
                </select>
            </div>

            <button type="button" class="btn btn-ghost btn-xs" id="btn-clear-filters" style="display:none; color:var(--text-muted);" title="Reset filters">
                Clear ✕
            </button>
        </div>

        <div class="board-actions">
            <?php if ($isAdmin): ?>
                <button type="button" class="btn btn-secondary btn-xs" onclick="openNewColumnModal()" style="height:28px;">
                    <span>+ New Column</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Kanban Board Container (Horizontal Scroll) -->
    <main class="board-page-container">
        <div class="kanban-viewport" id="kanban-board-container" data-project-id="<?php echo $projectId; ?>" data-current-user-id="<?php echo $currentUser['id']; ?>">
            <!-- Columns rendered here and synced live via kanban.js -->
        </div>
    </main>

    <!-- =====================================================================
         MODALS
         ===================================================================== -->

    <!-- 1. Task Detail & Comments Modal -->
    <div class="modal-backdrop" id="modal-task-detail">
        <div class="modal-container modal-lg">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:0.65rem;">
                    <span class="badge" style="background:rgba(255,255,255,0.08); color:var(--text-secondary);" id="task-detail-column-name">Column</span>
                    <h3 class="modal-title">Task Details</h3>
                </div>
                <button type="button" class="modal-close" id="close-modal-task-detail">✕</button>
            </div>

            <div class="modal-body">
                <div class="task-modal-grid">
                    <!-- Main details column -->
                    <div class="task-modal-main">
                        <div class="form-group" style="margin-bottom:0.85rem;">
                            <label class="form-label" for="task-detail-title">Task Title *</label>
                            <input type="text" id="task-detail-title" class="form-control" style="font-size:1.05rem; font-weight:700;">
                        </div>

                        <div class="form-group" style="margin-bottom:0.85rem;">
                            <label class="form-label" for="task-detail-desc">Description</label>
                            <textarea id="task-detail-desc" class="form-textarea" style="min-height:95px;" placeholder="Add detailed specifications, checklist, or acceptance criteria..."></textarea>
                        </div>

                        <!-- Real-Time Comments Discussion -->
                        <div style="margin-top:1rem; border-top:1px solid var(--border-color); padding-top:1rem; display:flex; flex-direction:column; flex:1;">
                            <h4 style="font-size:0.925rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem;">
                                <span>Discussion & Activity</span>
                                <span class="pulse-dot" style="width:6px; height:6px;"></span>
                            </h4>

                            <!-- Comments Stream -->
                            <div class="comments-feed" id="task-comments-feed">
                                <!-- Populated dynamically -->
                            </div>

                            <!-- Post Comment Box -->
                            <form id="form-add-comment" style="margin-top:1rem; display:flex; gap:0.65rem;">
                                <input type="text" name="comment_text" class="form-control" placeholder="Write a comment..." autocomplete="off" required>
                                <button type="submit" class="btn btn-primary btn-sm" style="padding:0.55rem 1rem;">Post</button>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar settings column -->
                    <div class="task-modal-sidebar">
                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label" for="task-detail-priority">Priority</label>
                            <select id="task-detail-priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label" for="task-detail-due">Due Date</label>
                            <input type="date" id="task-detail-due" class="form-control">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Assigned Members</label>
                            <div id="task-detail-assignees-list" style="max-height:160px; overflow-y:auto; padding-right:0.25rem;">
                                <!-- Checkboxes populated dynamically -->
                            </div>
                        </div>

                        <div style="margin-top:auto; padding-top:1rem; border-top:1px solid var(--border-color);">
                            <button type="button" class="btn btn-danger btn-sm" id="btn-delete-task" style="width:100%;">
                                Delete Task
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancel-task-detail">Cancel</button>
                <button type="button" class="btn btn-primary" id="btn-save-task-detail">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- 2. Create Task Modal -->
    <div class="modal-backdrop" id="modal-new-task">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">New Task in <span id="new-task-column-name" style="color:var(--primary);"></span></h3>
                <button type="button" class="modal-close" id="close-modal-new-task">✕</button>
            </div>
            <form id="form-new-task">
                <input type="hidden" id="new-task-column-id" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new-task-title">Title *</label>
                        <input type="text" id="new-task-title" name="title" class="form-control" placeholder="e.g. Implement resilient circuit breaker" required autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new-task-desc">Description</label>
                        <textarea id="new-task-desc" name="description" class="form-textarea" placeholder="Describe the task specifications or acceptance criteria..."></textarea>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                        <div class="form-group">
                            <label class="form-label" for="new-task-priority">Priority</label>
                            <select id="new-task-priority" name="priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new-task-due">Due Date</label>
                            <input type="date" id="new-task-due" name="due_date" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Assign Members</label>
                        <div id="new-task-assignees-wrap" style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.35rem;">
                            <!-- Populated via JS -->
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-new-task">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. Create Column Modal -->
    <div class="modal-backdrop" id="modal-new-column">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Add New Column</h3>
                <button type="button" class="modal-close" id="close-modal-new-column">✕</button>
            </div>
            <form id="form-new-column">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="new-col-name">Column Name *</label>
                        <input type="text" id="new-col-name" name="name" class="form-control" placeholder="e.g. In Testing / QA Review" required autofocus>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-new-column">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Column</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4. Invite Member Modal -->
    <div class="modal-backdrop" id="modal-invite-member">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Invite Member to Project</h3>
                <button type="button" class="modal-close" id="close-modal-invite">✕</button>
            </div>
            <form id="form-invite-member">
                <input type="hidden" id="invite-project-id" value="<?php echo $projectId; ?>">
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
                        <label class="form-label" for="invite-role">Assign Role</label>
                        <select id="invite-role" name="role" class="form-select">
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

    <!-- 5. Edit Project Details Modal -->
    <div class="modal-backdrop" id="modal-edit-project">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Edit Project Details</h3>
                <button type="button" class="modal-close" id="close-modal-edit-project">✕</button>
            </div>
            <form id="form-edit-project">
                <input type="hidden" id="edit-project-id" value="<?php echo $projectId; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="edit-proj-name">Project Name *</label>
                        <input type="text" id="edit-proj-name" name="name" class="form-control" value="<?php echo htmlspecialchars($project['name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit-proj-desc">Description</label>
                        <textarea id="edit-proj-desc" name="description" class="form-textarea"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancel-modal-edit-project">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 6. Team Roster & Role Management Modal -->
    <div class="modal-backdrop" id="modal-team-roster">
        <div class="modal-container modal-lg">
            <div class="modal-header">
                <h3 class="modal-title">Team Roster: <span id="roster-project-name" style="color:var(--primary);"><?php echo htmlspecialchars($project['name']); ?></span></h3>
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

    <!-- 7. User Profile & Settings Modal -->
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

    <!-- Load Client Scripts in Sequence -->
    <script src="assets/js/api.js?v=<?php echo filemtime(__DIR__ . '/assets/js/api.js'); ?>"></script>
    <script src="assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard.js'); ?>"></script>
    <script src="assets/js/kanban.js?v=<?php echo filemtime(__DIR__ . '/assets/js/kanban.js'); ?>"></script>
    <script src="assets/js/real_time.js?v=<?php echo filemtime(__DIR__ . '/assets/js/real_time.js'); ?>"></script>

    <!-- Initial Board State Hydration & Deep-linking -->
    <script>
        const initialBoardPayload = <?php echo $initialStateJson; ?>;
        document.addEventListener('DOMContentLoaded', () => {
            if (window.renderBoard && initialBoardPayload) {
                renderBoard(initialBoardPayload);

                // Deep-link check for ?task=ID in URL
                const urlParams = new URLSearchParams(window.location.search);
                const linkedTaskId = urlParams.get('task');
                if (linkedTaskId && window.openTaskDetailModal) {
                    const tid = parseInt(linkedTaskId, 10);
                    if (tid) {
                        setTimeout(() => {
                            openTaskDetailModal(tid);
                            const card = document.getElementById(`task-card-${tid}`);
                            if (card) {
                                card.classList.add('task-card-highlighted');
                                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }, 150);
                    }
                }
            }
        });
    </script>
</body>
</html>
