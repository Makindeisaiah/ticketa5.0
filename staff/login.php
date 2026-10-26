<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function staffLoginEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Redirect staff who are already logged in.
if (!empty($_SESSION['staff_user']['id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$email = '';
$activated = isset($_GET['activated']) && $_GET['activated'] === '1';

if (empty($_SESSION['staff_login_csrf'])) {
    $_SESSION['staff_login_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['staff_login_csrf'];

// Process login.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedCsrf) ||
        !hash_equals($csrfToken, $postedCsrf)
    ) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    }

    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';

    if (is_string($emailInput)) {
        $email = strtolower(trim($emailInput));
    }

    if (!is_string($passwordInput)) {
        $passwordInput = '';
    }

    if (
        $email === '' ||
        strlen($email) > 190 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($passwordInput === '' || strlen($passwordInput) > 4096) {
        $errors[] = 'Enter your password.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    full_name,
                    email,
                    password_hash,
                    role,
                    status
                 FROM staff_users
                 WHERE email = :email
                 LIMIT 1'
            );

            $stmt->execute(['email' => $email]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$staff ||
                !password_verify(
                    $passwordInput,
                    $staff['password_hash']
                )
            ) {
                $errors[] = 'Invalid email or password.';
            } elseif ($staff['status'] !== 'active') {
                $errors[] = 'Your staff account is inactive or suspended. Contact your organizer.';
            } else {
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException(
                        'Could not secure the login session.'
                    );
                }

                $_SESSION['staff_user'] = [
                    'id' => (int) $staff['id'],
                    'full_name' => $staff['full_name'],
                    'email' => $staff['email'],
                    'role' => $staff['role']
                ];

                $_SESSION['staff_login_csrf'] = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare(
                    'UPDATE staff_users
                     SET last_login_at = NOW()
                     WHERE id = :id'
                );

                $stmt->execute([
                    'id' => (int) $staff['id']
                ]);

                header('Location: dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            unset($_SESSION['staff_user']);

            error_log(
                'Ticketa staff login error: ' . $e->getMessage()
            );

            $errors[] = 'We could not sign you in right now. Please try again.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Staff Check-In Access | TICKETA</title>

    <style>
        :root {
            --teal: #25bda5;
            --teal-light: #a9dcd5;
            --teal-hover: #18aa94;
            --text: #242424;
            --muted: #777777;
            --border: #dedede;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--white);
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                         "Segoe UI", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* Brand */
        .site-header {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            padding: 52px 5.2%;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            color: #111111;
            text-decoration: none;
        }

        .brand-mark {
            display: flex;
            width: 60px;
            height: 47px;
            align-items: center;
            justify-content: center;
            border: 5px solid var(--teal);
            border-radius: 7px;
            color: var(--teal);
            font-size: 37px;
            font-weight: 500;
            line-height: 1;
            position: relative;
        }

        .brand-mark::before,
        .brand-mark::after {
            content: "";
            position: absolute;
            width: 8px;
            height: 13px;
            background: var(--white);
            border-top: 3px solid var(--teal);
            border-bottom: 3px solid var(--teal);
            left: -5px;
        }

        .brand-mark::before {
            top: 5px;
        }

        .brand-mark::after {
            bottom: 5px;
        }

        .brand-name {
            font-size: 36px;
            font-weight: 500;
            letter-spacing: -1.5px;
        }

        /* Main login area */
        .login-main {
            width: 100%;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 255px 24px 70px;
        }

        .login-content {
            width: 100%;
            max-width: 603px;
        }

        .login-heading {
            margin: 0;
            text-align: center;
            font-size: 36px;
            font-weight: 500;
            line-height: 1.3;
            letter-spacing: -1px;
        }

        .login-subtitle {
            margin: 20px 0 49px;
            color: var(--muted);
            text-align: center;
            font-size: 19px;
            font-weight: 400;
            line-height: 1.6;
        }

        /* Notifications */
        .notice {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 9px;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .notice.error {
            color: #a32929;
            background: #fff0f0;
            border: 1px solid #f5d2d2;
        }

        .notice.success {
            color: #176a43;
            background: #e9f9f0;
            border: 1px solid #c9ecd8;
        }

        /* Inputs */
        .field {
            position: relative;
            margin-bottom: 40px;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 23px;
            width: 27px;
            height: 27px;
            color: var(--teal);
            transform: translateY(-50%);
            pointer-events: none;
        }

        .field input {
            display: block;
            width: 100%;
            height: 73px;
            padding: 0 65px 0 70px;
            border: 1.5px solid var(--border);
            border-radius: 11px;
            outline: none;
            background: var(--white);
            color: var(--text);
            font: inherit;
            font-size: 16px;
            transition: border-color .2s, box-shadow .2s;
        }

        .field input::placeholder {
            color: #777777;
            opacity: 1;
        }

        .field input:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(37, 189, 165, .10);
        }

        .password-field {
            margin-bottom: 72px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 21px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 35px;
            padding: 0;
            border: 0;
            background: transparent;
            color: #999999;
            cursor: pointer;
            transform: translateY(-50%);
        }

        .toggle-password svg {
            width: 23px;
            height: 23px;
        }

        /* Continue button */
        .submit-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 63px;
            margin: 0;
            padding: 14px 20px;
            border: 0;
            border-radius: 16px;
            background: var(--teal-light);
            color: #ffffff;
            font: inherit;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background .2s ease;
        }

        .submit-button:hover {
            background: var(--teal-hover);
        }

        .submit-button:focus-visible,
        .toggle-password:focus-visible,
        .help-link:focus-visible {
            outline: 3px solid rgba(37, 189, 165, .4);
            outline-offset: 4px;
        }

        /* Help text */
        .help-text {
            margin: 49px 0 0;
            text-align: center;
            font-size: 17px;
            line-height: 1.7;
        }

        .help-link {
            color: var(--teal);
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        /* Responsive layout */
        @media (max-width: 768px) {
            .site-header {
                padding: 28px 24px;
            }

            .brand {
                gap: 10px;
            }

            .brand-mark {
                width: 47px;
                height: 38px;
                border-width: 4px;
                font-size: 29px;
            }

            .brand-name {
                font-size: 29px;
                letter-spacing: -1px;
            }

            .login-main {
                padding-top: 190px;
            }

            .login-heading {
                font-size: 30px;
            }

            .login-subtitle {
                margin: 15px 0 38px;
                font-size: 16px;
            }

            .field {
                margin-bottom: 26px;
            }

            .field input {
                height: 64px;
                padding-left: 60px;
                font-size: 15px;
            }

            .input-icon {
                left: 19px;
                width: 24px;
                height: 24px;
            }

            .password-field {
                margin-bottom: 48px;
            }

            .submit-button {
                min-height: 58px;
                border-radius: 12px;
            }

            .help-text {
                margin-top: 34px;
                font-size: 14px;
            }
        }

        @media (max-width: 420px) {
            .site-header {
                padding: 24px 20px;
            }

            .login-main {
                padding: 160px 20px 45px;
            }

            .login-heading {
                font-size: 27px;
            }

            .login-subtitle {
                margin-bottom: 32px;
                font-size: 14px;
            }

            .field input {
                height: 59px;
            }

            .password-field {
                margin-bottom: 42px;
            }

            .help-text {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>

<header class="site-header">
    <a class="brand" href="../index.php" aria-label="TICKETA home">
        <span class="brand-mark" aria-hidden="true">T</span>
        <span class="brand-name">TICKETA</span>
    </a>
</header>

<main class="login-main">
    <section class="login-content" aria-labelledby="loginHeading">

        <h1 class="login-heading" id="loginHeading">
            Staff Check-In Access
        </h1>

        <p class="login-subtitle">
            Login to manage attendee entry and ticket validation
        </p>

        <?php if ($activated): ?>
            <div class="notice success" role="status">
                Your staff account has been activated successfully.
                You can now sign in with your email and password.
            </div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="notice error" role="alert">
                <?= staffLoginEscape($error) ?>
            </div>
        <?php endforeach; ?>

        <form method="post" action="login.php" autocomplete="on">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= staffLoginEscape($csrfToken) ?>"
            >

            <div class="field">
                <label for="email" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">
                    Email Address
                </label>

                <svg class="input-icon" viewBox="0 0 24 24"
                     fill="none" aria-hidden="true">
                    <rect x="2" y="4" width="20" height="16" rx="2"
                          fill="currentColor"/>
                    <path d="M3 6L12 13L21 6"
                          stroke="#ffffff" stroke-width="2"
                          stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= staffLoginEscape($email) ?>"
                    placeholder="Email Address"
                    maxlength="190"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <div class="field password-field">
                <label for="password" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">
                    Password
                </label>

                <svg class="input-icon" viewBox="0 0 24 24"
                     fill="none" aria-hidden="true">
                    <rect x="4" y="10" width="16" height="12" rx="2"
                          fill="currentColor"/>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"
                          stroke="currentColor" stroke-width="2.5"
                          stroke-linecap="round"/>
                    <circle cx="12" cy="15" r="1.5" fill="#ffffff"/>
                    <path d="M12 16.5V18" stroke="#ffffff"
                          stroke-width="1.5" stroke-linecap="round"/>
                </svg>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    maxlength="4096"
                    autocomplete="current-password"
                    required
                >

                <button
                    type="button"
                    class="toggle-password"
                    id="togglePassword"
                    aria-label="Show password"
                    aria-pressed="false"
                >
                    <svg id="eyeIcon" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 3L21 21"/>
                        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                        <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5
                                 c5 0 8.3 4.1 9.5 6
                                 a12.8 12.8 0 0 1-3.1 3.5"/>
                        <path d="M6.6 6.6A13.5 13.5 0 0 0 2.5 12
                                 c1.2 1.9 4.5 6 9.5 6
                                 1.3 0 2.5-.3 3.5-.8"/>
                    </svg>
                </button>
            </div>

            <button type="submit" class="submit-button">
                Continue
            </button>

        </form>

        <p class="help-text">
            Having trouble accessing your account?
            <a class="help-link" href="../index.php">
                Contact organizer admin.
            </a>
        </p>

    </section>
</main>

<script>
(function () {
    const password = document.getElementById('password');
    const toggle = document.getElementById('togglePassword');
    const eyeIcon = document.getElementById('eyeIcon');

    const eyeOpen = `
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
        <circle cx="12" cy="12" r="3"/>
    `;

    const eyeClosed = `
        <path d="M3 3L21 21"/>
        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
        <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5
                 c5 0 8.3 4.1 9.5 6
                 a12.8 12.8 0 0 1-3.1 3.5"/>
        <path d="M6.6 6.6A13.5 13.5 0 0 0 2.5 12
                 c1.2 1.9 4.5 6 9.5 6
                 1.3 0 2.5-.3 3.5-.8"/>
    `;

    toggle.addEventListener('click', function () {
        const showPassword = password.type === 'password';

        password.type = showPassword ? 'text' : 'password';
        eyeIcon.innerHTML = showPassword ? eyeOpen : eyeClosed;

        toggle.setAttribute(
            'aria-label',
            showPassword ? 'Hide password' : 'Show password'
        );

        toggle.setAttribute(
            'aria-pressed',
            showPassword ? 'true' : 'false'
        );
    });
})();
</script>

</body>
</html>
