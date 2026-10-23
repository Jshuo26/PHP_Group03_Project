<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/catalog_visibility.php';

$stmt = $pdo->query(
    "SELECT g.genre_id, g.genre_name, COUNT(DISTINCT b.book_id) AS book_count
     FROM genres g
     LEFT JOIN book_genres bg ON bg.genre_id = g.genre_id
     LEFT JOIN books b ON b.book_id = bg.book_id AND " . catalogVisibleBookSql('b') . "
     LEFT JOIN book_editions e ON e.book_id = b.book_id
     WHERE g.status = 'active'
     GROUP BY g.genre_id, g.genre_name
     ORDER BY g.genre_name"
);
$genres = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Explore by Genre</h1>
    <?php if (!$genres): ?>
        <div class="card"><p class="card-text">Genres will appear here as the catalog grows.</p></div>
    <?php else: ?>
        <div class="catalog-grid">
            <?php foreach ($genres as $genre): ?>
                <article class="card">
                    <h2><?= htmlspecialchars($genre['genre_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= (int) $genre['book_count'] ?> book<?= (int) $genre['book_count'] === 1 ? '' : 's' ?></p>
                    <a class="btn btn-primary" href="/books.php?genre=<?= (int) $genre['genre_id'] ?>">Browse genre</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>