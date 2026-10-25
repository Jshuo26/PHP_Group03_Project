<?php

const AUDIT_ACTIONS = [
    'login', 'login_failed', 'login_challenge', 'logout', 'session_expired', 'register', 'email_verified',
    'create', 'update', 'delete', 'approve', 'reject', 'lock', 'unlock', 'authorization_denied',
    'cart_add', 'cart_update', 'cart_remove', 'checkout_succeeded', 'checkout_failed', 'order_created', 'order_status_changed',
    'order_cancelled', 'mfa_failed', 'mfa_enabled', 'mfa_disabled', 'password_change_failed', 'password_changed',
    'payment_initiated', 'payment_succeeded', 'payment_failed', 'payment_cancelled', 'payment_expired',
];

const AUDIT_MODULES = [
    'authentication', 'account', 'administration', 'security', 'products', 'books', 'genres', 'authors',
    'cart', 'checkout', 'orders', 'payments', 'system',
];

const AUDIT_ROLES = ['customer', 'staff', 'admin', 'system', 'anonymous', 'unknown'];
const AUDIT_RESULTS = ['success', 'failure', 'info'];

function auditSchemaMissingColumns(PDO $pdo): array
{
    static $missing = null;
    if ($missing !== null) {
        return $missing;
    }

    $required = ['user_id', 'actor_name', 'action', 'description', 'created_at', 'module', 'actor_role', 'record_type', 'record_id', 'result'];
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN, 0);
        $missing = array_values(array_diff($required, $columns));
    } catch (Throwable $e) {
        $missing = $required;
    }

    return $missing;
}

function auditHasExtraColumns(PDO $pdo): bool
{
    static $has = null;

    if ($has === null) {
        try {
            $has = (bool) $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'ip_address'")->fetch()
                && (bool) $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'module'")->fetch();
        } catch (PDOException $e) {
            $has = false;
        }
    }

    return $has;
}

function auditHasActorNameColumn(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_name'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function auditLog(
    PDO $pdo,
    string $action,
    string $description,
    ?string $module = null,
    ?int $userId = null,
    ?string $recordType = null,
    ?int $recordId = null,
    string $result = 'success',
    ?string $actorRole = null,
    ?string $actorName = null
): bool {
    if (!in_array($action, AUDIT_ACTIONS, true)
        || !in_array($result, AUDIT_RESULTS, true)
        || ($module !== null && !in_array($module, AUDIT_MODULES, true))
        || ($recordType !== null && !preg_match('/^[a-z_]{1,50}$/', $recordType))) {
        error_log('Audit log rejected an invalid event definition.');
        return false;
    }

    if ($userId === 0) {
        $userId = null;
    } elseif ($userId === null && !empty($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }

    if ($actorRole === null) {
        $sessionRole = $_SESSION['role'] ?? null;
        $actorRole = in_array($sessionRole, AUDIT_ROLES, true)
            ? $sessionRole
            : ($userId === null ? 'system' : 'unknown');
    }
    if (!in_array($actorRole, AUDIT_ROLES, true)) {
        error_log('Audit log rejected an invalid actor role.');
        return false;
    }

    if ($actorName === null && $userId !== null && (int) ($_SESSION['user_id'] ?? 0) === $userId) {
        $actorName = isset($_SESSION['full_name']) ? (string) $_SESSION['full_name'] : null;
    }
    if ($actorName === null && $userId === null) {
        $actorName = $actorRole === 'system' ? 'System' : ($actorRole === 'anonymous' ? 'Anonymous visitor' : null);
    }

    if (auditSchemaMissingColumns($pdo)) {
        static $schemaWarningWritten = false;
        if (!$schemaWarningWritten) {
            error_log('Central audit schema migration is required; structured audit records are not being written.');
            $schemaWarningWritten = true;
        }
        return false;
    }

    try {
        $description = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $description) ?? '';
        $description = mb_substr(trim($description), 0, 500);
        if ($actorName !== null) {
            $actorName = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $actorName) ?? ''), 0, 255);
        }
        if (auditHasActorNameColumn($pdo)) {
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id, actor_name, action, description, module, actor_role, record_type, record_id, result)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId, $actorName, $action, $description, $module, $actorRole, $recordType, $recordId, $result,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, description, module, actor_role, record_type, record_id, result)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId, $action, $description, $module, $actorRole, $recordType, $recordId, $result,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('Audit log write failed for action ' . $action . ' in module ' . ($module ?? 'unknown') . '.');
        return false;
    }
}

function auditLogRequired(PDO $pdo, string $action, ...$arguments): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Required audit records must share the business transaction.');
    }
    if (!auditLog($pdo, $action, ...$arguments)) {
        throw new RuntimeException('The activity could not be recorded. Please try again.');
    }
}