<?php
declare(strict_types=1);

/**
 * Tool Manager - Projects & Team Management API
 * Project CRUD, Member Roster & Role Promotion, User Profile & Settings, CSRF Protected
 */

require_once __DIR__ . '/../config/db.php';

$currentUser = requireAuthApi();
$pdo = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================================================
// 1. GET Requests: Projects, Roster, Single Project Board State, Search, Notifs
// ============================================================================
if ($method === 'GET') {
    // 1.1 Notification List
    if ($action === 'notifications') {
        $stmt = $pdo->prepare("
            SELECT id, message, link, is_read, created_at
            FROM `notifications`
            WHERE `user_id` = :uid
            ORDER BY `created_at` DESC
            LIMIT 25
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

    // 1.2 Get Project Members Roster (For Team Management Modal)
    if ($action === 'get_members') {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if (!$projectId || !checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
            jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
        }

        $stmtMem = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.avatar_color, pm.role, pm.created_at AS joined_at,
                   (CASE WHEN p.owner_id = u.id THEN 1 ELSE 0 END) AS is_owner
            FROM `project_members` pm
            JOIN `users` u ON pm.user_id = u.id
            JOIN `projects` p ON pm.project_id = p.id
            WHERE pm.project_id = :pid
            ORDER BY is_owner DESC, pm.role ASC, u.username ASC
        ");
        $stmtMem->execute([':pid' => $projectId]);
        $members = $stmtMem->fetchAll();

        jsonResponse(['success' => true, 'data' => $members]);
    }

    // 1.3 Search users to invite (excluding current project members)
    if ($action === 'search_users') {
        $query = trim($_GET['query'] ?? '');
        $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

        if (mb_strlen($query) < 2) {
            jsonResponse(['success' => true, 'data' => []]);
        }

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

    // 1.4 Single Project Detailed Board Fetch
    if (isset($_GET['id'])) {
        $projectId = (int)$_GET['id'];

        if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
            jsonResponse(['success' => false, 'error' => 'You do not have access to this project.'], 403);
        }

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

        $userRole = getProjectMemberRole($projectId, $currentUser['id'], $pdo);
        $project['user_role'] = $userRole;
        $project['is_owner'] = ($userRole === 'owner');
        $project['is_admin'] = in_array($userRole, ['owner', 'admin'], true);

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

        // Fetch Columns
        $stmtCol = $pdo->prepare("
            SELECT id, name, position
            FROM `columns`
            WHERE project_id = :pid
            ORDER BY position ASC, id ASC
        ");
        $stmtCol->execute([':pid' => $projectId]);
        $columns = $stmtCol->fetchAll();

        // Fetch Tasks
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

        // Fetch Assignments
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
                'project'      => $project,
                'members'      => $members,
                'columns'      => $columns,
                'last_updated' => $project['updated_at']
            ]
        ]);
    }

    // 1.5 List all Projects for Current User
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

        if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
            jsonResponse(['success' => false, 'error' => 'Only project admins or owners can add members.'], 403);
        }

        $stmtUser = $pdo->prepare("SELECT username FROM `users` WHERE id = :uid");
        $stmtUser->execute([':uid' => $userId]);
        $targetUser = $stmtUser->fetch();
        if (!$targetUser) {
            jsonResponse(['success' => false, 'error' => 'User does not exist.'], 404);
        }

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

    if (mb_strlen($name) > 120) {
        jsonResponse(['success' => false, 'error' => 'Project name must be 120 characters or fewer.'], 400);
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

        // Default enterprise columns
        $defaultColumns = ['Backlog', 'In Progress', 'Review & QA', 'Done'];
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
        error_log("Failed to create project: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create project.'], 500);
    }
}

// ============================================================================
// 3. PUT / PATCH: Update Project Details, Member Role, or User Profile
// ============================================================================
if ($method === 'PUT' || $method === 'PATCH') {
    $input = getJsonInput();

    // 3.1 Update User Profile & Settings
    if ($action === 'update_profile') {
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $avatarColor = trim($input['avatar_color'] ?? '');
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        if (empty($username) || empty($email)) {
            jsonResponse(['success' => false, 'error' => 'Username and email cannot be empty.'], 400);
        }

        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            jsonResponse(['success' => false, 'error' => 'Username must be between 3 and 50 characters.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'error' => 'Invalid email address.'], 400);
        }

        // Check uniqueness if changed
        $stmtCheck = $pdo->prepare("SELECT id FROM `users` WHERE (username = :u OR email = :e) AND id != :uid LIMIT 1");
        $stmtCheck->execute([':u' => $username, ':e' => $email, ':uid' => $currentUser['id']]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Username or email already in use.'], 400);
        }

        // Validate allowed avatar colors
        $allowedColors = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#06b6d4', '#8b5cf6', '#3b82f6'];
        if (!in_array($avatarColor, $allowedColors, true)) {
            $avatarColor = $currentUser['avatar_color'];
        }

        try {
            if (!empty($newPassword)) {
                if (mb_strlen($newPassword) < 6) {
                    jsonResponse(['success' => false, 'error' => 'New password must be at least 6 characters.'], 400);
                }

                $stmtPass = $pdo->prepare("SELECT password_hash FROM `users` WHERE id = :uid");
                $stmtPass->execute([':uid' => $currentUser['id']]);
                $hash = (string)$stmtPass->fetchColumn();

                if (!password_verify($currentPassword, $hash)) {
                    jsonResponse(['success' => false, 'error' => 'Current password is incorrect.'], 400);
                }

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtUpdate = $pdo->prepare("
                    UPDATE `users`
                    SET `username` = :u, `email` = :e, `avatar_color` = :a, `password_hash` = :p
                    WHERE `id` = :uid
                ");
                $stmtUpdate->execute([
                    ':u'   => $username,
                    ':e'   => $email,
                    ':a'   => $avatarColor,
                    ':p'   => $newHash,
                    ':uid' => $currentUser['id']
                ]);
            } else {
                $stmtUpdate = $pdo->prepare("
                    UPDATE `users`
                    SET `username` = :u, `email` = :e, `avatar_color` = :a
                    WHERE `id` = :uid
                ");
                $stmtUpdate->execute([
                    ':u'   => $username,
                    ':e'   => $email,
                    ':a'   => $avatarColor,
                    ':uid' => $currentUser['id']
                ]);
            }

            // Sync session
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['avatar_color'] = $avatarColor;

            jsonResponse([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'data'    => [
                    'username'     => $username,
                    'email'        => $email,
                    'avatar_color' => $avatarColor
                ]
            ]);
        } catch (Exception $e) {
            error_log("Profile update error: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to update profile.'], 500);
        }
    }

    // 3.2 Update Project Member Role (Admin / Owner Only)
    if ($action === 'update_member_role') {
        $projectId = (int)($input['project_id'] ?? 0);
        $targetUserId = (int)($input['user_id'] ?? 0);
        $newRole = ($input['role'] ?? '') === 'admin' ? 'admin' : 'member';

        if (!$projectId || !$targetUserId) {
            jsonResponse(['success' => false, 'error' => 'Project ID and User ID are required.'], 400);
        }

        // Must be admin or owner
        if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
            jsonResponse(['success' => false, 'error' => 'Only project admins or owners can update member roles.'], 403);
        }

        // Cannot demote the project owner
        $stmtOwner = $pdo->prepare("SELECT owner_id FROM `projects` WHERE id = :pid");
        $stmtOwner->execute([':pid' => $projectId]);
        $ownerId = (int)$stmtOwner->fetchColumn();

        if ($targetUserId === $ownerId) {
            jsonResponse(['success' => false, 'error' => 'Cannot modify the project owner role.'], 400);
        }

        $stmt = $pdo->prepare("UPDATE `project_members` SET `role` = :role WHERE `project_id` = :pid AND `user_id` = :uid");
        $stmt->execute([
            ':role' => $newRole,
            ':pid'  => $projectId,
            ':uid'  => $targetUserId
        ]);

        touchProject($projectId, $pdo);

        jsonResponse(['success' => true, 'message' => "Member role updated to {$newRole}."]);
    }

    // 3.3 Update Project Name / Description
    $projectId = (int)($input['id'] ?? $input['project_id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');

    if (!$projectId || empty($name)) {
        jsonResponse(['success' => false, 'error' => 'Project ID and name are required.'], 400);
    }

    if (mb_strlen($name) > 120) {
        jsonResponse(['success' => false, 'error' => 'Project name must be 120 characters or fewer.'], 400);
    }

    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
        jsonResponse(['success' => false, 'error' => 'Only project admins or owners can edit project details.'], 403);
    }

    try {
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
            'message' => 'Project updated successfully.',
            'data'    => [
                'id'          => $projectId,
                'name'        => $name,
                'description' => $description
            ]
        ]);
    } catch (Exception $e) {
        error_log("Failed to update project: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update project.'], 500);
    }
}

// ============================================================================
// 4. DELETE Requests: Delete Project or Remove Member
// ============================================================================
if ($method === 'DELETE') {
    $input = getJsonInput();

    // 4.1 Remove Member from Project
    if ($action === 'remove_member' || (isset($input['action']) && $input['action'] === 'remove_member')) {
        $projectId = (int)($input['project_id'] ?? $_GET['project_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);

        if (!$projectId || !$userId) {
            jsonResponse(['success' => false, 'error' => 'Project ID and User ID are required.'], 400);
        }

        $stmtOwner = $pdo->prepare("SELECT owner_id FROM `projects` WHERE id = :pid");
        $stmtOwner->execute([':pid' => $projectId]);
        $ownerId = (int)$stmtOwner->fetchColumn();

        if ($userId === $ownerId) {
            jsonResponse(['success' => false, 'error' => 'The project owner cannot be removed.'], 400);
        }

        $isSelf = ($userId === $currentUser['id']);
        if (!$isSelf && !checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
            jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM `project_members` WHERE `project_id` = :pid AND `user_id` = :uid");
        $stmt->execute([':pid' => $projectId, ':uid' => $userId]);

        touchProject($projectId, $pdo);

        jsonResponse(['success' => true, 'message' => 'Member removed successfully.']);
    }

    // 4.2 Delete Entire Project (Owner Only)
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

    try {
        $stmtDel = $pdo->prepare("DELETE FROM `projects` WHERE id = :id");
        $stmtDel->execute([':id' => $projectId]);

        jsonResponse(['success' => true, 'message' => 'Project deleted successfully.']);
    } catch (Exception $e) {
        error_log("Failed to delete project: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to delete project.'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
