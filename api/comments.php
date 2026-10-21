<?php
declare(strict_types=1);

/**
 * Tool Manager - Comments & Discussion API Endpoint
 * CSRF Protected, Input Validation & Sanitization
 */

require_once __DIR__ . '/../config/db.php';

$currentUser = requireAuthApi();
$pdo = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// 1. GET: Fetch Comments for a Task
// ============================================================================
if ($method === 'GET') {
    $taskId = (int)($_GET['task_id'] ?? 0);
    if (!$taskId) {
        jsonResponse(['success' => false, 'error' => 'Task ID is required.'], 400);
    }

    $stmtCheck = $pdo->prepare("
        SELECT c.project_id
        FROM `tasks` t
        JOIN `columns` c ON t.column_id = c.id
        WHERE t.id = :tid
    ");
    $stmtCheck->execute([':tid' => $taskId]);
    $projectId = $stmtCheck->fetchColumn();

    if (!$projectId) {
        jsonResponse(['success' => false, 'error' => 'Task not found.'], 404);
    }

    if (!checkProjectAccess((int)$projectId, $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.task_id, c.user_id, c.comment_text, c.created_at,
               u.username, u.avatar_color
        FROM `comments` c
        JOIN `users` u ON c.user_id = u.id
        WHERE c.task_id = :tid
        ORDER BY c.created_at ASC, c.id ASC
    ");
    $stmt->execute([':tid' => $taskId]);
    $comments = $stmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $comments]);
}

// ============================================================================
// 2. POST: Post a New Comment
// ============================================================================
if ($method === 'POST') {
    $input = getJsonInput();
    $taskId = (int)($input['task_id'] ?? 0);
    $commentText = trim($input['comment_text'] ?? '');

    if (!$taskId || empty($commentText)) {
        jsonResponse(['success' => false, 'error' => 'Task ID and comment text are required.'], 400);
    }

    if (mb_strlen($commentText) > 5000) {
        jsonResponse(['success' => false, 'error' => 'Comment cannot exceed 5000 characters.'], 400);
    }

    // Fetch Task & Project details
    $stmtTask = $pdo->prepare("
        SELECT t.id, t.title, c.project_id
        FROM `tasks` t
        JOIN `columns` c ON t.column_id = c.id
        WHERE t.id = :tid
    ");
    $stmtTask->execute([':tid' => $taskId]);
    $task = $stmtTask->fetch();

    if (!$task) {
        jsonResponse(['success' => false, 'error' => 'Task not found.'], 404);
    }

    $projectId = (int)$task['project_id'];
    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `comments` (`task_id`, `user_id`, `comment_text`, `created_at`)
            VALUES (:tid, :uid, :text, NOW())
        ");
        $stmt->execute([
            ':tid'  => $taskId,
            ':uid'  => $currentUser['id'],
            ':text' => $commentText
        ]);
        $commentId = (int)$pdo->lastInsertId();

        // Notify task assignees
        $stmtAss = $pdo->prepare("SELECT user_id FROM `task_assignments` WHERE task_id = :tid AND user_id != :me");
        $stmtAss->execute([':tid' => $taskId, ':me' => $currentUser['id']]);
        $assignees = $stmtAss->fetchAll();

        $snippet = mb_strimwidth($commentText, 0, 50, '...');
        foreach ($assignees as $a) {
            createNotification(
                (int)$a['user_id'],
                "{$currentUser['username']} commented on {$task['title']}: \"{$snippet}\"",
                "project.php?id={$projectId}&task={$taskId}",
                $pdo
            );
        }

        touchProject($projectId, $pdo);

        jsonResponse([
            'success' => true,
            'message' => 'Comment posted successfully.',
            'data'    => [
                'id'           => $commentId,
                'task_id'      => $taskId,
                'user_id'      => $currentUser['id'],
                'username'     => $currentUser['username'],
                'avatar_color' => $currentUser['avatar_color'],
                'comment_text' => $commentText,
                'created_at'   => date('Y-m-d H:i:s')
            ]
        ], 201);
    } catch (Exception $e) {
        error_log("Failed to post comment: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to post comment.'], 500);
    }
}

// ============================================================================
// 3. DELETE: Delete a Comment (Author or Project Admin)
// ============================================================================
if ($method === 'DELETE') {
    $input = getJsonInput();
    $commentId = (int)($input['id'] ?? $_GET['id'] ?? 0);

    if (!$commentId) {
        jsonResponse(['success' => false, 'error' => 'Comment ID is required.'], 400);
    }

    $stmtCom = $pdo->prepare("
        SELECT c.id, c.user_id, c.task_id, col.project_id
        FROM `comments` c
        JOIN `tasks` t ON c.task_id = t.id
        JOIN `columns` col ON t.column_id = col.id
        WHERE c.id = :cid
    ");
    $stmtCom->execute([':cid' => $commentId]);
    $comment = $stmtCom->fetch();

    if (!$comment) {
        jsonResponse(['success' => false, 'error' => 'Comment not found.'], 404);
    }

    $isAuthor = ((int)$comment['user_id'] === $currentUser['id']);
    $isAdmin = checkProjectAccess((int)$comment['project_id'], $currentUser['id'], $pdo, true);

    if (!$isAuthor && !$isAdmin) {
        jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    try {
        $stmtDel = $pdo->prepare("DELETE FROM `comments` WHERE id = :cid");
        $stmtDel->execute([':cid' => $commentId]);

        touchProject((int)$comment['project_id'], $pdo);

        jsonResponse(['success' => true, 'message' => 'Comment deleted successfully.']);
    } catch (Exception $e) {
        error_log("Failed to delete comment: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to delete comment.'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
