<?php

if (session_status() === PHP_SESSION_NONE) {
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