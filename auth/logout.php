<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

requireCsrfToken();
if (!empty($_SESSION['user_id'])) {
    auditLog($pdo, 'logout', 'User signed out.', 'authentication', (int) $_SESSION['user_id'], 'session', null, 'success', (string) ($_SESSION['role'] ?? 'unknown'));
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
header('Location: /index.php');
exit;