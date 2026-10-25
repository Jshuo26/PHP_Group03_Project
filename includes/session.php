
<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}

$session_timeout_minutes = 2;

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/audit.php';

    $stmt = $pdo->query(
        'SELECT session_timeout_minutes
         FROM security_policy
         WHERE policy_id = 1
         LIMIT 1'
    );

    $timeout = $stmt->fetchColumn();

    if ($timeout !== false) {
        $session_timeout_minutes = (int) $timeout;
    }
} catch (Throwable $e) {
    error_log('Unable to load session timeout setting: ' . $e->getMessage());
}

$session_timeout_minutes = max(1, min(1440, $session_timeout_minutes));
$session_timeout = $session_timeout_minutes * 60;
$_SESSION['session_timeout_minutes'] = $session_timeout_minutes;


if (!empty($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    $inactive_time = time() - (int) $_SESSION['last_activity'];

    if ($inactive_time >= $session_timeout) {
        $expiredRole = $_SESSION['role'] ?? 'customer';
        if (isset($pdo) && $pdo instanceof PDO && function_exists('auditLog')) {
            auditLog($pdo, 'session_expired', 'User session expired after inactivity.', 'authentication', (int) $_SESSION['user_id'], 'session', null, 'info', (string) $expiredRole);
        }
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        session_id('');
        session_start();

        $_SESSION['session_timeout_message'] =
            'You were logged out because of inactivity. Please log in again.';

        $isApiRequest = strpos(
            str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''),
            '/api/'
        ) !== false;

        if ($isApiRequest) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => 'Your session expired. Please log in again.'
            ]);

            exit;
        }

        if (in_array($expiredRole, ['admin', 'staff'], true)) {
            header('Location: /admin/login.php?timeout=1');
        } else {
            header('Location: /auth/login.php?timeout=1');
        }

        exit;
    }
}


if (!empty($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}
