<?php

require_once __DIR__ . '/spotlight.php';

$spotlight = getTodaysSpotlight($pdo);

$spotlightCover = '';

if ($spotlight && !empty($spotlight['cover'])) {
    $spotlightCover = function_exists('homepageBookCover')
        ? homepageBookCover($spotlight['cover'])
        : '/' . ltrim($spotlight['cover'], '/');
}

$authorInitials = '';

if ($spotlight) {
    foreach (preg_split('/\s+/', trim($spotlight['author_name'])) as $part) {
        $authorInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    $authorInitials = mb_substr($authorInitials, 0, 2);
}
?>

<section class="home-section">
    <div class="section-container">
        <h2 class="section-title">Today's Spotlight</h2>
        <div class="spotlight-grid">

            <article class="spotlight-card author-spotlight">
                <div class="spotlight-content">
                    <span class="spotlight-label">Author of the Day</span>
                    <div class="author-image-placeholder">
                        <span><?= htmlspecialchars($authorInitials ?: 'Author', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="spotlight-info">
                        <h3>
                            <?= $spotlight
                                ? htmlspecialchars($spotlight['author_name'], ENT_QUOTES, 'UTF-8')
                                : 'Author Spotlight Coming Soon' ?>
                        </h3>
                        <p>
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
                            <a href="/authors.php?id=<?= (int) $spotlight['author_id'] ?>" class="text-link">View Author Profile →</a>
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
                        <p>
                            <?= $spotlight
                                ? htmlspecialchars($spotlight['book_authors'] ?: $spotlight['author_name'], ENT_QUOTES, 'UTF-8')
                                : 'Book author' ?>
                        </p>
                        <p>
                            <?= $spotlight
                                ? htmlspecialchars($spotlight['genre_name'], ENT_QUOTES, 'UTF-8')
                                : 'Book genre' ?>
                        </p>
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