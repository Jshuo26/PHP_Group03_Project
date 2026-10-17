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
$hasStockIssues = false;

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

    foreach ($items as &$item) {
    $quantity = (int) $item['quantity'];
    $stock = (int) $item['stock_quantity'];

    $item['stock_issue'] = '';

    if ($stock < 1) {
        $item['stock_issue'] = 'out_of_stock';
    } elseif ($quantity > $stock) {
        $item['stock_issue'] = 'quantity_exceeds_stock';
    } else {
        $subtotal += (float) $item['price'] * $quantity;
    }
}
unset($item);

foreach ($items as $item) {
    if ($item['stock_issue'] !== '') {
        $hasStockIssues = true;
        break;
    }
}
}

include __DIR__ . '/includes/header.php';
?>
 <section class="container cart-page">
    <div class="cart-page-heading">
        <div>
            <p class="cart-eyebrow">ALL YOUR FAVES</p>
            <h1 class="card-title">Your Cart</h1>
            <p class="cart-page-subtitle">Review your books before proceeding to checkout.</p>
        </div>
        <?php if ($items): ?>
            <span class="cart-item-count">
                <?= count($items) ?> <?= count($items) === 1 ? 'book' : 'books' ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="form-errors" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="form-success" role="status">
            <p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="cart-empty-state">
            <div class="cart-empty-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>
                    <path d="M8 7h8M8 11h6"/>
                </svg>
            </div>
            <h2>Your cart is waiting for a story.</h2>
            <p>You haven't added any books yet. Explore our collection and find your next read.</p>
            <a class="btn btn-primary" href="/books.php">Browse Books</a>
        </div>
    <?php else: ?>
        <div class="cart-layout">
            <div class="cart-items-list">
                <?php foreach ($items as $item): ?>
                    <article class="cart-item">
                        <div class="cart-item-cover">
                            <?php if (!empty($item['cover_image_path'])): ?>
                                <img
                                    src="/images/book-covers/<?= rawurlencode(basename($item['cover_image_path'])) ?>"
                                    alt="Cover of <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="cart-cover-placeholder">No cover available</div>
                            <?php endif; ?>
                        </div>

                        <div class="cart-item-details">
                            <form method="post" action="/cart.php" class="cart-remove-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                <button
                                    class="cart-remove-btn"
                                    type="submit"
                                    name="action"
                                    value="remove"
                                    aria-label="Remove <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?> from cart"
                                    title="Remove book"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                                        <path d="M18 6 6 18M6 6l12 12"/>
                                    </svg>
                                </button>
                            </form>
                            <h2><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="cart-item-author">
                                <?= htmlspecialchars($item['authors'] ?: 'Author not listed', ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <span class="cart-format">
                                <?= htmlspecialchars(ucfirst($item['format']), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <p class="cart-unit-price">
                                ₱<?= number_format((float) $item['price'], 2) ?> each
                            </p>

                            <?php if ($item['stock_issue'] === 'out_of_stock'): ?>
                                <p class="cart-stock out-of-stock" role="alert">
                                    This book is currently out of stock. Remove it from your cart to continue.
                                </p>
                            <?php elseif ($item['stock_issue'] === 'quantity_exceeds_stock'): ?>
                                <p class="cart-stock out-of-stock" role="alert">
                                    Only <?= (int) $item['stock_quantity'] ?> available.
                                    Please reduce your quantity.
                                </p>
                            <?php else: ?>
                                <p class="cart-stock in-stock">
                                    <?= (int) $item['stock_quantity'] ?> in stock
                                </p>
                            <?php endif; ?>
                        
                                <form method="post" action="/cart.php" class="cart-item-actions">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                    <input type="hidden" name="action" value="update">

                                    <div class="cart-quantity-field">
                                        <label for="quantity-<?= (int) $item['cart_item_id'] ?>">Qty</label>
                                        <input
                                            class="form-input cart-quantity"
                                            type="number"
                                            id="quantity-<?= (int) $item['cart_item_id'] ?>"
                                            name="quantity"
                                            min="1"
                                            max="<?= max(1, min(99, (int) $item['stock_quantity'])) ?>"                                            value="<?= (int) $item['quantity'] ?>"
                                            required
                                            onchange="this.form.requestSubmit()"
                                        >
                                    </div>
                                </form>
                        </div>

                        <div class="cart-item-total">
                            <span>Line total</span>
                            <strong>₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="cart-summary">
                <h2>Order Summary</h2>

                <div class="cart-summary-row">
                    <span><?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?></span>
                    <strong>₱<?= number_format($subtotal, 2) ?></strong>
                </div>

                <div class="cart-summary-row cart-subtotal-row">
                    <span>Subtotal</span>
                    <strong>₱<?= number_format($subtotal, 2) ?></strong>
                </div>

                <p class="cart-summary-note">Shipping and any applicable taxes are calculated at checkout.</p>
                    <?php if ($hasStockIssues): ?>
                        <p class="cart-stock out-of-stock" role="alert">
                            Please resolve the stock issues above before checking out.
                        </p>
                        <span class="btn btn-primary cart-checkout-btn" aria-disabled="true">
                            Continue to Checkout
                        </span>
                    <?php else: ?>
                        <a class="btn btn-primary cart-checkout-btn" href="/checkout.php">
                            Continue to Checkout
                        </a>
                    <?php endif; ?>
                <a class="cart-continue-shopping" href="/books.php">Continue shopping</a>
            </aside>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
