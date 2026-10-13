<?php
require_once __DIR__ . '/includes/db.php';

$stmt = $pdo->query(
    "SELECT a.author_id, a.full_name, a.bio, a.author_image_path,
            COUNT(DISTINCT b.book_id) AS book_count
     FROM authors a
     JOIN book_authors ba ON ba.author_id = a.author_id
     JOIN books b ON b.book_id = ba.book_id
     JOIN book_genres bg ON bg.book_id = b.book_id
     JOIN genres g ON g.genre_id = bg.genre_id AND g.status = 'active'
     JOIN book_editions e ON e.book_id = b.book_id
     GROUP BY a.author_id, a.full_name, a.bio, a.author_image_path
     ORDER BY a.full_name"
);
$authors = $stmt->fetchAll();

function authorPhoto(?string $path): string
{
    $filename = basename(str_replace('\\', '/', (string) $path));
    return $filename !== '' ? '/images/authors/' . rawurlencode($filename) : '';
}

function authorInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
}

include __DIR__ . '/includes/header.php';
?>
<section class="container">
    <h1 class="card-title">Filipino Authors</h1>
    <?php if (!$authors): ?>
        <div class="card"><p class="card-text">Author profiles will appear here when books are added to the catalog.</p></div>
    <?php else: ?>
        <div class="catalog-grid">
            <?php foreach ($authors as $author): ?>
                <?php $photo = authorPhoto($author['author_image_path'] ?? null); ?>
                <article class="card catalog-card author-card" id="author-<?= (int) $author['author_id'] ?>">
                    <?php if ($photo !== ''): ?>
                        <img class="author-photo" src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="Photo of <?= htmlspecialchars($author['full_name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                    <?php else: ?>
                        <div class="author-photo author-photo-placeholder" aria-hidden="true"><?= htmlspecialchars(authorInitials($author['full_name']), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
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