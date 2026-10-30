<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../controllers/OrderController.php';
require_once __DIR__ . '/../controllers/PaymongoController.php';
require_once __DIR__ . '/../includes/payment_schema.php';

requireRole(['customer']);

$userId = (int) $_SESSION['user_id'];

$statuses = [
    'pending' => 'Pending',
    'processing' => 'Processing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled'
];

$selectedStatus = $_GET['status'] ?? 'all';

if ($selectedStatus !== 'all' && !array_key_exists($selectedStatus, $statuses)) {
    $selectedStatus = 'all';
}

$flashError = $_SESSION['order_flash_error'] ?? '';
$flashSuccess = $_SESSION['order_flash_success'] ?? '';
unset($_SESSION['order_flash_error'], $_SESSION['order_flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    requireCsrfToken();
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?: 0;
    try {
        $method = 'cod';
        if (paymentSchemaHasColumn($pdo, 'orders', 'payment_method')) {
            $lookup = $pdo->prepare('SELECT payment_method FROM orders WHERE order_id = ? AND user_id = ?');
            $lookup->execute([$orderId, $userId]);
            $method = $lookup->fetchColumn() ?: 'cod';
        }
        if (in_array($method, ['gcash', 'maya', 'qrph'], true)) {
            (new PaymongoController($pdo, $config))->cancelUnpaidOrder($orderId, $userId);
        } else {
            (new OrderController($pdo))->cancelOrderByCustomer($orderId, $userId);
        }
        $_SESSION['order_flash_success'] = 'Order cancelled successfully. Stock has been restored.';
    } catch (Throwable $e) {
        $_SESSION['order_flash_error'] = $e->getMessage();
    }
    header('Location: /account/orders.php' . ($selectedStatus !== 'all' ? '?status=' . urlencode($selectedStatus) : ''));
    exit;
}

$hasPaymentOrderColumns = paymentSchemaHasColumn($pdo, 'orders', 'payment_method')
    && paymentSchemaHasColumn($pdo, 'orders', 'payment_status');
$paymentOrderColumns = $hasPaymentOrderColumns
    ? 'o.payment_method, o.payment_status,'
    : "'cod' AS payment_method, 'not_required' AS payment_status,";
$stmt = $pdo->prepare(
    'SELECT
        o.order_id,
        o.order_number,
        o.status,
        ' . $paymentOrderColumns . '
        o.total_amount,
        o.placed_at,
        GROUP_CONCAT(
            CONCAT(b.title, " × ", oi.quantity)
            ORDER BY b.title
            SEPARATOR ", "
        ) AS items
     FROM orders o
     JOIN order_items oi
        ON oi.order_id = o.order_id
     JOIN book_editions e
        ON e.edition_id = oi.edition_id
     JOIN books b
        ON b.book_id = e.book_id
     WHERE o.user_id = ?
     GROUP BY
        o.order_id,
        o.order_number,
        o.status,
        ' . ($hasPaymentOrderColumns ? 'o.payment_method, o.payment_status,' : '') . '
        o.total_amount,
        o.placed_at
     ORDER BY o.placed_at DESC'
);

$stmt->execute([$userId]);
$allOrders = $stmt->fetchAll();

$orderIds = array_column($allOrders, 'order_id');
$orderItemsMap = [];
if (!empty($orderIds)) {
    $inClause = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare(
        "SELECT oi.order_id, oi.quantity, oi.unit_price, e.format, e.cover_image_path, b.title, b.book_id
         FROM order_items oi
         JOIN book_editions e ON e.edition_id = oi.edition_id
         JOIN books b ON b.book_id = e.book_id
         WHERE oi.order_id IN ($inClause)
         ORDER BY b.title"
    );
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $row) {
        $orderItemsMap[$row['order_id']][] = $row;
    }
}

$orderCounts = [
    'all' => count($allOrders),
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0
];

foreach ($allOrders as $order) {
    $status = $order['status'];

    if (isset($orderCounts[$status])) {
        $orderCounts[$status]++;
    }
}

if ($selectedStatus === 'all') {
    $orders = $allOrders;
} else {
    $orders = array_filter(
        $allOrders,
        static function ($order) use ($selectedStatus) {
            return $order['status'] === $selectedStatus;
        }
    );
}

$trackingSteps = [
    'pending' => [
        'Order Placed',
        'Processing',
        'Shipped',
        'Delivered'
    ],
    'processing' => [
        'Order Placed',
        'Processing',
        'Shipped',
        'Delivered'
    ],
    'shipped' => [
        'Order Placed',
        'Processing',
        'Shipped',
        'Delivered'
    ],
    'delivered' => [
        'Order Placed',
        'Processing',
        'Shipped',
        'Delivered'
    ]
];

$statusStep = [
    'pending' => 1,
    'processing' => 2,
    'shipped' => 3,
    'delivered' => 4
];

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

include __DIR__ . '/../includes/header.php';
?>

<div class="account-page-layout container">

    <aside class="account-sidebar">
        <nav class="account-sidebar-nav" aria-label="Account navigation">

            <p class="account-sidebar-section-label">Settings</p>

            <a href="/account/" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
                Account
            </a>

            <a href="/account/security.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Security
            </a>

            <a href="/addresses.php" class="account-sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                    <circle cx="12" cy="9" r="2.5"/>
                </svg>
                Manage Addresses
            </a>

            <div class="account-sidebar-divider"></div>

            <a href="/account/orders.php" class="account-sidebar-item active" aria-current="page">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="1"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="13" y2="16"/>
                </svg>
                Orders
            </a>

            <div class="account-sidebar-divider"></div>

            <button type="button" class="account-sidebar-item account-sidebar-logout account-logout-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Logout
            </button>

        </nav>
    </aside>

    <section class="account-main-content orders-page">

    <div class="page-heading">
        <h1 class="page-title">My Orders</h1>
        <p class="page-subtitle">
            View and track your orders.
        </p>
    </div>

    <?php if ($flashError !== ''): ?>
        <div class="form-errors" role="alert"><p><?= e($flashError) ?></p></div>
    <?php endif; ?>
    <?php if ($flashSuccess !== ''): ?>
        <div class="form-success" role="status"><p><?= e($flashSuccess) ?></p></div>
    <?php endif; ?>

    <nav class="order-status-tabs" aria-label="Order status filters">

        <a
            href="/account/orders.php"
            class="order-status-tab <?= $selectedStatus === 'all' ? 'active' : '' ?>"
            <?= $selectedStatus === 'all' ? 'aria-current="page"' : '' ?>
        >
            All
            <span class="order-count">
                <?= $orderCounts['all'] ?>
            </span>
        </a>

        <?php foreach ($statuses as $statusKey => $statusLabel): ?>

            <a
                href="/account/orders.php?status=<?= urlencode($statusKey) ?>"
                class="order-status-tab <?= $selectedStatus === $statusKey ? 'active' : '' ?>"
                <?= $selectedStatus === $statusKey ? 'aria-current="page"' : '' ?>
            >
                <?= e($statusLabel) ?>

                <span class="order-count">
                    <?= $orderCounts[$statusKey] ?>
                </span>
            </a>

        <?php endforeach; ?>

    </nav>

    <?php if (!$orders): ?>

        <div class="card empty-state">

            <?php if ($selectedStatus === 'all'): ?>

                <h2>No orders yet</h2>

                <p>
                    You have not placed an order yet.
                </p>

                <a
                    class="btn btn-primary"
                    href="/books.php"
                >
                    Browse Books
                </a>

            <?php else: ?>

                <h2>No <?= e($statuses[$selectedStatus]) ?> Orders</h2>

                <p>
                    You don't have any <?= strtolower(e($statuses[$selectedStatus])) ?> orders yet.
                </p>

                <a
                    class="btn btn-primary"
                    href="/account/orders.php"
                >
                    View All Orders
                </a>

            <?php endif; ?>

        </div>

    <?php else: ?>

        <div class="orders-list">

            <?php foreach ($orders as $order): ?>

                <?php
                $status = $order['status'];

                $statusLabel = $statuses[$status] ?? ucfirst($status);

                $currentStep = $statusStep[$status] ?? 0;

                $isCancelled = $status === 'cancelled';
                $isOnlinePayment = in_array($order['payment_method'], ['gcash', 'maya', 'qrph'], true);
                $paymentStatus = (string) ($order['payment_status'] ?? 'not_required');
                $canRetryPayment = $isOnlinePayment && !$isCancelled && in_array($paymentStatus, ['failed', 'expired'], true);
                $canCancelOrder = !$isOnlinePayment
                    && in_array($status, ['pending', 'processing'], true);
                ?>

                <article class="order-card <?= $isCancelled ? 'order-card-cancelled' : '' ?>">

                    <div class="order-card-header">
                        <div>
                            <span class="order-label">Order Number</span>
                            <h2 class="order-number"><?= e($order['order_number']) ?></h2>
                        </div>

                        <div class="order-card-actions">
                            <?php if ($canRetryPayment): ?>
                                <form method="post" action="/payments/create_checkout.php" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                    <button type="submit" class="btn btn-primary">Retry Payment</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canCancelOrder): ?>
                                <form method="post" action="/account/orders.php<?= $selectedStatus !== 'all' ? '?status=' . urlencode($selectedStatus) : '' ?>" onsubmit="return confirm('Are you sure you want to cancel this order?');" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                    <button type="submit" class="btn btn-cancel-order">Cancel Order</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isCancelled): ?>
                                <button type="button" class="btn btn-track-order track-order-btn" 
                                    data-order="<?= e($order['order_number']) ?>" 
                                    data-status="<?= e($status) ?>" 
                                    data-status-label="<?= e($statusLabel) ?>" 
                                    data-step="<?= (int) ($statusStep[$status] ?? 1) ?>">
                                    Track Order
                                </button>
                            <?php endif; ?>

                            <span class="order-status status-<?= e($status) ?>">
                                <?= e($statusLabel) ?>
                            </span>
                        </div>
                    </div>

                    <div class="order-card-body">
                        <div class="order-items-col">
                            <span class="order-detail-label">Items Ordered</span>
                            <div class="order-items-list">
                                <?php foreach ($orderItemsMap[$order['order_id']] ?? [] as $item): ?>
                                    <?php $coverUrl = catalogCover($item['cover_image_path']); ?>
                                    <div class="order-item-row">
                                        <div class="order-item-cover-wrap">
                                            <?php if ($coverUrl): ?>
                                                <img src="<?= e($coverUrl) ?>" alt="<?= e($item['title']) ?>" class="order-item-cover" loading="lazy">
                                            <?php else: ?>
                                                <div class="order-item-cover-placeholder">📖</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="order-item-info">
                                            <a href="/book.php?id=<?= (int) $item['book_id'] ?>" class="order-item-title"><?= e($item['title']) ?></a>
                                            <div class="order-item-meta">
                                                <span class="order-item-format"><?= e(ucfirst($item['format'])) ?></span>
                                                <span class="order-item-qty">Qty: <?= (int) $item['quantity'] ?></span>
                                                <span class="order-item-price">₱<?= number_format((float) $item['unit_price'], 2) ?> each</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($isCancelled): ?>
                                <div class="order-cancelled-notice">
                                    <p><strong>Order Cancelled</strong> · This order was cancelled and inventory was restored.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="order-meta-col">
                            <div class="order-meta-box">
                                <span class="order-detail-label">Order Date</span>
                                <span class="order-meta-value"><?= e(date('M d, Y · h:i A', strtotime($order['placed_at']))) ?></span>
                            </div>
                            <div class="order-meta-box order-meta-total">
                                <span class="order-detail-label">Total Amount</span>
                                <span class="order-total-amount">₱<?= number_format((float) $order['total_amount'], 2) ?></span>
                            </div>
                            <?php if ($isOnlinePayment): ?>
                                <div class="order-meta-box">
                                    <span class="order-detail-label">Payment</span>
                                    <span class="order-meta-value"><?= e(ucfirst($paymentStatus)) ?> · <?= e(strtoupper($order['payment_method'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    </section>

</div>

<!-- Order Tracking Modal -->
<div id="trackingModal" class="tracking-modal" hidden>
    <div class="tracking-modal-backdrop"></div>
    <div class="tracking-modal-card">
        <div class="tracking-modal-header">
            <div>
                <span class="tracking-modal-eyebrow">ORDER PROGRESS</span>
                <h3 class="tracking-modal-title" id="modalOrderNumber">ORD-...</h3>
            </div>
            <button type="button" class="tracking-modal-close" aria-label="Close tracking">&times;</button>
        </div>

        <div class="tracking-modal-body">
            <div class="tracking-modal-status-row">
                <span class="tracking-modal-status-label">Current Status:</span>
                <span class="order-status" id="modalStatusBadge">Pending</span>
            </div>
            <p class="tracking-info-message" role="status">No courier tracking number or tracking link is available yet. This timeline shows the bookstore's latest order status.</p>

            <div class="tracking-vertical-timeline">
                <div class="timeline-step" data-step="1">
                    <div class="timeline-dot"><span>1</span></div>
                    <div class="timeline-content">
                        <h4>Order Placed</h4>
                        <p>We received your order and reserved your items.</p>
                    </div>
                </div>

                <div class="timeline-step" data-step="2">
                    <div class="timeline-dot"><span>2</span></div>
                    <div class="timeline-content">
                        <h4>Processing</h4>
                        <p>Your books are being carefully prepared and packed.</p>
                    </div>
                </div>

                <div class="timeline-step" data-step="3">
                    <div class="timeline-dot"><span>3</span></div>
                    <div class="timeline-content">
                        <h4>Shipped</h4>
                        <p>Your package has been handed over to our courier partner.</p>
                    </div>
                </div>

                <div class="timeline-step" data-step="4">
                    <div class="timeline-dot"><span>4</span></div>
                    <div class="timeline-content">
                        <h4>Delivered</h4>
                        <p>Package delivered to your shipping address.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/js/orders.js" defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
