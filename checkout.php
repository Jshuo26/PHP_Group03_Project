<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

requireRole(['customer']);
$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = $_SESSION['order_flash'] ?? '';
unset($_SESSION['order_flash']);
$shippingName = requestString($_POST, 'shipping_name');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $shippingName === '') {
    $shippingName = (string) ($_SESSION['full_name'] ?? '');
}
$shipping = [
    'shipping_name' => trim($shippingName),
    'shipping_mobile' => trim(requestString($_POST, 'shipping_mobile')),
    'shipping_street' => trim(requestString($_POST, 'shipping_street')),
    'shipping_city' => trim(requestString($_POST, 'shipping_city')),
    'shipping_province' => trim(requestString($_POST, 'shipping_province')),
    'shipping_zip' => trim(requestString($_POST, 'shipping_zip'))
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $shipping['shipping_mobile'] = preg_replace('/[\s-]/', '', $shipping['shipping_mobile']);
    $limits = [
        'shipping_name' => 150,
        'shipping_mobile' => 255,
        'shipping_street' => 500,
        'shipping_city' => 100,
        'shipping_province' => 100,
        'shipping_zip' => 10
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

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $cartStmt = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ? FOR UPDATE');
            $cartStmt->execute([$userId]);
            $cartId = (int) ($cartStmt->fetchColumn() ?: 0);
            if ($cartId < 1) {
                throw new RuntimeException('Your cart is empty.');
            }

            $itemsStmt = $pdo->prepare(
                'SELECT ci.cart_item_id, ci.edition_id, ci.quantity, e.price,
                        e.stock_quantity, b.title
                 FROM cart_items ci
                 JOIN book_editions e ON e.edition_id = ci.edition_id
                 JOIN books b ON b.book_id = e.book_id
                 WHERE ci.cart_id = ?
                 ORDER BY e.edition_id
                 FOR UPDATE'
            );
            $itemsStmt->execute([$cartId]);
            $items = $itemsStmt->fetchAll();
            if (!$items) {
                throw new RuntimeException('Your cart is empty.');
            }

            $subtotalCents = 0;
            foreach ($items as $item) {
                if ((int) $item['quantity'] < 1 || (int) $item['quantity'] > (int) $item['stock_quantity']) {
                    throw new RuntimeException('There is not enough stock for "' . $item['title'] . '". Update your cart and try again.');
                }
                $unitPriceCents = (int) round((float) $item['price'] * 100);
                $subtotalCents += $unitPriceCents * (int) $item['quantity'];
            }
            $subtotal = number_format($subtotalCents / 100, 2, '.', '');
            $shippingFee = '0.00';
            $taxAmount = '0.00';
            $totalAmount = $subtotal;
            $orderNumber = 'ORD-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

            $orderStmt = $pdo->prepare(
                'INSERT INTO orders
                    (user_id, order_number, shipping_name, shipping_mobile, shipping_street,
                     shipping_city, shipping_province, shipping_zip, subtotal, shipping_fee,
                     tax_amount, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $orderStmt->execute([
                $userId,
                $orderNumber,
                $shipping['shipping_name'],
                $shipping['shipping_mobile'],
                $shipping['shipping_street'],
                $shipping['shipping_city'],
                $shipping['shipping_province'],
                $shipping['shipping_zip'],
                $subtotal,
                $shippingFee,
                $taxAmount,
                $totalAmount
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $orderItemStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, edition_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
            );
            $stockStmt = $pdo->prepare(
                'UPDATE book_editions SET stock_quantity = stock_quantity - ?
                 WHERE edition_id = ? AND stock_quantity >= ?'
            );
            foreach ($items as $item) {
                $orderItemStmt->execute([
                    $orderId,
                    (int) $item['edition_id'],
                    (int) $item['quantity'],
                    $item['price']
                ]);
                $stockStmt->execute([
                    (int) $item['quantity'],
                    (int) $item['edition_id'],
                    (int) $item['quantity']
                ]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Stock changed during checkout. Please review your cart and try again.');
                }
            }

            $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);
            $pdo->commit();

            $_SESSION['order_flash'] = 'Order placed successfully. Order number: ' . $orderNumber;
            header('Location: /checkout.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Checkout failed: ' . $e->getMessage());
            $errors[] = 'Unable to place your order right now. Please try again.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
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
<section class="container">
    <div class="card">
        <h1 class="card-title">Checkout</h1>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="form-success" role="status">
                <p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
                <p>No online payment was processed. The store will update your order status.</p>
                <a class="btn btn-primary" href="/orders.php">View my orders</a>
                <a class="btn btn-secondary" href="/books.php">Continue shopping</a>
            </div>
        <?php elseif (!$items): ?>
            <p class="card-text">Your cart is empty.</p><a class="btn btn-primary" href="/books.php">Browse books</a>
        <?php else: ?>
            <h2>Order summary</h2>
            <?php foreach ($items as $item): ?>
                <p><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst($item['format']), ENT_QUOTES, 'UTF-8') ?> · <?= (int) $item['quantity'] ?> × ₱<?= number_format((float) $item['price'], 2) ?></p>
            <?php endforeach; ?>
            <p><strong>Subtotal: ₱<?= number_format($subtotal, 2) ?></strong></p>
            <p>Shipping fee and tax are currently ₱0.00. No online payment is taken.</p>
            <h2>Delivery details</h2>
            <form method="post" action="/checkout.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group"><label for="shipping_name">Recipient name</label><input class="form-input" type="text" id="shipping_name" name="shipping_name" maxlength="150" required value="<?= htmlspecialchars($shipping['shipping_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="shipping_mobile">Mobile number</label><input class="form-input" type="tel" id="shipping_mobile" name="shipping_mobile" maxlength="20" required value="<?= htmlspecialchars($shipping['shipping_mobile'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="shipping_street">Street address</label><input class="form-input" type="text" id="shipping_street" name="shipping_street" maxlength="500" required value="<?= htmlspecialchars($shipping['shipping_street'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="shipping_city">City</label><input class="form-input" type="text" id="shipping_city" name="shipping_city" maxlength="100" required value="<?= htmlspecialchars($shipping['shipping_city'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="shipping_province">Province</label><input class="form-input" type="text" id="shipping_province" name="shipping_province" maxlength="100" required value="<?= htmlspecialchars($shipping['shipping_province'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="shipping_zip">ZIP or postal code</label><input class="form-input" type="text" id="shipping_zip" name="shipping_zip" maxlength="10" required value="<?= htmlspecialchars($shipping['shipping_zip'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <button class="btn btn-primary" type="submit">Confirm order · ₱<?= number_format($subtotal, 2) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
