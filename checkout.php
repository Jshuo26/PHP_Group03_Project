<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';
require_once __DIR__ . '/controllers/OrderController.php';
require_once __DIR__ . '/controllers/PaymongoController.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

requireRole(['customer']);
$userId = (int) $_SESSION['user_id'];
$errors = [];

$userStmt = $pdo->prepare('SELECT user_id, full_name, mobile_number FROM users WHERE user_id = ?');
$userStmt->execute([$userId]);
$userData = $userStmt->fetch() ?: [];
if ($userData) {
    $userData = decryptUserPersonalFields($userData);
}

$defaultAddrStmt = $pdo->prepare(
    'SELECT address_id, street, city, province, zip_code
     FROM addresses 
     WHERE user_id = ? AND is_default = 1 
     LIMIT 1'
);
$defaultAddrStmt->execute([$userId]);
$defaultAddress = $defaultAddrStmt->fetch();
if ($defaultAddress) {
    $defaultAddress = decryptAddressPersonalFields($defaultAddress);
}

if (!$defaultAddress) {
    $anyAddrStmt = $pdo->prepare(
        'SELECT address_id, street, city, province, zip_code
         FROM addresses 
         WHERE user_id = ? 
         ORDER BY address_id DESC 
         LIMIT 1'
    );
    $anyAddrStmt->execute([$userId]);
    $defaultAddress = $anyAddrStmt->fetch() ?: [];
    if ($defaultAddress) {
        $defaultAddress = decryptAddressPersonalFields($defaultAddress);
    }
}

$shippingName = requestString($_POST, 'shipping_name');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingName === '') {
    $shippingName = (string) ($userData['full_name'] ?? $_SESSION['full_name'] ?? '');
}

$shippingMobile = requestString($_POST, 'shipping_mobile');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingMobile === '') {
    $shippingMobile = (string) ($userData['mobile_number'] ?? '');
}

$shippingStreet = requestString($_POST, 'shipping_street');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingStreet === '') {
    $shippingStreet = (string) ($defaultAddress['street'] ?? '');
}

$shippingCity = requestString($_POST, 'shipping_city');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingCity === '') {
    $shippingCity = (string) ($defaultAddress['city'] ?? '');
}

$shippingProvince = requestString($_POST, 'shipping_province');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingProvince === '') {
    $shippingProvince = (string) ($defaultAddress['province'] ?? '');
}

$shippingZip = requestString($_POST, 'shipping_zip');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingZip === '') {
    $shippingZip = (string) ($defaultAddress['zip_code'] ?? '');
}

$paymentMethod = requestString($_POST, 'payment_method');
if ($paymentMethod === '') {
    $paymentMethod = 'cod';
}

$shipping = [
    'shipping_name'     => trim($shippingName),
    'shipping_mobile'   => trim($shippingMobile),
    'shipping_street'   => trim($shippingStreet),
    'shipping_city'     => trim($shippingCity),
    'shipping_province' => trim($shippingProvince),
    'shipping_zip'      => trim($shippingZip)
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $checkoutFailureLogged = false;
    $shipping['shipping_mobile'] = preg_replace('/[\s-]/', '', $shipping['shipping_mobile']);
    
    $limits = [
        'shipping_name'     => 150,
        'shipping_mobile'   => 20,
        'shipping_street'   => 255,
        'shipping_city'     => 100,
        'shipping_province' => 100,
        'shipping_zip'      => 10
    ];

    foreach ($limits as $field => $limit) {
        if ($shipping[$field] === '' || mb_strlen($shipping[$field]) > $limit) {
            $errors[] = ucfirst(str_replace('shipping_', '', str_replace('_', ' ', $field)))
                . ' is required and must not exceed ' . $limit . ' characters.';
        }
    }

    if ($shipping['shipping_mobile'] !== '' && !preg_match('/^(\+?[0-9]{8,15})$/', $shipping['shipping_mobile'])) {
        $errors[] = 'Enter a valid mobile number.';
    }

    if ($shipping['shipping_zip'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{1,9}$/', $shipping['shipping_zip'])) {
        $errors[] = 'Enter a valid ZIP or postal code.';
    }

    if (!in_array($paymentMethod, ['cod', 'gcash'], true)) {
        $errors[] = 'Select Cash on delivery or GCash.';
    }

    if (!$errors) {
        $createdOrderId = 0;
        try {
            if ($paymentMethod !== 'cod') {
                $paymongo = new PaymongoController($pdo, $config);
                $paymongo->assertReady();
            }
            $orderController = new OrderController($pdo);
            $confirmedOrderData = $orderController->createPendingOrder($userId, $shipping, $paymentMethod);
            $createdOrderId = (int) $confirmedOrderData['order_id'];

            if ($paymentMethod !== 'cod') {
                $checkoutUrl = $paymongo->startOrResumeCheckout($createdOrderId, $userId);
                header('Location: ' . $checkoutUrl);
                exit;
            }

            $_SESSION['confirmed_order'] = $confirmedOrderData;

            header('Location: /order-confirmation.php');
            exit;

        } catch (PDOException $e) {
            error_log('Checkout failed: ' . $e->getMessage());
            auditLog($pdo, 'checkout_failed', $createdOrderId > 0 ? 'Order was created but PayMongo checkout could not start.' : 'Checkout could not create an order.', 'checkout', $userId, 'checkout', $createdOrderId > 0 ? $createdOrderId : null, 'failure', 'customer');
            $checkoutFailureLogged = true;
            $errors[] = $createdOrderId > 0
                ? 'Your order is saved, but checkout could not start. Open My Orders to retry payment.'
                : 'Unable to place your order right now. Please try again.';
        } catch (Throwable $e) {
            error_log('Checkout step failed after order ' . $createdOrderId . ': ' . get_class($e) . ': ' . $e->getMessage());
            auditLog($pdo, 'checkout_failed', $createdOrderId > 0 ? 'Order was created but PayMongo checkout could not start.' : 'Checkout could not create an order.', 'checkout', $userId, 'checkout', $createdOrderId > 0 ? $createdOrderId : null, 'failure', 'customer');
            $checkoutFailureLogged = true;
            $errors[] = $createdOrderId > 0
                ? 'Your order is saved, but checkout could not start. Open My Orders to retry payment.'
                : $e->getMessage();
        }
    }
    if ($errors && !$checkoutFailureLogged) {
        auditLog($pdo, 'checkout_failed', 'Checkout validation failed.', 'checkout', $userId, 'checkout', null, 'failure', 'customer');
    }
}

$items = [];
$subtotal = 0.0;
$cartStmt = $pdo->prepare(
    'SELECT ci.quantity, e.format, e.price, e.stock_quantity, b.title
     FROM carts c
     JOIN cart_items ci ON ci.cart_id = c.cart_id
     JOIN book_editions e ON e.edition_id = ci.edition_id
     JOIN books b ON b.book_id = e.book_id
     WHERE c.user_id = ?
     ORDER BY b.title'
);
$cartStmt->execute([$userId]);
$items = $cartStmt->fetchAll();
foreach ($items as $item) {
    $subtotal += (float) $item['price'] * (int) $item['quantity'];
}

include __DIR__ . '/includes/header.php';
?>

<section class="container checkout-page">

    <h1 class="checkout-page-title">Checkout</h1>

    <?php if ($errors): ?>
        <div class="form-errors" role="alert" style="margin-bottom: 20px;">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endforeach; ?>
            <?php if (!empty($createdOrderId)): ?>
                <p><a class="btn btn-secondary" href="/account/orders.php">Open My Orders</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="checkout-card" style="text-align: center; padding: 40px 20px;">
            <p style="font-size: 1.1rem; color: #374151; margin-bottom: 20px;">Your cart is empty.</p>
            <a class="btn btn-primary" href="/books.php">Browse Books</a>
        </div>
    <?php else: ?>

        <form method="post" action="/checkout.php" id="checkoutForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="checkout-grid">

                <div class="checkout-main">

                    <div class="checkout-card">
                        <h2 class="checkout-card-title">1) Shipping Address</h2>
                        <div class="checkout-form-grid">

                            <div class="checkout-form-row-2">
                                <div class="checkout-field">
                                    <label for="shipping_name">Full Name</label>
                                    <input 
                                        type="text" 
                                        id="shipping_name" 
                                        name="shipping_name" 
                                        maxlength="150" 
                                        required 
                                        value="<?= htmlspecialchars($shipping['shipping_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                                <div class="checkout-field">
                                    <label for="shipping_mobile">Mobile Number</label>
                                    <input 
                                        type="tel" 
                                        id="shipping_mobile" 
                                        name="shipping_mobile" 
                                        maxlength="20" 
                                        placeholder="09XXXXXXXXX"
                                        required 
                                        value="<?= htmlspecialchars($shipping['shipping_mobile'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                            </div>

                            <div class="checkout-field">
                                <label for="shipping_street">Street Address</label>
                                <input 
                                    type="text" 
                                    id="shipping_street" 
                                    name="shipping_street" 
                                    maxlength="255" 
                                    placeholder="Unit / Street / Barangay" 
                                    required 
                                    value="<?= htmlspecialchars($shipping['shipping_street'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>

                            <div class="checkout-form-row-3">
                                <div class="checkout-field">
                                    <label for="shipping_city">City</label>
                                    <input 
                                        type="text" 
                                        id="shipping_city" 
                                        name="shipping_city" 
                                        maxlength="100" 
                                        required 
                                        value="<?= htmlspecialchars($shipping['shipping_city'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                                <div class="checkout-field">
                                    <label for="shipping_province">Province</label>
                                    <input 
                                        type="text" 
                                        id="shipping_province" 
                                        name="shipping_province" 
                                        maxlength="100" 
                                        required 
                                        value="<?= htmlspecialchars($shipping['shipping_province'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                                <div class="checkout-field">
                                    <label for="shipping_zip">ZIP Code</label>
                                    <input 
                                        type="text" 
                                        id="shipping_zip" 
                                        name="shipping_zip" 
                                        maxlength="10" 
                                        required 
                                        value="<?= htmlspecialchars($shipping['shipping_zip'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="checkout-card">
                        <h2 class="checkout-card-title">2) Payment Method</h2>
                        <div class="payment-methods-grid">
                            
                            <label class="payment-method-pill <?= $paymentMethod === 'gcash' ? 'active' : '' ?>">
                                <input type="radio" name="payment_method" value="gcash" <?= $paymentMethod === 'gcash' ? 'checked' : '' ?>>
                                GCash
                            </label>


                            <label class="payment-method-pill <?= $paymentMethod === 'cod' ? 'active' : '' ?>">
                                <input type="radio" name="payment_method" value="cod" <?= $paymentMethod === 'cod' ? 'checked' : '' ?>>
                                Cash-on-delivery
                            </label>

                        </div>
                    </div>

                </div>

                <aside class="checkout-sidebar">
                    <div class="order-summary-card">
                        <h2>Order Summary</h2>

                        <div class="order-summary-items">
                            <?php foreach ($items as $item): ?>
                                <div class="order-summary-item">
                                    <div class="order-summary-item-title">
                                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                                        <span class="order-summary-item-format"><?= htmlspecialchars(ucfirst($item['format']), ENT_QUOTES, 'UTF-8') ?> &times; <?= (int) $item['quantity'] ?></span>
                                    </div>
                                    <div class="order-summary-item-price">
                                        ₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="summary-divider"></div>

                        <div class="summary-row">
                            <span>Subtotal</span>
                            <span>₱<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Shipping</span>
                            <span>₱0.00</span>
                        </div>
                        <div class="summary-row">
                            <span>Tax (VAT incl.)</span>
                            <span>₱0.00</span>
                        </div>

                        <div class="summary-divider"></div>

                        <div class="summary-row-total">
                            <span>Total</span>
                            <span>₱<?= number_format($subtotal, 2) ?></span>
                        </div>

                        <button type="submit" class="btn-checkout-proceed">Proceed to Checkout</button>
                    </div>
                </aside>

            </div>
        </form>

    <?php endif; ?>

</section>

<script src="/js/checkout.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
\