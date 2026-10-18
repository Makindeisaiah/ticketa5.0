<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$message = 'This verification link is invalid, expired, or has already been used.';
$verified = false;

$token = $_GET['token'] ?? '';

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $tokenHash = hash('sha256', $token);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT t.id AS token_id, t.user_id
             FROM email_verification_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND t.used_at IS NULL
               AND t.expires_at > NOW()
               AND u.email_verified_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $record = $stmt->fetch();

        if ($record) {
            $updateUser = $pdo->prepare(
                'UPDATE users
                 SET email_verified_at = NOW()
                 WHERE id = :user_id AND email_verified_at IS NULL'
            );
            $updateUser->execute(['user_id' => $record['user_id']]);

            $useToken = $pdo->prepare(
                'UPDATE email_verification_tokens
                 SET used_at = NOW()
                 WHERE id = :token_id AND used_at IS NULL'
            );
            $useToken->execute(['token_id' => $record['token_id']]);

            if ($updateUser->rowCount() === 1 && $useToken->rowCount() === 1) {
                $pdo->commit();
                $verified = true;
                $message = 'Your email has been verified. You can now sign in.';
            } else {
                $pdo->rollBack();
            }
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Ticketa email verification error: ' . $e->getMessage());
        $message = 'We could not verify your email right now. Please try again.';
    }
}

$pageTitle = 'Verify Email | TICKETA';
$pageStyles = [];
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <section class="auth-card">
        <h1><?= $verified ? 'Email verified' : 'Email verification' ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($verified): ?>
            <p><a href="login.php">Sign in to Ticketa</a></p>
        <?php else: ?>
            <p><a href="resend-verification.php">Request a new verification email</a></p>
        <?php endif; ?>
    </section>
</main>

<style>
.auth-page{max-width:480px;margin:60px auto;padding:20px}
.auth-card{padding:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}
</style>
</body>
</html>