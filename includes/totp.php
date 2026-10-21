<?php

const TOTP_PERIOD = 30;
const TOTP_ISSUER = 'aklAAAt!';

function totpBase32Encode(string $binary): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    foreach (str_split($binary) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }

    return $out;
}

function totpBase32Decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32));
    $bits = '';

    foreach (str_split($base32) as $char) {
        $position = strpos($alphabet, $char);
        if ($position !== false) {
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
    }

    $binary = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $binary .= chr(bindec($byte));
        }
    }

    return $binary;
}

function totpGenerateSecret(): string
{
    return totpBase32Encode(random_bytes(20));
}

function totpCodeForStep(string $secretBinary, int $step): string
{
    $counter = pack('N*', 0, $step);
    $hash = hash_hmac('sha1', $counter, $secretBinary, true);
    $offset = ord($hash[19]) & 0x0F;

    $value = ((ord($hash[$offset]) & 0x7F) << 24)
           | ((ord($hash[$offset + 1]) & 0xFF) << 16)
           | ((ord($hash[$offset + 2]) & 0xFF) << 8)
           |  (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function totpVerify(string $secretBase32, string $code, int $window = 1): ?int
{
    $code = preg_replace('/\s+/', '', $code);

    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return null;
    }

    $secretBinary = totpBase32Decode($secretBase32);
    $current = intdiv(time(), TOTP_PERIOD);
    $matched = null;

    for ($i = -$window; $i <= $window; $i++) {
        $step = $current + $i;
        if (hash_equals(totpCodeForStep($secretBinary, $step), $code)) {
            $matched = $step;
        }
    }

    return $matched;
}

function totpProvisioningUri(string $secretBase32, string $accountEmail): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER . ':' . $accountEmail)
        . '?secret=' . $secretBase32
        . '&issuer=' . rawurlencode(TOTP_ISSUER)
        . '&algorithm=SHA1&digits=6&period=' . TOTP_PERIOD;
}

function totpQrSvg(string $uri): ?string
{
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        return null;
    }

    require_once $autoload;

    if (!class_exists('BaconQrCode\Writer')) {
        return null;
    }

    try {
        $renderer = new BaconQrCode\Renderer\ImageRenderer(
            new BaconQrCode\Renderer\RendererStyle\RendererStyle(220, 2),
            new BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $svg = (new BaconQrCode\Writer($renderer))->writeString($uri);

        return preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg);
    } catch (Throwable $e) {
        error_log('QR code generation failed: ' . $e->getMessage());
        return null;
    }
}