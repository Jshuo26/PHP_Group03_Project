<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/author_verification.php';

requireRole(['admin', 'staff']);
$errors = [];
$serverScan = null;
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$formats = ['paperback', 'hardbound', 'ebook'];
$statuses = ['active', 'inactive'];
$form = [
    'book_id' => 0,
    'edition_id' => 0,
    'title' => '',
    'description' => '',
    'isbn' => '',
    'language' => 'English',
    'publisher' => '',
    'status' => 'active',
    'genre_ids' => [],
    'author' => '',
    'format' => '',
    'price' => '',
    'stock_quantity' => ''
];

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT b.book_id, e.edition_id, b.title, b.description, b.isbn,
                b.language, b.publisher, b.status,
                e.format, e.price, e.stock_quantity,
                GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR "; ") AS author  -- CHANGED: "; "
         FROM book_editions e
         JOIN books b ON b.book_id = e.book_id
         LEFT JOIN book_authors ba ON ba.book_id = b.book_id
         LEFT JOIN authors a ON a.author_id = ba.author_id
         WHERE e.edition_id = ?
         GROUP BY b.book_id, e.edition_id, b.title, b.description, b.isbn,
                  b.language, b.publisher, b.status,
                  e.format, e.price, e.stock_quantity'
    );
    $editStmt->execute([$editId]);
    $loaded = $editStmt->fetch();
    if ($loaded) {
        $form = array_merge($form, $loaded);
        $genreStmt = $pdo->prepare('SELECT genre_id FROM book_genres WHERE book_id = ?');
        $genreStmt->execute([(int) $loaded['book_id']]);
        $form['genre_ids'] = array_map('intval', $genreStmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $errors[] = 'The selected book edition could not be found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');

    if ($action === 'delete') {
        $bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT) ?: 0;
        if ($bookId < 1) {
            $errors[] = 'Select a valid book to delete.';
        } else {
            try {
                $deleteStmt = $pdo->prepare('DELETE FROM books WHERE book_id = ?');
                $deleteStmt->execute([$bookId]);
                if ($deleteStmt->rowCount() !== 1) {
                    $errors[] = 'Book not found.';
                } else {
                    $_SESSION['admin_flash'] = 'Book deleted.';
                    header('Location: /admin/products.php');
                    exit;
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'This book cannot be deleted because it appears in an order or spotlight. Set its status to inactive instead.';
                } else {
                    error_log('Book deletion failed: ' . $e->getMessage());
                    $errors[] = 'Unable to delete this book.';
                }
            }
        }
    } elseif (in_array($action, ['add', 'edit'], true)) {
        $rawGenreIds = $_POST['genre_ids'] ?? [];
        $genreIds = [];
        if (is_array($rawGenreIds)) {
            foreach ($rawGenreIds as $rawGenreId) {
                if (is_scalar($rawGenreId) && filter_var((string) $rawGenreId, FILTER_VALIDATE_INT) !== false) {
                    $genreIds[] = (int) $rawGenreId;
                }
            }
        }
        $genreIds = array_values(array_unique(array_filter($genreIds, static fn($id) => $id > 0)));

        $form = [
            'book_id' => filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT) ?: 0,
            'edition_id' => filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT) ?: 0,
            'title' => trim(requestString($_POST, 'title')),
            'description' => trim(requestString($_POST, 'description')),
            'isbn' => trim(requestString($_POST, 'isbn')),
            'language' => trim(requestString($_POST, 'language')),
            'publisher' => trim(requestString($_POST, 'publisher')),
            'status' => requestString($_POST, 'status'),
            'genre_ids' => $genreIds,
            'author' => trim(requestString($_POST, 'author')),
            'format' => requestString($_POST, 'format'),
            'price' => trim(requestString($_POST, 'price')),
            'stock_quantity' => trim(requestString($_POST, 'stock_quantity'))
        ];
        $isEdit = $action === 'edit';

        if ($form['title'] === '' || mb_strlen($form['title']) > 255) {
            $errors[] = 'Book title is required and must not exceed 255 characters.';
        }
        if (strlen($form['description']) > 65535) {
            $errors[] = 'Description is too long.';
        }
        if (strlen($form['isbn']) > 20) {
            $errors[] = 'ISBN must not exceed 20 characters.';
        }
        if ($form['language'] === '' || mb_strlen($form['language']) > 50) {
            $errors[] = 'Language is required and must not exceed 50 characters.';
        }
        if (mb_strlen($form['publisher']) > 150) {
            $errors[] = 'Publisher must not exceed 150 characters.';
        }
        if (!in_array($form['status'], $statuses, true)) {
            $errors[] = 'Select a valid status.';
        }
        if (!$genreIds) {
            $errors[] = 'Select at least one genre.';
        }
        $authorNames = parseAuthorNames($form['author']);
        $authorError = validateAuthorNames($authorNames);

        if ($authorError !== null) {
            $errors[] = $authorError;
        } else {
            try {
                $serverScan = scanAuthorNames($pdo, $authorNames);
                if (requestString($_POST, 'scan_claimed') === '1' && !$serverScan['can_auto_approve']) {
                    $errors[] = 'Author records changed since the scan. Review the results below, then save again.';
                }
            } catch (Throwable $e) {
                error_log('Author revalidation failed: ' . $e->getMessage());
                $errors[] = 'Unable to verify the authors right now. Please try again.';
            }
        }
        if (!in_array($form['format'], $formats, true)) {
            $errors[] = 'Select a valid book format.';
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $form['price']) || (float) $form['price'] < 0) {
            $errors[] = 'Price must be a non-negative number with up to two decimal places.';
        }
        if (!preg_match('/^\d{1,7}$/', $form['stock_quantity'])) {
            $errors[] = 'Stock must be a whole number between 0 and 9,999,999.';
        }
        if ($isEdit && ((int) $form['book_id'] < 1 || (int) $form['edition_id'] < 1)) {
            $errors[] = 'Select a valid book edition to edit.';
        }

        if ($genreIds) {
            $placeholders = implode(',', array_fill(0, count($genreIds), '?'));
            $genreStmt = $pdo->prepare("SELECT genre_id FROM genres WHERE status = 'active' AND genre_id IN ($placeholders)");
            $genreStmt->execute($genreIds);
            $activeGenreIds = array_map('intval', $genreStmt->fetchAll(PDO::FETCH_COLUMN));
            if (count($activeGenreIds) !== count($genreIds)) {
                $errors[] = 'One or more selected genres are no longer available. Select active genres only.';
            }
        }

        $uploadName = null;
        $uploadTmpPath = null;
        if (isset($_FILES['cover']) && (!is_array($_FILES['cover'])
            || !isset($_FILES['cover']['error'], $_FILES['cover']['tmp_name'], $_FILES['cover']['size'])
            || !is_int($_FILES['cover']['error'])
            || !is_string($_FILES['cover']['tmp_name'])
            || !is_int($_FILES['cover']['size']))) {
            $errors[] = 'The cover image upload is invalid.';
        } elseif (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['cover'];
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'The cover image could not be uploaded.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Cover images must be 5 MB or smaller.';
            } else {
                $imageInfo = getimagesize($file['tmp_name']);
                $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    $errors[] = 'Cover image must be a JPEG, PNG, or WebP file.';
                } else {
                    $uploadName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $uploadTmpPath = $file['tmp_name'];
                }
            }
        }

        if (!$errors) {
            $coverDirectory = __DIR__ . '/../images/book-covers';
            $targetCover = $uploadName !== null ? $coverDirectory . '/' . $uploadName : null;
            if ($targetCover !== null && !is_dir($coverDirectory)) {
                $errors[] = 'The book cover directory does not exist.';
            } elseif ($targetCover !== null && !move_uploaded_file($uploadTmpPath, $targetCover)) {
                $errors[] = 'Unable to save the cover image.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $descriptionValue = $form['description'] !== '' ? $form['description'] : null;
                    $isbnValue = $form['isbn'] !== '' ? $form['isbn'] : null;
                    $publisherValue = $form['publisher'] !== '' ? $form['publisher'] : null;

                    if ($isEdit) {
                        $existsStmt = $pdo->prepare('SELECT 1 FROM book_editions WHERE edition_id = ? AND book_id = ? FOR UPDATE');
                        $existsStmt->execute([(int) $form['edition_id'], (int) $form['book_id']]);
                        if (!$existsStmt->fetchColumn()) {
                            throw new RuntimeException('Book edition not found.');
                        }

                        $bookStmt = $pdo->prepare(
                            'UPDATE books
                                SET title = ?, description = ?, isbn = ?,
                                    language = ?, publisher = ?, status = ?
                              WHERE book_id = ?'
                        );
                        $bookStmt->execute([
                            $form['title'],
                            $descriptionValue,
                            $isbnValue,
                            $form['language'],
                            $publisherValue,
                            $form['status'],
                            (int) $form['book_id']
                        ]);
                        $editionSql = 'UPDATE book_editions SET format = ?, price = ?, stock_quantity = ?';
                        $editionParams = [$form['format'], $form['price'], (int) $form['stock_quantity']];
                        if ($uploadName !== null) {
                            $editionSql .= ', cover_image_path = ?';
                            $editionParams[] = $uploadName;
                        }
                        $editionSql .= ' WHERE edition_id = ?';
                        $editionParams[] = (int) $form['edition_id'];
                        $pdo->prepare($editionSql)->execute($editionParams);
                        $bookId = (int) $form['book_id'];
                    } else {
                        $bookStmt = $pdo->prepare(
                            'INSERT INTO books (title, description, isbn, language, publisher, status)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $bookStmt->execute([
                            $form['title'],
                            $descriptionValue,
                            $isbnValue,
                            $form['language'],
                            $publisherValue,
                            $form['status']
                        ]);
                        $bookId = (int) $pdo->lastInsertId();
                        $editionStmt = $pdo->prepare(
                            'INSERT INTO book_editions (book_id, format, price, stock_quantity, cover_image_path)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        $editionStmt->execute([
                            $bookId,
                            $form['format'],
                            $form['price'],
                            (int) $form['stock_quantity'],
                            $uploadName
                        ]);
                    }

                    $pdo->prepare('DELETE FROM book_genres WHERE book_id = ?')->execute([$bookId]);
                    $genreInsert = $pdo->prepare('INSERT INTO book_genres (book_id, genre_id) VALUES (?, ?)');
                    foreach ($genreIds as $genreId) {
                        $genreInsert->execute([$bookId, $genreId]);
                    }
                    $reviewOutcome = linkBookAuthors($pdo, $bookId, $authorNames, (int) $_SESSION['user_id']);
                    $pdo->commit();

                    $flash = $isEdit ? 'Book updated.' : 'Book added.';
                    if ($reviewOutcome['review_status'] !== 'approved') {
                        $flash .= ' Author review: ' . bookReviewLabel($reviewOutcome['review_status'])
                                . '. The book stays hidden from customers until it is approved.';
                        $unresolved = [];
                        foreach ($reviewOutcome['authors'] as $linked) {
                            if ($linked['status'] !== 'verified') {
                                $unresolved[] = $linked['name'];
                            }
                        }
                        if ($unresolved) {
                            $flash .= ' Needs review: ' . implode('; ', $unresolved) . '.';
                        }
                    }
                    $_SESSION['admin_flash'] = $flash;
                    header('Location: /admin/products.php');
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($targetCover !== null && is_file($targetCover)) {
                        unlink($targetCover);
                    }
                    if ($e->getCode() === '23000') {
                        $errors[] = 'ISBN or format already exists for another book edition.';
                    } else {
                        error_log('Book save failed: ' . $e->getMessage());
                        $errors[] = 'Unable to save the book. Please check the submitted information.';
                    }
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($targetCover !== null && is_file($targetCover)) {
                        unlink($targetCover);
                    }
                    $errors[] = $e->getMessage();
                }
            }
        }
    } else {
        $errors[] = 'Choose a valid product action.';
    }
}

$genres = $pdo->query("SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name")->fetchAll();
$products = $pdo->query(
    'SELECT b.book_id, e.edition_id, b.title, b.description, b.isbn,
            b.language, b.publisher, b.status, b.review_status,
            GROUP_CONCAT(DISTINCT g.genre_name ORDER BY g.genre_name SEPARATOR ", ") AS genre_names,
            e.format, e.price, e.stock_quantity, e.cover_image_path,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS authors
     FROM books b
     JOIN book_editions e ON e.book_id = b.book_id
     LEFT JOIN book_genres bg ON bg.book_id = b.book_id
     LEFT JOIN genres g ON g.genre_id = bg.genre_id
     LEFT JOIN book_authors ba ON ba.book_id = b.book_id
     LEFT JOIN authors a ON a.author_id = ba.author_id
     GROUP BY b.book_id, e.edition_id, b.title, b.description, b.isbn,
              b.language, b.publisher, b.status, b.review_status,
              e.format, e.price, e.stock_quantity, e.cover_image_path
     ORDER BY b.created_at DESC, b.title, e.format'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <div class="card">
        <h1 class="card-title">Product Management</h1>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <h2><?= (int) $form['edition_id'] > 0 ? 'Edit book edition' : 'Add a book' ?></h2>
        <?php if (!$genres): ?>
            <p>Add an active genre before adding books.</p>
            <a class="btn btn-primary" href="/admin/genres.php">Manage categories</a>
        <?php else: ?>
            <form
                id="book-form"
                method="post"
                action="/admin/products.php<?= (int) $form['edition_id'] > 0 ? '?edit=' . (int) $form['edition_id'] : '' ?>"
                enctype="multipart/form-data"
            >
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= (int) $form['edition_id'] > 0 ? 'edit' : 'add' ?>">
                <input type="hidden" name="book_id" value="<?= (int) $form['book_id'] ?>">
                <input type="hidden" name="edition_id" value="<?= (int) $form['edition_id'] ?>">
                <input type="hidden" name="scan_claimed" id="scan_claimed" value="0">
                <div class="form-group"><label for="title">Book title</label><input class="form-input" type="text" id="title" name="title" maxlength="255" required value="<?= htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="author">Author</label><input class="form-input" type="text" id="author" name="author" maxlength="600" required value="<?= htmlspecialchars((string) $form['author'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <small>Separate several authors with a semicolon (;). Books with unverified authors stay hidden until reviewed.</small>
                <div id="author-scan-results" class="as-results" aria-live="polite"></div> <!-- CHANGED -->
                <div class="form-group">
                    <label for="genre_ids">Genres (select one or more)</label>
                    <select class="form-input" id="genre_ids" name="genre_ids[]" multiple required size="<?= min(max(count($genres), 3), 7) ?>">
                        <?php foreach ($genres as $genre): ?>
                            <option value="<?= (int) $genre['genre_id'] ?>" <?= in_array((int) $genre['genre_id'], array_map('intval', $form['genre_ids'] ?? []), true) ? 'selected' : '' ?>><?= htmlspecialchars($genre['genre_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Hold Ctrl (Windows) or Command (Mac) to select multiple genres.</small>
                </div>
                <div class="form-group"><label for="description">Description</label><textarea class="form-input" id="description" name="description" rows="4" maxlength="65535"><?= htmlspecialchars((string) ($form['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
                <div class="form-group"><label for="isbn">ISBN (optional)</label><input class="form-input" type="text" id="isbn" name="isbn" maxlength="20" value="<?= htmlspecialchars((string) ($form['isbn'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="language">Language</label><input class="form-input" type="text" id="language" name="language" maxlength="50" required placeholder="English, Filipino, Tagalog..." value="<?= htmlspecialchars((string) ($form['language'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="publisher">Publisher (optional)</label><input class="form-input" type="text" id="publisher" name="publisher" maxlength="150" value="<?= htmlspecialchars((string) ($form['publisher'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select class="form-input" id="status" name="status" required>
                        <?php foreach ($statuses as $statusOption): ?>
                            <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= ($form['status'] ?? 'active') === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Inactive books are hidden from the public catalog.</small>
                </div>
                <div class="form-group"><label for="format">Format</label><select class="form-input" id="format" name="format" required><option value="">Select a format</option><?php foreach ($formats as $format): ?><option value="<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?>" <?= $form['format'] === $format ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($format), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="price">Price (₱)</label><input class="form-input" type="number" id="price" name="price" min="0" max="99999999.99" step="0.01" required value="<?= htmlspecialchars((string) $form['price'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="stock_quantity">Stock quantity</label><input class="form-input" type="number" id="stock_quantity" name="stock_quantity" min="0" max="9999999" step="1" required value="<?= htmlspecialchars((string) $form['stock_quantity'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="cover">Book cover (JPEG, PNG, WebP; max 5 MB)</label><input class="form-input" type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"></div>
                <button class="btn btn-primary" type="submit">Save &amp; Scan</button>
                <?php if ((int) $form['edition_id'] > 0): ?><a class="btn btn-secondary" href="/admin/products.php">Cancel edit</a><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <h2 class="section-title">Catalog books and editions</h2>
    <?php if (!$products): ?><div class="card"><p class="card-text">No books have been added yet.</p></div><?php endif; ?>
    <?php foreach ($products as $product): ?>
        <article class="card admin-list-item">
            <?php if ($product['cover_image_path']): ?><img class="admin-cover" src="/images/book-covers/<?= rawurlencode(basename(str_replace('\\', '/', $product['cover_image_path']))) ?>" alt="<?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?> cover"><?php endif; ?>
            <h3><?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p>Author: <?= htmlspecialchars($product['authors'] ?: 'Not listed', ENT_QUOTES, 'UTF-8') ?> · Genres: <?= htmlspecialchars($product['genre_names'] ?: 'Not assigned', ENT_QUOTES, 'UTF-8') ?></p>
                        <p>Language: <?= htmlspecialchars($product['language'], ENT_QUOTES, 'UTF-8') ?><?php if ($product['publisher']): ?> · Publisher: <?= htmlspecialchars($product['publisher'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?> · Status: <?= htmlspecialchars(ucfirst($product['status']), ENT_QUOTES, 'UTF-8') ?> · Author review: <?= htmlspecialchars(bookReviewLabel($product['review_status']), ENT_QUOTES, 'UTF-8') ?></p>
            <?php if ($product['isbn']): ?><p>ISBN: <?= htmlspecialchars($product['isbn'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <p><?= htmlspecialchars(ucfirst($product['format']), ENT_QUOTES, 'UTF-8') ?> · ₱<?= number_format((float) $product['price'], 2) ?> · Stock: <?= (int) $product['stock_quantity'] ?></p>
            <a class="btn btn-secondary" href="/admin/products.php?edit=<?= (int) $product['edition_id'] ?>">Edit</a>
            <form class="inline-form" method="post" action="/admin/products.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="book_id" value="<?= (int) $product['book_id'] ?>">
                <button class="btn btn-secondary" type="submit" name="action" value="delete" onclick="return confirm('Delete this book and its editions?')">Delete book</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>

<link rel="stylesheet" href="/css/admin-author-review.css">
<?php if (!empty($serverScan)): ?>
<script>window.__initialScan = <?= json_encode($serverScan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<?php endif; ?>
<script>
(function () {
  var form = document.getElementById('book-form');
  if (!form) { return; }
  var field = document.getElementById('author');
  var box = document.getElementById('author-scan-results');
  var claimed = document.getElementById('scan_claimed');
  var token = form.querySelector('input[name="csrf_token"]').value;
  var okFor = null, busy = false;

  function node(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }
  function useName(oldName, newName) {
    field.value = field.value.split(/[;\r\n]+/).map(function (p) {
      p = p.trim().replace(/\s+/g, ' ');
      return p === oldName ? newName : p;
    }).filter(Boolean).join('; ');
    okFor = null; claimed.value = '0'; box.textContent = '';
  }
  function render(data) {
    box.textContent = '';
    var s = data.summary;
    box.appendChild(node('p', 'as-summary', s.verified + ' of ' + s.total + ' author(s) verified. ' +
      (data.can_auto_approve ? 'This book will be approved and shown to customers.'
                             : 'This book will stay pending and hidden from customers until reviewed.')));
    data.authors.forEach(function (a) {
      var card = node('div', 'as-card as-' + a.status);
      var head = node('div', 'as-head');
      head.appendChild(node('strong', '', a.input));
      head.appendChild(node('span', 'as-badge', a.label));
      card.appendChild(head);
      if (a.author) { card.appendChild(node('p', 'as-line', 'Matches: ' + a.author.full_name + ' (#' + a.author.author_id + ')')); }
      (a.candidates || []).forEach(function (c) { card.appendChild(node('p', 'as-line', 'Candidate: ' + c.full_name + ' (#' + c.author_id + ')')); });
      (a.possible || []).forEach(function (p) {
        var row = node('p', 'as-line', 'Did you mean ' + p.full_name + '? (' + p.reason + ') ');
        var b = node('button', 'btn btn-secondary as-use', 'Use this name');
        b.type = 'button';
        b.addEventListener('click', function () { useName(a.input, p.full_name); });
        row.appendChild(b); card.appendChild(row);
      });
      if (a.duplicate_of) { card.appendChild(node('p', 'as-line', 'Same author as "' + a.duplicate_of + '"; counted once.')); }
      box.appendChild(card);
    });
  }
  function submitNow(flag) { claimed.value = flag; okFor = field.value; form.submit(); }
  function confirmBar() {
    var bar = node('div', 'as-actions');
    var go = node('button', 'btn btn-primary', 'Save as pending review'); go.type = 'button';
    go.addEventListener('click', function () { submitNow('0'); });
    var back = node('button', 'btn btn-secondary', 'Keep editing'); back.type = 'button';
    back.addEventListener('click', function () { box.textContent = ''; });
    bar.appendChild(go); bar.appendChild(back); box.appendChild(bar);
  }

  form.addEventListener('submit', function (e) {
    if (busy) { e.preventDefault(); return; }
    if (okFor === field.value) { return; }
    e.preventDefault();
    busy = true;
    var body = new URLSearchParams();
    body.set('csrf_token', token); body.set('author', field.value);
    fetch('/admin/author_scan.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.d.ok) { throw new Error(res.d && res.d.message ? res.d.message : 'Scan failed.'); }
        render(res.d);
        if (res.d.can_auto_approve) { submitNow('1'); } else { confirmBar(); }
      })
      .catch(function (err) {
        box.textContent = '';
        box.appendChild(node('p', 'as-error', err.message + ' Click Save again to continue; the server will check the authors and keep the book pending if needed.'));
        okFor = field.value;
      })
      .then(function () { busy = false; });
  });
  field.addEventListener('input', function () { okFor = null; claimed.value = '0'; });
  if (window.__initialScan) { render(window.__initialScan); }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>