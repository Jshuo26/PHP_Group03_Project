<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? '';
$userAvatar = $_SESSION['profile_picture_path'] ?? '';
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isAdminAreaRequest = strpos($scriptPath, '/admin/') !== false;
$isAdminPortal = in_array($userRole, ['admin', 'staff'], true)
    || $isAdminAreaRequest;
$brandUrl = $isAdminPortal ? '/admin/login.php' : '/index.php';
if ($isLoggedIn && in_array($userRole, ['admin', 'staff'], true)) {
    $brandUrl = '/admin/index.php';
}

$isLoginPage = basename($_SERVER['PHP_SELF']) === 'login.php';
$sessionTimeoutMinutes = (int) ($_SESSION['session_timeout_minutes'] ?? 2);

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
    <link rel="stylesheet" href="/css/header.css">

    <?php if (($_SERVER['SCRIPT_NAME'] ?? '') === '/admin/index.php' && ($_SESSION['role'] ?? '') === 'admin'): ?>
        <link rel="stylesheet" href="/css/admin-dashboard.css">
    <?php endif; ?>

    <?php if (basename($_SERVER['PHP_SELF']) === 'featured.php'): ?>
        <link rel="stylesheet" href="/css/featured.css">
    <?php endif; ?>

    <?php if (basename($_SERVER['PHP_SELF']) === 'cart.php'): ?>
        <link rel="stylesheet" href="/css/cart.css">
    <?php endif; ?>

</head>

<body>

<header class="site-header">

    <div class="header-container<?php echo $isAdminPortal ? ' admin-portal-header' : ''; ?>">

        <a href="<?php echo htmlspecialchars($brandUrl, ENT_QUOTES, 'UTF-8'); ?>" class="brand">

            <img
                src="/images/logo/aklAAAt!-wordmark.png"
                alt="aklAAAt! Online Bookstore"
                class="site-logo"
            >
        </a>

        <nav class="main-navigation<?php echo $isAdminPortal ? ' admin-portal-navigation' : ''; ?>" aria-label="Main navigation">

            <?php if ($isAdminPortal): ?>
                <?php if ($isLoggedIn && in_array($userRole, ['admin', 'staff'], true)): ?>
                    <a class="nav-link" href="/admin/index.php">Dashboard</a>
                    <a class="nav-link" href="/admin/products.php">Books</a>
                    <a class="nav-link" href="/admin/orders.php">Orders</a>
                    <a class="nav-link" href="/admin/author_reviews.php">Author Reviews</a>
                    <?php if ($userRole === 'admin'): ?>
                        <a class="nav-link" href="/admin/users.php">Staff &amp; Roles</a>
                        <a class="nav-link" href="/admin/audit_logs.php">Activity</a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
            <a class="nav-link" href="/featured.php">
                Featured
            </a>

            <a class="nav-link" href="/books.php">
                Books
            </a>

            <a class="nav-link" href="/authors.php">
                Filipino Authors
            </a>

            <?php endif; ?>

        </nav>

        <div class="header-actions">

            <?php if (!$isAdminPortal): ?>
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
            <?php endif; ?>

            <?php if ($isLoggedIn && $userRole === 'customer' && !$isAdminPortal): ?>

                <div class="account-menu">

                    <button
                        type="button"
                        class="account-menu-button"
                        aria-label="Account menu"
                        aria-expanded="false"
                    >
                        <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/..' . $userAvatar)): ?>
                            <img 
                                src="<?php echo htmlspecialchars($userAvatar, ENT_QUOTES, 'UTF-8'); ?>" 
                                alt="Account" 
                                class="account-header-avatar"
                            >
                        <?php else: ?>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                class="account-icon"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M4 21a8 8 0 0 1 16 0"></path>
                            </svg>
                        <?php endif; ?>
                    </button>

                    <div class="account-dropdown">

                        <a href="/account/" class="account-dropdown-link">
                            Account
                        </a>

                        <a href="/account/orders.php" class="account-dropdown-link">
                            Orders
                        </a>

                        <div class="account-dropdown-divider"></div>

                        <button
                            type="button"
                            class="account-dropdown-link account-logout-button dropdown-danger"
                        >
                            Logout
                        </button>

                    </div>

                </div>

            <?php elseif (!$isLoggedIn && !$isLoginPage): ?>

                <a class="login-button" href="/auth/login.php">
                    Login / Register
                </a>

            <?php elseif ($isLoggedIn && in_array($userRole, ['admin', 'staff'], true)): ?>

                <form class="logout-form" method="post" action="/auth/logout.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars(
                            generateCsrfToken(),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                    >

                    <button class="login-button" type="submit">
                        Log out
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>


</header>

<?php if ($isLoggedIn && $userRole === 'customer'): ?>

<div class="logout-modal" id="logoutModal" hidden role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
    <div class="logout-modal-content">
        <div class="logout-modal-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </div>
        <h2 id="logoutModalTitle">Log out of your account?</h2>
        <p>You will need to sign in again to access your account.</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn btn-outline logout-cancel-button">Cancel</button>
            <form method="post" action="/auth/logout.php" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button class="btn btn-danger" type="submit">Log Out</button>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<main class="main-content">

<?php if ($isLoggedIn): ?>
    <script>
        window.sessionTimeoutMinutes = <?php echo $sessionTimeoutMinutes; ?>;
    </script>
    <script src="/js/session-timeout.js"></script>
<?php endif; ?>
