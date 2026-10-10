<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/input.php';

requireRole(['admin', 'staff']);
$errors = [];
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$formats = ['paperback', 'hardbound', 'ebook'];
$form = [
    'book_id' => 0,
    'edition_id' => 0,
    'title' => '',
    'description' => '',
    'isbn' => '',
    'genre_id' => '',
    'author' => '',
    'format' => '',
    'price' => '',
    'stock_quantity' => ''
];

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT b.book_id, e.edition_id, b.title, b.description, b.isbn, b.genre_id,
                e.format, e.price, e.stock_quantity,
                GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS author
         FROM book_editions e
         JOIN books b ON b.book_id = e.book_id
         LEFT JOIN book_authors ba ON ba.book_id = b.book_id
         LEFT JOIN authors a ON a.author_id = ba.author_id
         WHERE e.edition_id = ?
         GROUP BY b.book_id, e.edition_id, b.title, b.description, b.isbn,
                  b.genre_id, e.format, e.price, e.stock_quantity'
    );
    $editStmt->execute([$editId]);
    $loaded = $editStmt->fetch();
    if ($loaded) {
        $form = array_merge($form, $loaded);
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
                    $errors[] = 'This book cannot be deleted because it appears in an order or spotlight.';
                } else {
                    error_log('Book deletion failed: ' . $e->getMessage());
                    $errors[] = 'Unable to delete this book.';
                }
            }
        }
    } elseif (in_array($action, ['save', 'add', 'edit'], true)) {
        $form = [
            'book_id' => filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT) ?: 0,
            'edition_id' => filter_input(INPUT_POST, 'edition_id', FILTER_VALIDATE_INT) ?: 0,
            'title' => trim(requestString($_POST, 'title')),
            'description' => trim(requestString($_POST, 'description')),
            'isbn' => trim(requestString($_POST, 'isbn')),
            'genre_id' => filter_input(INPUT_POST, 'genre_id', FILTER_VALIDATE_INT) ?: 0,
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
        if ((int) $form['genre_id'] < 1) {
            $errors[] = 'Select a genre.';
        }
        if ($form['author'] === '' || mb_strlen($form['author']) > 150) {
            $errors[] = 'Author name is required and must not exceed 150 characters.';
        }
        if (!in_array($form['format'], $formats, true)) {
            $errors[] = 'Select a valid book format.';
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $form['price'])) {
            $errors[] = 'Price must be a positive number with up to two decimal places.';
        }
        if (!preg_match('/^\d{1,7}$/', $form['stock_quantity'])) {
            $errors[] = 'Stock must be a whole number between 0 and 9,999,999.';
        }
        if ($isEdit && ((int) $form['book_id'] < 1 || (int) $form['edition_id'] < 1)) {
            $errors[] = 'Select a valid book edition to edit.';
        }

        $genreStmt = $pdo->prepare("SELECT 1 FROM genres WHERE genre_id = ? AND status = 'active'");
        $genreStmt->execute([(int) $form['genre_id']]);
        if (!$genreStmt->fetchColumn()) {
            $errors[] = 'The selected genre is not available.';
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
            if ($targetCover !== null && !move_uploaded_file($uploadTmpPath, $targetCover)) {
                $errors[] = 'Unable to save the cover image.';
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($isEdit) {
                        $existsStmt = $pdo->prepare('SELECT 1 FROM book_editions WHERE edition_id = ? AND book_id = ? FOR UPDATE');
                        $existsStmt->execute([(int) $form['edition_id'], (int) $form['book_id']]);
                        if (!$existsStmt->fetchColumn()) {
                            throw new RuntimeException('Book edition not found.');
                        }
                        $bookStmt = $pdo->prepare('UPDATE books SET title = ?, description = ?, isbn = ?, genre_id = ? WHERE book_id = ?');
                        $bookStmt->execute([
                            $form['title'],
                            $form['description'] !== '' ? $form['description'] : null,
                            $form['isbn'] !== '' ? $form['isbn'] : null,
                            (int) $form['genre_id'],
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
                        $bookStmt = $pdo->prepare('INSERT INTO books (title, description, isbn, genre_id) VALUES (?, ?, ?, ?)');
                        $bookStmt->execute([
                            $form['title'],
                            $form['description'] !== '' ? $form['description'] : null,
                            $form['isbn'] !== '' ? $form['isbn'] : null,
                            (int) $form['genre_id']
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

                    $authorStmt = $pdo->prepare('SELECT author_id FROM authors WHERE full_name = ? ORDER BY author_id LIMIT 1');
                    $authorStmt->execute([$form['author']]);
                    $authorId = (int) ($authorStmt->fetchColumn() ?: 0);
                    if ($authorId === 0) {
                        $authorStmt = $pdo->prepare('INSERT INTO authors (full_name) VALUES (?)');
                        $authorStmt->execute([$form['author']]);
                        $authorId = (int) $pdo->lastInsertId();
                    }
                    $pdo->prepare('DELETE FROM book_authors WHERE book_id = ?')->execute([$bookId]);
                    $pdo->prepare('INSERT INTO book_authors (book_id, author_id) VALUES (?, ?)')->execute([$bookId, $authorId]);
                    $pdo->commit();

                    $_SESSION['admin_flash'] = $isEdit ? 'Book updated.' : 'Book added.';
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
    'SELECT b.book_id, e.edition_id, b.title, b.description, b.isbn, g.genre_name,
            e.format, e.price, e.stock_quantity, e.cover_image_path,
            GROUP_CONCAT(DISTINCT a.full_name ORDER BY a.full_name SEPARATOR ", ") AS authors
     FROM books b
     JOIN genres g ON g.genre_id = b.genre_id
     JOIN book_editions e ON e.book_id = b.book_id
     LEFT JOIN book_authors ba ON ba.book_id = b.book_id
     LEFT JOIN authors a ON a.author_id = ba.author_id
     GROUP BY b.book_id, e.edition_id, b.title, b.description, b.isbn,
              g.genre_name, e.format, e.price, e.stock_quantity, e.cover_image_path
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
            <form method="post" action="/admin/products.php<?= (int) $form['edition_id'] > 0 ? '?edit=' . (int) $form['edition_id'] : '' ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="<?= (int) $form['edition_id'] > 0 ? 'edit' : 'add' ?>">
                <input type="hidden" name="book_id" value="<?= (int) $form['book_id'] ?>">
                <input type="hidden" name="edition_id" value="<?= (int) $form['edition_id'] ?>">
                <div class="form-group"><label for="title">Book title</label><input class="form-input" type="text" id="title" name="title" maxlength="255" required value="<?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="author">Author</label><input class="form-input" type="text" id="author" name="author" maxlength="150" required value="<?= htmlspecialchars($form['author'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="genre_id">Genre</label><select class="form-input" id="genre_id" name="genre_id" required><option value="">Select a genre</option><?php foreach ($genres as $genre): ?><option value="<?= (int) $genre['genre_id'] ?>" <?= (int) $form['genre_id'] === (int) $genre['genre_id'] ? 'selected' : '' ?>><?= htmlspecialchars($genre['genre_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="description">Description</label><textarea class="form-input" id="description" name="description" rows="4" maxlength="65535"><?= htmlspecialchars($form['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></div>
                <div class="form-group"><label for="isbn">ISBN (optional)</label><input class="form-input" type="text" id="isbn" name="isbn" maxlength="20" value="<?= htmlspecialchars($form['isbn'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="format">Format</label><select class="form-input" id="format" name="format" required><option value="">Select a format</option><?php foreach ($formats as $format): ?><option value="<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?>" <?= $form['format'] === $format ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($format), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="price">Price (₱)</label><input class="form-input" type="number" id="price" name="price" min="0" max="99999999.99" step="0.01" required value="<?= htmlspecialchars((string) $form['price'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="stock_quantity">Stock quantity</label><input class="form-input" type="number" id="stock_quantity" name="stock_quantity" min="0" max="9999999" step="1" required value="<?= htmlspecialchars((string) $form['stock_quantity'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label for="cover">Book cover (JPEG, PNG, WebP; max 5 MB)</label><input class="form-input" type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"></div>
                <button class="btn btn-primary" type="submit"><?= (int) $form['edition_id'] > 0 ? 'Save changes' : 'Add book' ?></button>
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
            <p>Author: <?= htmlspecialchars($product['authors'] ?: 'Not listed', ENT_QUOTES, 'UTF-8') ?> · Genre: <?= htmlspecialchars($product['genre_name'], ENT_QUOTES, 'UTF-8') ?></p>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
