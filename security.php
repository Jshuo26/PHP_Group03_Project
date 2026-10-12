<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

requireRole(['customer']);

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

$profileStmt = $pdo->prepare(
    'SELECT full_name, email FROM users WHERE user_id = ?'
);
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit('Account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = requestString($_POST, 'action');

    if ($action === 'change_password') {
        $currentPassword = requestString($_POST, 'current_password');
        $newPassword = requestString($_POST, 'new_password');
        $confirmPassword = requestString($_POST, 'confirm_password');

        if ($currentPassword === '') {
            $errors[] = 'Enter your current password.';
        }

        if (
            strlen($newPassword) < 12 ||
            strlen($newPassword) > 72 ||
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword) ||
            !preg_match('/[^a-zA-Z0-9]/', $newPassword)
        ) {
            $errors[] = 'New password must be 12 to 72 characters and include uppercase, lowercase, number, and special characters.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (!$errors) {
            $passwordStmt = $pdo->prepare(
                'SELECT password_hash FROM users WHERE user_id = ?'
            );
            $passwordStmt->execute([$userId]);

            $passwordHash = (string) $passwordStmt->fetchColumn();

            if (!password_verify($currentPassword, $passwordHash)) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        if (!$errors) {
            try {
                $updateStmt = $pdo->prepare(
                    'UPDATE users SET password_hash = ? WHERE user_id = ?'
                );
                $updateStmt->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $userId
                ]);

                $success = 'Password changed successfully.';
            } catch (PDOException $e) {
                error_log('Password update failed: ' . $e->getMessage());
                $errors[] = 'Unable to change your password right now.';
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="account-page-layout container">

    <!-- ========== SIDEBAR ========== -->
    <aside class="account-sidebar">
        <nav class="account-sidebar-nav" aria-label="Account navigation">

            <p class="account-sidebar-section-label">Settings</p>

            <a href="/account.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
                Account
            </a>

            <a href="/security.php" class="account-sidebar-item active" aria-current="page">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Security
            </a>

            <a href="/addresses.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                    <circle cx="12" cy="9" r="2.5"/>
                </svg>
                Manage Addresses
            </a>

            <div class="account-sidebar-divider"></div>

            <a href="/orders.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="1"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="13" y2="16"/>
                </svg>
                Orders
            </a>

            <div class="account-sidebar-divider"></div>

            <button type="button" class="account-sidebar-item account-sidebar-logout account-logout-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Logout
            </button>

        </nav>
    </aside>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="account-main-content">

        <div class="page-heading">
            <h1 class="page-title">Security Settings</h1>
            <p class="page-subtitle">
                Manage your password and account security preferences.
            </p>
        </div>

        <?php if ($errors): ?>
            <div class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="form-success" role="status">
                <p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>

        <!-- Change Password Section -->
        <div class="settings-section">
            <div class="settings-section-heading">
                <h2>Change Password</h2>
                <p>Update your password to help keep your account secure.</p>
            </div>

            <div class="settings-card">
                <form method="post" action="/security.php" class="settings-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label for="current_password">Current password</label>
                        <input
                            class="form-input"
                            type="password"
                            id="current_password"
                            name="current_password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="new_password">New password</label>
                        <input
                            class="form-input"
                            type="password"
                            id="new_password"
                            name="new_password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <input
                            class="form-input"
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <p class="password-requirements">
                        Password must be 12–72 characters and include uppercase,
                        lowercase, number, and special characters.
                    </p>

                    <button class="btn btn-primary" type="submit">
                        Update Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Multi-Factor Authentication Section -->
        <div class="settings-section">
            <div class="settings-section-heading">
                <h2>Multi-Factor Authentication</h2>
                <p>Add an extra layer of protection to your account.</p>
            </div>

            <div class="settings-card">
                <div class="security-feature-row">
                    <div class="security-feature-info">
                        <div class="security-feature-title-wrap">
                            <h3>Google Authenticator</h3>
                            <span class="badge-coming-soon">Coming Soon</span>
                        </div>
                        <p class="security-feature-desc">
                            Use an authenticator application to generate verification codes when signing in.
                        </p>
                    </div>
                    <div class="security-feature-action">
                        <button type="button" class="btn btn-outline btn-sm" disabled aria-disabled="true">
                            Set Up
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Sessions Section -->
        <div class="settings-section">
            <div class="settings-section-heading">
                <h2>Active Sessions</h2>
                <p>Manage devices and browsers signed into your account.</p>
            </div>

            <div class="settings-card">
                <div class="security-feature-row">
                    <div class="security-feature-info">
                        <div class="security-feature-title-wrap">
                            <h3>Current Session</h3>
                            <span class="session-active-indicator">Active Now</span>
                        </div>
                        <p class="security-feature-desc">
                            This device &bull; Web browser
                        </p>
                    </div>
                    <div class="security-feature-action">
                        <button type="button" class="btn btn-outline btn-sm" disabled aria-disabled="true">
                            Sign Out
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.account-main-content -->

</div><!-- /.account-page-layout -->

<?php include __DIR__ . '/includes/footer.php'; ?>
