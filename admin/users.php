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
<main class="container admin-operations admin-users-page">
    <header class="operations-page-heading">
        <div><span class="operations-eyebrow">TEAM ACCESS</span><h1>Staff &amp; Roles</h1><p>Create staff accounts and manage access. This workspace is available to administrators.</p></div>
        <span class="operations-count"><?= count($staffUsers) ?> team <?= count($staffUsers) === 1 ? 'account' : 'accounts' ?></span>
    </header>
    <section class="card staff-account-editor">
        <div class="operations-section-heading"><div><span class="operations-eyebrow"><?= $editUser ? 'ACCOUNT SETTINGS' : 'TEAM SETUP' ?></span><h2><?= $editUser ? 'Edit staff account' : 'Create staff account' ?></h2></div><?php if ($editUser): ?><a class="btn btn-secondary" href="/admin/users.php">Cancel Edit</a><?php endif; ?></div>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <form method="post" action="/admin/users.php<?= $editUser && $editId ? '?edit=' . (int) $editId : '' ?>" class="staff-account-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= (int) ($editUser['user_id'] ?? 0) ?>">
            <label class="staff-field-first">First Name<input class="form-input" name="first_name" maxlength="75" required value="<?= htmlspecialchars((string) ($editUser['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="staff-field-last">Last Name<input class="form-input" name="last_name" maxlength="75" required value="<?= htmlspecialchars((string) ($editUser['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="staff-field-email">Email Address<input class="form-input" type="email" name="email" maxlength="255" required value="<?= htmlspecialchars((string) ($editUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="staff-field-role">Role<select class="form-input" name="role"><option value="staff" <?= ($editUser['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Staff</option><option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
            <label class="staff-field-status">Account Status<select class="form-input" name="account_status"><option value="active" <?= ($editUser['account_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= ($editUser['account_status'] ?? 'active') === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></label>
            <label class="staff-field-password">Password <?= $editUser ? '(leave blank to keep current)' : '' ?><input class="form-input" type="password" name="password" autocomplete="new-password" <?= $editUser ? '' : 'required' ?>></label>
            <div class="staff-form-footer"><p>Staff and administrator accounts enroll in Google Authenticator at first sign-in.</p><button class="btn btn-primary" type="submit"><?= $editUser ? 'Save Changes' : 'Create Account' ?></button></div>
        </form>
    </section>
    <section class="staff-directory" aria-labelledby="staff-directory-title">
        <div class="operations-section-heading"><div><span class="operations-eyebrow">ACCESS DIRECTORY</span><h2 id="staff-directory-title">Staff and administrators</h2></div><span class="operations-count"><?= count($staffUsers) ?> listed</span></div>
        <div class="staff-account-grid">
            <?php foreach ($staffUsers as $staffUser): ?>
                <article class="staff-account-card">
                    <div class="staff-account-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $staffUser['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="staff-account-info"><h3><?= htmlspecialchars((string) $staffUser['full_name'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) $staffUser['email'], ENT_QUOTES, 'UTF-8') ?></p><div class="staff-account-badges"><span class="staff-role-badge role-<?= htmlspecialchars((string) $staffUser['role'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst((string) $staffUser['role']), ENT_QUOTES, 'UTF-8') ?></span><span class="staff-state-badge state-<?= htmlspecialchars((string) $staffUser['account_status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst((string) $staffUser['account_status']), ENT_QUOTES, 'UTF-8') ?></span><small>Since <?= htmlspecialchars(date('M j, Y', strtotime((string) $staffUser['created_at'])), ENT_QUOTES, 'UTF-8') ?></small></div></div>
                    <div class="staff-account-action"><?php if ((int) $staffUser['user_id'] === $actorId): ?><span class="you-account-badge">You</span><?php else: ?><a class="btn btn-secondary" href="/admin/users.php?edit=<?= (int) $staffUser['user_id'] ?>">Edit Account</a><?php endif; ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
