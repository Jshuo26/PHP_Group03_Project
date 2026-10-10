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

$session_timeout = 1800;

if (isset($_SESSION['last_activity'])) {

  $inactive_time = time() - $_SESSION['last_activity'];

  if ($inactive_time > $session_timeout) {

    $_SESSION = [];
    session_destroy();
  }
}

$_SESSION['last_activity'] = time();