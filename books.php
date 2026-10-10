<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

$errors = [];
$query = trim(requestString($_GET, 'search'));
$genreId = filter_input(INPUT_GET, 'genre', FILTER_VALIDATE_INT) ?: 0;
$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if (strlen($query) > 255) {
    $query = substr($query, 0, 255);
    $errors[] = 'Search terms must be 255 characters or fewer.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $editionId = filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT) ?: 0;
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
        $errors[] = 'Please log in with a customer account before adding books to your cart.';
    }
    if ($editionId < 1) {
        $errors[] = 'Select a valid book edition.';
    }
    if ($quantity === false || $quantity === null || $quantity < 1 || $quantity > 99) {
        $errors[] = 'Quantity must be between 1 and 99.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $editionStmt = $pdo->prepare(
                "SELECT e.stock_quantity
                 FROM book_editions e
                 JOIN books b ON b.book_id = e.book_id
                 JOIN genres g ON g.genre_id = b.genre_id
                 WHERE e.edition_id = ? AND g.status = 'active'
                 FOR UPDATE"
            );
            $editionStmt->execute([$editionId]);
            $stock = $editionStmt->fetchColumn();
            if ($stock === false) {
                throw new RuntimeException('That book edition is no longer available.');
            }

            $cartStmt = $pdo->prepare('INSERT INTO carts (user_id) VALUES (?) ON DUPLICATE KEY UPDATE cart_id = LAST_INSERT_ID(cart_id)');
            $cartStmt->execute([(int) $_SESSION['user_id']]);
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
            $pdo->commit();

            $redirect = '/books.php';
            $params = array_filter(['search' => $query, 'genre' => $genreId ?: null, 'id' => $bookId ?: null]);
            if ($params) {
                $redirect .= '?' . http_build_query($params);
            }
            $_SESSION['store_flash'] = 'Book added to your cart.';
            header('Location: ' . $redirect);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Adding book to cart failed: ' . $e->getMessage());
            $errors[] = 'Unable to add this book to your cart right now.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$flash = $_SESSION['store_flash'] ?? '';
unset($_SESSION['store_flash']);
$genreStmt = $pdo->query("SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name");
$genres = $genreStmt->fetchAll();

$conditions = ["g.status = 'active'"];
$params = [];
if ($query !== '') {
    $conditions[] = '(b.title LIKE ? OR a.full_name LIKE ?)';
    $params[] = '%' . $query . '%';
    $params[] = '%' . $query . '%';
}
if ($genreId > 0) {
    $conditions[] = 'b.genre_id = ?';
    $params[] = $genreId;
}
if ($bookId > 0) {
    $conditions[] = 'b.book_id = ?';
    $params[] = $bookId;
}

$sql = 'SELECT b.book_id, b.title, b.description, b.isbn, g.genre_name,
               e.edition_id, e.format, e.price, e.stock_quantity, e.cover_image_path,
               GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS authors
        FROM books b
        JOIN genres g ON g.genre_id = b.genre_id
        JOIN book_editions e ON e.book_id = b.book_id
        LEFT JOIN book_authors ba ON ba.book_id = b.book_id
        LEFT JOIN authors a ON a.author_id = ba.author_id
        WHERE ' . implode(' AND ', $conditions) . '
        GROUP BY b.book_id, b.title, b.description, b.isbn, g.genre_name,
                 e.edition_id, e.format, e.price, e.stock_quantity, e.cover_image_path
        ORDER BY b.created_at DESC, b.title, e.format';
$bookStmt = $pdo->prepare($sql);
$bookStmt->execute($params);
$books = $bookStmt->fetchAll();

function catalogCover(?string $path): string
{
    $filename = basename(str_replace('\\', '/', (string) $path));
    return $filename !== '' ? '/images/book-covers/' . rawurlencode($filename) : '';
}

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Book Catalog</h1>
    <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($flash !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <form method="get" action="/books.php" class="card catalog-filters">
        <div class="form-group">
            <label for="search">Search title or author</label>
            <input class="form-input" type="search" id="search" name="search" maxlength="255" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label for="genre">Genre</label>
            <select class="form-input" id="genre" name="genre">
                <option value="">All genres</option>
                <?php foreach ($genres as $genre): ?>
                    <option value="<?= (int) $genre['genre_id'] ?>" <?= $genreId === (int) $genre['genre_id'] ? 'selected' : '' ?>><?= htmlspecialchars($genre['genre_name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($bookId > 0): ?><input type="hidden" name="id" value="<?= $bookId ?>"><?php endif; ?>
        <button class="btn btn-primary" type="submit">Search</button>
        <a class="btn btn-secondary" href="/books.php">Clear</a>
    </form>

    <?php if (!$books): ?>
        <div class="card"><p class="card-text">No books match your search.</p></div>
    <?php else: ?>
        <div class="catalog-grid">
            <?php foreach ($books as $book): ?>
                <article class="card catalog-card">
                    <?php if ($book['cover_image_path']): ?><img class="catalog-cover" src="<?= htmlspecialchars(catalogCover($book['cover_image_path']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?> cover" loading="lazy"><?php endif; ?>
                    <h2><?= htmlspecialchars($book['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><strong>Author:</strong> <?= htmlspecialchars($book['authors'] ?: 'Not listed', ENT_QUOTES, 'UTF-8') ?></p>
                    <p><strong>Genre:</strong> <?= htmlspecialchars($book['genre_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($book['isbn']): ?><p><strong>ISBN:</strong> <?= htmlspecialchars($book['isbn'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <?php if ($book['description']): ?><p><?= nl2br(htmlspecialchars($book['description'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                    <p><strong><?= htmlspecialchars(ucfirst($book['format']), ENT_QUOTES, 'UTF-8') ?></strong> · ₱<?= number_format((float) $book['price'], 2) ?></p>
                    <p><?= (int) $book['stock_quantity'] > 0 ? 'In stock: ' . (int) $book['stock_quantity'] : 'Currently out of stock' ?></p>
                    <?php if ((int) $book['stock_quantity'] > 0): ?>
                        <form method="post" action="/books.php<?= $bookId ? '?id=' . $bookId : '' ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="edition_id" value="<?= (int) $book['edition_id'] ?>">
                            <input type="hidden" name="quantity" value="1">
                            <button class="btn btn-primary" type="submit">Add to cart</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
