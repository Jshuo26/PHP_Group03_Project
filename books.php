<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';
require_once __DIR__ . '/includes/catalog.php';

$legacyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($legacyId && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: /book.php?id=' . $legacyId);
    exit;
}

const CATALOG_PER_PAGE = 12;

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$errors = [];
$formats = ['paperback', 'hardbound', 'ebook'];

$sorts = [
    'newest' => ['Newest first', 'b.created_at DESC, b.title ASC'],
    'title' => ['Title A to Z', 'b.title ASC'],
    'price_asc' => ['Price: low to high', 'MIN(e.price) ASC, b.title ASC'],
    'price_desc' => ['Price: high to low', 'MIN(e.price) DESC, b.title ASC'],
];

$genres = $pdo->query(
    "SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name"
)->fetchAll();

$languages = $pdo->query(
    "SELECT DISTINCT language FROM books WHERE status = 'active' AND review_status = 'approved' AND language IS NOT NULL AND language <> '' ORDER BY language"  // CHANGED
)->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('Filipino', $languages, true)) {
    $languages[] = 'Filipino';
}
sort($languages, SORT_NATURAL | SORT_FLAG_CASE);

$query = trim(requestString($_GET, 'search'));

if (mb_strlen($query) > 255) {
    $query = mb_substr($query, 0, 255);
    $errors[] = 'Search terms must be 255 characters or fewer.';
}

$genreId = filter_input(INPUT_GET, 'genre', FILTER_VALIDATE_INT) ?: 0;

$format = requestString($_GET, 'format');
$format = in_array($format, $formats, true) ? $format : '';

$language = trim(requestString($_GET, 'language'));
$language = in_array($language, $languages, true) ? $language : '';

$inStock = requestString($_GET, 'in_stock') === '1';

$sort = requestString($_GET, 'sort');
$sort = isset($sorts[$sort]) ? $sort : 'newest';

$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);

$parsePrice = static function (string $raw, string $label) use (&$errors): ?float {
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    $value = filter_var($raw, FILTER_VALIDATE_FLOAT);

    if ($value === false || $value < 0 || $value > 99999999) {
        $errors[] = "{$label} must be a number of 0 or more.";
        return null;
    }

    return (float) $value;
};

$minPrice = $parsePrice(requestString($_GET, 'min_price'), 'Minimum price');
$maxPrice = $parsePrice(requestString($_GET, 'max_price'), 'Maximum price');

if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}

$filters = [
    'search' => $query,
    'genre' => $genreId ?: null,
    'format' => $format,
    'language' => $language,
    'in_stock' => $inStock ? '1' : null,
    'min_price' => $minPrice,
    'max_price' => $maxPrice,
    'sort' => $sort === 'newest' ? null : $sort,
];

$returnUrl = '/books.php' . ($_GET ? '?' . http_build_query($_GET) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $editionId = filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT) ?: 0;

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
        $errors[] = 'Please log in with a customer account before adding books to your cart.';
    }

    if ($editionId < 1) {
        $errors[] = 'Select a valid book edition.';
    }

    if (!$errors) {
        try {
            addEditionToCart($pdo, (int) $_SESSION['user_id'], $editionId, 1);
            $_SESSION['store_flash'] = 'Book added to your cart.';
            header('Location: ' . $returnUrl);
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

$bookConditions = [
    "b.status = 'active'",
    "b.review_status = 'approved'",
    "EXISTS (SELECT 1
               FROM book_genres bg
               JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
              WHERE bg.book_id = b.book_id)",
];
$bookParams = [];

$words = array_slice(array_filter(preg_split('/\s+/u', $query)), 0, 5);

foreach ($words as $word) {
    $like = catalogEscapeLike(mb_substr($word, 0, 100));

    $bookConditions[] = "(b.title LIKE ? ESCAPE '!'
                       OR b.isbn LIKE ? ESCAPE '!'
                       OR b.publisher LIKE ? ESCAPE '!'
                       OR b.description LIKE ? ESCAPE '!'
                       OR EXISTS (SELECT 1
                                    FROM book_authors sba
                                    JOIN authors sa ON sa.author_id = sba.author_id
                                   WHERE sba.book_id = b.book_id
                                     AND sa.full_name LIKE ? ESCAPE '!'))";

    array_push($bookParams, $like, $like, $like, $like, $like);
}

if ($genreId > 0) {
    $bookConditions[] = 'EXISTS (SELECT 1 FROM book_genres bgf
                                  WHERE bgf.book_id = b.book_id AND bgf.genre_id = ?)';
    $bookParams[] = $genreId;
}

if ($language !== '') {
    $bookConditions[] = 'b.language = ?';
    $bookParams[] = $language;
}

$editionConditions = [];
$editionParams = [];

if ($format !== '') {
    $editionConditions[] = 'e.format = ?';
    $editionParams[] = $format;
}

if ($inStock) {
    $editionConditions[] = 'e.stock_quantity > 0';
}

if ($minPrice !== null) {
    $editionConditions[] = 'e.price >= ?';
    $editionParams[] = $minPrice;
}

if ($maxPrice !== null) {
    $editionConditions[] = 'e.price <= ?';
    $editionParams[] = $maxPrice;
}

$where = implode(' AND ', array_merge($bookConditions, $editionConditions));
$params = array_merge($bookParams, $editionParams);

$from = 'FROM books b
         INNER JOIN book_editions e ON e.book_id = b.book_id';

$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT b.book_id) $from WHERE $where");
$countStmt->execute($params);

$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / CATALOG_PER_PAGE));
$page = min($page, $pages);

$offset = ($page - 1) * CATALOG_PER_PAGE;
$limit = CATALOG_PER_PAGE;

$sql = "SELECT
            b.book_id,
            b.title,
            b.isbn,
            b.language,
            MIN(e.price) AS min_price,
            MAX(e.price) AS max_price,
            SUM(e.stock_quantity) AS stock_total,
            MAX(e.cover_image_path) AS cover,
            GROUP_CONCAT(e.format
                ORDER BY FIELD(e.format, 'paperback', 'hardbound', 'ebook')
                SEPARATOR ', ') AS format_list,
            SUBSTRING_INDEX(
                GROUP_CONCAT(CASE WHEN e.stock_quantity > 0 THEN e.edition_id END
                             ORDER BY e.price ASC, e.edition_id ASC),
                ',', 1) AS buy_edition_id,
            (SELECT GROUP_CONCAT(g2.genre_name ORDER BY g2.genre_name SEPARATOR ', ')
               FROM book_genres bg2
               JOIN genres g2 ON g2.genre_id = bg2.genre_id AND g2.status = 'active'
              WHERE bg2.book_id = b.book_id) AS genre_names,
            (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
               FROM book_authors ba2
               JOIN authors a2 ON a2.author_id = ba2.author_id
              WHERE ba2.book_id = b.book_id) AS authors
        $from
        WHERE $where
        GROUP BY b.book_id
        ORDER BY {$sorts[$sort][1]}
        LIMIT $limit OFFSET $offset";

$bookStmt = $pdo->prepare($sql);
$bookStmt->execute($params);

$books = $bookStmt->fetchAll();

$firstItem = $total > 0 ? $offset + 1 : 0;
$lastItem = min($offset + CATALOG_PER_PAGE, $total);
$hasFilter = (bool) array_filter($filters, static fn ($v) => $v !== null && $v !== '');

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Book Catalog</h1>

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

    <div class="catalog-layout">
        <aside class="catalog-sidebar">
            <form method="get" action="/books.php" class="card catalog-filters">
                <div class="catalog-filter-heading">
                    <div>
                        <h2>Filters</h2>
                        <p>Find your next read</p>
                    </div>
                    <svg class="catalog-filter-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h10.125M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.375 6H7.5m3 12h10.125m-10.125 0a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.375 18H7.5M14.25 12h6.375m-6.375 0a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.375 12h7.5" />
                    </svg>
                </div>

                <div class="catalog-filter-group">
                    <label for="search">Search books</label>
                    <input class="form-input" type="search" id="search" name="search" maxlength="255" placeholder="Title, author, ISBN..." value="<?= $h($query) ?>">
                </div>

                <div class="catalog-filter-group">
                    <label for="genre">Genre</label>
                    <select class="form-input" id="genre" name="genre">
                        <option value="">All genres</option>
                        <?php foreach ($genres as $genre): ?>
                            <option value="<?= (int) $genre['genre_id'] ?>" <?= $genreId === (int) $genre['genre_id'] ? 'selected' : '' ?>>
                                <?= $h($genre['genre_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="catalog-filter-group">
                    <label for="format">Book format</label>
                    <select class="form-input" id="format" name="format">
                        <option value="">All formats</option>
                        <?php foreach ($formats as $f): ?>
                            <option value="<?= $h($f) ?>" <?= $format === $f ? 'selected' : '' ?>>
                                <?= $h(ucfirst($f)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="catalog-filter-group">
                    <label for="language">Language</label>
                    <select class="form-input" id="language" name="language">
                        <option value="">All languages</option>
                        <?php foreach ($languages as $lang): ?>
                            <option value="<?= $h($lang) ?>" <?= $language === $lang ? 'selected' : '' ?>>
                                <?= $h($lang) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="catalog-filter-group">
                    <label>Price range (₱)</label>
                    <div class="catalog-price-range">
                        <input class="form-input" type="number" id="min_price" name="min_price" min="0" step="0.01" aria-label="Minimum price" placeholder="Min" value="<?= $minPrice !== null ? $h($minPrice) : '' ?>">
                        <span aria-hidden="true">–</span>
                        <input class="form-input" type="number" id="max_price" name="max_price" min="0" step="0.01" aria-label="Maximum price" placeholder="Max" value="<?= $maxPrice !== null ? $h($maxPrice) : '' ?>">
                    </div>
                </div>

                <div class="catalog-filter-group">
                    <label for="sort">Sort books by</label>
                    <select class="form-input" id="sort" name="sort">
                        <?php foreach ($sorts as $key => [$label]): ?>
                            <option value="<?= $h($key) ?>" <?= $sort === $key ? 'selected' : '' ?>>
                                <?= $h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label class="catalog-stock-option" for="in_stock">
                    <input type="checkbox" id="in_stock" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>>
                    <span>Show in-stock books only</span>
                </label>

                <button class="btn btn-primary catalog-apply-btn" type="submit">Apply Filters</button>
                <a class="catalog-clear-btn" href="/books.php">Clear all filters</a>
            </form>
        </aside>

        <div class="catalog-results">
            <p class="catalog-count" role="status">
                <?php if ($total === 0): ?>
                    No books found.
                <?php else: ?>
                    Showing <?= $firstItem ?>&ndash;<?= $lastItem ?> of <?= $total ?>
                    book<?= $total === 1 ? '' : 's' ?><?= $hasFilter ? ' matching your filters' : '' ?>
                <?php endif; ?>
            </p>

            <?php if (!$books): ?>
                <div class="card">
                    <p class="card-text">No books match your search. Try fewer words or clear the filters.</p>
                </div>
            <?php else: ?>
                <div class="catalog-grid">
                    <?php foreach ($books as $book): ?>
                        <?php
                        $cover = catalogCover($book['cover']);
                        $buyId = $book['buy_edition_id'] !== null ? (int) $book['buy_edition_id'] : 0;
                        $inStockN = (int) $book['stock_total'];
                        $detail = '/book.php?id=' . (int) $book['book_id'];
                        ?>

                        <article class="card catalog-card">
                            <a class="catalog-cover-link" href="<?= $h($detail) ?>">
                                <?php if ($cover !== ''): ?>
                                    <img class="catalog-cover" src="<?= $h($cover) ?>" alt="<?= $h($book['title']) ?> cover" loading="lazy">
                                <?php else: ?>
                                    <?= catalogCoverPlaceholder((string) $book['title']) ?>
                                <?php endif; ?>
                            </a>

                            <h2><a href="<?= $h($detail) ?>"><?= $h($book['title']) ?></a></h2>

                            <p><strong>Author:</strong> <?= $h($book['authors'] ?: 'Not listed') ?></p>
                            <p><strong>Genre:</strong> <?= $h($book['genre_names'] ?: 'Not listed') ?></p>
                            <p><strong>Language:</strong> <?= $h($book['language']) ?></p>
                            <p><strong>Formats:</strong> <?= $h(ucwords($book['format_list'])) ?></p>

                            <p class="catalog-price"><?= $h(catalogPrice($book['min_price'], $book['max_price'])) ?></p>

                            <p>
                                <?php if ($inStockN > 0): ?>
                                    <span class="stock-badge stock-in">In stock</span>
                                <?php else: ?>
                                    <span class="stock-badge stock-out">Out of stock</span>
                                <?php endif; ?>
                            </p>

                            <div class="catalog-actions">
                                        <a class="catalog-action-btn catalog-details-btn" href="<?= $h($detail) ?>" aria-label="View details" title="View details">                                    <svg class="catalog-btn-icon catalog-icon-outline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z" />
                                    </svg>
                                    <svg class="catalog-btn-icon catalog-icon-filled" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M4.125 3C3.089 3 2.25 3.84 2.25 4.875V18a3 3 0 0 0 3 3h15a3 3 0 0 1-3-3V4.875C17.25 3.839 16.41 3 15.375 3H4.125ZM12 9.75a.75.75 0 0 0 0 1.5h1.5a.75.75 0 0 0 0-1.5H12Zm-.75-2.25a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 0 1.5H12a.75.75 0 0 1-.75-.75ZM6 12.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5H6Zm-.75 3.75a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5H6a.75.75 0 0 1-.75-.75ZM6 6.75a.75.75 0 0 0-.75.75v3c0 .414.336.75.75.75h3a.75.75 0 0 0 .75-.75v-3A.75.75 0 0 0 9 6.75H6Z" clip-rule="evenodd" />
                                        <path d="M18.75 6.75h1.875c.621 0 1.125.504 1.125 1.125V18a1.5 1.5 0 0 1-3 0V6.75Z" />
                                    </svg>
                                </a>

                                <?php if ($buyId > 0): ?>
                                    <form method="post" action="<?= $h($returnUrl) ?>" class="catalog-cart-form">
                                        <input type="hidden" name="csrf_token" value="<?= $h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="edition_id" value="<?= $buyId ?>">

                                        <button class="catalog-action-btn catalog-add-btn" type="submit">
                                            <svg class="catalog-btn-icon catalog-icon-outline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1-1.5 0Z" />
                                            </svg>
                                            <svg class="catalog-btn-icon catalog-icon-filled" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M2.25 2.25a.75.75 0 0 0 0 1.5h1.386c.17 0 .318.114.362.278l2.558 9.592a3.752 3.752 0 0 0-2.806 3.63c0 .414.336.75.75.75h15.75a.75.75 0 0 1 0-1.5H5.378A2.25 2.25 0 0 1 7.5 15h11.218a.75.75 0 0 0 .674-.421 60.358 60.358 0 0 0 2.96-7.228.75.75 0 0 0-.525-.965A60.864 60.864 0 0 0 5.68 4.509l-.232-.867A1.875 1.875 0 0 0 3.636 2.25H2.25ZM3.75 20.25a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0ZM16.5 20.25a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" />
                                            </svg>
                                            <span>Add to Cart</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="pagination" aria-label="Catalog pages">
                        <?php if ($page > 1): ?>
                            <a class="pagination-link" href="<?= $h(catalogUrl('/books.php', $filters + ['page' => $page - 1 > 1 ? $page - 1 : null])) ?>">&laquo; Previous</a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="pagination-link current" aria-current="page"><?= $p ?></span>
                            <?php else: ?>
                                <a class="pagination-link" href="<?= $h(catalogUrl('/books.php', $filters + ['page' => $p > 1 ? $p : null])) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $pages): ?>
                            <a class="pagination-link" href="<?= $h(catalogUrl('/books.php', $filters + ['page' => $page + 1])) ?>">Next &raquo;</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
