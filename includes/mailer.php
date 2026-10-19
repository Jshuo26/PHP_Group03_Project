<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/otp.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;


function sendOtpEmail(
    array $config,
    string $toEmail,
    string $toName,
    string $code,
    string $reason
): bool {

    foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM'] as $key) {
        $value = (string) ($config[$key] ?? '');

        if ($value === '' || str_contains($value, 'your_')) {
            error_log("Mailer: missing config {$key}.");
            return false;
        }
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['SMTP_USER'];
        $mail->Password   = $config['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($config['SMTP_PORT'] ?? 587);
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($config['SMTP_FROM'], 'aklAAAt!');
        $mail->addAddress($toEmail, $toName);
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $mail->isHTML(true);
        $mail->Subject = 'Your aklAAAt! verification code';

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;color:#3f3a37;'>
                <p>Hi {$safeName},</p>
                <p>Use this code to {$reason}:</p>
                <p style='font-size:32px;font-weight:bold;letter-spacing:8px;'>
                    {$code}
                </p>
                <p>The code expires in " . OTP_TTL_MINUTES . " minutes.</p>
                <p>If you did not request this, ignore this email.</p>
            </div>";

        $mail->AltBody =
            "Hi {$toName},\n\n"
            . "Your verification code is: {$code}\n"
            . "Purpose: {$reason}\n"
            . "The code expires in " . OTP_TTL_MINUTES . " minutes.";

        $mail->send();
        return true;

    } catch (MailException $e) {
        error_log('OTP email failed: ' . $e->getMessage());
        return false;
    }
}


function sendActivationEmail(
    array $config,
    string $toEmail,
    string $toName,
    string $activationUrl
): bool {

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['SMTP_USER'];
        $mail->Password   = $config['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($config['SMTP_PORT'] ?? 587);
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($config['SMTP_FROM'], 'aklAAAt!');
        $mail->addAddress($toEmail, $toName);

        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'Activate your aklAAAt! account';

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;color:#3f3a37;'>
                <p>Hi {$safeName},</p>
                <p>Your email address has been verified.</p>
                <p>Click below to activate your account.</p>
                <p>
                    <a href='{$safeUrl}'
                       style='display:inline-block;background:#8b7e74;
                              color:#ffffff;padding:12px 22px;
                              border-radius:8px;text-decoration:none;'
                              font-weight:bold;'>
                        Activate my account
                    </a>
                </p>
                <p>This link expires in " . ACTIVATION_LINK_HOURS . " hours.</p>
                <p>If you did not register, ignore this email.</p>
            </div>";

        $mail->AltBody =
            "Hi {$toName},\n\n"
            . "Your email has been verified.\n"
            . "Activate your account using this link:\n"
            . $activationUrl . "\n\n"
            . "The link expires in " . ACTIVATION_LINK_HOURS . " hours.";

        $mail->send();
        return true;

    } catch (MailException $e) {
        error_log('Activation email failed: ' . $e->getMessage());
        return false;
    }
}
