<?php

function isLoggedIn()
{
  return isset($_SESSION['user_id']);
}

function requireLogin()
{
  if (!isLoggedIn()) {
    header("Location: ../login.php");
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

  if (!hasRole($allowedRoles)) {

    http_response_code(403);

    echo "<h1>403 - Access Denied</h1>";
    echo "<p>You do not have permission to access this page.</p>";

    exit;
  }
}