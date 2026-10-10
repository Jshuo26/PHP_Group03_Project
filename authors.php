<?php
require_once __DIR__ . '/includes/db.php';

$stmt = $pdo->query(
    "SELECT a.author_id, a.full_name, a.bio,
            COUNT(DISTINCT b.book_id) AS book_count
     FROM authors a
     JOIN book_authors ba ON ba.author_id = a.author_id
     JOIN books b ON b.book_id = ba.book_id
     JOIN genres g ON g.genre_id = b.genre_id AND g.status = 'active'
     JOIN book_editions e ON e.book_id = b.book_id
     GROUP BY a.author_id, a.full_name, a.bio
     ORDER BY a.full_name"
);
$authors = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Filipino Authors</h1>
    <?php if (!$authors): ?>
        <div class="card"><p class="card-text">Author profiles will appear here when books are added to the catalog.</p></div>
    <?php else: ?>
        <div class="catalog-grid">
            <?php foreach ($authors as $author): ?>
                <article class="card">
                    <h2><?= htmlspecialchars($author['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php if ($author['bio']): ?><p><?= nl2br(htmlspecialchars($author['bio'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                    <p><?= (int) $author['book_count'] ?> book<?= (int) $author['book_count'] === 1 ? '' : 's' ?></p>
                    <a class="btn btn-primary" href="/books.php?search=<?= rawurlencode($author['full_name']) ?>">View books</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
