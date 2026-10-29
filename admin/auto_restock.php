<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

requireRole(['admin', 'staff']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin/products.php');
    exit;
}
requireCsrfToken();
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
if ($quantity === false || $quantity === null || $quantity < 1 || $quantity > 5000) {
    http_response_code(422);
    echo 'Enter a restock quantity between 1 and 5,000.';
    exit;
}

$stmt = $pdo->prepare("SELECT b.title, COALESCE(NULLIF(b.isbn, ''), NULLIF(e.isbn, ''), '') AS isbn,
        b.publisher, b.description, b.language, e.format,
        GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR '; ') AS authors,
        GROUP_CONCAT(DISTINCT a.bio ORDER BY a.bio SEPARATOR '; ') AS author_description
    FROM book_editions e
    JOIN books b ON b.book_id = e.book_id
    LEFT JOIN book_authors ba ON ba.book_id = b.book_id
    LEFT JOIN authors a ON a.author_id = ba.author_id
    WHERE b.status = 'active' AND b.review_status = 'approved'
      AND e.stock_quantity <= e.low_stock_threshold + 2
    GROUP BY b.book_id, e.edition_id, b.title, b.isbn, e.isbn, b.publisher, b.description, b.language, e.format
    ORDER BY e.stock_quantity ASC, b.title ASC");
$stmt->execute();
$books = $stmt->fetchAll();

auditLog(
    $pdo,
    'create',
    'Generated demo auto-restock order CSV for ' . count($books) . ' book edition(s), ' . $quantity . ' copies each. Inventory was not changed.',
    'products',
    (int) $_SESSION['user_id'],
    'restock_order',
    null,
    'success',
    (string) $_SESSION['role']
);

$filename = 'aklaaat-auto-restock-' . date('Y-m-d-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
$output = fopen('php://output', 'wb');
fputcsv($output, ['title', 'author', 'author_description', 'isbn', 'format', 'quantity', 'cost', 'price', 'publisher', 'description', 'language']);
foreach ($books as $book) {
    fputcsv($output, [
        (string) $book['title'],
        (string) ($book['authors'] ?: 'Author unknown'),
        (string) ($book['author_description'] ?? ''),
        (string) ($book['isbn'] ?? ''),
        (string) $book['format'],
        $quantity,
        '',
        '',
        (string) ($book['publisher'] ?? ''),
        (string) ($book['description'] ?? ''),
        (string) ($book['language'] ?? ''),
    ]);
}
fclose($output);
exit;
