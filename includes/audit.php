<?php

const AUDIT_ACTIONS = ['login', 'logout', 'create', 'update', 'delete', 'approve', 'reject', 'lock', 'unlock'];

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

function auditLog(
    PDO $pdo,
    string $action,
    string $description,
    ?string $module = null,
    ?int $userId = null
): void {
    try {
        if (!in_array($action, AUDIT_ACTIONS, true)) {
            $description = '[' . $action . '] ' . $description;
            $action = 'update';
        }

        if ($userId === null && !empty($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }

        $description = mb_substr($description, 0, 500);

        if (auditHasExtraColumns($pdo)) {
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, description, module, ip_address)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $action,
                $description,
                $module !== null ? mb_substr($module, 0, 50) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)'
            )->execute([
                $userId,
                $action,
                $module !== null ? '[' . $module . '] ' . mb_substr($description, 0, 480) : $description,
            ]);
        }
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}