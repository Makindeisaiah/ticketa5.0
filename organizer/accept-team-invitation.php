
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!authIsLoggedIn()) {
    $_SESSION['auth_return_to'] = 'index.php';
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$email = strtolower(trim((string) ($user['email'] ?? '')));
$token = trim((string) ($_GET['token'] ?? ''));
$message = '';
$success = false;

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    $message = 'This invitation link is invalid.';
} else {
    try {
        $tokenHash = hash('sha256', $token);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT i.*, o.business_name
             FROM organizer_team_invitations i
             INNER JOIN organizers o ON o.id = i.organizer_id
             WHERE i.token_hash = :token
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['token' => $tokenHash]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            $message = 'This invitation link is invalid or no longer exists.';
        } elseif ($invitation['status'] !== 'pending') {
            $message = 'This invitation has already been used or revoked.';
        } elseif (strtotime($invitation['expires_at']) < time()) {
            $expire = $pdo->prepare(
                "UPDATE organizer_team_invitations SET status = 'expired' WHERE id = :id"
            );
            $expire->execute(['id' => $invitation['id']]);
            $message = 'This invitation has expired. Ask the organizer to send another one.';
        } elseif (!hash_equals(
            strtolower((string) $invitation['email']),
            $email
        )) {
            $message = 'Sign in with the email address that received this invitation.';
        } else {
            $member = $pdo->prepare(
                'SELECT id FROM organizer_team_members
                 WHERE organizer_id = :org AND user_id = :user LIMIT 1'
            );
            $member->execute([
                'org' => $invitation['organizer_id'],
                'user' => $userId
            ]);
            $existing = $member->fetch();

            if ($existing) {
                $updateMember = $pdo->prepare(
                    "UPDATE organizer_team_members
                     SET team_role = :role, gate_name = :gate, status = 'active'
                     WHERE id = :id"
                );
                $updateMember->execute([
                    'role' => $invitation['team_role'],
                    'gate' => $invitation['gate_name'],
                    'id' => $existing['id']
                ]);
            } else {
                $insertMember = $pdo->prepare(
                    "INSERT INTO organizer_team_members
                     (organizer_id, user_id, team_role, gate_name, status)
                     VALUES (:org, :user, :role, :gate, 'active')"
                );
                $insertMember->execute([
                    'org' => $invitation['organizer_id'],
                    'user' => $userId,
                    'role' => $invitation['team_role'],
                    'gate' => $invitation['gate_name']
                ]);
            }

            $accept = $pdo->prepare(
                "UPDATE organizer_team_invitations
                 SET status = 'accepted', accepted_at = NOW()
                 WHERE id = :id AND status = 'pending'"
            );
            $accept->execute(['id' => $invitation['id']]);

            $pdo->commit();
            $success = true;
            $message = 'You have joined ' .
                ($invitation['business_name'] ?: 'the organization') .
                ' as ' . $invitation['team_role'] . '.';
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Ticketa invitation acceptance error: ' . $e->getMessage());
        http_response_code(500);
        $message = 'We could not process this invitation. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accept Team Invitation | TICKETA</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#f7faf9;color:#171c2e;font-family:Arial,sans-serif}
.card{width:min(500px,100%);padding:32px;border:1px solid #e4e9e7;border-radius:14px;background:#fff;text-align:center;box-shadow:0 8px 30px #172b2410}
h1{font-size:25px}p{line-height:1.6;color:#59615f}.status{margin:20px 0;padding:15px;border-radius:8px;background:<?= $success ? "'#e7f8ef'" : "'#fff0f0'" ?>;color:<?= $success ? "'#176a43'" : "'#a32929'" ?>}
a{display:inline-block;margin-top:12px;padding:12px 18px;border-radius:7px;background:#10bea0;color:#fff;text-decoration:none}
</style>
</head>
<body>
<div class="card">
<h1><?= $success ? 'Invitation accepted' : 'Team invitation' ?></h1>
<p class="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($success): ?>
<p>You can now return to Ticketa and use your team account.</p>
<a href="index.php">Continue to Ticketa</a>
<?php else: ?>
<a href="team-permissions.php">Return to Team &amp; Permissions</a>
<?php endif; ?>
</div>
</body>
</html>
