<?php

require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';

$errors = [];
$lock_seconds_left = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {

        $policyStmt = $pdo->query(
            'SELECT max_failed_attempts, lockout_duration_minutes
             FROM security_policy
             WHERE policy_id = 1
             LIMIT 1'
        );
        $policy = $policyStmt->fetch();

        $max_failed_attempts = (int) ($policy['max_failed_attempts'] ?? 3);
        $lockout_minutes = (int) ($policy['lockout_duration_minutes'] ?? 30);

        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, email, password_hash, role, account_status
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {

            $lockStmt = $pdo->prepare(
                'SELECT TIMESTAMPDIFF(
                            SECOND,
                            NOW(),
                            DATE_ADD(locked_at, INTERVAL ? MINUTE)
                        ) AS seconds_left
                 FROM locked_accounts
                 WHERE user_id = ?
                 LIMIT 1'
            );
            $lockStmt->execute([$lockout_minutes, $user['user_id']]);
            $locked = $lockStmt->fetch();

            if ($locked) {
                if ((int) $locked['seconds_left'] > 0) {
                    $lock_seconds_left = (int) $locked['seconds_left'];
                    $errors[] = 'Your account is temporarily locked. Please try again later.';
                } else {
                    $unlockStmt = $pdo->prepare(
                        'DELETE FROM locked_accounts WHERE user_id = ?'
                    );
                    $unlockStmt->execute([$user['user_id']]);
                }
            }
        }

        if (empty($errors)) {

            $password_valid = $user && password_verify($password, $user['password_hash']);

            if (!$password_valid) {

                $user_id = $user ? $user['user_id'] : null;
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                $attemptStmt = $pdo->prepare(
                    'INSERT INTO login_attempts (user_id, ip_address, success)
                     VALUES (?, ?, 0)'
                );
                $attemptStmt->execute([$user_id, $ip_address]);

                if ($user) {

                    $countStmt = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM login_attempts
                         WHERE user_id = ?
                         AND success = 0
                         AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
                    );
                    $countStmt->execute([$user['user_id'], $lockout_minutes]);
                    $failed_attempts = (int) $countStmt->fetchColumn();

                    if ($failed_attempts >= $max_failed_attempts) {

                        $lockInsertStmt = $pdo->prepare(
                            'INSERT INTO locked_accounts (user_id, reason)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE
                                locked_at = CURRENT_TIMESTAMP,
                                reason = VALUES(reason)'
                        );
                        $lockInsertStmt->execute([
                            $user['user_id'],
                            'Too many failed login attempts.'
                        ]);

                        $lock_seconds_left = $lockout_minutes * 60;
                        $errors[] = 'Too many failed attempts. Your account has been temporarily locked.';

                    } else {
                        $errors[] = 'Invalid email or password.';
                    }

                } else {
                    $errors[] = 'Invalid email or password.';
                }

            } elseif ($user['account_status'] !== 'active') {

                $errors[] = 'Your account is currently suspended.';

            } else {

                $successStmt = $pdo->prepare(
                    'INSERT INTO login_attempts (user_id, ip_address, success)
                     VALUES (?, ?, 1)'
                );
                $successStmt->execute([
                    $user['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                if ($user['role'] === 'admin' || $user['role'] === 'staff') {
                    header('Location: admin/index.php');
                    exit;
                }

                header('Location: index.php');
                exit;
            }
        }
    }
}

include 'includes/header.php';
?>

<section class="container auth-section">

  <div class="login-card">

    <h1 class="login-title">
      Login
    </h1>

    <?php if (!empty($errors)): ?>
      <div class="form-errors" role="alert">
        <?php foreach ($errors as $error): ?>
          <p><?php echo htmlspecialchars($error); ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($lock_seconds_left > 0): ?>
      <div
        class="lock-countdown"
        id="lockCountdown"
        data-seconds="<?php echo (int) $lock_seconds_left; ?>"
      >
        You can try again in
        <strong id="lockTimer">--:--</strong>
      </div>
    <?php endif; ?>

    <form id="loginForm" method="POST" action="login.php" novalidate>

      <input
        type="hidden"
        name="csrf_token"
        value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
      >

      <div class="form-group">
        <label for="email">
          Email<span class="required" aria-hidden="true">*</span>
        </label>
        <input
          type="email"
          id="email"
          name="email"
          class="form-input"
          autocomplete="email"
          required
          value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
        >
        <span class="field-error" id="emailError"></span>
      </div>

      <div class="form-group">
        <label for="password">
          Password<span class="required" aria-hidden="true">*</span>
        </label>
        <div class="password-wrapper">
          <input
            type="password"
            id="password"
            name="password"
            class="form-input"
            autocomplete="current-password"
            required
          >
          <button
            type="button"
            class="password-toggle"
            data-target="password"
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
        </div>
        <span class="field-error" id="passwordError"></span>
      </div>

      <button
        type="submit"
        id="loginButton"
        class="btn btn-primary btn-block"
        <?php echo $lock_seconds_left > 0 ? 'disabled' : ''; ?>
      >
        Login
      </button>

    </form>

    <p class="login-links">
      <a href="forgot-password.php">Forgot password?</a>
    </p>

    <p class="login-links">
      Don't have an account yet?
      <a href="register.php">Register now</a>
    </p>

  </div>

</section>

<script src="<?= $base ?? '' ?>/js/lockout.js" defer></script>

<?php include 'includes/footer.php'; ?>