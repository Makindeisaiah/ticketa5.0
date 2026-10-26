
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

if (authIsLoggedIn()) {
    $role = $_SESSION['user']['role'] ?? 'attendee';

    if ($role === 'organizer') {
        header('Location: dashboard.php');
    } else {
        header('Location: ../index.php');
    }
    exit;
}

$errors = [];
$success = '';

$fullName = '';
$email = '';
$businessName = '';
$organizerType = '';
$country = "Côte d'Ivoire";
$phone = '';
$description = '';

$allowedTypes = [
    'Individual',
    'Company',
    'Event Agency',
    'Nonprofit',
    'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $businessName = trim((string) ($_POST['business_name'] ?? ''));
    $organizerType = trim((string) ($_POST['organizer_type'] ?? ''));
    $country = trim((string) ($_POST['country'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (!authVerifyCsrf()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
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

    if ($businessName === '' || mb_strlen($businessName) > 180) {
        $errors[] = 'Enter your organization name (maximum 180 characters).';
    }

    if (!in_array($organizerType, $allowedTypes, true)) {
        $errors[] = 'Choose a valid organizer type.';
    }

    if ($country === '' || mb_strlen($country) > 100) {
        $errors[] = 'Enter your country (maximum 100 characters).';
    }

    if (mb_strlen($phone) > 30) {
        $errors[] = 'Your phone number must not exceed 30 characters.';
    }

    if (mb_strlen($description) > 5000) {
        $errors[] = 'Your organization description is too long.';
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

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertUser = $pdo->prepare(
                    'INSERT INTO users
                        (full_name, email, phone, password_hash, role,
                         email_verified_at, is_active)
                     VALUES
                        (:full_name, :email, :phone, :password_hash,
                         :role, NULL, 1)'
                );

                $insertUser->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'password_hash' => $passwordHash,
                    'role' => 'organizer',
                ]);

                $userId = (int) $pdo->lastInsertId();

                $insertOrganizer = $pdo->prepare(
                    'INSERT INTO organizers
                        (user_id, business_name, organizer_type,
                         contact_name, email, phone, country, description)
                     VALUES
                        (:user_id, :business_name, :organizer_type,
                         :contact_name, :email, :phone, :country, :description)'
                );

                $insertOrganizer->execute([
                    'user_id' => $userId,
                    'business_name' => $businessName,
                    'organizer_type' => $organizerType,
                    'contact_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'country' => $country,
                    'description' => $description !== '' ? $description : null,
                ]);

                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 1800);

                $insertToken = $pdo->prepare(
                    'INSERT INTO email_verification_tokens
                        (user_id, token_hash, expires_at)
                     VALUES (:user_id, :token_hash, :expires_at)'
                );

                $insertToken->execute([
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                ]);

                $pdo->commit();

                $mailConfig = require dirname(__DIR__) . '/config/mail.php';

                $verificationUrl = rtrim(
                    $mailConfig['base_url'],
                    '/'
                ) . '/verify-email.php?token=' . urlencode($rawToken);

                try {
                    sendVerificationEmail(
                        $email,
                        $fullName,
                        $verificationUrl
                    );

                    $success =
                        'Your organizer account has been created. '
                        . 'Check your email for the verification link. '
                        . 'It expires in 30 minutes.';

                    $fullName = '';
                    $email = '';
                    $businessName = '';
                    $organizerType = '';
                    $country = "Côte d'Ivoire";
                    $phone = '';
                    $description = '';
                } catch (Throwable $mailError) {
                    error_log(
                        'Ticketa organizer verification email error: '
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
                error_log(
                    'Ticketa organizer registration error: ' . $e->getMessage()
                );
                $errors[] =
                    'We could not create your organizer account. Please try again.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Ticketa organizer registration error: ' . $e->getMessage()
            );
            $errors[] =
                'We could not create your organizer account. Please try again.';
        }
    }
}

function organizerEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Organizer Account | TICKETA</title>

    <style>
        :root {
            --primary: #10c6a4;
            --primary-dark: #087f6b;
            --ink: #152b28;
            --muted: #71817e;
            --border: #e5ebe9;
            --surface: #f7faf9;
            --danger: #a52828;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--surface);
            color: var(--ink);
            font-family: Inter, Arial, sans-serif;
        }

        a {
            color: var(--primary-dark);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        /* Sticky navigation bar */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            width: 100%;
            padding: 20px 6%;
            background: rgba(255, 255, 255, 0.97);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 4px 18px rgba(25, 60, 51, 0.05);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .brand {
            flex-shrink: 0;
            color: var(--ink);
            font-size: 25px;
            font-weight: 850;
            letter-spacing: -1px;
            text-decoration: none;
        }

        .brand:hover {
            text-decoration: none;
        }

        .brand span {
            color: var(--primary-dark);
        }

        .topbar p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .topbar p a {
            font-weight: 700;
        }

        .page {
            width: min(760px, calc(100% - 32px));
            margin: 48px auto;
        }

        .intro {
            margin-bottom: 28px;
            text-align: center;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 12px;
            padding: 7px 12px;
            border-radius: 100px;
            background: #e3faf3;
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(28px, 5vw, 40px);
            letter-spacing: -1.3px;
        }

        .intro p {
            max-width: 520px;
            margin: auto;
            color: var(--muted);
            line-height: 1.7;
        }

        .card {
            padding: clamp(22px, 5vw, 38px);
            border: 1px solid var(--border);
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 15px 45px rgba(25, 60, 51, 0.05);
        }

        .section-heading {
            margin: 0 0 6px;
            font-size: 19px;
        }

        .section-note {
            margin: 0 0 24px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 19px;
        }

        .field {
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        input,
        select,
        textarea {
            display: block;
            width: 100%;
            min-height: 48px;
            padding: 12px 13px;
            border: 1px solid #dce5e2;
            border-radius: 10px;
            outline: none;
            background: #fff;
            color: var(--ink);
            font: inherit;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        textarea {
            min-height: 105px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16, 198, 164, 0.13);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 72px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .hint {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .separator {
            height: 1px;
            margin: 29px 0;
            background: var(--border);
        }

        .submit-button {
            display: block;
            width: 100%;
            min-height: 52px;
            margin-top: 27px;
            border: 0;
            border-radius: 11px;
            background: var(--primary);
            color: #102a26;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .submit-button:hover {
            background: #22d7b5;
            transform: translateY(-1px);
        }

        .message {
            margin-bottom: 22px;
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.6;
        }

        .message.error {
            border: 1px solid #f2cccc;
            background: #fff2f2;
            color: var(--danger);
        }

        .message.success {
            border: 1px solid #bcebdc;
            background: #eafff7;
            color: #075e49;
        }

        .signin {
            margin: 24px 0 0;
            color: var(--muted);
            text-align: center;
            font-size: 14px;
            line-height: 1.7;
        }

        .legal {
            margin: 20px auto 0;
            color: var(--muted);
            text-align: center;
            font-size: 12px;
            line-height: 1.7;
        }

        @media (max-width: 560px) {
            .topbar {
                position: sticky;
                top: 0;
                z-index: 1000;
                padding: 16px 20px;
                gap: 12px;
            }

            .brand {
                font-size: 22px;
            }

            .topbar p {
                max-width: 155px;
                text-align: right;
                font-size: 12px;
                line-height: 1.5;
            }

            .page {
                margin: 32px auto;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 17px;
            }

            .field.full {
                grid-column: auto;
            }

            .card {
                border-radius: 16px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
    <header class="topbar">
        <a class="brand" href="../index.php">TICKETA<span>.</span></a>

        <p>
            Already have an account?
            <a href="login.php">Sign in</a>
        </p>
    </header>

    <main class="page">
        <div class="intro">
            <span class="eyebrow">Organizer registration</span>

            <h1>Create your organizer account</h1>

            <p>
                Set up your profile to prepare for publishing events
                and managing your ticket sales on Ticketa.
            </p>
        </div>

        <section class="card">
            <?php if ($success !== ''): ?>
                <div class="message success" role="status">
                    <?= organizerEscape($success) ?>

                    <br><br>

                    <a href="../resend-verification.php">
                        Request another verification email
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="message error" role="alert">
                    <strong>Please check the following:</strong>

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= organizerEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success === ''): ?>
                <form method="post" action="register.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= organizerEscape(authCsrfToken()) ?>"
                    >

                    <h2 class="section-heading">Your account</h2>

                    <p class="section-note">
                        Enter the personal details you will use to manage
                        your organizer account.
                    </p>

                    <div class="form-grid">
                        <div class="field full">
                            <label for="full_name">Full name *</label>

                            <input
                                id="full_name"
                                name="full_name"
                                type="text"
                                maxlength="150"
                                autocomplete="name"
                                required
                                value="<?= organizerEscape($fullName) ?>"
                            >
                        </div>

                        <div class="field full">
                            <label for="email">Email address *</label>

                            <input
                                id="email"
                                name="email"
                                type="email"
                                maxlength="190"
                                autocomplete="email"
                                required
                                value="<?= organizerEscape($email) ?>"
                            >

                            <span class="hint">
                                Use an email address you can access to verify
                                your account.
                            </span>
                        </div>

                        <div class="field">
                            <label for="password">Password *</label>

                            <div class="password-wrap">
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    minlength="10"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    class="toggle-password"
                                    type="button"
                                    data-target="password"
                                >Show</button>
                            </div>

                            <span class="hint">
                                At least 10 characters.
                            </span>
                        </div>

                        <div class="field">
                            <label for="confirm_password">
                                Confirm password *
                            </label>

                            <div class="password-wrap">
                                <input
                                    id="confirm_password"
                                    name="confirm_password"
                                    type="password"
                                    minlength="10"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    class="toggle-password"
                                    type="button"
                                    data-target="confirm_password"
                                >Show</button>
                            </div>
                        </div>
                    </div>

                    <div class="separator"></div>

                    <h2 class="section-heading">Organization details</h2>

                    <p class="section-note">
                        Tell us about the business or organization that
                        will host events on Ticketa.
                    </p>

                    <div class="form-grid">
                        <div class="field full">
                            <label for="business_name">
                                Organization or business name *
                            </label>

                            <input
                                id="business_name"
                                name="business_name"
                                type="text"
                                maxlength="180"
                                autocomplete="organization"
                                required
                                value="<?= organizerEscape($businessName) ?>"
                            >
                        </div>

                        <div class="field">
                            <label for="organizer_type">
                                Organizer type *
                            </label>

                            <select
                                id="organizer_type"
                                name="organizer_type"
                                required
                            >
                                <option value="">Choose a type</option>

                                <?php foreach ($allowedTypes as $type): ?>
                                    <option
                                        value="<?= organizerEscape($type) ?>"
                                        <?= $organizerType === $type ? 'selected' : '' ?>
                                    >
                                        <?= organizerEscape($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="country">Country *</label>

                            <input
                                id="country"
                                name="country"
                                type="text"
                                maxlength="100"
                                autocomplete="country-name"
                                required
                                value="<?= organizerEscape($country) ?>"
                            >
                        </div>

                        <div class="field full">
                            <label for="phone">Phone number</label>

                            <input
                                id="phone"
                                name="phone"
                                type="tel"
                                maxlength="30"
                                autocomplete="tel"
                                value="<?= organizerEscape($phone) ?>"
                                placeholder="+225 ..."
                            >
                        </div>

                        <div class="field full">
                            <label for="description">
                                About your organization (optional)
                            </label>

                            <textarea
                                id="description"
                                name="description"
                                maxlength="5000"
                                placeholder="Tell us briefly about your organization."
                            ><?= organizerEscape($description) ?></textarea>
                        </div>
                    </div>

                    <button class="submit-button" type="submit">
                        Create organizer account
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <p class="signin">
            By creating an account, you agree to follow Ticketa's
            applicable terms and policies.
        </p>

        <p class="legal">
            Your email must be verified before you can sign in.
        </p>
    </main>

    <script>
        document.querySelectorAll('.toggle-password').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.target);
                const showing = input.type === 'text';

                input.type = showing ? 'password' : 'text';
                button.textContent = showing ? 'Show' : 'Hide';
            });
        });
    </script>
</body>
</html>
