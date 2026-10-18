
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    session_start();
}

function authCsrfToken(): string
{
    if (empty($_SESSION['auth_csrf_token'])) {
        $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['auth_csrf_token'];
}

function authVerifyCsrf(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['auth_csrf_token'] ?? '';

    return is_string($submitted)
        && is_string($stored)
        && $stored !== ''
        && hash_equals($stored, $submitted);
}

function authIsLoggedIn(): bool
{
    return isset($_SESSION['user']['id']);
}

function authRedirectAfterLogin(): never
{
    // Only allow known local destinations.
    $destination = $_SESSION['auth_return_to'] ?? 'index.php';
    unset($_SESSION['auth_return_to']);

    $allowed = [
        'index.php',
        'events.php',
        'checkout.php',
        'account.php',
    ];

    if (!in_array($destination, $allowed, true)) {
        $destination = 'index.php';
    }

    header('Location: ' . $destination);
    exit;
}
