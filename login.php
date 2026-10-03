<?php

require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/csrf.php';

$errors = [];

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

        $max_failed_attempts =
            (int) ($policy['max_failed_attempts'] ?? 3);

        $lockout_minutes =
            (int) ($policy['lockout_duration_minutes'] ?? 30);

        $stmt = $pdo->prepare(
            'SELECT
                user_id,
                full_name,
                email,
                password_hash,
                role,
                account_status
             FROM users
             WHERE email = ?
             LIMIT 1'
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if ($user) {

            $lockStmt = $pdo->prepare(
                'SELECT locked_at
                 FROM locked_accounts
                 WHERE user_id = ?
                 LIMIT 1'
            );

            $lockStmt->execute([
                $user['user_id']
            ]);

            $locked = $lockStmt->fetch();

            if ($locked) {

                $locked_until =
                    strtotime($locked['locked_at']) +
                    ($lockout_minutes * 60);

                if (time() < $locked_until) {

                    $errors[] =
                        'Your account is temporarily locked. ' .
                        'Please try again later.';

                } else {

                    $unlockStmt = $pdo->prepare(
                        'DELETE FROM locked_accounts
                         WHERE user_id = ?'
                    );

                    $unlockStmt->execute([
                        $user['user_id']
                    ]);
                }
            }
        }

        if (empty($errors)) {

            $password_valid =
                $user &&
                password_verify(
                    $password,
                    $user['password_hash']
                );

            if (!$password_valid) {

                $user_id = $user
                    ? $user['user_id']
                    : null;

                $ip_address =
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown';

                $attemptStmt = $pdo->prepare(
                    'INSERT INTO login_attempts
                    (user_id, ip_address, success)
                    VALUES (?, ?, 0)'
                );

                $attemptStmt->execute([
                    $user_id,
                    $ip_address
                ]);

                if ($user) {

                    $countStmt = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM login_attempts
                         WHERE user_id = ?
                         AND success = 0
                         AND attempted_at >=
                             DATE_SUB(
                                 NOW(),
                                 INTERVAL ? MINUTE
                             )'
                    );

                    $countStmt->execute([
                        $user['user_id'],
                        $lockout_minutes
                    ]);

                    $failed_attempts =
                        (int) $countStmt->fetchColumn();

                    if (
                        $failed_attempts >=
                        $max_failed_attempts
                    ) {

                        $lockStmt = $pdo->prepare(
                            'INSERT INTO locked_accounts
                            (user_id, reason)
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE
                                locked_at = CURRENT_TIMESTAMP,
                                reason = VALUES(reason)'
                        );

                        $lockStmt->execute([
                            $user['user_id'],
                            'Too many failed login attempts.'
                        ]);

                        $errors[] =
                            'Too many failed attempts. ' .
                            'Your account has been temporarily locked.';

                    } else {

                        $errors[] =
                            'Invalid email or password.';
                    }

                } else {

                    $errors[] =
                        'Invalid email or password.';
                }

            } elseif ($user['account_status'] !== 'active') {

                $errors[] =
                    'Your account is currently suspended.';

            } else {

                $successStmt = $pdo->prepare(
                    'INSERT INTO login_attempts
                    (user_id, ip_address, success)
                    VALUES (?, ?, 1)'
                );

                $successStmt->execute([
                    $user['user_id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                session_regenerate_id(true);

                $_SESSION['user_id'] =
                    $user['user_id'];

                $_SESSION['full_name'] =
                    $user['full_name'];

                $_SESSION['email'] =
                    $user['email'];

                $_SESSION['role'] =
                    $user['role'];

                $_SESSION['last_activity'] =
                    time();

                if (
                    $user['role'] === 'admin' ||
                    $user['role'] === 'staff'
                ) {

                    header(
                        'Location: admin/index.php'
                    );

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

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Login
        </h1>

        <?php if (!empty($errors)): ?>

            <div class="form-errors">

                <?php foreach ($errors as $error): ?>

                    <p>
                        <?php echo htmlspecialchars($error); ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <form method="POST" action="login.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
            >

            <div class="form-group">

                <label for="email">
                    Email
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                >

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >

            </div>


            <button
                type="submit"
                class="btn btn-primary"
            >
                Login
            </button>

        </form>


        <p>
            Don't have an account?
            <a href="register.php">
                Create an account
            </a>
        </p>

    </div>

</section>

<?php include 'includes/footer.php'; ?>