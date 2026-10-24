<?php

function appEncryptionKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $configuredKey = getenv('AKLAAAT_ENCRYPTION_KEY');
    if ($configuredKey === false || $configuredKey === '') {
        $config = $GLOBALS['config'] ?? null;
        if (!is_array($config)) {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $isLocalRequest = $host === 'localhost' || $host === '127.0.0.1' || str_starts_with($host, 'localhost:');
            $localConfigFile = __DIR__ . '/../config/config.php';
            $rootConfigFile = __DIR__ . '/../config.php';
            $configFile = $isLocalRequest && is_file($localConfigFile) ? $localConfigFile : $rootConfigFile;
            $config = is_file($configFile) ? require $configFile : [];
        }
        $configuredKey = is_array($config) ? (string) ($config['ENCRYPTION_KEY'] ?? '') : '';
    }

    $key = base64_decode((string) $configuredKey, true);

    if ($key === false || strlen($key) !== 32) {
        $key = null;
        throw new RuntimeException('Application encryption is unavailable. Configure a valid server-side key.');
    }

    return $key;
}

function encryptPersonalField(string $plain, string $context): string
{
    $iv = random_bytes(12);
    $tag = '';
    $aad = 'aklaaat|personal|v1|' . $context;
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', appEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);

    if ($cipher === false) {
        throw new RuntimeException('Personal data encryption failed.');
    }

    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

function decryptPersonalField(?string $payload, string $context): ?string
{
    if ($payload === null) {
        return null;
    }

    if (!str_starts_with($payload, 'enc:v1:')) {
        return $payload;
    }

    $raw = base64_decode(substr($payload, 7), true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $aad = 'aklaaat|personal|v1|' . $context;
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', appEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, $aad);

    return $plain === false ? null : $plain;
}

function normalizeEmailForLookup(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function emailLookupHash(string $email): string
{
    $lookupKey = hash_hkdf('sha256', appEncryptionKey(), 32, 'aklaaat/email-lookup/v1');
    return hash_hmac('sha256', normalizeEmailForLookup($email), $lookupKey);
}

function personalFieldContext(string $table, string $field, string|int $recordId): string
{
    return $table . '.' . $field . ':' . (string) $recordId;
}

function decryptUserPersonalFields(array $row): array
{
    if (!isset($row['user_id'])) {
        throw new InvalidArgumentException('User identifier is required to decrypt personal data.');
    }
    foreach (['first_name', 'last_name', 'full_name', 'email', 'mobile_number'] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = decryptPersonalField(
                $row[$field] === null ? null : (string) $row[$field],
                personalFieldContext('users', $field, $row['user_id'])
            );
        }
    }
    return $row;
}

function decryptAddressPersonalFields(array $row): array
{
    if (!isset($row['address_id'])) {
        throw new InvalidArgumentException('Address identifier is required to decrypt personal data.');
    }
    foreach (['label', 'street', 'city', 'province', 'zip_code'] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = decryptPersonalField(
                $row[$field] === null ? null : (string) $row[$field],
                personalFieldContext('addresses', $field, $row['address_id'])
            );
        }
    }
    return $row;
}

function encryptOrderPersonalFields(array $fields, string $orderNumber): array
{
    foreach (['shipping_name', 'shipping_mobile', 'shipping_street', 'shipping_city', 'shipping_province', 'shipping_zip'] as $field) {
        if (array_key_exists($field, $fields) && $fields[$field] !== null) {
            $fields[$field] = encryptPersonalField(
                (string) $fields[$field],
                personalFieldContext('orders', $field, $orderNumber)
            );
        }
    }
    return $fields;
}

function decryptOrderPersonalFields(array $row): array
{
    if (!isset($row['order_number'])) {
        throw new InvalidArgumentException('Order number is required to decrypt personal data.');
    }
    foreach (['shipping_name', 'shipping_mobile', 'shipping_street', 'shipping_city', 'shipping_province', 'shipping_zip'] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = decryptPersonalField(
                $row[$field] === null ? null : (string) $row[$field],
                personalFieldContext('orders', $field, $row['order_number'])
            );
        }
    }
    return $row;
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