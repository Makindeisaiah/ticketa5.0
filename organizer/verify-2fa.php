
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/session_registry.php';

$pending = $_SESSION['pending_organizer_2fa'] ?? [];

if (
    !is_array($pending)
    || (int) ($pending['id'] ?? 0) < 1
    || ($pending['role'] ?? '') !== 'organizer'
    || !isset($pending['created_at'])
    || time() - (int) $pending['created_at'] > 300
) {
    unset($_SESSION['pending_organizer_2fa']);
    header('Location: login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!authVerifyCsrf()) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        $code = trim((string) ($_POST['code'] ?? ''));

        try {
            $stmt = $pdo->prepare(
                'SELECT secret_encrypted, is_enabled
                 FROM user_two_factor
                 WHERE user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute(['user_id' => (int) $pending['id']]);
            $twoFactor = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$twoFactor || (int) $twoFactor['is_enabled'] !== 1) {
                throw new RuntimeException('Two-factor setup is unavailable.');
            }

            $secret = ticketaDecryptSecret(
                (string) $twoFactor['secret_encrypted']
            );

            $verified = ticketaVerifyTotp($secret, $code);

            // If it is not a TOTP code, try an unused recovery code.
            if (!$verified && $code !== '') {
                $recoveryStmt = $pdo->prepare(
                    'SELECT id, code_hash
                     FROM user_two_factor_recovery_codes
                     WHERE user_id = :user_id
                       AND used_at IS NULL'
                );
                $recoveryStmt->execute([
                    'user_id' => (int) $pending['id'],
                ]);

                foreach ($recoveryStmt->fetchAll(PDO::FETCH_ASSOC) as $recovery) {
                    if (password_verify($code, $recovery['code_hash'])) {
                        $consume = $pdo->prepare(
                            'UPDATE user_two_factor_recovery_codes
                             SET used_at = NOW()
                             WHERE id = :id
                               AND user_id = :user_id
                               AND used_at IS NULL'
                        );
                        $consume->execute([
                            'id' => (int) $recovery['id'],
                            'user_id' => (int) $pending['id'],
                        ]);

                        $verified = $consume->rowCount() === 1;
                        break;
                    }
                }
            }

            if (!$verified) {
                $error = 'That code is invalid or has already been used.';
            } else {
                // Re-check account status before completing authentication.
                $userStmt = $pdo->prepare(
                    'SELECT id, full_name, email, role, is_active,
                            email_verified_at
                     FROM users
                     WHERE id = :id
                     LIMIT 1'
                );
                $userStmt->execute(['id' => (int) $pending['id']]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (
                    !$user
                    || (int) $user['is_active'] !== 1
                    || $user['role'] !== 'organizer'
                    || empty($user['email_verified_at'])
                ) {
                    unset($_SESSION['pending_organizer_2fa']);
                    header('Location: login.php');
                    exit;
                }

                session_regenerate_id(true);
                ticketaRegisterCurrentSession($pdo, (int) $user['id']);

                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ];

                unset($_SESSION['pending_organizer_2fa']);
                unset($_SESSION['auth_csrf_token']);

                header('Location: dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Ticketa 2FA verification error: ' . $e->getMessage());
            $error = 'We could not verify your code. Please try again.';
        }
    }
}

function verifyTwoFactorEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Sign In | TICKETA</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#f7faf9;color:#152b28;font-family:Arial,sans-serif}
        .card{width:min(440px,100%);padding:30px;border:1px solid #e5ebe9;border-radius:16px;background:#fff}
        h1{margin-top:0;font-size:27px}
        p{color:#71817e;line-height:1.6}
        label{display:block;margin:20px 0 8px;font-weight:700}
        input{width:100%;padding:13px;border:1px solid #dce5e2;border-radius:9px;font:inherit}
        button{width:100%;margin-top:18px;padding:14px;border:0;border-radius:9px;background:#10c6a4;color:#102a26;font-weight:800;cursor:pointer}
        .error{padding:12px;border-radius:8px;background:#fff2f2;color:#a52828}
        a{color:#087f6b}
    </style>
</head>
<body>
    <main class="card">
        <h1>Verify it's you</h1>
        <p>Enter the six-digit code from your authenticator app. You can also enter an unused recovery code.</p>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= verifyTwoFactorEscape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="verify-2fa.php">
            <input type="hidden" name="csrf_token"
                   value="<?= verifyTwoFactorEscape(authCsrfToken()) ?>">
            <label for="code">Authenticator or recovery code</label>
            <input id="code" name="code" type="text" inputmode="text"
                   autocomplete="one-time-code" maxlength="100" required autofocus>
            <button type="submit">Verify and sign in</button>
        </form>

        <p><a href="login.php">Cancel and return to sign in</a></p>
    </main>
</body>
</html>
