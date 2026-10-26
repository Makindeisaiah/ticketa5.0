<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

session_start();

$error = '';
$success = '';

/*
 * Temporary setup protection:
 * Set this to true only while creating your first staff account.
 * Set it to false after creating the account.
 */
$setupEnabled = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupEnabled) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');

    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid name and email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Your password must contain at least 10 characters.';
    } else {
        try {
            $check = $pdo->prepare(
                'SELECT id FROM staff_users WHERE email = :email LIMIT 1'
            );
            $check->execute(['email' => $email]);

            if ($check->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $pdo->prepare(
                    "INSERT INTO staff_users
                    (full_name, email, password_hash, phone, role, status)
                    VALUES
                    (:full_name, :email, :password_hash, :phone,
                     'checkin_staff', 'active')"
                );

                $insert->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $hash,
                    'phone' => $phone !== '' ? $phone : null,
                ]);

                $success = 'Staff account created successfully.';
            }
        } catch (PDOException $e) {
            error_log('Ticketa staff setup error: ' . $e->getMessage());
            $error = 'Unable to create the account. Please check the server logs.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Staff Account | Ticketa</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f5f7f8;
            color: #172321;
            font-family: Arial, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 440px;
            padding: 32px;
            background: #fff;
            border: 1px solid #e5e9e8;
            border-radius: 16px;
            box-shadow: 0 12px 35px #1723210d;
        }
        .brand { color: #12a98f; font-size: 25px; font-weight: 800; }
        h1 { margin: 22px 0 8px; font-size: 25px; }
        p { color: #65716f; line-height: 1.5; }
        label { display: block; margin: 17px 0 7px; font-weight: 600; }
        input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #d8dfdd;
            border-radius: 8px;
            font: inherit;
        }
        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #12a98f;
            color: white;
            font-weight: 700;
            cursor: pointer;
        }
        .message { padding: 12px; border-radius: 8px; }
        .error { color: #a51d2d; background: #fff0f1; }
        .success { color: #08775f; background: #eafaf5; }
        .notice { margin-top: 18px; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">ticketa.</div>
    <h1>Create staff account</h1>
    <p>Set up an account for your event check-in team.</p>

    <?php if ($error !== ''): ?>
        <p class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($setupEnabled): ?>
    <form method="post">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" required maxlength="150">

        <label for="email">Email address</label>
        <input id="email" name="email" type="email" required maxlength="190">

        <label for="phone">Phone (optional)</label>
        <input id="phone" name="phone" type="tel" maxlength="30">

        <label for="password">Password</label>
        <input id="password" name="password" type="password"
               minlength="10" required autocomplete="new-password">

        <button type="submit">Create staff account</button>
    </form>
    <p class="notice">
        Security: after creating your account, disable setup and remove
        or restrict this page.
    </p>
    <?php else: ?>
        <p>Staff account setup is disabled.</p>
    <?php endif; ?>
</div>
</body>
</html>
