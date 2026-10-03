<?php

function generateCsrfToken()
{
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }

  return $_SESSION['csrf_token'];
}

function validateCsrfToken($token)
{
  if (
    empty($token) ||
    empty($_SESSION['csrf_token'])
  ) {
    return false;
  }

  return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken()
{
  if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !validateCsrfToken($_POST['csrf_token'] ?? '')
  ) {

    http_response_code(403);

    echo "<h1>403 - Invalid Request</h1>";
    echo "<p>The security token is invalid or expired.</p>";

    exit;
  }
}