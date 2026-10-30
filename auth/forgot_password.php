<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/mailer.php';

$sent = false;
$error = '';
$genericMessage = 'If an active customer account matches that email, a password reset link will arrive shortly.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $email = normalizeEmailForLookup(requestString($_POST, 'email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            $emailHash = emailLookupHash($email);
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $ipHash = hash_hmac('sha256', $ip, appEncryptionKey());
            $rate = $pdo->prepare(
                'SELECT
                    SUM(email_lookup_hash = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS email_requests,
                    SUM(requested_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS ip_requests
                 FROM password_reset_tokens
                 WHERE request_ip_hash = ? OR email_lookup_hash = ?'
            );
            $rate->execute([$emailHash, $ipHash, $emailHash]);
            $counts = $rate->fetch() ?: [];

            if ((int) ($counts['email_requests'] ?? 0) < 3 && (int) ($counts['ip_requests'] ?? 0) < 15) {
                $stmt = $pdo->prepare(
                    'SELECT user_id, full_name, email
                       FROM users
                      WHERE role = \'customer\'
                        AND account_status = \'active\'
                        AND (email_lookup_hash = ? OR email = ?)
                      LIMIT 1'
                );
                $stmt->execute([$emailHash, $email]);
                $user = $stmt->fetch();
                if ($user) {
                    $user = decryptUserPersonalFields($user);
                }

                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $pdo->prepare(
                    'UPDATE password_reset_tokens
                        SET used_at = NOW()
                      WHERE email_lookup_hash = ? AND used_at IS NULL'
                )->execute([$emailHash]);
                $insert = $pdo->prepare(
                    'INSERT INTO password_reset_tokens
                        (user_id, email_lookup_hash, token_hash, request_ip_hash, expires_at)
                     VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
                );
                $insert->execute([$user['user_id'] ?? null, $emailHash, $tokenHash, $ipHash]);

                if ($user && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
                    $baseUrl = rtrim((string) ($config['APP_URL'] ?? ''), '/');
                    $resetUrl = $baseUrl . '/auth/reset_password.php?token=' . rawurlencode($rawToken);
                    sendPasswordResetEmail($config, (string) $user['email'], (string) ($user['full_name'] ?: 'Reader'), $resetUrl);
                }
            }
            $sent = true;
        } catch (Throwable $e) {
            error_log('Password reset request could not be completed: ' . $e->getMessage());
            // Keep the response generic even when storage or mail services fail.
            $sent = true;
        }
    }
}

include __DIR__ . '/../includes/header.php'; ?>
<link rel="stylesheet" href="/css/verify-email.css">
<main class="verify-email-page">
    <section class="verify-email-card" aria-labelledby="reset-request-title">
        <div class="verify-email-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/><path d="M12 14v3"/></svg>
        </div>
        <p class="verify-email-eyebrow">ACCOUNT RECOVERY</p>
        <h1 class="verify-email-title" id="reset-request-title"><?php echo $sent ? 'Check Your Email' : 'Forgot Your Password?'; ?></h1>
        <p class="verify-email-subtitle"><?php echo $sent ? htmlspecialchars($genericMessage, ENT_QUOTES, 'UTF-8') : 'Enter the email address on your account. We’ll send a secure link to choose a new password.'; ?></p>
        <?php if ($error !== ''): ?><div class="verify-email-message verify-email-message--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if (!$sent): ?>
        <form method="post" action="/auth/forgot_password.php" class="verify-email-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="verify-email-field"><label for="reset-email">Email Address</label><input class="verify-email-text-input" type="email" id="reset-email" name="email" autocomplete="email" maxlength="255" required value="<?php echo htmlspecialchars(requestString($_POST, 'email'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="you@example.com"></div>
            <button type="submit" class="btn btn-primary verify-email-primary-button">Send Reset Link</button>
        </form>
        <?php endif; ?>
        <a class="verify-email-back-link" href="/auth/login.php">Back to Login</a>
        <p class="verify-email-footer-note">Reset links expire after 30 minutes and can only be used once.</p>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
