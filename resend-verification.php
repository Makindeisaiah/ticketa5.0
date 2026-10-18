<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/mailer.php';

if (authIsLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!authVerifyCsrf()) {
        $error = 'Your session expired. Please refresh and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } else {
        // Always show a generic response to avoid revealing account status.
        $message =
            'If an unverified account exists for that address, '
            . 'a verification email will be sent if permitted. Check your inbox.';

        try {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, email_verified_at, is_active
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user
                && $user['email_verified_at'] === null
                && (int) $user['is_active'] === 1) {

                $cooldown = $pdo->prepare(
                    'SELECT id
                     FROM email_verification_tokens
                     WHERE user_id = :user_id
                       AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                     LIMIT 1'
                );
                $cooldown->execute(['user_id' => $user['id']]);

                if (!$cooldown->fetch()) {
                    $pdo->beginTransaction();

                    $invalidate = $pdo->prepare(
                        'UPDATE email_verification_tokens
                         SET used_at = NOW()
                         WHERE user_id = :user_id AND used_at IS NULL'
                    );
                    $invalidate->execute(['user_id' => $user['id']]);

                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = date('Y-m-d H:i:s', time() + 1800);

                    $insert = $pdo->prepare(
                        'INSERT INTO email_verification_tokens
                            (user_id, token_hash, expires_at)
                         VALUES (:user_id, :token_hash, :expires_at)'
                    );
                    $insert->execute([
                        'user_id' => $user['id'],
                        'token_hash' => $tokenHash,
                        'expires_at' => $expiresAt,
                    ]);

                    $pdo->commit();

                    $mailConfig = require __DIR__ . '/config/mail.php';
                    $verificationUrl = rtrim($mailConfig['base_url'], '/')
                        . '/verify-email.php?token=' . urlencode($rawToken);

                    try {
                        sendVerificationEmail(
                            $user['email'],
                            $user['full_name'],
                            $verificationUrl
                        );
                    } catch (Throwable $mailError) {
                        error_log(
                            'Ticketa resend verification error: '
                            . $mailError->getMessage()
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Ticketa resend request error: ' . $e->getMessage());
            // Keep the generic response.
        }
    }
}

$pageTitle = 'Resend Verification | TICKETA';
$pageStyles = [];
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <section class="auth-card">
        <h1>Resend verification email</h1>
        <p>Enter the email address you used to register.</p>

        <?php if ($error !== ''): ?>
            <p class="auth-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <p class="auth-success">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form method="post" action="resend-verification.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <label for="email">Email address</label>
            <input id="email" name="email" type="email"
                   maxlength="190" autocomplete="email" required>

            <button type="submit">Send verification link</button>
        </form>

        <p><a href="login.php">Return to sign in</a></p>
    </section>
</main>

<style>
.auth-page{max-width:480px;margin:60px auto;padding:20px}
.auth-card{padding:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}
.auth-card form{display:grid;gap:10px}
.auth-card label{font-weight:600;margin-top:8px}
.auth-card input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d1d5db;border-radius:8px;font:inherit}
.auth-card button{padding:13px;border:0;border-radius:8px;background:#10c6a4;color:#102a26;font-weight:700;cursor:pointer;margin-top:12px}
.auth-error,.auth-success{padding:10px;border-radius:8px}
.auth-error{background:#fff0f0;color:#9b1c1c}
.auth-success{background:#e8fff7;color:#065f46}
</style>
</body>
</html>