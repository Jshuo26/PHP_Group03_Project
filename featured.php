<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/catalog.php';

$featuredBooks = [];

$h = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES,
    'UTF-8'
);

function featuredBookCover(?string $path): string
{
    return catalogCover($path);
}

try {
    $featuredQuery = $pdo->query("
        SELECT
            b.book_id,
            b.title,
            be.price,
            be.cover_image_path,
            GROUP_CONCAT(
                DISTINCT a.full_name
                ORDER BY a.full_name
                SEPARATOR ', '
            ) AS authors
        FROM featured_books fb
        INNER JOIN books b
            ON b.book_id = fb.book_id
        INNER JOIN book_editions be
            ON be.book_id = b.book_id
        LEFT JOIN book_authors ba
            ON ba.book_id = b.book_id
        LEFT JOIN authors a
            ON a.author_id = ba.author_id
        WHERE b.status = 'active'
          AND b.review_status = 'approved'
          AND EXISTS (
              SELECT 1
              FROM book_genres bg
              INNER JOIN genres g
                  ON g.genre_id = bg.genre_id
              WHERE bg.book_id = b.book_id
                AND g.status = 'active'
          )
        GROUP BY
            b.book_id,
            b.title,
            fb.display_order,
            fb.added_at,
            be.edition_id,
            be.price,
            be.cover_image_path
        ORDER BY
            fb.display_order ASC,
            fb.added_at DESC,
            be.price ASC,
            be.edition_id ASC
    ");

    $featuredBooks = $featuredQuery->fetchAll();

    $seenBooks = [];
    $featuredBooks = array_values(array_filter(
        $featuredBooks,
        static function (array $book) use (&$seenBooks): bool {
            $id = (int) $book['book_id'];

            if (isset($seenBooks[$id])) {
                return false;
            }

            $seenBooks[$id] = true;
            return true;
        }
    ));
} catch (PDOException $e) {
    error_log('Featured books query failed: ' . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
?>

<section class="featured-page">
    <div class="featured-intro">
        <span class="featured-eyebrow">THE AKLAAAAT! COLLECTION</span>

        <h1>A Shelf of Stories Worth Discovering.</h1>

        <p>
            Explore a handpicked collection celebrating Filipino authors,
            memorable stories, and the voices that deserve a place on your shelf.
        </p>

        <a href="/books.php" class="featured-browse-button">
            Explore All Books
            <span aria-hidden="true">→</span>
        </a>

        <div class="featured-intro-decoration" aria-hidden="true">
            <span>Stories</span>
            <span>Culture</span>
            <span>Discovery</span>
        </div>
    </div>

    <div class="featured-content">
        <div class="featured-section-heading">
            <div>
                <span class="featured-eyebrow">SELECTED WITH CARE</span>
                <h2>Staff Picks</h2>
                <p>Books selected by our team for your next reading adventure.</p>
            </div>

            <a href="/books.php" class="featured-view-all">
                Browse catalog <span aria-hidden="true">↗</span>
            </a>
        </div>

        <?php if ($featuredBooks): ?>
            <div class="featured-book-grid">
                <?php foreach ($featuredBooks as $book): ?>
                    <?php
                    $bookId = (int) $book['book_id'];
                    $title = (string) $book['title'];
                    $authors = trim((string) ($book['authors'] ?? ''));
                    $cover = featuredBookCover($book['cover_image_path'] ?? null);
                    $detailUrl = '/book.php?id=' . $bookId;
                    ?>

                    <article class="book-tile">
                        <a class="book-tile-cover" href="/book.php?id=<?= $bookId ?>">
                            <?php if ($cover !== ''): ?>
                                <img
                                    src="<?= $h($cover) ?>"
                                    alt="<?= $h($title) ?> cover"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <?= catalogCoverPlaceholder($title, 'featured-cover-placeholder') ?>
                            <?php endif; ?>
                        </a>

                        <div class="book-tile-body">
                            <h3 class="book-tile-title">
                                <a href="/book.php?id=<?= $bookId ?>">
                                    <?= $h($title) ?>
                                </a>
                            </h3>

                            <p class="book-tile-author">
                                <?= $h($authors !== '' ? $authors : 'Filipino Author') ?>
                            </p>

                            <div class="book-tile-footer">
                                <p class="book-tile-price">
                                    ₱<?= number_format((float) $book['price'], 2) ?>
                                </p>

                                <a href="/book.php?id=<?= $bookId ?>" class="view-book-btn">
                                    <span>View Book</span>
                                    <svg class="icon-outline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 1 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                    </svg>
                                    <svg class="icon-solid" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M11.25 4.533A9.707 9.707 0 0 0 6 3a9.735 9.735 0 0 0-3.25.555.75.75 0 0 0-.5.707v14.25a.75.75 0 0 0 1 .707A8.237 8.237 0 0 1 6 18.75c1.995 0 3.823.707 5.25 1.886V4.533ZM12.75 20.636A8.214 8.214 0 0 1 18 18.75c.966 0 1.89.166 2.75.47a.75.75 0 0 0 1-.708V4.262a.75.75 0 0 0-.5-.707A9.735 9.735 0 0 0 18 3a9.707 9.707 0 0 0-5.25 1.533v16.103Z" />
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="featured-empty-state">
                <span class="featured-empty-icon" aria-hidden="true">✦</span>
                <h3>Something special is coming to your shelf.</h3>
                <p>
                    Our featured collection is being prepared.
                    In the meantime, explore the full bookstore and discover
                    your next favorite read.
                </p>
                <a href="/books.php" class="featured-browse-button">
                    Browse All Books <span aria-hidden="true">→</span>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <section class="featured-bottom-banner">
        <div>
            <span class="featured-eyebrow">READ LOCAL. DISCOVER MORE.</span>
            <h2>Every Filipino story has a place on the shelf.</h2>
            <p>Find your next read among the voices, ideas, and stories of the Philippines.</p>
        </div>

        <a href="/authors.php" class="featured-banner-button">
            Meet Filipino Authors <span aria-hidden="true">→</span>
        </a>
    </section>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
