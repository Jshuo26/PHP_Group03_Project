<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';

if (!file_exists($configFile)) {
    error_log('config.php is missing. Copy config.sample.php to config.php.');
    http_response_code(500);
    exit('Server configuration error.');
}

$config = require $configFile;

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['DB_HOST'],
    $config['DB_NAME'],
    $config['DB_CHARSET']
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], $options);
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection error.');
}