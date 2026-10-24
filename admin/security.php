<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/crypto.php';

requireRole(['admin']);

const MIN_ATTEMPTS = 1;
const MAX_ATTEMPTS = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $action = requestString($_POST, 'action');

    if ($action === 'save_policy') {
        $attempts = filter_var(
            requestString($_POST, 'max_failed_attempts'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => MIN_ATTEMPTS, 'max_range' => MAX_ATTEMPTS]]
        );

        if ($attempts === false) {
            $_SESSION['flash_error'] = 'Enter a whole number from ' . MIN_ATTEMPTS . ' to ' . MAX_ATTEMPTS . '.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policy (policy_id, max_failed_attempts, updated_by)
                 VALUES (1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    max_failed_attempts = VALUES(max_failed_attempts),
                    updated_by = VALUES(updated_by)'
            );
            $stmt->execute([$attempts, $_SESSION['user_id']]);
            $_SESSION['flash_ok'] = 'Maximum login attempts set to ' . $attempts . '.';
        }

    } elseif ($action === 'save_password_policy') {
        $limits = [
            'password_min_length'  => [PASSWORD_LENGTH_FLOOR, PASSWORD_MAX_LENGTH],
            'uppercase_min_length' => [0, PASSWORD_COUNT_MAX],
            'lowercase_min_length' => [0, PASSWORD_COUNT_MAX],
            'number_min_length'    => [0, PASSWORD_COUNT_MAX],
            'special_min_length'   => [0, PASSWORD_COUNT_MAX],
        ];

        $values = [];
        $valid = true;
        foreach ($limits as $field => [$min, $max]) {
            $v = filter_var(
                requestString($_POST, $field),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $min, 'max_range' => $max]]
            );
            if ($v === false) {
                $valid = false;
                break;
            }
            $values[$field] = $v;
        }

        if (!$valid) {
            $_SESSION['flash_error'] = 'Check the password rules: length must be '
                . PASSWORD_LENGTH_FLOOR . ' to ' . PASSWORD_MAX_LENGTH
                . ' and each character count must be 0 to ' . PASSWORD_COUNT_MAX . '.';
        } elseif (
            $values['uppercase_min_length'] + $values['lowercase_min_length']
            + $values['number_min_length'] + $values['special_min_length']
            > $values['password_min_length']
        ) {
            $_SESSION['flash_error'] = 'The required characters add up to more than the minimum length.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policy
                    (policy_id, password_min_length, uppercase_min_length, lowercase_min_length,
                     number_min_length, special_min_length, updated_by)
                 VALUES (1, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    password_min_length  = VALUES(password_min_length),
                    uppercase_min_length = VALUES(uppercase_min_length),
                    lowercase_min_length = VALUES(lowercase_min_length),
                    number_min_length    = VALUES(number_min_length),
                    special_min_length   = VALUES(special_min_length),
                    updated_by           = VALUES(updated_by)'
            );
            $stmt->execute([
                $values['password_min_length'],
                $values['uppercase_min_length'],
                $values['lowercase_min_length'],
                $values['number_min_length'],
                $values['special_min_length'],
                $_SESSION['user_id'],
            ]);
            $_SESSION['flash_ok'] = 'Password rules updated. They apply to new and changed passwords.';
        }
    
    } elseif ($action === 'save_session_timeout') {

        $timeout = filter_var(
            requestString($_POST, 'session_timeout_minutes'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1440]]
        );

        if ($timeout === false) {
            $_SESSION['flash_error'] =
                'Session timeout must be between 1 and 1440 minutes.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO security_policy
                    (policy_id, session_timeout_minutes, updated_by)
                 VALUES (1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    session_timeout_minutes = VALUES(session_timeout_minutes),
                    updated_by = VALUES(updated_by)'
            );

            $stmt->execute([
                $timeout,
                $_SESSION['user_id']
            ]);

            $_SESSION['flash_ok'] =
                'Session timeout updated to ' . $timeout . ' minute(s).';
        }


    } elseif ($action === 'unlock') {

        $targetId = filter_var(requestString($_POST, 'user_id'), FILTER_VALIDATE_INT);

        if ($targetId === false) {
            $_SESSION['flash_error'] = 'Invalid account.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE locked_accounts
                 SET unlocked_by = ?, unlocked_at = NOW()
                 WHERE user_id = ? AND unlocked_at IS NULL'
            );
            $stmt->execute([$_SESSION['user_id'], $targetId]);
            $_SESSION['flash_ok'] = $stmt->rowCount() > 0
                ? 'Account unlocked. The user can log in again.'
                : 'That account was not locked.';
        }
    }
    header('Location: /admin/security.php');
    exit;
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$current = (int) $pdo->query(
    'SELECT max_failed_attempts FROM security_policy WHERE policy_id = 1 LIMIT 1'
)->fetchColumn();
if ($current < 1) {
    $current = 3;
}

$sessionTimeout = $pdo->query(
    'SELECT session_timeout_minutes
     FROM security_policy
     WHERE policy_id = 1
     LIMIT 1'
)->fetchColumn();

$sessionTimeout = $sessionTimeout === false
    ? 2
    : max(1, min(1440, (int) $sessionTimeout));

$pw = getPasswordPolicy($pdo);

$lockedStmt = $pdo->query(
    'SELECT u.user_id, u.full_name, u.email, u.role, l.locked_at, l.reason,
            TIMESTAMPDIFF(MINUTE, l.locked_at, NOW()) AS minutes_ago
     FROM locked_accounts l
     JOIN users u ON u.user_id = l.user_id
     WHERE l.unlocked_at IS NULL
     ORDER BY l.locked_at DESC'
);
$lockedAccounts = $lockedStmt->fetchAll();
foreach ($lockedAccounts as &$lockedAccount) {
    $lockedAccount = decryptUserPersonalFields($lockedAccount);
}
unset($lockedAccount);

$csrf = generateCsrfToken();

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function timeAgo($minutes)
{
    $minutes = max(0, (int) $minutes);
    if ($minutes < 1) {
        return 'Just now';
    }
    if ($minutes < 60) {
        return $minutes . ' min ago';
    }
    if ($minutes < 1440) {
        return floor($minutes / 60) . ' hr ago';
    }
    $days = floor($minutes / 1440);
    return $days . ($days === 1 ? ' day ago' : ' days ago');
}

function initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="/css/admin-security.css">

<section class="container">

  <div class="card">
    <h1 class="card-title">Security Settings</h1>

    <?php if ($flashOk): ?>
      <p class="card-text" role="status"><?php echo e($flashOk); ?></p>
    <?php endif; ?>
    <?php if ($flashError): ?>
      <div class="form-errors" role="alert"><p><?php echo e($flashError); ?></p></div>
    <?php endif; ?>

    <h2>Account lockout</h2>
    <p class="card-text">
      An account is locked after this many failed logins in a row. It stays locked until an administrator unlocks it.
    </p>

    <form method="POST" action="/admin/security.php">
      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="save_policy">

      <div class="form-group">
        <label for="max_failed_attempts">Maximum failed attempts (<?php echo MIN_ATTEMPTS; ?> to <?php echo MAX_ATTEMPTS; ?>)</label>
        <input type="number" id="max_failed_attempts" name="max_failed_attempts" class="form-input"
               min="<?php echo MIN_ATTEMPTS; ?>" max="<?php echo MAX_ATTEMPTS; ?>"
               value="<?php echo (int) $current; ?>" required>
      </div>

      <button type="submit" class="btn btn-primary">Save</button>
    </form>
  </div>

  <div class="card">
    <h2>Session Timeout</h2>

    <p class="card-text">
      Automatically log users out after the selected period of inactivity.
      This setting applies to customers, staff, and administrators.
    </p>

    <form method="POST" action="/admin/security.php">
      <input type="hidden" name="csrf_token"
             value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action"
             value="save_session_timeout">

      <div class="form-group">
        <label for="session_timeout_minutes">
          Inactivity timeout (1 to 1440 minutes)
        </label>

        <input
          type="number"
          id="session_timeout_minutes"
          name="session_timeout_minutes"
          class="form-input"
          min="1"
          max="1440"
          value="<?php echo $sessionTimeout; ?>"
          required
        >

        <div class="pp-hint">
          Current timeout: <?php echo $sessionTimeout; ?> minute(s).
        </div>
      </div>

      <button type="submit" class="btn btn-primary">
        Save session timeout
      </button>
    </form>
  </div>

  <div class="card">
    <h2>Password complexity</h2>
    <p class="card-text">
      Set what a password must contain. These rules apply when someone registers or changes their password.
      Existing passwords are not affected. Set a character type to 0 to make it optional.
    </p>

    <form method="POST" action="/admin/security.php">
      <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="save_password_policy">

      <div class="pp-grid">
        <div class="form-group">
          <label for="password_min_length">Minimum length</label>
          <input type="number" id="password_min_length" name="password_min_length" class="form-input"
                 min="<?php echo PASSWORD_LENGTH_FLOOR; ?>" max="<?php echo PASSWORD_MAX_LENGTH; ?>"
                 value="<?php echo (int) $pw['password_min_length']; ?>" required>
          <div class="pp-hint"><?php echo PASSWORD_LENGTH_FLOOR; ?> to <?php echo PASSWORD_MAX_LENGTH; ?></div>
        </div>

        <div class="form-group">
          <label for="uppercase_min_length">Uppercase letters</label>
          <input type="number" id="uppercase_min_length" name="uppercase_min_length" class="form-input"
                 min="0" max="<?php echo PASSWORD_COUNT_MAX; ?>"
                 value="<?php echo (int) $pw['uppercase_min_length']; ?>" required>
          <div class="pp-hint">At least (A-Z)</div>
        </div>

        <div class="form-group">
          <label for="lowercase_min_length">Lowercase letters</label>
          <input type="number" id="lowercase_min_length" name="lowercase_min_length" class="form-input"
                 min="0" max="<?php echo PASSWORD_COUNT_MAX; ?>"
                 value="<?php echo (int) $pw['lowercase_min_length']; ?>" required>
          <div class="pp-hint">At least (a-z)</div>
        </div>

        <div class="form-group">
          <label for="number_min_length">Numbers</label>
          <input type="number" id="number_min_length" name="number_min_length" class="form-input"
                 min="0" max="<?php echo PASSWORD_COUNT_MAX; ?>"
                 value="<?php echo (int) $pw['number_min_length']; ?>" required>
          <div class="pp-hint">At least (0-9)</div>
        </div>

        <div class="form-group">
          <label for="special_min_length">Special characters</label>
          <input type="number" id="special_min_length" name="special_min_length" class="form-input"
                 min="0" max="<?php echo PASSWORD_COUNT_MAX; ?>"
                 value="<?php echo (int) $pw['special_min_length']; ?>" required>
          <div class="pp-hint">At least (for example ! @ #)</div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Save password rules</button>
    </form>
  </div>

  <div class="card">
    <div class="lk-head">
      <h2>Locked accounts</h2>
      <span class="lk-count<?php echo $lockedAccounts ? '' : ' is-zero'; ?>">
        <?php echo count($lockedAccounts); ?> locked
      </span>
    </div>
    <p class="lk-note">Review each account, then unlock it so the user can log in again.</p>

    <?php if (!$lockedAccounts): ?>
      <div class="lk-empty">
        <strong>All clear</strong>
        No accounts are locked right now.
      </div>
    <?php else: ?>
      <div class="lk-wrap">
        <table class="lk-table">
          <thead>
            <tr>
              <th scope="col">User</th>
              <th scope="col">Role</th>
              <th scope="col">Locked</th>
              <th scope="col">Reason</th>
              <th scope="col"><span class="sr-only">Action</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lockedAccounts as $row): ?>
              <?php
                $roleClass = in_array($row['role'], ['customer', 'staff', 'admin'], true) ? $row['role'] : 'customer';
                $lockedFull = date('M j, Y g:i A', strtotime($row['locked_at']));
              ?>
              <tr>
                <td class="lk-cell-user">
                  <div class="lk-user">
                    <span class="lk-avatar" aria-hidden="true"><?php echo e(initials($row['full_name'])); ?></span>
                    <div>
                      <div class="lk-name"><?php echo e($row['full_name']); ?></div>
                      <div class="lk-email"><?php echo e($row['email']); ?></div>
                    </div>
                  </div>
                </td>
                <td data-label="Role">
                  <span class="lk-role lk-role--<?php echo e($roleClass); ?>"><?php echo e($row['role']); ?></span>
                </td>
                <td data-label="Locked" class="lk-when">
                  <div>
                    <?php echo e(timeAgo($row['minutes_ago'])); ?>
                    <small><?php echo e($lockedFull); ?></small>
                  </div>
                </td>
                <td data-label="Reason" class="lk-reason"><?php echo e($row['reason']); ?></td>
                <td class="lk-cell-action">
                  <form method="POST" action="/admin/security.php" class="lk-unlock-form"
                        data-name="<?php echo e($row['full_name']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="action" value="unlock">
                    <input type="hidden" name="user_id" value="<?php echo (int) $row['user_id']; ?>">
                    <button type="submit" class="lk-unlock">Unlock</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</section>

<script>
document.querySelectorAll('.lk-unlock-form').forEach(function (form) {
  form.addEventListener('submit', function (event) {
    var name = form.getAttribute('data-name') || 'this account';
    if (!window.confirm('Unlock ' + name + '? They will be able to log in again.')) {
      event.preventDefault();
    }
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>