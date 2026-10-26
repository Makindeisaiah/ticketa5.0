
<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/session_registry.php';

if (authIsLoggedIn()) {
    $role = $_SESSION['user']['role'] ?? '';

    if ($role === 'organizer') {
        header('Location: dashboard.php');
        exit;
    }

    if ($role === 'attendee') {
        header('Location: ../index.php');
        exit;
    }

    http_response_code(403);
    exit('This account cannot access the organizer portal.');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!authVerifyCsrf()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Enter your password.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, password_hash, role,
                        email_verified_at, is_active
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid email address or password.';
            } elseif ((int) $user['is_active'] !== 1) {
                $errors[] = 'This account is inactive. Please contact support.';
            } elseif ($user['role'] !== 'organizer') {
                $errors[] = 'This account is not an organizer account. Please use the correct sign-in page.';
            } elseif (empty($user['email_verified_at'])) {
                $errors[] = 'Please verify your email before signing in. Check your inbox for the verification link.';
                $_SESSION['organizer_unverified_email'] = $email;
            } else {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $updateHash = $pdo->prepare(
                        'UPDATE users
                         SET password_hash = :password_hash
                         WHERE id = :id'
                    );

                    $updateHash->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $user['id'],
                    ]);
                }

                $twoFactorStmt = $pdo->prepare(
                    'SELECT secret_encrypted, is_enabled
                     FROM user_two_factor
                     WHERE user_id = :user_id
                     LIMIT 1'
                );

                $twoFactorStmt->execute([
                    'user_id' => (int) $user['id'],
                ]);

                $twoFactor = $twoFactorStmt->fetch(PDO::FETCH_ASSOC);

                if ($twoFactor && (int) $twoFactor['is_enabled'] === 1) {
                    if (empty($twoFactor['secret_encrypted'])) {
                        throw new RuntimeException(
                            'Enabled 2FA account has no stored secret.'
                        );
                    }

                    session_regenerate_id(true);

                    $_SESSION['pending_organizer_2fa'] = [
                        'id' => (int) $user['id'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => 'organizer',
                        'created_at' => time(),
                    ];

                    unset($_SESSION['user']);
                    unset($_SESSION['auth_csrf_token']);

                    header('Location: verify-2fa.php');
                    exit;
                }

                session_regenerate_id(true);

                ticketaRegisterCurrentSession(
                    $pdo,
                    (int) $user['id']
                );

                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ];

                unset($_SESSION['organizer_unverified_email']);
                unset($_SESSION['auth_csrf_token']);

                header('Location: dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Ticketa organizer login error: ' . $e->getMessage());
            $errors[] = 'We could not sign you in right now. Please try again.';
        }
    }
}

function organizerLoginEscape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$csrfToken = authCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Organizer Login | TICKETA</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f5f8f7;
            color: #171c2e;
            font-family: Inter, Arial, sans-serif;
        }

        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }

        .brand {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .brand-mark {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border: 2px solid #10bea0;
            border-radius: 9px;
            color: #087f6b;
            font-size: 21px;
        }

        .login-card {
            padding: 36px;
            border: 1px solid #e5e8e9;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 18px 50px rgba(23, 28, 46, 0.06);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
            letter-spacing: -0.8px;
        }

        .subtitle {
            margin: 0 0 26px;
            color: #777d84;
            font-size: 14px;
            line-height: 1.6;
        }

        .error-list {
            margin-bottom: 20px;
            padding: 14px 16px;
            border: 1px solid #ffdada;
            border-radius: 9px;
            background: #fff2f2;
            color: #a32929;
            font-size: 14px;
            line-height: 1.6;
        }

        .error-list ul {
            margin: 0;
            padding-left: 20px;
        }

        .field {
            margin-bottom: 20px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .field input {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            border: 1px solid #dce3e1;
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: #171c2e;
            font: inherit;
            font-size: 15px;
        }

        .field input:focus {
            border-color: #10bea0;
            box-shadow: 0 0 0 3px rgba(16, 190, 160, 0.12);
        }

        .submit-button {
            width: 100%;
            min-height: 49px;
            margin-top: 6px;
            border: 0;
            border-radius: 9px;
            background: #10bea0;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .submit-button:hover {
            background: #08a98d;
        }

        .footer-text {
            margin: 24px 0 0;
            color: #777d84;
            font-size: 13px;
            line-height: 1.7;
            text-align: center;
        }

        .footer-text a {
            color: #087f6b;
            font-weight: 600;
            text-decoration: none;
        }

        .footer-text a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            body {
                padding: 16px;
            }

            .login-card {
                padding: 26px 20px;
            }

            h1 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>
    <main class="login-wrapper">

        <div class="brand">
            <span class="brand-mark">T</span>
            <span>TICKETA</span>
        </div>

        <section class="login-card">
            <h1>Welcome back</h1>

            <p class="subtitle">
                Sign in to manage your events, tickets, and organizer dashboard.
            </p>

            <?php if ($errors): ?>
                <div class="error-list" role="alert">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= organizerLoginEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" autocomplete="on">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= organizerLoginEscape($csrfToken) ?>"
                >

                <div class="field">
                    <label for="email">Email address</label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="190"
                        value="<?= organizerLoginEscape($email) ?>"
                        placeholder="you@example.com"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>

                <div class="field">
                    <label for="password">Password</label>

                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button class="submit-button" type="submit">
                    Sign In
                </button>
            </form>

            <p class="footer-text">
                Are you an attendee?
                <a href="./register.php">Signup in here</a>
            </p>
        </section>

    </main>
</body>
</html>
