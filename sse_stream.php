<?php
declare(strict_types=1);

// Prevent timeouts and disable all output buffering for low-latency streaming
set_time_limit(0);
ignore_user_abort(true);

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 'off');
@ini_set('implicit_flush', '1');

require_once __DIR__ . '/config/db.php';

// Authenticate session before closing session write lock
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    exit;
}

$userId = (int)$_SESSION['user_id'];
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// IMPORTANT: Release session lock so other AJAX/Fetch requests are not blocked!
session_write_close();

$pdo = getDBConnection();

// Set SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Function to safely flush buffers
function sseFlush(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

// Initial connection handshake
echo ": connected\n\n";
sseFlush();

// Validate project access if a specific project was requested
if ($projectId > 0) {
    if (!checkProjectAccess($projectId, $userId, $pdo)) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'Access denied to project.']) . "\n\n";
        sseFlush();
        exit;
    }
}

// Helper to fetch complete board payload for project
function getBoardPayload(int $projectId, PDO $pdo): array
{
    // Fetch Project
    $stmt = $pdo->prepare("SELECT id, name, description, updated_at FROM `projects` WHERE id = :id");
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        return [];
    }

    // Fetch Columns
    $stmtCol = $pdo->prepare("SELECT id, name, position FROM `columns` WHERE project_id = :pid ORDER BY position ASC, id ASC");
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

    $tasksByCol = [];
    foreach ($columns as $c) {
        $tasksByCol[$c['id']] = [];
    }
    foreach ($allTasks as $t) {
        $t['assignees'] = $assignmentsByTask[$t['id']] ?? [];
        $tasksByCol[$t['column_id']][] = $t;
    }
    foreach ($columns as &$c) {
        $c['tasks'] = $tasksByCol[$c['id']] ?? [];
    }
    unset($c);

    return [
        'project_id'   => $projectId,
        'updated_at'   => $project['updated_at'],
        'columns'      => $columns
    ];
}

// Track last known states
$lastProjectUpdated = '';
$lastMaxCommentId = 0;
$lastNotifCount = -1;

if ($projectId > 0) {
    $stmt = $pdo->prepare("SELECT updated_at FROM `projects` WHERE id = :pid");
    $stmt->execute([':pid' => $projectId]);
    $lastProjectUpdated = (string)($stmt->fetchColumn() ?: '');

    $stmtC = $pdo->prepare("
        SELECT COALESCE(MAX(c.id), 0)
        FROM `comments` c
        JOIN `tasks` t ON c.task_id = t.id
        JOIN `columns` col ON t.column_id = col.id
        WHERE col.project_id = :pid
    ");
    $stmtC->execute([':pid' => $projectId]);
    $lastMaxCommentId = (int)$stmtC->fetchColumn();
}

$loopCounter = 0;

// Main Real-Time Event Loop
while (true) {
    if (connection_aborted()) {
        break;
    }

    $loopCounter++;

    // 1. Check Project Updates (Tasks, Columns, Drag & Drop Moves)
    if ($projectId > 0) {
        $stmt = $pdo->prepare("SELECT updated_at FROM `projects` WHERE id = :pid");
        $stmt->execute([':pid' => $projectId]);
        $currentUpdated = (string)($stmt->fetchColumn() ?: '');

        if ($currentUpdated !== '' && $currentUpdated !== $lastProjectUpdated) {
            $lastProjectUpdated = $currentUpdated;
            $boardData = getBoardPayload($projectId, $pdo);

            echo "event: board_update\n";
            echo "data: " . json_encode($boardData, JSON_UNESCAPED_UNICODE) . "\n\n";
            sseFlush();
        }

        // Check for new comments in this project
        $stmtC = $pdo->prepare("
            SELECT c.id, c.task_id, c.user_id, c.comment_text, c.created_at,
                   u.username, u.avatar_color
            FROM `comments` c
            JOIN `users` u ON c.user_id = u.id
            JOIN `tasks` t ON c.task_id = t.id
            JOIN `columns` col ON t.column_id = col.id
            WHERE col.project_id = :pid AND c.id > :last_cid
            ORDER BY c.id ASC
        ");
        $stmtC->execute([':pid' => $projectId, ':last_cid' => $lastMaxCommentId]);
        $newComments = $stmtC->fetchAll();

        if (!empty($newComments)) {
            foreach ($newComments as $nc) {
                if ((int)$nc['id'] > $lastMaxCommentId) {
                    $lastMaxCommentId = (int)$nc['id'];
                }
                echo "event: comment_added\n";
                echo "data: " . json_encode($nc, JSON_UNESCAPED_UNICODE) . "\n\n";
                sseFlush();
            }
        }
    }

    // 2. Check User Unread Notifications
    $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM `notifications` WHERE user_id = :uid AND is_read = FALSE");
    $stmtNotif->execute([':uid' => $userId]);
    $currentNotifCount = (int)$stmtNotif->fetchColumn();

    if ($currentNotifCount !== $lastNotifCount) {
        $lastNotifCount = $currentNotifCount;

        // Fetch latest notification item if count increased
        $stmtLatest = $pdo->prepare("
            SELECT id, message, link, created_at
            FROM `notifications`
            WHERE user_id = :uid AND is_read = FALSE
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmtLatest->execute([':uid' => $userId]);
        $latest = $stmtLatest->fetch();

        echo "event: notification\n";
        echo "data: " . json_encode([
            'unread_count' => $currentNotifCount,
            'latest'       => $latest ?: null
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        sseFlush();
    }

    // 3. Heartbeat / Ping every 15 iterations (~25-30s) to keep TCP socket active
    if ($loopCounter % 15 === 0) {
        echo ": heartbeat " . time() . "\n\n";
        sseFlush();
    }

    // Sleep for 1.8 seconds between polls
    usleep(1800000);
}
