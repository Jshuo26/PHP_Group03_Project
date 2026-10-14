<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireRole(['customer']);

$order = $_SESSION['confirmed_order'] ?? null;

if (!$order) {
    header('Location: /account/orders.php');
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<div class="container confirmation-wrapper">
    <div class="confirmation-card">
        
        <div class="confirmation-icon" aria-hidden="true">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>

        <h1 class="confirmation-title">Order Placed Successfully!</h1>
        <p class="confirmation-sub">Thank you for shopping with aklAAAt!</p>

        <div class="confirmation-box">
            <div class="confirmation-box-col">
                <span class="confirmation-box-label">Order Number</span>
                <span class="confirmation-box-value"><?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="confirmation-box-col">
                <span class="confirmation-box-label">Order Date</span>
                <span class="confirmation-box-value"><?= htmlspecialchars($order['order_date'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <div class="confirmation-details">
            <div class="confirmation-detail-group">
                <h3>Items (<?= (int) $order['items_count'] ?>)</h3>
                <?php foreach ($order['titles'] as $title): ?>
                    <p><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
            </div>

            <div class="confirmation-detail-group">
                <h3>Delivery Estimate</h3>
                <p><?= htmlspecialchars($order['delivery_estimate'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="confirmation-total-row">
                <span>Total</span>
                <span>₱<?= number_format((float) $order['total_amount'], 2) ?></span>
            </div>
        </div>

        <div class="confirmation-actions">
            <a href="/account/orders.php" class="btn-confirm-action btn-confirm-track">Track My Order</a>
            <a href="/books.php" class="btn-confirm-action btn-confirm-shop">Continue Shopping</a>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>