<?php
/**
 * Ticketa organizer session registry.
 * Store only a SHA-256 hash of the PHP session ID, never the raw session ID.
 */

function ticketaRegisterCurrentSession(PDO $pdo, int $userId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        throw new RuntimeException('Cannot register an inactive session.');
    }

    $sessionHash = hash('sha256', session_id());
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser'));
    $deviceName = substr($userAgent !== '' ? $userAgent : 'Unknown browser', 0, 255);
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        $ipAddress = null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO user_sessions
            (user_id, session_token_hash, device_name, ip_address, last_activity, created_at, revoked_at)
         VALUES
            (:user_id, :session_hash, :device_name, :ip_address, NOW(), NOW(), NULL)'
    );

    $stmt->execute([
        'user_id' => $userId,
        'session_hash' => $sessionHash,
        'device_name' => $deviceName,
        'ip_address' => $ipAddress,
    ]);
}

/**
 * Call on every protected request after database.php has provided $pdo.
 * Returns false if the session is not registered or has been revoked.
 */
function ticketaValidateCurrentSession(PDO $pdo): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        return false;
    }

    $sessionHash = hash('sha256', session_id());
    $stmt = $pdo->prepare(
        'SELECT id, revoked_at
         FROM user_sessions
         WHERE session_token_hash = :session_hash
         LIMIT 1'
    );
    $stmt->execute(['session_hash' => $sessionHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['revoked_at'] !== null) {
        return false;
    }

    $touch = $pdo->prepare(
        'UPDATE user_sessions SET last_activity = NOW() WHERE id = :id AND revoked_at IS NULL'
    );
    $touch->execute(['id' => (int) $row['id']]);

    return true;
}

/**
 * Mark the current registry row revoked. Call before destroying a session.
 */
function ticketaRevokeCurrentSession(PDO $pdo): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE user_sessions
         SET revoked_at = NOW()
         WHERE session_token_hash = :session_hash AND revoked_at IS NULL'
    );
    $stmt->execute(['session_hash' => hash('sha256', session_id())]);
}
