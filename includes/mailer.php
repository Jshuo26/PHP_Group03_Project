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
    string $reason,
    ?string $activationUrl = null
): bool {

    foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM'] as $key) {

        $value = (string) ($config[$key] ?? '');

        if ($value === '' || str_contains($value, 'your_')) {
            error_log("Mailer: config key {$key} is missing or still a placeholder.");
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

        $minutes  = OTP_TTL_MINUTES;
        $hours    = ACTIVATION_LINK_HOURS;
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $linkHtml = '';
        $linkText = '';

        if ($activationUrl !== null && $activationUrl !== '') {

            $safeUrl = htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8');

            $linkHtml = "
                <p>Or activate your account with one click:</p>
                <p>
                    <a href=\"{$safeUrl}\"
                       style=\"display:inline-block;background:#8b7e74;color:#ffffff;
                              padding:12px 22px;border-radius:8px;
                              text-decoration:none;font-weight:bold;\">
                        Activate my account
                    </a>
                </p>
                <p style=\"color:#6c6865;font-size:12px;\">
                    If the button does not work, copy this link into your browser:<br>
                    {$safeUrl}<br>
                    The link works for {$hours} hours and can be used once.
                </p>";

            $linkText = "\n\nOr activate your account with this link "
                . "(valid {$hours} hours, one use):\n{$activationUrl}";
        }

        $mail->isHTML(true);
        $mail->Subject = 'Your aklAAAt! verification code';

        $mail->Body = "
            <div style=\"font-family:Arial,Helvetica,sans-serif;color:#3f3a37;\">
                <p>Hi {$safeName},</p>
                <p>Use this code to {$reason}:</p>
                <p style=\"font-size:32px;font-weight:bold;letter-spacing:8px;margin:16px 0;\">
                    {$code}
                </p>
                <p>The code expires in {$minutes} minutes and can be used once.</p>
                {$linkHtml}
                <p style=\"color:#6c6865;font-size:13px;\">
                    If you did not request this, you can safely ignore this email.
                    Never share this code with anyone.
                </p>
            </div>";

        $mail->AltBody =
            "Hi {$toName},\n\n"
            . "Use this code to {$reason}: {$code}\n\n"
            . "The code expires in {$minutes} minutes and can be used once."
            . $linkText . "\n\n"
            . "If you did not request this, ignore this email.";

        $mail->send();

        return true;

    } catch (MailException $e) {

        error_log('Mailer failed: ' . $e->getMessage());

        return false;
    }
}