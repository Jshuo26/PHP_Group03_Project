<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/input.php';

$isStaffLogin = ($loginAudience ?? 'customer') === 'staff';
$allowedRoles    = $isStaffLogin ? ['admin', 'staff'] : ['customer'];
$loginAction     = $isStaffLogin ? '/admin/login.php' : '/auth/login.php';
$successRedirect = $isStaffLogin ? '/admin/index.php' : '/index.php';
$errors = [];
const DEFAULT_MAX_ATTEMPTS = 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $email = trim(requestString($_POST, 'email'));
    $password = requestString($_POST, 'password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {

        $policyStmt = $pdo->query(
            'SELECT max_failed_attempts
             FROM security_policy
             WHERE policy_id = 1
             LIMIT 1'
        );
        $policy = $policyStmt->fetch();

        $max_failed_attempts = (int) ($policy['max_failed_attempts'] ?? DEFAULT_MAX_ATTEMPTS);
        if ($max_failed_attempts < 1) {
            $max_failed_attempts = DEFAULT_MAX_ATTEMPTS;
        }

        $rolePlaceholders = implode(',', array_fill(0, count($allowedRoles), '?'));

        $stmt = $pdo->prepare(
            "SELECT user_id, full_name, email, password_hash, role, account_status, profile_picture_path
             FROM users
             WHERE email = ? AND role IN ($rolePlaceholders)
             LIMIT 1"
        );
        $stmt->execute(array_merge([$email], $allowedRoles));
        $user = $stmt->fetch();

        if ($user) {
            $lockStmt = $pdo->prepare(
                'SELECT 1
                 FROM locked_accounts
                 WHERE user_id = ? AND unlocked_at IS NULL
                 LIMIT 1'
            );
            $lockStmt->execute([$user['user_id']]);

            if ($lockStmt->fetchColumn()) {
                $errors[] = 'Your account is locked after too many failed attempts. Please contact an administrator to unlock it.';
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
                        "SELECT COUNT(*)
                         FROM login_attempts
                         WHERE user_id = ?
                         AND success = 0
                         AND attempted_at > GREATEST(
                             COALESCE((SELECT MAX(attempted_at) FROM login_attempts
                                       WHERE user_id = ? AND success = 1), '1970-01-01 00:00:00'),
                             COALESCE((SELECT unlocked_at FROM locked_accounts
                                       WHERE user_id = ?), '1970-01-01 00:00:00')
                         )"
                    );
                    $countStmt->execute([$user['user_id'], $user['user_id'], $user['user_id']]);
                    $failed_attempts = (int) $countStmt->fetchColumn();

                    if ($failed_attempts >= $max_failed_attempts) {
                        $lockInsertStmt = $pdo->prepare(
                            'INSERT INTO locked_accounts (user_id, reason)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE
                                locked_at = NOW(),
                                reason = VALUES(reason),
                                unlocked_by = NULL,
                                unlocked_at = NULL'
                        );
                        $lockInsertStmt->execute([
                            $user['user_id'],
                            'Too many failed login attempts.'
                        ]);

                        $errors[] = 'Too many failed attempts. Your account has been locked. Please contact an administrator.';

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
                $_SESSION['profile_picture_path'] = $user['profile_picture_path'] ?? null;
                $_SESSION['last_activity'] = time();

                header('Location: ' . $successRedirect);
                exit;
            }
        }
    }
}

include __DIR__ . '/header.php';
?>

<?php
$loginSlides = [];
$slideDirectory = __DIR__ . '/../images/others';
$slideFiles = glob($slideDirectory . '/*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE);
if ($slideFiles !== false) {
    foreach ($slideFiles as $slideFile) {
        if (is_file($slideFile)) {
            $loginSlides[] = '/images/others/' . rawurlencode(basename($slideFile));
        }
    }
}
?>

<section class="container auth-section login-page-section">
  <div class="login-card login-card-split">
    <aside class="login-promo" aria-label="Bookstore highlights">
      <div class="login-slideshow" id="loginSlideshow">
        <?php if ($loginSlides): ?>
          <?php foreach ($loginSlides as $index => $slide): ?>
            <div class="login-slide<?php echo $index === 0 ? ' is-active' : ''; ?>"<?php echo $index === 0 ? '' : ' aria-hidden="true"'; ?>>
              <img src="<?php echo htmlspecialchars($slide, ENT_QUOTES, 'UTF-8'); ?>" alt="Filipino books and bookstore highlights" loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>">
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="login-slide login-slide-fallback is-active" aria-hidden="false">
            <div class="login-fallback-art" aria-hidden="true">
              <svg viewBox="0 0 120 120" fill="none"><path d="M20 27c15-5 28-2 40 6v62c-12-8-25-11-40-6V27Z" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/><path d="M100 27c-15-5-28-2-40 6v62c12-8 25-11 40-6V27Z" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/><path d="M29 42c8-2 15 0 22 4M29 54c8-2 15 0 22 4M91 42c-8-2-15 0-22 4M91 54c-8-2-15 0-22 4" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </div>
          </div>
        <?php endif; ?>
        <div class="login-slide-shade"></div>
        <div class="login-promo-copy">
          <img class="login-promo-logo" src="/images/logo/aklAAAt!-white.png" alt="aklAAAt! Online Bookstore">
          <h2>Find stories that stay with you.</h2>
          <p>Discover Filipino literature, meet local authors, and bring your next favorite book home.</p>
        </div>
        <?php if (count($loginSlides) > 1): ?>
          <div class="login-slide-dots" aria-label="Slideshow progress">
            <?php foreach ($loginSlides as $index => $slide): ?>
              <span class="login-slide-dot<?php echo $index === 0 ? ' is-active' : ''; ?>" aria-hidden="true"></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </aside>

    <div class="login-form-panel">
      <div class="login-form-inner">
        <?php if ($isStaffLogin): ?>
          <p class="login-kicker">STAFF &amp; ADMIN PORTAL</p>
          <h1 class="login-title">Staff &amp; Admin Login</h1>
          <p class="login-subtitle">Authorized personnel only. Log in to manage books, categories, and orders.</p>
        <?php else: ?>
          <p class="login-kicker">ACCESSIBLE. ASSORTED. AUTOMATED.</p>
          <h1 class="login-title">Welcome back!</h1>
          <p class="login-subtitle">Log in to explore Filipino stories and pick up where you left off.</p>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="form-errors" role="alert">
            <?php foreach ($errors as $error): ?>
              <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="<?php echo htmlspecialchars($loginAction, ENT_QUOTES, 'UTF-8'); ?>" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

          <div class="form-group">
            <label for="email">Email Address<span class="required" aria-hidden="true">*</span></label>
            <input type="email" id="email" name="email" class="form-input" autocomplete="email" placeholder="<?php echo $isStaffLogin ? 'staff@example.com' : 'juandelacruz@example.com'; ?>" required value="<?php echo htmlspecialchars(requestString($_POST, 'email'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="field-error" id="emailError"></span>
          </div>

          <div class="form-group login-password-group">
            <div class="login-label-row">
              <label for="password">Password<span class="required" aria-hidden="true">*</span></label>
            </div>
            <div class="password-wrapper">
              <input type="password" id="password" name="password" class="form-input" autocomplete="current-password" placeholder="Enter your password" required>
              <button type="button" class="password-toggle" data-target="password" aria-label="Show password" aria-pressed="false">
                <svg class="icon-closed" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                <svg class="icon-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd"/></svg>
              </button>
            </div>
            <?php if (!$isStaffLogin): ?>
              <div class="forgot-password-row">
                <a class="forgot-password-link" href="/auth/forgot_password.php">Forgot Password?</a>
              </div>
            <?php endif; ?>
            <span class="field-error" id="passwordError"></span>
         </div>
          <button type="submit" id="loginButton" class="btn btn-primary btn-block login-submit">Log in</button>
        </form>

        <?php if (!$isStaffLogin): ?>
          <p class="login-links">Don't have an account yet? <a href="/auth/register.php">Register now</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const slideshow = document.getElementById('loginSlideshow');
  if (!slideshow) return;
  const slides = Array.from(slideshow.querySelectorAll('.login-slide'));
  const dots = Array.from(slideshow.querySelectorAll('.login-slide-dot'));
  if (slides.length < 2) return;
  let current = 0;
  window.setInterval(function () {
    slides[current].classList.remove('is-active');
    slides[current].setAttribute('aria-hidden', 'true');
    if (dots[current]) dots[current].classList.remove('is-active');
    current = (current + 1) % slides.length;
    slides[current].classList.add('is-active');
    slides[current].setAttribute('aria-hidden', 'false');
    if (dots[current]) dots[current].classList.add('is-active');
  }, 5000);
});
</script>

<?php include __DIR__ . '/footer.php'; ?>