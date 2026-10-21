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
    echo "<!DOCTYPE html><html><body style='background:#0f111a;color:#ff6b6b;font-family:sans-serif;padding:3rem;'><h2>Access Denied</h2><p>You do not have permission to view this project board.</p><p><a href='dashboard.php' style='color:#6366f1;'>← Return to Dashboard</a></p></body></html>";
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

$isOwner = ((int)$project['owner_id'] === $currentUser['id']);

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
    'project' => $project,
    'members' => $members,
    'columns' => $columns,
    'last_updated' => $project['updated_at']
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['name']); ?> | Tool Manager</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/kanban.css">
</head>
<body>

    <!-- Main Navigation Bar -->
    <header class="navbar">
        <div style="display:flex; align-items:center; gap:1.25rem;">
            <a href="dashboard.php" class="nav-brand">
                <div class="brand-icon-wrap">
                    <img src="assets/img/logo.svg" alt="Tool Manager">
                </div>
                <span class="brand-title-text">Tool Manager</span>
                <span class="brand-tag">Flow</span>
            </a>
            <span style="color:var(--text-muted); font-size:1.1rem;">/</span>
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <span style="font-weight:700; font-size:1rem; color:var(--text-primary);"><?php echo htmlspecialchars($project['name']); ?></span>
                <?php if ($isOwner): ?>
                    <span class="badge" style="background:rgba(99,102,241,0.15); color:var(--primary); font-size:0.65rem;">Owner</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-center">
            <span class="realtime-badge" id="realtime-status-pill">
                <span class="pulse-dot"></span> Live Synced
            </span>
        </div>

        <div class="nav-actions">
            <!-- Team Avatars Stack -->
            <div style="display:flex; align-items:center; gap:0.5rem; margin-right:0.5rem;">
                <div class="avatar-group">
                    <?php foreach (array_slice($members, 0, 4) as $m): ?>
                        <div class="avatar avatar-sm" style="background-color:<?php echo htmlspecialchars($m['avatar_color']); ?>;" title="<?php echo htmlspecialchars($m['username']) . ' (' . $m['role'] . ')'; ?>">
                            <?php echo strtoupper(substr($m['username'], 0, 2)); ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($members) > 4): ?>
                        <div class="avatar avatar-sm" style="background:#334155;">+<?php echo count($members) - 4; ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openInviteModal(<?php echo $projectId; ?>, '<?php echo htmlspecialchars(addslashes($project['name'])); ?>');">
                    + Invite
                </button>
            </div>

            <!-- Notifications Dropdown -->
            <div class="dropdown-wrapper">
                <button type="button" class="btn btn-secondary btn-icon" id="notif-dropdown-btn" title="Notifications">
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
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>

            <!-- User Avatar & Dashboard Link -->
            <div style="display:flex; align-items:center; gap:0.6rem; margin-left:0.35rem;">
                <a href="dashboard.php" class="btn btn-ghost btn-sm" title="Back to Dashboard">Dashboard</a>
                <a href="logout.php" class="btn btn-ghost btn-sm" title="Sign out" style="color:var(--text-muted);">Sign out</a>
            </div>
        </div>
    </header>

    <!-- Sub-Navbar: Filter and Board Actions -->
    <div class="board-subnav">
        <div class="board-title-area">
            <!-- Search tasks input -->
            <div style="width:240px;">
                <input type="text" id="board-search-input" class="form-control" placeholder="🔍 Search tasks..." style="padding:0.4rem 0.75rem; font-size:0.8rem;">
            </div>

            <!-- Priority Filter Dropdown -->
            <div style="width:160px;">
                <select id="board-priority-filter" class="form-select" style="padding:0.4rem 0.75rem; font-size:0.8rem;">
                    <option value="all">All Priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
        </div>

        <div class="board-actions">
            <button type="button" class="btn btn-secondary btn-sm" onclick="openNewColumnModal()">
                <span>+ Add Column</span>
            </button>
        </div>
    </div>

    <!-- Main Kanban Board Container (Horizontal Scroll) -->
    <main class="board-page-container">
        <div class="kanban-viewport" id="kanban-board-container" data-project-id="<?php echo $projectId; ?>">
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
                    <span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-secondary);" id="task-detail-column-name">Column</span>
                    <h3 class="modal-title">Task Details</h3>
                </div>
                <button type="button" class="modal-close" id="close-modal-task-detail">✕</button>
            </div>

            <div class="modal-body">
                <div class="task-modal-grid">
                    <!-- Main details column -->
                    <div class="task-modal-main">
                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label">Task Title</label>
                            <input type="text" id="task-detail-title" class="form-control" style="font-size:1.05rem; font-weight:700;">
                        </div>

                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label">Description</label>
                            <textarea id="task-detail-desc" class="form-textarea" style="min-height:100px;" placeholder="Add detailed specifications, checklist, or acceptance criteria..."></textarea>
                        </div>

                        <!-- Real-Time Comments Discussion -->
                        <div style="margin-top:1rem; border-top:1px solid var(--border-color); padding-top:1rem;">
                            <h4 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem;">
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
                            <label class="form-label">Priority</label>
                            <select id="task-detail-priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0.75rem;">
                            <label class="form-label">Due Date</label>
                            <input type="date" id="task-detail-due" class="form-control">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Assigned Members</label>
                            <div id="task-detail-assignees-list" style="max-height:160px; overflow-y:auto;">
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
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Implement resilient circuit breaker" required autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Describe the task requirements..."></textarea>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                        <div class="form-group">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control">
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
                        <label class="form-label">Column Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. In Testing / UAT" required autofocus>
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
                        <label class="form-label">Search Users (by username or email)</label>
                        <input type="text" id="invite-user-search" class="form-control" placeholder="Type at least 2 characters...">
                    </div>

                    <div id="invite-selected-user-badge" style="display:none; padding:0.65rem 0.85rem; background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.3); border-radius:var(--radius-md); font-size:0.85rem; margin-bottom:1rem; color:var(--text-primary);">
                    </div>

                    <div id="user-search-results" style="max-height:160px; overflow-y:auto; margin-bottom:1rem;"></div>

                    <div class="form-group">
                        <label class="form-label">Assign Role</label>
                        <select name="role" class="form-select">
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

    <!-- Load Client Scripts in Sequence -->
    <script src="assets/js/api.js"></script>
    <script src="assets/js/dashboard.js"></script>
    <script src="assets/js/kanban.js"></script>
    <script src="assets/js/real_time.js"></script>

    <!-- Initial Board State Hydration -->
    <script>
        const initialBoardPayload = <?php echo $initialStateJson; ?>;
        document.addEventListener('DOMContentLoaded', () => {
            if (window.renderBoard && initialBoardPayload) {
                renderBoard(initialBoardPayload);
            }
        });
    </script>
</body>
</html>
