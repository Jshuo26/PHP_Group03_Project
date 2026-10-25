<?php
require_once __DIR__ . '/../includes/audit.php';

class CartController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getOrCreateCartId(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ?');
        $stmt->execute([$userId]);
        $cartId = (int) ($stmt->fetchColumn() ?: 0);

        if ($cartId < 1) {
            $stmt = $this->pdo->prepare('INSERT INTO carts (user_id) VALUES (?)');
            $stmt->execute([$userId]);
            $cartId = (int) $this->pdo->lastInsertId();
        }

        return $cartId;
    }

    public function getCart(int $userId): array
    {
        $cartStmt = $this->pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ?');
        $cartStmt->execute([$userId]);
        $cartId = (int) ($cartStmt->fetchColumn() ?: 0);

        if ($cartId < 1) {
            return [
                'cart_id'         => 0,
                'items'           => [],
                'subtotal'        => '0.00',
                'has_stock_issue' => false,
                'item_count'      => 0
            ];
        }

        $itemsStmt = $this->pdo->prepare(
            'SELECT ci.cart_item_id, ci.quantity, e.edition_id, e.format, e.price, e.stock_quantity,
                    e.cover_image_path, b.title, b.status AS book_status, b.review_status,
                    GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS authors
             FROM cart_items ci
             JOIN book_editions e ON e.edition_id = ci.edition_id
             JOIN books b ON b.book_id = e.book_id
             LEFT JOIN book_authors ba ON ba.book_id = b.book_id
             LEFT JOIN authors a ON a.author_id = ba.author_id
             WHERE ci.cart_id = ?
             GROUP BY ci.cart_item_id, ci.quantity, e.edition_id, e.format, e.price,
                      e.stock_quantity, e.cover_image_path, b.title, b.status, b.review_status
             ORDER BY b.title'
        );
        $itemsStmt->execute([$cartId]);
        $items = $itemsStmt->fetchAll();

        $subtotal = 0.0;
        $hasStockIssue = false;
        $totalQuantity = 0;

        foreach ($items as &$item) {
            $quantity = (int) $item['quantity'];
            $stock = (int) $item['stock_quantity'];
            $totalQuantity += $quantity;

            $item['stock_issue'] = '';
            $item['stock_issue'] = '';
            
            if ($item['book_status'] !== 'active' || $item['review_status'] !== 'approved') {
                $item['stock_issue'] = 'out_of_stock';
                $hasStockIssue = true;
            } elseif ($stock < 1) {
                $item['stock_issue'] = 'out_of_stock';
                $hasStockIssue = true;
            } elseif ($quantity > $stock) {
                $item['stock_issue'] = 'quantity_exceeds_stock';
                $hasStockIssue = true;
            } else {
                $subtotal += (float) $item['price'] * $quantity;
            }
        }
        unset($item);

        return [
            'cart_id'         => $cartId,
            'items'           => $items,
            'subtotal'        => number_format($subtotal, 2, '.', ''),
            'has_stock_issue' => $hasStockIssue,
            'item_count'      => $totalQuantity
        ];
    }

    public function addToCart(int $userId, int $editionId, int $quantity = 1): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Quantity must be between 1 and 99.');
        }

        try {
            $this->pdo->beginTransaction();

            $editionStmt = $this->pdo->prepare(
                "SELECT e.stock_quantity, b.title
                 FROM book_editions e
                 JOIN books b ON b.book_id = e.book_id
                 WHERE e.edition_id = ?
                   AND b.status = 'active'
                   AND b.review_status = 'approved'
                 FOR UPDATE"
            );
            $editionStmt->execute([$editionId]);
            $edition = $editionStmt->fetch();

            if (!$edition) {
                throw new RuntimeException('That book edition is not available.');
            }

            $stock = (int) $edition['stock_quantity'];
            if ($stock < 1) {
                throw new RuntimeException('This book is currently out of stock.');
            }

            $cartId = $this->getOrCreateCartId($userId);

            $itemStmt = $this->pdo->prepare(
                'SELECT cart_item_id, quantity FROM cart_items WHERE cart_id = ? AND edition_id = ? FOR UPDATE'
            );
            $itemStmt->execute([$cartId, $editionId]);
            $existing = $itemStmt->fetch();

            $existingQuantity = $existing ? (int) $existing['quantity'] : 0;
            if ($existingQuantity + $quantity > $stock) {
                throw new RuntimeException('Requested quantity exceeds available stock (' . $stock . ' available).');
            }

            if ($existing) {
                $updateStmt = $this->pdo->prepare(
                    'UPDATE cart_items SET quantity = quantity + ? WHERE cart_item_id = ?'
                );
                $updateStmt->execute([$quantity, (int) $existing['cart_item_id']]);
            } else {
                $insertStmt = $this->pdo->prepare(
                    'INSERT INTO cart_items (cart_id, edition_id, quantity) VALUES (?, ?, ?)'
                );
                $insertStmt->execute([$cartId, $editionId, $quantity]);
            }

            auditLogRequired($this->pdo, $existing ? 'cart_update' : 'cart_add', 'Customer changed a cart item quantity.', 'cart', $userId, 'cart_item', $existing ? (int) $existing['cart_item_id'] : (int) $this->pdo->lastInsertId(), 'success', 'customer');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateQuantity(int $userId, int $cartItemId, int $quantity): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Quantity must be between 1 and 99.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT ci.cart_item_id, ci.cart_id, e.stock_quantity, b.title
             FROM cart_items ci
             JOIN carts c ON c.cart_id = ci.cart_id
             JOIN book_editions e ON e.edition_id = ci.edition_id
             JOIN books b ON b.book_id = e.book_id
             WHERE ci.cart_item_id = ? AND c.user_id = ?'
        );
        $stmt->execute([$cartItemId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new RuntimeException('That cart item could not be found.');
        }

        if ($quantity > (int) $item['stock_quantity']) {
            throw new RuntimeException('Quantity exceeds available stock (' . (int) $item['stock_quantity'] . ' available).');
        }

        $updateStmt = $this->pdo->prepare('UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?');
        $updateStmt->execute([$quantity, $cartItemId]);
        auditLog($this->pdo, 'cart_update', 'Customer updated a cart item quantity.', 'cart', $userId, 'cart_item', $cartItemId, 'success', 'customer');
    }

    public function removeItem(int $userId, int $cartItemId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE ci FROM cart_items ci
             JOIN carts c ON c.cart_id = ci.cart_id
             WHERE ci.cart_item_id = ? AND c.user_id = ?'
        );
        $stmt->execute([$cartItemId, $userId]);
        $removed = $stmt->rowCount() > 0;
        if ($removed) {
            auditLog($this->pdo, 'cart_remove', 'Customer removed an item from the cart.', 'cart', $userId, 'cart_item', $cartItemId, 'success', 'customer');
        }
        return $removed;
    }

    public function clearCart(int $userId): void
    {
        $cartStmt = $this->pdo->prepare('SELECT cart_id FROM carts WHERE user_id = ?');
        $cartStmt->execute([$userId]);
        $cartId = (int) ($cartStmt->fetchColumn() ?: 0);

        if ($cartId > 0) {
            $delStmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
            $delStmt->execute([$cartId]);
            if ($delStmt->rowCount() > 0) {
                auditLog($this->pdo, 'cart_remove', 'Customer cleared the cart.', 'cart', $userId, 'cart', $cartId, 'success', 'customer');
            }
        }
    }
}