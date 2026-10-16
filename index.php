<?php
require_once 'includes/db.php';

$spotlightAuthor = null;
$spotlightBook = null;
$featuredBooks = [];
$newBooks = [];
$genres = [];

try {
    $spotlightQuery = $pdo->query("SELECT ds.book_id, ds.author_id, a.full_name AS author_name, a.bio AS author_bio, b.title AS book_title, b.description AS book_description, (SELECT GROUP_CONCAT(g.genre_name ORDER BY g.genre_name SEPARATOR ', ') FROM book_genres bg JOIN genres g ON g.genre_id = bg.genre_id WHERE bg.book_id = b.book_id) AS genre_name, be.cover_image_path, (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ') FROM book_authors ba2 JOIN authors a2 ON a2.author_id = ba2.author_id WHERE ba2.book_id = b.book_id) AS book_authors
        FROM daily_spotlight ds
        JOIN authors a ON a.author_id = ds.author_id
        JOIN books b ON b.book_id = ds.book_id
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

        $bookQuery = $pdo->query("SELECT b.book_id, b.title, b.description, (SELECT GROUP_CONCAT(g.genre_name ORDER BY g.genre_name SEPARATOR ', ') FROM book_genres bg JOIN genres g ON g.genre_id = bg.genre_id WHERE bg.book_id = b.book_id) AS genre_name, be.cover_image_path, (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ') FROM book_authors ba2 JOIN authors a2 ON a2.author_id = ba2.author_id WHERE ba2.book_id = b.book_id) AS book_authors
            FROM books b
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
        WHERE EXISTS (SELECT 1 FROM book_genres bg JOIN genres g ON g.genre_id = bg.genre_id WHERE bg.book_id = b.book_id AND g.status = 'active')
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
        LIMIT 5");
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

function homepageViewBookButton(int $bookId): string
{
    return '<a href="/books.php?id=' . $bookId . '" class="view-book-btn">'
        . '<span>View Book</span>'
        . '<svg class="icon-outline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>'
        . '<svg class="icon-solid" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.25 4.533A9.707 9.707 0 0 0 6 3a9.735 9.735 0 0 0-3.25.555.75.75 0 0 0-.5.707v14.25a.75.75 0 0 0 1 .707A8.237 8.237 0 0 1 6 18.75c1.995 0 3.823.707 5.25 1.886V4.533ZM12.75 20.636A8.214 8.214 0 0 1 18 18.75c.966 0 1.89.166 2.75.47a.75.75 0 0 0 1-.708V4.262a.75.75 0 0 0-.5-.707A9.735 9.735 0 0 0 18 3a9.707 9.707 0 0 0-5.25 1.533v16.103Z" /></svg>'
        . '</a>';
}

function homepageBookTile(array $book): string
{
    $bookId = (int) $book['book_id'];
    $title = htmlspecialchars((string) $book['title'], ENT_QUOTES, 'UTF-8');
    $authors = htmlspecialchars($book['authors'] ?: 'Filipino Author', ENT_QUOTES, 'UTF-8');
    $price = number_format((float) $book['price'], 2);
    $coverUrl = !empty($book['cover_image_path']) ? homepageBookCover($book['cover_image_path']) : '';
    $cover = $coverUrl !== ''
        ? '<img src="' . htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . ' cover" loading="lazy">'
        : '<span>Book Cover</span>';

    return '<article class="book-tile">'
        . '<a class="book-tile-cover" href="/books.php?id=' . $bookId . '">' . $cover . '</a>'
        . '<div class="book-tile-body">'
        . '<h3 class="book-tile-title"><a href="/books.php?id=' . $bookId . '">' . $title . '</a></h3>'
        . '<p class="book-tile-author">' . $authors . '</p>'
        . '<div class="book-tile-footer">'
        . '<p class="book-tile-price">₱' . $price . '</p>'
        . homepageViewBookButton($bookId)
        . '</div>'
        . '</div>'
        . '</article>';
}

include 'includes/header.php';
?>

<section class="hero-slider">
    <div class="hero-slide active">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">Accessible. Assorted. Automated.</h1>
            <p class="hero-description">Discover Filipino authors and independent publishers, all in one shelf.</p>
            <a href="/books.php" class="hero-cta">Browse the Catalog</a>
        </div>
    </div>
    <div class="hero-slide">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">Discover Filipino Stories</h1>
            <p class="hero-description">Explore stories, ideas, and voices from Filipino writers.</p>
            <a href="/books.php" class="hero-cta">Browse the Catalog</a>
        </div>
    </div>
    <div class="hero-slide">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">Find Your Next Favorite Book</h1>
            <p class="hero-description">Browse our growing collection of local literature.</p>
            <a href="/books.php" class="hero-cta">Browse the Catalog</a>
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

<?php include __DIR__ . '/includes/spotlight_section.php'; ?>

<section class="home-section featured-books-section">
    <div class="section-container">
        <div class="section-heading-row">
            <h2 class="section-title">Featured Books</h2>
            <a href="/books.php" class="view-all-link">View All →</a>
        </div>
        <?php if ($featuredBooks): ?>
            <div class="book-grid">
                <?php foreach ($featuredBooks as $featured): ?>
                    <?= homepageBookTile($featured) ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="section-empty-message">Featured books will appear here as the catalog grows.</p>
        <?php endif; ?>
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
        <p>Behind every Filipino book is a writer who dared to tell our story, and often a small independent press that made it possible. When you buy local, your support goes straight to the people who write, edit, and publish our literature, so they can keep creating.</p>
        <ul class="filipino-banner-points">
            <li>
                <span class="point-icon" aria-hidden="true">✦ <b>Tangkilikin</b> </span>
                <p>Your purchase directly supports Filipino authors and helps them write their next book.</p>
            </li>
            <li>
                <span class="point-icon" aria-hidden="true">✦ <b>Pagtibayin</b> </span>
                <p>Small publishers take chances on new voices that bigger markets often overlook.</p>
            </li>
            <li>
                <span class="point-icon" aria-hidden="true">✦ <b>Pangalagaan</b> </span>
                <p>From folktales to modern fiction, local books carry our histories and everyday lives to the next generation.</p>
            </li>
        </ul>
        <a href="/books.php" class="hero-cta">Discover Filipino Books</a>
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
            <div class="book-grid">
                <?php foreach ($newBooks as $book): ?>
                    <?= homepageBookTile($book) ?>
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