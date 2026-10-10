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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = requestString($_POST, 'action');
    $genreId = filter_input(INPUT_POST, 'genre_id', FILTER_VALIDATE_INT) ?: 0;

    if ($action === 'save') {
        $genreName = trim(requestString($_POST, 'genre_name'));
        if ($genreName === '' || mb_strlen($genreName) > 100) {
            $errors[] = 'Genre name is required and must be 100 characters or fewer.';
        }
        if ($genreId < 0) {
            $errors[] = 'Select a valid genre.';
        }
        if (!$errors) {
            try {
                if ($genreId > 0) {
                    $stmt = $pdo->prepare('UPDATE genres SET genre_name = ? WHERE genre_id = ?');
                    $stmt->execute([$genreName, $genreId]);
                    if ($stmt->rowCount() === 0) {
                        $existsStmt = $pdo->prepare('SELECT 1 FROM genres WHERE genre_id = ?');
                        $existsStmt->execute([$genreId]);
                        if (!$existsStmt->fetchColumn()) {
                            throw new RuntimeException('Genre not found.');
                        }
                    }
                    $_SESSION['admin_flash'] = 'Genre updated.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO genres (genre_name) VALUES (?)');
                    $stmt->execute([$genreName]);
                    $_SESSION['admin_flash'] = 'Genre added.';
                }
                header('Location: /admin/genres.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'A genre with that name already exists.';
                } else {
                    error_log('Genre save failed: ' . $e->getMessage());
                    $errors[] = 'Unable to save the genre.';
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'delete' && $genreId > 0) {
        try {
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare('SELECT genre_id FROM genres WHERE genre_id = ? FOR UPDATE');
            $lockStmt->execute([$genreId]);
            if (!$lockStmt->fetchColumn()) {
                throw new RuntimeException('Genre not found.');
            }
            $bookCountStmt = $pdo->prepare('SELECT COUNT(*) FROM books WHERE genre_id = ?');
            $bookCountStmt->execute([$genreId]);
            if ((int) $bookCountStmt->fetchColumn() > 0) {
                $pdo->prepare("UPDATE genres SET status = 'inactive' WHERE genre_id = ?")->execute([$genreId]);
                $_SESSION['admin_flash'] = 'This genre is assigned to books, so it was deactivated instead of deleted.';
            } else {
                $pdo->prepare('DELETE FROM genres WHERE genre_id = ?')->execute([$genreId]);
                $_SESSION['admin_flash'] = 'Genre deleted.';
            }
            $pdo->commit();
            header('Location: /admin/genres.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Genre deletion failed: ' . $e->getMessage());
            $errors[] = 'Unable to delete this genre.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'toggle' && $genreId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE genres SET status = IF(status = 'active', 'inactive', 'active') WHERE genre_id = ?"
        );
        $stmt->execute([$genreId]);
        if ($stmt->rowCount() !== 1) {
            $errors[] = 'Genre not found.';
        } else {
            $_SESSION['admin_flash'] = 'Genre status updated.';
            header('Location: /admin/genres.php');
            exit;
        }
    } else {
        $errors[] = 'Choose a valid genre action.';
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editGenre = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT genre_id, genre_name FROM genres WHERE genre_id = ?');
    $editStmt->execute([$editId]);
    $editGenre = $editStmt->fetch();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && requestString($_POST, 'action') === 'save') {
    $editGenre = [
        'genre_id' => filter_input(INPUT_POST, 'genre_id', FILTER_VALIDATE_INT) ?: 0,
        'genre_name' => trim(requestString($_POST, 'genre_name'))
    ];
}
$genres = $pdo->query(
    'SELECT g.genre_id, g.genre_name, g.status, COUNT(DISTINCT b.book_id) AS book_count
     FROM genres g LEFT JOIN books b ON b.genre_id = g.genre_id
     GROUP BY g.genre_id, g.genre_name, g.status ORDER BY g.genre_name'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<section class="container">
    <div class="card">
        <h1 class="card-title">Category Management</h1>
        <?php if ($errors): ?><div class="form-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="form-success" role="status"><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
        <h2><?= $editGenre ? 'Edit genre' : 'Add genre' ?></h2>
        <form method="post" action="/admin/genres.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="genre_id" value="<?= (int) ($editGenre['genre_id'] ?? 0) ?>">
            <div class="form-group"><label for="genre_name">Genre name</label><input class="form-input" type="text" id="genre_name" name="genre_name" maxlength="100" required value="<?= htmlspecialchars($editGenre['genre_name'] ?? ($_POST['genre_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            <button class="btn btn-primary" type="submit">Save genre</button>
            <?php if ($editGenre): ?><a class="btn btn-secondary" href="/admin/genres.php">Cancel</a><?php endif; ?>
        </form>
    </div>
    <h2 class="section-title">Genres</h2>
    <?php foreach ($genres as $genre): ?>
        <article class="card admin-list-item">
            <h3><?= htmlspecialchars($genre['genre_name'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p>Status: <?= htmlspecialchars($genre['status'], ENT_QUOTES, 'UTF-8') ?> · Books: <?= (int) $genre['book_count'] ?></p>
            <a class="btn btn-secondary" href="/admin/genres.php?edit=<?= (int) $genre['genre_id'] ?>">Edit</a>
            <form class="inline-form" method="post" action="/admin/genres.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="genre_id" value="<?= (int) $genre['genre_id'] ?>">
                <button class="btn btn-secondary" type="submit" name="action" value="toggle"><?= $genre['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form class="inline-form" method="post" action="/admin/genres.php" onsubmit="return confirm('Delete this genre? Genres used by books will be deactivated instead.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="genre_id" value="<?= (int) $genre['genre_id'] ?>">
                <button class="btn btn-secondary" type="submit" name="action" value="delete">Delete</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
