<?php
declare(strict_types=1);

/**
 * Tool Manager - Modern Enterprise Workflow & Database Engine
 */

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'kanban_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get configured PDO Database instance (Singleton)
 *
 * @return PDO
 */
function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Database connection error: ' . $e->getMessage()
                ]);
            } else {
                echo "<div style='font-family:sans-serif;padding:2rem;background:#181824;color:#ff6b6b;'>";
                echo "<h2>Database Connection Failed</h2>";
                echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
            exit;
        }
    }

    return $pdo;
}

/**
 * Send JSON API response and terminate execution
 *
 * @param mixed $data
 * @param int $statusCode
 * @return void
 */
function jsonResponse(mixed $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Retrieve parsed JSON input body
 *
 * @return array
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Enforce authentication for API requests
 *
 * @return array Session User data
 */
function requireAuthApi(): array
{
    if (!isset($_SESSION['user_id'])) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized access. Please log in.'
        ], 401);
    }

    return [
        'id'           => (int)$_SESSION['user_id'],
        'username'     => $_SESSION['username'] ?? '',
        'email'        => $_SESSION['email'] ?? '',
        'avatar_color' => $_SESSION['avatar_color'] ?? '#6366f1'
    ];
}

/**
 * Enforce authentication for Web views
 *
 * @return array Session User data
 */
function requireAuthWeb(): array
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    return [
        'id'           => (int)$_SESSION['user_id'],
        'username'     => $_SESSION['username'] ?? '',
        'email'        => $_SESSION['email'] ?? '',
        'avatar_color' => $_SESSION['avatar_color'] ?? '#6366f1'
    ];
}

/**
 * Touch a project's updated_at timestamp to broadcast SSE updates
 *
 * @param int $projectId
 * @param PDO $pdo
 * @return void
 */
function touchProject(int $projectId, PDO $pdo): void
{
    $stmt = $pdo->prepare("UPDATE `projects` SET `updated_at` = CURRENT_TIMESTAMP WHERE `id` = :id");
    $stmt->execute([':id' => $projectId]);
}

/**
 * Check if a user has access to a project
 *
 * @param int $projectId
 * @param int $userId
 * @param PDO $pdo
 * @param bool $requireAdmin
 * @return bool
 */
function checkProjectAccess(int $projectId, int $userId, PDO $pdo, bool $requireAdmin = false): bool
{
    // Check if user is the project owner
    $stmt = $pdo->prepare("SELECT owner_id FROM `projects` WHERE `id` = :pid LIMIT 1");
    $stmt->execute([':pid' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        return false;
    }

    if ((int)$project['owner_id'] === $userId) {
        return true;
    }

    if ($requireAdmin) {
        $stmt = $pdo->prepare("
            SELECT role FROM `project_members` 
            WHERE `project_id` = :pid AND `user_id` = :uid AND `role` = 'admin'
            LIMIT 1
        ");
        $stmt->execute([':pid' => $projectId, ':uid' => $userId]);
        return (bool)$stmt->fetch();
    }

    $stmt = $pdo->prepare("
        SELECT role FROM `project_members` 
        WHERE `project_id` = :pid AND `user_id` = :uid
        LIMIT 1
    ");
    $stmt->execute([':pid' => $projectId, ':uid' => $userId]);
    return (bool)$stmt->fetch();
}

/**
 * Create a user notification
 *
 * @param int $userId
 * @param string $message
 * @param string|null $link
 * @param PDO $pdo
 * @return void
 */
function createNotification(int $userId, string $message, ?string $link, PDO $pdo): void
{
    $stmt = $pdo->prepare("
        INSERT INTO `notifications` (`user_id`, `message`, `link`, `is_read`, `created_at`)
        VALUES (:uid, :msg, :link, FALSE, NOW())
    ");
    $stmt->execute([
        ':uid'  => $userId,
        ':msg'  => $message,
        ':link' => $link
    ]);
}
