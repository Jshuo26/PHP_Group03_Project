<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/audit.php';

requireRole(['admin']);

function auditPageEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function auditPageDate(string $value): ?string
{
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        return null;
    }

    return $date->format('Y-m-d');
}

function auditPageUrl(array $filters, int $page): string
{
    $query = array_filter($filters, static fn ($value) => $value !== '' && $value !== null);
    $query['page'] = $page;
    return '/admin/audit_logs.php?' . http_build_query($query);
}

$auditActionGroups = [
    'create' => ['create', 'register', 'cart_add', 'order_created', 'payment_initiated'],
    'update' => ['update', 'approve', 'reject', 'lock', 'unlock', 'email_verified', 'order_status_changed', 'order_cancelled', 'checkout_succeeded', 'checkout_failed', 'cart_update', 'payment_succeeded', 'payment_failed', 'payment_cancelled', 'payment_expired', 'mfa_failed', 'mfa_enabled', 'mfa_disabled', 'password_change_failed', 'password_changed', 'authorization_denied'],
    'delete' => ['delete', 'cart_remove'],
    'login' => ['login', 'login_failed', 'login_challenge'],
    'logout' => ['logout', 'session_expired'],
];

$auditModuleGroups = [
    'products' => ['products', 'books', 'genres', 'authors'],
    'checkout' => ['cart', 'checkout', 'orders'],
    'payments' => ['payments'],
    'account_security' => ['authentication', 'account', 'security'],
    'administration' => ['administration'],
    'system' => ['system'],
];

function auditPageActionLabel(string $action): string
{
    global $auditActionGroups;
    foreach ($auditActionGroups as $label => $actions) {
        if (in_array($action, $actions, true)) {
            return ucfirst($label);
        }
    }
    return 'Update';
}

function auditPageModuleLabel(?string $module): string
{
    global $auditModuleGroups;
    foreach ($auditModuleGroups as $label => $modules) {
        if (in_array($module, $modules, true)) {
            return match ($label) {
                'products' => 'Products',
                'checkout' => 'Check-out',
                'payments' => 'Payment',
                'account_security' => 'Account & Security',
                'administration' => 'Administration',
                default => 'System',
            };
        }
    }
    return 'Unknown / legacy';
}

$filters = [
    'user_id' => filter_var($_GET['user_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: '',
    'role' => trim((string) ($_GET['role'] ?? '')),
    'action' => trim((string) ($_GET['action'] ?? '')),
    'module' => trim((string) ($_GET['module'] ?? '')),
    'result' => trim((string) ($_GET['result'] ?? '')),
    'from' => auditPageDate(trim((string) ($_GET['from'] ?? ''))),
    'to' => auditPageDate(trim((string) ($_GET['to'] ?? ''))),
    'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

if (!in_array($filters['role'], ['customer', 'staff', 'admin', ''], true)) {
    $filters['role'] = '';
}
if (!in_array($filters['action'], array_merge(array_keys($auditActionGroups), ['']), true)) {
    $filters['action'] = '';
}
if (!in_array($filters['module'], array_merge(array_keys($auditModuleGroups), ['']), true)) {
    $filters['module'] = '';
}
if (!in_array($filters['result'], ['success', 'failure', 'info', 'unknown', ''], true)) {
    $filters['result'] = '';
}
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 30;
$rows = [];
$users = [];
$totalRows = 0;
$totalPages = 1;
$loadError = '';
$missingColumns = auditSchemaMissingColumns($pdo);

if (!$missingColumns) {
    try {
        $userStmt = $pdo->query('SELECT user_id, full_name, role FROM users ORDER BY user_id DESC LIMIT 500');
        foreach ($userStmt->fetchAll() as $userRow) {
            $name = 'User #' . (int) $userRow['user_id'];
            try {
                $decrypted = decryptUserPersonalFields($userRow);
                if (!empty($decrypted['full_name'])) {
                    $name = (string) $decrypted['full_name'];
                }
            } catch (Throwable $e) {
            }
            $users[] = [
                'user_id' => (int) $userRow['user_id'],
                'name' => $name,
                'role' => (string) $userRow['role'],
            ];
        }

        $where = [];
        $params = [];
        if ($filters['user_id'] !== '') {
            $where[] = 'logs.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if ($filters['role'] !== '') {
            $where[] = 'logs.actor_role = ?';
            $params[] = $filters['role'];
        }
        if ($filters['action'] !== '') {
            $actions = $auditActionGroups[$filters['action']];
            $where[] = 'logs.action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
            array_push($params, ...$actions);
        }
        if ($filters['module'] !== '') {
            $modules = $auditModuleGroups[$filters['module']];
            $where[] = 'logs.module IN (' . implode(',', array_fill(0, count($modules), '?')) . ')';
            array_push($params, ...$modules);
        }
        if ($filters['result'] === 'unknown') {
            $where[] = 'logs.result IS NULL';
        } elseif ($filters['result'] !== '') {
            $where[] = 'logs.result = ?';
            $params[] = $filters['result'];
        }
        if ($filters['from'] !== null) {
            $where[] = 'logs.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== null) {
            $where[] = 'logs.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $filters['to'] . ' 00:00:00';
        }
        if ($filters['q'] !== '') {
            $needle = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']) . '%';
            $where[] = '(CAST(logs.user_id AS CHAR) LIKE ? ESCAPE "!"
                         OR CAST(logs.record_id AS CHAR) LIKE ? ESCAPE "!"
                         OR logs.description LIKE ? ESCAPE "!")';
            array_push($params, $needle, $needle, $needle);
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs AS logs' . $whereSql);
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        $listStmt = $pdo->prepare(
            'SELECT logs.log_id, logs.user_id, logs.actor_role, logs.action, logs.description,
                    logs.module, logs.record_type, logs.record_id, logs.result, logs.created_at, logs.actor_name,
                    users.full_name AS actor_full_name
               FROM audit_logs AS logs
               LEFT JOIN users ON users.user_id = logs.user_id'
            . $whereSql . ' ORDER BY logs.created_at DESC, logs.log_id DESC LIMIT ' . $pageSize . ' OFFSET ' . (int) $offset
        );
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll();
        foreach ($rows as &$row) {
            $storedActorName = trim((string) ($row['actor_name'] ?? ''));
            $row['actor_name'] = $storedActorName !== '' ? $storedActorName : (!empty($row['user_id']) ? 'User #' . (int) $row['user_id'] : 'System / unknown');
            if ($storedActorName === '' && !empty($row['user_id']) && isset($row['actor_full_name'])) {
                try {
                    $name = decryptPersonalField(
                        (string) $row['actor_full_name'],
                        personalFieldContext('users', 'full_name', (int) $row['user_id'])
                    );
                    if ($name !== null && $name !== '') {
                        $row['actor_name'] = $name;
                    }
                } catch (Throwable $e) {
                }
            }
        }
        unset($row);
    } catch (Throwable $e) {
        error_log('Unable to load the admin audit trail.');
        $loadError = 'Audit records could not be loaded right now.';
    }
}

include __DIR__ . '/../includes/header.php';
?>

<main class="container admin-operations admin-audit-page">
    <header class="admin-page-hero admin-page-heading">
        <div><span class="admin-hero-eyebrow">ACCOUNTABILITY</span><h1>Audit Trail</h1><p>Review customer, staff, administrator, and system activity across the bookstore.</p></div>
        <div class="hero-meta"><a class="btn btn-secondary" href="/admin/index.php">Back to Dashboard</a></div>
    </header>
    <div class="admin-audit-card">

        <?php if ($missingColumns): ?>
            <div class="form-errors" role="alert">
                <p>The audit schema update is required before structured activity can be displayed.</p>
                <p>Review and apply <code>database/migrations/002_centralized_audit_trail.sql</code> to the intended database.</p>
            </div>
        <?php elseif ($loadError !== ''): ?>
            <div class="form-errors" role="alert"><p><?= auditPageEscape($loadError) ?></p></div>
        <?php else: ?>
            <form method="get" action="/admin/audit_logs.php" class="admin-audit-filters">
                <label>User
                    <select name="user_id" class="form-input">
                        <option value="">All users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int) $user['user_id'] ?>" <?= (string) $filters['user_id'] === (string) $user['user_id'] ? 'selected' : '' ?>>
                                <?= auditPageEscape($user['name'] . ' (' . $user['role'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Role
                    <select name="role" class="form-input">
                        <option value="">All roles</option>
                        <?php foreach (['customer' => 'Customer', 'staff' => 'Staff', 'admin' => 'Admin'] as $role => $label): ?>
                            <option value="<?= auditPageEscape($role) ?>" <?= $filters['role'] === $role ? 'selected' : '' ?>><?= auditPageEscape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Action
                    <select name="action" class="form-input">
                        <option value="">All actions</option>
                        <?php foreach (['create' => 'Create', 'update' => 'Update', 'delete' => 'Delete', 'login' => 'Login', 'logout' => 'Logout'] as $action => $label): ?>
                            <option value="<?= auditPageEscape($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= auditPageEscape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Module
                    <select name="module" class="form-input">
                        <option value="">All modules</option>
                        <?php foreach (['products' => 'Products', 'checkout' => 'Check-out', 'payments' => 'Payment', 'account_security' => 'Account & Security', 'administration' => 'Administration', 'system' => 'System'] as $module => $label): ?>
                            <option value="<?= auditPageEscape($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>><?= auditPageEscape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Result
                    <select name="result" class="form-input">
                        <option value="">All results</option>
                        <?php foreach (['success', 'failure', 'info', 'unknown'] as $result): ?>
                            <option value="<?= auditPageEscape($result) ?>" <?= $filters['result'] === $result ? 'selected' : '' ?>><?= auditPageEscape(ucfirst($result)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="audit-filter-bottom">
                    <label>From<input class="form-input" type="date" name="from" value="<?= auditPageEscape((string) $filters['from']) ?>"></label>
                    <label>To<input class="form-input" type="date" name="to" value="<?= auditPageEscape((string) $filters['to']) ?>"></label>
                    <label>Search user or record ID<input class="form-input" type="search" name="q" maxlength="100" value="<?= auditPageEscape($filters['q']) ?>" placeholder="User ID, record ID, or description"></label>
                    <div class="audit-filter-actions">
                        <button class="btn btn-primary" type="submit">Filter</button>
                        <a class="btn btn-secondary" href="/admin/audit_logs.php">Clear</a>
                    </div>
                </div>
            </form>

            <p class="admin-audit-count">Showing <?= $totalRows ? ($offset + 1) : 0 ?>–<?= min($offset + $pageSize, $totalRows) ?> of <?= $totalRows ?> records.</p>
            <div class="admin-audit-table-wrap">
                <table class="table admin-audit-table">
                    <thead><tr><th>Date and time</th><th>User</th><th>Role / actor</th><th>Action</th><th>Module</th><th>Record</th><th>Result</th><th>Description</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= auditPageEscape($row['created_at']) ?></td>
                            <td><?= auditPageEscape($row['actor_name']) ?></td>
                            <td><?= auditPageEscape(ucfirst((string) ($row['actor_role'] ?? 'unknown'))) ?></td>
                            <td><span class="audit-action-badge audit-action-<?= auditPageEscape(strtolower((string) $row['action'])) ?>"><?= auditPageEscape(auditPageActionLabel((string) $row['action'])) ?></span></td>
                            <td><?= auditPageEscape(auditPageModuleLabel(isset($row['module']) ? (string) $row['module'] : null)) ?></td>
                            <td><?= auditPageEscape($row['record_type'] ? $row['record_type'] . ' #' . ($row['record_id'] ?? '') : '') ?></td>
                            <td><?= auditPageEscape($row['result'] ?? 'Unknown / legacy') ?></td>
                            <td><?= auditPageEscape($row['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="8">No audit records match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <?php
                $visiblePages = [1, $totalPages];
                for ($pageNumber = max(1, $page - 1); $pageNumber <= min($totalPages, $page + 1); $pageNumber++) {
                    $visiblePages[] = $pageNumber;
                }
                $visiblePages = array_values(array_unique($visiblePages));
                sort($visiblePages);
                ?>
                <nav class="admin-audit-pagination" aria-label="Audit trail pages">
                    <?php if ($page > 1): ?>
                        <a class="audit-page-link" href="<?= auditPageEscape(auditPageUrl($filters, $page - 1)) ?>" rel="prev">Previous</a>
                    <?php else: ?>
                        <span class="audit-page-link is-disabled" aria-disabled="true">Previous</span>
                    <?php endif; ?>
                    <?php $lastVisiblePage = 0; foreach ($visiblePages as $pageNumber): ?>
                        <?php if ($pageNumber > $lastVisiblePage + 1): ?><span class="audit-page-ellipsis" aria-hidden="true">…</span><?php endif; ?>
                        <?php if ($pageNumber === $page): ?>
                            <span class="audit-page-link is-current" aria-current="page"><?= $pageNumber ?></span>
                        <?php else: ?>
                            <a class="audit-page-link" href="<?= auditPageEscape(auditPageUrl($filters, $pageNumber)) ?>"><?= $pageNumber ?></a>
                        <?php endif; ?>
                        <?php $lastVisiblePage = $pageNumber; ?>
                    <?php endforeach; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="audit-page-link" href="<?= auditPageEscape(auditPageUrl($filters, $page + 1)) ?>" rel="next">Next</a>
                    <?php else: ?>
                        <span class="audit-page-link is-disabled" aria-disabled="true">Next</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
