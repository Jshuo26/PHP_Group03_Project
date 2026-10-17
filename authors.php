<?php
require_once __DIR__ . '/includes/db.php';

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
        WHERE b.status = 'active'";

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
                    $booksUrl = '/books.php?search=' . rawurlencode($author['full_name']);
                    ?>

                    <article
                        class="author-directory-card"
                        id="author-<?= (int) $author['author_id'] ?>"
                    >
                        <a
                            class="author-card-photo-link"
                            href="<?= $h($booksUrl) ?>"
                            aria-label="Explore books by <?= $h($author['full_name']) ?>"
                        >
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
                        </a>

                        <div class="author-directory-content">
                            <h3><?= $h($author['full_name']) ?></h3>

                            <p class="author-directory-bio">
                                <?= $h(authorBioPreview($author['bio'] ?? null)) ?>
                            </p>

                            <a class="author-explore-link" href="<?= $h($booksUrl) ?>">
                                Explore Books
                                <svg viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M5 12h14"></path>
                                    <path d="m13 6 6 6-6 6"></path>
                                </svg>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>