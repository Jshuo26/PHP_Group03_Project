<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/password_policy.php';

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
$passwordPolicy = getPasswordPolicy($pdo);
$mfaStmt = $pdo->prepare("SELECT 1 FROM mfa_settings WHERE user_id = ? AND method = 'totp' LIMIT 1");
$mfaStmt->execute([$userId]);
$mfaEnabled = (bool) $mfaStmt->fetchColumn();

if (
    isset($_SESSION['pw_change']) &&
    (int) ($_SESSION['pw_change']['user_id'] ?? 0) !== $userId
) {
    unset($_SESSION['pw_change']);
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

        foreach (validatePasswordAgainstPolicy($newPassword, $passwordPolicy) as $passwordError) {
            $errors[] = $passwordError;
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
            } elseif (password_verify($newPassword, $passwordHash)) {
                $errors[] = 'New password must be different from your current password.';
            }
        }

        if (!$errors) {
            $wait = otpResendWait($pdo, $userId, 'password_change');

            if ($wait > 0) {
                $errors[] = "Please wait {$wait} seconds before requesting another code.";
            } else {
                $code = createOtp($pdo, $userId, 'password_change');

                if (sendOtpEmail(
                    $config,
                    $profile['email'],
                    $profile['full_name'],
                    $code,
                    'confirm your password change'
                )) {
                    $_SESSION['pw_change'] = [
                        'user_id' => $userId,
                        'hash'    => password_hash($newPassword, PASSWORD_DEFAULT),
                    ];

                    $success = 'We sent a 6-digit code to ' . maskEmail($profile['email']) . '.';
                } else {
                    $errors[] = 'We could not send the email right now. Please try again later.';
                }
            }
        }

    } elseif ($action === 'confirm_password_change') {

        if (empty($_SESSION['pw_change'])) {
            $errors[] = 'Your password change request expired. Please start again.';
        } else {
            [$ok, $message] = verifyOtp(
                $pdo,
                $userId,
                'password_change',
                requestString($_POST, 'otp_code')
            );

            if (!$ok) {
                $errors[] = $message;
            } else {
                try {
                    $updateStmt = $pdo->prepare(
                        'UPDATE users SET password_hash = ? WHERE user_id = ?'
                    );
                    $updateStmt->execute([
                        $_SESSION['pw_change']['hash'],
                        $userId
                    ]);

                    unset($_SESSION['pw_change']);
                    session_regenerate_id(true);

                    $success = 'Password changed successfully.';
                } catch (PDOException $e) {
                    error_log('Password update failed: ' . $e->getMessage());
                    $errors[] = 'Unable to change your password right now.';
                }
            }
        }

    } elseif ($action === 'resend_password_otp') {

        if (empty($_SESSION['pw_change'])) {
            $errors[] = 'Your password change request expired. Please start again.';
        } else {
            $wait = otpResendWait($pdo, $userId, 'password_change');

            if ($wait > 0) {
                $errors[] = "Please wait {$wait} seconds before requesting another code.";
            } else {
                $code = createOtp($pdo, $userId, 'password_change');

                if (sendOtpEmail(
                    $config,
                    $profile['email'],
                    $profile['full_name'],
                    $code,
                    'confirm your password change'
                )) {
                    $success = 'A new code was sent to ' . maskEmail($profile['email']) . '.';
                } else {
                    $errors[] = 'We could not send the email right now. Please try again later.';
                }
            }
        }

    } elseif ($action === 'cancel_password_change') {

        unset($_SESSION['pw_change']);
    }
}

$otpStep = !empty($_SESSION['pw_change']);
$secondsLeft = $otpStep ? otpSecondsLeft($pdo, $userId, 'password_change') : 0;

function passwordToggleButton(string $target): void
{
    ?>
    <button
        type="button"
        class="password-toggle"
        data-target="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>"
        aria-label="Show password"
        aria-pressed="false"
    >
        <svg class="icon-closed" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
        <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
            <path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd" />
        </svg>
    </button>
    <?php
}

$passwordFields = [
    ['current_password', 'Current password',     'current-password'],
    ['new_password',     'New password',         'new-password'],
    ['confirm_password', 'Confirm new password', 'new-password'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="account-page-layout container">

    <aside class="account-sidebar">
        <nav class="account-sidebar-nav" aria-label="Account navigation">

            <p class="account-sidebar-section-label">Settings</p>

            <a href="/account/" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
                Account
            </a>

            <a href="/account/security.php" class="account-sidebar-item active" aria-current="page">
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

            <a href="/account/orders.php" class="account-sidebar-item">
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

        <?php if (!$otpStep): ?>

            <div class="settings-section">
                <div class="settings-section-heading">
                    <h2>Password</h2>
                    <p>Manage your account password and security credentials.</p>
                </div>

                <div class="settings-card collapsible-card">
                    <div class="collapsible-header">
                        <div class="collapsible-meta">
                            <h3>Password</h3>
                            <p class="collapsible-value">••••••••••••</p>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm collapse-toggle-btn">
                            <span class="collapse-btn-text" data-edit-text="Change Password">Change Password</span>
                            <svg class="collapse-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                    </div>

                    <div class="collapsible-body" hidden>
                        <form method="post" action="/account/security.php" class="settings-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="change_password">

                            <?php foreach ($passwordFields as [$fieldId, $fieldLabel, $fieldAutocomplete]): ?>
                                <div class="form-group">
                                    <label for="<?= $fieldId ?>"><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></label>
                                    <div class="password-wrapper">
                                        <input
                                            class="form-input"
                                            type="password"
                                            id="<?= $fieldId ?>"
                                            name="<?= $fieldId ?>"
                                            autocomplete="<?= $fieldAutocomplete ?>"
                                            required
                                        >
                                        <?php passwordToggleButton($fieldId); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <p class="password-requirements">
                                <?= htmlspecialchars(passwordRulesSentence($passwordPolicy), ENT_QUOTES, 'UTF-8') ?>
                            </p>

                            <button class="btn btn-primary" type="submit" style="margin-top: 10px;">
                                Send Verification Code
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>

            <div class="settings-section">
                <div class="settings-section-heading">
                    <h2>Verify Your Password Change</h2>
                    <p>
                        Enter the 6-digit code sent to
                        <?= htmlspecialchars(maskEmail($profile['email']), ENT_QUOTES, 'UTF-8') ?>.
                        Your password is only changed after the code is confirmed.
                    </p>
                </div>

                <div class="settings-card">

                    <form method="post" action="/account/security.php" class="settings-form">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <input type="hidden" name="action" value="confirm_password_change">

                        <div class="form-group">
                            <label for="otp_code">Verification code</label>
                            <input
                                class="form-input"
                                type="text"
                                id="otp_code"
                                name="otp_code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                placeholder="123456"
                                required
                                autofocus
                            >
                        </div>

                        <p
                            class="password-requirements"
                            id="otpCountdown"
                            data-seconds="<?= (int) $secondsLeft ?>"
                        >
                            Code expires in <strong id="otpTimer">--:--</strong>
                        </p>

                        <button class="btn btn-primary" type="submit" id="otpVerifyButton">
                            Confirm Password Change
                        </button>
                    </form>

                    <form method="post" action="/account/security.php" class="settings-form">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <input type="hidden" name="action" value="resend_password_otp">

                        <button class="btn btn-outline btn-sm" type="submit">
                            Resend code
                        </button>
                    </form>

                    <form method="post" action="/account/security.php" class="settings-form">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <input type="hidden" name="action" value="cancel_password_change">

                        <button class="btn btn-outline btn-sm" type="submit">
                            Cancel
                        </button>
                    </form>

                </div>
            </div>

        <?php endif; ?>

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
                            <?php if ($mfaEnabled): ?>
                                <span class="session-active-indicator">Turned on</span>
                            <?php endif; ?>
                        </div>
                        <p class="security-feature-desc">
                            Use an authenticator application to generate verification codes when signing in.
                        </p>
                    </div>
                    <div class="security-feature-action">
                        <a class="btn btn-outline btn-sm" href="/account/mfa.php"><?= $mfaEnabled ? 'Manage' : 'Set Up' ?></a>
                    </div>
                </div>
            </div>
        </div>

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

<script src="/js/account.js" defer></script>
<?php if ($otpStep): ?>
    <script src="/js/otp-countdown.js" defer></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>