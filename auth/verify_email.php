<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/mailer.php';

$userId = (int) ($_SESSION['verify_user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT user_id, full_name, email, account_status
       FROM users
      WHERE user_id = ?
      LIMIT 1'
);

$stmt->execute([$userId]);

$user = $stmt->fetch();

if (!$user || $user['account_status'] !== 'pending') {
    unset($_SESSION['verify_user_id'], $_SESSION['verify_notice']);
    header('Location: /auth/login.php');
    exit;
}

$errors  = [];
$success = '';

$notice = (string) ($_SESSION['verify_notice'] ?? '');
unset($_SESSION['verify_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $action = requestString($_POST, 'action');

    if ($action === 'resend') {

        $wait = otpResendWait($pdo, $userId, 'registration');

        if ($wait > 0) {

            $errors[] = "Please wait {$wait} seconds before requesting another code.";

        } else {

            $code  = createOtp($pdo, $userId, 'registration');
            $token = createActivationToken($pdo, $userId);

            if (sendOtpEmail(
                $config,
                $user['email'],
                $user['full_name'],
                $code,
                'verify your email address',
                activationUrl($config, $token)
            )) {
                $notice = 'A new code and activation link were sent to '
                        . maskEmail($user['email']) . '.';
            } else {
                $errors[] = 'We could not send the email right now. Please try again later.';
            }
        }

    } elseif ($action === 'verify') {

        [$ok, $message] = verifyOtp(
            $pdo,
            $userId,
            'registration',
            requestString($_POST, 'otp_code')
        );

        if ($ok) {

            $pdo->prepare(
                "UPDATE users
                    SET account_status = 'active',
                        email_verified_at = NOW()
                  WHERE user_id = ? AND account_status = 'pending'"
            )->execute([$userId]);

            $pdo->prepare(
                "UPDATE otp_codes
                    SET used_at = NOW()
                  WHERE user_id = ?
                    AND purpose IN ('registration', 'activation_link')
                    AND used_at IS NULL"
            )->execute([$userId]);

            unset($_SESSION['verify_user_id'], $_SESSION['verify_notice']);

            $notice  = '';
            $success = 'Your email is verified and your account is active. You can now log in.';

        } else {
            $errors[] = $message;
        }
    }
}

$secondsLeft = $success === '' ? otpSecondsLeft($pdo, $userId, 'registration') : 0;

include __DIR__ . '/../includes/header.php'; ?>

<link rel="stylesheet" href="/css/verify-email.css">

<main class="verify-email-page">
    <section class="verify-email-card">

        <div class="verify-email-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="5" width="18" height="14" rx="2"/>
                <path d="m3 7 9 6 9-6"/>
                <path d="m16 16 2 2 4-4" />
            </svg>
        </div>

        <p class="verify-email-eyebrow">AKLAAAT ACCOUNT</p>

        <h1 class="verify-email-title">
            <?php echo $success !== '' ? 'Email Verified!' : 'Verify Your Email'; ?>
        </h1>

        <p class="verify-email-subtitle">
            <?php if ($success !== ''): ?>
                Your email address has been confirmed successfully.
            <?php else: ?>
                One more step before you start discovering Filipino stories.
            <?php endif; ?>
        </p>

        <?php if ($notice !== ''): ?>
            <div class="verify-email-message verify-email-message--success"
                 role="status">
                <?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="verify-email-message verify-email-message--error"
                 role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>

            <div class="verify-email-complete">
                <div class="verify-email-complete-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 12 4 4L19 6"/>
                    </svg>
                </div>

                <p class="verify-email-success-text">
                    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <a href="/auth/login.php"
                   class="btn btn-primary verify-email-primary-button">
                    Go to Login
                </a>
            </div>

        <?php else: ?>

            <div class="verify-email-recipient">
                <span class="verify-email-recipient-label">Verification email sent to</span>
                <strong>
                    <?php echo htmlspecialchars(maskEmail($user['email']), ENT_QUOTES, 'UTF-8'); ?>
                </strong>
            </div>

            <p class="verify-email-instructions">
                Enter the six-digit code from your email, or use the
                <strong>Activate my account</strong> link in the message.
            </p>

            <form method="POST" action="/auth/verify_email.php"
                  class="verify-email-form">

                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                <input type="hidden" name="action" value="verify">

                <div class="verify-email-field">
                    <label for="otp_code">6-digit verification code</label>

                    <input
                        type="text"
                        id="otp_code"
                        name="otp_code"
                        class="verify-email-otp-input"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9]{6}"
                        minlength="6"
                        maxlength="6"
                        placeholder="000000"
                        aria-describedby="otpHelp otpCountdown"
                        required
                        autofocus
                    >

                    <p id="otpHelp" class="verify-email-field-help">
                        Enter the code exactly as it appears in your email.
                    </p>
                </div>

                <div class="verify-email-countdown"
                     id="otpCountdown"
                     data-seconds="<?php echo (int) $secondsLeft; ?>">

                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="M12 7v5l3 2"/>
                    </svg>

                    <span>
                        Code expires in
                        <strong id="otpTimer">--:--</strong>
                    </span>
                </div>

                <button type="submit"
                        id="otpVerifyButton"
                        class="btn btn-primary verify-email-primary-button">
                    Verify &amp; Activate
                </button>
            </form>

            <div class="verify-email-divider">
                <span>Need another code?</span>
            </div>

            <p class="verify-email-resend-text">
                Check your spam folder first. If the email hasn't arrived,
                you can request a new code and activation link.
            </p>

            <form method="POST" action="/auth/verify_email.php"
                  class="verify-email-resend-form">

                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                <input type="hidden" name="action" value="resend">

                <button type="submit"
                        class="btn btn-secondary verify-email-resend-button">
                    Resend Code &amp; Link
                </button>
            </form>

        <?php endif; ?>

        <p class="verify-email-footer-note">
            AklAAAt — Discover stories, support Filipino books.
        </p>

    </section>
</main>

<script src="/js/otp-countdown.js" defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>