<?php

require_once '../includes/session.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';

requireRole(['admin', 'staff']);

$errors = [];
$success = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_product'])
) {

    requireCsrfToken();

    $book_id = (int) ($_POST['book_id'] ?? 0);

    if ($book_id > 0) {

        $stmt = $pdo->prepare(
            'DELETE FROM books WHERE book_id = ?'
        );

        $stmt->execute([$book_id]);

        $success = 'Product deleted successfully.';
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_product'])
) {

    requireCsrfToken();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $genre_id = (int) ($_POST['genre_id'] ?? 0);

    $format = $_POST['format'] ?? '';
    $price = $_POST['price'] ?? '';
    $stock_quantity = (int) ($_POST['stock_quantity'] ?? 0);

    if ($title === '') {
        $errors[] = 'Title is required.';
    }

    if ($genre_id <= 0) {
        $errors[] = 'Please select a genre.';
    }

    if (
        !in_array(
            $format,
            ['paperback', 'hardbound', 'ebook'],
            true
        )
    ) {
        $errors[] = 'Please select a valid format.';
    }

    if (!is_numeric($price) || $price < 0) {
        $errors[] = 'Please enter a valid price.';
    }

    if ($stock_quantity < 0) {
        $errors[] = 'Stock cannot be negative.';
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO books
                (title, description, isbn, genre_id)
                VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $title,
                $description !== '' ? $description : null,
                $isbn !== '' ? $isbn : null,
                $genre_id
            ]);

            $book_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO book_editions
                (book_id, format, price, stock_quantity)
                VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $book_id,
                $format,
                $price,
                $stock_quantity
            ]);

            $pdo->commit();

            $success = 'Product added successfully.';

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Unable to add product. Please check the information.';
        }
    }
}

$genreStmt = $pdo->query(
    'SELECT genre_id, genre_name
     FROM genres
     WHERE status = "active"
     ORDER BY genre_name'
);

$genres = $genreStmt->fetchAll();


$productStmt = $pdo->query(
    'SELECT
        b.book_id,
        b.title,
        b.description,
        b.isbn,
        g.genre_name,
        e.edition_id,
        e.format,
        e.price,
        e.stock_quantity
     FROM books b
     INNER JOIN genres g
        ON b.genre_id = g.genre_id
     INNER JOIN book_editions e
        ON b.book_id = e.book_id
     ORDER BY b.created_at DESC'
);

$products = $productStmt->fetchAll();

include '../includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Product Management
        </h1>

        <?php if (!empty($errors)): ?>

            <div class="form-errors">

                <?php foreach ($errors as $error): ?>

                    <p>
                        <?php echo htmlspecialchars($error); ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <?php if ($success !== ''): ?>

            <div class="form-success">

                <p>
                    <?php echo htmlspecialchars($success); ?>
                </p>

            </div>

        <?php endif; ?>


        <h2>
            Add Product
        </h2>


        <?php if (empty($genres)): ?>

            <p>
                No active genres are available.
                Add a genre before creating a product.
            </p>

        <?php else: ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
                >

                <div class="form-group">

                    <label for="title">
                        Book Title
                    </label>

                    <input
                        type="text"
                        id="title"
                        name="title"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="description">
                        Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        rows="4"
                    ></textarea>

                </div>


                <div class="form-group">

                    <label for="isbn">
                        ISBN
                    </label>

                    <input
                        type="text"
                        id="isbn"
                        name="isbn"
                    >

                </div>


                <div class="form-group">

                    <label for="genre_id">
                        Genre
                    </label>

                    <select
                        id="genre_id"
                        name="genre_id"
                        required
                    >

                        <option value="">
                            Select Genre
                        </option>

                        <?php foreach ($genres as $genre): ?>

                            <option
                                value="<?php echo $genre['genre_id']; ?>"
                            >
                                <?php echo htmlspecialchars($genre['genre_name']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label for="format">
                        Format
                    </label>

                    <select
                        id="format"
                        name="format"
                        required
                    >

                        <option value="">
                            Select Format
                        </option>

                        <option value="paperback">
                            Paperback
                        </option>

                        <option value="hardbound">
                            Hardbound
                        </option>

                        <option value="ebook">
                            Ebook
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label for="price">
                        Price
                    </label>

                    <input
                        type="number"
                        id="price"
                        name="price"
                        min="0"
                        step="0.01"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="stock_quantity">
                        Stock Quantity
                    </label>

                    <input
                        type="number"
                        id="stock_quantity"
                        name="stock_quantity"
                        min="0"
                        required
                    >

                </div>


                <button
                    type="submit"
                    name="add_product"
                    class="btn btn-primary"
                >
                    Add Product
                </button>

            </form>

        <?php endif; ?>

    </div>


    <section class="section">

        <h2 class="section-title">
            Products
        </h2>


        <?php if (empty($products)): ?>

            <div class="card">

                <p class="card-text">
                    No products have been added yet.
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($products as $product): ?>

                <article class="card">

                    <h3 class="card-title">
                        <?php echo htmlspecialchars($product['title']); ?>
                    </h3>

                    <p class="card-text">
                        Genre:
                        <?php echo htmlspecialchars($product['genre_name']); ?>
                    </p>

                    <p class="card-text">
                        Format:
                        <?php echo htmlspecialchars($product['format']); ?>
                    </p>

                    <p class="card-text">
                        Price:
                        ₱<?php echo number_format($product['price'], 2); ?>
                    </p>

                    <p class="card-text">
                        Stock:
                        <?php echo htmlspecialchars($product['stock_quantity']); ?>
                    </p>


                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars(generateCsrfToken()); ?>"
                        >

                        <input
                            type="hidden"
                            name="book_id"
                            value="<?php echo $product['book_id']; ?>"
                        >

                        <button
                            type="submit"
                            name="delete_product"
                            class="btn btn-secondary"
                            onclick="return confirm('Delete this product?');"
                        >
                            Delete
                        </button>

                    </form>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</section>

<?php include '../includes/footer.php'; ?>