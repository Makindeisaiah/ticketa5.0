<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function staffActivationEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function staffActivationRedirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['staff_activation_csrf'])) {
    $_SESSION['staff_activation_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['staff_activation_csrf'];

$errors = [];
$success = '';
$invitation = null;
$token = '';

/*
|--------------------------------------------------------------------------
| Read invitation token
|--------------------------------------------------------------------------
*/

$submittedToken = $_POST['token'] ?? $_GET['token'] ?? '';

if (is_string($submittedToken) && preg_match('/\A[a-f0-9]{64}\z/', $submittedToken)) {
    $token = $submittedToken;
}

/*
|--------------------------------------------------------------------------
| Validate invitation
|--------------------------------------------------------------------------
*/

try {
    if ($token === '') {
        $errors[] = 'This invitation link is invalid. Please ask your organizer to send a new invitation.';
    } else {
        $tokenHash = hash('sha256', $token);

        $stmt = $pdo->prepare(
            "SELECT
                i.id,
                i.organizer_id,
                i.email,
                i.team_role,
                i.gate_name,
                i.event_id,
                i.shift_start,
                i.shift_end,
                i.status,
                i.expires_at,
                o.business_name,
                e.title AS event_title
             FROM organizer_team_invitations i
             INNER JOIN organizers o
                ON o.id = i.organizer_id
             LEFT JOIN events e
                ON e.id = i.event_id
                AND e.organizer_id = i.organizer_id
             WHERE i.token_hash = :token_hash
             LIMIT 1"
        );

        $stmt->execute(['token_hash' => $tokenHash]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invitation) {
            $errors[] = 'This invitation does not exist or the link is invalid.';
        } elseif ($invitation['team_role'] !== 'checkin_staff') {
            $errors[] = 'This is not a check-in staff invitation.';
        } elseif ($invitation['status'] !== 'pending') {
            $errors[] = 'This invitation has already been used or revoked.';
        } elseif (strtotime($invitation['expires_at']) <= time()) {
            $errors[] = 'This invitation has expired. Please ask your organizer to send a new one.';
        } elseif (
            empty($invitation['event_id']) ||
            empty($invitation['shift_start']) ||
            empty($invitation['shift_end']) ||
            empty($invitation['gate_name']) ||
            empty($invitation['event_title'])
        ) {
            $errors[] = 'This invitation is missing its event or shift details. Please contact your organizer.';
        } elseif (
            strtotime($invitation['shift_end']) <=
            strtotime($invitation['shift_start'])
        ) {
            $errors[] = 'The assigned shift is invalid. Please contact your organizer.';
        }
    }
} catch (Throwable $e) {
    error_log('Ticketa staff invitation lookup failed: ' . $e->getMessage());

    $errors[] = 'We could not verify your invitation right now. Please try again later.';
}

/*
|--------------------------------------------------------------------------
| Process account activation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $postedCsrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedCsrf) ||
        !hash_equals($csrfToken, $postedCsrf)
    ) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!is_string($password) || !is_string($confirmPassword)) {
        $errors[] = 'Enter a valid password.';
        $password = '';
        $confirmPassword = '';
    }

    if (
        $fullName === '' ||
        strlen($fullName) > 150 ||
        preg_match('/[\x00-\x1F\x7F]/', $fullName)
    ) {
        $errors[] = 'Enter your full name using no more than 150 characters.';
    }

    if (strlen($password) < 12) {
        $errors[] = 'Your password must contain at least 12 characters.';
    }

    if (strlen($password) > 4096) {
        $errors[] = 'Your password is too long.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the invitation so it cannot be accepted twice
             * by concurrent requests.
             */
            $stmt = $pdo->prepare(
                "SELECT
                    i.id,
                    i.organizer_id,
                    i.email,
                    i.team_role,
                    i.gate_name,
                    i.event_id,
                    i.shift_start,
                    i.shift_end,
                    i.status,
                    i.expires_at
                 FROM organizer_team_invitations i
                 WHERE i.token_hash = :token_hash
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([
                'token_hash' => hash('sha256', $token)
            ]);

            $lockedInvitation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedInvitation) {
                throw new RuntimeException(
                    'This invitation is invalid.'
                );
            }

            if ($lockedInvitation['team_role'] !== 'checkin_staff') {
                throw new RuntimeException(
                    'This invitation is not for check-in staff.'
                );
            }

            if ($lockedInvitation['status'] !== 'pending') {
                throw new RuntimeException(
                    'This invitation has already been used or revoked.'
                );
            }

            if (strtotime($lockedInvitation['expires_at']) <= time()) {
                throw new RuntimeException(
                    'This invitation has expired. Ask your organizer to send a new one.'
                );
            }

            if (
                empty($lockedInvitation['event_id']) ||
                empty($lockedInvitation['shift_start']) ||
                empty($lockedInvitation['shift_end']) ||
                empty($lockedInvitation['gate_name']) ||
                strtotime($lockedInvitation['shift_end']) <=
                    strtotime($lockedInvitation['shift_start'])
            ) {
                throw new RuntimeException(
                    'The invitation has incomplete or invalid assignment details.'
                );
            }

            /*
             * Verify that the assigned event still belongs to
             * the organization that issued the invitation.
             */
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM events
                 WHERE id = :event_id
                   AND organizer_id = :organizer_id
                 LIMIT 1'
            );

            $stmt->execute([
                'event_id' => $lockedInvitation['event_id'],
                'organizer_id' => $lockedInvitation['organizer_id']
            ]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'The assigned event could not be verified. Contact your organizer.'
                );
            }

            /*
             * Do not allow a duplicate staff account.
             */
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM staff_users
                 WHERE email = :email
                 LIMIT 1'
            );

            $stmt->execute([
                'email' => $lockedInvitation['email']
            ]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'A staff account already exists for this email address. Contact your organizer.'
                );
            }

            /*
             * Create the dedicated staff account.
             */
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'The account password could not be secured.'
                );
            }

            $stmt = $pdo->prepare(
                "INSERT INTO staff_users
                 (
                     full_name,
                     email,
                     password_hash,
                     role,
                     status,
                     email_verified_at
                 )
                 VALUES
                 (
                     :full_name,
                     :email,
                     :password_hash,
                     'checkin_staff',
                     'active',
                     NOW()
                 )"
            );

            $stmt->execute([
                'full_name' => $fullName,
                'email' => $lockedInvitation['email'],
                'password_hash' => $passwordHash
            ]);

            $staffUserId = (int) $pdo->lastInsertId();

            /*
             * Assign the staff member to the invited event,
             * gate, and shift.
             */
            $stmt = $pdo->prepare(
                "INSERT INTO staff_event_assignments
                 (
                     staff_user_id,
                     event_id,
                     assigned_gate,
                     assignment_role,
                     shift_start,
                     shift_end,
                     status
                 )
                 VALUES
                 (
                     :staff_user_id,
                     :event_id,
                     :assigned_gate,
                     'checkin_staff',
                     :shift_start,
                     :shift_end,
                     'scheduled'
                 )"
            );

            $stmt->execute([
                'staff_user_id' => $staffUserId,
                'event_id' => $lockedInvitation['event_id'],
                'assigned_gate' => $lockedInvitation['gate_name'],
                'shift_start' => $lockedInvitation['shift_start'],
                'shift_end' => $lockedInvitation['shift_end']
            ]);

            /*
             * Mark the invitation accepted only after the account
             * and assignment have both been created.
             */
            $stmt = $pdo->prepare(
                "UPDATE organizer_team_invitations
                 SET status = 'accepted',
                     accepted_at = NOW()
                 WHERE id = :id
                   AND status = 'pending'"
            );

            $stmt->execute([
                'id' => $lockedInvitation['id']
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The invitation could not be finalized.'
                );
            }

            $pdo->commit();

            /*
             * Clear activation-specific state and redirect to
             * the dedicated staff login page.
             */
            unset($_SESSION['staff_activation_csrf']);

            staffActivationRedirect(
                'login.php?activated=1'
            );

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                error_log(
                    'Ticketa staff activation failed: ' . $e->getMessage()
                );

                $errors[] = 'Your account could not be activated. Please try again or contact your organizer.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Page metadata
|--------------------------------------------------------------------------
*/

$eventTitle = $invitation['event_title'] ?? '';
$organizerBusinessName = $invitation['business_name'] ?? 'Ticketa';
$invitedEmail = $invitation['email'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Activate Staff Account | TICKETA</title>

    <style>
        :root {
            --navy: #11172b;
            --purple: #7557ff;
            --purple-dark: #6243e8;
            --text: #20243a;
            --muted: #747b8c;
            --border: #e6e8ef;
            --background: #f5f6fb;
            --white: #ffffff;
            --success-bg: #e9f9f0;
            --success-text: #176a43;
            --error-bg: #fff0f0;
            --error-text: #a32929;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--background);
            color: var(--text);
            font-family: Inter, Arial, sans-serif;
        }

        .activation-card {
            width: 100%;
            max-width: 510px;
            padding: 34px;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 15px 50px rgba(17, 23, 43, .07);
        }

        .brand {
            margin-bottom: 26px;
            color: var(--navy);
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand span {
            color: var(--purple);
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--purple);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 10px;
            color: var(--navy);
            font-size: 28px;
            line-height: 1.25;
        }

        .intro {
            margin: 0 0 24px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .event-card {
            margin-bottom: 24px;
            padding: 16px;
            border: 1px solid #e8e4ff;
            border-radius: 12px;
            background: #f8f7ff;
        }

        .event-card strong {
            display: block;
            margin-bottom: 9px;
            color: var(--navy);
            font-size: 16px;
            overflow-wrap: anywhere;
        }

        .event-detail {
            margin-top: 7px;
            color: #5f6578;
            font-size: 13px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .field {
            display: grid;
            gap: 8px;
            margin-bottom: 18px;
        }

        .field label {
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
        }

        .field input {
            width: 100%;
            min-height: 47px;
            padding: 12px 14px;
            border: 1px solid #d9dce6;
            border-radius: 8px;
            outline: none;
            background: #fff;
            color: var(--text);
            font: inherit;
            font-size: 14px;
        }

        .field input:focus {
            border-color: var(--purple);
            box-shadow: 0 0 0 3px rgba(117, 87, 255, .12);
        }

        .field input[readonly] {
            background: #f7f8fb;
            color: #62697a;
        }

        .hint {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 72px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--purple);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .submit-button {
            width: 100%;
            min-height: 49px;
            margin-top: 4px;
            border: 0;
            border-radius: 9px;
            background: var(--purple);
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s ease;
        }

        .submit-button:hover {
            background: var(--purple-dark);
        }

        .notice {
            margin-bottom: 18px;
            padding: 13px 15px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .notice.error {
            background: var(--error-bg);
            color: var(--error-text);
        }

        .notice.success {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .footer {
            margin-top: 22px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            text-align: center;
        }

        .footer a {
            color: var(--purple);
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 520px) {
            body {
                padding: 14px;
                align-items: flex-start;
            }

            .activation-card {
                margin: 20px 0;
                padding: 24px 19px;
                border-radius: 13px;
            }

            h1 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>

<div class="activation-card">

    <div class="brand">TICKETA<span>.</span></div>

    <?php if ($errors): ?>

        <p class="eyebrow">Staff account</p>
        <h1>Invitation unavailable</h1>

        <?php foreach ($errors as $error): ?>
            <div class="notice error">
                <?= staffActivationEscape($error) ?>
            </div>
        <?php endforeach; ?>

        <p class="footer">
            Need another invitation?
            Contact the organizer who invited you.
        </p>

    <?php elseif ($invitation): ?>

        <p class="eyebrow">Staff account setup</p>
        <h1>Activate your account</h1>

        <p class="intro">
            You've been invited by
            <strong><?= staffActivationEscape($organizerBusinessName) ?></strong>
            to join the event team. Create your staff login below.
        </p>

        <div class="event-card">
            <strong><?= staffActivationEscape($eventTitle) ?></strong>

            <div class="event-detail">
                <b>Assigned gate:</b>
                <?= staffActivationEscape($invitation['gate_name']) ?>
            </div>

            <div class="event-detail">
                <b>Shift starts:</b>
                <?= staffActivationEscape(
                    date('M j, Y \a\t H:i', strtotime($invitation['shift_start']))
                ) ?>
            </div>

            <div class="event-detail">
                <b>Shift ends:</b>
                <?= staffActivationEscape(
                    date('M j, Y \a\t H:i', strtotime($invitation['shift_end']))
                ) ?>
            </div>

            <div class="event-detail">
                <b>Invitation expires:</b>
                <?= staffActivationEscape(
                    date('M j, Y \a\t H:i', strtotime($invitation['expires_at']))
                ) ?>
            </div>
        </div>

        <form method="post" action="activate-account.php" autocomplete="on">

            <input type="hidden"
                   name="token"
                   value="<?= staffActivationEscape($token) ?>">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= staffActivationEscape($csrfToken) ?>">

            <div class="field">
                <label for="full_name">Full name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="150"
                    autocomplete="name"
                    placeholder="Enter your full name"
                    value="<?= staffActivationEscape($_POST['full_name'] ?? '') ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Invited email address</label>
                <input
                    type="email"
                    id="email"
                    value="<?= staffActivationEscape($invitedEmail) ?>"
                    readonly
                    aria-readonly="true"
                >
                <span class="hint">
                    Your account will be created with this invited email address.
                </span>
            </div>

            <div class="field">
                <label for="password">Create password</label>

                <div class="password-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="12"
                        maxlength="4096"
                        autocomplete="new-password"
                        placeholder="At least 12 characters"
                        required
                    >

                    <button
                        class="toggle-password"
                        type="button"
                        data-target="password"
                    >Show</button>
                </div>

                <span class="hint">
                    Use at least 12 characters and choose a password you don't use elsewhere.
                </span>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm password</label>

                <div class="password-wrapper">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="12"
                        maxlength="4096"
                        autocomplete="new-password"
                        placeholder="Enter the password again"
                        required
                    >

                    <button
                        class="toggle-password"
                        type="button"
                        data-target="confirm_password"
                    >Show</button>
                </div>
            </div>

            <button type="submit" class="submit-button">
                Activate Staff Account
            </button>

        </form>

        <p class="footer">
            By activating your account, you accept the event assignment
            shown above. Keep your login credentials private.
        </p>

    <?php endif; ?>

</div>

<script>
document.querySelectorAll('.toggle-password').forEach(function (button) {
    button.addEventListener('click', function () {
        const input = document.getElementById(button.dataset.target);

        if (!input) {
            return;
        }

        const showPassword = input.type === 'password';

        input.type = showPassword ? 'text' : 'password';
        button.textContent = showPassword ? 'Hide' : 'Show';
    });
});

const activationForm = document.querySelector('form');

if (activationForm) {
    activationForm.addEventListener('submit', function (event) {
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        if (password && confirmPassword && password.value !== confirmPassword.value) {
            event.preventDefault();
            alert('The passwords do not match.');
            confirmPassword.focus();
        }
    });
}
</script>

</body>
</html>