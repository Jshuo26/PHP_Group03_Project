<?php

require_once __DIR__ . '/spotlight.php';

$spotlight = getTodaysSpotlight($pdo);

$spotlightCover = '';
$authorPhoto = '';
$authorInitials = '';
$genreList = [];
$spotlightDate = '';

if ($spotlight) {
    if (!empty($spotlight['cover'])) {
        $spotlightCover = function_exists('homepageBookCover')
            ? homepageBookCover($spotlight['cover'])
            : '/images/book-covers/' . rawurlencode(basename(str_replace('\\', '/', $spotlight['cover'])));
    }

    $authorPhoto = spotlightAuthorPhoto($spotlight['author_image'] ?? null);

    foreach (preg_split('/\s+/', trim($spotlight['author_name']), -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $authorInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    $authorInitials = mb_substr($authorInitials, 0, 2);

    $genreList = array_filter(explode('|', (string) ($spotlight['genre_names'] ?? '')));

    $dateValue = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        (string) $spotlight['spotlight_date'],
        new DateTimeZone('Asia/Manila')
    );
    $spotlightDate = $dateValue ? $dateValue->format('F j, Y') : '';
}

$authorBookCount = $spotlight ? (int) $spotlight['author_book_count'] : 0;
?>

<section class="home-section spotlight-section">
    <div class="section-container">
        <div class="section-heading-row">
            <h2 class="section-title">Today's Spotlight</h2>
            <?php if ($spotlightDate !== ''): ?>
                <span class="spotlight-date"><?= htmlspecialchars($spotlightDate, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>

        <div class="spotlight-grid">

            <article class="spotlight-card author-spotlight">
                <div class="spotlight-content">
                    <span class="spotlight-label">Author of the Day</span>

                    <div class="author-image-placeholder">
                        <?php if ($authorPhoto !== ''): ?>
                            <img
                                src="<?= htmlspecialchars($authorPhoto, ENT_QUOTES, 'UTF-8') ?>"
                                alt="Photo of <?= htmlspecialchars($spotlight['author_name'], ENT_QUOTES, 'UTF-8') ?>"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='block';"
                            >
                            <span style="display:none"><?= htmlspecialchars($authorInitials, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <span><?= htmlspecialchars($authorInitials ?: 'Author', ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="spotlight-info">
                        <h3>
                            <?= $spotlight
                                ? htmlspecialchars($spotlight['author_name'], ENT_QUOTES, 'UTF-8')
                                : 'Author Spotlight Coming Soon' ?>
                        </h3>
                        <?php if ($spotlight): ?>
                            <p class="spotlight-meta">
                                <?= $authorBookCount ?> book<?= $authorBookCount === 1 ? '' : 's' ?> in our catalog
                            </p>
                        <?php endif; ?>
                        <p class="spotlight-synopsis">
                            <?php if ($spotlight): ?>
                                <?= htmlspecialchars(
                                    !empty($spotlight['author_bio'])
                                        ? mb_strimwidth($spotlight['author_bio'], 0, 160, '...')
                                        : 'Discover the story behind this Filipino author.',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            <?php else: ?>
                                Add authors to your catalog to start the daily spotlight.
                            <?php endif; ?>
                        </p>
                        <?php if ($spotlight): ?>
                            <a href="/authors.php#author-<?= (int) $spotlight['author_id'] ?>" class="text-link">View Author Profile →</a>
                        <?php else: ?>
                            <a href="/authors.php" class="text-link">View Author Profile →</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>

            <article class="spotlight-card book-spotlight">
                <div class="spotlight-content">
                    <span class="spotlight-label">Book of the Day</span>

                    <div class="book-image-placeholder">
                        <?php if ($spotlightCover !== ''): ?>
                            <img
                                src="<?= htmlspecialchars($spotlightCover, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($spotlight['title'], ENT_QUOTES, 'UTF-8') ?> cover"
                            >
                        <?php else: ?>
                            <span>Book Cover</span>
                        <?php endif; ?>
                    </div>

                    <div class="spotlight-info">
                        <h3>
                            <?= $spotlight
                                ? htmlspecialchars($spotlight['title'], ENT_QUOTES, 'UTF-8')
                                : 'Book Spotlight Coming Soon' ?>
                        </h3>
                        <p class="spotlight-meta">
                            <?= $spotlight
                                ? 'by ' . htmlspecialchars($spotlight['book_authors'] ?: $spotlight['author_name'], ENT_QUOTES, 'UTF-8')
                                : 'Book author' ?>
                        </p>
                        <?php if ($genreList): ?>
                            <div class="spotlight-genres">
                                <?php foreach ($genreList as $genreName): ?>
                                    <span class="spotlight-genre"><?= htmlspecialchars($genreName, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!$spotlight): ?>
                            <p class="spotlight-meta">Book genre</p>
                        <?php endif; ?>
                        <p class="spotlight-synopsis">
                            <?php if ($spotlight): ?>
                                <?= htmlspecialchars(
                                    !empty($spotlight['description'])
                                        ? mb_strimwidth($spotlight['description'], 0, 150, '...')
                                        : 'Explore this title from our catalog.',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            <?php else: ?>
                                Add books and authors to begin showing a daily selection.
                            <?php endif; ?>
                        </p>
                        <?php if ($spotlight): ?>
                            <a href="/books.php?id=<?= (int) $spotlight['book_id'] ?>" class="text-link">View Book →</a>
                        <?php else: ?>
                            <a href="/books.php" class="text-link">View Book →</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>

        </div>
    </div>
</section>
