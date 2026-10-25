<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/payment_schema.php';

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
$recentActivity = [];
if (!auditSchemaMissingColumns($pdo)) {
    $recentActivity = $pdo->query(
        'SELECT actor_name, actor_role, action, module, description, created_at
           FROM audit_logs ORDER BY created_at DESC, log_id DESC LIMIT 8'
    )->fetchAll();
}
include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <div class="card">
        <h1 class="card-title"><?= $isAdmin ? 'Admin Dashboard' : 'Staff Dashboard' ?></h1>
        <p class="card-text">Welcome, <?= htmlspecialchars((string) $_SESSION['full_name'], ENT_QUOTES, 'UTF-8') ?>. Your role: <strong><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></strong>.</p>
        <div class="admin-shortcuts" aria-label="Bookstore operations">
            <a class="btn btn-primary" href="/admin/products.php">Manage books</a>
            <a class="btn btn-secondary" href="/admin/genres.php">Manage categories</a>
            <a class="btn btn-secondary" href="/admin/author_reviews.php">Author reviews</a>
            <a class="btn btn-secondary" href="/admin/customers.php">Customers</a>
            <a class="btn btn-secondary" href="/admin/orders.php">Manage orders</a>
            <?php if ($isAdmin): ?>
                <a class="btn btn-primary" href="/admin/users.php">Manage staff</a>
                <a class="btn btn-secondary" href="/admin/security.php">Security settings</a>
                <a class="btn btn-secondary" href="/admin/audit_logs.php">Full audit trail</a>
            <?php endif; ?>
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
        <?php if ($isAdmin && $paidSales !== null): ?>
            <div class="card"><p class="card-text">Confirmed paid sales</p><strong style="font-size:1.7rem;">₱<?= number_format($paidSales, 2) ?></strong></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="card-title">Recent bookstore activity</h2>
        <?php if (!$recentActivity): ?>
            <p class="card-text">No recent activity is available. Apply the centralized audit schema if this table is empty.</p>
        <?php else: ?>
            <div style="overflow-x:auto;"><table class="table">
                <thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Activity</th></tr></thead>
                <tbody><?php foreach ($recentActivity as $activity): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($activity['actor_name'] ?: 'System / unknown'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) ($activity['actor_role'] ?: 'unknown')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', (string) $activity['action']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) ($activity['module'] ?: 'unknown')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $activity['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
