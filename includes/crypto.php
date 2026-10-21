<?php

function appEncryptionKey(): string
{
    $config = $GLOBALS['config'] ?? null;

    if (!is_array($config)) {
        $config = require __DIR__ . '/../config.php';
    }

    $key = base64_decode((string) ($config['ENCRYPTION_KEY'] ?? ''), true);

    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('ENCRYPTION_KEY is missing or invalid in config.php.');
    }

    return $key;
}

function encryptField(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt($plain, 'aes-256-gcm', appEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }

    return base64_encode($iv . $tag . $cipher);
}

function decryptField(string $payload): ?string
{
    $raw = base64_decode($payload, true);

    if ($raw === false || strlen($raw) < 29) {
        return null;
    }

    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', appEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

    return $plain === false ? null : $plain;
}