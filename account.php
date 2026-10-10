<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

requireRole(['customer']);
$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';
$profile = ['full_name' => $_SESSION['full_name'] ?? '', 'email' => $_SESSION['email'] ?? '', 'mobile_number' => ''];

$profileStmt = $pdo->prepare('SELECT full_name, email, mobile_number FROM users WHERE user_id = ?');
$profileStmt->execute([$userId]);
$dbProfile = $profileStmt->fetch();
if (!$dbProfile) {
    http_response_code(404);
    exit('Account not found.');
}
$profile = $dbProfile;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $profile['full_name'] = trim(requestString($_POST, 'full_name'));
    $profile['email'] = strtolower(trim(requestString($_POST, 'email')));
    $profile['mobile_number'] = preg_replace('/[\s-]/', '', trim(requestString($_POST, 'mobile_number')));
    $currentPassword = requestString($_POST, 'current_password');
    $newPassword = requestString($_POST, 'new_password');
    $confirmPassword = requestString($_POST, 'confirm_password');

    if (mb_strlen($profile['full_name']) < 2 || mb_strlen($profile['full_name']) > 150) {
        $errors[] = 'Name must be between 2 and 150 characters.';
    }
    if (!filter_var($profile['email'], FILTER_VALIDATE_EMAIL) || strlen($profile['email']) > 255) {
        $errors[] = 'Enter a valid email address (maximum 255 characters).';
    }
    if ($profile['mobile_number'] !== '' && !preg_match('/^(\+63|0)9[0-9]{9}$/', $profile['mobile_number'])) {
        $errors[] = 'Enter a valid Philippine mobile number, such as 09123456789.';
    }
    if ($newPassword !== '') {
        if ($currentPassword === '') {
            $errors[] = 'Enter your current password to change it.';
        }
        if (strlen($newPassword) < 12 || strlen($newPassword) > 72
            || !preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)
            || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
            $errors[] = 'New password must be 12 to 72 characters and include uppercase, lowercase, number, and special characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }
    }

    if (!$errors) {
        $emailStmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
        $emailStmt->execute([$profile['email'], $userId]);
        if ($emailStmt->fetch()) {
            $errors[] = 'That email address is already in use.';
        }
    }
    if (!$errors && $newPassword !== '') {
        $passwordStmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $passwordStmt->execute([$userId]);
        if (!password_verify($currentPassword, (string) $passwordStmt->fetchColumn())) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    if (!$errors) {
        try {
            if ($newPassword !== '') {
                $updateStmt = $pdo->prepare(
                    'UPDATE users SET full_name = ?, email = ?, mobile_number = ?, password_hash = ? WHERE user_id = ?'
                );
                $updateStmt->execute([
                    $profile['full_name'],
                    $profile['email'],
                    $profile['mobile_number'] !== '' ? $profile['mobile_number'] : null,
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $userId
                ]);
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE users SET full_name = ?, email = ?, mobile_number = ? WHERE user_id = ?'
                );
                $updateStmt->execute([
                    $profile['full_name'],
                    $profile['email'],
                    $profile['mobile_number'] !== '' ? $profile['mobile_number'] : null,
                    $userId
                ]);
            }
            $_SESSION['full_name'] = $profile['full_name'];
            $_SESSION['email'] = $profile['email'];
            $success = 'Account information updated.';
            $_POST = [];
        } catch (PDOException $e) {
            error_log('Account update failed: ' . $e->getMessage());
            $errors[] = 'Unable to update your account right now.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <div class="card account-card">
        <h1 class="card-title">My Account</h1>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <form method="post" action="/account.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group"><label for="full_name">Full name</label><input class="form-input" id="full_name" name="full_name" maxlength="150" required value="<?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group"><label for="email">Email</label><input class="form-input" type="email" id="email" name="email" maxlength="255" required value="<?= htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group"><label for="mobile_number">Mobile number</label><input class="form-input" type="tel" id="mobile_number" name="mobile_number" maxlength="20" value="<?= htmlspecialchars((string) $profile['mobile_number'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <h2>Change password (optional)</h2>
            <div class="form-group"><label for="current_password">Current password</label><input class="form-input" type="password" id="current_password" name="current_password" autocomplete="current-password"></div>
            <div class="form-group"><label for="new_password">New password</label><input class="form-input" type="password" id="new_password" name="new_password" autocomplete="new-password"></div>
            <div class="form-group"><label for="confirm_password">Confirm new password</label><input class="form-input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"></div>
            <button class="btn btn-primary" type="submit">Save account</button>
        </form>
        <p><a href="/orders.php">View my orders</a></p>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
