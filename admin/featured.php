<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/catalog.php';

requireRole(['admin', 'staff']);

$errors = [];
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$limit = 10;
$shelves = ['staff_picks' => 'Staff Picks', 'local_gems' => 'Local Gems'];

$eligibleSql = "
    SELECT b.book_id, b.title, b.description, b.language, b.publisher,
           MIN(e.price) AS price,
           (SELECT e2.cover_image_path FROM book_editions e2
             WHERE e2.book_id = b.book_id
             ORDER BY (e2.stock_quantity > 0) DESC, e2.edition_id ASC LIMIT 1) AS cover_image_path,
           GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ', ') AS authors,
           COALESCE((SELECT SUM(oi.quantity)
                       FROM order_items oi
                       JOIN orders o ON o.order_id = oi.order_id
                       JOIN book_editions sold_e ON sold_e.edition_id = oi.edition_id
                      WHERE sold_e.book_id = b.book_id AND o.status <> 'cancelled'), 0) AS units_sold
      FROM books b
      JOIN book_editions e ON e.book_id = b.book_id
      LEFT JOIN book_authors ba ON ba.book_id = b.book_id
      LEFT JOIN authors a ON a.author_id = ba.author_id
     WHERE b.status = 'active' AND b.review_status = 'approved'
       AND EXISTS (SELECT 1 FROM book_genres bg JOIN genres g ON g.genre_id = bg.genre_id
                    WHERE bg.book_id = b.book_id AND g.status = 'active')
     GROUP BY b.book_id, b.title, b.description, b.language, b.publisher, b.created_at
";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');
    try {
        $shelfKey = requestString($_POST, 'shelf_key');
        if (!isset($shelves[$shelfKey])) throw new RuntimeException('Choose a valid featured shelf.');
        $autoOrder = $shelfKey === 'local_gems'
            ? ' ORDER BY b.created_at DESC, units_sold ASC, b.book_id DESC'
            : ' ORDER BY units_sold DESC, b.created_at DESC, b.book_id DESC';
        $eligible = $pdo->query($eligibleSql . $autoOrder)->fetchAll();
        $eligibleById = [];
        foreach ($eligible as $book) $eligibleById[(int) $book['book_id']] = $book;

        if ($action === 'auto_pick') {
            $chosen = array_slice(array_keys($eligibleById), 0, $limit);
        } elseif ($action === 'save_picks') {
            $submitted = $_POST['featured_ids'] ?? [];
            $orderValues = $_POST['display_order'] ?? [];
            if (!is_array($submitted)) $submitted = [];
            if (count($submitted) > $limit) throw new RuntimeException('Choose up to ' . $limit . ' books for ' . $shelves[$shelfKey] . '.');
            $chosen = [];
            foreach ($submitted as $rawId) {
                $id = filter_var($rawId, FILTER_VALIDATE_INT);
                if (!$id || !isset($eligibleById[$id])) continue;
                $order = filter_var($orderValues[$id] ?? null, FILTER_VALIDATE_INT);
                $chosen[] = ['id' => $id, 'order' => $order !== false && $order > 0 ? $order : PHP_INT_MAX];
            }
            usort($chosen, static fn(array $a, array $b): int => ($a['order'] <=> $b['order']) ?: ($a['id'] <=> $b['id']));
            $chosen = array_column($chosen, 'id');
        } else {
            throw new RuntimeException('Choose a valid featured-books action.');
        }

        $pdo->beginTransaction();
        $delete = $pdo->prepare('DELETE FROM featured_books WHERE shelf_key = ?');
        $delete->execute([$shelfKey]);
        $insert = $pdo->prepare('INSERT INTO featured_books (shelf_key, book_id, display_order) VALUES (?, ?, ?)');
        foreach (array_values($chosen) as $index => $bookId) $insert->execute([$shelfKey, (int) $bookId, $index + 1]);
        auditLog($pdo, 'update', ($action === 'auto_pick' ? 'Automatically refreshed ' : 'Updated ') . $shelves[$shelfKey] . ' (' . count($chosen) . ' books).', 'products', (int) $_SESSION['user_id'], 'featured_books', null, 'success', (string) $_SESSION['role']);
        $pdo->commit();
        $_SESSION['admin_flash'] = count($chosen) . ' ' . $shelves[$shelfKey] . ' ' . (count($chosen) === 1 ? 'book was' : 'books were') . ' saved.';
        header('Location: /admin/featured.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save featured shelves. Apply database migration 015 and try again.';
    }
}

try {
    $books = $pdo->query($eligibleSql . ' ORDER BY units_sold DESC, b.created_at DESC, b.book_id DESC')->fetchAll();
    $selectedStmt = $pdo->query('SELECT shelf_key, book_id, display_order FROM featured_books ORDER BY shelf_key, display_order, added_at DESC');
    $selected = array_fill_keys(array_keys($shelves), []);
    foreach ($selectedStmt->fetchAll() as $row) $selected[$row['shelf_key']][(int) $row['book_id']] = (int) $row['display_order'];
} catch (PDOException $e) {
    error_log('Admin featured picks could not load: ' . $e->getMessage());
    $books = [];
    $selected = array_fill_keys(array_keys($shelves), []);
    $errors[] = 'Featured shelves are unavailable. Apply database/migrations/015_featured_shelves.sql, then reload this page.';
}

include __DIR__ . '/../includes/header.php';
?>
<main class="container admin-operations admin-featured-page">
    <header class="admin-page-hero">
        <div><span class="admin-hero-eyebrow">CUSTOMER DISCOVERY</span><h1>Featured Books</h1><p>Curate two customer shelves: Staff Picks and Local Gems. Each shelf can show up to <?= $limit ?> books.</p></div>
        <div class="hero-meta"><span class="featured-pick-count"><strong><?= array_sum(array_map('count', $selected)) ?></strong> / <?= $limit * count($shelves) ?> selected</span></div>
    </header>
    <?php if ($errors): ?><div class="operations-alert error" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>

    <section class="featured-curation-toolbar card" aria-label="Featured selection tools">
        <div><span class="operations-eyebrow">CURATION TOOLS</span><h2>Build Customer Shelves</h2><p>Only active, approved books with an active genre can be featured. Changes appear on the customer page as soon as they are saved.</p></div>
        <div class="featured-toolbar-actions">
            <?php foreach ($shelves as $key => $label): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="auto_pick"><input type="hidden" name="shelf_key" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-secondary" type="submit">Auto-Fill <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button></form><?php endforeach; ?>
            <a class="btn btn-primary" href="/featured.php" target="_blank" rel="noopener">Preview Featured Page <span aria-hidden="true">↗</span></a>
        </div>
    </section>

    <?php foreach ($shelves as $shelfKey => $shelfLabel): ?><form class="featured-curation-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_picks">
        <input type="hidden" name="shelf_key" value="<?= htmlspecialchars($shelfKey, ENT_QUOTES, 'UTF-8') ?>">
        <div class="featured-library-heading"><div><span class="operations-eyebrow"><?= htmlspecialchars($shelfLabel, ENT_QUOTES, 'UTF-8') ?></span><h2><?= htmlspecialchars($shelfLabel, ENT_QUOTES, 'UTF-8') ?></h2><p>Select up to <?= $limit ?> books for this shelf and set their display order.</p></div><label class="featured-search">Search Catalog<input type="search" data-featured-search placeholder="Title or author"></label></div>
        <?php if (!$books): ?><div class="operations-empty"><h2>No Eligible Books Yet</h2><p>Approved, active books with an active genre will appear here.</p></div><?php else: ?>
            <div class="featured-admin-grid">
                <?php foreach ($books as $book): $id = (int) $book['book_id']; $cover = catalogCover($book['cover_image_path'] ?? null); ?>
                    <article class="featured-admin-card" data-search="<?= htmlspecialchars(mb_strtolower((string) $book['title'] . ' ' . (string) ($book['authors'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="featured-admin-cover"><?php if ($cover !== ''): ?><img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $book['title'], ENT_QUOTES, 'UTF-8') ?> cover" loading="lazy"><?php else: ?><?= catalogCoverPlaceholder((string) $book['title'], 'featured-admin-placeholder') ?><?php endif; ?></div>
                        <div class="featured-admin-card-body"><span class="featured-sales-badge"><?= (int) $book['units_sold'] ?> sold</span><h3><?= htmlspecialchars((string) $book['title'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) ($book['authors'] ?: 'Author not listed'), ENT_QUOTES, 'UTF-8') ?></p><div class="featured-admin-controls"><label><input type="checkbox" name="featured_ids[]" value="<?= $id ?>" <?= isset($selected[$shelfKey][$id]) ? 'checked' : '' ?>> Feature</label><label>Order<input type="number" name="display_order[<?= $id ?>]" min="1" max="<?= $limit ?>" value="<?= isset($selected[$shelfKey][$id]) ? (int) $selected[$shelfKey][$id] : '' ?>" placeholder="—" <?= isset($selected[$shelfKey][$id]) ? '' : 'disabled' ?>></label></div></div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="featured-save-bar"><p><strong><?= count($selected[$shelfKey]) ?></strong> selected · maximum <?= $limit ?></p><button class="btn btn-primary" type="submit">Save <?= htmlspecialchars($shelfLabel, ENT_QUOTES, 'UTF-8') ?></button></div>
        <?php endif; ?>
    </form><?php endforeach; ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-featured-search]').forEach(function (search) {
    search.addEventListener('input', function () {
      var query = search.value.trim().toLowerCase();
      search.closest('.featured-curation-form').querySelectorAll('.featured-admin-card').forEach(function (card) { card.hidden = query !== '' && !card.dataset.search.includes(query); });
    });
  });
  document.querySelectorAll('.featured-admin-controls input[type="checkbox"]').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
      var order = checkbox.closest('.featured-admin-controls').querySelector('input[type="number"]');
      order.disabled = !checkbox.checked;
      if (!checkbox.checked) order.value = '';
      else if (!order.value) order.value = String(checkbox.closest('.featured-curation-form').querySelectorAll('.featured-admin-controls input[type="checkbox"]:checked').length);
    });
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
