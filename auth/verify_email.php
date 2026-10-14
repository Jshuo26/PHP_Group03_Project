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

include __DIR__ . '/../includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Verify Your Email
        </h1>

        <?php if ($notice !== ''): ?>
            <div class="form-success" role="status">
                <p><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>

            <div class="form-success" role="status">
                <p><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>

                <a href="/auth/login.php" class="btn btn-primary">
                    Go to Login
                </a>
            </div>

        <?php else: ?>

            <p>
                We sent an email to
                <strong><?php echo htmlspecialchars(maskEmail($user['email']), ENT_QUOTES, 'UTF-8'); ?></strong>.
                Click the <strong>Activate my account</strong> button in it,
                or type the 6-digit code below.
            </p>

            <form method="POST" action="/auth/verify-email.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
                >

                <input type="hidden" name="action" value="verify">

                <div class="form-group">

                    <label for="otp_code">
                        Verification code
                    </label>

                    <input
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
                    id="otpCountdown"
                    data-seconds="<?php echo (int) $secondsLeft; ?>"
                >
                    Code expires in <strong id="otpTimer">--:--</strong>
                </p>

                <button
                    type="submit"
                    id="otpVerifyButton"
                    class="btn btn-primary"
                >
                    Verify &amp; Activate
                </button>

            </form>

            <form method="POST" action="/auth/verify-email.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
                >

                <input type="hidden" name="action" value="resend">

                <p>
                    Did not get the email? Check your spam folder, or
                    <button type="submit" class="btn btn-secondary">
                        Resend code and link
                    </button>
                </p>

            </form>

        <?php endif; ?>

    </div>

</section>

<script src="/js/otp_countdown.js" defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>