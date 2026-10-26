
<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_registry.php';

// Accept only a POST request with a valid CSRF token.
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !authVerifyCsrf()
) {
    http_response_code(403);
    exit('Invalid request. Please return to Ticketa and try again.');
}

// Revoke the current session before destroying it.
// Do not silently ignore a database failure.
try {
    ticketaRevokeCurrentSession($pdo);
} catch (Throwable $e) {
    error_log('Ticketa organizer logout error: ' . $e->getMessage());

    http_response_code(500);
    exit('We could not safely complete logout. Please try again.');
}

// Clear session data.
$_SESSION = [];

// Expire the session cookie using its existing settings.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

// Destroy the PHP session.
session_destroy();

// Redirect to the organizer login page.
header('Location: login.php');
exit;
