<?php
declare(strict_types=1);

/**
 * Tool Manager - Modern Enterprise Workflow & Database Engine
 * Hardened Security, Session Management, CSRF Protection & Role Access
 */

// Start session securely if not active
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
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
            error_log("Database connection error: " . $e->getMessage());
            http_response_code(500);
            if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Service temporarily unavailable. Unable to connect to database.'
                ]);
            } else {
                echo "<div style='font-family:sans-serif;padding:2.5rem;background:#0a0c16;color:#f87171;border-radius:12px;margin:2rem auto;max-width:600px;border:1px solid rgba(239,68,68,0.3);'>";
                echo "<h2 style='margin-bottom:0.75rem;font-size:1.4rem;'>Service Temporarily Unavailable</h2>";
                echo "<p style='color:#94a3b8;'>The system encountered an error connecting to the database engine. Please check configuration or verify that MySQL is running.</p>";
                echo "</div>";
            }
            exit;
        }
    }

    return $pdo;
}

/**
 * Get or initialize current user's CSRF token
 *
 * @return string
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a provided CSRF token against session
 *
 * @param string|null $token
 * @return bool
 */
function validateCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Enforce CSRF token verification for state-changing HTTP methods
 *
 * @return void
 */
function requireCsrfApi(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $input = getJsonInput();
        $bodyToken = $input['csrf_token'] ?? ($_POST['csrf_token'] ?? null);
        $token = $headerToken ?: $bodyToken;

        if (!validateCsrfToken($token)) {
            jsonResponse([
                'success' => false,
                'error'   => 'Security verification failed: Invalid or missing CSRF token. Please refresh.'
            ], 403);
        }
    }
}

/**
 * Simple in-session rate limiting helper
 *
 * @param string $key Action identifier (e.g. 'login', 'register')
 * @param int $maxAttempts
 * @param int $decaySeconds
 * @return bool True if within limits, False if exceeded
 */
function checkRateLimit(string $key, int $maxAttempts = 5, int $decaySeconds = 60): bool
{
    $now = time();
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [];
    }
    // Filter attempts within time window
    $_SESSION['rate_limits'][$key] = array_filter(
        $_SESSION['rate_limits'][$key],
        fn(int $ts) => ($now - $ts) < $decaySeconds
    );

    if (count($_SESSION['rate_limits'][$key]) >= $maxAttempts) {
        return false;
    }

    $_SESSION['rate_limits'][$key][] = $now;
    return true;
}

/**
 * Reset rate limit tracker on successful action
 *
 * @param string $key
 * @return void
 */
function resetRateLimit(string $key): void
{
    unset($_SESSION['rate_limits'][$key]);
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
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }

    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        $parsed = [];
        return $parsed;
    }

    $data = json_decode($raw, true);
    $parsed = is_array($data) ? $data : [];
    return $parsed;
}

/**
 * Enforce authentication for API requests
 *
 * @param bool $enforceCsrf
 * @return array Session User data
 */
function requireAuthApi(bool $enforceCsrf = true): array
{
    if (!isset($_SESSION['user_id'])) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized access. Please log in.'
        ], 401);
    }

    if ($enforceCsrf) {
        requireCsrfApi();
    }

    return [
        'id'           => (int)$_SESSION['user_id'],
        'username'     => (string)($_SESSION['username'] ?? ''),
        'email'        => (string)($_SESSION['email'] ?? ''),
        'avatar_color' => (string)($_SESSION['avatar_color'] ?? '#6366f1'),
        'csrf_token'   => getCsrfToken()
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
        'username'     => (string)($_SESSION['username'] ?? ''),
        'email'        => (string)($_SESSION['email'] ?? ''),
        'avatar_color' => (string)($_SESSION['avatar_color'] ?? '#6366f1'),
        'csrf_token'   => getCsrfToken()
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
 * Get user's role in a project ('owner', 'admin', 'member', or null if no access)
 *
 * @param int $projectId
 * @param int $userId
 * @param PDO $pdo
 * @return string|null
 */
function getProjectMemberRole(int $projectId, int $userId, PDO $pdo): ?string
{
    $stmt = $pdo->prepare("SELECT owner_id FROM `projects` WHERE `id` = :pid LIMIT 1");
    $stmt->execute([':pid' => $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        return null;
    }

    if ((int)$project['owner_id'] === $userId) {
        return 'owner';
    }

    $stmt = $pdo->prepare("
        SELECT role FROM `project_members` 
        WHERE `project_id` = :pid AND `user_id` = :uid
        LIMIT 1
    ");
    $stmt->execute([':pid' => $projectId, ':uid' => $userId]);
    $role = $stmt->fetchColumn();

    return $role ? (string)$role : null;
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
    $role = getProjectMemberRole($projectId, $userId, $pdo);

    if ($role === null) {
        return false;
    }

    if ($requireAdmin) {
        return in_array($role, ['owner', 'admin'], true);
    }

    return true;
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
