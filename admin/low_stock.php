<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

requireRole(['admin', 'staff']);
$isAdmin = $_SESSION['role'] === 'admin';
$errors = [];
$success = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);
$ready = lowStockAlertsReady($pdo);
$threshold = $ready ? getLowStockThreshold($pdo) : 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');
    if ($action === 'demo_restock') {
        $editionId = filter_var(requestString($_POST, 'edition_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var(requestString($_POST, 'quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($editionId === false || $quantity === false) {
            $errors[] = 'Choose a valid edition and a restock quantity from 1 to 5,000.';
        } elseif (!$ready) {
            $errors[] = 'Apply the low-stock migration before restocking from alerts.';
        } else {
            try {
                $pdo->beginTransaction();
                $stockStmt = $pdo->prepare('SELECT stock_quantity FROM book_editions WHERE edition_id = ? FOR UPDATE');
                $stockStmt->execute([(int) $editionId]);
                $stock = $stockStmt->fetchColumn();
                if ($stock === false) throw new RuntimeException('That edition could not be found.');
                if ((int) $stock + (int) $quantity > 4294967295) throw new RuntimeException('The new stock total is above the database limit.');
                $pdo->prepare('UPDATE book_editions SET stock_quantity = stock_quantity + ? WHERE edition_id = ?')
                    ->execute([(int) $quantity, (int) $editionId]);
                syncEditionLowStockAlert($pdo, (int) $editionId);
                auditLogRequired(
                    $pdo,
                    'update',
                    'Demo restock added ' . (int) $quantity . ' copies to book edition #' . (int) $editionId . '.',
                    'products',
                    (int) $_SESSION['user_id'],
                    'book_edition',
                    (int) $editionId,
                    'success',
                    (string) $_SESSION['role']
                );
                $pdo->commit();
                $_SESSION['admin_flash'] = 'Restocked ' . (int) $quantity . ' copies. Inventory and its low-stock alert are updated.';
                $status = requestString($_POST, 'status');
                if (!in_array($status, ['active', 'resolved', 'all'], true)) $status = 'active';
                header('Location: /admin/low_stock.php?status=' . rawurlencode($status));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Demo low-stock restock failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to restock this edition. Please try again.';
            }
        }
    } elseif ($action === 'demo_restock_all') {
        $quantity = filter_var(requestString($_POST, 'quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($quantity === false) {
            $errors[] = 'Choose a restock quantity per edition from 1 to 5,000.';
        } elseif (!$ready) {
            $errors[] = 'Apply the low-stock migration before restocking alerts.';
        } else {
            try {
                $pdo->beginTransaction();
                $editionIds = $pdo->query("SELECT edition_id FROM low_stock_alerts WHERE status = 'active' ORDER BY edition_id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
                if (!$editionIds) throw new RuntimeException('There are no active alerts to restock.');
                $stockStmt = $pdo->prepare('SELECT stock_quantity FROM book_editions WHERE edition_id = ? FOR UPDATE');
                $updateStmt = $pdo->prepare('UPDATE book_editions SET stock_quantity = stock_quantity + ? WHERE edition_id = ?');
                $restocked = 0;
                foreach ($editionIds as $editionId) {
                    $editionId = (int) $editionId;
                    $stockStmt->execute([$editionId]);
                    $stock = $stockStmt->fetchColumn();
                    if ($stock === false) continue;
                    if ((int) $stock + (int) $quantity > 4294967295) {
                        throw new RuntimeException('Edition #' . $editionId . ' would exceed the database stock limit. No editions were changed.');
                    }
                    $updateStmt->execute([(int) $quantity, $editionId]);
                    syncEditionLowStockAlert($pdo, $editionId);
                    $restocked++;
                }
                auditLogRequired(
                    $pdo,
                    'update',
                    'Demo restock added ' . (int) $quantity . ' copies to each of ' . $restocked . ' active low-stock editions.',
                    'products',
                    (int) $_SESSION['user_id'],
                    'inventory_alerts',
                    null,
                    'success',
                    (string) $_SESSION['role']
                );
                $pdo->commit();
                $_SESSION['admin_flash'] = 'Restocked ' . $restocked . ' edition(s) by ' . (int) $quantity . ' copies each. Inventory alerts were refreshed.';
                header('Location: /admin/low_stock.php?status=active');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Demo restock-all failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to restock all active alerts. Please try again.';
            }
        }
    } elseif ($action === 'save_threshold') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Only administrators can change the low-stock threshold.');
        }
        $rawThreshold = trim(requestString($_POST, 'low_stock_threshold'));
        if (!preg_match('/^[1-9][0-9]{0,6}$/', $rawThreshold)) {
            $errors[] = 'Enter a whole-number threshold from 1 to 9,999,999.';
        } elseif (!$ready) {
            $errors[] = 'Apply database/migrations/005_low_stock_alerts.sql before configuring alerts.';
        } else {
        try {
            $newThreshold = (int) $rawThreshold;
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE inventory_settings SET low_stock_threshold = ?, updated_by = ? WHERE setting_id = 1')
                ->execute([$newThreshold, (int) $_SESSION['user_id']]);
            $pdo->prepare('UPDATE book_editions SET low_stock_threshold = ?')->execute([$newThreshold]);
            $summary = reevaluateAllLowStockAlerts($pdo);
            auditLogRequired(
                $pdo,
                'update',
                'Administrator set the low-stock threshold to ' . $newThreshold . ' units and reevaluated inventory.',
                'products',
                (int) $_SESSION['user_id'],
                'inventory_settings',
                1,
                'success',
                'admin'
            );
            $pdo->commit();
            $_SESSION['admin_flash'] = 'Threshold saved. Inventory checked: ' . $summary['created'] . ' alert(s) opened and ' . $summary['resolved'] . ' resolved.';
            header('Location: /admin/low_stock.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Low-stock threshold update failed: ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save the threshold. Please try again.';
        }
        }
    } else {
        $errors[] = 'Choose a valid inventory action.';
    }
}

$statusFilter = (string) ($_GET['status'] ?? 'active');
if (!in_array($statusFilter, ['active', 'resolved', 'all'], true)) $statusFilter = 'active';
$alerts = [];
$activeCount = 0;
if ($ready) {
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM low_stock_alerts WHERE status = 'active'")->fetchColumn();
    $where = $statusFilter === 'all' ? '' : 'WHERE status = ?';
    $stmt = $pdo->prepare(
        'SELECT alert_id, edition_id, book_title, edition_format, current_stock,
                threshold_at_detection, status, created_at, resolved_at
           FROM low_stock_alerts ' . $where . '
          ORDER BY (status = \'active\') DESC, created_at DESC LIMIT 200'
    );
    $stmt->execute($statusFilter === 'all' ? [] : [$statusFilter]);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
include __DIR__ . '/../includes/header.php';
?>
<main class="container admin-operations admin-low-stock-page">
    <header class="admin-page-hero">
        <div><span class="admin-hero-eyebrow">INVENTORY CONTROL</span><h1>Low-Stock Alerts</h1><p>Monitor editions that need replenishment. Alerts open at the configured threshold and resolve after stock rises above it.</p></div>
        <div class="hero-meta"><a class="btn btn-secondary" href="/admin/products.php">Manage Inventory</a></div>
    </header>
    <?php if ($errors): ?><div class="operations-alert error" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <?php if (!$ready): ?><div class="operations-alert error" role="alert"><p>Low-stock alert tables are not installed yet. Apply <code>database/migrations/005_low_stock_alerts.sql</code> to this database.</p></div><?php endif; ?>

    <section class="low-stock-overview-panel" aria-label="Low-stock overview and settings">
        <div class="low-stock-overview-copy"><span class="operations-eyebrow">CURRENT INVENTORY PULSE</span><h2><?= number_format($activeCount) ?> Active Alert<?= $activeCount === 1 ? '' : 's' ?></h2><p><?= $ready ? 'Editions at or below the shared restock threshold appear in the alert list.' : 'Apply the low-stock migration to enable monitoring.' ?></p><div class="low-stock-threshold-pill">Current Threshold <strong><?= number_format($threshold) ?> Units</strong></div></div>
        <div class="low-stock-settings">
            <div><span class="operations-eyebrow">ALERT SETTINGS</span><h2>Shared Stock Threshold</h2><p><?php if ($isAdmin): ?>Changing this value updates every edition and reevaluates open alerts.<?php else: ?>Only an administrator can change the shared threshold.<?php endif; ?></p></div>
            <?php if ($isAdmin): ?>
                <form method="post" action="/admin/low_stock.php" class="low-stock-threshold-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="save_threshold">
                    <label for="low-stock-threshold">Alert When Stock Is at or Below</label>
                    <div class="low-stock-threshold-controls"><input class="form-input" type="number" id="low-stock-threshold" name="low_stock_threshold" min="1" max="9999999" step="1" required value="<?= (int) $threshold ?>" <?= !$ready ? 'disabled' : '' ?>><span>Units</span><button class="btn btn-primary" type="submit" <?= !$ready ? 'disabled' : '' ?>>Save Threshold</button></div>
                </form>
            <?php else: ?><p class="low-stock-admin-note">The administrator manages this setting.</p><?php endif; ?>
        </div>
    </section>

    <section class="low-stock-alerts-panel" aria-labelledby="inventory-alerts-title">
        <div class="low-stock-alerts-heading">
            <div><span class="operations-eyebrow">RESTOCK QUEUE</span><h2 id="inventory-alerts-title">Inventory Alerts</h2><p>Review current and resolved stock alerts, then open an edition to update its inventory.</p></div>
            <div class="low-stock-queue-actions">
                <?php if ($statusFilter === 'active' && $activeCount > 0): ?><form method="post" action="/admin/low_stock.php" class="low-stock-restock-all-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="demo_restock_all"><label for="restock-all-quantity">Copies per edition</label><input class="form-input" type="number" id="restock-all-quantity" name="quantity" min="1" max="5000" value="10" required><button class="btn btn-primary" type="submit" <?= !$ready ? 'disabled' : '' ?>>Restock All <span>(<?= number_format($activeCount) ?>)</span></button></form><?php endif; ?>
                <nav class="low-stock-filter-tabs" aria-label="Filter alert status">
                    <a class="btn <?= $statusFilter === 'active' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/low_stock.php?status=active">Active</a>
                    <a class="btn <?= $statusFilter === 'resolved' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/low_stock.php?status=resolved">Resolved</a>
                    <a class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/low_stock.php?status=all">All</a>
                </nav>
            </div>
        </div>
        <?php if (!$ready): ?>
            <p class="card-text">Alerts will appear here once the migration is applied.</p>
        <?php elseif (!$alerts): ?>
            <div class="operations-empty"><h2>No <?= htmlspecialchars(ucfirst($statusFilter), ENT_QUOTES, 'UTF-8') ?> Alerts</h2><p>There are no <?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?> low-stock alerts to show.</p></div>
        <?php else: ?>
            <div class="low-stock-table-wrap"><table class="table low-stock-table">
                <thead><tr><th>Book / edition</th><th>Stock</th><th>Threshold</th><th>Detected</th><th>Status</th><th>Resolved</th><th>Manage</th></tr></thead>
                <tbody><?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $alert['book_title'], ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars(ucfirst((string) $alert['edition_format']), ENT_QUOTES, 'UTF-8') ?> · Edition #<?= (int) $alert['edition_id'] ?></small></td>
                        <td><strong><?= number_format((int) $alert['current_stock']) ?></strong> units</td>
                        <td><?= number_format((int) $alert['threshold_at_detection']) ?> units</td>
                        <td><?= htmlspecialchars((string) $alert['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) $alert['status']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($alert['resolved_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="low-stock-row-actions">
                            <?php if ($alert['status'] === 'active'): ?>
                                <form method="post" action="/admin/low_stock.php" class="low-stock-restock-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="demo_restock"><input type="hidden" name="edition_id" value="<?= (int) $alert['edition_id'] ?>"><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <label class="sr-only" for="restock-quantity-<?= (int) $alert['edition_id'] ?>">Copies to add for <?= htmlspecialchars((string) $alert['book_title'], ENT_QUOTES, 'UTF-8') ?></label><input class="form-input" type="number" id="restock-quantity-<?= (int) $alert['edition_id'] ?>" name="quantity" min="1" max="5000" value="10" required>
                                    <button class="btn btn-primary" type="submit">Restock</button>
                                </form>
                            <?php else: ?><a class="btn btn-secondary" href="/admin/products.php?edit=<?= (int) $alert['edition_id'] ?>">Manage Stock</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
