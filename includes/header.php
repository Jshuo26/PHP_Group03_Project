<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

$isLoggedIn = !empty($_SESSION['user_id']);

$isLoginPage = basename($_SERVER['PHP_SELF']) === 'login.php';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>aklAAAt! - Online Bookstore</title>

    <link rel="icon" type="image/png" href="/images/logo/aklAAAt!-favicon.png">
    <link rel="apple-touch-icon" href="/images/logo/aklAAAt!-favicon.png">

    <link rel="stylesheet" href="/css/styles.css">

</head>

<body>

<header class="site-header">

    <div class="header-container">

        <a href="/index.php" class="brand">

            <img
                src="/images/logo/aklAAAt!-wordmark.png"
                alt="aklAAAt! Online Bookstore"
                class="site-logo"
            >

        </a>

        <nav class="main-navigation">

            <a class="nav-link" href="/index.php">
                Featured
            </a>

            <a class="nav-link" href="/books.php">
                Books
            </a>

            <a class="nav-link" href="/genres.php">
                Genres
            </a>

            <a class="nav-link" href="/authors.php">
                Filipino Authors
            </a>

            <?php if ($isLoggedIn && ($_SESSION['role'] ?? '') === 'customer'): ?>
                <a class="nav-link" href="/account.php">My Account</a>
            <?php elseif ($isLoggedIn && in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)): ?>
                <a class="nav-link" href="/admin/index.php">Admin</a>
            <?php endif; ?>

        </nav>

        <div class="header-actions">

        <form class="search-form" action="/books.php" method="GET">

                <input
                    type="search"
                    name="search"
                    class="search-input"
                    placeholder="Search titles, authors..."
                    aria-label="Search books and authors"
                >

            </form>

            <a href="/cart.php" class="cart-link" aria-label="Shopping cart">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    class="cart-icon"
                    aria-hidden="true"
                >
                    <path d="M1 1.75A.75.75 0 0 1 1.75 1h1.628a1.75 1.75 0 0 1 1.734 1.51L5.18 3a65.25 65.25 0 0 1 13.36 1.412.75.75 0 0 1 .58.875 48.645 48.645 0 0 1-1.618 6.2.75.75 0 0 1-.712.513H6a2.503 2.503 0 0 0-2.292 1.5H17.25a.75.75 0 0 1 0 1.5H2.76a.75.75 0 0 1-.748-.807 4.002 4.002 0 0 1 2.716-3.486L3.626 2.716a.25.25 0 0 0-.248-.216H1.75A.75.75 0 0 1 1 1.75ZM6 17.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM15.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" />
                </svg>

            </a>

            <?php if (!$isLoggedIn && !$isLoginPage): ?>

                <a class="login-button" href="/login.php">
                    Login / Register
                </a>

            <?php elseif ($isLoggedIn): ?>

                <form class="logout-form" method="post" action="/logout.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="login-button" type="submit">Log out</button>
                </form>

            <?php endif; ?>

        </div>

    </div>

</header>

<main class="main-content">