<?php

/**
 * Low-stock alert helpers. Call after every stock change, inside the same
 * transaction when the caller already has one. Missing migration is treated as
 * a disabled feature so older deployments keep checkout and inventory working.
 */
function lowStockAlertsReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $threshold = $pdo->query('SELECT low_stock_threshold FROM inventory_settings WHERE setting_id = 1')->fetchColumn();
        $pdo->query('SELECT edition_id FROM low_stock_alerts LIMIT 0');
        $ready = $threshold !== false;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function getLowStockThreshold(PDO $pdo): int
{
    if (!lowStockAlertsReady($pdo)) {
        return 5;
    }
    $value = $pdo->query('SELECT low_stock_threshold FROM inventory_settings WHERE setting_id = 1')->fetchColumn();
    return $value === false ? 5 : max(1, (int) $value);
}

function isInventoryItemLow(int $stock, int $threshold): bool
{
    return $stock <= $threshold;
}

/** Returns created, updated, resolved, none, missing, or null on no such edition. */
function syncEditionLowStockAlert(PDO $pdo, int $editionId, ?int $thresholdOverride = null): ?string
{
    if ($editionId < 1 || !lowStockAlertsReady($pdo)) {
        return 'missing';
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT e.edition_id, e.stock_quantity, b.title, e.format
               FROM book_editions e JOIN books b ON b.book_id = e.book_id
              WHERE e.edition_id = ? FOR UPDATE'
        );
        $stmt->execute([$editionId]);
        $edition = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$edition) {
            if ($ownsTransaction) $pdo->commit();
            return null;
        }

        $threshold = $thresholdOverride ?? getLowStockThreshold($pdo);
        $stock = (int) $edition['stock_quantity'];
        $activeStmt = $pdo->prepare('SELECT alert_id FROM low_stock_alerts WHERE edition_id = ? AND status = \'active\' LIMIT 1 FOR UPDATE');
        $activeStmt->execute([$editionId]);
        $activeId = $activeStmt->fetchColumn();

        if (isInventoryItemLow($stock, $threshold)) {
            if ($activeId !== false) {
                $pdo->prepare('UPDATE low_stock_alerts SET current_stock = ?, threshold_at_detection = ?, book_title = ?, edition_format = ?, active_edition_id = ? WHERE alert_id = ?')
                    ->execute([$stock, $threshold, $edition['title'], $edition['format'], $editionId, (int) $activeId]);
                $result = 'updated';
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO low_stock_alerts (edition_id, active_edition_id, book_title, edition_format, current_stock, threshold_at_detection, status)
                         VALUES (?, ?, ?, ?, ?, ?, \'active\')'
                    )->execute([$editionId, $editionId, $edition['title'], $edition['format'], $stock, $threshold]);
                    $result = 'created';
                } catch (PDOException $e) {
                    if ((string) $e->getCode() !== '23000') throw $e;
                    $reload = $pdo->prepare('SELECT alert_id FROM low_stock_alerts WHERE edition_id = ? AND status = \'active\' LIMIT 1 FOR UPDATE');
                    $reload->execute([$editionId]);
                    $existingId = $reload->fetchColumn();
                    if ($existingId === false) throw $e;
                    $pdo->prepare('UPDATE low_stock_alerts SET current_stock = ?, threshold_at_detection = ?, book_title = ?, edition_format = ?, active_edition_id = ? WHERE alert_id = ?')
                        ->execute([$stock, $threshold, $edition['title'], $edition['format'], $editionId, (int) $existingId]);
                    $result = 'updated';
                }
            }
        } elseif ($activeId !== false) {
            $pdo->prepare(
                'UPDATE low_stock_alerts SET current_stock = ?, active_edition_id = NULL, status = \'resolved\', resolved_at = CURRENT_TIMESTAMP WHERE alert_id = ?'
            )->execute([$stock, (int) $activeId]);
            $result = 'resolved';
        } else {
            $result = 'none';
        }

        if ($ownsTransaction) $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Reconcile all editions after migration or a global threshold update. */
function reevaluateAllLowStockAlerts(PDO $pdo): array
{
    $summary = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'none' => 0];
    if (!lowStockAlertsReady($pdo)) {
        throw new RuntimeException('Apply database/migrations/005_low_stock_alerts.sql before using low-stock alerts.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $threshold = getLowStockThreshold($pdo);
        $editionIds = $pdo->query('SELECT edition_id FROM book_editions ORDER BY edition_id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($editionIds as $editionId) {
            $result = syncEditionLowStockAlert($pdo, (int) $editionId, $threshold);
            if (isset($summary[$result])) $summary[$result]++;
        }
        if ($ownsTransaction) $pdo->commit();
        return $summary;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
