<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

if (authIsLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!authVerifyCsrf()) {
        $error = 'Your session expired. Please refresh and try again.';
    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 190
        || $password === ''
    ) {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    full_name,
                    email,
                    password_hash,
                    role,
                    is_active,
                    email_verified_at
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (
                !$user
                || !password_verify($password, $user['password_hash'])
                || !(int) $user['is_active']
                || $user['role'] !== 'attendee'
            ) {
                $error =
                    'Invalid email or password, or this account cannot sign in here.';
            } elseif ($user['email_verified_at'] === null) {
                $error =
                    'Please verify your email before signing in. '
                    . 'Check your inbox for the verification link. '
                    . 'If you need a new link, use the resend option below.';
            } else {
                // Create a new session ID after successful authentication.
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ];

                // Upgrade older password hashes when necessary.
                if (
                    password_needs_rehash(
                        $user['password_hash'],
                        PASSWORD_DEFAULT
                    )
                ) {
                    $newHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare(
                        'UPDATE users
                         SET password_hash = :hash
                         WHERE id = :id'
                    );

                    $update->execute([
                        'hash' => $newHash,
                        'id' => $user['id'],
                    ]);
                }

                unset($_SESSION['auth_csrf_token']);

                // Respect the existing safe redirect helper.
                authRedirectAfterLogin();
            }
        } catch (PDOException $e) {
            error_log('Ticketa login error: ' . $e->getMessage());
            $error = 'We could not sign you in right now. Please try again.';
        }
    }
}

$pageTitle = 'Login | TICKETA';
$pageStyles = [];
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <section class="auth-card">
        <h1>Welcome back</h1>
        <p>Sign in to your Ticketa account.</p>

        <?php if ($error !== ''): ?>
            <p class="auth-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>

            <?php if (
                strpos($error, 'verify your email') !== false
            ): ?>
                <p>
                    <a href="resend-verification.php">
                        Resend verification email
                    </a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    authCsrfToken(),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <label for="email">Email address</label>
            <input
                id="email"
                name="email"
                type="email"
                autocomplete="email"
                maxlength="190"
                required
                value="<?= htmlspecialchars(
                    $email,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Sign in</button>
        </form>

        <p>New to Ticketa? <a href="register.php">Create an account</a></p>
    </section>
</main>

<style>
.auth-page{max-width:480px;margin:60px auto;padding:20px}
.auth-card{padding:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}
.auth-card h1{margin-top:0}
.auth-card form{display:grid;gap:10px}
.auth-card label{font-weight:600;margin-top:8px}
.auth-card input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d1d5db;border-radius:8px;font:inherit}
.auth-card button{padding:13px;border:0;border-radius:8px;background:#10c6a4;color:#102a26;font-weight:700;cursor:pointer;margin-top:12px}
.auth-error{padding:10px;background:#fff0f0;color:#9b1c1c;border-radius:8px}
</style>
</body>
</html>
```