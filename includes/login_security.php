<?php

const LOGIN_DEFAULT_MAX_ATTEMPTS = 3;

function isAccountLocked(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM locked_accounts WHERE user_id = ? AND unlocked_at IS NULL LIMIT 1'
    );
    $stmt->execute([$userId]);

    return (bool) $stmt->fetchColumn();
}

function recordFailedLogin(PDO $pdo, int $userId): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $pdo->prepare('INSERT INTO login_attempts (user_id, ip_address, success) VALUES (?, ?, 0)')
        ->execute([$userId, $ip]);

    $max = (int) $pdo->query(
        'SELECT max_failed_attempts FROM security_policy WHERE policy_id = 1 LIMIT 1'
    )->fetchColumn();

    if ($max < 1) {
        $max = LOGIN_DEFAULT_MAX_ATTEMPTS;
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM login_attempts
         WHERE user_id = ?
         AND success = 0
         AND attempted_at > GREATEST(
             COALESCE((SELECT MAX(attempted_at) FROM login_attempts
                       WHERE user_id = ? AND success = 1), '1970-01-01 00:00:00'),
             COALESCE((SELECT unlocked_at FROM locked_accounts
                       WHERE user_id = ?), '1970-01-01 00:00:00')
         )"
    );
    $countStmt->execute([$userId, $userId, $userId]);

    if ((int) $countStmt->fetchColumn() < $max) {
        return false;
    }

    $pdo->prepare(
        'INSERT INTO locked_accounts (user_id, reason)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
            locked_at = NOW(),
            reason = VALUES(reason),
            unlocked_by = NULL,
            unlocked_at = NULL'
    )->execute([$userId, 'Too many failed login attempts.']);

    return true;
}

function recordSuccessfulLogin(PDO $pdo, int $userId): void
{
    $pdo->prepare('INSERT INTO login_attempts (user_id, ip_address, success) VALUES (?, ?, 1)')
        ->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
}