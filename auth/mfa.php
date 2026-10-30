<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/login_security.php';
require_once __DIR__ . '/../includes/audit.php';

const MFA_PENDING_SECONDS = 300; 
const MFA_MAX_ATTEMPTS    = 3;

if (!empty($_SESSION['user_id'])) {
    $signedInRole = (string) ($_SESSION['role'] ?? 'customer');
    header('Location: ' . (in_array($signedInRole, ['admin', 'staff'], true) ? '/admin/index.php' : '/index.php'));
    exit;
}

$pending = $_SESSION['mfa_pending'] ?? null;
$loginUrl = (is_array($pending) && ($pending['audience'] ?? '') === 'staff')
    ? '/admin/login.php'
    : '/auth/login.php';

function mfaBackToLogin(string $loginUrl, string $message): void
{
    unset($_SESSION['mfa_pending']);
    $_SESSION['session_timeout_message'] = $message;
    header('Location: ' . $loginUrl);
    exit;
}

if (
    !is_array($pending)
    || empty($pending['user_id'])
    || (time() - (int) ($pending['started'] ?? 0)) > MFA_PENDING_SECONDS
    || ($pending['mode'] ?? 'verify') !== 'verify'
) {
    mfaBackToLogin($loginUrl, 'Your sign-in session expired. Please log in again.');
}

$userId = (int) $pending['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    if (requestString($_POST, 'action') === 'cancel') {
        mfaBackToLogin($loginUrl, 'Sign-in cancelled.');
    }

    $userStmt = $pdo->prepare(
        'SELECT user_id, full_name, email, role, account_status, profile_picture_path
         FROM users WHERE user_id = ? LIMIT 1'
    );
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if ($user) {
        $user = decryptUserPersonalFields($user);
    }

    $mfaStmt = $pdo->prepare(
        "SELECT secret_key, last_used_step FROM mfa_settings WHERE user_id = ? AND method = 'totp' LIMIT 1"
    );
    $mfaStmt->execute([$userId]);
    $mfa = $mfaStmt->fetch();

    $audienceMatches = $user && (
        (($pending['audience'] ?? '') === 'staff' && in_array($user['role'], ['admin', 'staff'], true))
        || (($pending['audience'] ?? '') === 'customer' && $user['role'] === 'customer')
    );
    if (!$user || !$mfa || !$audienceMatches || $user['account_status'] !== 'active') {
        mfaBackToLogin($loginUrl, 'We could not complete your sign-in. Please log in again.');
    }

    if (isAccountLocked($pdo, $userId)) {
        mfaBackToLogin($loginUrl, 'Your account is locked. Please contact an administrator to unlock it.');
    }

    $secret = decryptField((string) $mfa['secret_key']);
    $step = $secret === null ? null : totpVerify($secret, requestString($_POST, 'code'));
    $accepted = false;

    if ($step !== null) {
        $replayStmt = $pdo->prepare(
            'UPDATE mfa_settings
             SET last_used_step = ?
             WHERE user_id = ? AND (last_used_step IS NULL OR last_used_step < ?)'
        );
        $replayStmt->execute([$step, $userId, $step]);
        $accepted = $replayStmt->rowCount() > 0;
    }

    if ($accepted) {

        recordSuccessfulLogin($pdo, $userId);

        session_regenerate_id(true);
        unset($_SESSION['mfa_pending']);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['profile_picture_path'] = $user['profile_picture_path'] ?? null;
        $_SESSION['mfa_verified'] = true;
        $_SESSION['last_activity'] = time();

        auditLog($pdo, 'login', 'MFA login verified', 'authentication', $userId);

        header('Location: ' . (in_array($user['role'], ['admin', 'staff'], true) ? '/admin/index.php' : '/index.php'));
        exit;
    }

    auditLog($pdo, 'update', 'MFA verification failed', 'authentication', $userId);
    if (recordFailedLogin($pdo, $userId)) {
        mfaBackToLogin($loginUrl, 'Too many failed attempts. Your account has been locked. Please contact an administrator.');
    }

    $_SESSION['mfa_pending']['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;

    if ($_SESSION['mfa_pending']['attempts'] >= MFA_MAX_ATTEMPTS) {
        mfaBackToLogin($loginUrl, 'Too many incorrect codes. Please log in again.');
    }

    $errors[] = 'That code is not correct or was already used. Wait for the next code and try again.';
}

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/css/mfa.css?v=20261031-mfa-4">

<section class="container auth-section mfa-page">
  <div class="card mfa-wrap">
    <div class="mfa-heading">
      <div class="mfa-heading-title">
        <span class="mfa-shield" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3 19 6v5c0 4.5-2.9 7.8-7 10-4.1-2.2-7-5.5-7-10V6l7-3Z" />
            <path d="m9 12 2 2 4-4" />
          </svg>
        </span>
        <div class="mfa-heading-copy">
          <span class="mfa-eyebrow">Account Security</span>
          <h1 class="card-title">Verify It’s You</h1>
        </div>
      </div>
      <p class="mfa-lead">Open Google Authenticator and enter the current 6-digit code to securely finish signing in to aklAAAt!.</p>
    </div>

    <?php if ($errors): ?>
      <div class="form-errors" role="alert">
        <?php foreach ($errors as $error): ?>
          <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/auth/mfa.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-group mfa-code-group">
        <label for="code">Authenticator Code</label>
        <input class="form-input mfa-code-input" type="text" id="code" name="code"
               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
               placeholder="000 000" aria-describedby="mfa-code-help" required autofocus>
        <p class="mfa-code-help" id="mfa-code-help">Codes refresh every 30 seconds. Use the newest code shown in your app.</p>
      </div>

      <div class="mfa-actions">
        <button class="btn btn-primary" type="submit">Verify &amp; Continue</button>
        <button class="btn btn-outline" type="submit" form="mfa-cancel-form">Cancel</button>
      </div>
    </form>

    <form id="mfa-cancel-form" method="POST" action="/auth/mfa.php" class="mfa-cancel-form">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="cancel">
    </form>

    <div class="mfa-help-note">
      <strong>Can’t access your authenticator?</strong>
      <p>Contact an administrator to reset the authenticator for your account.</p>
    </div>

  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
