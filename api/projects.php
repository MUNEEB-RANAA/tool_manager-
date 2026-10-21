<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$currentUser = requireAuthApi();
$pdo = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================================================
// 1. GET Requests: Project Listing, Single Project Board State, Search, Notifs
// ============================================================================
if ($method === 'GET') {
    // 1.1 Notification List
    if ($action === 'notifications') {
        $stmt = $pdo->prepare("
            SELECT id, message, link, is_read, created_at
            FROM `notifications`
            WHERE `user_id` = :uid
            ORDER BY `created_at` DESC
            LIMIT 20
        ");
        $stmt->execute([':uid' => $currentUser['id']]);
        $notifications = $stmt->fetchAll();

        // Unread count
        $stmtCount = $pdo->prepare("
            SELECT COUNT(*) FROM `notifications` WHERE `user_id` = :uid AND `is_read` = FALSE
        ");
        $stmtCount->execute([':uid' => $currentUser['id']]);
        $unreadCount = (int)$stmtCount->fetchColumn();

        jsonResponse([
            'success' => true,
            'data'    => [
                'notifications' => $notifications,
                'unread_count'  => $unreadCount
            ]
        ]);
    }

    // 1.2 Search users to invite
    if ($action === 'search_users') {
        $query = trim($_GET['query'] ?? '');
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

        if (strlen($query) < 2) {
            jsonResponse(['success' => true, 'data' => []]);
        }

        // Exclude members already in the project
        if ($projectId > 0) {
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.email, u.avatar_color
                FROM `users` u
                WHERE (u.username LIKE :q1 OR u.email LIKE :q2)
                  AND u.id NOT IN (
                      SELECT user_id FROM `project_members` WHERE project_id = :pid
                  )
                  AND u.id != (SELECT owner_id FROM `projects` WHERE id = :pid2)
                LIMIT 10
            ");
            $stmt->execute([
                ':q1'   => "%$query%",
                ':q2'   => "%$query%",
                ':pid'  => $projectId,
                ':pid2' => $projectId
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, username, email, avatar_color
                FROM `users`
                WHERE (username LIKE :q1 OR email LIKE :q2)
                  AND id != :curr_id
                LIMIT 10
            ");
            $stmt->execute([
                ':q1'      => "%$query%",
                ':q2'      => "%$query%",
                ':curr_id' => $currentUser['id']
            ]);
        }

        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // 1.3 Single Project Detailed Board Fetch
    if (isset($_GET['id'])) {
        $projectId = (int)$_GET['id'];

        if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
            jsonResponse(['success' => false, 'error' => 'You do not have access to this project.'], 403);
        }

        // Fetch Project Info
        $stmt = $pdo->prepare("
            SELECT p.*, u.username AS owner_name, u.avatar_color AS owner_avatar
            FROM `projects` p
            JOIN `users` u ON p.owner_id = u.id
            WHERE p.id = :id
        ");
        $stmt->execute([':id' => $projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            jsonResponse(['success' => false, 'error' => 'Project not found.'], 404);
        }

        $project['is_owner'] = ((int)$project['owner_id'] === $currentUser['id']);

        // Fetch Members
        $stmtMem = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.avatar_color, pm.role
            FROM `project_members` pm
            JOIN `users` u ON pm.user_id = u.id
            WHERE pm.project_id = :pid
            ORDER BY pm.role ASC, u.username ASC
        ");
        $stmtMem->execute([':pid' => $projectId]);
        $members = $stmtMem->fetchAll();

        // Fetch Columns ordered by position
        $stmtCol = $pdo->prepare("
            SELECT id, name, position
            FROM `columns`
            WHERE project_id = :pid
            ORDER BY position ASC, id ASC
        ");
        $stmtCol->execute([':pid' => $projectId]);
        $columns = $stmtCol->fetchAll();

        // Fetch all Tasks in project
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
        $stmtAssign = $pdo->prepare("
            SELECT ta.task_id, u.id AS user_id, u.username, u.avatar_color
            FROM `task_assignments` ta
            JOIN `users` u ON ta.user_id = u.id
            JOIN `tasks` t ON ta.task_id = t.id
            JOIN `columns` c ON t.column_id = c.id
            WHERE c.project_id = :pid
        ");
        $stmtAssign->execute([':pid' => $projectId]);
        $assignments = $stmtAssign->fetchAll();

        $assignmentsByTask = [];
        foreach ($assignments as $a) {
            $assignmentsByTask[$a['task_id']][] = [
                'id'           => (int)$a['user_id'],
                'username'     => $a['username'],
                'avatar_color' => $a['avatar_color']
            ];
        }

        // Group tasks by column
        $tasksByColumn = [];
        foreach ($columns as $c) {
            $tasksByColumn[$c['id']] = [];
        }

        foreach ($allTasks as $t) {
            $tid = (int)$t['id'];
            $t['assignees'] = $assignmentsByTask[$tid] ?? [];
            $tasksByColumn[$t['column_id']][] = $t;
        }

        foreach ($columns as &$col) {
            $col['tasks'] = $tasksByColumn[$col['id']] ?? [];
        }
        unset($col);

        jsonResponse([
            'success' => true,
            'data'    => [
                'project' => $project,
                'members' => $members,
                'columns' => $columns,
                'last_updated' => $project['updated_at']
            ]
        ]);
    }

    // 1.4 List all Projects for Current User
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.description, p.owner_id, p.created_at, p.updated_at,
               u.username AS owner_name, u.avatar_color AS owner_avatar,
               (SELECT COUNT(DISTINCT pm.user_id) FROM `project_members` pm WHERE pm.project_id = p.id) AS member_count,
               (SELECT COUNT(DISTINCT t.id) FROM `tasks` t JOIN `columns` c ON t.column_id = c.id WHERE c.project_id = p.id) AS total_tasks,
               (SELECT COUNT(DISTINCT t.id) FROM `tasks` t JOIN `columns` c ON t.column_id = c.id WHERE c.project_id = p.id AND LOWER(c.name) LIKE '%done%') AS done_tasks
        FROM `projects` p
        JOIN `users` u ON p.owner_id = u.id
        WHERE p.owner_id = :uid1
           OR p.id IN (SELECT project_id FROM `project_members` WHERE user_id = :uid2)
        ORDER BY p.updated_at DESC
    ");
    $stmt->execute([
        ':uid1' => $currentUser['id'],
        ':uid2' => $currentUser['id']
    ]);
    $projects = $stmt->fetchAll();

    foreach ($projects as &$p) {
        $p['is_owner'] = ((int)$p['owner_id'] === $currentUser['id']);
        $tot = (int)$p['total_tasks'];
        $done = (int)$p['done_tasks'];
        $p['progress'] = $tot > 0 ? (int)round(($done / $tot) * 100) : 0;
    }
    unset($p);

    jsonResponse(['success' => true, 'data' => $projects]);
}

// ============================================================================
// 2. POST Requests: Create Project, Add Member, Mark Notifications Read
// ============================================================================
if ($method === 'POST') {
    $input = getJsonInput();

    // 2.1 Mark Notifications as Read
    if ($action === 'mark_notifications_read') {
        $stmt = $pdo->prepare("UPDATE `notifications` SET `is_read` = TRUE WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $currentUser['id']]);
        jsonResponse(['success' => true, 'message' => 'Notifications marked as read.']);
    }

    // 2.2 Add Member to Project
    if ($action === 'add_member') {
        $projectId = (int)($input['project_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);
        $role = ($input['role'] ?? 'member') === 'admin' ? 'admin' : 'member';

        if (!$projectId || !$userId) {
            jsonResponse(['success' => false, 'error' => 'Project ID and User ID are required.'], 400);
        }

        // Only project owner or admin can add members
        if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
            jsonResponse(['success' => false, 'error' => 'Only project admins can add members.'], 403);
        }

        // Check if user exists
        $stmtUser = $pdo->prepare("SELECT username FROM `users` WHERE id = :uid");
        $stmtUser->execute([':uid' => $userId]);
        $targetUser = $stmtUser->fetch();
        if (!$targetUser) {
            jsonResponse(['success' => false, 'error' => 'User does not exist.'], 404);
        }

        // Insert or update member role
        $stmt = $pdo->prepare("
            INSERT INTO `project_members` (`project_id`, `user_id`, `role`, `created_at`)
            VALUES (:pid, :uid, :role, NOW())
            ON DUPLICATE KEY UPDATE `role` = :role_up
        ");
        $stmt->execute([
            ':pid'     => $projectId,
            ':uid'     => $userId,
            ':role'    => $role,
            ':role_up' => $role
        ]);

        // Get project name for notification
        $stmtP = $pdo->prepare("SELECT name FROM `projects` WHERE id = :pid");
        $stmtP->execute([':pid' => $projectId]);
        $projectName = $stmtP->fetchColumn() ?: 'a project';

        createNotification(
            $userId,
            "{$currentUser['username']} added you to project: {$projectName}",
            "project.php?id={$projectId}",
            $pdo
        );

        touchProject($projectId, $pdo);

        jsonResponse([
            'success' => true,
            'message' => "User {$targetUser['username']} added as {$role}."
        ]);
    }

    // 2.3 Create New Project
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');

    if (empty($name)) {
        jsonResponse(['success' => false, 'error' => 'Project name is required.'], 400);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `projects` (`name`, `description`, `owner_id`, `created_at`, `updated_at`)
            VALUES (:name, :desc, :owner_id, NOW(), NOW())
        ");
        $stmt->execute([
            ':name'     => $name,
            ':desc'     => $description,
            ':owner_id' => $currentUser['id']
        ]);
        $projectId = (int)$pdo->lastInsertId();

        // Add owner to project_members as admin
        $stmtMem = $pdo->prepare("
            INSERT INTO `project_members` (`project_id`, `user_id`, `role`, `created_at`)
            VALUES (:pid, :uid, 'admin', NOW())
        ");
        $stmtMem->execute([
            ':pid' => $projectId,
            ':uid' => $currentUser['id']
        ]);

        // Initialize default enterprise columns
        $defaultColumns = ['To Do', 'In Progress', 'Code Review', 'Done'];
        $stmtCol = $pdo->prepare("
            INSERT INTO `columns` (`project_id`, `name`, `position`, `created_at`)
            VALUES (:pid, :cname, :pos, NOW())
        ");
        foreach ($defaultColumns as $pos => $colName) {
            $stmtCol->execute([
                ':pid'   => $projectId,
                ':cname' => $colName,
                ':pos'   => $pos
            ]);
        }

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Project created successfully.',
            'data'    => [
                'id'   => $projectId,
                'name' => $name
            ]
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Failed to create project: ' . $e->getMessage()], 500);
    }
}

// ============================================================================
// 3. PUT / PATCH Requests: Update Project Name / Description
// ============================================================================
if ($method === 'PUT' || $method === 'PATCH') {
    $input = getJsonInput();
    $projectId = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');

    if (!$projectId || empty($name)) {
        jsonResponse(['success' => false, 'error' => 'Project ID and name are required.'], 400);
    }

    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
        jsonResponse(['success' => false, 'error' => 'Only project admins can edit project details.'], 403);
    }

    $stmt = $pdo->prepare("
        UPDATE `projects`
        SET `name` = :name, `description` = :desc, `updated_at` = NOW()
        WHERE `id` = :id
    ");
    $stmt->execute([
        ':name' => $name,
        ':desc' => $description,
        ':id'   => $projectId
    ]);

    touchProject($projectId, $pdo);

    jsonResponse([
        'success' => true,
        'message' => 'Project updated successfully.'
    ]);
}

// ============================================================================
// 4. DELETE Requests: Delete Project or Remove Member
// ============================================================================
if ($method === 'DELETE') {
    $input = getJsonInput();

    // 4.1 Remove Member from Project
    if ($action === 'remove_member' || isset($input['action']) && $input['action'] === 'remove_member') {
        $projectId = (int)($input['project_id'] ?? $_GET['project_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);

        if (!$projectId || !$userId) {
            jsonResponse(['success' => false, 'error' => 'Project ID and User ID are required.'], 400);
        }

        // Cannot remove the project owner
        $stmtOwner = $pdo->prepare("SELECT owner_id FROM `projects` WHERE id = :pid");
        $stmtOwner->execute([':pid' => $projectId]);
        $ownerId = (int)$stmtOwner->fetchColumn();

        if ($userId === $ownerId) {
            jsonResponse(['success' => false, 'error' => 'The project owner cannot be removed.'], 400);
        }

        // Must be admin or removing self
        $isSelf = ($userId === $currentUser['id']);
        if (!$isSelf && !checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
            jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM `project_members` WHERE `project_id` = :pid AND `user_id` = :uid");
        $stmt->execute([':pid' => $projectId, ':uid' => $userId]);

        touchProject($projectId, $pdo);

        jsonResponse(['success' => true, 'message' => 'Member removed successfully.']);
    }

    // 4.2 Delete Entire Project (Owner only)
    $projectId = (int)($input['id'] ?? $_GET['id'] ?? 0);

    if (!$projectId) {
        jsonResponse(['success' => false, 'error' => 'Project ID is required.'], 400);
    }

    $stmt = $pdo->prepare("SELECT owner_id FROM `projects` WHERE id = :id");
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        jsonResponse(['success' => false, 'error' => 'Project not found.'], 404);
    }

    if ((int)$project['owner_id'] !== $currentUser['id']) {
        jsonResponse(['success' => false, 'error' => 'Only the project owner can delete this project.'], 403);
    }

    $stmtDel = $pdo->prepare("DELETE FROM `projects` WHERE id = :id");
    $stmtDel->execute([':id' => $projectId]);

    jsonResponse(['success' => true, 'message' => 'Project deleted successfully.']);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
