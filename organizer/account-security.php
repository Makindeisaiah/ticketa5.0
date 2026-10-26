<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/session_registry.php';



if (!authIsLoggedIn()) {

    header('Location: login.php');

    exit;

}



$user = $_SESSION['user'] ?? [];

$userId = (int) ($user['id'] ?? 0);

if ($userId < 1) {

    header('Location: login.php');

    exit;

}

if (($user['role'] ?? '') !== 'organizer') {

    header('Location: ../index.php');

    exit;

}



function accountSecurityEscape($value): string

{

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

}



$errors = [];

$success = '';

$setupSecret = '';

$twoFactorEnabled = false;

$recoveryCodes = [];

$account = [

    'full_name' => (string) ($user['full_name'] ?? ''),

    'email' => (string) ($user['email'] ?? ''),

];



try {

    $stmt = $pdo->prepare(

        'SELECT id, full_name, email, password_hash, email_verified_at

         FROM users WHERE id = :id LIMIT 1'

    );

    $stmt->execute(['id' => $userId]);

    $dbUser = $stmt->fetch();



    if (!$dbUser) {

        session_unset();

        session_destroy();

        header('Location: login.php');

        exit;

    }



    $account['full_name'] = (string) $dbUser['full_name'];

    $account['email'] = (string) $dbUser['email'];



    $status2fa = $pdo->prepare(

        'SELECT is_enabled FROM user_two_factor WHERE user_id = :user_id LIMIT 1'

    );

    $status2fa->execute(['user_id' => $userId]);

    $twoFactorRow = $status2fa->fetch(PDO::FETCH_ASSOC);

    $twoFactorEnabled = $twoFactorRow && (int) $twoFactorRow['is_enabled'] === 1;



    // Keep an unfinished setup visible after submitting the setup form.

    if (!$twoFactorEnabled && $twoFactorRow) {

        $pendingStmt = $pdo->prepare(

            'SELECT pending_secret_encrypted FROM user_two_factor WHERE user_id = :user_id LIMIT 1'

        );

        $pendingStmt->execute(['user_id' => $userId]);

        $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($pendingRow['pending_secret_encrypted'])) {

            $setupSecret = ticketaDecryptSecret((string) $pendingRow['pending_secret_encrypted']);

        }

    }



    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!authVerifyCsrf()) {

            $errors[] = 'Your session expired. Refresh the page and try again.';

        } else {

            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'revoke_session') {
                $targetSessionId = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
                if (!$targetSessionId || $targetSessionId < 1) {
                    $errors[] = 'Choose a valid session to sign out.';
                } else {
                    $currentHash = hash('sha256', session_id());
                    $targetStmt = $pdo->prepare('SELECT id, session_token_hash FROM user_sessions WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL LIMIT 1');
                    $targetStmt->execute(['id' => $targetSessionId, 'user_id' => $userId]);
                    $targetSession = $targetStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$targetSession) {
                        $errors[] = 'That session is no longer active or does not belong to your account.';
                    } elseif (hash_equals($currentHash, (string) $targetSession['session_token_hash'])) {
                        $errors[] = 'Use the Sign Out button to end your current session.';
                    } else {
                        $revoke = $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL');
                        $revoke->execute(['id' => $targetSessionId, 'user_id' => $userId]);
                        $success = $revoke->rowCount() === 1 ? 'The selected device has been signed out.' : 'That session was already signed out.';
                    }
                }
            } elseif ($action === 'revoke_other_sessions') {
                $currentHash = hash('sha256', session_id());
                $revokeOthers = $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL AND session_token_hash <> :current_hash');
                $revokeOthers->execute(['user_id' => $userId, 'current_hash' => $currentHash]);
                $success = 'All other registered sessions have been signed out.';
            } elseif ($action === 'update_account') {

                $name = trim((string) ($_POST['full_name'] ?? ''));

                $email = strtolower(trim((string) ($_POST['email'] ?? '')));



                if ($name === '' || strlen($name) > 150) {

                    $errors[] = 'Enter a name using no more than 150 characters.';

                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {

                    $errors[] = 'Enter a valid email address using no more than 190 characters.';

                }



                if (!$errors && strcasecmp($email, (string) $dbUser['email']) !== 0) {

                    $check = $pdo->prepare(

                        'SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1'

                    );

                    $check->execute(['email' => $email, 'id' => $userId]);

                    if ($check->fetch()) {

                        $errors[] = 'That email address is already associated with another account.';

                    }

                }



                if (!$errors) {

                    if (strcasecmp($email, (string) $dbUser['email']) !== 0) {

                        // A changed address must be verified again by the app's verification flow.

                        $update = $pdo->prepare(

                            'UPDATE users SET full_name = :name, email = :email,

                             email_verified_at = NULL WHERE id = :id'

                        );

                    } else {

                        $update = $pdo->prepare(

                            'UPDATE users SET full_name = :name WHERE id = :id'

                        );

                    }

                    $update->execute(['name' => $name, 'email' => $email, 'id' => $userId]);

                    $_SESSION['user']['full_name'] = $name;

                    $_SESSION['user']['email'] = $email;

                    $account['full_name'] = $name;

                    $account['email'] = $email;

                    $success = 'Account information saved.';

                    if (strcasecmp($email, (string) $dbUser['email']) !== 0) {

                        $success .= ' Your new email address is unverified until you complete email verification.';

                    }

                }

            } elseif ($action === 'change_password') {

                $currentPassword = (string) ($_POST['current_password'] ?? '');

                $newPassword = (string) ($_POST['new_password'] ?? '');

                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');



                if ($currentPassword === '' || !password_verify($currentPassword, (string) $dbUser['password_hash'])) {

                    $errors[] = 'Your current password is incorrect.';

                }

                if (strlen($newPassword) < 12) {

                    $errors[] = 'Your new password must be at least 12 characters long.';

                }

                if ($newPassword !== $confirmPassword) {

                    $errors[] = 'The new password and confirmation do not match.';

                }

                if ($currentPassword !== '' && $newPassword !== '' && hash_equals($currentPassword, $newPassword)) {

                    $errors[] = 'Choose a new password that differs from your current password.';

                }



                if (!$errors) {

                    $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');

                    $update->execute([

                        'hash' => password_hash($newPassword, PASSWORD_DEFAULT),

                        'id' => $userId,

                    ]);

                    $success = 'Password changed successfully.';

                }

            } elseif ($action === 'disable_2fa') {
                $disablePassword = (string) ($_POST['disable_password'] ?? '');
                $disableCode = strtoupper(preg_replace('/[\s-]+/', '', (string) ($_POST['disable_code'] ?? '')));

                if ($disablePassword === '' || !password_verify($disablePassword, (string) $dbUser['password_hash'])) {
                    $errors[] = 'Your current password is incorrect.';
                }

                $active2faStmt = $pdo->prepare(
                    'SELECT secret_encrypted, is_enabled FROM user_two_factor WHERE user_id = :user_id LIMIT 1'
                );
                $active2faStmt->execute(['user_id' => $userId]);
                $active2fa = $active2faStmt->fetch(PDO::FETCH_ASSOC);

                if (!$active2fa || (int) $active2fa['is_enabled'] !== 1 || empty($active2fa['secret_encrypted'])) {
                    $errors[] = 'Two-factor authentication is not currently enabled.';
                }

                $validSecondFactor = false;
                if (!$errors && $active2fa) {
                    $activeSecret = ticketaDecryptSecret((string) $active2fa['secret_encrypted']);
                    if (preg_match('/^\d{6}$/', $disableCode)) {
                        $validSecondFactor = ticketaVerifyTotp($activeSecret, $disableCode);
                    } elseif ($disableCode !== '') {
                        $codesStmt = $pdo->prepare(
                            'SELECT id, code_hash FROM user_two_factor_recovery_codes WHERE user_id = :user_id AND used_at IS NULL'
                        );
                        $codesStmt->execute(['user_id' => $userId]);
                        foreach ($codesStmt->fetchAll(PDO::FETCH_ASSOC) as $storedCode) {
                            if (password_verify($disableCode, (string) $storedCode['code_hash'])) {
                                $validSecondFactor = true;
                                break;
                            }
                        }
                    }

                    if (!$validSecondFactor) {
                        $errors[] = 'Enter a valid current authenticator code or an unused recovery code.';
                    }
                }

                if (!$errors) {
                    $pdo->beginTransaction();
                    try {
                        $disable = $pdo->prepare(
                            'UPDATE user_two_factor SET is_enabled = 0, secret_encrypted = NULL, pending_secret_encrypted = NULL, enabled_at = NULL WHERE user_id = :user_id AND is_enabled = 1'
                        );
                        $disable->execute(['user_id' => $userId]);
                        if ($disable->rowCount() !== 1) {
                            throw new RuntimeException('Could not disable two-factor authentication.');
                        }

                        $deleteCodes = $pdo->prepare(
                            'DELETE FROM user_two_factor_recovery_codes WHERE user_id = :user_id'
                        );
                        $deleteCodes->execute(['user_id' => $userId]);
                        $pdo->commit();

                        $twoFactorEnabled = false;
                        $setupSecret = '';
                        $recoveryCodes = [];
                        $success = 'Two-factor authentication has been disabled. Your authenticator secret and recovery codes have been removed.';
                    } catch (Throwable $disableError) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $disableError;
                    }
                }
            } elseif ($action === 'start_2fa') {

                $check2fa = $pdo->prepare(

                    'SELECT is_enabled

                     FROM user_two_factor

                     WHERE user_id = :user_id

                     LIMIT 1'

                );

                $check2fa->execute(['user_id' => $userId]);

                $existing2fa = $check2fa->fetch(PDO::FETCH_ASSOC);



                if ($existing2fa && (int) $existing2fa['is_enabled'] === 1) {

                    $twoFactorEnabled = true;

                    $errors[] = 'Two-factor authentication is already enabled.';

                } else {

                    $google2fa = new \PragmaRX\Google2FA\Google2FA();

                    $secret = $google2fa->generateSecretKey();

                    $encryptedSecret = ticketaEncryptSecret($secret);



                    $savePending = $pdo->prepare(

                        'INSERT INTO user_two_factor

                            (user_id, pending_secret_encrypted, is_enabled)

                         VALUES (:user_id, :secret, 0)

                         ON DUPLICATE KEY UPDATE

                            pending_secret_encrypted =

                                IF(is_enabled = 0, VALUES(pending_secret_encrypted),

                                   pending_secret_encrypted)'

                    );



                    $savePending->execute([

                        'user_id' => $userId,

                        'secret' => $encryptedSecret,

                    ]);



                    $setupSecret = $secret;

                    $success = 'Setup key generated. Add it to your authenticator app, then verify a code to finish setup.';

                }

            } elseif ($action === 'verify_2fa') {

                $code = preg_replace('/\s+/', '', (string) ($_POST['verification_code'] ?? ''));

                $pendingStmt = $pdo->prepare(

                    'SELECT pending_secret_encrypted, is_enabled FROM user_two_factor WHERE user_id = :user_id LIMIT 1'

                );

                $pendingStmt->execute(['user_id' => $userId]);

                $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);



                if (!$pendingRow || (int) $pendingRow['is_enabled'] === 1 || empty($pendingRow['pending_secret_encrypted'])) {

                    $errors[] = 'No unfinished authenticator setup was found. Start setup again.';

                    $setupSecret = '';

                } else {

                    $pendingSecret = ticketaDecryptSecret((string) $pendingRow['pending_secret_encrypted']);

                    if (!ticketaVerifyTotp($pendingSecret, (string) $code)) {

                        $errors[] = 'That verification code is invalid or expired. Enter the current six-digit code from Google Authenticator.';

                        $setupSecret = $pendingSecret;

                    } else {

                        $pdo->beginTransaction();

                        try {

                            $enable = $pdo->prepare(

                                'UPDATE user_two_factor SET secret_encrypted = pending_secret_encrypted, pending_secret_encrypted = NULL, is_enabled = 1, enabled_at = NOW() WHERE user_id = :user_id AND is_enabled = 0'

                            );

                            $enable->execute(['user_id' => $userId]);

                            if ($enable->rowCount() !== 1) {

                                throw new RuntimeException('Could not activate two-factor authentication.');

                            }



                            $deleteCodes = $pdo->prepare('DELETE FROM user_two_factor_recovery_codes WHERE user_id = :user_id');

                            $deleteCodes->execute(['user_id' => $userId]);

                            $saveCode = $pdo->prepare('INSERT INTO user_two_factor_recovery_codes (user_id, code_hash) VALUES (:user_id, :code_hash)');

                            for ($i = 0; $i < 8; $i++) {

                                $recoveryCode = strtoupper(bin2hex(random_bytes(5)));

                                $saveCode->execute([

                                    'user_id' => $userId,

                                    'code_hash' => password_hash($recoveryCode, PASSWORD_DEFAULT),

                                ]);

                                $recoveryCodes[] = $recoveryCode;

                            }

                            $pdo->commit();

                            $twoFactorEnabled = true;

                            $setupSecret = '';

                            $success = 'Two-factor authentication is now enabled. Save your recovery codes somewhere safe; they will not be shown again.';

                        } catch (Throwable $activationError) {

                            if ($pdo->inTransaction()) {

                                $pdo->rollBack();

                            }

                            $recoveryCodes = [];

                            throw $activationError;

                        }

                    }

                }

            } else {

                $errors[] = 'Unknown action.';

            }

        }

    }

} catch (Throwable $e) {

    error_log('Ticketa account security page: ' . $e->getMessage());

    $errors[] = 'We could not save your changes. Please check the database setup and try again.';

}



// Ensure the current authenticated session has a registry row. Never revive a revoked row.
$currentSessionHash = hash('sha256', session_id());
$currentSessionRecord = null;
$deviceSessions = [];
try {
    $currentSessionStmt = $pdo->prepare('SELECT id, user_id, revoked_at FROM user_sessions WHERE session_token_hash = :hash LIMIT 1');
    $currentSessionStmt->execute(['hash' => $currentSessionHash]);
    $currentSessionRecord = $currentSessionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentSessionRecord) {
        ticketaRegisterCurrentSession($pdo, $userId);
        $currentSessionRecord = ['user_id' => $userId, 'revoked_at' => null];
    } elseif ((int) $currentSessionRecord['user_id'] !== $userId || $currentSessionRecord['revoked_at'] !== null) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $sessionsStmt = $pdo->prepare(
        'SELECT id, session_token_hash, device_name, ip_address, last_activity, created_at
         FROM user_sessions WHERE user_id = :user_id AND revoked_at IS NULL
         ORDER BY (session_token_hash = :current_hash) DESC, last_activity DESC'
    );
    $sessionsStmt->execute(['user_id' => $userId, 'current_hash' => $currentSessionHash]);
    $deviceSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $sessionError) {
    error_log('Ticketa device session list error: ' . $sessionError->getMessage());
    $errors[] = 'Device activity could not be loaded. Check that the user_sessions table exists and try again.';
}

$csrf = authCsrfToken();

$initial = strtoupper(substr(trim($account['full_name']) ?: 'U', 0, 1));

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Account &amp; Security | TICKETA</title>

<style>

:root{--green:#10bea0;--green-dark:#08a98d;--ink:#171c2e;--muted:#777d84;--border:#e5e8e9;--bg:#fff;--soft:#f5f7f7}

*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,Arial,sans-serif}.main{min-height:100vh;padding-top:90px}.content{max-width:1600px;margin:0 auto;padding:36px 44px 48px}.section{padding:0 20px 28px;margin-bottom:28px;border-bottom:1px solid var(--border)}.section:last-child{border-bottom:0}.section-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}h2{font-size:21px;margin:0;font-weight:600}.subtext{color:var(--muted);font-size:15px;line-height:1.5;margin:8px 0 0}.account-grid{display:grid;grid-template-columns:1fr 1fr;gap:28px 44px}.field{display:grid;gap:8px;min-width:0}.field label{font-size:16px;font-weight:500}.field input{width:100%;min-height:42px;padding:10px;border:2px solid #303030;border-radius:5px;background:#f8f8f8;color:#303030;font:inherit}.field input:focus{outline:2px solid #9ae9d9;outline-offset:2px}.hint{font-size:14px;color:var(--muted);margin:4px 0 0}.btn{min-height:42px;padding:0 18px;border:1px solid #d7dcde;border-radius:6px;background:#fff;color:var(--green-dark);font:inherit;cursor:pointer}.btn-primary{border-color:var(--green);background:var(--green);color:#fff}.btn:hover{filter:brightness(.97)}.btn:disabled{opacity:.55;cursor:not-allowed}.inline-row{display:flex;align-items:center;justify-content:space-between;gap:20px}.tip{padding-top:18px;color:var(--muted);font-size:15px}.notice{margin:0 0 18px;padding:13px 16px;border-radius:7px;font-size:14px;line-height:1.5}.notice.success{background:#e8f8f0;color:#176a43}.notice.error{background:#fff0f0;color:#a32929}.security-description{display:flex;align-items:center;gap:16px;margin-top:18px}.security-icon{width:52px;height:52px;display:flex;align-items:center;justify-content:center;flex:0 0 52px;border:3px solid var(--green);border-radius:14px;color:var(--green);font-size:26px}.security-description p{margin:0 0 8px;line-height:1.5}.status-note{font-size:14px;color:var(--muted)}.toggle{position:relative;width:64px;height:36px;border:0;border-radius:20px;background:#d6d8da;cursor:not-allowed;flex:0 0 auto}.toggle:after{content:"";position:absolute;width:30px;height:30px;left:3px;top:3px;border-radius:50%;background:white;box-shadow:0 1px 3px #0002}.device-placeholder{display:flex;align-items:center;gap:14px;border:1px solid var(--border);padding:18px;margin-top:16px;background:#fff}.device-icon{width:44px;height:44px;border-radius:8px;background:#effaf7;color:var(--green-dark);display:flex;align-items:center;justify-content:center;font-size:23px}.device-text{flex:1;min-width:0}.device-text strong{display:block;margin-bottom:5px}.device-text small{color:var(--muted)}.badge{display:inline-block;padding:7px 12px;border-radius:5px;background:#e7f8ed;color:#24944b;font-size:13px}.bottom-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 20px}.password-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 28px}.password-grid .field:first-child{grid-column:1/-1}.small{font-size:13px;color:var(--muted);line-height:1.5}[hidden]{display:none!important}

.session-list{display:grid;gap:12px;margin-top:16px}.session-card{display:flex;align-items:center;gap:14px;border:1px solid var(--border);padding:16px;background:#fff}.session-card .device-text{display:grid;gap:5px}.session-card .device-text small{overflow-wrap:anywhere}.session-card form{margin-left:auto;flex-shrink:0}.current-session{margin-top:4px;width:max-content}.session-card .btn{white-space:nowrap}
@media(max-width:850px){.main{padding-top:80px}.content{padding:25px 18px}.section{padding:0 0 24px}.account-grid,.password-grid{grid-template-columns:1fr;gap:18px}.password-grid .field:first-child{grid-column:auto}.section-head,.inline-row{align-items:flex-start}.bottom-actions{padding:0;flex-direction:column;align-items:stretch}.bottom-actions .btn{width:100%}}

@media(max-width:520px){.section-head{flex-direction:column}.section-head .btn{align-self:flex-start}.security-description{align-items:flex-start}.device-placeholder{flex-wrap:wrap}.session-card{align-items:flex-start;flex-wrap:wrap}.session-card form{margin-left:58px;width:calc(100% - 58px)}.session-card form .btn{width:100%}.bottom-actions form{width:100%}}

</style>

</head>

<body>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

    <main class="content">

        <?php foreach ($errors as $error): ?><div class="notice error"><?= accountSecurityEscape($error) ?></div><?php endforeach; ?>

        <?php if ($success !== ''): ?><div class="notice success"><?= accountSecurityEscape($success) ?></div><?php endif; ?>



        <section class="section">

            <form method="post" autocomplete="on">

                <input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>">

                <input type="hidden" name="action" value="update_account">

                <div class="section-head"><h2>Account Info</h2><button class="btn btn-primary" type="submit">Save Changes</button></div>

                <div class="account-grid">

                    <div class="field"><label for="full_name">Name</label><input id="full_name" name="full_name" type="text" maxlength="150" required value="<?= accountSecurityEscape($account['full_name']) ?>" autocomplete="name"></div>

                    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" required value="<?= accountSecurityEscape($account['email']) ?>" autocomplete="email"><p class="hint">Email used for login and notifications</p></div>

                </div>

            </form>

        </section>



        <section class="section">

            <div class="section-head"><div><h2>Password</h2><p class="subtext">Change your password to help keep your account secure.</p></div><button class="btn" type="button" id="showPasswordForm">Change Password</button></div>

            <form method="post" id="passwordForm" hidden autocomplete="off">

                <input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>"><input type="hidden" name="action" value="change_password">

                <div class="password-grid">

                    <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>

                    <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="12" required autocomplete="new-password"></div>

                    <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="12" required autocomplete="new-password"></div>

                </div>

                <p class="small">Use at least 12 characters and choose a unique password.</p><button class="btn btn-primary" type="submit">Update Password</button>

            </form>

            <div class="tip">Tip: Use a strong, unique password for better account security.</div>

        </section>



        <section class="section">

            <div class="inline-row"><h2>Two-Factor Authentication (2FA)</h2><?php if ($twoFactorEnabled): ?><span class="badge">Enabled</span><?php else: ?><span class="status-note">Not enabled</span><?php endif; ?></div>

            <div class="security-description"><div class="security-icon" aria-hidden="true">⌑</div><div><p>Add an extra layer of security by requiring a verification code during login.</p><p class="status-note"><strong>Status:</strong> <?= $twoFactorEnabled ? 'Enabled' : 'Not enabled' ?></p><p class="small">Setup is not complete until the authenticator code is verified. Do not share your setup key with anyone.</p></div></div>

            <?php if ($twoFactorEnabled): ?>
                <div style="margin-top:18px;padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--soft)">
                    <h3 style="margin:0 0 8px;font-size:16px">Disable two-factor authentication</h3>
                    <p class="small">For your protection, confirm your current password and enter either the current six-digit authenticator code or one unused recovery code. Disabling 2FA removes the saved authenticator secret and invalidates all recovery codes.</p>
                    <form method="post" style="margin-top:14px;max-width:460px" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>">
                        <input type="hidden" name="action" value="disable_2fa">
                        <div class="field" style="margin-bottom:12px">
                            <label for="disable_password">Current password</label>
                            <input id="disable_password" name="disable_password" type="password" required autocomplete="current-password">
                        </div>
                        <div class="field" style="margin-bottom:14px">
                            <label for="disable_code">Authenticator or recovery code</label>
                            <input id="disable_code" name="disable_code" type="text" inputmode="text" maxlength="40" required autocomplete="one-time-code" aria-describedby="disable2faHint">
                            <p id="disable2faHint" class="small">Use the current 6-digit code, or enter an unused recovery code.</p>
                        </div>
                        <button class="btn" type="submit" onclick="return confirm('Are you sure you want to disable two-factor authentication?');">Disable 2FA</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!$twoFactorEnabled && $setupSecret === ''): ?>

                <form method="post" style="margin-top:18px">

                    <input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>">

                    <input type="hidden" name="action" value="start_2fa">

                    <button class="btn btn-primary" type="submit">Set Up Authenticator</button>

                </form>

            <?php endif; ?>

            <?php if ($setupSecret !== ''): ?>

                <div style="margin-top:18px;padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--soft)">

                    <h3 style="margin:0 0 8px;font-size:16px">Your authenticator setup key</h3>

                    <p class="small">Open Google Authenticator, choose to add an account using a setup key, and enter the key below.</p>

                    <p style="font-family:monospace;font-size:18px;font-weight:700;letter-spacing:1px;overflow-wrap:anywhere"><?= accountSecurityEscape($setupSecret) ?></p>

                    <button class="btn" type="button" id="copy2faSecret">Copy setup key</button>

                    <form method="post" style="margin-top:18px">

                        <input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>">

                        <input type="hidden" name="action" value="verify_2fa">

                        <div class="field" style="max-width:360px;margin-bottom:12px">

                            <label for="verification_code">6-digit verification code</label>

                            <input id="verification_code" name="verification_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" autocomplete="one-time-code" required>

                        </div>

                        <button class="btn btn-primary" type="submit">Verify &amp; Enable 2FA</button>

                    </form>

                    <p class="small" style="margin-top:12px">2FA is not enabled until the code is verified successfully.</p>

                </div>

            <?php endif; ?>



            <?php if ($recoveryCodes): ?>

                <div style="margin-top:18px;padding:16px;border:1px solid var(--border);border-radius:8px;background:var(--soft)">

                    <h3 style="margin:0 0 8px;font-size:16px">Save your recovery codes</h3>

                    <p class="small">Each code can be used once if you cannot access your authenticator. Store them somewhere private. This is the only time they will be displayed.</p>

                    <ul style="font-family:monospace;line-height:1.9">

                        <?php foreach ($recoveryCodes as $recoveryCode): ?><li><?= accountSecurityEscape($recoveryCode) ?></li><?php endforeach; ?>

                    </ul>

                    <button class="btn" type="button" id="copyRecoveryCodes">Copy recovery codes</button>

                </div>

            <?php endif; ?>

        </section>



        <section class="section">
            <div class="section-head"><div><h2>Devices &amp; Activity</h2><p class="subtext">Review and sign out devices that have accessed your account.</p></div><span class="badge"><?= count($deviceSessions) ?> active <?= count($deviceSessions) === 1 ? 'session' : 'sessions' ?></span></div>
            <?php if (!$deviceSessions): ?>
                <div class="device-placeholder"><div class="device-icon" aria-hidden="true">▣</div><div class="device-text"><strong>No active registered sessions found</strong><small>Sign out and sign back in if this list remains empty.</small></div></div>
            <?php else: ?>
                <div class="session-list">
                    <?php foreach ($deviceSessions as $deviceSession): ?>
                        <?php $isCurrentSession = hash_equals($currentSessionHash, (string) $deviceSession['session_token_hash']); ?>
                        <article class="session-card"><div class="device-icon" aria-hidden="true">▣</div><div class="device-text">
                            <strong><?= accountSecurityEscape($deviceSession['device_name'] ?: 'Unknown browser or device') ?></strong>
                            <small>IP address: <?= accountSecurityEscape($deviceSession['ip_address'] ?: 'Not available') ?></small>
                            <small>Last activity: <?= accountSecurityEscape($deviceSession['last_activity'] ?: 'Not available') ?></small>
                            <small>Signed in: <?= accountSecurityEscape($deviceSession['created_at'] ?: 'Not available') ?></small>
                            <?php if ($isCurrentSession): ?><span class="badge current-session">This device</span><?php endif; ?>
                        </div>
                        <?php if (!$isCurrentSession): ?><form method="post" onsubmit="return confirm('Sign out this device? It will need to sign in again.');">
                            <input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>"><input type="hidden" name="action" value="revoke_session"><input type="hidden" name="session_id" value="<?= (int) $deviceSession['id'] ?>"><button class="btn" type="submit">Sign Out</button>
                        </form><?php endif; ?></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="small">Device names are based on the browser's user-agent string. IP addresses and activity times come from your server; Ticketa does not infer physical locations.</p>
        </section>
        <div class="bottom-actions"><span class="small">Keep your account details and password up to date.</span><form method="post" onsubmit="return confirm('Sign out every other registered device? This browser will remain signed in.');"><input type="hidden" name="csrf_token" value="<?= accountSecurityEscape($csrf) ?>"><input type="hidden" name="action" value="revoke_other_sessions"><button class="btn" type="submit">Sign Out of All Other Sessions</button></form></div>

    </main>

</div>

<script>

(function () {

    const toggleButton = document.getElementById('showPasswordForm');

    const passwordForm = document.getElementById('passwordForm');

    toggleButton.addEventListener('click', function () {

        const opening = passwordForm.hidden;

        passwordForm.hidden = !opening;

        toggleButton.textContent = opening ? 'Cancel' : 'Change Password';

        if (opening) document.getElementById('current_password').focus();

        else passwordForm.reset();

    });

    const copy2faButton = document.getElementById('copy2faSecret');

    if (copy2faButton) {

        copy2faButton.addEventListener('click', async function () {

            const secretElement = copy2faButton.parentElement.querySelector('p[style*="monospace"]');

            const secret = secretElement ? secretElement.textContent.trim() : '';

            if (!secret) return;

            try {

                await navigator.clipboard.writeText(secret);

                copy2faButton.textContent = 'Copied';

            } catch (error) {

                alert('Copy was unavailable. Please select and copy the setup key manually.');

            }

        });

    }



    const copySecretButton = document.getElementById('copy2faSecret');

    if (copySecretButton) {

        copySecretButton.addEventListener('click', async function () {

            const keyElement = copySecretButton.parentElement.querySelector('p[style*="font-family:monospace"]');

            try {

                await navigator.clipboard.writeText(keyElement.textContent.trim());

                copySecretButton.textContent = 'Copied';

            } catch (error) {

                alert('Copy failed. Please select and copy the setup key manually.');

            }

        });

    }



    const copyRecoveryButton = document.getElementById('copyRecoveryCodes');

    if (copyRecoveryButton) {

        copyRecoveryButton.addEventListener('click', async function () {

            const list = copyRecoveryButton.parentElement.querySelector('ul');

            const codesText = Array.from(list.querySelectorAll('li')).map(function (item) { return item.textContent.trim(); }).join('\n');

            try {

                await navigator.clipboard.writeText(codesText);

                copyRecoveryButton.textContent = 'Copied';

            } catch (error) {

                alert('Copy failed. Please copy the recovery codes manually.');

            }

        });

    }



    passwordForm.addEventListener('submit', function (event) {

        const next = document.getElementById('new_password').value;

        const confirm = document.getElementById('confirm_password').value;

        if (next !== confirm) {

            event.preventDefault();

            alert('The new password and confirmation do not match.');

            document.getElementById('confirm_password').focus();

        }

    });

})();

</script>

</body>

</html>
