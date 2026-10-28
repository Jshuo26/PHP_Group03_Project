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
<section class="container">
    <div class="card">
        <p class="card-text"><a href="/admin/index.php">Dashboard</a> / Inventory</p>
        <h1 class="card-title">Low-stock alerts</h1>
        <p class="card-text">Alerts are created when edition stock reaches the configured threshold and resolve automatically after restocking above it.</p>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <?php if (!$ready): ?>
            <div class="form-errors" role="alert"><p>Low-stock alert tables are not installed yet. Apply <code>database/migrations/005_low_stock_alerts.sql</code> to this database.</p></div>
        <?php else: ?>
            <p><strong><?= number_format($activeCount) ?></strong> active alert<?= $activeCount === 1 ? '' : 's' ?> · Current threshold: <strong><?= number_format($threshold) ?></strong> units</p>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
        <div class="card" style="margin-top:16px;">
            <h2 class="card-title">Alert settings</h2>
            <p class="card-text">Use one threshold for all book editions. Changing it immediately reevaluates inventory and updates active alerts.</p>
            <form method="post" action="/admin/low_stock.php" class="inline-form" style="margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <label for="low-stock-threshold">Alert when stock is at or below</label>
                <input class="form-input" type="number" id="low-stock-threshold" name="low_stock_threshold" min="1" max="9999999" step="1" required value="<?= (int) $threshold ?>" <?= !$ready ? 'disabled' : '' ?>>
                <span>units</span>
                <button class="btn btn-primary" type="submit" <?= !$ready ? 'disabled' : '' ?>>Save threshold</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card" style="margin-top:16px;"><h2 class="card-title">Alert settings</h2><p class="card-text">The administrator controls the global stock threshold. Current threshold: <strong><?= number_format($threshold) ?></strong> units.</p></div>
    <?php endif; ?>

    <div class="card" style="margin-top:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <h2 class="card-title">Inventory alerts</h2>
            <nav class="inline-form" aria-label="Alert status filter">
                <a class="btn <?= $statusFilter === 'active' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/low_stock.php?status=active">Active</a>
                <a class="btn <?= $statusFilter === 'resolved' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/low_stock.php?status=resolved">Resolved</a>
                <a class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/low_stock.php?status=all">All</a>
            </nav>
        </div>
        <?php if (!$ready): ?>
            <p class="card-text">Alerts will appear here once the migration is applied.</p>
        <?php elseif (!$alerts): ?>
            <p class="card-text">No <?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?> low-stock alerts.</p>
        <?php else: ?>
            <div style="overflow-x:auto;"><table class="table">
                <thead><tr><th>Book / edition</th><th>Stock</th><th>Threshold</th><th>Detected</th><th>Status</th><th>Resolved</th><th>Manage</th></tr></thead>
                <tbody><?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $alert['book_title'], ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars(ucfirst((string) $alert['edition_format']), ENT_QUOTES, 'UTF-8') ?> · Edition #<?= (int) $alert['edition_id'] ?></small></td>
                        <td><strong><?= number_format((int) $alert['current_stock']) ?></strong> units</td>
                        <td><?= number_format((int) $alert['threshold_at_detection']) ?> units</td>
                        <td><?= htmlspecialchars((string) $alert['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) $alert['status']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($alert['resolved_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><a class="btn btn-secondary" href="/admin/products.php?edit=<?= (int) $alert['edition_id'] ?>">Manage stock</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
