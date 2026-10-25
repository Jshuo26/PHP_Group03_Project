<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/audit.php';

requireRole(['admin']);
$errors = [];
$success = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);
$actorId = (int) $_SESSION['user_id'];
$editId = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$editUser = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT user_id, first_name, last_name, full_name, email, role, account_status FROM users WHERE user_id = ? AND role IN ('staff','admin')");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
    if ($editUser) $editUser = decryptUserPersonalFields($editUser);
    if (!$editUser || $editId === $actorId) {
        $editUser = null;
        $errors[] = 'That account cannot be edited from this page.';
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');
    if ($action !== 'save') {
        $errors[] = 'Choose a valid staff-account action.';
    } else {
        $targetId = filter_var($_POST['user_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
        $firstName = trim(requestString($_POST, 'first_name'));
        $lastName = trim(requestString($_POST, 'last_name'));
        $fullName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $lastName));
        $email = normalizeEmailForLookup(requestString($_POST, 'email'));
        $role = requestString($_POST, 'role');
        $status = requestString($_POST, 'account_status');
        $password = requestString($_POST, 'password');

        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 75 || mb_strlen($lastName) > 75
            || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $firstName)
            || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $lastName)) {
            $errors[] = 'Enter a valid first and last name (up to 75 characters each).';
        }
        if (mb_strlen($fullName) > 150) $errors[] = 'Full name must be 150 characters or fewer.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) $errors[] = 'Enter a valid email address.';
        if (!in_array($role, ['staff', 'admin'], true)) $errors[] = 'Choose Staff or Admin as the account role.';
        if (!in_array($status, ['active', 'suspended'], true)) $errors[] = 'Choose Active or Suspended as the account status.';
        if ($targetId === $actorId) $errors[] = 'You cannot change your own role or account status here.';
        if ($targetId === 0 && $password === '') $errors[] = 'A password is required when creating an account.';
        if ($password !== '') {
            foreach (validatePasswordAgainstPolicy($password, getPasswordPolicy($pdo)) as $passwordError) $errors[] = $passwordError;
        }

        if (!$errors) {
            $duplicate = $pdo->prepare('SELECT user_id FROM users WHERE email_lookup_hash = ? AND user_id <> ? LIMIT 1');
            $duplicate->execute([emailLookupHash($email), $targetId]);
            if ($duplicate->fetchColumn()) $errors[] = 'That email address is already used by another account.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($targetId > 0) {
                    $lock = $pdo->prepare("SELECT user_id, role, account_status FROM users WHERE user_id = ? AND role IN ('staff','admin') FOR UPDATE");
                    $lock->execute([$targetId]);
                    $previous = $lock->fetch();
                    if (!$previous) throw new RuntimeException('Staff account not found.');
                    if ($previous['role'] === 'admin' && ($role !== 'admin' || $status !== 'active')) {
                        $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'")->fetchColumn();
                        if ($activeAdmins <= 1) throw new RuntimeException('The last active administrator cannot be demoted or suspended.');
                    }
                    $sql = 'UPDATE users SET first_name = ?, last_name = ?, full_name = ?, email = ?, email_lookup_hash = ?, role = ?, account_status = ?';
                    $params = [
                        encryptPersonalField($firstName, personalFieldContext('users', 'first_name', $targetId)),
                        encryptPersonalField($lastName, personalFieldContext('users', 'last_name', $targetId)),
                        encryptPersonalField($fullName, personalFieldContext('users', 'full_name', $targetId)),
                        encryptPersonalField($email, personalFieldContext('users', 'email', $targetId)),
                        emailLookupHash($email), $role, $status,
                    ];
                    if ($password !== '') {
                        $sql .= ', password_hash = ?';
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE user_id = ?';
                    $params[] = $targetId;
                    $pdo->prepare($sql)->execute($params);
                    auditLogRequired($pdo, 'update', 'Administrator updated a staff account, role, or access status.', 'administration', $actorId, 'user', $targetId, 'success', 'admin');
                    $message = 'Staff account updated.';
                    $recordId = $targetId;
                    $auditAction = 'update';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO users (first_name, last_name, full_name, email, email_lookup_hash, password_hash, role, account_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        encryptPersonalField($firstName, personalFieldContext('users', 'first_name', 0)),
                        encryptPersonalField($lastName, personalFieldContext('users', 'last_name', 0)),
                        encryptPersonalField($fullName, personalFieldContext('users', 'full_name', 0)),
                        encryptPersonalField($email, personalFieldContext('users', 'email', 0)),
                        emailLookupHash($email), password_hash($password, PASSWORD_DEFAULT), $role, $status,
                    ]);
                    $recordId = (int) $pdo->lastInsertId();
                    // Re-encrypt fields using the generated user ID as authenticated context.
                    $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, full_name = ?, email = ? WHERE user_id = ?')->execute([
                        encryptPersonalField($firstName, personalFieldContext('users', 'first_name', $recordId)),
                        encryptPersonalField($lastName, personalFieldContext('users', 'last_name', $recordId)),
                        encryptPersonalField($fullName, personalFieldContext('users', 'full_name', $recordId)),
                        encryptPersonalField($email, personalFieldContext('users', 'email', $recordId)),
                        $recordId,
                    ]);
                    auditLogRequired($pdo, 'create', 'Administrator created a staff or administrator account.', 'administration', $actorId, 'user', $recordId, 'success', 'admin');
                    $message = 'Staff account created.';
                    $auditAction = 'create';
                }
                $pdo->commit();
                $_SESSION['admin_flash'] = $message;
                header('Location: /admin/users.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this account. Verify the database fields and try again.';
            }
        }
        if ($targetId > 0) $editId = $targetId;
        $editUser = ['user_id' => $targetId, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'role' => $role, 'account_status' => $status];
    }
}

$staffUsers = $pdo->query("SELECT user_id, first_name, last_name, full_name, email, role, account_status, created_at FROM users WHERE role IN ('staff','admin') ORDER BY role, created_at DESC LIMIT 500")->fetchAll();
foreach ($staffUsers as &$staffUser) $staffUser = decryptUserPersonalFields($staffUser);
unset($staffUser);
include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <div class="card">
        <h1 class="card-title">Staff and Access Management</h1>
        <p class="card-text">Administrators can create and manage staff accounts, assign roles, and suspend or reactivate access. Staff cannot access this page.</p>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <h2><?= $editUser ? 'Edit account' : 'Create staff account' ?></h2>
        <form method="post" action="/admin/users.php<?= $editUser && $editId ? '?edit=' . (int) $editId : '' ?>" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= (int) ($editUser['user_id'] ?? 0) ?>">
            <label>First name<input class="form-input" name="first_name" maxlength="75" required value="<?= htmlspecialchars((string) ($editUser['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Last name<input class="form-input" name="last_name" maxlength="75" required value="<?= htmlspecialchars((string) ($editUser['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Email<input class="form-input" type="email" name="email" maxlength="255" required value="<?= htmlspecialchars((string) ($editUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Role<select class="form-input" name="role"><option value="staff" <?= ($editUser['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Staff</option><option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
            <label>Account status<select class="form-input" name="account_status"><option value="active" <?= ($editUser['account_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= ($editUser['account_status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></label>
            <label>Password <?= $editUser ? '(leave blank to keep current)' : '' ?><input class="form-input" type="password" name="password" autocomplete="new-password" <?= $editUser ? '' : 'required' ?>></label>
            <div><button class="btn btn-primary" type="submit"><?= $editUser ? 'Save account' : 'Create account' ?></button><?php if ($editUser): ?> <a class="btn btn-secondary" href="/admin/users.php">Cancel</a><?php endif; ?></div>
        </form>
        <p class="card-text">Staff and administrator accounts are required to enroll in Google Authenticator at first sign-in.</p>
    </div>
    <h2 class="section-title">Staff and administrator accounts</h2>
    <?php foreach ($staffUsers as $staffUser): ?>
        <article class="card admin-list-item">
            <h3><?= htmlspecialchars((string) $staffUser['full_name'], ENT_QUOTES, 'UTF-8') ?> <small>#<?= (int) $staffUser['user_id'] ?></small></h3>
            <p><?= htmlspecialchars((string) $staffUser['email'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string) $staffUser['role']), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string) $staffUser['account_status']), ENT_QUOTES, 'UTF-8') ?></p>
            <p>Created <?= htmlspecialchars((string) $staffUser['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php if ((int) $staffUser['user_id'] === $actorId): ?><span class="card-text">Your signed-in account</span><?php else: ?><a class="btn btn-secondary" href="/admin/users.php?edit=<?= (int) $staffUser['user_id'] ?>">Edit account</a><?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
