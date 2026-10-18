<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/mailer.php';

if (authIsLoggedIn()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';
$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!authVerifyCsrf()) {
        $errors[] = 'Your session expired. Please refresh and try again.';
    }

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        $errors[] = 'Enter your full name (maximum 150 characters).';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($password) < 10) {
        $errors[] = 'Your password must contain at least 10 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if (!$errors) {
        try {
            $check = $pdo->prepare(
                'SELECT id FROM users WHERE email = :email LIMIT 1'
            );
            $check->execute(['email' => $email]);

            if ($check->fetch()) {
                $errors[] =
                    'An account with this email already exists. Please sign in.';
            } else {
                $pdo->beginTransaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $pdo->prepare(
                    'INSERT INTO users
                        (full_name, email, password_hash, role, email_verified_at)
                     VALUES
                        (:full_name, :email, :password_hash, :role, NULL)'
                );

                $insert->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $hash,
                    'role' => 'attendee',
                ]);

                $userId = (int) $pdo->lastInsertId();

                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 1800);

                $tokenInsert = $pdo->prepare(
                    'INSERT INTO email_verification_tokens
                        (user_id, token_hash, expires_at)
                     VALUES (:user_id, :token_hash, :expires_at)'
                );

                $tokenInsert->execute([
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                ]);

                $pdo->commit();

                $mailConfig = require __DIR__ . '/config/mail.php';
                $verificationUrl = rtrim($mailConfig['base_url'], '/')
                    . '/verify-email.php?token=' . urlencode($rawToken);

                try {
                    sendVerificationEmail(
                        $email,
                        $fullName,
                        $verificationUrl
                    );

                    $success =
                        'Your account has been created. Check your email '
                        . 'for a verification link. It expires in 30 minutes.';
                    $fullName = '';
                    $email = '';
                } catch (Throwable $mailError) {
                    error_log(
                        'Ticketa verification email error: '
                        . $mailError->getMessage()
                    );

                    $errors[] =
                        'Your account was created, but we could not send '
                        . 'the verification email. Please use the resend '
                        . 'verification page or try again later.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {
                $errors[] =
                    'An account with this email may already exist. Please sign in.';
            } else {
                error_log('Ticketa registration error: ' . $e->getMessage());
                $errors[] = 'We could not create your account. Please try again.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Ticketa registration error: ' . $e->getMessage());
            $errors[] = 'We could not create your account. Please try again.';
        }
    }
}

$pageTitle = 'Create Account | TICKETA';
$pageStyles = [];
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <section class="auth-card">
        <h1>Create your Ticketa account</h1>
        <p>Join Ticketa to continue your event experience.</p>

        <?php if ($success !== ''): ?>
            <p class="auth-success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p><a href="resend-verification.php">Resend verification email</a></p>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <p class="auth-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endforeach; ?>

        <form method="post" action="register.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" type="text"
                   maxlength="150" autocomplete="name" required
                   value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>">

            <label for="email">Email address</label>
            <input id="email" name="email" type="email"
                   maxlength="190" autocomplete="email" required
                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">Password</label>
            <input id="password" name="password" type="password"
                   minlength="10" autocomplete="new-password" required>

            <label for="confirm_password">Confirm password</label>
            <input id="confirm_password" name="confirm_password"
                   type="password" minlength="10"
                   autocomplete="new-password" required>

            <button type="submit">Create account</button>
        </form>

        <p>Already have an account? <a href="login.php">Sign in</a></p>
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
.auth-error,.auth-success{padding:10px;border-radius:8px}
.auth-error{background:#fff0f0;color:#9b1c1c}
.auth-success{background:#e8fff7;color:#065f46}
</style>
</body>
</html>