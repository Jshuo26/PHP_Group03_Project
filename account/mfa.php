<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/audit.php';

$pendingMfa = $_SESSION['mfa_pending'] ?? null;
$isStaffEnrollment = is_array($pendingMfa)
    && ($pendingMfa['audience'] ?? '') === 'staff'
    && ($pendingMfa['mode'] ?? '') === 'enroll'
    && !empty($pendingMfa['user_id'])
    && time() - (int) ($pendingMfa['started'] ?? 0) <= 300
    && empty($_SESSION['user_id']);

if (!$isStaffEnrollment) {
    requireLogin();
    if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
        requireRole(['admin', 'staff']);
    } else {
        requireRole(['customer']);
    }
}

$userId = $isStaffEnrollment ? (int) $pendingMfa['user_id'] : (int) $_SESSION['user_id'];
$errors = [];

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$userStmt = $pdo->prepare('SELECT user_id, email, full_name, password_hash, role, account_status, profile_picture_path FROM users WHERE user_id = ?');
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    http_response_code(404);
    exit('Account not found.');
}
$user = decryptUserPersonalFields($user);

if ($user['account_status'] !== 'active'
    || ($isStaffEnrollment && !in_array($user['role'], ['admin', 'staff'], true))) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_setup_secret']);
    header('Location: /admin/login.php');
    exit;
}

$backUrl = $isStaffEnrollment ? '/admin/login.php' : '/account/security.php';

function loadMfaRow(PDO $pdo, int $userId)
{
    $stmt = $pdo->prepare(
        "SELECT secret_key FROM mfa_settings WHERE user_id = ? AND method = 'totp' LIMIT 1"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

$mfa = loadMfaRow($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $action = requestString($_POST, 'action');

    if ($action === 'enable') {

        $secret = (string) ($_SESSION['mfa_setup_secret'] ?? '');
        $step = null;

        if ($isStaffEnrollment && $mfa) {
            unset($_SESSION['mfa_pending'], $_SESSION['mfa_setup_secret']);
            header('Location: /admin/login.php');
            exit;
        } elseif ($mfa) {
            $errors[] = 'Google Authenticator is already turned on.';
        } elseif ($secret === '') {
            $errors[] = 'Your setup session expired. Scan the new QR code and try again.';
        } else {
            $step = totpVerify($secret, requestString($_POST, 'code'));
            if ($step === null) {
                auditLog($pdo, 'update', 'MFA enrollment code rejected', 'authentication', $userId);
                $errors[] = 'That code is not correct. Check the code in the app and try again.';
            }
        }

        if (!$errors && $step !== null) {
            $enrollmentStage = 'saving authenticator settings';
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO mfa_settings (user_id, method, secret_key, last_used_step)
                     VALUES (?, 'totp', ?, ?)"
                )->execute([$userId, encryptField($secret), $step]);

                if ($isStaffEnrollment) {
                    $enrollmentStage = 'recording successful staff login';
                    recordSuccessfulLogin($pdo, $userId);
                }
                $pdo->commit();

                unset($_SESSION['mfa_setup_secret']);
                session_regenerate_id(true);
                if ($isStaffEnrollment) {
                    unset($_SESSION['mfa_pending']);
                    $_SESSION['user_id'] = (int) $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_picture_path'] = $user['profile_picture_path'] ?? null;
                    $_SESSION['mfa_verified'] = true;
                    $_SESSION['last_activity'] = time();
                    auditLog($pdo, 'create', 'Authenticator enrollment completed', 'authentication', $userId);
                    auditLog($pdo, 'login', 'MFA enrollment and login completed', 'authentication', $userId);
                    header('Location: /admin/index.php');
                    exit;
                }
                auditLog($pdo, 'create', 'Authenticator enrollment completed', 'authentication', $userId);
                $_SESSION['mfa_verified'] = true;
                $_SESSION['mfa_flash_ok'] = 'Google Authenticator is now on. You will be asked for a code each time you log in.';

                header('Location: /account/mfa.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('MFA enrollment failed while ' . $enrollmentStage . ' (' . get_class($e) . ', SQLSTATE ' . $e->getCode() . ').');
                $errors[] = 'We could not turn on Google Authenticator right now. Please try again later.';
            }
        }

    } elseif ($action === 'regenerate') {

        unset($_SESSION['mfa_setup_secret']);
        header('Location: /account/mfa.php');
        exit;

    } elseif ($action === 'disable') {

        if (in_array($user['role'], ['admin', 'staff'], true)) {
            http_response_code(403);
            $errors[] = 'MFA is required for staff and administrator accounts and cannot be disabled here.';
        }

        $fails = (int) ($_SESSION['mfa_disable_fails'] ?? 0);

        if (in_array($user['role'], ['admin', 'staff'], true)) {
        } elseif (!$mfa) {
            $errors[] = 'Google Authenticator is not turned on.';
        } elseif ($fails >= 5) {
            $errors[] = 'Too many attempts. Log out and log in again to try later.';
        } else {
            $passwordOk = password_verify(requestString($_POST, 'password'), (string) $user['password_hash']);
            $secret = decryptField((string) $mfa['secret_key']);
            $codeOk = $secret !== null && totpVerify($secret, requestString($_POST, 'code')) !== null;

            if (!$passwordOk || !$codeOk) {
                $_SESSION['mfa_disable_fails'] = $fails + 1;
                $errors[] = 'Your password or code is incorrect.';
            } else {
                $pdo->prepare('DELETE FROM mfa_settings WHERE user_id = ?')->execute([$userId]);
                auditLog($pdo, 'delete', 'Authenticator disabled', 'authentication', $userId);
                unset($_SESSION['mfa_disable_fails']);
                session_regenerate_id(true);
                $_SESSION['mfa_flash_ok'] = 'Google Authenticator has been turned off.';

                header('Location: /account/mfa.php');
                exit;
            }
        }
    }

    $mfa = loadMfaRow($pdo, $userId);
}

$flashOk = $_SESSION['mfa_flash_ok'] ?? null;
unset($_SESSION['mfa_flash_ok']);

$setupSecret = '';
$qrSvg = null;

if (!$mfa) {
    if (empty($_SESSION['mfa_setup_secret'])) {
        $_SESSION['mfa_setup_secret'] = totpGenerateSecret();
    }
    $setupSecret = $_SESSION['mfa_setup_secret'];
    $qrSvg = totpQrSvg(totpProvisioningUri($setupSecret, $user['email']));
}

$csrf = generateCsrfToken();

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/css/mfa.css">

<section class="container">
  <div class="card mfa-wrap">

    <h1 class="card-title">Google Authenticator</h1>
    <p class="mfa-lead">Add a second step to your login. After your password, you will enter a 6-digit code from the app.</p>

    <?php if ($flashOk): ?>
      <div class="form-success" role="status"><p><?php echo e($flashOk); ?></p></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="form-errors" role="alert">
        <?php foreach ($errors as $error): ?>
          <p><?php echo e($error); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($mfa): ?>

      <span class="mfa-status mfa-status--on">Turned on</span>
      <p class="card-text">Your account asks for a Google Authenticator code at every login.</p>

      <?php if (!in_array($user['role'], ['admin', 'staff'], true)): ?>
      <div class="mfa-danger-zone">
        <h2>Turn off</h2>
        <p class="card-text">Enter your password and a current code from the app to turn it off.</p>

        <form method="POST" action="/account/mfa.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="action" value="disable">

          <div class="form-group">
            <label for="password">Password</label>
            <input class="form-input" type="password" id="password" name="password" autocomplete="current-password" required>
          </div>

          <div class="form-group">
            <label for="code">6-digit code</label>
            <input class="form-input mfa-code-input" type="text" id="code" name="code"
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
                   placeholder="123456" required>
          </div>

          <div class="mfa-actions">
            <button class="btn btn-secondary" type="submit">Turn off Google Authenticator</button>
            <a href="<?php echo e($backUrl); ?>">Back</a>
          </div>
        </form>

        <p class="mfa-note">Lost your phone? Contact an administrator to reset your authenticator.</p>
      </div>
      <?php else: ?>
      <p class="mfa-note">MFA is mandatory for staff and administrator accounts. To recover access after losing your authenticator, contact an authorized administrator.</p>
      <?php endif; ?>

    <?php else: ?>

      <span class="mfa-status mfa-status--off">Not set up</span>

      <ol class="mfa-steps">
        <li>Install Google Authenticator (or any authenticator app) on your phone.</li>
        <li>In the app, tap <strong>+</strong> and scan the QR code, or enter the key by hand.</li>
        <li>Type the 6-digit code the app shows to finish.</li>
      </ol>

      <div class="mfa-setup">
        <?php if ($qrSvg): ?>
          <div class="mfa-qr" role="img" aria-label="QR code for Google Authenticator">
            <?php echo $qrSvg;?>
          </div>
        <?php endif; ?>
        <div class="mfa-key-box">
          <div class="mfa-key-label"><?php echo $qrSvg ? "Can't scan? Enter this key:" : 'Enter this key in the app:'; ?></div>
          <code class="mfa-key"><?php echo e(trim(chunk_split($setupSecret, 4, ' '))); ?></code>
          <p class="mfa-note">Choose "Time based" if the app asks. Keep this key private.</p>
        </div>
      </div>

      <form method="POST" action="/account/mfa.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="enable">

        <div class="form-group">
          <label for="code">6-digit code from the app</label>
          <input class="form-input mfa-code-input" type="text" id="code" name="code"
                 inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
                 placeholder="123456" required autofocus>
        </div>

        <div class="mfa-actions">
          <button class="btn btn-primary" type="submit">Turn on Google Authenticator</button>
          <a href="<?php echo e($backUrl); ?>">Cancel</a>
        </div>
      </form>

      <form method="POST" action="/account/mfa.php" class="mfa-note">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="regenerate">
        <button class="btn btn-outline btn-sm" type="submit">Generate a new QR code</button>
      </form>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
