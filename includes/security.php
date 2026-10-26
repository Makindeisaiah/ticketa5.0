
<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PragmaRX\Google2FA\Google2FA;

/**
 * Load and validate the application's encryption key.
 *
 * The key file must return a Base64-encoded 32-byte key.
 */
function ticketaSecurityKey(): string
{
    $keyFile = dirname(__DIR__, 3)
        . DIRECTORY_SEPARATOR . 'private'
        . DIRECTORY_SEPARATOR . 'ticketa_security_key.php';

    if (!is_file($keyFile)) {
        throw new RuntimeException(
            'Ticketa security key file is missing.'
        );
    }

    $encoded = require $keyFile;

    if (!is_string($encoded)) {
        throw new RuntimeException(
            'Ticketa security key is invalid.'
        );
    }

    $key = base64_decode($encoded, true);

    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException(
            'The security key must decode to exactly 32 bytes.'
        );
    }

    return $key;
}

/**
 * Encrypt a secret using AES-256-GCM.
 */
function ticketaEncryptSecret(string $secret): string
{
    $iv = random_bytes(12);
    $tag = '';

    $encrypted = openssl_encrypt(
        $secret,
        'aes-256-gcm',
        ticketaSecurityKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($encrypted === false) {
        throw new RuntimeException(
            'Could not encrypt the secret.'
        );
    }

    // Store IV (12 bytes) + authentication tag (16 bytes) + ciphertext.
    return base64_encode($iv . $tag . $encrypted);
}

/**
 * Decrypt a secret previously encrypted by ticketaEncryptSecret().
 */
function ticketaDecryptSecret(string $encoded): string
{
    $data = base64_decode($encoded, true);

    if ($data === false || strlen($data) < 29) {
        throw new RuntimeException(
            'Stored secret is invalid.'
        );
    }

    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $encrypted = substr($data, 28);

    $secret = openssl_decrypt(
        $encrypted,
        'aes-256-gcm',
        ticketaSecurityKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($secret === false) {
        throw new RuntimeException(
            'Could not decrypt the secret.'
        );
    }

    return $secret;
}

/**
 * Verify a six-digit Google Authenticator TOTP code.
 *
 * The verification window allows one time step of clock difference.
 */
function ticketaVerifyTotp(string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $google2fa = new Google2FA();

    return $google2fa->verifyKey($secret, $code, 1);
}
