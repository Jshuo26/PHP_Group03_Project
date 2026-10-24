<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../controllers/PaymongoController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"error":"method_not_allowed"}';
    exit;
}

$rawBody = file_get_contents('php://input', false, null, 0, 1048577);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1048576) {
    http_response_code(400);
    echo '{"error":"invalid_request"}';
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$signature = '';
foreach ($headers as $name => $value) {
    if (strcasecmp((string) $name, 'Paymongo-Signature') === 0) {
        $signature = (string) $value;
        break;
    }
}
if ($signature === '') {
    $signature = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
}

try {
    $signingSecret = paymongoWebhookSecret($config);
} catch (Throwable $e) {
    error_log('PayMongo webhook signing secret is not configured.');
    http_response_code(503);
    echo '{"error":"webhook_unavailable"}';
    exit;
}

if (!paymongoVerifyWebhookSignature($rawBody, $signature, $signingSecret, time())) {
    error_log('Rejected a PayMongo webhook with an invalid or stale signature.');
    http_response_code(401);
    echo '{"error":"invalid_signature"}';
    exit;
}

try {
    $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
    $controller = new PaymongoController($pdo, $config);
    $result = $controller->processVerifiedWebhook($event);
    http_response_code(200);
    echo json_encode(['received' => true, 'result' => $result]);
} catch (UnexpectedValueException $e) {
    error_log('A signed PayMongo sandbox webhook failed order/payment validation.');
    http_response_code(202);
    echo '{"received":true,"result":"ignored"}';
} catch (Throwable $e) {
    error_log('PayMongo webhook processing failed; provider retry is required.');
    http_response_code(500);
    echo '{"error":"processing_failed"}';
}