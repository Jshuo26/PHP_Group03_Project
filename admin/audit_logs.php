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

if (!in_array($filters['role'], array_merge(AUDIT_ROLES, ['']), true)) {
    $filters['role'] = '';
}
if (!in_array($filters['action'], array_merge(AUDIT_ACTIONS, ['']), true)) {
    $filters['action'] = '';
}
if (!in_array($filters['module'], array_merge(AUDIT_MODULES, ['__unknown', '']), true)) {
    $filters['module'] = '';
}
if (!in_array($filters['result'], ['success', 'failure', 'info', 'unknown', ''], true)) {
    $filters['result'] = '';
}
if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$pageSize = 50;
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
        if ($filters['role'] === 'unknown') {
            $where[] = '(logs.actor_role IS NULL OR logs.actor_role = ?)';
            $params[] = 'unknown';
        } elseif ($filters['role'] !== '') {
            $where[] = 'logs.actor_role = ?';
            $params[] = $filters['role'];
        }
        foreach (['action' => 'action', 'module' => 'module'] as $filter => $column) {
            if ($filters[$filter] === '__unknown') {
                $where[] = 'logs.' . $column . ' IS NULL';
            } elseif ($filters[$filter] !== '') {
                $where[] = 'logs.' . $column . ' = ?';
                $params[] = $filters[$filter];
            }
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

<section class="container">
    <div class="card">
        <h1 class="card-title">Audit Trail</h1>
        <p class="card-text">Review customer, staff, administrator, and system activity.</p>
        <p><a class="btn btn-secondary" href="/admin/index.php">Back to administration</a></p>

        <?php if ($missingColumns): ?>
            <div class="form-errors" role="alert">
                <p>The audit schema update is required before structured activity can be displayed.</p>
                <p>Review and apply <code>database/migrations/002_centralized_audit_trail.sql</code> to the intended database.</p>
            </div>
        <?php elseif ($loadError !== ''): ?>
            <div class="form-errors" role="alert"><p><?= auditPageEscape($loadError) ?></p></div>
        <?php else: ?>
            <form method="get" action="/admin/audit_logs.php" class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
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
                        <?php foreach (array_merge(AUDIT_ROLES, ['unknown']) as $role): ?>
                            <option value="<?= auditPageEscape($role) ?>" <?= $filters['role'] === $role ? 'selected' : '' ?>><?= auditPageEscape(ucfirst($role)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Action
                    <select name="action" class="form-input">
                        <option value="">All actions</option>
                        <?php foreach (AUDIT_ACTIONS as $action): ?>
                            <option value="<?= auditPageEscape($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= auditPageEscape(str_replace('_', ' ', ucfirst($action))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Module
                    <select name="module" class="form-input">
                        <option value="">All modules</option>
                        <?php foreach (AUDIT_MODULES as $module): ?>
                            <option value="<?= auditPageEscape($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>><?= auditPageEscape(ucfirst($module)) ?></option>
                        <?php endforeach; ?>
                        <option value="__unknown" <?= $filters['module'] === '__unknown' ? 'selected' : '' ?>>Unknown / legacy</option>
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
                <label>From<input class="form-input" type="date" name="from" value="<?= auditPageEscape((string) $filters['from']) ?>"></label>
                <label>To<input class="form-input" type="date" name="to" value="<?= auditPageEscape((string) $filters['to']) ?>"></label>
                <label>Search user or record ID<input class="form-input" type="search" name="q" maxlength="100" value="<?= auditPageEscape($filters['q']) ?>" placeholder="User ID, record ID, or description"></label>
                <div style="align-self:end;display:flex;gap:8px;">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a class="btn btn-secondary" href="/admin/audit_logs.php">Reset</a>
                </div>
            </form>

            <p class="card-text">Showing <?= $totalRows ? ($offset + 1) : 0 ?>–<?= min($offset + $pageSize, $totalRows) ?> of <?= $totalRows ?> records.</p>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Record</th><th>Result</th><th>Description</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= auditPageEscape($row['created_at']) ?></td>
                            <td><?= auditPageEscape($row['actor_name']) ?></td>
                            <td><?= auditPageEscape($row['actor_role'] ?? 'Unknown') ?></td>
                            <td><?= auditPageEscape(str_replace('_', ' ', (string) $row['action'])) ?></td>
                            <td><?= auditPageEscape($row['module'] ?? 'Unknown / legacy') ?></td>
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
                <nav aria-label="Audit trail pages" style="display:flex;gap:12px;align-items:center;margin-top:16px;">
                    <?php if ($page > 1): ?><a class="btn btn-secondary" href="<?= auditPageEscape(auditPageUrl($filters, $page - 1)) ?>">Newer</a><?php endif; ?>
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?><a class="btn btn-secondary" href="<?= auditPageEscape(auditPageUrl($filters, $page + 1)) ?>">Older</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>