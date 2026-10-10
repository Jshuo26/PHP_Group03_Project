<?php

require_once '../includes/session.php';
require_once '../includes/auth.php';

requireRole(['admin', 'staff']);

include '../includes/header.php';
?>

<section class="container">

    <div class="card">

        <h1 class="card-title">
            Admin Dashboard
        </h1>

        <p class="card-text">
            Welcome,
            <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
        </p>

        <p class="card-text">
            Your role:
            <?php echo htmlspecialchars($_SESSION['role']); ?>
        </p>

        <nav class="admin-shortcuts" aria-label="Administration">
            <a class="btn btn-primary" href="/admin/products.php">Manage books</a>
            <a class="btn btn-secondary" href="/admin/genres.php">Manage categories</a>
            <a class="btn btn-secondary" href="/admin/orders.php">Manage orders</a>
        </nav>

    </div>

</section>

<?php include '../includes/footer.php'; ?>