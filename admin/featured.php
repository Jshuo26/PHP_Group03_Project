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
        $eligible = $pdo->query($eligibleSql . ' ORDER BY units_sold DESC, b.created_at DESC, b.book_id DESC')->fetchAll();
        $eligibleById = [];
        foreach ($eligible as $book) $eligibleById[(int) $book['book_id']] = $book;

        if ($action !== 'save_curation') {
            throw new RuntimeException('Choose a valid featured-books action.');
        }

        $submitted = $_POST['featured_ids'] ?? [];
        $shelfValues = $_POST['shelf_key'] ?? [];
        $orderValues = $_POST['display_order'] ?? [];
        if (!is_array($submitted)) $submitted = [];
        if (!is_array($shelfValues)) $shelfValues = [];
        if (!is_array($orderValues)) $orderValues = [];
        $chosen = array_fill_keys(array_keys($shelves), []);
        foreach (array_unique($submitted) as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if (!$id || !isset($eligibleById[$id])) continue;
            $key = (string) ($shelfValues[$id] ?? '');
            if (!isset($shelves[$key])) continue;
            $order = filter_var($orderValues[$id] ?? null, FILTER_VALIDATE_INT);
            $chosen[$key][] = ['id' => $id, 'order' => $order !== false && $order > 0 ? $order : PHP_INT_MAX];
        }
        foreach ($chosen as $key => $items) {
            if (count($items) > $limit) throw new RuntimeException('Choose up to ' . $limit . ' books for ' . $shelves[$key] . '.');
            usort($items, static fn(array $a, array $b): int => ($a['order'] <=> $b['order']) ?: ($a['id'] <=> $b['id']));
            $chosen[$key] = array_column($items, 'id');
        }

        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM featured_books');
        $insert = $pdo->prepare('INSERT INTO featured_books (shelf_key, book_id, display_order) VALUES (?, ?, ?)');
        foreach ($chosen as $key => $ids) {
            foreach (array_values($ids) as $index => $bookId) $insert->execute([$key, (int) $bookId, $index + 1]);
        }
        $totalChosen = count($chosen['staff_picks']) + count($chosen['local_gems']);
        auditLog($pdo, 'update', 'Updated featured shelves: ' . count($chosen['staff_picks']) . ' Staff Picks and ' . count($chosen['local_gems']) . ' Local Gems.', 'products', (int) $_SESSION['user_id'], 'featured_books', null, 'success', (string) $_SESSION['role']);
        $pdo->commit();
        $_SESSION['admin_flash'] = $totalChosen . ' featured ' . ($totalChosen === 1 ? 'book was' : 'books were') . ' saved across both shelves.';
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
        <div><span class="operations-eyebrow">CURATION TOOLS</span><h2>Choose Your Featured Books</h2><p>Select each title once, assign it to a shelf, and set its display position. Each shelf holds up to <?= $limit ?> books.</p></div>
        <a class="btn btn-primary" href="/featured.php" target="_blank" rel="noopener">Preview Featured Page <span aria-hidden="true">↗</span></a>
    </section>

    <form class="featured-curation-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_curation">
        <div class="featured-library-heading"><div><span class="operations-eyebrow">ELIGIBLE CATALOG</span><h2>Featured Book Catalog</h2><p>Check a book to feature it. Choose Staff Pick or Local Gem, then set its order on the shelf.</p></div><label class="featured-search">Search Catalog<input type="search" data-featured-search placeholder="Title or author"></label></div>
        <?php if (!$books): ?><div class="operations-empty"><h2>No Eligible Books Yet</h2><p>Approved, active books with an active genre will appear here.</p></div><?php else: ?>
            <div class="featured-admin-grid">
                <?php foreach ($books as $book): $id = (int) $book['book_id']; $cover = catalogCover($book['cover_image_path'] ?? null); ?>
                    <?php $assignedShelf = isset($selected['staff_picks'][$id]) ? 'staff_picks' : (isset($selected['local_gems'][$id]) ? 'local_gems' : 'staff_picks'); $isSelected = isset($selected['staff_picks'][$id]) || isset($selected['local_gems'][$id]); $bookOrder = $selected[$assignedShelf][$id] ?? ''; ?>
                    <article class="featured-admin-card" data-search="<?= htmlspecialchars(mb_strtolower((string) $book['title'] . ' ' . (string) ($book['authors'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="featured-admin-cover"><?php if ($cover !== ''): ?><img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $book['title'], ENT_QUOTES, 'UTF-8') ?> cover" loading="lazy"><?php else: ?><?= catalogCoverPlaceholder((string) $book['title'], 'featured-admin-placeholder') ?><?php endif; ?></div>
                        <div class="featured-admin-card-body"><div class="featured-book-title-row"><h3><?= htmlspecialchars((string) $book['title'], ENT_QUOTES, 'UTF-8') ?></h3><span class="featured-sales-badge"><?= (int) $book['units_sold'] ?> sold</span></div><p><?= htmlspecialchars((string) ($book['authors'] ?: 'Author not listed'), ENT_QUOTES, 'UTF-8') ?></p><div class="featured-admin-controls"><label class="featured-book-check"><input type="checkbox" name="featured_ids[]" value="<?= $id ?>" <?= $isSelected ? 'checked' : '' ?>><span><?= $isSelected ? 'Featured' : 'Feature this book' ?></span></label><label class="featured-shelf-select">Shelf<select name="shelf_key[<?= $id ?>]" <?= $isSelected ? '' : 'disabled' ?>><option value="staff_picks" <?= $assignedShelf === 'staff_picks' ? 'selected' : '' ?>>Staff Pick</option><option value="local_gems" <?= $assignedShelf === 'local_gems' ? 'selected' : '' ?>>Local Gem</option></select></label><label class="featured-order-control">Order<input type="number" name="display_order[<?= $id ?>]" min="1" max="<?= $limit ?>" value="<?= htmlspecialchars((string) $bookOrder, ENT_QUOTES, 'UTF-8') ?>" placeholder="—" <?= $isSelected ? '' : 'disabled' ?>></label></div></div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="featured-save-bar"><p data-featured-selection-count aria-live="polite"><strong><?= array_sum(array_map('count', $selected)) ?></strong> titles selected. Staff Picks: <strong><?= count($selected['staff_picks']) ?>/<?= $limit ?></strong> · Local Gems: <strong><?= count($selected['local_gems']) ?>/<?= $limit ?></strong></p><button class="btn btn-primary" type="submit">Save Featured Books</button></div>
        <?php endif; ?>
    </form>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function syncSelection(form) {
    var selected = {staff_picks: 0, local_gems: 0};
    form.querySelectorAll('.featured-admin-card').forEach(function (card) {
      var checkbox = card.querySelector('input[type="checkbox"]');
      var shelf = card.querySelector('select[name^="shelf_key"]');
      var order = card.querySelector('input[type="number"]');
      shelf.disabled = !checkbox.checked;
      order.disabled = !checkbox.checked;
      shelf.dataset.current = shelf.value;
      card.classList.toggle('is-featured', checkbox.checked);
      var text = checkbox.parentElement.querySelector('span');
      text.textContent = checkbox.checked ? 'Featured' : 'Feature this book';
      if (checkbox.checked) selected[shelf.value]++;
    });
    var counts = form.querySelector('[data-featured-selection-count]');
    counts.innerHTML = '<strong>' + (selected.staff_picks + selected.local_gems) + '</strong> titles selected. Staff Picks: <strong>' + selected.staff_picks + '/10</strong> · Local Gems: <strong>' + selected.local_gems + '/10</strong>';
  }
  document.querySelectorAll('[data-featured-search]').forEach(function (search) {
    search.addEventListener('input', function () {
      var query = search.value.trim().toLowerCase();
      search.closest('.featured-curation-form').querySelectorAll('.featured-admin-card').forEach(function (card) { card.hidden = query !== '' && !card.dataset.search.includes(query); });
    });
  });
  document.querySelectorAll('.featured-curation-form').forEach(function (form) {
    syncSelection(form);
    form.querySelectorAll('.featured-admin-card input[type="checkbox"], .featured-admin-card select').forEach(function (control) {
      control.addEventListener('change', function () {
        var card = control.closest('.featured-admin-card');
        var checkbox = card.querySelector('input[type="checkbox"]');
        var shelf = card.querySelector('select[name^="shelf_key"]');
        var order = card.querySelector('input[type="number"]');
        if (control === checkbox && checkbox.checked) {
          var counts = {staff_picks: 0, local_gems: 0};
          form.querySelectorAll('.featured-admin-card').forEach(function (entry) {
            var picked = entry.querySelector('input[type="checkbox"]');
            var assigned = entry.querySelector('select[name^="shelf_key"]');
            if (picked.checked && picked !== checkbox) counts[assigned.value]++;
          });
          if (counts[shelf.value] >= 10 && counts.local_gems < 10) {
            shelf.value = 'local_gems';
          } else if (counts[shelf.value] >= 10 && counts.staff_picks < 10) {
            shelf.value = 'staff_picks';
          } else if (counts.staff_picks >= 10 && counts.local_gems >= 10) {
            checkbox.checked = false;
            window.alert('Both featured shelves already contain 10 books. Remove a title before adding another.');
          }
          if (checkbox.checked && !order.value) order.value = String(counts[shelf.value] + 1);
        }
        if (control === checkbox && !checkbox.checked) order.value = '';
        if (control === shelf) {
          var counts = {staff_picks: 0, local_gems: 0};
          form.querySelectorAll('.featured-admin-card').forEach(function (entry) {
            var picked = entry.querySelector('input[type="checkbox"]');
            var assigned = entry.querySelector('select[name^="shelf_key"]');
            if (picked.checked) counts[assigned.value]++;
          });
          if (counts[shelf.value] > 10) {
            shelf.value = shelf.dataset.current;
            window.alert('Each featured shelf can contain up to 10 books.');
          }
        }
        syncSelection(form);
      });
    });
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
