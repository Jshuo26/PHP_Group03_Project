<?php
require_once __DIR__ . '/audit.php';

function catalogCover(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') return '';

    if (filter_var($path, FILTER_VALIDATE_URL) !== false) {
        $parts = parse_url($path);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHost = $host === 'books.google.com' || $host === 'books.googleusercontent.com'
            || str_ends_with($host, '.books.googleusercontent.com');
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' && $allowedHost ? $path : '';
    }

    $filename = basename(str_replace('\\', '/', $path));

    return $filename !== '' ? '/images/book-covers/' . rawurlencode($filename) : '';
}

function catalogEscapeLike(string $text): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
}

function catalogUrl(string $path, array $params): string
{
    $params = array_filter(
        $params,
        static fn ($value) => $value !== '' && $value !== null && $value !== false
    );

    return $params ? $path . '?' . http_build_query($params) : $path;
}

function catalogPrice($min, $max): string
{
    $text = '₱' . number_format((float) $min, 2);

    return (float) $min === (float) $max ? $text : 'From ' . $text;
}

function addEditionToCart(PDO $pdo, int $userId, int $editionId, int $quantity): void
{
    try {

        $pdo->beginTransaction();

        $editionStmt = $pdo->prepare(
            "SELECT e.stock_quantity
               FROM book_editions e
               JOIN books b ON b.book_id = e.book_id
              WHERE e.edition_id = ?
                AND b.status = 'active'
                AND b.review_status = 'approved'
                AND EXISTS (
                    SELECT 1
                      FROM book_genres bg
                      JOIN genres g ON g.genre_id = bg.genre_id
                     WHERE bg.book_id = b.book_id AND g.status = 'active'
                )
                FOR UPDATE"
        );

        $editionStmt->execute([$editionId]);

        $stock = $editionStmt->fetchColumn();

        if ($stock === false) {
            throw new RuntimeException('That book edition is no longer available.');
        }

        $cartStmt = $pdo->prepare(
            'INSERT INTO carts (user_id) VALUES (?)
             ON DUPLICATE KEY UPDATE cart_id = LAST_INSERT_ID(cart_id)'
        );

        $cartStmt->execute([$userId]);

        $cartId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'SELECT quantity FROM cart_items WHERE cart_id = ? AND edition_id = ? FOR UPDATE'
        );

        $itemStmt->execute([$cartId, $editionId]);

        $existingQuantity = (int) ($itemStmt->fetchColumn() ?: 0);

        if ($existingQuantity + $quantity > (int) $stock) {
            throw new RuntimeException('The requested quantity exceeds the available stock.');
        }

        $saveStmt = $pdo->prepare(
            'INSERT INTO cart_items (cart_id, edition_id, quantity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        );

        $saveStmt->execute([$cartId, $editionId, $quantity]);
        auditLogRequired($pdo, $existingQuantity > 0 ? 'cart_update' : 'cart_add', 'Customer added a book edition to the cart.', 'cart', $userId, 'edition', $editionId, 'success', 'customer');

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
