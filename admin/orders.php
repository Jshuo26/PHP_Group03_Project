<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../controllers/OrderController.php';
require_once __DIR__ . '/../includes/crypto.php';

requireRole(['admin', 'staff']);
$errors = [];
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
$customerId = filter_var($_GET['customer_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$ordersUrl = '/admin/orders.php' . ($customerId > 0 ? '?customer_id=' . $customerId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?: 0;
    $status = requestString($_POST, 'status');
    if ($orderId < 1 || !in_array($status, $statuses, true)) {
        $errors[] = 'Select a valid order and status.';
    } else {
        try {
            $orderController = new OrderController($pdo);
            $orderController->updateOrderStatus($orderId, $status, (int) $_SESSION['user_id']);
            $_SESSION['admin_flash'] = 'Order status updated successfully.';
            header('Location: ' . $ordersUrl);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$sql =
    'SELECT o.order_id, o.order_number, u.user_id,
            MAX(o.shipping_name) AS shipping_name,
            MAX(o.shipping_mobile) AS shipping_mobile,
            MAX(o.shipping_street) AS shipping_street,
            MAX(o.shipping_city) AS shipping_city,
            MAX(o.shipping_province) AS shipping_province,
            MAX(o.shipping_zip) AS shipping_zip,
            o.status, o.total_amount,
            COALESCE(CONVERT_TZ(o.placed_at, 'SYSTEM', '+08:00'), o.placed_at) AS placed_at,
            MAX(u.email) AS email,
            GROUP_CONCAT(CONCAT(b.title, " × ", oi.quantity) ORDER BY b.title SEPARATOR ", ") AS items
     FROM orders o
     JOIN users u ON u.user_id = o.user_id
     JOIN order_items oi ON oi.order_id = o.order_id
     JOIN book_editions e ON e.edition_id = oi.edition_id
     JOIN books b ON b.book_id = e.book_id'
    . ($customerId > 0 ? ' WHERE o.user_id = ?' : '')
    . ' GROUP BY o.order_id, o.order_number, u.user_id,
              o.status, o.total_amount, o.placed_at
       ORDER BY o.placed_at DESC';
$orderStmt = $pdo->prepare($sql);
$orderStmt->execute($customerId > 0 ? [$customerId] : []);
$orders = $orderStmt->fetchAll();
$orderStatusCounts = array_fill_keys($statuses, 0);
foreach ($orders as $orderRow) {
    $currentStatus = (string) ($orderRow['status'] ?? '');
    if (isset($orderStatusCounts[$currentStatus])) $orderStatusCounts[$currentStatus]++;
}

foreach ($orders as &$order) {
    $order = decryptOrderPersonalFields($order);
    $order['email'] = decryptPersonalField(
        (string) $order['email'],
        personalFieldContext('users', 'email', $order['user_id'])
    );
}
unset($order);

include __DIR__ . '/../includes/header.php';
?>
<main class="container admin-operations admin-orders-page">
    <header class="admin-page-hero">
        <div><span class="admin-hero-eyebrow">STORE OPERATIONS</span><h1><?= $customerId > 0 ? 'Customer Order History' : 'Order Management' ?></h1><p><?= $customerId > 0 ? 'Review and update orders for this customer.' : 'Review incoming orders and keep fulfillment moving.' ?></p></div>
        <div class="hero-meta"><span class="operations-count"><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?></span><?php if ($customerId > 0): ?><a class="btn btn-secondary" href="/admin/customers.php">Back to customers</a><?php endif; ?></div>
    </header>
    <section class="orders-summary-grid" aria-label="Order status overview">
        <?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $statusKey => $statusLabel): ?>
            <article class="orders-summary-card orders-summary-<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"><span><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format($orderStatusCounts[$statusKey]) ?></strong><small><?= $orderStatusCounts[$statusKey] === 1 ? 'order' : 'orders' ?></small></article>
        <?php endforeach; ?>
    </section>
    <?php if ($errors): ?><div class="operations-alert error" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="operations-alert success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <?php if ($orders): ?>
        <section class="orders-filter-panel" aria-label="Filter customer orders">
            <label for="orders-search">Search Orders<input class="form-input" type="search" id="orders-search" placeholder="Order number, customer, or book"></label>
            <label for="orders-status-filter">Order Status<select class="form-input" id="orders-status-filter"><option value="all">All Statuses</option><?php foreach ($statuses as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
            <button type="button" class="btn btn-secondary" id="orders-filter-clear">Clear Filters</button>
            <p id="orders-filter-count" aria-live="polite">Showing <?= count($orders) ?> of <?= count($orders) ?> orders</p>
        </section>
        <div class="operations-empty orders-filter-empty" id="orders-filter-empty" hidden aria-live="polite"><h2>No Matching Orders</h2><p>Try a different search or status filter.</p></div>
    <?php endif; ?>
    <?php if (!$orders): ?>
        <div class="operations-empty"><span aria-hidden="true">✓</span><h2>No Orders Yet</h2><p>New customer orders will appear here.</p></div>
    <?php else: foreach ($orders as $order): ?>
        <?php $orderSearch = mb_strtolower((string) $order['order_number'] . ' ' . (string) $order['shipping_name'] . ' ' . (string) $order['items'] . ' ' . (string) $order['email']); ?>
        <article class="order-management-card" data-order-status="<?= htmlspecialchars((string) $order['status'], ENT_QUOTES, 'UTF-8') ?>" data-order-search="<?= htmlspecialchars($orderSearch, ENT_QUOTES, 'UTF-8') ?>">
            <header class="order-management-heading"><div><span class="operations-eyebrow">ORDER</span><h2><?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></h2><p>Placed <?= htmlspecialchars($order['placed_at'], ENT_QUOTES, 'UTF-8') ?></p></div><div class="order-heading-meta"><span class="order-status-pill status-<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?></span><strong>₱<?= number_format((float) $order['total_amount'], 2) ?></strong></div></header>
            <div class="order-detail-grid">
                <section><h3>Customer</h3><p><?= htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') ?></p><a href="mailto:<?= htmlspecialchars($order['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($order['email'], ENT_QUOTES, 'UTF-8') ?></a></section>
                <section><h3>Ship to</h3><p><?= htmlspecialchars($order['shipping_street'] . ', ' . $order['shipping_city'] . ', ' . $order['shipping_province'] . ' ' . $order['shipping_zip'], ENT_QUOTES, 'UTF-8') ?></p><p><?= htmlspecialchars($order['shipping_mobile'], ENT_QUOTES, 'UTF-8') ?></p></section>
                <section class="order-items-detail"><h3>Items</h3><p><?= htmlspecialchars($order['items'], ENT_QUOTES, 'UTF-8') ?></p></section>
            </div>
            <form method="post" action="<?= htmlspecialchars($ordersUrl, ENT_QUOTES, 'UTF-8') ?>" class="order-status-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                <label for="status-<?= (int) $order['order_id'] ?>">Status</label>
                <select class="form-input order-status" id="status-<?= (int) $order['order_id'] ?>" name="status" required>
                    <?php foreach ($statuses as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Update Status</button>
            </form>
        </article>
    <?php endforeach; endif; ?>
</main>
<?php if ($orders): ?><script>
document.addEventListener('DOMContentLoaded', function () {
  var search = document.getElementById('orders-search');
  var status = document.getElementById('orders-status-filter');
  var clear = document.getElementById('orders-filter-clear');
  var count = document.getElementById('orders-filter-count');
  var empty = document.getElementById('orders-filter-empty');
  var cards = Array.from(document.querySelectorAll('.order-management-card[data-order-status]'));
  function applyFilters() {
    var query = search.value.trim().toLowerCase();
    var selectedStatus = status.value;
    var visible = 0;
    cards.forEach(function (card) {
      var match = (selectedStatus === 'all' || card.dataset.orderStatus === selectedStatus)
        && (!query || card.dataset.orderSearch.includes(query));
      card.hidden = !match;
      if (match) visible++;
    });
    count.textContent = 'Showing ' + visible + ' of ' + cards.length + ' orders';
    empty.hidden = visible !== 0;
    empty.setAttribute('aria-hidden', visible !== 0 ? 'true' : 'false');
  }
  search.addEventListener('input', applyFilters);
  status.addEventListener('change', applyFilters);
  clear.addEventListener('click', function () { search.value = ''; status.value = 'all'; applyFilters(); });
  applyFilters();
});
</script><?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
