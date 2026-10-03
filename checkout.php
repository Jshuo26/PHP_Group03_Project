<?php

require_once 'includes/session.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

requireLogin();

$errors = [];
$success = '';

$user_id = (int) $_SESSION['user_id'];


$stmt = $pdo->prepare(
    'SELECT
        ci.cart_item_id,
        ci.edition_id,
        ci.quantity,
        b.title,
        e.format,
        e.price,
        e.stock_quantity
     FROM carts c
     INNER JOIN cart_items ci
        ON c.cart_id = ci.cart_id
     INNER JOIN book_editions e
        ON ci.edition_id = e.edition_id
     INNER JOIN books b
        ON e.book_id = b.book_id
     WHERE c.user_id = ?
     ORDER BY b.title'
);

$stmt->execute([$user_id]);

$cart_items = $stmt->fetchAll();

$subtotal = 0;

foreach ($cart_items as $item) {

    $subtotal +=
        $item['price'] * $item['quantity'];
}

$shipping_fee = 0;
$tax_amount = 0;
$total_amount = $subtotal + $shipping_fee + $tax_amount;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $shipping_name = trim($_POST['shipping_name'] ?? '');
    $shipping_mobile = trim($_POST['shipping_mobile'] ?? '');
    $shipping_street = trim($_POST['shipping_street'] ?? '');
    $shipping_city = trim($_POST['shipping_city'] ?? '');
    $shipping_province = trim($_POST['shipping_province'] ?? '');
    $shipping_zip = trim($_POST['shipping_zip'] ?? '');

    if (empty($cart_items)) {
        $errors[] = 'Your cart is empty.';
    }

    if ($shipping_name === '') {
        $errors[] = 'Shipping name is required.';
    }

    if ($shipping_mobile === '') {
        $errors[] = 'Mobile number is required.';
    }

    if ($shipping_street === '') {
        $errors[] = 'Street address is required.';
    }

    if ($shipping_city === '') {
        $errors[] = 'City is required.';
    }

    if ($shipping_province === '') {
        $errors[] = 'Province is required.';
    }

    if ($shipping_zip === '') {
        $errors[] = 'ZIP code is required.';
    }


    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            foreach ($cart_items as $item) {

                $stockStmt = $pdo->prepare(
                    'SELECT stock_quantity
                     FROM book_editions
                     WHERE edition_id = ?
                     FOR UPDATE'
                );

                $stockStmt->execute([
                    $item['edition_id']
                ]);

                $stock = $stockStmt->fetchColumn();

                if (
                    $stock === false ||
                    $stock < $item['quantity']
                ) {

                    throw new Exception(
                        'Not enough stock for "' .
                        $item['title'] .
                        '".'
                    );
                }
            }

            $order_number =
                'ORD-' .
                date('YmdHis') .
                '-' .
                random_int(100, 999);

            $orderStmt = $pdo->prepare(
                'INSERT INTO orders
                (
                    user_id,
                    order_number,
                    shipping_name,
                    shipping_mobile,
                    shipping_street,
                    shipping_city,
                    shipping_province,
                    shipping_zip,
                    subtotal,
                    shipping_fee,
                    tax_amount,
                    total_amount
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $orderStmt->execute([
                $user_id,
                $order_number,
                $shipping_name,
                $shipping_mobile,
                $shipping_street,
                $shipping_city,
                $shipping_province,
                $shipping_zip,
                $subtotal,
                $shipping_fee,
                $tax_amount,
                $total_amount
            ]);

            $order_id = $pdo->lastInsertId();

            foreach ($cart_items as $item) {

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items
                    (
                        order_id,
                        edition_id,
                        quantity,
                        unit_price
                    )
                    VALUES (?, ?, ?, ?)'
                );

                $itemStmt->execute([
                    $order_id,
                    $item['edition_id'],
                    $item['quantity'],
                    $item['price']
                ]);


                $updateStock = $pdo->prepare(
                    'UPDATE book_editions
                     SET stock_quantity =
                         stock_quantity - ?
                     WHERE edition_id = ?'
                );

                $updateStock->execute([
                    $item['quantity'],
                    $item['edition_id']
                ]);
            }

            $cartStmt = $pdo->prepare(
                'SELECT cart_id
                 FROM carts
                 WHERE user_id = ?'
            );

            $cartStmt->execute([$user_id]);

            $cart_id = $cartStmt->fetchColumn();

            if ($cart_id) {

                $clearStmt = $pdo->prepare(
                    'DELETE FROM cart_items
                     WHERE cart_id = ?'
                );

                $clearStmt->execute([$cart_id]);
            }


            $pdo->commit();

            $success =
                'Order placed successfully! ' .
                'Order number: ' .
                $order_number;

            $cart_items = [];
            $subtotal = 0;
            $total_amount = 0;

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                'Unable to place order. ' .
                $e->getMessage();
        }
    }
}

include 'includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Checkout
        </h1>


        <?php if (!empty($errors)): ?>

            <div class="form-errors">

                <?php foreach ($errors as $error): ?>

                    <p>
                        <?php echo htmlspecialchars($error); ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <?php if ($success !== ''): ?>

            <div class="form-success">

                <p>
                    <?php echo htmlspecialchars($success); ?>
                </p>

                <a
                    href="index.php"
                    class="btn btn-primary"
                >
                    Continue Shopping
                </a>

            </div>

        <?php elseif (empty($cart_items)): ?>

            <p class="card-text">
                Your cart is empty.
            </p>

        <?php else: ?>

            <h2>
                Order Summary
            </h2>

            <?php foreach ($cart_items as $item): ?>

                <p>
                    <?php echo htmlspecialchars($item['title']); ?>
                    —
                    <?php echo htmlspecialchars($item['format']); ?>
                    —
                    <?php echo $item['quantity']; ?> ×
                    ₱<?php echo number_format($item['price'], 2); ?>
                </p>

            <?php endforeach; ?>


            <p>
                <strong>
                    Total:
                    ₱<?php echo number_format($total_amount, 2); ?>
                </strong>
            </p>


            <h2>
                Shipping Information
            </h2>


            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
                >


                <div class="form-group">

                    <label for="shipping_name">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="shipping_name"
                        name="shipping_name"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="shipping_mobile">
                        Mobile Number
                    </label>

                    <input
                        type="text"
                        id="shipping_mobile"
                        name="shipping_mobile"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="shipping_street">
                        Street Address
                    </label>

                    <input
                        type="text"
                        id="shipping_street"
                        name="shipping_street"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="shipping_city">
                        City
                    </label>

                    <input
                        type="text"
                        id="shipping_city"
                        name="shipping_city"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="shipping_province">
                        Province
                    </label>

                    <input
                        type="text"
                        id="shipping_province"
                        name="shipping_province"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="shipping_zip">
                        ZIP Code
                    </label>

                    <input
                        type="text"
                        id="shipping_zip"
                        name="shipping_zip"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Place Order
                </button>

            </form>

        <?php endif; ?>

    </div>

</section>

<?php include 'includes/footer.php'; ?>