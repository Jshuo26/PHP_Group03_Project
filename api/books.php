
<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/author_verification.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';
require_once __DIR__ . '/../includes/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function apiResponse(int $status, array $body): void
{
    http_response_code($status);

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function apiBookId($value)
{
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id > 0) ? $id : false;
}

function apiReadJson(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Request body must contain valid JSON.'
        ]);
    }

    return $data;
}

function apiCoverUrl($path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }

    $url = catalogCover((string) $path);
    return $url !== '' ? $url : null;
}

function apiFormatBooks(array &$books): void
{
    foreach ($books as &$book) {
        $book['book_id'] = (int) $book['book_id'];

        if (isset($book['edition_id'])) {
            $book['edition_id'] = (int) $book['edition_id'];
        }

        if (isset($book['stock_quantity'])) {
            $book['stock_quantity'] = (int) $book['stock_quantity'];
        }

        if (array_key_exists('author_names', $book)) {
            $book['authors'] = $book['author_names'] !== null
                && $book['author_names'] !== ''
                    ? explode('||', $book['author_names'])
                    : [];

            unset($book['author_names']);
        }

        if (array_key_exists('genre_names', $book)) {
            $book['genres'] = $book['genre_names'] !== null
                && $book['genre_names'] !== ''
                    ? explode('||', $book['genre_names'])
                    : [];

            unset($book['genre_names']);
        }

        if (array_key_exists('cover_image_path', $book)) {
            $book['cover_image_url'] = apiCoverUrl(
                $book['cover_image_path']
            );

            unset($book['cover_image_path']);
        }
    }

    unset($book);
}

function apiValidateBook(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $isbn = trim((string) ($input['isbn'] ?? ''));
    $language = trim((string) ($input['language'] ?? 'English'));
    $publisher = trim((string) ($input['publisher'] ?? ''));
    $status = (string) ($input['status'] ?? 'active');
    $format = (string) ($input['format'] ?? 'paperback');
    $price = $input['price'] ?? null;
    $stock = $input['stock_quantity'] ?? null;
    $author = trim((string) ($input['author'] ?? ''));
    $genreIds = $input['genre_ids'] ?? [];

    if ($title === '' || strlen($title) > 255) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Title is required and must not exceed 255 bytes.'
        ]);
    }

    if (strlen($description) > 65535) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Description is too long.'
        ]);
    }

    if (
        strlen($isbn) > 20 ||
        strlen($language) > 50 ||
        strlen($publisher) > 150
    ) {
        apiResponse(400, [
            'success' => false,
            'message' => 'ISBN, language, or publisher exceeds its allowed length.'
        ]);
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Invalid book status.'
        ]);
    }

    if (!in_array($format, ['paperback', 'hardbound', 'ebook'], true)) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Invalid book format.'
        ]);
    }

    if (
        !is_scalar($price) ||
        !preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $price)
    ) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Invalid price.'
        ]);
    }

    if (
        filter_var($stock, FILTER_VALIDATE_INT) === false ||
        (int) $stock < 0
    ) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Stock quantity must be a non-negative integer.'
        ]);
    }

    $authorNames = parseAuthorNames($author);
    if ($author !== '' && ($authorError = validateAuthorNames($authorNames)) !== null) {
        apiResponse(400, ['success' => false, 'message' => $authorError]);
    }

    if (!is_array($genreIds)) {
        apiResponse(400, [
            'success' => false,
            'message' => 'genre_ids must be an array.'
        ]);
    }

    $cleanGenreIds = [];

    foreach ($genreIds as $genreId) {
        $validId = filter_var($genreId, FILTER_VALIDATE_INT);

        if ($validId === false || $validId < 1) {
            apiResponse(400, [
                'success' => false,
                'message' => 'Each genre ID must be a positive integer.'
            ]);
        }

        $cleanGenreIds[] = (int) $validId;
    }

    return [
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'isbn' => $isbn !== '' ? $isbn : null,
        'language' => $language,
        'publisher' => $publisher !== '' ? $publisher : null,
        'status' => $status,
        'format' => $format,
        'price' => $price,
        'stock_quantity' => (int) $stock,
        'author' => $author,
        'genre_ids' => array_values(array_unique($cleanGenreIds))
    ];
}

function apiSaveRelations(PDO $pdo, int $bookId, array $data): string
{
    foreach ($data['genre_ids'] as $genreId) {
        $stmt = $pdo->prepare(
            "SELECT genre_id
             FROM genres
             WHERE genre_id = ? AND status = 'active'"
        );
        $stmt->execute([$genreId]);

        if (!$stmt->fetchColumn()) {
            apiResponse(400, [
                'success' => false,
                'message' => 'A genre ID is invalid or inactive.'
            ]);
        }
    }

    $stmt = $pdo->prepare(
        'DELETE FROM book_genres WHERE book_id = ?'
    );
    $stmt->execute([$bookId]);

    $stmt = $pdo->prepare(
        'INSERT INTO book_genres (book_id, genre_id) VALUES (?, ?)'
    );

    foreach ($data['genre_ids'] as $genreId) {
        $stmt->execute([$bookId, $genreId]);
    }

    if ($data['author'] !== '') {
        linkBookAuthors($pdo, $bookId, parseAuthorNames($data['author']), (int) ($_SESSION['user_id'] ?? 0));
    }

    return refreshBookReviewStatus($pdo, $bookId);
}

$method = $_SERVER['REQUEST_METHOD'];

if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, DELETE');

    apiResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    if (!isLoggedIn()) {
        apiResponse(401, [
            'success' => false,
            'message' => 'Please log in first.'
        ]);
    }

    if (!hasRole(['admin', 'staff'])) {
        apiResponse(403, [
            'success' => false,
            'message' => 'You do not have permission to manage books.'
        ]);
    }

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        apiResponse(403, [
            'success' => false,
            'message' => 'Invalid or expired CSRF token.'
        ]);
    }

    $bookId = null;

    if ($method === 'PUT' || $method === 'DELETE') {
        $bookId = apiBookId($_GET['id'] ?? null);

        if ($bookId === false) {
            apiResponse(400, [
                'success' => false,
                'message' => 'A valid book ID is required.'
            ]);
        }
    }

    $input = [];

    if ($method !== 'DELETE') {
        $input = apiReadJson();
        $data = apiValidateBook($input);
        if ($method === 'POST' && ($data['author'] === '' || !$data['genre_ids'])) {
             apiResponse(400, ['success' => false, 'message' => 'An author and at least one genre are required.']);
        }
    }

    try {
        $pdo->beginTransaction();

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare(
                'DELETE FROM books WHERE book_id = ?'
            );
            $stmt->execute([$bookId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();

                apiResponse(404, [
                    'success' => false,
                    'message' => 'Book not found.'
                ]);
            }

            auditLogRequired($pdo, 'delete', 'Staff deleted a product through the books API.', 'products', (int) $_SESSION['user_id'], 'book', (int) $bookId, 'success', (string) $_SESSION['role']);
            $pdo->commit();

            apiResponse(200, [
                'success' => true,
                'message' => 'Book deleted successfully.',
                'book_id' => (int) $bookId
            ]);
        }

        if ($method === 'POST') {
            $stmt = $pdo->prepare(
                'INSERT INTO books
                    (title, description, isbn, language, publisher, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $data['title'],
                $data['description'],
                $data['isbn'],
                $data['language'],
                $data['publisher'],
                $data['status']
            ]);

            $bookId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO book_editions
                    (book_id, format, price, stock_quantity, low_stock_threshold)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $bookId,
                $data['format'],
                $data['price'],
                $data['stock_quantity'],
                getLowStockThreshold($pdo)
            ]);
            syncEditionLowStockAlert($pdo, (int) $pdo->lastInsertId());
        } else {
            $stmt = $pdo->prepare(
                'SELECT book_id FROM books WHERE book_id = ?'
            );
            $stmt->execute([$bookId]);

            if (!$stmt->fetchColumn()) {
                $pdo->rollBack();

                apiResponse(404, [
                    'success' => false,
                    'message' => 'Book not found.'
                ]);
            }

            $stmt = $pdo->prepare(
                'UPDATE books
                 SET title = ?, description = ?, isbn = ?,
                     language = ?, publisher = ?, status = ?
                 WHERE book_id = ?'
            );

            $stmt->execute([
                $data['title'],
                $data['description'],
                $data['isbn'],
                $data['language'],
                $data['publisher'],
                $data['status'],
                $bookId
            ]);

            $stmt = $pdo->prepare(
                'SELECT edition_id
                 FROM book_editions
                 WHERE book_id = ? AND format = ?
                 LIMIT 1'
            );
            $stmt->execute([$bookId, $data['format']]);
            $editionId = $stmt->fetchColumn();

            if ($editionId !== false) {
                $stmt = $pdo->prepare(
                    'UPDATE book_editions
                     SET price = ?, stock_quantity = ?
                     WHERE edition_id = ?'
                );
                $stmt->execute([
                    $data['price'],
                    $data['stock_quantity'],
                    $editionId
                ]);
                syncEditionLowStockAlert($pdo, (int) $editionId);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO book_editions
                        (book_id, format, price, stock_quantity, low_stock_threshold)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $bookId,
                    $data['format'],
                    $data['price'],
                    $data['stock_quantity'],
                    getLowStockThreshold($pdo)
                ]);
                syncEditionLowStockAlert($pdo, (int) $pdo->lastInsertId());
            }
        }

        $reviewStatus = apiSaveRelations($pdo, $bookId, $data);
        auditLogRequired($pdo, $method === 'POST' ? 'create' : 'update', $method === 'POST' ? 'Staff created a product through the books API.' : 'Staff updated a product through the books API.', 'products', (int) $_SESSION['user_id'], 'book', (int) $bookId, 'success', (string) $_SESSION['role']);
        $pdo->commit();
        apiResponse($method === 'POST' ? 201 : 200, [
            'success' => true,
            'message' => $method === 'POST'
                ? 'Book created successfully.'
                : 'Book updated successfully.',
            'book_id' => (int) $bookId,
            'review_status' => $reviewStatus
        ]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Books API write error: ' . $e->getMessage());

        if ($e->getCode() === '23000') {
            apiResponse(409, [
                'success' => false,
                'message' => 'The request conflicts with existing data, such as a duplicate ISBN or edition, or a book referenced by another record.'
            ]);
        }

        apiResponse(500, [
            'success' => false,
            'message' => 'Unable to save the book.'
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Books API write error: ' . $e->getMessage());

        apiResponse(500, [
            'success' => false,
            'message' => 'Unable to process the request.'
        ]);
    }
}

$search = trim((string) ($_GET['search'] ?? ''));

if (strlen($search) > 255) {
    apiResponse(400, [
        'success' => false,
        'message' => 'Search must not exceed 255 bytes.'
    ]);
}

$format = trim((string) ($_GET['format'] ?? ''));
$language = trim((string) ($_GET['language'] ?? ''));

$validFormats = ['paperback', 'hardbound', 'ebook'];

if ($format !== '' && !in_array($format, $validFormats, true)) {
    apiResponse(400, [
        'success' => false,
        'message' => 'Invalid book format.'
    ]);
}

if (strlen($language) > 50) {
    apiResponse(400, [
        'success' => false,
        'message' => 'Language must not exceed 50 bytes.'
    ]);
}

$genreId = null;

if (isset($_GET['genre_id']) && $_GET['genre_id'] !== '') {
    $genreId = filter_var($_GET['genre_id'], FILTER_VALIDATE_INT);

    if ($genreId === false || $genreId < 1) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Invalid genre ID.'
        ]);
    }
}

$minPrice = $_GET['min_price'] ?? null;
$maxPrice = $_GET['max_price'] ?? null;

foreach (['min_price' => $minPrice, 'max_price' => $maxPrice] as $name => $value) {
    if (
        $value !== null &&
        (
            !is_scalar($value) ||
            !preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $value)
        )
    ) {
        apiResponse(400, [
            'success' => false,
            'message' => "Invalid $name value."
        ]);
    }
}

if (
    $minPrice !== null &&
    $maxPrice !== null &&
    (float) $minPrice > (float) $maxPrice
) {
    apiResponse(400, [
        'success' => false,
        'message' => 'Minimum price cannot exceed maximum price.'
    ]);
}

$inStock = (string) ($_GET['in_stock'] ?? '');

if (!in_array($inStock, ['', '0', '1'], true)) {
    apiResponse(400, [
        'success' => false,
        'message' => 'in_stock must be 0 or 1.'
    ]);
}

$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
$perPage = filter_var($_GET['per_page'] ?? '12', FILTER_VALIDATE_INT);

if ($page === false || $page < 1) {
    apiResponse(400, [
        'success' => false,
        'message' => 'Page must be a positive integer.'
    ]);
}

if ($perPage === false || $perPage < 1 || $perPage > 100) {
    apiResponse(400, [
        'success' => false,
        'message' => 'per_page must be between 1 and 100.'
    ]);
}

$offset = ($page - 1) * $perPage;

$sort = (string) ($_GET['sort'] ?? 'newest');

$sortOptions = [
    'newest' => 'b.created_at DESC, b.book_id DESC, e.edition_id DESC',
    'title_asc' => 'b.title ASC, e.edition_id ASC',
    'price_asc' => 'e.price ASC, b.title ASC',
    'price_desc' => 'e.price DESC, b.title ASC'
];

if (!isset($sortOptions[$sort])) {
    apiResponse(400, [
        'success' => false,
        'message' => 'Invalid sort option.',
        'allowed_sorts' => array_keys($sortOptions)
    ]);
}

if (isset($_GET['id'])) {
    $bookId = apiBookId($_GET['id']);

    if ($bookId === false) {
        apiResponse(400, [
            'success' => false,
            'message' => 'Invalid book ID.'
        ]);
    }

    try {
        $check = $pdo->prepare(
            "SELECT book_id FROM books
             WHERE book_id = ? 
             AND status = 'active'
             AND review_status = 'approved'"
        );
        $check->execute([$bookId]);

        if (!$check->fetchColumn()) {
            apiResponse(404, [
                'success' => false,
                'message' => 'Book not found.'
            ]);
        }

        if (($_GET['related'] ?? '') === '1') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    b.book_id,
                    b.title,
                    b.description,
                    b.isbn,
                    b.language,
                    b.publisher,
                    b.created_at,
                    e.edition_id,
                    e.format,
                    e.price,
                    e.stock_quantity,
                    e.cover_image_path
                FROM books b
                JOIN book_editions e ON e.book_id = b.book_id
                WHERE b.status = 'active'
                  AND b.review_status = 'approved'
                  AND b.book_id <> ?
                  AND (
                      EXISTS (
                          SELECT 1
                          FROM book_authors ca
                          JOIN book_authors oa
                            ON oa.author_id = ca.author_id
                          WHERE ca.book_id = ?
                            AND oa.book_id = b.book_id
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM book_genres cg
                          JOIN book_genres og
                            ON og.genre_id = cg.genre_id
                          JOIN genres g
                            ON g.genre_id = og.genre_id
                          WHERE cg.book_id = ?
                            AND og.book_id = b.book_id
                            AND g.status = 'active'
                      )
                  )
                ORDER BY b.title ASC, e.edition_id ASC
                LIMIT 12
            ");

            $stmt->execute([$bookId, $bookId, $bookId]);
            $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

            apiFormatBooks($books);

            apiResponse(200, [
                'success' => true,
                'book_id' => (int) $bookId,
                'data' => $books
            ]);
        }

        $sql = "
            SELECT
                b.book_id,
                e.edition_id,
                b.title,
                b.description,
                b.isbn,
                b.language,
                b.publisher,
                b.created_at,
                e.format,
                e.price,
                e.stock_quantity,
                e.cover_image_path,

                (
                    SELECT GROUP_CONCAT(
                        DISTINCT a.full_name
                        ORDER BY a.full_name SEPARATOR '||'
                    )
                    FROM book_authors ba
                    JOIN authors a ON a.author_id = ba.author_id
                    WHERE ba.book_id = b.book_id
                ) AS author_names,

                (
                    SELECT GROUP_CONCAT(
                        DISTINCT g.genre_name
                        ORDER BY g.genre_name SEPARATOR '||'
                    )
                    FROM book_genres bg
                    JOIN genres g ON g.genre_id = bg.genre_id
                    WHERE bg.book_id = b.book_id
                      AND g.status = 'active'
                ) AS genre_names

            FROM books b
            LEFT JOIN book_editions e ON e.book_id = b.book_id
            WHERE b.book_id = ?
              AND b.status = 'active'
              AND b.review_status = 'approved'
            ORDER BY e.edition_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$bookId]);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        apiFormatBooks($books);

        apiResponse(200, [
            'success' => true,
            'data' => $books
        ]);

    } catch (Throwable $e) {
        error_log('Book details API failed: ' . $e->getMessage());

        apiResponse(500, [
            'success' => false,
            'message' => 'Unable to retrieve book details.'
        ]);
    }
}

$where = ["b.status = 'active'", "b.review_status = 'approved'"];
$params = [];

if ($search !== '') {
    $escapedSearch = str_replace(
        ['!', '%', '_'],
        ['!!', '!%', '!_'],
        $search
    );

    $searchPattern = '%' . $escapedSearch . '%';

    $where[] = "(
        b.title LIKE ? ESCAPE '!'
        OR b.isbn LIKE ? ESCAPE '!'
        OR b.publisher LIKE ? ESCAPE '!'
        OR b.description LIKE ? ESCAPE '!'
        OR EXISTS (
            SELECT 1
            FROM book_authors ba_search
            JOIN authors a_search
                ON a_search.author_id = ba_search.author_id
            WHERE ba_search.book_id = b.book_id
              AND a_search.full_name LIKE ? ESCAPE '!'
        )
    )";

    for ($i = 0; $i < 5; $i++) {
        $params[] = $searchPattern;
    }
}

if ($format !== '') {
    $where[] = 'e.format = ?';
    $params[] = $format;
}

if ($language !== '') {
    $where[] = 'b.language = ?';
    $params[] = $language;
}

if ($genreId !== null) {
    $where[] = "EXISTS (
        SELECT 1
        FROM book_genres bg_filter
        JOIN genres g_filter
            ON g_filter.genre_id = bg_filter.genre_id
        WHERE bg_filter.book_id = b.book_id
          AND g_filter.genre_id = ?
          AND g_filter.status = 'active'
    )";

    $params[] = $genreId;
}

if ($minPrice !== null) {
    $where[] = 'e.price >= ?';
    $params[] = $minPrice;
}

if ($maxPrice !== null) {
    $where[] = 'e.price <= ?';
    $params[] = $maxPrice;
}

if ($inStock === '1') {
    $where[] = 'e.stock_quantity > 0';
} elseif ($inStock === '0') {
    $where[] = 'e.stock_quantity = 0';
}

$whereSql = implode(' AND ', $where);

try {
    $countSql = "
        SELECT COUNT(*)
        FROM books b
        JOIN book_editions e ON e.book_id = b.book_id
        WHERE $whereSql
    ";

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            b.book_id,
            e.edition_id,
            b.title,
            b.description,
            b.isbn,
            b.language,
            b.publisher,
            b.created_at,
            e.format,
            e.price,
            e.stock_quantity,
            e.cover_image_path,

            (
                SELECT GROUP_CONCAT(
                    DISTINCT a.full_name
                    ORDER BY a.full_name SEPARATOR '||'
                )
                FROM book_authors ba
                JOIN authors a ON a.author_id = ba.author_id
                WHERE ba.book_id = b.book_id
            ) AS author_names,

            (
                SELECT GROUP_CONCAT(
                    DISTINCT g.genre_name
                    ORDER BY g.genre_name SEPARATOR '||'
                )
                FROM book_genres bg
                JOIN genres g ON g.genre_id = bg.genre_id
                WHERE bg.book_id = b.book_id
                  AND g.status = 'active'
            ) AS genre_names

        FROM books b
        JOIN book_editions e ON e.book_id = b.book_id
        WHERE $whereSql
        ORDER BY {$sortOptions[$sort]}
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apiFormatBooks($books);

    apiResponse(200, [
        'success' => true,
        'data' => $books,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage)
        ],
        'filters' => [
            'search' => $search,
            'genre_id' => $genreId,
            'format' => $format,
            'language' => $language,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'in_stock' => $inStock,
            'sort' => $sort
        ]
    ]);

} catch (Throwable $e) {
    error_log('Catalog API failed: ' . $e->getMessage());

    apiResponse(500, [
        'success' => false,
        'message' => 'Unable to retrieve catalog data.'
    ]);
}
