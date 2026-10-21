<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/login_security.php';

const MFA_PENDING_SECONDS = 300; 
const MFA_MAX_ATTEMPTS    = 3;

if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$pending = $_SESSION['mfa_pending'] ?? null;
$loginUrl = '/auth/login.php';

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

    $mfaStmt = $pdo->prepare(
        "SELECT secret_key, last_used_step FROM mfa_settings WHERE user_id = ? AND method = 'totp' LIMIT 1"
    );
    $mfaStmt->execute([$userId]);
    $mfa = $mfaStmt->fetch();

    if (!$user || !$mfa || !$user['role'] === 'customer' || $user['account_status'] !== 'active') {
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
        $_SESSION['last_activity'] = time();

        header('Location: /index.php');
        exit;
    }

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

<link rel="stylesheet" href="/css/mfa.css">

<section class="container auth-section">
  <div class="card mfa-wrap">

    <h1 class="card-title">Enter your code</h1>
    <p class="mfa-lead">Open Google Authenticator and type the 6-digit code for aklAAAt!.</p>

    <?php if ($errors): ?>
      <div class="form-errors" role="alert">
        <?php foreach ($errors as $error): ?>
          <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/auth/mfa.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-group">
        <label for="code">6-digit code</label>
        <input class="form-input mfa-code-input" type="text" id="code" name="code"
               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
               placeholder="123456" required autofocus>
      </div>

      <div class="mfa-actions">
        <button class="btn btn-primary" type="submit">Verify and log in</button>
      </div>
    </form>

    <form method="POST" action="/auth/mfa.php" class="mfa-note">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="cancel">
      <button class="btn btn-outline btn-sm" type="submit">Cancel and go back</button>
    </form>

    <p class="mfa-note">Lost your phone? Contact an administrator to reset your authenticator.</p>

  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>