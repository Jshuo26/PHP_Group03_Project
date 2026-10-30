<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/payment_schema.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

requireRole(['admin', 'staff']);
$role = (string) $_SESSION['role'];
$isAdmin = $role === 'admin';
$counts = [
    'customers' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn(),
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(),
    'inventory' => (int) $pdo->query('SELECT COALESCE(SUM(stock_quantity), 0) FROM book_editions')->fetchColumn(),
    'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
    'cancelled' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn(),
];
$counts['processing'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('processing','shipped')")->fetchColumn();
$counts['completed'] = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'")->fetchColumn();
$paidSales = null;
if (paymentSchemaHasColumn($pdo, 'orders', 'payment_status')) {
    $paidSales = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();
}
$lowStockReady = lowStockAlertsReady($pdo);
$activeLowStockCount = 0;
$activeLowStockItems = [];
if ($lowStockReady) {
    $activeLowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM low_stock_alerts WHERE status = 'active'")->fetchColumn();
    $activeLowStockItems = $pdo->query(
        "SELECT edition_id, book_title, edition_format, current_stock, threshold_at_detection
           FROM low_stock_alerts WHERE status = 'active' ORDER BY current_stock ASC, created_at ASC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
}
$recentActivity = [];
if (!auditSchemaMissingColumns($pdo)) {
    $recentActivity = $pdo->query(
        'SELECT actor_name, actor_role, action, module, description, created_at
           FROM audit_logs ORDER BY created_at DESC, log_id DESC LIMIT 8'
    )->fetchAll();
}
include __DIR__ . '/../includes/header.php';
?>
<section class="container <?= $isAdmin ? 'admin-dashboard' : 'staff-dashboard' ?>">
    <?php if ($isAdmin): ?>
        <header class="admin-dashboard-hero">
            <div>
                <p class="admin-eyebrow">Administrator dashboard</p>
                <h1>Good day, <?= htmlspecialchars((string) $_SESSION['full_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p>Your bookstore at a glance. Review what needs attention and jump straight into daily operations.</p>
            </div>
            <div class="admin-hero-actions">
                <a class="admin-hero-action" href="/admin/audit_logs.php">Review activity <span aria-hidden="true">→</span></a>
                <span class="admin-secure-badge"><span aria-hidden="true">●</span> Secure admin workspace</span>
            </div>
        </header>

        <section class="admin-overview" aria-labelledby="admin-overview-title">
            <div class="admin-section-heading">
                <div><p class="admin-eyebrow">At a Glance</p><h2 id="admin-overview-title">Store Overview</h2></div>
                <span class="admin-role-pill"><span aria-hidden="true">●</span> Administrator</span>
            </div>
            <div class="admin-metric-grid">
                <article class="admin-metric admin-metric-orders"><span>Total orders</span><strong><?= number_format($counts['orders']) ?></strong><small>All customer orders</small></article>
                <article class="admin-metric admin-metric-attention"><span>Needs attention</span><strong><?= number_format($counts['pending']) ?></strong><small>Pending orders</small></article>
                <article class="admin-metric admin-metric-progress"><span>In progress</span><strong><?= number_format($counts['processing']) ?></strong><small>Processing or shipped</small></article>
                <article class="admin-metric admin-metric-books"><span>Books in catalog</span><strong><?= number_format($counts['products']) ?></strong><small><?= number_format($counts['inventory']) ?> total stock units</small></article>
                <article class="admin-metric admin-metric-customers"><span>Customers</span><strong><?= number_format($counts['customers']) ?></strong><small>Registered accounts</small></article>
                <?php if ($paidSales !== null): ?><article class="admin-metric admin-metric-sales"><span>Confirmed sales</span><strong>₱<?= number_format($paidSales, 2) ?></strong><small>Payments marked paid</small></article><?php endif; ?>
            </div>
        </section>

        <div class="admin-dashboard-workspace">
        <section class="admin-management" aria-labelledby="admin-management-title">
            <div class="admin-section-heading">
                <div><p class="admin-eyebrow">Daily Operations</p><h2 id="admin-management-title">Manage the Bookstore</h2></div>
                <p>Choose a workspace to continue</p>
            </div>
            <div class="admin-tool-grid">
                <a class="admin-tool-card" href="/admin/products.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5v16M8 7h8M8 10h8"/></svg></span><span><strong>Books &amp; Inventory</strong><small>Catalog, pricing, stock, and availability</small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/orders.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h10A2.25 2.25 0 0 1 19.25 6v15H4.75V6A2.25 2.25 0 0 1 7 3.75Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 8h8M8 12h8M8 16h5"/></svg></span><span><strong>Orders</strong><small>Payments and fulfillment</small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/featured.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 2.7 5.47 6.03.88-4.36 4.25 1.03 6.01L12 16.77l-5.4 2.84 1.03-6.01-4.36-4.25 6.03-.88L12 3Z"/></svg></span><span><strong>Featured Books</strong><small>Curate the customer Staff Picks shelf</small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/authors.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="9" cy="8" r="3.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 20a5.25 5.25 0 0 1 10.5 0M16 4.75a3.25 3.25 0 0 1 0 6.5m1.25 3.5A4.5 4.5 0 0 1 21 19"/></svg></span><span><strong>Authors</strong><small>Edit profiles and manage eligibility</small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/low_stock.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5a6 6 0 0 0-6 6v3.2c0 1.2-.4 2.4-1.2 3.3l-1 1.2h16.4l-1-1.2a5.1 5.1 0 0 1-1.2-3.3V9.5a6 6 0 0 0-6-6Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 20a2.7 2.7 0 0 0 5 0"/></svg></span><span><strong>Inventory Alerts</strong><small><?= $lowStockReady ? number_format($activeLowStockCount) . ' active low-stock alert(s)' : 'Low-stock monitoring setup' ?></small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/customers.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="9" cy="8" r="3.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 20a5.25 5.25 0 0 1 10.5 0M16 5.5a2.75 2.75 0 0 1 0 5.5m1 3.5a4.6 4.6 0 0 1 3.25 4.4"/></svg></span><span><strong>Customers</strong><small>Accounts and order history</small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/genres.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="4" width="6.5" height="6.5" rx="1.3"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.3"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.3"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.3"/></svg></span><span><strong>Categories</strong><small>Organize the book catalog</small></span><b aria-hidden="true">→</b></a>
                <a class="admin-tool-card" href="/admin/users.php"><span class="admin-tool-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="9" cy="8" r="3.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 20a5.25 5.25 0 0 1 10.5 0M16 5.5a2.75 2.75 0 0 1 0 5.5m1 3.5a4.6 4.6 0 0 1 3.25 4.4"/></svg></span><span><strong>Staff &amp; Roles</strong><small>Manage staff accounts and access</small></span><b aria-hidden="true">→</b></a>
            </div>
        </section>
        <?php include __DIR__ . '/../includes/low_stock_widget.php'; ?>
        </div>

        <section class="admin-activity-panel" aria-labelledby="admin-activity-title">
            <div class="admin-section-heading">
                <div><p class="admin-eyebrow">Audit Trail</p><h2 id="admin-activity-title">Recent Activity</h2></div>
                <a href="/admin/audit_logs.php">Open Full Audit Trail <span aria-hidden="true">→</span></a>
            </div>
            <?php if (!$recentActivity): ?>
                <p class="admin-empty-state">No recent activity is available yet.</p>
            <?php else: ?>
                <div class="admin-activity-scroll"><table class="admin-activity-table">
                    <thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Area</th><th>Details</th></tr></thead>
                    <tbody><?php foreach ($recentActivity as $activity): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($activity['actor_name'] ?: 'System / unknown'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(ucfirst((string) ($activity['actor_role'] ?: 'unknown')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="admin-action-tag"><?= htmlspecialchars(str_replace('_', ' ', (string) $activity['action']), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars(ucfirst((string) ($activity['module'] ?: 'unknown')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $activity['description'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php include __DIR__ . '/../includes/low_stock_widget.php'; ?>
        <div class="card">
            <h1 class="card-title">Staff Dashboard</h1>
            <p class="card-text">Welcome, <?= htmlspecialchars((string) $_SESSION['full_name'], ENT_QUOTES, 'UTF-8') ?>. Your role: <strong>Staff</strong>.</p>
            <div class="admin-shortcuts" aria-label="Bookstore operations">
                <a class="btn btn-primary" href="/admin/products.php">Manage Books</a>
                <a class="btn btn-secondary" href="/admin/featured.php">Manage Featured Books</a>
                <a class="btn btn-secondary" href="/admin/genres.php">Manage categories</a>
                <a class="btn btn-secondary" href="/admin/authors.php">Manage authors</a>
                <a class="btn btn-secondary" href="/admin/customers.php">Customers</a>
                <a class="btn btn-secondary" href="/admin/orders.php">Manage orders</a>
                <a class="btn btn-secondary" href="/admin/low_stock.php">Low-stock alerts (<?= number_format($activeLowStockCount) ?>)</a>
            </div>
        </div>
        <div class="admin-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin:20px 0;">
            <?php foreach ([
                'Orders' => $counts['orders'], 'Pending orders' => $counts['pending'],
                'Processing / shipped' => $counts['processing'], 'Delivered' => $counts['completed'],
                'Cancelled' => $counts['cancelled'], 'Books' => $counts['products'],
                'Inventory units' => $counts['inventory'], 'Customers' => $counts['customers'],
            ] as $label => $value): ?>
                <div class="card"><p class="card-text"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p><strong style="font-size:1.7rem;"><?= number_format((int) $value) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h2 class="card-title">Recent Bookstore Activity</h2>
            <?php if (!$recentActivity): ?><p class="card-text">No recent activity is available.</p>
            <?php else: ?><div style="overflow-x:auto;"><table class="table"><thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Activity</th></tr></thead><tbody>
                <?php foreach ($recentActivity as $activity): ?><tr><td><?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($activity['actor_name'] ?: 'System / unknown'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ucfirst((string) ($activity['actor_role'] ?: 'unknown')), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(str_replace('_', ' ', (string) $activity['action']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ucfirst((string) ($activity['module'] ?: 'unknown')), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $activity['description'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
