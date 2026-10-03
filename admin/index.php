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

    </div>

</section>

<?php include '../includes/footer.php'; ?>