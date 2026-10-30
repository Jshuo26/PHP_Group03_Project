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
    if (!$editUser || $editId !== $actorId) {
        $editUser = null;
        $errors[] = $editId === $actorId ? 'Your profile could not be loaded.' : 'You can edit only your own account from this page.';
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
        $confirmPassword = requestString($_POST, 'confirm_password');
        $isSelfEdit = $targetId > 0 && $targetId === $actorId;
        if ($targetId > 0 && !$isSelfEdit) $errors[] = 'You can edit only your own account. Create new staff accounts using this form.';
        if ($isSelfEdit) { $role = (string) $_SESSION['role']; $status = 'active'; }

        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 75 || mb_strlen($lastName) > 75
            || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $firstName)
            || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $lastName)) {
            $errors[] = 'Enter a valid first and last name (up to 75 characters each).';
        }
        if (mb_strlen($fullName) > 150) $errors[] = 'Full name must be 150 characters or fewer.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) $errors[] = 'Enter a valid email address.';
        if (!in_array($role, ['staff', 'admin'], true)) $errors[] = 'Choose Staff or Admin as the account role.';
        if (!in_array($status, ['active', 'suspended'], true)) $errors[] = 'Choose Active or Suspended as the account status.';
        if ($targetId === 0) { $status = 'active'; if ($password === '') $errors[] = 'A password is required when creating an account.'; }
        if ($password !== '' && !hash_equals($password, $confirmPassword)) $errors[] = 'Password and confirmation must match.';
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
                    if ($targetId !== $actorId) throw new RuntimeException('You can edit only your own account.');
                    $lock = $pdo->prepare("SELECT user_id, role, account_status FROM users WHERE user_id = ? AND role IN ('staff','admin') FOR UPDATE");
                    $lock->execute([$targetId]);
                    $previous = $lock->fetch();
                    if (!$previous) throw new RuntimeException('Staff account not found.');
                    $sql = 'UPDATE users SET first_name = ?, last_name = ?, full_name = ?, email = ?, email_lookup_hash = ?';
                    $params = [
                        encryptPersonalField($firstName, personalFieldContext('users', 'first_name', $targetId)),
                        encryptPersonalField($lastName, personalFieldContext('users', 'last_name', $targetId)),
                        encryptPersonalField($fullName, personalFieldContext('users', 'full_name', $targetId)),
                        encryptPersonalField($email, personalFieldContext('users', 'email', $targetId)),
                        emailLookupHash($email),
                    ];
                    if ($password !== '') {
                        $sql .= ', password_hash = ?';
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE user_id = ?';
                    $params[] = $targetId;
                    $pdo->prepare($sql)->execute($params);
                    auditLogRequired($pdo, 'update', 'Administrator updated their own account profile.', 'administration', $actorId, 'user', $targetId, 'success', 'admin');
                    $message = 'Your account profile was updated.';
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
                if ($targetId === $actorId) {
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                }
                $_SESSION['admin_flash'] = $message;
                header('Location: /admin/users.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this account. Verify the database fields and try again.';
            }
        }
        if ($targetId === $actorId && $targetId > 0) {
            $editId = $targetId;
            $editUser = ['user_id' => $targetId, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'role' => $role, 'account_status' => $status];
        } else {
            $editId = 0;
            $editUser = null;
        }
    }
}

$staffUsers = $pdo->query("SELECT user_id, first_name, last_name, full_name, email, role, account_status, created_at FROM users WHERE role IN ('staff','admin') ORDER BY role, created_at DESC LIMIT 500")->fetchAll();
foreach ($staffUsers as &$staffUser) $staffUser = decryptUserPersonalFields($staffUser);
unset($staffUser);
include __DIR__ . '/../includes/header.php';
?>
<main class="container admin-operations admin-users-page">
    <header class="admin-page-hero admin-page-heading">
        <div><span class="admin-hero-eyebrow">TEAM ACCESS</span><h1>Staff &amp; Roles</h1><p>Create staff accounts and update your own profile. Other team accounts remain protected.</p></div>
        <div class="hero-meta"><span class="operations-count"><?= count($staffUsers) ?> team <?= count($staffUsers) === 1 ? 'account' : 'accounts' ?></span></div>
    </header>
    <div class="admin-users-workspace">
    <section class="card staff-account-editor">
        <div class="operations-section-heading"><div><span class="operations-eyebrow"><?= $editUser ? 'ACCOUNT SETTINGS' : 'TEAM SETUP' ?></span><h2><?= $editUser ? 'Edit My Profile' : 'Create Staff Account' ?></h2></div><?php if ($editUser): ?><a class="btn btn-secondary" href="/admin/users.php">Cancel Edit</a><?php endif; ?></div>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <form method="post" action="/admin/users.php<?= $editUser && $editId ? '?edit=' . (int) $editId : '' ?>" class="staff-account-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= (int) ($editUser['user_id'] ?? 0) ?>">
            <label class="staff-field-first">First Name<input class="form-input" name="first_name" maxlength="75" required value="<?= htmlspecialchars((string) ($editUser['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="staff-field-last">Last Name<input class="form-input" name="last_name" maxlength="75" required value="<?= htmlspecialchars((string) ($editUser['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="staff-field-email">Email Address<input class="form-input" type="email" name="email" maxlength="255" required value="<?= htmlspecialchars((string) ($editUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <?php if ($editUser): ?><input type="hidden" name="role" value="<?= htmlspecialchars((string) $editUser['role'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="account_status" value="<?= htmlspecialchars((string) $editUser['account_status'], ENT_QUOTES, 'UTF-8') ?>"><div class="staff-field-role staff-edit-note">Role: <?= htmlspecialchars(ucfirst((string) $editUser['role']), ENT_QUOTES, 'UTF-8') ?> · Status: <?= htmlspecialchars(ucfirst((string) $editUser['account_status']), ENT_QUOTES, 'UTF-8') ?><small>Role and access status are managed separately.</small></div><?php else: ?>
            <label class="staff-field-role">Role<select class="form-input" name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></label><?php endif; ?>
            <label class="staff-field-password">Password <?= $editUser ? '(leave blank to keep current)' : '' ?><span class="password-wrapper"><input class="form-input" id="staff-password" type="password" name="password" autocomplete="new-password" <?= $editUser ? '' : 'required' ?>><button type="button" class="password-toggle" data-target="staff-password" aria-label="Show password" aria-pressed="false"><svg class="icon-closed" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg><svg class="icon-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113ZM17.25 12a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0Z" clip-rule="evenodd"/></svg></button></span></label>
            <label class="staff-field-confirm">Confirm Password<span class="password-wrapper"><input class="form-input" id="staff-confirm-password" type="password" name="confirm_password" autocomplete="new-password" <?= $editUser ? '' : 'required' ?>><button type="button" class="password-toggle" data-target="staff-confirm-password" aria-label="Show password" aria-pressed="false"><svg class="icon-closed" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg><svg class="icon-open" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path fill-rule="evenodd" d="M1.323 11.447A9.707 9.707 0 0 1 12 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 0 1 0-1.113Z" clip-rule="evenodd"/></svg></button></span></label>
            <div class="staff-form-footer"><p>Staff and administrator accounts enroll in Google Authenticator at first sign-in.</p><button class="btn btn-primary" type="submit"><?= $editUser ? 'Save Changes' : 'Create Account' ?></button></div>
        </form>
    </section>
    <section class="staff-directory" aria-labelledby="staff-directory-title">
        <div class="operations-section-heading"><div><span class="operations-eyebrow">ACCESS DIRECTORY</span><h2 id="staff-directory-title">Staff and Administrators</h2></div><span class="operations-count"><?= count($staffUsers) ?> Listed</span></div>
        <nav class="staff-filter-tabs" aria-label="Filter team accounts"><button type="button" data-staff-filter="all" aria-pressed="true">All</button><button type="button" data-staff-filter="admin" aria-pressed="false">Admin</button><button type="button" data-staff-filter="staff" aria-pressed="false">Staff</button></nav>
        <div class="staff-account-grid">
            <?php foreach ($staffUsers as $staffUser): ?>
                <article class="staff-account-card" data-role="<?= htmlspecialchars((string) $staffUser['role'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="staff-account-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $staffUser['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="staff-account-info"><h3><?= htmlspecialchars((string) $staffUser['full_name'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) $staffUser['email'], ENT_QUOTES, 'UTF-8') ?></p><div class="staff-account-badges"><span class="staff-role-badge role-<?= htmlspecialchars((string) $staffUser['role'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst((string) $staffUser['role']), ENT_QUOTES, 'UTF-8') ?></span><span class="staff-state-badge state-<?= htmlspecialchars((string) $staffUser['account_status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst((string) $staffUser['account_status']), ENT_QUOTES, 'UTF-8') ?></span><small>Since <?= htmlspecialchars(date('M j, Y', strtotime((string) $staffUser['created_at'])), ENT_QUOTES, 'UTF-8') ?></small></div></div>
                    <div class="staff-account-action"><?php if ((int) $staffUser['user_id'] === $actorId): ?><a class="btn btn-secondary" href="/admin/users.php?edit=<?= (int) $staffUser['user_id'] ?>">Edit My Profile</a><?php else: ?><span class="you-account-badge">Protected</span><?php endif; ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
