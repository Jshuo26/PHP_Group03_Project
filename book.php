<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';
require_once __DIR__ . '/includes/catalog.php';

const RELATED_BOOKS_LIMIT = 4;

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$book   = null;

if ($bookId > 0) {
    $bookStmt = $pdo->prepare(
        "SELECT b.book_id, b.title, b.description, b.isbn, b.language, b.publisher
           FROM books b
          WHERE b.book_id = ?
            AND b.status = 'active'
            AND EXISTS (SELECT 1
                          FROM book_genres bg
                          JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
                         WHERE bg.book_id = b.book_id)"
    );
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();
}

if (!$book) {
    http_response_code(404);
    include __DIR__ . '/includes/header.php';
    ?>
    <section class="container">
        <div class="card">
            <h1 class="card-title">Book not found</h1>
            <p class="card-text">This book does not exist or is no longer available.</p>
            <a class="btn btn-primary" href="/books.php">Back to the catalog</a>
        </div>
    </section>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$editionStmt = $pdo->prepare(
    "SELECT edition_id, format, price, stock_quantity, cover_image_path
       FROM book_editions
      WHERE book_id = ?
      ORDER BY FIELD(format, 'paperback', 'hardbound', 'ebook')"
);
$editionStmt->execute([$bookId]);
$editions = $editionStmt->fetchAll();

$genreStmt = $pdo->prepare(
    "SELECT g.genre_id, g.genre_name
       FROM book_genres bg
       JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
      WHERE bg.book_id = ?
      ORDER BY g.genre_name"
);
$genreStmt->execute([$bookId]);
$bookGenres = $genreStmt->fetchAll();

$authorStmt = $pdo->prepare(
    'SELECT a.author_id, a.full_name
       FROM book_authors ba
       JOIN authors a ON a.author_id = ba.author_id
      WHERE ba.book_id = ?
      ORDER BY a.full_name'
);
$authorStmt->execute([$bookId]);
$bookAuthors = $authorStmt->fetchAll();

$detailUrl = '/book.php?id=' . $bookId;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireCsrfToken();

    $editionId  = filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT) ?: 0;
    $editionIds = array_map(static fn ($e) => (int) $e['edition_id'], $editions);

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
        $errors[] = 'Please log in with a customer account before adding books to your cart.';
    }

    if (!in_array($editionId, $editionIds, true)) {
        $errors[] = 'Select a valid edition of this book.';
    }

    if (!$errors) {
        try {
            addEditionToCart($pdo, (int) $_SESSION['user_id'], $editionId, 1);

            $_SESSION['store_flash'] = 'Book added to your cart.';

            header('Location: ' . $detailUrl);
            exit;

        } catch (PDOException $e) {
            error_log('Adding book to cart failed: ' . $e->getMessage());
            $errors[] = 'Unable to add this book to your cart right now.';
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$flash = (string) ($_SESSION['store_flash'] ?? '');
unset($_SESSION['store_flash']);

$relatedStmt = $pdo->prepare(
    "SELECT
        b.book_id,
        b.title,
        MIN(e.price)            AS min_price,
        MAX(e.price)            AS max_price,
        SUM(e.stock_quantity)   AS stock_total,
        MAX(e.cover_image_path) AS cover,
        (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
           FROM book_authors ba2
           JOIN authors a2 ON a2.author_id = ba2.author_id
          WHERE ba2.book_id = b.book_id) AS authors,
        (SELECT COUNT(*)
           FROM book_authors sa
          WHERE sa.book_id = b.book_id
            AND sa.author_id IN (SELECT author_id FROM book_authors WHERE book_id = ?)) AS shared_authors,
        (SELECT COUNT(*)
           FROM book_genres sg
           JOIN genres sgg ON sgg.genre_id = sg.genre_id AND sgg.status = 'active'
          WHERE sg.book_id = b.book_id
            AND sg.genre_id IN (SELECT genre_id FROM book_genres WHERE book_id = ?)) AS shared_genres
       FROM books b
       JOIN book_editions e ON e.book_id = b.book_id
      WHERE b.status = 'active'
        AND b.book_id <> ?
      GROUP BY b.book_id, b.title
     HAVING stock_total > 0
        AND (shared_authors > 0 OR shared_genres > 0)
      ORDER BY shared_authors DESC, shared_genres DESC, b.title ASC
      LIMIT " . RELATED_BOOKS_LIMIT
);
$relatedStmt->execute([$bookId, $bookId, $bookId]);
$related = $relatedStmt->fetchAll();

$cover = '';
foreach ($editions as $edition) {
    if (!empty($edition['cover_image_path'])) {
        $cover = catalogCover($edition['cover_image_path']);
        break;
    }
}

$canBuy = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer';

include __DIR__ . '/includes/header.php';
?>
<section class="container">

    <p><a href="/books.php">&laquo; Back to the catalog</a></p>

    <?php if ($errors): ?>
        <div class="form-errors" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= $h($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($flash !== ''): ?>
        <div class="form-success" role="status">
            <p><?= $h($flash) ?> <a href="/cart.php">View cart</a></p>
        </div>
    <?php endif; ?>

    <article class="card book-detail">

        <?php if ($cover !== ''): ?>
            <img class="book-detail-cover" src="<?= $h($cover) ?>" alt="<?= $h($book['title']) ?> cover">
        <?php endif; ?>

        <div class="book-detail-info">

            <h1 class="card-title"><?= $h($book['title']) ?></h1>

            <?php if ($bookAuthors): ?>
                <p>
                    <strong>Author:</strong>
                    <?php foreach ($bookAuthors as $i => $author): ?>
                        <?= $i > 0 ? ', ' : '' ?><a href="/books.php?search=<?= rawurlencode($author['full_name']) ?>"><?= $h($author['full_name']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <p>
                <strong>Genre:</strong>
                <?php foreach ($bookGenres as $i => $genre): ?>
                    <?= $i > 0 ? ', ' : '' ?><a href="/books.php?genre=<?= (int) $genre['genre_id'] ?>"><?= $h($genre['genre_name']) ?></a>
                <?php endforeach; ?>
            </p>

            <p><strong>Language:</strong> <?= $h($book['language']) ?></p>

            <?php if ($book['publisher']): ?>
                <p><strong>Publisher:</strong> <?= $h($book['publisher']) ?></p>
            <?php endif; ?>

            <?php if ($book['isbn']): ?>
                <p><strong>ISBN:</strong> <?= $h($book['isbn']) ?></p>
            <?php endif; ?>

            <?php if ($book['description']): ?>
                <h2>About this book</h2>
                <p><?= nl2br($h($book['description'])) ?></p>
            <?php endif; ?>

            <h2>Available editions</h2>

            <?php if (!$editions): ?>
                <p>No editions are available right now.</p>
            <?php else: ?>
                <div class="book-editions">
                    <?php foreach ($editions as $edition): ?>
                        <div class="book-edition">
                            <strong><?= $h(ucfirst($edition['format'])) ?></strong>
                            <span>&#8369;<?= number_format((float) $edition['price'], 2) ?></span>

                            <?php if ((int) $edition['stock_quantity'] > 0): ?>
                                <span class="stock-badge stock-in">In stock</span>

                                <?php if ($canBuy || !isset($_SESSION['user_id'])): ?>
                                    <form method="post" action="<?= $h($detailUrl) ?>" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= $h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="edition_id" value="<?= (int) $edition['edition_id'] ?>">
                                        <button class="btn btn-primary" type="submit">Add to cart</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="stock-badge stock-out">Out of stock</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

    </article>

    <?php if ($related): ?>

        <h2 class="section-title">You may also like</h2>

        <div class="catalog-grid">

            <?php foreach ($related as $item): ?>

                <?php
                $itemCover  = catalogCover($item['cover']);
                $itemDetail = '/book.php?id=' . (int) $item['book_id'];
                ?>

                <article class="card catalog-card">

                    <?php if ($itemCover !== ''): ?>
                        <a href="<?= $h($itemDetail) ?>">
                            <img class="catalog-cover" src="<?= $h($itemCover) ?>" alt="<?= $h($item['title']) ?> cover" loading="lazy">
                        </a>
                    <?php endif; ?>

                    <h3><a href="<?= $h($itemDetail) ?>"><?= $h($item['title']) ?></a></h3>

                    <p><strong>Author:</strong> <?= $h($item['authors'] ?: 'Not listed') ?></p>
                    <p class="catalog-price"><?= $h(catalogPrice($item['min_price'], $item['max_price'])) ?></p>

                    <div class="catalog-actions">
                        <a class="btn btn-secondary" href="<?= $h($itemDetail) ?>">View details</a>
                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>
<?php include __DIR__ . '/includes/footer.php'; ?>