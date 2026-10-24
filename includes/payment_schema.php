<?php

function paymentSchemaHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $allowed = [
        'orders' => ['payment_method', 'payment_status'],
        'payments' => [
            'payment_method', 'checkout_session_id', 'checkout_url', 'manual_review_required', 'provider_payment_id',
            'provider_intent_id', 'idempotency_key', 'currency',
        ],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        return false;
    }

    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
            $stmt->execute([$column]);
            $cache[$key] = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }

    return $cache[$key];
}

function paymongoSchemaReady(PDO $pdo): bool
{
    foreach ([
        ['orders', 'payment_method'], ['orders', 'payment_status'],
        ['payments', 'payment_method'], ['payments', 'checkout_session_id'],
        ['payments', 'manual_review_required'],
        ['payments', 'checkout_url'], ['payments', 'provider_payment_id'],
        ['payments', 'provider_intent_id'], ['payments', 'idempotency_key'],
        ['payments', 'currency'],
    ] as [$table, $column]) {
        if (!paymentSchemaHasColumn($pdo, $table, $column)) {
            return false;
        }
    }

    try {
        $pdo->query('SELECT event_id FROM payment_webhook_events LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}