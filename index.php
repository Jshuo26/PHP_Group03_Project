<?php
require_once 'includes/db.php';

$spotlightAuthor = null;
$spotlightBook = null;
$featuredBooks = [];
$newBooks = [];
$genres = [];

try {
    $spotlightQuery = $pdo->query("SELECT ds.book_id, ds.author_id, a.full_name AS author_name, a.bio AS author_bio, b.title AS book_title, b.description AS book_description, g.genre_name, be.cover_image_path, (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ') FROM book_authors ba2 JOIN authors a2 ON a2.author_id = ba2.author_id WHERE ba2.book_id = b.book_id) AS book_authors
        FROM daily_spotlight ds
        JOIN authors a ON a.author_id = ds.author_id
        JOIN books b ON b.book_id = ds.book_id
        JOIN genres g ON g.genre_id = b.genre_id
        LEFT JOIN book_editions be ON be.book_id = b.book_id
        WHERE ds.spotlight_date = CURDATE()
        ORDER BY be.edition_id
        LIMIT 1");
    $todaySpotlight = $spotlightQuery->fetch();

    if (!$todaySpotlight) {
        $authorQuery = $pdo->query("SELECT a.author_id, a.full_name, a.bio
            FROM authors a
            WHERE NOT EXISTS (
                SELECT 1 FROM daily_spotlight ds
                WHERE ds.author_id = a.author_id
                AND ds.spotlight_date > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            )
            ORDER BY RAND()
            LIMIT 1");
        $eligibleAuthor = $authorQuery->fetch();

        $bookQuery = $pdo->query("SELECT b.book_id, b.title, b.description, g.genre_name, be.cover_image_path, (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ') FROM book_authors ba2 JOIN authors a2 ON a2.author_id = ba2.author_id WHERE ba2.book_id = b.book_id) AS book_authors
            FROM books b
            JOIN genres g ON g.genre_id = b.genre_id
            LEFT JOIN book_editions be ON be.book_id = b.book_id
            WHERE NOT EXISTS (
                SELECT 1 FROM daily_spotlight ds
                WHERE ds.book_id = b.book_id
                AND ds.spotlight_date > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            )
            ORDER BY RAND()
            LIMIT 1");
        $eligibleBook = $bookQuery->fetch();

        if ($eligibleAuthor && $eligibleBook) {
            $insertSpotlight = $pdo->prepare('INSERT INTO daily_spotlight (spotlight_date, book_id, author_id) VALUES (CURDATE(), ?, ?)');
            $insertSpotlight->execute([$eligibleBook['book_id'], $eligibleAuthor['author_id']]);

            $todaySpotlight = [
                'book_id' => $eligibleBook['book_id'],
                'author_id' => $eligibleAuthor['author_id'],
                'author_name' => $eligibleAuthor['full_name'],
                'author_bio' => $eligibleAuthor['bio'],
                'book_title' => $eligibleBook['title'],
                'book_description' => $eligibleBook['description'],
                'genre_name' => $eligibleBook['genre_name'],
                'cover_image_path' => $eligibleBook['cover_image_path']
            ];
        }
    }

    if ($todaySpotlight) {
        $spotlightAuthor = [
            'author_id' => $todaySpotlight['author_id'],
            'name' => $todaySpotlight['author_name'],
            'bio' => $todaySpotlight['author_bio']
        ];
        $spotlightBook = [
            'book_id' => $todaySpotlight['book_id'],
            'title' => $todaySpotlight['book_title'],
            'authors' => $todaySpotlight['book_authors'] ?? '',
            'description' => $todaySpotlight['book_description'],
            'genre' => $todaySpotlight['genre_name'],
            'cover' => $todaySpotlight['cover_image_path']
        ];
    }

    $featuredQuery = $pdo->query("SELECT b.book_id, b.title, be.price, be.cover_image_path,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ', ') AS authors
        FROM books b
        JOIN book_editions be ON be.book_id = b.book_id
        LEFT JOIN book_authors ba ON ba.book_id = b.book_id
        LEFT JOIN authors a ON a.author_id = ba.author_id
        JOIN genres g ON g.genre_id = b.genre_id AND g.status = 'active'
        GROUP BY b.book_id, b.title, b.created_at, be.edition_id, be.price, be.cover_image_path
        ORDER BY b.created_at DESC, b.book_id DESC
        LIMIT 5");
    $featuredBooks = $featuredQuery->fetchAll();

    $newBooksQuery = $pdo->query("SELECT b.book_id, b.title, be.price, be.cover_image_path,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ', ') AS authors
        FROM books b
        JOIN book_editions be ON be.book_id = b.book_id
        LEFT JOIN book_authors ba ON ba.book_id = b.book_id
        LEFT JOIN authors a ON a.author_id = ba.author_id
        GROUP BY b.book_id, b.title, b.created_at, be.price, be.cover_image_path
        ORDER BY b.created_at DESC, b.book_id DESC
        LIMIT 4");
    $newBooks = $newBooksQuery->fetchAll();

    $genreQuery = $pdo->query("SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name LIMIT 6");
    $genres = $genreQuery->fetchAll();
} catch (PDOException $e) {
    error_log('Homepage query failed: ' . $e->getMessage());
}

function homepageBookCover(?string $path): string
{
    $filename = basename(str_replace('\\', '/', trim((string) $path)));
    return $filename !== '' && $filename !== '.' ? '/images/book-covers/' . rawurlencode($filename) : '';
}

function homepageText(?string $value, int $limit = 150): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }
    return strlen($value) > $limit ? substr($value, 0, $limit - 1) . '…' : $value;
}

include 'includes/header.php';
?>

<section class="hero-slider">
    <div class="hero-slide active">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">Accessible. Assorted. Automated.</h1>
            <p class="hero-description">Discover Filipino authors and independent publishers, all in one shelf.</p>
            <a href="/books.php" class="hero-button">Browse the Catalog</a>
        </div>
    </div>
    <div class="hero-slide">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">Discover Filipino Stories</h1>
            <p class="hero-description">Explore stories, ideas, and voices from Filipino writers.</p>
            <a href="/books.php" class="hero-button">Browse the Catalog</a>
        </div>
    </div>
    <div class="hero-slide">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">Find Your Next Favorite Book</h1>
            <p class="hero-description">Browse our growing collection of local literature.</p>
            <a href="/books.php" class="hero-button">Browse the Catalog</a>
        </div>
    </div>
    <button class="hero-arrow hero-prev" type="button" aria-label="Previous slide">&#10094;</button>
    <button class="hero-arrow hero-next" type="button" aria-label="Next slide">&#10095;</button>
    <div class="hero-dots">
        <button class="hero-dot active" type="button" aria-label="Slide 1"></button>
        <button class="hero-dot" type="button" aria-label="Slide 2"></button>
        <button class="hero-dot" type="button" aria-label="Slide 3"></button>
    </div>
</section>

<section class="home-section">
    <div class="section-container">
        <h2 class="section-title">Today's Spotlight</h2>
        <div class="spotlight-grid">
            <article class="spotlight-card author-spotlight">
                <div class="spotlight-content">
                    <span class="spotlight-label">Author of the Day</span>
                    <div class="author-image-placeholder">
                        <img src="/images/authors/author-1.jpg" alt="Filipino author" loading="lazy">
                    </div>
                    <div class="spotlight-info">
                        <h3><?= $spotlightAuthor ? htmlspecialchars($spotlightAuthor['name']) : 'Author Spotlight Coming Soon' ?></h3>
                        <p><?= $spotlightAuthor ? htmlspecialchars(homepageText($spotlightAuthor['bio'], 160) ?: 'Discover the story behind this Filipino author.') : 'Add authors to your catalog to start the daily spotlight.' ?></p>
                        <a href="/authors.php" class="text-link">View Author Profile →</a>
                    </div>
                </div>
            </article>
            <article class="spotlight-card book-spotlight">
                <div class="spotlight-content">
                    <span class="spotlight-label">Book of the Day</span>
                    <div class="book-image-placeholder">
                        <?php if (!empty($spotlightBook['cover'])): ?>
                            <img src="<?= htmlspecialchars(homepageBookCover($spotlightBook['cover'])) ?>" alt="<?= htmlspecialchars($spotlightBook['title']) ?> cover">
                        <?php else: ?>
                            <span>Book Cover</span>
                        <?php endif; ?>
                    </div>
                    <div class="spotlight-info">
                        <h3><?= $spotlightBook ? htmlspecialchars($spotlightBook['title']) : 'Book Spotlight Coming Soon' ?></h3>
                        <p><?= $spotlightBook ? htmlspecialchars($spotlightBook['authors'] ?: $spotlightBook['genre']) : 'Book author' ?></p>
                        <p><?= $spotlightBook ? htmlspecialchars($spotlightBook['genre']) : 'Book genre' ?></p>
                        <p class="spotlight-synopsis"><?= $spotlightBook ? htmlspecialchars(homepageText($spotlightBook['description'], 150) ?: 'Explore this title from our catalog.') : 'Add books and authors to begin showing a daily selection.' ?></p>
                        <a href="/books.php" class="text-link">View Book →</a>
                    </div>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="home-section featured-books-section">
    <div class="section-container">
        <div class="section-heading-row">
            <h2 class="section-title">Featured Books</h2>
            <a href="/books.php" class="view-all-link">View All →</a>
        </div>
        <div class="featured-books-grid">
            <?php foreach ($featuredBooks as $featured): ?>
                <article class="book-card">
                    <div class="book-card-image">
                        <?php if (!empty($featured['cover_image_path'])): ?>
                            <img src="<?= htmlspecialchars(homepageBookCover($featured['cover_image_path'])) ?>" alt="<?= htmlspecialchars($featured['title']) ?> cover" loading="lazy">
                        <?php else: ?>
                            <span>Book Cover</span>
                        <?php endif; ?>
                    </div>
                    <div class="book-card-content">
                        <h3 class="book-card-title"><?= htmlspecialchars($featured['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="book-card-author"><?= htmlspecialchars($featured['authors'] ?: 'Filipino Author', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="book-card-price">₱<?= number_format((float) $featured['price'], 2) ?></p>
                        <a href="/books.php?id=<?= (int) $featured['book_id'] ?>" class="add-cart-button">View Book</a>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$featuredBooks): ?><p class="section-empty-message">Featured books will appear here as the catalog grows.</p><?php endif; ?>
        </div>
    </div>
</section>

<section class="filipino-banner-section" aria-label="Why support Filipino books">
    <div class="filipino-banner-slideshow" aria-hidden="true">
        <div class="filipino-banner-image filipino-banner-image-one"></div>
        <div class="filipino-banner-image filipino-banner-image-two"></div>
        <div class="filipino-banner-image filipino-banner-image-three"></div>
    </div>
    <div class="filipino-banner-overlay"></div>
    <div class="filipino-banner-content">
        <p class="eyebrow">Stories from home</p>
        <h2>Why Support Filipino Books?</h2>
        <p>Every book you choose helps Filipino voices reach more readers and keeps our stories alive for the next generation.</p>
        <a href="/books.php" class="hero-button">Discover Filipino Books</a>
    </div>
</section>

<section class="home-section genre-section">
    <div class="section-container">
        <div class="section-heading-row">
            <h2 class="section-title">Explore by Genre</h2>
            <a href="/genres.php" class="view-all-link">View All →</a>
        </div>
        <?php if ($genres): ?>
            <div class="genre-grid">
                <?php foreach ($genres as $genre): ?>
                    <a class="genre-card" href="/books.php?genre=<?= (int) $genre['genre_id'] ?>">
                        <span class="genre-card-icon">✦</span>
                        <span><?= htmlspecialchars($genre['genre_name']) ?></span>
                        <span class="genre-card-arrow">→</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="section-empty-message">Book genres will appear here as they are added to the catalog.</p>
        <?php endif; ?>
    </div>
</section>

<section class="home-section new-books-section">
    <div class="section-container">
        <div class="section-heading-row">
            <h2 class="section-title">Newly Added Books</h2>
            <a href="/books.php" class="view-all-link">View All Books →</a>
        </div>
        <?php if ($newBooks): ?>
            <div class="new-books-grid">
                <?php foreach ($newBooks as $book): ?>
                    <article class="new-book-card">
                        <a class="new-book-cover" href="/books.php?id=<?= (int) $book['book_id'] ?>">
                            <?php if (!empty($book['cover_image_path'])): ?>
                                <img src="<?= htmlspecialchars(homepageBookCover($book['cover_image_path'])) ?>" alt="<?= htmlspecialchars($book['title']) ?> cover" loading="lazy">
                            <?php else: ?>
                                <span>Book Cover</span>
                            <?php endif; ?>
                        </a>
                        <div class="new-book-info">
                            <h3><a href="/books.php?id=<?= (int) $book['book_id'] ?>"><?= htmlspecialchars($book['title']) ?></a></h3>
                            <p><?= htmlspecialchars($book['authors'] ?: 'Filipino Author') ?></p>
                            <strong>₱<?= number_format((float) $book['price'], 2) ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="section-empty-message">New books will appear here when they are added to the catalog.</p>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('.hero-dot');
    const previousButton = document.querySelector('.hero-prev');
    const nextButton = document.querySelector('.hero-next');
    let currentSlide = 0;
    let slideInterval;

    function showSlide(index) {
        slides.forEach(function (slide) { slide.classList.remove('active'); });
        dots.forEach(function (dot) { dot.classList.remove('active'); });
        slides[index].classList.add('active');
        dots[index].classList.add('active');
        currentSlide = index;
    }

    function nextSlide() {
        showSlide((currentSlide + 1) % slides.length);
    }

    function resetSlider() {
        clearInterval(slideInterval);
        slideInterval = setInterval(nextSlide, 5000);
    }

    nextButton.addEventListener('click', function () {
        nextSlide();
        resetSlider();
    });

    previousButton.addEventListener('click', function () {
        showSlide((currentSlide - 1 + slides.length) % slides.length);
        resetSlider();
    });

    dots.forEach(function (dot, index) {
        dot.addEventListener('click', function () {
            showSlide(index);
            resetSlider();
        });
    });

    slideInterval = setInterval(nextSlide, 5000);
});
</script>

<?php include 'includes/footer.php'; ?>