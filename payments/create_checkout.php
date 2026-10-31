<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../controllers/PaymongoController.php';

requireRole(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
requireCsrfToken();

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($orderId < 1) {
    $_SESSION['order_flash_error'] = 'Select a valid order to continue payment.';
    header('Location: /account/orders.php');
    exit;
}

try {
    $controller = new PaymongoController($pdo, $config);
    $destination = $controller->startOrResumeCheckout($orderId, (int) $_SESSION['user_id']);
    if (str_starts_with($destination, '/')) {
        header('Location: ' . $destination);
    } else {
        header('Location: ' . $destination);
    }
    exit;
} catch (Throwable $e) {
    error_log('Unable to start PayMongo checkout for order #' . $orderId . '.');
    $_SESSION['order_flash_error'] = $e instanceof PaymongoApiException
        ? paymongoSafeCheckoutDiagnostic($e)
        : $e->getMessage();
    header('Location: /account/orders.php');
    exit;
}
