<?php

function cookieConsent(): string
{
    $value = $_COOKIE['cookie_consent'] ?? '';

    return in_array($value, ['accepted', 'declined'], true) ? $value : 'unset';
}

function hasCookieChoice(): bool
{
    return cookieConsent() !== 'unset';
}

function hasCookieConsent(): bool
{
    return cookieConsent() === 'accepted';
}