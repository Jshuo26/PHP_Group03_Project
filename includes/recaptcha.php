<?php

function verifyRecaptcha(string $token, string $secret): bool
{
    if ($token === '' || $secret === '') {
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $result = curl_exec($ch);

    if ($result === false) {
        error_log('reCAPTCHA request failed: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $data = json_decode($result, true);

    return is_array($data) && !empty($data['success']);
}