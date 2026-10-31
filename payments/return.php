<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/payment_schema.php';
require_once __DIR__ . '/../controllers/PaymongoController.php';

requireRole(['customer']);
$isRefreshRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isRefreshRequest) {
    requireCsrfToken();
    if (($_POST['action'] ?? '') !== 'refresh_payment') {
        http_response_code(400);
        exit('Invalid payment status request.');
    }
}
$orderId = filter_var(
    $isRefreshRequest ? ($_POST['order_id'] ?? null) : ($_GET['order_id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
) ?: 0;
$order = null;
$refreshError = '';

if ($orderId > 0 && paymongoSchemaReady($pdo)) {
    try {
        $controller = new PaymongoController($pdo, $config);
        $controller->refreshPaymentStatus($orderId, (int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        $refreshError = paymongoSafeCheckoutDiagnostic($e);
        error_log('Unable to refresh PayMongo return status for an owned order: ' . $refreshError);
    }

    $stmt = $pdo->prepare(
        'SELECT order_id, order_number, status, payment_method, payment_status
           FROM orders WHERE order_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$orderId, (int) $_SESSION['user_id']]);
    $order = $stmt->fetch() ?: null;
}

if (!$order) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<section class="container"><div class="card"><h1 class="card-title">Order not found</h1><p>This order could not be found for your account.</p><a class="btn btn-primary" href="/account/orders.php">My orders</a></div></section>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$state = (string) $order['payment_status'];
$cancelReturn = isset($_GET['cancelled']);
$message = match ($state) {
    'paid' => 'PayMongo confirmed your payment. Your order is awaiting fulfillment.',
    'expired' => 'This PayMongo checkout expired. You can start a new payment attempt from My Orders.',
    'failed' => 'PayMongo could not start this payment attempt. You can retry from My Orders.',
    'cancelled' => 'This order was cancelled and its checkout is no longer payable.',
    default => $cancelReturn
        ? 'You returned from PayMongo checkout without confirmed payment. Your order is still awaiting payment.'
        : 'We are waiting for PayMongo to confirm your payment. This page does not treat the redirect as proof of payment.',
};
if ($refreshError !== '' && $state !== 'paid') {
    $message = 'We could not refresh the payment status. No payment success has been assumed. Diagnostic: ' . $refreshError;
}
$canCheck = $state === 'pending' && $order['status'] !== 'cancelled';
$canRetry = in_array($state, ['failed', 'expired'], true) && $order['status'] !== 'cancelled';

include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <div class="card">
        <h1 class="card-title">Payment status</h1>
        <p class="card-text">Order <?= htmlspecialchars((string) $order['order_number'], ENT_QUOTES, 'UTF-8') ?></p>
        <p class="card-text"><strong>Status:</strong> <?= htmlspecialchars(ucfirst($state), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="card-text" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="admin-shortcuts">
            <?php if ($canCheck): ?>
                <form method="post" action="/payments/return.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="refresh_payment">
                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                    <button class="btn btn-primary" type="submit">Check Payment Status</button>
                </form>
            <?php endif; ?>
            <?php if ($canRetry): ?>
                <form method="post" action="/payments/create_checkout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                    <button class="btn btn-primary" type="submit">Retry payment</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-secondary" href="/account/orders.php">My Orders</a>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
