<?php

$isLocalHost = isset($_SERVER['HTTP_HOST']) && (
    $_SERVER['HTTP_HOST'] === 'localhost'
    || $_SERVER['HTTP_HOST'] === '127.0.0.1'
    || strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0
);

if ($isLocalHost) {
    $db_host = 'localhost';
    $db_user = 'your_username_here';
    $db_name = 'aklaaat_db';
    $db_pass = 'your_password_here';
    $app_url = 'http://localhost:8000';
} else {
    $db_host = 'your_host_here';
    $db_user = 'your_username_here';
    $db_name = 'your_name_here';
    $db_pass = 'your_password_here';
    $app_url = 'https://aklaaat.infinityfreeapp.com';
}

return [
    'APP_URL'    => $app_url,
    'DB_HOST'    => $db_host,
    'DB_USER'    => $db_user,
    'DB_NAME'    => $db_name,
    'DB_PASS'    => $db_pass,
    'DB_CHARSET' => 'utf8mb4',

    'RECAPTCHA_SITE_KEY' => 'your_site_key_here',
    'RECAPTCHA_SECRET_KEY' => 'your_secret_key_here',

    'SMTP_HOST'     => 'smtp.gmail.com',
    'SMTP_PORT'     => 587,
    'SMTP_USER'     => 'your_gmail_here@gmail.com',
    'SMTP_PASS'     => 'your_16_char_app_password_here',
    'SMTP_FROM'     => 'your_gmail_here@gmail.com',

    'PAYMONGO_MODE' => 'test',
    'PAYMONGO_TEST_PUBLIC_KEY' => 'pk_test_your_test_public_key_here',
    'PAYMONGO_TEST_SECRET_KEY' => 'sk_test_your_test_secret_key_here',
    'PAYMONGO_TEST_WEBHOOK_SECRET' => 'your_test_webhook_signing_secret_here',
    'PAYMONGO_LIVE_PUBLIC_KEY' => 'pk_live_your_live_public_key_here',
    'PAYMONGO_LIVE_SECRET_KEY' => 'sk_live_your_live_secret_key_here',
    'PAYMONGO_LIVE_WEBHOOK_SECRET' => 'your_live_webhook_signing_secret_here',

    'ENCRYPTION_KEY' => 'paste_the_output_here',
];
