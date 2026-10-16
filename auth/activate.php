<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = (string) ($_GET['token'] ?? '');
$state = 'invalid';

if (preg_match('/^[a-f0-9]{64}$/', $token)) {

    $stmt = $pdo->prepare(
        "SELECT o.user_id,
                o.used_at,
                (o.expires_at > NOW()) AS still_valid,
                u.account_status
           FROM otp_codes o
           INNER JOIN users u ON u.user_id = o.user_id
          WHERE o.purpose = 'activation_link'
            AND o.code_hash = ?
          LIMIT 1"
    );

    $stmt->execute([hash('sha256', $token)]);

    $row = $stmt->fetch();

    if ($row) {

        if ($row['account_status'] === 'active') {
            $state = 'already';

        } elseif ($row['account_status'] !== 'pending') {
            $state = 'invalid';

        } elseif ($row['used_at'] !== null) {
            $state = 'invalid';

        } elseif (!(int) $row['still_valid']) {
            $state = 'expired';

        } else {

            try {

                $pdo->beginTransaction();

                $pdo->prepare(
                    "UPDATE users
                        SET account_status = 'active',
                            email_verified_at = NOW()
                      WHERE user_id = ? AND account_status = 'pending'"
                )->execute([$row['user_id']]);

                $pdo->prepare(
                    "UPDATE otp_codes
                        SET used_at = NOW()
                      WHERE user_id = ?
                        AND purpose IN ('registration', 'activation_link')
                        AND used_at IS NULL"
                )->execute([$row['user_id']]);

                $pdo->commit();

                $state = 'activated';

            } catch (PDOException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Activation failed: ' . $e->getMessage());

                $state = 'error';
            }
        }
    }
}

if ($state === 'activated' || $state === 'already') {

    header('Refresh: 3; url=/auth/login.php');

    unset($_SESSION['verify_user_id'], $_SESSION['verify_notice']);
}

$messages = [
    'activated' => 'Your account is activated.',
    'already'   => 'This account is already activated.',
    'expired'   => 'This activation link has expired. Log in with your password and we will send you a new one.',
    'invalid'   => 'This activation link is not valid or was already used. If you have not activated your account yet, log in and request a new link.',
    'error'     => 'Something went wrong while activating your account. Please try again later.',
];

$ok = ($state === 'activated' || $state === 'already');

include __DIR__ . '/../includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            <?php echo $ok ? 'Account Activated' : 'Activation Link Problem'; ?>
        </h1>

        <div class="<?php echo $ok ? 'form-success' : 'form-errors'; ?>" role="<?php echo $ok ? 'status' : 'alert'; ?>">
            <p>
                <?php echo htmlspecialchars($messages[$state], ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <?php if ($ok): ?>
                <p>Taking you to the login page...</p>
            <?php endif; ?>
        </div>

        <a href="/auth/login.php" class="btn btn-primary">
            <?php echo $ok ? 'Go to Login now' : 'Go to Login'; ?>
        </a>

    </div>

</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>