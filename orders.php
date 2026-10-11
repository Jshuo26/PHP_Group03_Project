<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

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

/*
 * Get all orders belonging to the currently logged-in customer.
 */
$stmt = $pdo->prepare(
    'SELECT
        o.order_id,
        o.order_number,
        o.status,
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
        o.total_amount,
        o.placed_at
     ORDER BY o.placed_at DESC'
);

$stmt->execute([$userId]);
$allOrders = $stmt->fetchAll();

/*
 * Count orders for each status.
 */
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

/*
 * Filter orders according to the selected tab.
 */
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

/*
 * Tracking steps.
 */
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

include __DIR__ . '/includes/header.php';
?>

<section class="container orders-page">

    <div class="page-heading">
        <h1 class="page-title">My Orders</h1>
        <p class="page-subtitle">
            View and track your orders.
        </p>
    </div>

    <!-- STATUS TABS -->
    <nav class="order-status-tabs" aria-label="Order status filters">

        <a
            href="/orders.php"
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
                href="/orders.php?status=<?= urlencode($statusKey) ?>"
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


    <!-- ORDERS -->
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
                    href="/orders.php"
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
                ?>

                <article class="order-card <?= $isCancelled ? 'cancelled-order' : '' ?>">

                    <!-- ORDER HEADER -->
                    <div class="order-card-header">

                        <div>
                            <span class="order-label">
                                Order Number
                            </span>

                            <h2 class="order-number">
                                <?= e($order['order_number']) ?>
                            </h2>
                        </div>

                        <span
                            class="order-status status-<?= e($status) ?>"
                        >
                            <?= e($statusLabel) ?>
                        </span>

                    </div>


                    <!-- ORDER DETAILS -->
                    <div class="order-details">

                        <div class="order-detail">

                            <span class="order-detail-label">
                                Items
                            </span>

                            <span class="order-detail-value">
                                <?= e($order['items']) ?>
                            </span>

                        </div>


                        <div class="order-detail">

                            <span class="order-detail-label">
                                Order Date
                            </span>

                            <span class="order-detail-value">
                                <?= e(
                                    date(
                                        'M d, Y · h:i A',
                                        strtotime($order['placed_at'])
                                    )
                                ) ?>
                            </span>

                        </div>


                        <div class="order-detail order-total">

                            <span class="order-detail-label">
                                Total
                            </span>

                            <span class="order-detail-value">
                                ₱<?= number_format(
                                    (float) $order['total_amount'],
                                    2
                                ) ?>
                            </span>

                        </div>

                    </div>


                    <!-- ORDER TRACKING -->
                    <?php if ($isCancelled): ?>

                        <div class="tracking-section">

                            <h3 class="tracking-heading">
                                Order Tracking
                            </h3>

                            <div class="cancelled-order-message">
                                <p>
                                    This order has been cancelled.
                                </p>
                            </div>

                        </div>

                    <?php else: ?>

                        <div class="tracking-section">

                            <div class="tracking-heading-row">

                                <h3 class="tracking-heading">
                                    Order Tracking
                                </h3>

                                <span class="tracking-progress">
                                    Step <?= $currentStep ?> of 4
                                </span>

                            </div>


                            <div class="tracking-progress">

                                <?php
                                $steps = $trackingSteps[$status];
                                ?>

                                <?php foreach ($steps as $index => $stepLabel): ?>

                                    <?php
                                    $stepNumber = $index + 1;

                                    $isCompleted = $stepNumber < $currentStep;
                                    $isCurrent = $stepNumber === $currentStep;

                                    $stepClass = '';

                                    if ($isCompleted) {
                                        $stepClass = 'completed';
                                    } elseif ($isCurrent) {
                                        $stepClass = 'active';
                                    }
                                    ?>

                                    <div
                                        class="tracking-step <?= $stepClass ?>"
                                    >

                                        <div class="tracking-dot">

                                            <?php if ($isCompleted || $isCurrent): ?>

                                                ✓

                                            <?php else: ?>

                                                <?= $stepNumber ?>

                                            <?php endif; ?>

                                        </div>

                                        <span class="tracking-label">
                                            <?= e($stepLabel) ?>
                                        </span>

                                    </div>


                                    <?php if ($stepNumber < 4): ?>

                                        <div
                                            class="tracking-line <?= $isCompleted ? 'completed' : '' ?>"
                                        ></div>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<?php include __DIR__ . '/includes/footer.php'; ?>