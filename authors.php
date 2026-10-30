<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/catalog.php';

$h = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES,
    'UTF-8'
);

$search = trim((string) ($_GET['search'] ?? ''));
$search = mb_substr($search, 0, 100);

$sql = "SELECT
            a.author_id,
            a.full_name,
            a.bio,
            a.author_image_path,
            COUNT(DISTINCT b.book_id) AS book_count
        FROM authors a
        JOIN book_authors ba ON ba.author_id = a.author_id
        JOIN books b ON b.book_id = ba.book_id
        JOIN book_genres bg ON bg.book_id = b.book_id
        JOIN genres g ON g.genre_id = bg.genre_id
                     AND g.status = 'active'
        JOIN book_editions e ON e.book_id = b.book_id
        WHERE a.verification_status = 'verified'
          AND b.status = 'active'
          AND b.review_status = 'approved'";

$params = [];

if ($search !== '') {
    $sql .= " AND a.full_name LIKE ?";
    $params[] = '%' . $search . '%';
}

$sql .= " GROUP BY
              a.author_id,
              a.full_name,
              a.bio,
              a.author_image_path
          ORDER BY a.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$authors = $stmt->fetchAll();

// Load the public catalog records for these authors once, so the profile
// dialogs can show the same active books customers can browse.
$booksByAuthor = [];
if ($authors) {
    $authorIds = array_values(array_unique(array_map(
        static fn (array $author): int => (int) $author['author_id'],
        $authors
    )));
    $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
    $booksStmt = $pdo->prepare(
        "SELECT ba.author_id, b.book_id, b.title, b.description, b.isbn, b.language,
                MIN(e.price) AS min_price,
                MAX(e.price) AS max_price,
                SUM(e.stock_quantity) AS stock_total,
                GROUP_CONCAT(DISTINCT e.format ORDER BY FIELD(e.format, 'paperback', 'hardbound', 'ebook') SEPARATOR ', ') AS formats,
                MAX(e.cover_image_path) AS cover_image_path
           FROM book_authors ba
           JOIN books b ON b.book_id = ba.book_id
           JOIN book_genres bg ON bg.book_id = b.book_id
           JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
           JOIN book_editions e ON e.book_id = b.book_id
          WHERE ba.author_id IN ($placeholders)
            AND b.status = 'active'
            AND b.review_status = 'approved'
          GROUP BY ba.author_id, b.book_id, b.title, b.description, b.isbn, b.language
          ORDER BY ba.author_id, b.title"
    );
    $booksStmt->execute($authorIds);
    foreach ($booksStmt->fetchAll() as $book) {
        $book['cover_image_path'] = catalogCover($book['cover_image_path'] ?? null);
        $booksByAuthor[(int) $book['author_id']][] = $book;
    }
}


$selectedLetter = strtoupper(trim((string) ($_GET['letter'] ?? '')));

if (!preg_match('/^[A-Z]$/', $selectedLetter)) {
    $selectedLetter = '';
}

if ($selectedLetter !== '') {
    $authors = array_values(array_filter(
        $authors,
        static function (array $author) use ($selectedLetter): bool {
            $name = trim((string) ($author['full_name'] ?? ''));

            return strtoupper(substr($name, 0, 1)) === $selectedLetter;
        }
    ));
}

function authorPhoto(?string $path): string
{
    $filename = basename(str_replace('\\', '/', (string) $path));

    return $filename !== ''
        ? '/images/authors/' . rawurlencode($filename)
        : '';
}

function authorInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : '?';
}

function authorBioPreview(?string $bio, int $limit = 150): string
{
    $bio = trim((string) $bio);

    if ($bio === '') {
        return 'Discover the books and stories by this author.';
    }

    if (mb_strlen($bio) > $limit) {
        return rtrim(mb_substr($bio, 0, $limit)) . '...';
    }

    return $bio;
}

include __DIR__ . '/includes/header.php';
?>

<section class="container authors-page">
    <header class="authors-hero">
        <span class="authors-eyebrow">Stories from the Philippines</span>
        <h1>Filipino Authors</h1>
        <p>
            Meet the writers behind the stories, ideas, and voices that
            shape Philippine literature. Discover their works and find
            your next meaningful read.
        </p>
    </header>

    <section class="authors-directory" aria-label="Author directory">
        <div class="authors-directory-heading">
            <div>
                <h2>Meet the Authors</h2>
                <p>Explore the people behind your next favorite book.</p>
            </div>

            <span class="authors-total">
                <?= count($authors) ?>
                <?= count($authors) === 1 ? 'author' : 'authors' ?>
            </span>
        </div>

        <form class="authors-search" method="get" action="/authors.php" role="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle>
                <path d="m16 16 4 4"></path>
            </svg>

            <input
                type="search"
                name="search"
                aria-label="Search authors"
                placeholder="Search by author name..."
                maxlength="100"
                value="<?= $h($search) ?>"
            >

            <button type="submit">Search</button>

            <?php if ($search !== ''): ?>
                <a class="authors-clear-search" href="/authors.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="authors-alphabet" aria-label="Filter authors alphabetically">
            <?php
            $alphabetParams = [];

            if ($search !== '') {
                $alphabetParams['search'] = $search;
            }

            $allAuthorsUrl = '/authors.php' .
                ($alphabetParams ? '?' . http_build_query($alphabetParams) : '');
            ?>

            <a
                href="<?= $h($allAuthorsUrl) ?>"
                class="authors-letter <?= $selectedLetter === '' ? 'active' : '' ?>"
                <?= $selectedLetter === '' ? 'aria-current="page"' : '' ?>
            >All</a>

            <?php foreach (range('A', 'Z') as $letter): ?>
                <?php
                $letterParams = $alphabetParams;
                $letterParams['letter'] = $letter;
                ?>

                <a
                    href="/authors.php?<?= $h(http_build_query($letterParams)) ?>"
                    class="authors-letter <?= $selectedLetter === $letter ? 'active' : '' ?>"
                    <?= $selectedLetter === $letter ? 'aria-current="page"' : '' ?>
                ><?= $h($letter) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$authors): ?>
            <div class="authors-empty">
                <div class="authors-empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round"
                         stroke-linejoin="round">
                        <circle cx="10" cy="8" r="4"></circle>
                        <path d="M3 21v-2a7 7 0 0 1 14 0v2"></path>
                        <path d="m19 8 2 2-2 2"></path>
                    </svg>
                </div>

                <h2>No authors found</h2>

                <p>
                    <?= $search !== ''
                        ? 'Try a different name or clear your search.'
                        : 'Author profiles will appear here when books are added to the catalog.' ?>
                </p>

                <?php if ($search !== ''): ?>
                    <a class="authors-secondary-btn" href="/authors.php">
                        View all authors
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="authors-grid">
                <?php foreach ($authors as $author): ?>
                    <?php
                    $photo = authorPhoto($author['author_image_path'] ?? null);
                    $bookCount = (int) $author['book_count'];
                    $profileBooks = $booksByAuthor[(int) $author['author_id']] ?? [];
                    $profilePayload = [
                        'name' => (string) $author['full_name'],
                        'bio' => trim((string) ($author['bio'] ?? '')),
                        'photo' => $photo,
                        'books' => $profileBooks,
                    ];
                    $profileJson = json_encode(
                        $profilePayload,
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    );
                    ?>

                    <button
                        type="button"
                        class="author-directory-card"
                        id="author-<?= (int) $author['author_id'] ?>"
                        data-author-profile="<?= $h($profileJson ?: '{}') ?>"
                        aria-label="View profile and books by <?= $h($author['full_name']) ?>"
                    >
                        <span class="author-card-photo-link" aria-hidden="true">
                            <?php if ($photo !== ''): ?>
                                <img
                                    class="author-directory-photo"
                                    src="<?= $h($photo) ?>"
                                    alt="Portrait of <?= $h($author['full_name']) ?>"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="author-directory-placeholder" aria-hidden="true">
                                    <?= $h(authorInitials($author['full_name'])) ?>
                                </div>
                            <?php endif; ?>

                            <span class="author-book-count">
                                <?= $bookCount ?>
                                <?= $bookCount === 1 ? 'book' : 'books' ?>
                            </span>
                        </span>

                        <div class="author-directory-content">
                            <h3><?= $h($author['full_name']) ?></h3>

                            <p class="author-directory-bio">
                                <?= $h(authorBioPreview($author['bio'] ?? null)) ?>
                            </p>

                            <span class="author-explore-link">
                                View Author Profile
                                <svg viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M5 12h14"></path>
                                    <path d="m13 6 6 6-6 6"></path>
                                </svg>
                            </span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>

<dialog class="customer-author-dialog" id="customer-author-dialog" aria-labelledby="customer-author-dialog-title">
    <div class="customer-author-dialog-shell">
        <header class="customer-author-dialog-header">
            <div>
                <span class="authors-eyebrow">Author Profile</span>
                <h2 id="customer-author-dialog-title">Author Details</h2>
            </div>
            <button class="customer-author-dialog-close" type="button" aria-label="Close author profile">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </header>
        <div class="customer-author-dialog-body" id="customer-author-dialog-body"></div>
    </div>
</dialog>

<script src="/js/customer-authors.js" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
