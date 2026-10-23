<?php

class OrderController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createPendingOrder(int $userId, array $shipping, string $paymentMethod = 'cod'): array
    {
        try {
            $this->pdo->beginTransaction();

            $cartStmt = $this->pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ? FOR UPDATE');
            $cartStmt->execute([$userId]);
            $cartId = (int) ($cartStmt->fetchColumn() ?: 0);
            if ($cartId < 1) {
                throw new RuntimeException('Your cart is empty.');
            }

            $itemsStmt = $this->pdo->prepare(
                'SELECT ci.cart_item_id, ci.edition_id, ci.quantity, e.price,
                        e.stock_quantity, b.title, e.format,    
                        b.status AS book_status, b.review_status
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
            $orderItemTitles = [];
            foreach ($items as $item) {
                if ($item['book_status'] !== 'active' || $item['review_status'] !== 'approved') {
                    throw new RuntimeException('"' . $item['title'] . '" is no longer available. Remove it from your cart and try again.');
                }

                $qty = (int) $item['quantity'];
                $stock = (int) $item['stock_quantity'];
                
                if ($qty < 1 || $qty > $stock) {
                    throw new RuntimeException('There is not enough stock for "' . $item['title'] . '". Update your cart and try again.');
                }
                $unitPriceCents = (int) round((float) $item['price'] * 100);
                $subtotalCents += $unitPriceCents * $qty;
                $orderItemTitles[] = $item['title'] . ' (' . ucfirst($item['format']) . ') x ' . $qty;
            }

            $subtotal = number_format($subtotalCents / 100, 2, '.', '');
            $shippingFee = '0.00';
            $taxAmount = '0.00';
            $totalAmount = $subtotal;
            $orderNumber = 'ORD-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

            $orderStmt = $this->pdo->prepare(
                'INSERT INTO orders
                    (user_id, order_number, shipping_name, shipping_mobile, shipping_street,
                     shipping_city, shipping_province, shipping_zip, status, subtotal,
                     shipping_fee, tax_amount, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?, ?, ?)'
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
            $orderId = (int) $this->pdo->lastInsertId();

            $orderItemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, edition_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
            );
            $stockStmt = $this->pdo->prepare(
                'UPDATE book_editions SET stock_quantity = stock_quantity - ?
                 WHERE edition_id = ? AND stock_quantity >= ?'
            );

            foreach ($items as $item) {
                $qty = (int) $item['quantity'];
                $editionId = (int) $item['edition_id'];

                $orderItemStmt->execute([
                    $orderId,
                    $editionId,
                    $qty,
                    $item['price']
                ]);

                $stockStmt->execute([$qty, $editionId, $qty]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Stock changed during checkout for "' . $item['title'] . '". Please review your cart.');
                }
            }

            $delStmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
            $delStmt->execute([$cartId]);

            $logStmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, description) VALUES (?, "create", ?)'
            );
            $logStmt->execute([$userId, 'Created order ' . $orderNumber . ' and reserved inventory']);

            $this->pdo->commit();

            return [
                'order_id'          => $orderId,
                'order_number'      => $orderNumber,
                'order_date'        => date('F j, Y'),
                'items_count'       => count($items),
                'titles'            => $orderItemTitles,
                'delivery_estimate' => date('F j, Y', strtotime('+4 weekdays')),
                'total_amount'      => $totalAmount,
                'payment_method'    => $paymentMethod
            ];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateOrderStatus(int $orderId, string $newStatus, ?int $actorUserId = null): void
    {
        $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($newStatus, $validStatuses, true)) {
            throw new InvalidArgumentException('Invalid order status.');
        }

        try {
            $this->pdo->beginTransaction();

            $orderStmt = $this->pdo->prepare(
                'SELECT order_id, order_number, status FROM orders WHERE order_id = ? FOR UPDATE'
            );
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();

            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            $currentStatus = $order['status'];
            if ($currentStatus === $newStatus) {
                $this->pdo->commit();
                return;
            }

            $activeStatuses = ['pending', 'processing', 'shipped', 'delivered'];
            $wasActive = in_array($currentStatus, $activeStatuses, true);
            $isBecomingActive = in_array($newStatus, $activeStatuses, true);

            $itemsStmt = $this->pdo->prepare(
                'SELECT oi.edition_id, oi.quantity, e.stock_quantity, b.title
                 FROM order_items oi
                 JOIN book_editions e ON e.edition_id = oi.edition_id
                 JOIN books b ON b.book_id = e.book_id
                 WHERE oi.order_id = ?
                 FOR UPDATE'
            );
            $itemsStmt->execute([$orderId]);
            $orderItems = $itemsStmt->fetchAll();

            if ($wasActive && $newStatus === 'cancelled') {
                $restoreStmt = $this->pdo->prepare(
                    'UPDATE book_editions SET stock_quantity = stock_quantity + ? WHERE edition_id = ?'
                );
                foreach ($orderItems as $item) {
                    $restoreStmt->execute([
                        (int) $item['quantity'],
                        (int) $item['edition_id']
                    ]);
                }
            }

            if ($currentStatus === 'cancelled' && $isBecomingActive) {
                foreach ($orderItems as $item) {
                    if ((int) $item['stock_quantity'] < (int) $item['quantity']) {
                        throw new RuntimeException(
                            'Cannot re-open order. Insufficient stock for "' . $item['title'] . '". ' .
                            '(Available: ' . (int) $item['stock_quantity'] . ', Required: ' . (int) $item['quantity'] . ')'
                        );
                    }
                }

                $deductStmt = $this->pdo->prepare(
                    'UPDATE book_editions SET stock_quantity = stock_quantity - ?
                     WHERE edition_id = ? AND stock_quantity >= ?'
                );
                foreach ($orderItems as $item) {
                    $deductStmt->execute([
                        (int) $item['quantity'],
                        (int) $item['edition_id'],
                        (int) $item['quantity']
                    ]);
                    if ($deductStmt->rowCount() !== 1) {
                        throw new RuntimeException('Stock update conflict for "' . $item['title'] . '". Please try again.');
                    }
                }
            }

            $updateStmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?');
            $updateStmt->execute([$newStatus, $orderId]);

            $logStmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, description) VALUES (?, "update", ?)'
            );
            $desc = 'Changed order ' . $order['order_number'] . ' status from ' . $currentStatus . ' to ' . $newStatus;
            $logStmt->execute([$actorUserId, $desc]);

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelOrderByCustomer(int $orderId, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT status, user_id FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || (int) $order['user_id'] !== $userId) {
            throw new RuntimeException('Order not found.');
        }

        if ($order['status'] !== 'pending') {
            throw new RuntimeException('Only pending orders can be cancelled.');
        }

        $this->updateOrderStatus($orderId, 'cancelled', $userId);
    }
}