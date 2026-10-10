<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireRole(['customer']);
$stmt = $pdo->prepare(
    'SELECT o.order_number, o.status, o.total_amount, o.placed_at,
            GROUP_CONCAT(CONCAT(b.title, " × ", oi.quantity) ORDER BY b.title SEPARATOR ", ") AS items
     FROM orders o
     JOIN order_items oi ON oi.order_id = o.order_id
     JOIN book_editions e ON e.edition_id = oi.edition_id
     JOIN books b ON b.book_id = e.book_id
     WHERE o.user_id = ?
     GROUP BY o.order_id, o.order_number, o.status, o.total_amount, o.placed_at
     ORDER BY o.placed_at DESC'
);
$stmt->execute([(int) $_SESSION['user_id']]);
$orders = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">My Orders</h1>
    <?php if (!$orders): ?>
        <div class="card"><p class="card-text">You have not placed an order yet.</p><a class="btn btn-primary" href="/books.php">Browse books</a></div>
    <?php else: foreach ($orders as $order): ?>
        <article class="card admin-list-item">
            <h2><?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($order['items'], ENT_QUOTES, 'UTF-8') ?></p>
            <p>Status: <?= htmlspecialchars(ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?></p>
            <p>Total: ₱<?= number_format((float) $order['total_amount'], 2) ?> · <?= htmlspecialchars($order['placed_at'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>
    <?php endforeach; endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
