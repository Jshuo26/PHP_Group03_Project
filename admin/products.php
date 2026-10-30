<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/author_verification.php';
require_once __DIR__ . '/../includes/upload_security.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';
require_once __DIR__ . '/../includes/catalog.php';

requireRole(['admin', 'staff']);
$errors = [];
$serverScan = null;
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$formats = ['paperback', 'hardbound', 'ebook'];
$statuses = ['active', 'inactive'];
$genres = $pdo->query("SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name")->fetchAll();
$fallbackGenreId = 0;
foreach ($genres as $genre) {
    if (strcasecmp((string) $genre['genre_name'], 'Other / General') === 0) {
        $fallbackGenreId = (int) $genre['genre_id'];
        break;
    }
}
$form = [
    'book_id' => 0,
    'edition_id' => 0,
    'title' => '',
    'description' => 'A full synopsis will be added soon.',
    'isbn' => '',
    'language' => 'Other Languages',
    'publisher' => '',
    'status' => 'active',
    'genre_ids' => $fallbackGenreId > 0 ? [$fallbackGenreId] : [],
    'author' => '',
    'format' => 'paperback',
    'price' => '625.00',
        'stock_quantity' => '10',
    'cover_url' => '',
    'cover_source_url' => '',
    'cover_permission_confirmed' => false,
    'metadata_source_url' => ''
];

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT b.book_id, e.edition_id, b.title, b.description, b.isbn,
                b.language, b.publisher, b.status,
                e.format, e.price, e.stock_quantity, e.cover_image_path, e.cover_source_url, e.cover_license_note,
                b.metadata_source_url,
                GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR "; ") AS author  -- CHANGED: "; "
         FROM book_editions e
         JOIN books b ON b.book_id = e.book_id
         LEFT JOIN book_authors ba ON ba.book_id = b.book_id
         LEFT JOIN authors a ON a.author_id = ba.author_id
         WHERE e.edition_id = ?
         GROUP BY b.book_id, e.edition_id, b.title, b.description, b.isbn,
                  b.language, b.publisher, b.status,
                  e.format, e.price, e.stock_quantity, e.cover_image_path, e.cover_source_url,
                  e.cover_license_note, b.metadata_source_url'
    );
    $editStmt->execute([$editId]);
    $loaded = $editStmt->fetch();
    if ($loaded) {
        $form = array_merge($form, $loaded);
        $form['cover_url'] = (string) ($loaded['cover_image_path'] ?? '');
        $form['cover_source_url'] = (string) ($loaded['cover_source_url'] ?? '');
        $form['cover_permission_confirmed'] = true;
        $form['metadata_source_url'] = (string) ($loaded['metadata_source_url'] ?? '');
        $genreStmt = $pdo->prepare('SELECT genre_id FROM book_genres WHERE book_id = ?');
        $genreStmt->execute([(int) $loaded['book_id']]);
        $form['genre_ids'] = array_map('intval', $genreStmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $errors[] = 'The selected book edition could not be found.';
    }
}
if ($editId < 1) {
    $form['title'] = trim(requestString($_GET, 'title'));
    $form['author'] = trim(requestString($_GET, 'author'));
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
                    auditLog($pdo, 'delete', 'Staff deleted a book product.', 'products', (int) $_SESSION['user_id'], 'book', $bookId, 'success', (string) $_SESSION['role']);
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
            'stock_quantity' => trim(requestString($_POST, 'stock_quantity')),
            'cover_url' => trim(requestString($_POST, 'cover_url')),
            'cover_source_url' => trim(requestString($_POST, 'cover_source_url')),
            'cover_permission_confirmed' => requestString($_POST, 'cover_permission_confirmed') === '1',
            'metadata_source_url' => trim(requestString($_POST, 'metadata_source_url'))
        ];
        $isEdit = $action === 'edit';

        if ($form['title'] === '' || mb_strlen($form['title']) > 255) {
            $errors[] = 'Book title is required and must not exceed 255 characters.';
        }
        if ($form['description'] === '') {
            $form['description'] = 'A full synopsis will be added soon.';
        } elseif (strlen($form['description']) > 65535) {
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
        if (strlen($form['metadata_source_url']) > 1000 || strlen($form['cover_source_url']) > 1000 || strlen($form['cover_url']) > 1000) {
            $errors[] = 'A metadata or cover URL is too long.';
        }
        if ($form['cover_url'] !== '' && catalogCover($form['cover_url']) === '') {
            $errors[] = 'Choose an HTTPS cover image from Google Books or Open Library, or upload an authorized cover file.';
        }
        if ($form['cover_url'] !== '' && !$form['cover_permission_confirmed']) {
            $errors[] = 'Confirm that the selected cover is appropriate and permitted for display.';
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
        $coverImage = null;
        $targetCover = null;
        if (isset($_FILES['cover']) && (!is_array($_FILES['cover']) || !array_key_exists('error', $_FILES['cover']))) {
            $errors[] = 'The cover image upload is invalid.';
        } elseif (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $coverImage = validatePublicImageUpload($_FILES['cover'], 'book cover', 5 * 1024 * 1024);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors && $coverImage !== null) {
            $coverDirectory = __DIR__ . '/../images/book-covers';
            if (!is_dir($coverDirectory)) {
                $errors[] = 'The book cover directory does not exist.';
            } else {
                try {
                    $savedCover = savePublicImageUpload($coverImage, $coverDirectory);
                    $uploadName = $savedCover['filename'];
                    $targetCover = $savedCover['path'];
                } catch (Throwable $e) {
                    error_log('Book cover upload failed: ' . $e->getMessage());
                    $errors[] = 'Unable to save the cover image.';
                }
            }
        }

        if (!$errors) {
                try {
                    $descriptionValue = $form['description'] !== '' ? $form['description'] : null;
                    $isbnValue = $form['isbn'] !== '' ? strtoupper(preg_replace('/[\s-]+/', '', $form['isbn'])) : null;
                    $publisherValue = $form['publisher'] !== '' ? $form['publisher'] : null;
                    $pdo->beginTransaction();

                    if (!$isEdit) {
                        $titleCandidates = $pdo->prepare('SELECT b.book_id, b.title FROM books b WHERE LOWER(b.title) = LOWER(?) LIMIT 20 FOR UPDATE');
                        $titleCandidates->execute([$form['title']]);
                        foreach ($titleCandidates->fetchAll() as $candidate) {
                            $existingAuthorsStmt = $pdo->prepare('SELECT a.full_name FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id WHERE ba.book_id = ? UNION SELECT al.alias AS full_name FROM author_aliases al JOIN authors a ON a.author_id = al.author_id WHERE al.author_id IN (SELECT author_id FROM book_authors WHERE book_id = ?)');
                            $existingAuthorsStmt->execute([(int) $candidate['book_id'], (int) $candidate['book_id']]);
                            $existingAuthorNames = $existingAuthorsStmt->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($authorNames as $submittedAuthor) {
                                foreach ($existingAuthorNames as $existingAuthor) {
                                    $left = normalizeAuthorName($submittedAuthor);
                                    $right = normalizeAuthorName((string) $existingAuthor);
                                    if ($left === $right || authorNamesPossiblyMatch($left, $right) !== null) {
                                        throw new RuntimeException('“' . (string) $candidate['title'] . '” by a matching author is already in the catalog. Use the existing record or confirm that this is a distinct book before adding it.');
                                    }
                                }
                            }
                        }
                    }
                    if (!$isEdit && $isbnValue !== null) {
                        $isbnDuplicate = $pdo->prepare('SELECT b.title FROM books b LEFT JOIN book_editions e ON e.book_id = b.book_id WHERE b.isbn = ? OR e.isbn = ? LIMIT 1 FOR UPDATE');
                        $isbnDuplicate->execute([$isbnValue, $isbnValue]);
                        $duplicateTitle = $isbnDuplicate->fetchColumn();
                        if ($duplicateTitle !== false) {
                            throw new RuntimeException('This ISBN is already in the catalog for “' . (string) $duplicateTitle . '”. Open that catalog record and edit or restock it instead.');
                        }
                    }

                    if ($isEdit) {
                        $existsStmt = $pdo->prepare('SELECT 1 FROM book_editions WHERE edition_id = ? AND book_id = ? FOR UPDATE');
                        $existsStmt->execute([(int) $form['edition_id'], (int) $form['book_id']]);
                        if (!$existsStmt->fetchColumn()) {
                            throw new RuntimeException('Book edition not found.');
                        }

                        $bookStmt = $pdo->prepare(
                            'UPDATE books
                                SET title = ?, description = ?, isbn = ?,
                                    language = ?, publisher = ?, status = ?, metadata_source_url = ?
                              WHERE book_id = ?'
                        );
                        $bookStmt->execute([
                            $form['title'],
                            $descriptionValue,
                            $isbnValue,
                            $form['language'],
                            $publisherValue,
                            $form['status'],
                            $form['metadata_source_url'] !== '' ? $form['metadata_source_url'] : null,
                            (int) $form['book_id']
                        ]);
                        $editionSql = 'UPDATE book_editions SET format = ?, isbn = ?, price = ?, stock_quantity = ?';
                        $editionParams = [$form['format'], $isbnValue, $form['price'], (int) $form['stock_quantity']];
                        if ($uploadName !== null) {
                            $editionSql .= ', cover_image_path = ?, cover_source_url = NULL, cover_license_note = ?';
                            $editionParams[] = $uploadName;
                            $editionParams[] = 'Authorized cover file supplied by staff';
                        } elseif ($form['cover_url'] !== '') {
                            $editionSql .= ', cover_image_path = ?, cover_source_url = ?, cover_license_note = ?';
                            $editionParams[] = $form['cover_url'];
                            $editionParams[] = $form['cover_source_url'] !== '' ? $form['cover_source_url'] : null;
                            $editionParams[] = 'Staff confirmed cover display permission for this project';
                        }
                        $editionSql .= ' WHERE edition_id = ?';
                        $editionParams[] = (int) $form['edition_id'];
                        $pdo->prepare($editionSql)->execute($editionParams);
                        syncEditionLowStockAlert($pdo, (int) $form['edition_id']);
                        $bookId = (int) $form['book_id'];
        } else {
                        $bookStmt = $pdo->prepare(
                            'INSERT INTO books (title, description, isbn, language, publisher, status, metadata_source_url)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $bookStmt->execute([
                            $form['title'],
                            $descriptionValue,
                            $isbnValue,
                            $form['language'],
                            $publisherValue,
                            $form['status'],
                            $form['metadata_source_url'] !== '' ? $form['metadata_source_url'] : null
                        ]);
                        $bookId = (int) $pdo->lastInsertId();
                        $editionStmt = $pdo->prepare(
                            'INSERT INTO book_editions (book_id, format, isbn, price, stock_quantity, low_stock_threshold, cover_image_path, cover_source_url, cover_license_note)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $editionStmt->execute([
                            $bookId,
                            $form['format'],
                            $isbnValue,
                            $form['price'],
                            (int) $form['stock_quantity'],
                            getLowStockThreshold($pdo),
                            $uploadName ?? ($form['cover_url'] !== '' ? $form['cover_url'] : null),
                            $uploadName !== null ? null : ($form['cover_source_url'] !== '' ? $form['cover_source_url'] : null),
                            $uploadName !== null ? 'Authorized cover file supplied by staff' : ($form['cover_url'] !== '' ? 'Staff confirmed cover display permission for this project' : null)
                        ]);
                        syncEditionLowStockAlert($pdo, (int) $pdo->lastInsertId());
                    }

                    $pdo->prepare('DELETE FROM book_genres WHERE book_id = ?')->execute([$bookId]);
                    $genreInsert = $pdo->prepare('INSERT INTO book_genres (book_id, genre_id) VALUES (?, ?)');
                    foreach ($genreIds as $genreId) {
                        $genreInsert->execute([$bookId, $genreId]);
                    }
                    $reviewOutcome = linkBookAuthors($pdo, $bookId, $authorNames, (int) $_SESSION['user_id']);
                    auditLogRequired($pdo, $isEdit ? 'update' : 'create', $isEdit ? 'Staff updated a book product and edition.' : 'Staff created a book product and edition.', 'products', (int) $_SESSION['user_id'], 'book', $bookId, 'success', (string) $_SESSION['role']);
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
    } else {
        $errors[] = 'Choose a valid product action.';
    }
}

$genres = $pdo->query("SELECT genre_id, genre_name FROM genres WHERE status = 'active' ORDER BY genre_name")->fetchAll();
$products = $pdo->query(
    'SELECT b.book_id, e.edition_id, b.title, b.description, b.isbn,
            b.language, b.publisher, b.status, b.review_status,
            GROUP_CONCAT(DISTINCT g.genre_name ORDER BY g.genre_name SEPARATOR ", ") AS genre_names,
            e.format, e.price, e.stock_quantity, e.low_stock_threshold, e.cover_image_path,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS authors
     FROM books b
     JOIN book_editions e ON e.book_id = b.book_id
     LEFT JOIN book_genres bg ON bg.book_id = b.book_id
     LEFT JOIN genres g ON g.genre_id = bg.genre_id
     LEFT JOIN book_authors ba ON ba.book_id = b.book_id
     LEFT JOIN authors a ON a.author_id = ba.author_id
     GROUP BY b.book_id, e.edition_id, b.title, b.description, b.isbn,
              b.language, b.publisher, b.status, b.review_status,
              e.format, e.price, e.stock_quantity, e.low_stock_threshold, e.cover_image_path
     ORDER BY b.created_at DESC, b.title, e.format'
)->fetchAll();
$atRiskProducts = array_values(array_filter($products, static function (array $product): bool {
    return $product['status'] === 'active' && $product['review_status'] === 'approved'
        && (int) $product['stock_quantity'] <= (int) $product['low_stock_threshold'] + 2;
}));
usort($atRiskProducts, static fn(array $left, array $right): int => (int) $left['stock_quantity'] <=> (int) $right['stock_quantity']);
$attentionStmt = $pdo->query("SELECT COUNT(DISTINCT b.book_id)
    FROM books b
    LEFT JOIN book_authors ba ON ba.book_id = b.book_id
    LEFT JOIN authors a ON a.author_id = ba.author_id
    WHERE b.review_status <> 'approved'
       OR b.description IS NULL OR TRIM(b.description) = '' OR b.description = 'A full synopsis will be added soon.'
       OR b.language = 'Other Languages'
       OR a.verification_status <> 'verified'
       OR EXISTS (SELECT 1 FROM book_editions e WHERE e.book_id = b.book_id AND (e.cover_image_path IS NULL OR e.cover_image_path = ''))
       OR EXISTS (SELECT 1 FROM book_genres bg JOIN genres g ON g.genre_id = bg.genre_id WHERE bg.book_id = b.book_id AND g.genre_name = 'Other / General')");
$attentionCount = (int) $attentionStmt->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>
<main class="admin-products container">
    <section class="products-hero">
        <div><p class="products-eyebrow">CATALOG OPERATIONS</p><h1>Product Management</h1><p>Add a single title, review the catalog, and return to records that need attention.</p></div>
        <div class="products-actions"><a class="btn btn-primary" href="/admin/bulk_import.php">Bulk Catalog <span aria-hidden="true">→</span></a><a class="btn btn-secondary" href="/admin/catalog_review.php">Needs Review <span class="review-count"><?= (int) $attentionCount ?></span></a></div>
    </section>
    <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>

    <div class="product-workspace">
    <section class="product-editor card" aria-labelledby="editor-title">
        <div class="section-heading"><div><p class="products-eyebrow"><?= (int) $form['edition_id'] > 0 ? 'CATALOG RECORD' : 'SINGLE BOOK ENTRY' ?></p><h2 id="editor-title"><?= (int) $form['edition_id'] > 0 ? 'Edit book edition' : 'Add a book' ?></h2></div><?php if ((int) $form['edition_id'] > 0): ?><a class="btn btn-secondary" href="/admin/products.php">Cancel edit</a><?php endif; ?></div>
        <?php if (!$genres): ?><p>Add an active genre before adding books.</p><a class="btn btn-primary" href="/admin/genres.php">Manage categories</a>
        <?php else: ?>
        <form id="book-form" method="post" action="/admin/products.php<?= (int) $form['edition_id'] > 0 ? '?edit=' . (int) $form['edition_id'] : '' ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= (int) $form['edition_id'] > 0 ? 'edit' : 'add' ?>"><input type="hidden" name="book_id" value="<?= (int) $form['book_id'] ?>"><input type="hidden" name="edition_id" value="<?= (int) $form['edition_id'] ?>"><input type="hidden" name="scan_claimed" id="scan_claimed" value="0">
            <input type="hidden" id="cover_url" name="cover_url" value="<?= htmlspecialchars((string) ($form['cover_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" id="cover_source_url" name="cover_source_url" value="<?= htmlspecialchars((string) ($form['cover_source_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" id="metadata_source_url" name="metadata_source_url" value="<?= htmlspecialchars((string) ($form['metadata_source_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="product-form-grid">
                <div class="form-group product-field-title"><label for="title">Book Title <span>*</span></label><input class="form-input" type="text" id="title" name="title" maxlength="255" required value="<?= htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group product-field-author"><label for="author">Author(s) <span>*</span></label><input class="form-input" type="text" id="author" name="author" maxlength="600" required value="<?= htmlspecialchars((string) $form['author'], ENT_QUOTES, 'UTF-8') ?>"><small>Separate multiple authors with semicolons.</small></div>
                <?php if ((int) $form['edition_id'] === 0): ?><div class="lookup-action product-form-wide"><button type="button" id="search-book" class="btn btn-primary">Check Author &amp; Search Metadata <span aria-hidden="true">⌕</span></button><small>Checks Filipino-author identity, the current catalog, Google Books, then Open Library.</small><div id="lookup-status" class="lookup-status" role="status" aria-live="polite"></div><div id="lookup-results" class="lookup-results" aria-live="polite"></div></div><?php endif; ?>
                <div class="form-group product-field-description"><label for="description">Book Synopsis / Description <span>*</span></label><textarea class="form-input" id="description" name="description" rows="5" maxlength="65535" required><?= htmlspecialchars((string) ($form['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea><small>If unavailable, the system inserts “A full synopsis will be added soon.” and flags it for follow-up.</small></div>
                <div class="form-group product-field-author-bio"><label for="author_description">Primary Author Description</label><textarea class="form-input" id="author_description" rows="5" readonly placeholder="Author bio appears here when available"></textarea><small>Managed in Authors; shown for reference.</small></div>
                <div class="form-group product-field-isbn"><label for="isbn">ISBN</label><input class="form-input" type="text" id="isbn" name="isbn" maxlength="20" value="<?= htmlspecialchars((string) ($form['isbn'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group product-field-language"><label for="language">Language <span>*</span></label><select class="form-input" id="language" name="language" required><option value="Other Languages" <?= ($form['language'] ?? '') === 'Other Languages' ? 'selected' : '' ?>>Other Languages</option><option value="English" <?= ($form['language'] ?? '') === 'English' ? 'selected' : '' ?>>English</option><option value="Filipino" <?= ($form['language'] ?? '') === 'Filipino' ? 'selected' : '' ?>>Filipino</option></select><small>Auto-filled when identified.</small></div>
                <div class="form-group product-field-format"><label for="format">Edition / Format <span>*</span></label><select class="form-input" id="format" name="format" required><?php foreach ($formats as $format): ?><option value="<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?>" <?= $form['format'] === $format ? 'selected' : '' ?>><?= htmlspecialchars($format === 'paperback' ? 'Paperback / softbound' : ucfirst($format), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group product-field-genres"><label for="genre_ids">Genres <span>*</span></label><select class="form-input" id="genre_ids" name="genre_ids[]" multiple required size="5"><?php foreach ($genres as $genre): ?><option value="<?= (int) $genre['genre_id'] ?>" <?= in_array((int) $genre['genre_id'], array_map('intval', $form['genre_ids'] ?? []), true) ? 'selected' : '' ?>><?= htmlspecialchars($genre['genre_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><small>Hold Ctrl / Command to select multiple.</small></div>
                <div class="form-group product-field-publisher"><label for="publisher">Publisher</label><input class="form-input" type="text" id="publisher" name="publisher" maxlength="150" value="<?= htmlspecialchars((string) ($form['publisher'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group product-field-status"><label for="status">Catalog Status</label><select class="form-input" id="status" name="status" required><?php foreach ($statuses as $statusOption): ?><option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= ($form['status'] ?? 'active') === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($statusOption), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group product-field-price"><label for="price">Website Selling Price (₱) <span>*</span></label><input class="form-input" type="number" id="price" name="price" min="0" max="99999999.99" step="0.01" required value="<?= htmlspecialchars((string) $form['price'], ENT_QUOTES, 'UTF-8') ?>"><small>Provider PHP price + markup, or ₱500 fallback + 25%.</small></div>
                <div class="form-group product-field-quantity"><label for="stock_quantity">Quantity <span>*</span></label><input class="form-input" type="number" id="stock_quantity" name="stock_quantity" min="0" max="9999999" step="1" required value="<?= htmlspecialchars((string) $form['stock_quantity'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group product-field-cover"><label for="cover_url">Book Cover Image</label><input class="form-input" type="text" id="cover_url_display" readonly value="<?= htmlspecialchars((string) ($form['cover_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Found after metadata search"><small>Online covers are added during lookup.</small></div>
                <div class="form-group product-field-upload"><label for="cover">Or Upload an Authorized Cover File</label><input class="form-input" type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG, or WebP; up to 5 MB.</small></div>
                <div class="product-form-wide cover-preview-wrap" id="cover-preview-wrap" <?= empty($form['cover_url']) ? 'hidden' : '' ?>><div id="cover-preview" class="cover-preview"><?php if (!empty($form['cover_url'])): ?><img src="<?= htmlspecialchars(catalogCover((string) $form['cover_url']), ENT_QUOTES, 'UTF-8') ?>" alt="Selected book cover"><span>Cover image selected. Confirm that it is suitable and permitted for this project’s display.</span><?php endif; ?></div><label class="cover-confirm"><input type="checkbox" name="cover_permission_confirmed" id="cover_permission_confirmed" value="1" <?= !empty($form['cover_permission_confirmed']) ? 'checked' : '' ?>> I confirmed this cover is appropriate and permitted for this project’s display.</label></div>
            </div>
            <div id="author-scan-results" class="as-results" aria-live="polite"></div>
            <div class="product-form-footer"><button class="btn btn-primary" type="submit"><?= (int) $form['edition_id'] > 0 ? 'Save Book Changes' : 'Add Book to Catalog' ?></button></div>
        </form>
        <?php endif; ?>
    </section>

    <aside class="restock-panel" aria-labelledby="restock-title">
        <div class="restock-heading"><p class="products-eyebrow">DEMO RESTOCK WORKFLOW</p><h2 id="restock-title">Auto-Restock</h2><p>Prepare a CSV order list. This demo does not change stock.</p></div>
        <form method="post" action="/admin/auto_restock.php" class="restock-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><label for="restock-quantity">Order quantity per book</label><input class="form-input" type="number" id="restock-quantity" name="quantity" min="1" max="5000" value="10" required><button class="btn btn-primary" type="submit">Generate Restock CSV <span aria-hidden="true">↓</span></button></form>
        <div class="at-risk-heading"><h3>At-risk editions</h3><span><?= count($atRiskProducts) ?></span></div>
        <p class="at-risk-note">Out of stock or within two copies above the threshold.</p>
        <?php if (!$atRiskProducts): ?><p class="at-risk-empty">No active editions are near their restock threshold.</p><?php else: ?><ul class="at-risk-list"><?php foreach (array_slice($atRiskProducts, 0, 12) as $risk): ?><li><a href="/admin/products.php?edit=<?= (int) $risk['edition_id'] ?>"><strong><?= htmlspecialchars((string) $risk['title'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(ucfirst((string) $risk['format']), ENT_QUOTES, 'UTF-8') ?> · <?= (int) $risk['stock_quantity'] ?> left</span></a><em><?= (int) $risk['low_stock_threshold'] ?> threshold</em></li><?php endforeach; ?></ul><?php if (count($atRiskProducts) > 12): ?><p class="at-risk-more">Showing 12 of <?= count($atRiskProducts) ?> at-risk editions.</p><?php endif; ?><?php endif; ?>
    </aside>
    </div>

    <section class="catalog-section" aria-labelledby="catalog-title"><div class="section-heading"><div><p class="products-eyebrow">CURRENT INVENTORY</p><h2 id="catalog-title">Catalog Books &amp; Editions</h2></div><span class="catalog-total"><?= count($products) ?> editions</span></div>
        <div class="catalog-toolbar"><div class="catalog-filters"><input class="form-input" id="catalog-filter" type="search" placeholder="Search title or author" aria-label="Search catalog"><select class="form-input" id="catalog-format-filter" aria-label="Filter by format"><option value="all">All formats</option><option value="paperback">Paperback</option><option value="hardbound">Hardbound</option><option value="ebook">Ebook</option></select><select class="form-input" id="catalog-review-filter" aria-label="Filter by review status"><option value="all">All review states</option><option value="approved">Approved</option><option value="pending_review">Needs author review</option><option value="rejected">Rejected</option></select><select class="form-input" id="catalog-stock-filter" aria-label="Filter by stock status"><option value="all">All stock levels</option><option value="risk">At risk</option><option value="out">Out of stock</option></select></div><a class="text-link" href="/admin/catalog_review.php">Review flagged &amp; incomplete books →</a></div>
        <?php if (!$products): ?><div class="card"><p class="card-text">No books have been added yet.</p></div><?php else: ?><div class="catalog-grid" id="catalog-grid">
        <?php foreach ($products as $product): $risk = $product['status'] === 'active' && $product['review_status'] === 'approved' && (int) $product['stock_quantity'] <= (int) $product['low_stock_threshold'] + 2; ?><article class="catalog-card" data-search="<?= htmlspecialchars(mb_strtolower($product['title'] . ' ' . ($product['authors'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" data-format="<?= htmlspecialchars((string) $product['format'], ENT_QUOTES, 'UTF-8') ?>" data-review="<?= htmlspecialchars((string) $product['review_status'], ENT_QUOTES, 'UTF-8') ?>" data-risk="<?= $risk ? '1' : '0' ?>" data-out="<?= (int) $product['stock_quantity'] === 0 ? '1' : '0' ?>"><a class="catalog-card-cover" href="/admin/products.php?edit=<?= (int) $product['edition_id'] ?>" aria-label="Edit <?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?>"><?php if ($product['cover_image_path'] && catalogCover((string) $product['cover_image_path']) !== ''): ?><img src="<?= htmlspecialchars(catalogCover($product['cover_image_path']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?> cover" loading="lazy"><?php else: ?><?= catalogCoverPlaceholder((string) $product['title'], 'admin-catalog-placeholder') ?><?php endif; ?></a><div class="catalog-card-body"><span class="catalog-format"><?= htmlspecialchars(ucfirst($product['format']), ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?></h3><p class="catalog-authors"><?= htmlspecialchars($product['authors'] ?: 'Author not listed', ENT_QUOTES, 'UTF-8') ?></p><p class="catalog-price">₱<?= number_format((float) $product['price'], 2) ?><span><?= (int) $product['stock_quantity'] ?> in stock</span></p><div class="catalog-status-row"><span><?= htmlspecialchars(ucfirst($product['status']), ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars(bookReviewLabel($product['review_status']), ENT_QUOTES, 'UTF-8') ?></span><?php if ($risk): ?><span class="is-at-risk">At risk</span><?php endif; ?></div><div class="catalog-card-actions"><a class="btn btn-secondary" href="/admin/products.php?edit=<?= (int) $product['edition_id'] ?>">Edit</a><?php if ($product['format'] === 'paperback' || count(array_filter($products, static fn($row) => (int) $row['book_id'] === (int) $product['book_id'])) === 1): ?><form method="post" action="/admin/products.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="book_id" value="<?= (int) $product['book_id'] ?>"><button class="btn btn-danger" type="submit" name="action" value="delete" onclick="return confirm('Delete this book and all its editions?')">Delete</button></form><?php endif; ?></div></div></article><?php endforeach; ?></div><p class="catalog-empty" id="catalog-empty" hidden>No catalog records match your search.</p><?php endif; ?>
    </section>
</main>

<link rel="stylesheet" href="/css/admin-author-review.css">
<script src="/js/admin-products.js" defer></script>
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
