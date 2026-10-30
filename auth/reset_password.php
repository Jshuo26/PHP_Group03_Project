<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/password_policy.php';

header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
$tokenSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$token = trim(requestString($tokenSource, 'token'));
$validToken = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$success = isset($_GET['success']) && $_GET['success'] === '1';
$errors = [];
$policy = getPasswordPolicy($pdo);
$tokenRow = null;

if ($validToken && !$success) {
    $stmt = $pdo->prepare(
        'SELECT t.reset_id, t.user_id
           FROM password_reset_tokens t
           JOIN users u ON u.user_id = t.user_id
          WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
            AND u.role = \'customer\' AND u.account_status = \'active\'
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $tokenRow = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    requireCsrfToken();
    $password = requestString($_POST, 'password');
    $confirm = requestString($_POST, 'confirm_password');
    if (!$validToken || !$tokenRow) {
        $errors[] = 'This reset link is invalid or has expired. Request a new one to continue.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The passwords do not match.';
    }
    $errors = array_merge($errors, validatePasswordAgainstPolicy($password, $policy));
    if (!$errors && $tokenRow) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT reset_id FROM password_reset_tokens
                  WHERE reset_id = ? AND used_at IS NULL AND expires_at > NOW()
                  FOR UPDATE'
            );
            $lock->execute([(int) $tokenRow['reset_id']]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Reset link is no longer valid.');
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND role = \'customer\'')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $tokenRow['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                ->execute([(int) $tokenRow['user_id']]);
            $pdo->commit();
            header('Location: /auth/reset_password.php?success=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Password reset could not be saved: ' . $e->getMessage());
            $errors[] = 'We could not update your password right now. Please request a fresh reset link and try again.';
        }
    }
}

include __DIR__ . '/../includes/header.php'; ?>
<link rel="stylesheet" href="/css/verify-email.css">
<main class="verify-email-page">
    <section class="verify-email-card" aria-labelledby="reset-password-title">
        <div class="verify-email-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/><path d="m9 15 2 2 4-4"/></svg>
        </div>
        <p class="verify-email-eyebrow">ACCOUNT RECOVERY</p>
        <h1 class="verify-email-title" id="reset-password-title"><?php echo $success ? 'Password Updated' : 'Create a New Password'; ?></h1>
        <p class="verify-email-subtitle"><?php echo $success ? 'Your customer account password has been changed. You can now sign in with your new password.' : 'Choose a strong password you have not used for this account before.'; ?></p>
        <?php if ($success): ?>
            <a class="btn btn-primary verify-email-primary-button" href="/auth/login.php">Back to Login</a>
        <?php elseif ($tokenRow): ?>
            <?php if ($errors): ?><div class="verify-email-message verify-email-message--error" role="alert"><?php foreach ($errors as $message): ?><p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endforeach; ?></div><?php endif; ?>
            <form method="post" action="/auth/reset_password.php" class="verify-email-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="verify-email-field"><label for="new-password">New Password</label><input class="verify-email-text-input" type="password" id="new-password" name="password" autocomplete="new-password" required><p class="verify-email-field-help"><?php echo htmlspecialchars(passwordRulesSentence($policy), ENT_QUOTES, 'UTF-8'); ?></p></div>
                <div class="verify-email-field"><label for="confirm-password">Confirm New Password</label><input class="verify-email-text-input" type="password" id="confirm-password" name="confirm_password" autocomplete="new-password" required></div>
                <button type="submit" class="btn btn-primary verify-email-primary-button">Update Password</button>
            </form>
        <?php else: ?>
            <div class="verify-email-message verify-email-message--error" role="alert">This reset link is invalid or has expired. Request a new one to continue.</div>
            <a class="btn btn-primary verify-email-primary-button" href="/auth/forgot_password.php">Request a New Link</a>
        <?php endif; ?>
        <p class="verify-email-footer-note">For your protection, each reset link works once and expires after 30 minutes.</p>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
