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
$selectedRestockIds = [];
$restockQuantities = [];

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
    } elseif ($action === 'export_selected_restock_csv' || $action === 'demo_restock_selected') {
        $rawEditionIds = $_POST['edition_ids'] ?? [];
        $rawQuantities = $_POST['quantities'] ?? [];
        $rawEditionIds = is_array($rawEditionIds) ? $rawEditionIds : [];
        $rawQuantities = is_array($rawQuantities) ? $rawQuantities : [];
        foreach ($rawEditionIds as $rawId) {
            $editionId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($editionId !== false && !in_array((int) $editionId, $selectedRestockIds, true)) $selectedRestockIds[] = (int) $editionId;
        }
        foreach ($rawQuantities as $rawId => $rawQuantity) {
            $editionId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
            if ($editionId !== false && $quantity !== false) $restockQuantities[(int) $editionId] = (int) $quantity;
        }
        if (!$selectedRestockIds) {
            $errors[] = 'Select at least one active edition to restock.';
        } elseif (count($selectedRestockIds) > 200) {
            $errors[] = 'Select no more than 200 editions at a time.';
        } elseif (!$ready) {
            $errors[] = 'Apply the low-stock migration before restocking alerts.';
        } elseif ($action === 'export_selected_restock_csv') {
            try {
                $placeholders = implode(',', array_fill(0, count($selectedRestockIds), '?'));
                $stmt = $pdo->prepare("SELECT e.edition_id, b.title,
                        COALESCE(NULLIF(e.isbn, ''), NULLIF(b.isbn, ''), '') AS isbn,
                        e.format, b.publisher, b.description, b.language,
                        GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR '; ') AS authors,
                        GROUP_CONCAT(DISTINCT NULLIF(a.bio, '') ORDER BY a.bio SEPARATOR '; ') AS author_description
                    FROM low_stock_alerts la
                    JOIN book_editions e ON e.edition_id = la.edition_id
                    JOIN books b ON b.book_id = e.book_id
                    LEFT JOIN book_authors ba ON ba.book_id = b.book_id
                    LEFT JOIN authors a ON a.author_id = ba.author_id
                    WHERE la.status = 'active' AND e.edition_id IN ($placeholders)
                    GROUP BY e.edition_id, b.title, b.isbn, e.isbn, e.format, b.publisher, b.description, b.language
                    ORDER BY b.title, e.format");
                $stmt->execute($selectedRestockIds);
                $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($books) !== count($selectedRestockIds)) {
                    throw new RuntimeException('One or more selected alerts are no longer active. Refresh the list and try again.');
                }
                foreach ($selectedRestockIds as $editionId) {
                    if (!isset($restockQuantities[$editionId])) throw new RuntimeException('Enter a valid “Copies to Add” amount for every selected edition.');
                }
                auditLog(
                    $pdo,
                    'create',
                    'Generated a low-stock purchase CSV for ' . count($books) . ' selected edition(s). Inventory was not changed.',
                    'products',
                    (int) $_SESSION['user_id'],
                    'restock_order',
                    null,
                    'success',
                    (string) $_SESSION['role']
                );
                $output = fopen('php://temp', 'w+b');
                if ($output === false) throw new RuntimeException('The CSV export could not be prepared.');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['title', 'author', 'author_description', 'isbn', 'format', 'quantity', 'cost', 'price', 'publisher', 'description', 'language']);
                $safeCsvValue = static function ($value): string {
                    $value = (string) $value;
                    return preg_match('/^\s*[=+@-]/u', $value) ? "'" . $value : $value;
                };
                foreach ($books as $book) {
                    $editionId = (int) $book['edition_id'];
                    fputcsv($output, [
                        $safeCsvValue($book['title']),
                        $safeCsvValue($book['authors'] ?: 'Author unknown'),
                        $safeCsvValue($book['author_description'] ?? ''),
                        $safeCsvValue($book['isbn'] ?? ''),
                        $safeCsvValue($book['format']),
                        $restockQuantities[$editionId],
                        '',
                        '',
                        $safeCsvValue($book['publisher'] ?? ''),
                        $safeCsvValue($book['description'] ?? ''),
                        $safeCsvValue($book['language'] ?? ''),
                    ]);
                }
                rewind($output);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="aklaaat-low-stock-order-' . date('Y-m-d-His') . '.csv"');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                fpassthru($output);
                fclose($output);
                exit;
            } catch (Throwable $e) {
                error_log('Low-stock CSV export failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to generate the selected restock CSV. Please try again.';
            }
        } else {
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($selectedRestockIds), '?'));
                $alertStmt = $pdo->prepare("SELECT edition_id FROM low_stock_alerts WHERE status = 'active' AND edition_id IN ($placeholders) ORDER BY edition_id FOR UPDATE");
                $alertStmt->execute($selectedRestockIds);
                $editionIds = array_map('intval', $alertStmt->fetchAll(PDO::FETCH_COLUMN));
                if (count($editionIds) !== count($selectedRestockIds)) {
                    throw new RuntimeException('One or more selected alerts are no longer active. Refresh the list and try again.');
                }
                $stockStmt = $pdo->prepare('SELECT stock_quantity FROM book_editions WHERE edition_id = ? FOR UPDATE');
                $updateStmt = $pdo->prepare('UPDATE book_editions SET stock_quantity = stock_quantity + ? WHERE edition_id = ?');
                $restocked = 0;
                foreach ($editionIds as $editionId) {
                    if (!isset($restockQuantities[$editionId])) throw new RuntimeException('Enter a valid “Copies to Add” amount for every selected edition.');
                    $quantity = $restockQuantities[$editionId];
                    $stockStmt->execute([$editionId]);
                    $stock = $stockStmt->fetchColumn();
                    if ($stock === false) throw new RuntimeException('A selected edition could not be found. No editions were changed.');
                    if ((int) $stock + $quantity > 4294967295) {
                        throw new RuntimeException('Edition #' . $editionId . ' would exceed the database stock limit. No editions were changed.');
                    }
                    $updateStmt->execute([$quantity, $editionId]);
                    syncEditionLowStockAlert($pdo, $editionId);
                    $restocked++;
                }
                auditLogRequired(
                    $pdo,
                    'update',
                    'Demo restock added copies to ' . $restocked . ' selected active low-stock edition(s).',
                    'products',
                    (int) $_SESSION['user_id'],
                    'inventory_alerts',
                    null,
                    'success',
                    (string) $_SESSION['role']
                );
                $pdo->commit();
                $_SESSION['admin_flash'] = 'Restocked ' . $restocked . ' selected edition(s). Inventory alerts were refreshed.';
                header('Location: /admin/low_stock.php?status=active');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Demo selected restock failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to restock the selected editions. Please try again.';
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
            <?php if ($statusFilter === 'active' || $statusFilter === 'all'): ?><form method="post" action="/admin/low_stock.php" class="low-stock-selection-form" id="low-stock-selection-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="low-stock-selection-toolbar"><label class="low-stock-select-all"><input type="checkbox" id="low-stock-select-all" <?= $activeCount < 1 ? 'disabled' : '' ?>> <span>Select All Listed</span></label><p>Select active editions, set copies to add, then restock or export an order list.</p><div class="low-stock-selection-actions"><button class="btn btn-secondary" type="submit" name="action" value="export_selected_restock_csv" <?= !$ready || $activeCount < 1 ? 'disabled' : '' ?>>Generate CSV</button><button class="btn btn-primary" type="submit" name="action" value="demo_restock_selected" <?= !$ready || $activeCount < 1 ? 'disabled' : '' ?>>Restock Selected <span aria-hidden="true">→</span></button></div></div>
            <?php endif; ?>
            <div class="low-stock-table-wrap"><table class="table low-stock-table <?= ($statusFilter === 'active' || $statusFilter === 'all') ? 'low-stock-selectable-table' : '' ?>">
                <thead><tr><?php if ($statusFilter === 'active' || $statusFilter === 'all'): ?><th scope="col">Select</th><?php endif; ?><th>Book / edition</th><th>Stock</th><th>Threshold</th><th>Detected</th><th>Status</th><th>Resolved</th><th><?= $statusFilter === 'resolved' ? 'Manage' : ($statusFilter === 'all' ? 'Copies to Add / Manage' : 'Copies to Add') ?></th></tr></thead>
                <tbody><?php foreach ($alerts as $alert): ?>
                    <tr>
                        <?php if ($statusFilter === 'active' || $statusFilter === 'all'): ?><td><?php if ($alert['status'] === 'active'): ?><input type="checkbox" name="edition_ids[]" value="<?= (int) $alert['edition_id'] ?>" class="low-stock-select-edition" aria-label="Select <?= htmlspecialchars((string) $alert['book_title'], ENT_QUOTES, 'UTF-8') ?> for restock"><?php else: ?><span aria-label="Resolved alert">—</span><?php endif; ?></td><?php endif; ?>
                        <td><?= htmlspecialchars((string) $alert['book_title'], ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars(ucfirst((string) $alert['edition_format']), ENT_QUOTES, 'UTF-8') ?> · Edition #<?= (int) $alert['edition_id'] ?></small></td>
                        <td><strong><?= number_format((int) $alert['current_stock']) ?></strong> units</td>
                        <td><?= number_format((int) $alert['threshold_at_detection']) ?> units</td>
                        <td><?= htmlspecialchars((string) $alert['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) $alert['status']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($alert['resolved_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="low-stock-row-actions">
                            <?php if ($alert['status'] === 'active'): ?>
                                <label class="sr-only" for="restock-quantity-<?= (int) $alert['edition_id'] ?>">Copies to Add</label><input class="form-input low-stock-quantity-input" type="number" id="restock-quantity-<?= (int) $alert['edition_id'] ?>" name="quantities[<?= (int) $alert['edition_id'] ?>]" min="1" max="5000" value="<?= (int) ($restockQuantities[(int) $alert['edition_id']] ?? 10) ?>" required form="low-stock-selection-form">
                            <?php else: ?><a class="btn btn-secondary" href="/admin/products.php?edit=<?= (int) $alert['edition_id'] ?>">Manage Stock</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if ($statusFilter === 'active' || $statusFilter === 'all'): ?></form><?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('low-stock-select-all');
    if (!selectAll) return;
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.low-stock-select-edition'));
    selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
    });
    boxes.forEach(function (box) {
        box.addEventListener('change', function () {
            selectAll.checked = boxes.length > 0 && boxes.every(function (item) { return item.checked; });
            selectAll.indeterminate = boxes.some(function (item) { return item.checked; }) && !selectAll.checked;
        });
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
