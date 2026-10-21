<?php
/**
 * Tool Manager - Comprehensive Automated Test Suite
 * End-to-End Verification: Auth, Rate Limiting, CSRF, RBAC, Task Management, Profile, and Database
 * 
 * Run with: php tests/run_tests.php
 */

declare(strict_types=1);

// Prevent session warning in CLI test runner
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . '/../config/db.php';

$baseUrl = 'http://127.0.0.1/project%20managing%20tool';

class TestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function section(string $title): void {
        echo "\n\033[1;36m======================================================================\033[0m\n";
        echo "\033[1;36m  " . strtoupper($title) . "\033[0m\n";
        echo "\033[1;36m======================================================================\033[0m\n";
    }

    public function test(string $description, callable $fn): void {
        try {
            $result = $fn();
            if ($result === true || $result === null) {
                $this->passed++;
                echo "  \033[32m✔ PASS\033[0m: {$description}\n";
            } else {
                $this->failed++;
                $this->failures[] = $description . " (returned false)";
                echo "  \033[31m✘ FAIL\033[0m: {$description}\n";
            }
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = $description . " -> " . $e->getMessage();
            echo "  \033[31m✘ FAIL\033[0m: {$description}\n";
            echo "         \033[33mException: " . $e->getMessage() . "\033[0m\n";
        }
    }

    public function summary(): void {
        echo "\n\033[1;37m----------------------------------------------------------------------\033[0m\n";
        echo "TEST SUMMARY: Total: " . ($this->passed + $this->failed) . " | ";
        echo "\033[32mPassed: {$this->passed}\033[0m | ";
        if ($this->failed > 0) {
            echo "\033[31mFailed: {$this->failed}\033[0m\n";
            echo "\nFailed Assertions:\n";
            foreach ($this->failures as $i => $msg) {
                echo "  " . ($i + 1) . ". {$msg}\n";
            }
            exit(1);
        } else {
            echo "\033[1;32mALL TESTS PASSED PERFECTLY!\033[0m\n";
            exit(0);
        }
    }
}

class TestClient {
    public string $baseUrl;
    public string $sessionId = '';
    public string $csrfToken = '';

    public function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function resetSession(): void {
        $this->sessionId = '';
        $this->csrfToken = '';
    }

    public function request(string $endpoint, string $method = 'GET', array $data = [], array $headers = []): array {
        $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init();

        $defaultHeaders = ['Accept: application/json'];
        if ($this->sessionId) {
            $defaultHeaders[] = 'Cookie: PHPSESSID=' . $this->sessionId;
        }

        $hasCustomCsrf = false;
        foreach ($headers as $h) {
            if (str_starts_with(strtolower(trim($h)), 'x-csrf-token:')) {
                $hasCustomCsrf = true;
                break;
            }
        }

        if (!$hasCustomCsrf && $this->csrfToken && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $defaultHeaders[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }

        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if (!empty($data)) {
            $isForm = false;
            foreach ($allHeaders as $h) {
                if (str_contains(strtolower($h), 'content-type: application/x-www-form-urlencoded')) {
                    $isForm = true;
                    break;
                }
            }

            if ($isForm) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                $hasJsonHeader = false;
                foreach ($allHeaders as $h) {
                    if (str_starts_with(strtolower($h), 'content-type:')) {
                        $hasJsonHeader = true;
                        break;
                    }
                }
                if (!$hasJsonHeader) {
                    $allHeaders[] = 'Content-Type: application/json';
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL Error: " . $err);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $json = json_decode($body, true);

        // Update session ID if Set-Cookie was sent
        if (preg_match_all('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $headerStr, $cookieMatches)) {
            $this->sessionId = end($cookieMatches[1]);
        }

        // Update CSRF token if meta tag was present
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/i', $body, $metaMatches)) {
            $this->csrfToken = $metaMatches[1];
        } elseif (preg_match('/name="csrf_token" value="([a-f0-9]+)"/i', $body, $formMatches)) {
            $this->csrfToken = $formMatches[1];
        }

        return [
            'status' => $httpCode,
            'header' => $headerStr,
            'body'   => $body,
            'json'   => $json
        ];
    }

    public function login(string $email, string $password): bool {
        // Step 1: GET index.php to obtain fresh session and CSRF token
        $initRes = $this->request('index.php', 'GET');
        if (empty($this->csrfToken)) {
            throw new Exception("CSRF token could not be extracted from index.php (HTTP {$initRes['status']})");
        }

        // Step 2: POST credentials with form encoding
        $loginRes = $this->request('index.php', 'POST', [
            'auth_action' => 'login',
            'email'       => $email,
            'password'    => $password,
            'csrf_token'  => $this->csrfToken
        ], ['Content-Type: application/x-www-form-urlencoded']);

        if ($loginRes['status'] === 302 && str_contains($loginRes['header'], 'dashboard.php')) {
            // Load dashboard to extract updated CSRF token for the authenticated session
            $dashRes = $this->request('dashboard.php', 'GET');
            if ($dashRes['status'] === 200 && str_contains($dashRes['body'], 'Workspace Dashboard')) {
                return true;
            }
            throw new Exception("Redirect to dashboard failed (HTTP {$dashRes['status']})");
        }

        if (preg_match('/<div id="auth-alert"[^>]*>(.*?)<\/div>/is', $loginRes['body'], $alert)) {
            $msg = trim(strip_tags($alert[1]));
            throw new Exception("Login failed (HTTP {$loginRes['status']}): {$msg}");
        }

        throw new Exception("Login failed with HTTP {$loginRes['status']}");
    }
}

// ============================================================================
// EXECUTE SUITES
// ============================================================================
$runner = new TestRunner();
$alex = new TestClient($baseUrl);
$sarah = new TestClient($baseUrl);

// ----------------------------------------------------------------------------
// SUITE 1: DATABASE & SCHEMA INTEGRITY
// ----------------------------------------------------------------------------
$runner->section('Suite 1: Database Connectivity & Schema Architecture');

$runner->test('Database connection and required tables exist', function() {
    $pdo = getDBConnection();
    if (!$pdo) throw new Exception('PDO connection is null');

    $tables = ['users', 'projects', 'project_members', 'columns', 'tasks', 'task_assignments', 'comments', 'notifications'];
    $stmt = $pdo->query("SHOW TABLES");
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $t) {
        if (!in_array($t, $existing)) {
            throw new Exception("Missing required table: {$t}");
        }
    }
    return true;
});

$runner->test('Seed users exist with verified bcrypt password hashes', function() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT email, password_hash FROM `users` WHERE email IN ('alex@example.com', 'sarah@example.com')");
    $users = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!isset($users['alex@example.com'])) throw new Exception('alex@example.com missing from database');
    if (!password_verify('password123', $users['alex@example.com'])) throw new Exception('Password verification failed for Alex');
    if (!password_verify('password123', $users['sarah@example.com'])) throw new Exception('Password verification failed for Sarah');
    return true;
});

// ----------------------------------------------------------------------------
// SUITE 2: AUTHENTICATION, RATE LIMITING & SECURITY
// ----------------------------------------------------------------------------
$runner->section('Suite 2: Authentication Security & Rate Limiting');

$runner->test('Login rejected with invalid credentials', function() use ($alex) {
    $alex->resetSession();
    $initRes = $alex->request('index.php', 'GET');

    $badLogin = $alex->request('index.php', 'POST', [
        'auth_action' => 'login',
        'email'       => 'alex@example.com',
        'password'    => 'wrong_password_xyz',
        'csrf_token'  => $alex->csrfToken
    ], ['Content-Type: application/x-www-form-urlencoded']);

    if ($badLogin['status'] !== 200 || !str_contains($badLogin['body'], 'Invalid email or password')) {
        throw new Exception("Expected rejection message on invalid login");
    }
    return true;
});

$runner->test('Rate limit triggers after exceeding failed login threshold', function() use ($alex) {
    $triggered = false;
    for ($i = 0; $i < 6; $i++) {
        $attempt = $alex->request('index.php', 'POST', [
            'auth_action' => 'login',
            'email'       => 'alex@example.com',
            'password'    => 'wrong_attempt_' . $i,
            'csrf_token'  => $alex->csrfToken
        ], ['Content-Type: application/x-www-form-urlencoded']);

        if (str_contains($attempt['body'], 'Too many attempts') || str_contains($attempt['body'], 'Please wait')) {
            $triggered = true;
            break;
        }
    }
    if (!$triggered) throw new Exception('Rate limit failed to block rapid invalid login attempts');
    return true;
});

$runner->test('Valid demo login succeeds and generates session + CSRF token', function() use ($alex) {
    // Reset session to clear failed rate limit counter for valid login test
    $alex->resetSession();

    $success = $alex->login('alex@example.com', 'password123');
    if (!$success) throw new Exception('Alex login failed');
    if (empty($alex->csrfToken)) throw new Exception('CSRF token not extracted after login');

    // Confirm dashboard accessibility
    $dash = $alex->request('dashboard.php', 'GET');
    if ($dash['status'] !== 200 || !str_contains($dash['body'], 'Workspace Dashboard')) {
        throw new Exception('Dashboard page was not loaded successfully');
    }
    return true;
});

// ----------------------------------------------------------------------------
// SUITE 3: CSRF PROTECTION ON MUTATING APIS
// ----------------------------------------------------------------------------
$runner->section('Suite 3: CSRF Defense Architecture');

$runner->test('Mutating POST request without X-CSRF-Token is blocked with HTTP 403', function() use ($alex) {
    $res = $alex->request('api/projects.php', 'POST', [
        'name' => 'Should Be Blocked Project'
    ], [
        'X-CSRF-Token: ' // empty header overrides client default
    ]);

    if ($res['status'] !== 403) {
        throw new Exception("Expected HTTP 403 for missing CSRF, got HTTP " . $res['status']);
    }
    if (!str_contains($res['json']['error'] ?? '', 'CSRF token')) {
        throw new Exception("Unexpected error message: " . ($res['json']['error'] ?? ''));
    }
    return true;
});

$runner->test('Mutating POST request with tampered X-CSRF-Token is blocked with HTTP 403', function() use ($alex) {
    $res = $alex->request('api/projects.php', 'POST', [
        'name' => 'Should Be Blocked Project'
    ], [
        'X-CSRF-Token: bad_tampered_token_99999999999999'
    ]);

    if ($res['status'] !== 403) {
        throw new Exception("Expected HTTP 403 for tampered CSRF, got HTTP " . $res['status']);
    }
    return true;
});

// ----------------------------------------------------------------------------
// SUITE 4: PROJECT WORKFLOWS & RBAC PERMISSION CONTROLS
// ----------------------------------------------------------------------------
$runner->section('Suite 4: Project Lifecycle & Role-Based Access Control');

$testProjectId = null;
$createdColumnId = null;

$runner->test('Admin/Owner can create a new project with auto-generated workflow stages', function() use ($alex, &$testProjectId) {
    $res = $alex->request('api/projects.php', 'POST', [
        'name'        => 'Automated Test Flow Project',
        'description' => 'Test project initialized via automated test suite'
    ]);

    if ($res['status'] !== 201 || !($res['json']['success'] ?? false)) {
        throw new Exception("Project creation failed: " . ($res['json']['error'] ?? json_encode($res)));
    }

    $testProjectId = (int)$res['json']['data']['id'];

    // Verify auto-generated columns
    $colsRes = $alex->request("api/columns.php?project_id={$testProjectId}", 'GET');
    if (!isset($colsRes['json']['data']) || count($colsRes['json']['data']) !== 4) {
        throw new Exception("Expected 4 default columns, found: " . count($colsRes['json']['data'] ?? []));
    }
    return true;
});

$runner->test('Admin/Owner can invite a member to the project', function() use ($alex, &$testProjectId) {
    $res = $alex->request('api/projects.php?action=add_member', 'POST', [
        'project_id' => $testProjectId,
        'user_id'    => 2,
        'role'       => 'member'
    ]);

    if ($res['status'] !== 200 && $res['status'] !== 201) {
        throw new Exception("Failed to invite member: " . ($res['json']['error'] ?? json_encode($res)));
    }
    return true;
});

$runner->test('Member is blocked from column creation (HTTP 403 RBAC enforcement)', function() use ($sarah, &$testProjectId) {
    $sarah->resetSession();
    $sarahLogin = $sarah->login('sarah@example.com', 'password123');
    if (!$sarahLogin) throw new Exception('Sarah login failed');

    $res = $sarah->request('api/columns.php', 'POST', [
        'project_id' => $testProjectId,
        'name'       => 'Unauthorized Member Column'
    ]);

    if ($res['status'] !== 403) {
        throw new Exception("Expected HTTP 403 for non-admin column creation, got HTTP " . $res['status']);
    }
    return true;
});

$runner->test('Owner can promote Member to Admin, enabling administrative capabilities', function() use ($alex, $sarah, &$testProjectId, &$createdColumnId) {
    // Alex promotes Sarah to 'admin'
    $promoRes = $alex->request('api/projects.php?action=update_member_role', 'PUT', [
        'project_id' => $testProjectId,
        'user_id'    => 2,
        'role'       => 'admin'
    ]);

    if (!($promoRes['json']['success'] ?? false)) {
        throw new Exception("Failed to promote member to admin: " . ($promoRes['json']['error'] ?? ''));
    }

    // Now Sarah creates a column
    $colRes = $sarah->request('api/columns.php', 'POST', [
        'project_id' => $testProjectId,
        'name'       => 'Sarah Admin Stage'
    ]);

    if ($colRes['status'] !== 201 || !($colRes['json']['success'] ?? false)) {
        throw new Exception("Promoted admin failed to create column: " . ($colRes['json']['error'] ?? ''));
    }

    $createdColumnId = (int)$colRes['json']['data']['id'];
    return true;
});

$runner->test('Admin can reorder columns batch-wise', function() use ($sarah, &$testProjectId) {
    $colsRes = $sarah->request("api/columns.php?project_id={$testProjectId}", 'GET');
    $cols = $colsRes['json']['data'] ?? [];
    $colIds = array_column($cols, 'id');

    // Reverse order
    $reversed = array_reverse($colIds);

    $reorderRes = $sarah->request('api/columns.php', 'PUT', [
        'project_id' => $testProjectId,
        'column_ids' => $reversed
    ]);

    if (!($reorderRes['json']['success'] ?? false)) {
        throw new Exception("Failed to batch reorder columns: " . ($reorderRes['json']['error'] ?? ''));
    }
    return true;
});

// ----------------------------------------------------------------------------
// SUITE 5: TASK MANAGEMENT, ATOMIC POSITIONING & COMMENTS
// ----------------------------------------------------------------------------
$runner->section('Suite 5: Task Management, Atomic Moves & Comments');

$testTaskId = null;

$runner->test('Create task with priority, due date, and assignees', function() use ($sarah, &$createdColumnId, &$testTaskId) {
    $res = $sarah->request('api/tasks.php', 'POST', [
        'column_id'    => $createdColumnId,
        'title'        => 'Automated Flow Verification Task',
        'description'  => 'Checking persistence, SSE trigger and comments',
        'priority'     => 'urgent',
        'due_date'     => date('Y-m-d', strtotime('+3 days')),
        'assignee_ids' => [1, 2]
    ]);

    if ($res['status'] !== 201 || !($res['json']['success'] ?? false)) {
        throw new Exception("Task creation failed: " . ($res['json']['error'] ?? ''));
    }

    $testTaskId = (int)$res['json']['data']['id'];
    if (($res['json']['data']['priority'] ?? '') !== 'urgent') {
        throw new Exception("Priority mismatch");
    }
    return true;
});

$runner->test('Atomic batch column task reorder persists positions accurately', function() use ($sarah, &$createdColumnId, &$testTaskId) {
    // Create a second task
    $task2 = $sarah->request('api/tasks.php', 'POST', [
        'column_id' => $createdColumnId,
        'title'     => 'Task 2 for Reorder Test',
        'priority'  => 'medium'
    ]);
    $t2Id = (int)$task2['json']['data']['id'];

    // Atomic batch reorder: place Task 2 first, then Task 1
    $reorder = $sarah->request('api/tasks.php', 'PATCH', [
        'action'    => 'reorder_column',
        'column_id' => $createdColumnId,
        'task_ids'  => [$t2Id, $testTaskId]
    ]);

    if (!($reorder['json']['success'] ?? false)) {
        throw new Exception("Atomic reorder failed: " . ($reorder['json']['error'] ?? ''));
    }

    // Verify task positions via database
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, position FROM `tasks` WHERE id IN (?, ?)");
    $stmt->execute([$t2Id, $testTaskId]);
    $posMap = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if ($posMap[$t2Id] !== 0 || $posMap[$testTaskId] !== 1) {
        throw new Exception("Position verification failed. Map: " . json_encode($posMap));
    }
    return true;
});

$runner->test('Post comment to task and verify comment counter synchronization', function() use ($alex, &$testTaskId) {
    $res = $alex->request('api/comments.php', 'POST', [
        'task_id'      => $testTaskId,
        'comment_text' => 'This is an automated test comment from Alex.'
    ]);

    if ($res['status'] !== 201 || !($res['json']['success'] ?? false)) {
        throw new Exception("Failed to post comment: " . ($res['json']['error'] ?? ''));
    }

    // Verify comment retrieval
    $list = $alex->request("api/comments.php?task_id={$testTaskId}", 'GET');
    if (!isset($list['json']['data']) || count($list['json']['data']) < 1) {
        throw new Exception("Expected at least 1 comment in thread");
    }
    return true;
});

$runner->test('Oversized comments (> 5000 chars) are rejected with HTTP 400', function() use ($alex, &$testTaskId) {
    $oversized = str_repeat('X', 5001);
    $res = $alex->request('api/comments.php', 'POST', [
        'task_id'      => $testTaskId,
        'comment_text' => $oversized
    ]);

    if ($res['status'] !== 400) {
        throw new Exception("Expected HTTP 400 for oversized comment, got " . $res['status']);
    }
    return true;
});

$runner->test('Delete task and verify cascading cleanup', function() use ($sarah, &$testTaskId) {
    $res = $sarah->request('api/tasks.php', 'DELETE', [
        'id' => $testTaskId
    ]);

    if (!($res['json']['success'] ?? false)) {
        throw new Exception("Failed to delete task: " . ($res['json']['error'] ?? ''));
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `comments` WHERE task_id = ?");
    $stmt->execute([$testTaskId]);
    if ((int)$stmt->fetchColumn() !== 0) {
        throw new Exception("Task comments were not cascaded");
    }
    return true;
});

// ----------------------------------------------------------------------------
// SUITE 6: USER PROFILE & PASSWORD SECURITY
// ----------------------------------------------------------------------------
$runner->section('Suite 6: Account Profile & Credential Management');

$runner->test('Profile password update rejected if current password is wrong', function() use ($alex) {
    $res = $alex->request('api/projects.php?action=update_profile', 'PUT', [
        'username'         => 'alex_dev',
        'email'            => 'alex@example.com',
        'avatar_color'     => '#6366f1',
        'current_password' => 'wrong_pass_here',
        'new_password'     => 'newpassword123'
    ]);

    if ($res['status'] !== 400 || !str_contains($res['json']['error'] ?? '', 'Current password is incorrect')) {
        throw new Exception("Expected error on invalid current password, got: " . json_encode($res));
    }
    return true;
});

$runner->test('Profile update succeeds with valid data', function() use ($alex) {
    $res = $alex->request('api/projects.php?action=update_profile', 'PUT', [
        'username'     => 'alex_dev',
        'email'        => 'alex@example.com',
        'avatar_color' => '#10b981'
    ]);

    if (!($res['json']['success'] ?? false)) {
        throw new Exception("Failed to update profile: " . ($res['json']['error'] ?? ''));
    }

    // Revert avatar color back
    $alex->request('api/projects.php?action=update_profile', 'PUT', [
        'username'     => 'alex_dev',
        'email'        => 'alex@example.com',
        'avatar_color' => '#6366f1'
    ]);
    return true;
});

// ----------------------------------------------------------------------------
// SUITE 7: TEARDOWN & CLEANUP
// ----------------------------------------------------------------------------
$runner->section('Suite 7: Teardown & State Restoration');

$runner->test('Clean up test project and foreign relationships', function() use ($alex, &$testProjectId) {
    if ($testProjectId) {
        $delRes = $alex->request('api/projects.php', 'DELETE', [
            'id' => $testProjectId
        ]);

        if (!($delRes['json']['success'] ?? false)) {
            throw new Exception("Failed to delete test project: " . ($delRes['json']['error'] ?? ''));
        }
    }
    return true;
});

// Output final summary
$runner->summary();
