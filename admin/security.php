<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';

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
                ? 'Account unlocked.'
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

$lockedStmt = $pdo->query(
    'SELECT u.user_id, u.full_name, u.email, u.role, l.locked_at, l.reason
     FROM locked_accounts l
     JOIN users u ON u.user_id = l.user_id
     WHERE l.unlocked_at IS NULL
     ORDER BY l.locked_at DESC'
);
$lockedAccounts = $lockedStmt->fetchAll();

$csrf = generateCsrfToken();

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

include __DIR__ . '/../includes/header.php';
?>

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
    <h2>Locked accounts</h2>

    <?php if (!$lockedAccounts): ?>
      <p class="card-text">No accounts are currently locked.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Locked at</th><th>Reason</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($lockedAccounts as $row): ?>
            <tr>
              <td><?php echo e($row['full_name']); ?></td>
              <td><?php echo e($row['email']); ?></td>
              <td><?php echo e($row['role']); ?></td>
              <td><?php echo e($row['locked_at']); ?></td>
              <td><?php echo e($row['reason']); ?></td>
              <td>
                <form method="POST" action="/admin/security.php">
                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="unlock">
                  <input type="hidden" name="user_id" value="<?php echo (int) $row['user_id']; ?>">
                  <button type="submit" class="btn btn-secondary">Unlock</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>