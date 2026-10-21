<?php
declare(strict_types=1);

/**
 * Tool Manager - Tasks API Endpoint
 * High-Reliability Positional DND Engine, Batch Reordering, CSRF & RBAC Protected
 */

require_once __DIR__ . '/../config/db.php';

$currentUser = requireAuthApi();
$pdo = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Helper to get project_id from column_id
function getProjectIdFromColumn(int $columnId, PDO $pdo): ?int
{
    $stmt = $pdo->prepare("SELECT project_id FROM `columns` WHERE id = :id");
    $stmt->execute([':id' => $columnId]);
    $res = $stmt->fetchColumn();
    return $res !== false ? (int)$res : null;
}

// Helper to get project_id and column_id from task_id
function getTaskMeta(int $taskId, PDO $pdo): ?array
{
    $stmt = $pdo->prepare("
        SELECT t.id, t.column_id, t.position, c.project_id
        FROM `tasks` t
        JOIN `columns` c ON t.column_id = c.id
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $taskId]);
    $res = $stmt->fetch();
    return $res ?: null;
}

// ============================================================================
// 1. GET: Fetch Single Task Details with Assignees & Meta
// ============================================================================
if ($method === 'GET') {
    $taskId = (int)($_GET['id'] ?? 0);
    if (!$taskId) {
        jsonResponse(['success' => false, 'error' => 'Task ID is required.'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT t.*, c.name AS column_name, c.project_id, p.name AS project_name
        FROM `tasks` t
        JOIN `columns` c ON t.column_id = c.id
        JOIN `projects` p ON c.project_id = p.id
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        jsonResponse(['success' => false, 'error' => 'Task not found.'], 404);
    }

    if (!checkProjectAccess((int)$task['project_id'], $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    // Get assignees
    $stmtAssign = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.avatar_color
        FROM `task_assignments` ta
        JOIN `users` u ON ta.user_id = u.id
        WHERE ta.task_id = :tid
    ");
    $stmtAssign->execute([':tid' => $taskId]);
    $task['assignees'] = $stmtAssign->fetchAll();

    jsonResponse(['success' => true, 'data' => $task]);
}

// ============================================================================
// 2. POST: Create New Task
// ============================================================================
if ($method === 'POST') {
    $input = getJsonInput();
    $columnId = (int)($input['column_id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $rawDue = trim($input['due_date'] ?? '');
    $priority = in_array($input['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $input['priority'] : 'medium';
    $assigneeIds = is_array($input['assignee_ids'] ?? null) ? $input['assignee_ids'] : [];

    if (!$columnId || empty($title)) {
        jsonResponse(['success' => false, 'error' => 'Column ID and task title are required.'], 400);
    }

    if (mb_strlen($title) > 255) {
        jsonResponse(['success' => false, 'error' => 'Task title must be 255 characters or fewer.'], 400);
    }

    // Validate due date format
    $dueDate = null;
    if (!empty($rawDue)) {
        $d = DateTime::createFromFormat('Y-m-d', $rawDue);
        if ($d && $d->format('Y-m-d') === $rawDue) {
            $dueDate = $rawDue;
        }
    }

    $projectId = getProjectIdFromColumn($columnId, $pdo);
    if (!$projectId || !checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    $pdo->beginTransaction();
    try {
        // Find next position in column
        $stmtPos = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM `tasks` WHERE column_id = :cid");
        $stmtPos->execute([':cid' => $columnId]);
        $nextPos = (int)$stmtPos->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO `tasks` (`column_id`, `title`, `description`, `due_date`, `priority`, `position`, `created_at`, `updated_at`)
            VALUES (:cid, :title, :desc, :due, :priority, :pos, NOW(), NOW())
        ");
        $stmt->execute([
            ':cid'      => $columnId,
            ':title'    => $title,
            ':desc'     => $description,
            ':due'      => $dueDate,
            ':priority' => $priority,
            ':pos'      => $nextPos
        ]);
        $taskId = (int)$pdo->lastInsertId();

        // Assign users
        if (!empty($assigneeIds)) {
            $stmtAssign = $pdo->prepare("INSERT IGNORE INTO `task_assignments` (`task_id`, `user_id`) VALUES (:tid, :uid)");
            foreach ($assigneeIds as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $stmtAssign->execute([':tid' => $taskId, ':uid' => $uid]);
                    if ($uid !== $currentUser['id']) {
                        createNotification(
                            $uid,
                            "{$currentUser['username']} assigned you to task: {$title}",
                            "project.php?id={$projectId}&task={$taskId}",
                            $pdo
                        );
                    }
                }
            }
        }

        $pdo->commit();
        touchProject($projectId, $pdo);

        // Fetch newly created task with assignees
        $stmtNew = $pdo->prepare("
            SELECT t.*, 0 AS comments_count
            FROM `tasks` t
            WHERE t.id = :id
        ");
        $stmtNew->execute([':id' => $taskId]);
        $createdTask = $stmtNew->fetch();

        // Fetch assignees
        $stmtA = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.avatar_color
            FROM `task_assignments` ta
            JOIN `users` u ON ta.user_id = u.id
            WHERE ta.task_id = :tid
        ");
        $stmtA->execute([':tid' => $taskId]);
        $createdTask['assignees'] = $stmtA->fetchAll();

        jsonResponse([
            'success' => true,
            'message' => 'Task created successfully.',
            'data'    => $createdTask
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to create task: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create task.'], 500);
    }
}

// ============================================================================
// 3. PUT / PATCH: Drag-Drop Reorder or Update Task Details
// ============================================================================
if ($method === 'PUT' || $method === 'PATCH') {
    $input = getJsonInput();

    // 3.1 BATCH REORDER ENTIRE COLUMN TASKS (Most robust for Kanban drag & drop)
    if (($input['action'] ?? '') === 'reorder_column') {
        $columnId = (int)($input['column_id'] ?? 0);
        $taskIds = is_array($input['task_ids'] ?? null) ? $input['task_ids'] : [];

        if (!$columnId) {
            jsonResponse(['success' => false, 'error' => 'Column ID is required.'], 400);
        }

        $projectId = getProjectIdFromColumn($columnId, $pdo);
        if (!$projectId || !checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
            jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE `tasks`
                SET `position` = :pos, `column_id` = :cid, `updated_at` = NOW()
                WHERE `id` = :tid AND `column_id` IN (SELECT id FROM `columns` WHERE project_id = :pid)
            ");
            foreach ($taskIds as $pos => $tid) {
                $stmt->execute([
                    ':pos' => (int)$pos,
                    ':cid' => $columnId,
                    ':tid' => (int)$tid,
                    ':pid' => $projectId
                ]);
            }
            $pdo->commit();
            touchProject($projectId, $pdo);

            jsonResponse(['success' => true, 'message' => 'Tasks reordered successfully.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Failed to reorder column tasks: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to reorder tasks.'], 500);
        }
    }

    // 3.2 SINGLE TASK UPDATE OR MOVE
    $taskId = (int)($input['task_id'] ?? $input['id'] ?? 0);
    if (!$taskId) {
        jsonResponse(['success' => false, 'error' => 'Task ID is required.'], 400);
    }

    $taskMeta = getTaskMeta($taskId, $pdo);
    if (!$taskMeta) {
        jsonResponse(['success' => false, 'error' => 'Task not found.'], 404);
    }

    $projectId = (int)$taskMeta['project_id'];
    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    $pdo->beginTransaction();
    try {
        // CASE A: Drag-and-Drop Column Move or Position Reordering
        if (isset($input['column_id']) || isset($input['new_column_id']) || isset($input['position']) || isset($input['new_position'])) {
            $sourceColId = (int)$taskMeta['column_id'];
            $targetColId = (int)($input['new_column_id'] ?? $input['column_id'] ?? $sourceColId);
            $oldPos = (int)$taskMeta['position'];
            $newPos = max(0, (int)($input['new_position'] ?? $input['position'] ?? 0));

            // Verify target column belongs to the same project
            $targetProjId = getProjectIdFromColumn($targetColId, $pdo);
            if ($targetProjId !== $projectId) {
                throw new Exception('Target column is invalid or belongs to another project.');
            }

            if ($sourceColId === $targetColId) {
                // Moving within same column
                if ($oldPos !== $newPos) {
                    if ($oldPos < $newPos) {
                        $stmtShift = $pdo->prepare("
                            UPDATE `tasks`
                            SET `position` = `position` - 1
                            WHERE `column_id` = :cid AND `position` > :oldPos AND `position` <= :newPos
                        ");
                        $stmtShift->execute([':cid' => $sourceColId, ':oldPos' => $oldPos, ':newPos' => $newPos]);
                    } else {
                        $stmtShift = $pdo->prepare("
                            UPDATE `tasks`
                            SET `position` = `position` + 1
                            WHERE `column_id` = :cid AND `position` >= :newPos AND `position` < :oldPos
                        ");
                        $stmtShift->execute([':cid' => $sourceColId, ':newPos' => $newPos, ':oldPos' => $oldPos]);
                    }

                    $stmtMove = $pdo->prepare("UPDATE `tasks` SET `position` = :newPos, `updated_at` = NOW() WHERE `id` = :tid");
                    $stmtMove->execute([':newPos' => $newPos, ':tid' => $taskId]);
                }
            } else {
                // Moving to different column
                $stmtOld = $pdo->prepare("
                    UPDATE `tasks`
                    SET `position` = `position` - 1
                    WHERE `column_id` = :oldCid AND `position` > :oldPos
                ");
                $stmtOld->execute([':oldCid' => $sourceColId, ':oldPos' => $oldPos]);

                $stmtNew = $pdo->prepare("
                    UPDATE `tasks`
                    SET `position` = `position` + 1
                    WHERE `column_id` = :newCid AND `position` >= :newPos
                ");
                $stmtNew->execute([':newCid' => $targetColId, ':newPos' => $newPos]);

                $stmtMove = $pdo->prepare("
                    UPDATE `tasks`
                    SET `column_id` = :newCid, `position` = :newPos, `updated_at` = NOW()
                    WHERE `id` = :tid
                ");
                $stmtMove->execute([
                    ':newCid' => $targetColId,
                    ':newPos' => $newPos,
                    ':tid'    => $taskId
                ]);
            }
        }

        // CASE B: Update Task Content (Title, Description, Due Date, Priority)
        $fieldsToUpdate = [];
        $params = [':tid' => $taskId];

        if (isset($input['title']) && trim($input['title']) !== '') {
            $fieldsToUpdate[] = "`title` = :title";
            $params[':title'] = mb_substr(trim($input['title']), 0, 255);
        }
        if (isset($input['description'])) {
            $fieldsToUpdate[] = "`description` = :desc";
            $params[':desc'] = trim($input['description']);
        }
        if (array_key_exists('due_date', $input)) {
            $rawDue = trim((string)$input['due_date']);
            $validDue = null;
            if (!empty($rawDue)) {
                $d = DateTime::createFromFormat('Y-m-d', $rawDue);
                if ($d && $d->format('Y-m-d') === $rawDue) {
                    $validDue = $rawDue;
                }
            }
            $fieldsToUpdate[] = "`due_date` = :due";
            $params[':due'] = $validDue;
        }
        if (isset($input['priority']) && in_array($input['priority'], ['low', 'medium', 'high', 'urgent'], true)) {
            $fieldsToUpdate[] = "`priority` = :priority";
            $params[':priority'] = $input['priority'];
        }

        if (!empty($fieldsToUpdate)) {
            $sql = "UPDATE `tasks` SET " . implode(', ', $fieldsToUpdate) . ", `updated_at` = NOW() WHERE `id` = :tid";
            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute($params);
        }

        // CASE C: Update Assignees
        if (isset($input['assignee_ids']) && is_array($input['assignee_ids'])) {
            $stmtDelAss = $pdo->prepare("DELETE FROM `task_assignments` WHERE `task_id` = :tid");
            $stmtDelAss->execute([':tid' => $taskId]);

            if (!empty($input['assignee_ids'])) {
                $stmtInsAss = $pdo->prepare("INSERT IGNORE INTO `task_assignments` (`task_id`, `user_id`) VALUES (:tid, :uid)");
                foreach ($input['assignee_ids'] as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) {
                        $stmtInsAss->execute([':tid' => $taskId, ':uid' => $uid]);
                    }
                }
            }
        }

        $pdo->commit();
        touchProject($projectId, $pdo);

        jsonResponse([
            'success' => true,
            'message' => 'Task updated successfully.'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to update task: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to update task.'], 500);
    }
}

// ============================================================================
// 4. DELETE: Delete Task
// ============================================================================
if ($method === 'DELETE') {
    $input = getJsonInput();
    $taskId = (int)($input['id'] ?? $_GET['id'] ?? 0);

    if (!$taskId) {
        jsonResponse(['success' => false, 'error' => 'Task ID is required.'], 400);
    }

    $taskMeta = getTaskMeta($taskId, $pdo);
    if (!$taskMeta) {
        jsonResponse(['success' => false, 'error' => 'Task not found.'], 404);
    }

    $projectId = (int)$taskMeta['project_id'];
    $columnId = (int)$taskMeta['column_id'];
    $pos = (int)$taskMeta['position'];

    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    $pdo->beginTransaction();
    try {
        $stmtDel = $pdo->prepare("DELETE FROM `tasks` WHERE `id` = :tid");
        $stmtDel->execute([':tid' => $taskId]);

        $stmtShift = $pdo->prepare("
            UPDATE `tasks`
            SET `position` = `position` - 1
            WHERE `column_id` = :cid AND `position` > :pos
        ");
        $stmtShift->execute([':cid' => $columnId, ':pos' => $pos]);

        $pdo->commit();
        touchProject($projectId, $pdo);

        jsonResponse(['success' => true, 'message' => 'Task deleted successfully.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to delete task: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to delete task.'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
