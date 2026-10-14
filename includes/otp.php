<?php

const OTP_TTL_MINUTES       = 5;
const OTP_MAX_ATTEMPTS      = 5;
const OTP_RESEND_SECONDS    = 60;
const ACTIVATION_LINK_HOURS = 24;

function generateOtpCode(): string
{

    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function createOtp(PDO $pdo, int $userId, string $purpose): string
{
    $code = generateOtpCode();

    $pdo->prepare(
        'UPDATE otp_codes
            SET used_at = NOW()
          WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
    )->execute([$userId, $purpose]);

    $pdo->prepare(
        'INSERT INTO otp_codes (user_id, code_hash, purpose, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
    )->execute([
        $userId,
        password_hash($code, PASSWORD_DEFAULT),
        $purpose,
        OTP_TTL_MINUTES,
    ]);

    return $code;
}

function otpResendWait(PDO $pdo, int $userId, string $purpose): int
{
    $stmt = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(
                    SECOND,
                    DATE_SUB(expires_at, INTERVAL ? MINUTE),
                    NOW()
                )
           FROM otp_codes
          WHERE user_id = ? AND purpose = ?
          ORDER BY otp_id DESC
          LIMIT 1'
    );

    $stmt->execute([OTP_TTL_MINUTES, $userId, $purpose]);

    $age = $stmt->fetchColumn();

    if ($age === false || $age === null) {
        return 0;
    }

    return max(0, OTP_RESEND_SECONDS - (int) $age);
}

function otpSecondsLeft(PDO $pdo, int $userId, string $purpose): int
{
    $stmt = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at)
           FROM otp_codes
          WHERE user_id = ? AND purpose = ? AND used_at IS NULL
          ORDER BY otp_id DESC
          LIMIT 1'
    );

    $stmt->execute([$userId, $purpose]);

    $left = $stmt->fetchColumn();

    return ($left === false || $left === null) ? 0 : max(0, (int) $left);
}

function verifyOtp(PDO $pdo, int $userId, string $purpose, string $code): array
{
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return [false, 'Enter the 6-digit code from your email.'];
    }

    $stmt = $pdo->prepare(
        'SELECT otp_id, code_hash, attempts
           FROM otp_codes
          WHERE user_id = ?
            AND purpose = ?
            AND used_at IS NULL
            AND expires_at > NOW()
          ORDER BY otp_id DESC
          LIMIT 1'
    );

    $stmt->execute([$userId, $purpose]);

    $row = $stmt->fetch();

    if (!$row) {
        return [false, 'This code has expired. Request a new one.'];
    }

    if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return [false, 'Too many wrong attempts. Request a new code.'];
    }

    $pdo->prepare(
        'UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = ?'
    )->execute([$row['otp_id']]);

    if (!password_verify($code, $row['code_hash'])) {

        $left = OTP_MAX_ATTEMPTS - ((int) $row['attempts'] + 1);

        return [
            false,
            $left > 0
                ? "Incorrect code. {$left} attempt(s) left."
                : 'Too many wrong attempts. Request a new code.',
        ];
    }

    $mark = $pdo->prepare(
        'UPDATE otp_codes SET used_at = NOW() WHERE otp_id = ? AND used_at IS NULL'
    );

    $mark->execute([$row['otp_id']]);

    if ($mark->rowCount() !== 1) {
        return [false, 'This code was already used.'];
    }

    return [true, ''];
}

function createActivationToken(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));

    $pdo->prepare(
        "UPDATE otp_codes
            SET used_at = NOW()
          WHERE user_id = ? AND purpose = 'activation_link' AND used_at IS NULL"
    )->execute([$userId]);

    $pdo->prepare(
        "INSERT INTO otp_codes (user_id, code_hash, purpose, expires_at)
         VALUES (?, ?, 'activation_link', DATE_ADD(NOW(), INTERVAL ? HOUR))"
    )->execute([
        $userId,
        hash('sha256', $token),
        ACTIVATION_LINK_HOURS,
    ]);

    return $token;
}

function activationUrl(array $config, string $token): string
{
    $base = rtrim((string) ($config['APP_URL'] ?? ''), '/');

    if ($base === '' || str_contains($base, 'your_')) {
        $base = 'http://localhost:8000';
    }

    return $base . '/auth/activate.php?token=' . urlencode($token);
}

function maskEmail(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

    if ($domain === '') {
        return $email;
    }

    return mb_substr($name, 0, 2)
        . str_repeat('*', max(1, mb_strlen($name) - 2))
        . '@' . $domain;
}