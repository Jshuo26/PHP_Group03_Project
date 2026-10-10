<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireRole(['customer']);
$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = $_SESSION['store_flash'] ?? '';
unset($_SESSION['store_flash']);

$cartStmt = $pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ?');
$cartStmt->execute([$userId]);
$cartId = (int) ($cartStmt->fetchColumn() ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $itemId = filter_input(INPUT_POST, 'cart_item_id', FILTER_VALIDATE_INT) ?: 0;

    if (!in_array($action, ['update', 'remove'], true)) {
        $errors[] = 'Choose a valid cart action.';
    } elseif ($itemId < 1 || $cartId < 1) {
        $errors[] = 'That cart item could not be found.';
    } elseif ($action === 'update') {
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity === null || $quantity < 1 || $quantity > 99) {
            $errors[] = 'Quantity must be between 1 and 99.';
        } else {
            $stockStmt = $pdo->prepare(
                'SELECT e.stock_quantity
                 FROM cart_items ci
                 JOIN book_editions e ON e.edition_id = ci.edition_id
                 WHERE ci.cart_item_id = ? AND ci.cart_id = ?'
            );
            $stockStmt->execute([$itemId, $cartId]);
            $stock = $stockStmt->fetchColumn();
            if ($stock === false) {
                $errors[] = 'That cart item could not be found.';
            } elseif ($quantity > (int) $stock) {
                $errors[] = 'Quantity exceeds the available stock.';
            } else {
                $updateStmt = $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE cart_item_id = ? AND cart_id = ?');
                $updateStmt->execute([$quantity, $itemId, $cartId]);
                $_SESSION['store_flash'] = 'Cart quantity updated.';
                header('Location: /cart.php');
                exit;
            }
        }
    } else {
        $deleteStmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_item_id = ? AND cart_id = ?');
        $deleteStmt->execute([$itemId, $cartId]);
        if ($deleteStmt->rowCount() !== 1) {
            $errors[] = 'That cart item could not be found.';
        } else {
            $_SESSION['store_flash'] = 'Book removed from your cart.';
            header('Location: /cart.php');
            exit;
        }
    }
}

$items = [];
$subtotal = 0.0;
if ($cartId > 0) {
    $itemsStmt = $pdo->prepare(
        'SELECT ci.cart_item_id, ci.quantity, e.edition_id, e.format, e.price, e.stock_quantity,
                e.cover_image_path, b.title,
                GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS authors
         FROM cart_items ci
         JOIN book_editions e ON e.edition_id = ci.edition_id
         JOIN books b ON b.book_id = e.book_id
         LEFT JOIN book_authors ba ON ba.book_id = b.book_id
         LEFT JOIN authors a ON a.author_id = ba.author_id
         WHERE ci.cart_id = ?
         GROUP BY ci.cart_item_id, ci.quantity, e.edition_id, e.format, e.price,
                  e.stock_quantity, e.cover_image_path, b.title
         ORDER BY b.title'
    );
    $itemsStmt->execute([$cartId]);
    $items = $itemsStmt->fetchAll();
    foreach ($items as $item) {
        $subtotal += (float) $item['price'] * (int) $item['quantity'];
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Your Cart</h1>
    <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <?php if (!$items): ?>
        <div class="card"><p class="card-text">Your cart is empty.</p><a class="btn btn-primary" href="/books.php">Browse books</a></div>
    <?php else: ?>
        <?php foreach ($items as $item): ?>
            <article class="card cart-item">
                <h2><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($item['authors'] ?: 'Author not listed', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst($item['format']), ENT_QUOTES, 'UTF-8') ?></p>
                <p>₱<?= number_format((float) $item['price'], 2) ?> each · <?= (int) $item['stock_quantity'] ?> in stock</p>
                <form method="post" action="/cart.php" class="cart-item-actions">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                    <label for="quantity-<?= (int) $item['cart_item_id'] ?>">Quantity</label>
                    <input class="form-input cart-quantity" type="number" id="quantity-<?= (int) $item['cart_item_id'] ?>" name="quantity" min="1" max="<?= min(99, (int) $item['stock_quantity']) ?>" value="<?= (int) $item['quantity'] ?>" required>
                    <button class="btn btn-primary" type="submit" name="action" value="update">Update</button>
                    <button class="btn btn-secondary" type="submit" name="action" value="remove">Remove</button>
                </form>
                <p><strong>Line total: ₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></strong></p>
            </article>
        <?php endforeach; ?>
        <div class="card cart-summary">
            <p><strong>Subtotal: ₱<?= number_format($subtotal, 2) ?></strong></p>
            <p>Shipping and taxes are calculated at checkout.</p>
            <a class="btn btn-primary" href="/checkout.php">Continue to checkout</a>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
