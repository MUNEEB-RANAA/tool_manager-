<?php
declare(strict_types=1);

/**
 * Tool Manager - Columns API Endpoint
 * Hardened RBAC (Admin required for modifications), CSRF Protected
 */

require_once __DIR__ . '/../config/db.php';

$currentUser = requireAuthApi();
$pdo = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// 1. GET: Fetch Columns for a Project (Members & Admins)
// ============================================================================
if ($method === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if (!$projectId) {
        jsonResponse(['success' => false, 'error' => 'Project ID is required.'], 400);
    }

    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo)) {
        jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
    }

    $stmt = $pdo->prepare("
        SELECT id, project_id, name, position, created_at
        FROM `columns`
        WHERE project_id = :pid
        ORDER BY position ASC, id ASC
    ");
    $stmt->execute([':pid' => $projectId]);
    $columns = $stmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $columns]);
}

// ============================================================================
// 2. POST: Create a New Column (Admin / Owner Only)
// ============================================================================
if ($method === 'POST') {
    $input = getJsonInput();
    $projectId = (int)($input['project_id'] ?? 0);
    $name = trim($input['name'] ?? '');

    if (!$projectId || empty($name)) {
        jsonResponse(['success' => false, 'error' => 'Project ID and column name are required.'], 400);
    }

    if (mb_strlen($name) > 60) {
        jsonResponse(['success' => false, 'error' => 'Column name must be 60 characters or fewer.'], 400);
    }

    // Require admin access to alter board columns
    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
        jsonResponse(['success' => false, 'error' => 'Only project admins or owners can create columns.'], 403);
    }

    try {
        // Determine next position
        $stmtPos = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM `columns` WHERE project_id = :pid");
        $stmtPos->execute([':pid' => $projectId]);
        $nextPos = (int)$stmtPos->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO `columns` (`project_id`, `name`, `position`, `created_at`)
            VALUES (:pid, :name, :pos, NOW())
        ");
        $stmt->execute([
            ':pid'  => $projectId,
            ':name' => $name,
            ':pos'  => $nextPos
        ]);
        $colId = (int)$pdo->lastInsertId();

        touchProject($projectId, $pdo);

        jsonResponse([
            'success' => true,
            'message' => 'Column created successfully.',
            'data'    => [
                'id'         => $colId,
                'project_id' => $projectId,
                'name'       => $name,
                'position'   => $nextPos,
                'tasks'      => []
            ]
        ], 201);
    } catch (Exception $e) {
        error_log("Failed to create column: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to create column.'], 500);
    }
}

// ============================================================================
// 3. PUT / PATCH: Rename Column or Batch Reorder Columns (Admin / Owner Only)
// ============================================================================
if ($method === 'PUT' || $method === 'PATCH') {
    $input = getJsonInput();

    // 3.1 Batch Reorder Columns
    if (isset($input['column_ids']) && is_array($input['column_ids'])) {
        $projectId = (int)($input['project_id'] ?? 0);
        if (!$projectId || !checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
            jsonResponse(['success' => false, 'error' => 'Only project admins or owners can reorder columns.'], 403);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE `columns` SET position = :pos WHERE id = :id AND project_id = :pid");
            foreach ($input['column_ids'] as $pos => $id) {
                $stmt->execute([
                    ':pos' => (int)$pos,
                    ':id'  => (int)$id,
                    ':pid' => $projectId
                ]);
            }
            $pdo->commit();
            touchProject($projectId, $pdo);

            jsonResponse(['success' => true, 'message' => 'Columns reordered successfully.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Failed to reorder columns: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to reorder columns.'], 500);
        }
    }

    // 3.2 Rename Single Column
    $colId = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');

    if (!$colId || empty($name)) {
        jsonResponse(['success' => false, 'error' => 'Column ID and name are required.'], 400);
    }

    if (mb_strlen($name) > 60) {
        jsonResponse(['success' => false, 'error' => 'Column name must be 60 characters or fewer.'], 400);
    }

    $stmtCol = $pdo->prepare("SELECT project_id FROM `columns` WHERE id = :id");
    $stmtCol->execute([':id' => $colId]);
    $column = $stmtCol->fetch();

    if (!$column) {
        jsonResponse(['success' => false, 'error' => 'Column not found.'], 404);
    }

    $projectId = (int)$column['project_id'];
    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
        jsonResponse(['success' => false, 'error' => 'Only project admins or owners can rename columns.'], 403);
    }

    try {
        $stmtUpdate = $pdo->prepare("UPDATE `columns` SET name = :name WHERE id = :id");
        $stmtUpdate->execute([':name' => $name, ':id' => $colId]);

        touchProject($projectId, $pdo);

        jsonResponse(['success' => true, 'message' => 'Column renamed successfully.']);
    } catch (Exception $e) {
        error_log("Failed to rename column: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to rename column.'], 500);
    }
}

// ============================================================================
// 4. DELETE: Delete Column and Cascade its Tasks (Admin / Owner Only)
// ============================================================================
if ($method === 'DELETE') {
    $input = getJsonInput();
    $colId = (int)($input['id'] ?? $_GET['id'] ?? 0);

    if (!$colId) {
        jsonResponse(['success' => false, 'error' => 'Column ID is required.'], 400);
    }

    $stmtCol = $pdo->prepare("SELECT project_id FROM `columns` WHERE id = :id");
    $stmtCol->execute([':id' => $colId]);
    $column = $stmtCol->fetch();

    if (!$column) {
        jsonResponse(['success' => false, 'error' => 'Column not found.'], 404);
    }

    $projectId = (int)$column['project_id'];
    if (!checkProjectAccess($projectId, $currentUser['id'], $pdo, true)) {
        jsonResponse(['success' => false, 'error' => 'Only project admins or owners can delete columns.'], 403);
    }

    try {
        // Delete column (cascades tasks via foreign key)
        $stmtDel = $pdo->prepare("DELETE FROM `columns` WHERE id = :id");
        $stmtDel->execute([':id' => $colId]);

        touchProject($projectId, $pdo);

        jsonResponse(['success' => true, 'message' => 'Column deleted successfully.']);
    } catch (Exception $e) {
        error_log("Failed to delete column: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to delete column.'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
