<?php

function isLoggedIn()
{
  return isset($_SESSION['user_id']);
}

function loginUrl()
{
  $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

  return strpos($script, '/admin/') !== false
    ? '/admin/login.php'
    : '/auth/login.php';
}

function requireLogin()
{
  if (!isLoggedIn()) {
    header("Location: " . loginUrl());
    exit;
  }
}

function hasRole($allowedRoles)
{
  if (!isLoggedIn()) {
    return false;
  }

  if (!isset($_SESSION['role'])) {
    return false;
  }

  return in_array($_SESSION['role'], $allowedRoles, true);
}

function requireRole($allowedRoles)
{
  requireLogin();

  // Refresh the role and account state from the database so staff deactivation
  // or role changes take effect on the next protected request.
  require_once __DIR__ . '/db.php';
  global $pdo;
  $accessStmt = $pdo->prepare('SELECT role, account_status FROM users WHERE user_id = ? LIMIT 1');
  $accessStmt->execute([(int) $_SESSION['user_id']]);
  $access = $accessStmt->fetch();
  if (!$access || $access['account_status'] !== 'active') {
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name'], $_SESSION['email'], $_SESSION['mfa_verified']);
    header('Location: ' . loginUrl() . '?access=revoked');
    exit;
  }
  $_SESSION['role'] = (string) $access['role'];
  if (in_array($_SESSION['role'] ?? null, ['admin', 'staff'], true)
      && empty($_SESSION['mfa_verified'])) {
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name'], $_SESSION['email']);
    header('Location: /admin/login.php?mfa_required=1');
    exit;
  }

  if (!hasRole($allowedRoles)) {

    http_response_code(403);

    echo "<h1>403 - Access Denied</h1>";
    echo "<p>You do not have permission to access this page.</p>";

    exit;
  }
}