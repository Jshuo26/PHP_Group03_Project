<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';

requireRole(['admin', 'staff']);
$errors = [];
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?: 0;
    $status = requestString($_POST, 'status');
    if ($orderId < 1 || !in_array($status, $statuses, true)) {
        $errors[] = 'Select a valid order and status.';
    } else {
        $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?');
        $stmt->execute([$status, $orderId]);
        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare('SELECT 1 FROM orders WHERE order_id = ?');
            $existsStmt->execute([$orderId]);
            if (!$existsStmt->fetchColumn()) {
                $errors[] = 'Order not found.';
            }
        }
        if (!$errors) {
            $_SESSION['admin_flash'] = 'Order status updated.';
            header('Location: /admin/orders.php');
            exit;
        }
    }
}

$orders = $pdo->query(
    'SELECT o.order_id, o.order_number, o.shipping_name, o.shipping_mobile,
            o.shipping_street, o.shipping_city, o.shipping_province, o.shipping_zip,
            o.status, o.total_amount, o.placed_at, u.email,
            GROUP_CONCAT(CONCAT(b.title, " × ", oi.quantity) ORDER BY b.title SEPARATOR ", ") AS items
     FROM orders o
     JOIN users u ON u.user_id = o.user_id
     JOIN order_items oi ON oi.order_id = o.order_id
     JOIN book_editions e ON e.edition_id = oi.edition_id
     JOIN books b ON b.book_id = e.book_id
     GROUP BY o.order_id, o.order_number, o.shipping_name, o.shipping_mobile,
              o.shipping_street, o.shipping_city, o.shipping_province, o.shipping_zip,
              o.status, o.total_amount, o.placed_at, u.email
     ORDER BY o.placed_at DESC'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Order Management</h1>
    <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <?php if (!$orders): ?>
        <div class="card"><p class="card-text">There are no orders yet.</p></div>
    <?php else: foreach ($orders as $order): ?>
        <article class="card admin-list-item">
            <h2><?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><strong>Customer:</strong> <?= htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($order['email'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Ship to:</strong> <?= htmlspecialchars($order['shipping_street'] . ', ' . $order['shipping_city'] . ', ' . $order['shipping_province'] . ' ' . $order['shipping_zip'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($order['shipping_mobile'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Items:</strong> <?= htmlspecialchars($order['items'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Total:</strong> ₱<?= number_format((float) $order['total_amount'], 2) ?> · <?= htmlspecialchars($order['placed_at'], ENT_QUOTES, 'UTF-8') ?></p>
            <form method="post" action="/admin/orders.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                <label for="status-<?= (int) $order['order_id'] ?>">Status</label>
                <select class="form-input order-status" id="status-<?= (int) $order['order_id'] ?>" name="status" required>
                    <?php foreach ($statuses as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Update status</button>
            </form>
        </article>
    <?php endforeach; endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
