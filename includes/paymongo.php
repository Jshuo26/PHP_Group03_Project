<?php

class PaymongoApiException extends RuntimeException
{
    public int $httpStatus;
    public bool $uncertain;

    public function __construct(int $httpStatus, bool $uncertain = false)
    {
        parent::__construct('PayMongo could not complete the request.');
        $this->httpStatus = $httpStatus;
        $this->uncertain = $uncertain;
    }
}

function paymongoSetting(array $config, string $name): string
{
    $value = trim((string) ($config[$name] ?? ''));
    $env = getenv($name);
    if ($env !== false && trim($env) !== '') {
        $value = trim($env);
    }
    return $value;
}

function paymongoTestKeys(array $config): array
{
    $publicKey = paymongoSetting($config, 'PAYMONGO_PUBLIC_KEY');
    $secretKey = paymongoSetting($config, 'PAYMONGO_SECRET_KEY');
    if (!preg_match('/^pk_test_[A-Za-z0-9_]+$/', $publicKey)
        || !preg_match('/^sk_test_[A-Za-z0-9_]+$/', $secretKey)) {
        throw new RuntimeException('PayMongo sandbox keys are missing or are not test-mode keys. Configure pk_test_ and sk_test_ values.');
    }
    return ['public_key' => $publicKey, 'secret_key' => $secretKey];
}

function paymongoWebhookSecret(array $config): string
{
    $secret = paymongoSetting($config, 'PAYMONGO_WEBHOOK_SECRET');
    if ($secret === '' || strlen($secret) < 16) {
        throw new RuntimeException('The PayMongo test webhook signing secret is not configured.');
    }
    return $secret;
}

function paymongoApiRequest(array $config, string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    $keys = paymongoTestKeys($config);
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The server does not have the PHP cURL extension enabled.');
    }
    if (!preg_match('#^/v2/checkout_sessions(?:/[A-Za-z0-9_-]+(?:/expire)?)?$#', $path)) {
        throw new InvalidArgumentException('Unsupported PayMongo API path.');
    }

    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    $handle = curl_init('https://api.paymongo.com' . $path);
    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $keys['secret_key'] . ':',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($handle, $options);
    $responseBody = curl_exec($handle);
    $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($handle);
    curl_close($handle);

    if ($responseBody === false || $curlError !== 0) {
        error_log('PayMongo API transport failed; cURL error number ' . $curlError . '.');
        throw new PaymongoApiException($httpStatus, true);
    }

    try {
        $response = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('PayMongo API returned invalid JSON (HTTP ' . $httpStatus . ').');
        throw new PaymongoApiException($httpStatus, $httpStatus >= 500);
    }
    if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response)) {
        error_log('PayMongo API returned HTTP ' . $httpStatus . '.');
        throw new PaymongoApiException($httpStatus, $httpStatus === 0 || $httpStatus >= 500 || $httpStatus === 409);
    }
    return $response;
}

function paymongoValidateCheckoutUrl(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string) ($parts['host'] ?? '')) === 'checkout.paymongo.com'
        && isset($parts['path']);
}

function paymongoVerifyWebhookSignature(string $rawBody, string $header, string $secret, int $now): bool
{
    $parts = [];
    foreach (explode(',', $header) as $segment) {
        $pair = explode('=', trim($segment), 2);
        if (count($pair) === 2) {
            $parts[$pair[0]] = $pair[1];
        }
    }
    if (!isset($parts['t'], $parts['te']) || !ctype_digit($parts['t'])
        || abs($now - (int) $parts['t']) > 300
        || !preg_match('/^[a-f0-9]{64}$/i', $parts['te'])) {
        return false;
    }
    $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);
    return hash_equals(strtolower($expected), strtolower($parts['te']));
}