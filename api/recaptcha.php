<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Verify a reCAPTCHA v2 checkbox token with Google's API.
 * Returns true if the token is valid, false otherwise.
 */
function verifyRecaptcha(string $token): bool
{
    if (portalLocalCaptchaBypass()) return true;
    if ($token === '') return false;

    $secret = portalConfig()['recaptcha_secret'] ?? '';
    if ($secret === '') {
        error_log('reCAPTCHA secret key is not configured.');
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('reCAPTCHA verification request failed.');
        return false;
    }

    $data = json_decode($response, true);
    return is_array($data) && ($data['success'] ?? false) === true;
}
