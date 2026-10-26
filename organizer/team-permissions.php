<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

if (!authIsLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'] ?? [];

if (($user['role'] ?? '') !== 'organizer') {
    header('Location: ../index.php');
    exit;
}

$userId = (int) $user['id'];
$organizerName = $user['full_name'] ?? 'Organizer';
$currentPage = 'team-permissions.php';
$topbarSearchPlaceholder = 'Search team members';

function teamEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function teamRoleLabel(string $role): string
{
    $roles = [
        'admin' => 'Admin',
        'organizer' => 'Organizer',
        'finance' => 'Finance / Accountant',
        'checkin_staff' => 'Check-in Staff',
        'marketing' => 'Marketing / Promotions',
        'support' => 'Support'
    ];

    return $roles[$role] ?? ucfirst($role);
}

$allowedRoles = [
    'admin',
    'organizer',
    'finance',
    'checkin_staff',
    'marketing',
    'support'
];

$errors = [];
$success = '';
$members = [];
$invitations = [];
$events = [];
$organizer = null;
$organizerId = 0;

try {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, business_name, email
         FROM organizers
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $stmt->execute(['user_id' => $userId]);
    $organizer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$organizer) {
        http_response_code(403);
        exit('No organizer profile is linked to your account.');
    }

    $organizerId = (int) $organizer['id'];

    if (!empty($organizer['business_name'])) {
        $organizerName = $organizer['business_name'];
    }

    // Only events belonging to this organizer are available for assignment.
    $stmt = $pdo->prepare(
        "SELECT id, title, event_date
         FROM events
         WHERE organizer_id = :organizer_id
           AND status IN ('published', 'draft')
         ORDER BY event_date ASC"
    );

    $stmt->execute(['organizer_id' => $organizerId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!authVerifyCsrf()) {
            $errors[] = 'Session expired. Refresh the page and try again.';
        } else {
            $action = $_POST['action'] ?? '';

            /*
             * INVITE TEAM MEMBER
             */
            if ($action === 'invite_member') {
                $email = strtolower(trim($_POST['email'] ?? ''));
                $role = $_POST['team_role'] ?? 'organizer';
                $gate = trim($_POST['gate_name'] ?? '');

                $eventId = filter_var(
                    $_POST['event_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $shiftStartInput = trim($_POST['shift_start'] ?? '');
                $shiftEndInput = trim($_POST['shift_end'] ?? '');

                $shiftStart = null;
                $shiftEnd = null;
                $eventId = $eventId !== false ? $eventId : null;

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid email address.';
                }

                if (strlen($email) > 190) {
                    $errors[] = 'The email address is too long.';
                }

                if (!in_array($role, $allowedRoles, true)) {
                    $errors[] = 'Select a valid team role.';
                }

                if ($role === 'checkin_staff') {
                    if (!$eventId || $eventId < 1) {
                        $errors[] = 'Select an event for the check-in staff member.';
                    }

                    if ($gate === '' || strlen($gate) > 100) {
                        $errors[] = 'Enter a gate name of no more than 100 characters.';
                    }

                    $startDate = DateTimeImmutable::createFromFormat(
                        '!Y-m-d\TH:i',
                        $shiftStartInput
                    );

                    $endDate = DateTimeImmutable::createFromFormat(
                        '!Y-m-d\TH:i',
                        $shiftEndInput
                    );

                    if (
                        !$startDate ||
                        $startDate->format('Y-m-d\TH:i') !== $shiftStartInput
                    ) {
                        $errors[] = 'Enter a valid shift start time.';
                    } else {
                        $shiftStart = $startDate->format('Y-m-d H:i:s');
                    }

                    if (
                        !$endDate ||
                        $endDate->format('Y-m-d\TH:i') !== $shiftEndInput
                    ) {
                        $errors[] = 'Enter a valid shift end time.';
                    } else {
                        $shiftEnd = $endDate->format('Y-m-d H:i:s');
                    }

                    if ($startDate && $endDate && $endDate <= $startDate) {
                        $errors[] = 'Shift end must be later than shift start.';
                    }

                    // Never trust an event ID supplied by the browser.
                    if ($eventId && $eventId > 0) {
                        $stmt = $pdo->prepare(
                            'SELECT id
                             FROM events
                             WHERE id = :event_id
                               AND organizer_id = :organizer_id
                             LIMIT 1'
                        );

                        $stmt->execute([
                            'event_id' => $eventId,
                            'organizer_id' => $organizerId
                        ]);

                        if (!$stmt->fetch()) {
                            $errors[] = 'The selected event does not belong to your organization.';
                        }
                    }
                }

                if (!$errors) {
                    // Prevent inviting the organizer owner as a team member.
                    $stmt = $pdo->prepare(
                        'SELECT id
                         FROM users
                         WHERE email = :email
                         LIMIT 1'
                    );

                    $stmt->execute(['email' => $email]);
                    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (
                        $existingUser &&
                        (int) $existingUser['id'] === $userId
                    ) {
                        $errors[] = 'You are already the organization owner.';
                    }

                    // Preserve the existing membership checks for other roles.
                    if ($existingUser && !$errors && $role !== 'checkin_staff') {
                        $stmt = $pdo->prepare(
                            'SELECT id
                             FROM organizer_team_members
                             WHERE organizer_id = :org
                               AND user_id = :user
                             LIMIT 1'
                        );

                        $stmt->execute([
                            'org' => $organizerId,
                            'user' => (int) $existingUser['id']
                        ]);

                        if ($stmt->fetch()) {
                            $errors[] = 'This user already has a team membership.';
                        }
                    }

                    // A pending invitation for the same email blocks duplicates.
                    $stmt = $pdo->prepare(
                        "SELECT id
                         FROM organizer_team_invitations
                         WHERE organizer_id = :org
                           AND email = :email
                           AND status = 'pending'
                           AND expires_at > NOW()
                         LIMIT 1"
                    );

                    $stmt->execute([
                        'org' => $organizerId,
                        'email' => $email
                    ]);

                    if ($stmt->fetch()) {
                        $errors[] = 'An invitation is already pending for this email.';
                    }

                    // Do not create a second staff account for an existing staff email.
                    if ($role === 'checkin_staff' && !$errors) {
                        $stmt = $pdo->prepare(
                            'SELECT id
                             FROM staff_users
                             WHERE email = :email
                             LIMIT 1'
                        );

                        $stmt->execute(['email' => $email]);

                        if ($stmt->fetch()) {
                            $errors[] = 'A staff account already exists for this email. A separate staff-assignment workflow will be needed to assign that account to another event.';
                        }
                    }
                }

                if (!$errors) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $expires = (new DateTimeImmutable('+7 days'))
                        ->format('Y-m-d H:i:s');

                    $stmt = $pdo->prepare(
                        "INSERT INTO organizer_team_invitations
                         (
                             organizer_id,
                             invited_by,
                             email,
                             team_role,
                             gate_name,
                             event_id,
                             shift_start,
                             shift_end,
                             token_hash,
                             status,
                             expires_at
                         )
                         VALUES
                         (
                             :org,
                             :sender,
                             :email,
                             :role,
                             :gate,
                             :event_id,
                             :shift_start,
                             :shift_end,
                             :token,
                             'pending',
                             :expires
                         )"
                    );

                    $stmt->execute([
                        'org' => $organizerId,
                        'sender' => $userId,
                        'email' => $email,
                        'role' => $role,
                        'gate' => $role === 'checkin_staff' ? $gate : null,
                        'event_id' => $role === 'checkin_staff' ? $eventId : null,
                        'shift_start' => $role === 'checkin_staff' ? $shiftStart : null,
                        'shift_end' => $role === 'checkin_staff' ? $shiftEnd : null,
                        'token' => $tokenHash,
                        'expires' => $expires
                    ]);

                    $invitationId = (int) $pdo->lastInsertId();

                    // Use the existing SMTP configuration.
                    $mailConfig = require dirname(__DIR__) . '/config/mail.php';
                    $baseUrl = rtrim(
                        (string) ($mailConfig['base_url'] ?? ''),
                        '/'
                    );

                    if (
                        $baseUrl === '' ||
                        !filter_var($baseUrl, FILTER_VALIDATE_URL) ||
                        !in_array(
                            strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)),
                            ['http', 'https'],
                            true
                        )
                    ) {
                        $errors[] = 'The Ticketa application URL is not configured correctly.';

                        $stmt = $pdo->prepare(
                            "UPDATE organizer_team_invitations
                             SET status = 'revoked'
                             WHERE id = :id
                               AND organizer_id = :org
                               AND status = 'pending'"
                        );

                        $stmt->execute([
                            'id' => $invitationId,
                            'org' => $organizerId
                        ]);
                    } else {
                        if ($role === 'checkin_staff') {
                            $invitationUrl = $baseUrl
                                . '/staff/activate-account.php?token='
                                . rawurlencode($token);
                        } else {
                            $invitationUrl = $baseUrl
                                . '/organizer/accept-team-invitation.php?token='
                                . rawurlencode($token);
                        }

                        try {
                            sendTeamInvitationEmail(
                                $email,
                                $organizer['business_name'] ?: 'TICKETA',
                                $role,
                                $invitationUrl
                            );

                            $success = 'Invitation email sent successfully to '
                                . $email . '.';
                        } catch (Throwable $e) {
                            error_log(
                                'Ticketa invitation delivery failed for invitation ID '
                                . $invitationId . ': ' . $e->getMessage()
                            );

                            $stmt = $pdo->prepare(
                                "UPDATE organizer_team_invitations
                                 SET status = 'revoked'
                                 WHERE id = :id
                                   AND organizer_id = :org
                                   AND status = 'pending'"
                            );

                            $stmt->execute([
                                'id' => $invitationId,
                                'org' => $organizerId
                            ]);

                            $errors[] = 'Ticketa could not send the invitation email. Check the SMTP configuration and try again.';
                        }
                    }
                }
            } elseif ($action === 'revoke_invitation') {
                $invitationId = filter_input(
                    INPUT_POST,
                    'invitation_id',
                    FILTER_VALIDATE_INT
                );

                if ($invitationId) {
                    $stmt = $pdo->prepare(
                        "UPDATE organizer_team_invitations
                         SET status = 'revoked'
                         WHERE id = :id
                           AND organizer_id = :org
                           AND status = 'pending'"
                    );

                    $stmt->execute([
                        'id' => $invitationId,
                        'org' => $organizerId
                    ]);

                    $success = $stmt->rowCount()
                        ? 'Invitation revoked.'
                        : 'No pending invitation was found to revoke.';
                }
            } elseif ($action === 'revoke_member') {
                $memberId = filter_input(
                    INPUT_POST,
                    'member_id',
                    FILTER_VALIDATE_INT
                );
                $memberType = $_POST['member_type'] ?? 'team_member';

                if ($memberId && $memberId > 0) {
                    if ($memberType === 'staff_assignment') {
                        // Only revoke assignments belonging to this organizer's events.
                        $stmt = $pdo->prepare(
                            "UPDATE staff_event_assignments a
                             INNER JOIN events e ON e.id = a.event_id
                             SET a.status = 'revoked'
                             WHERE a.id = :id
                               AND e.organizer_id = :org"
                        );
                        $stmt->execute([
                            'id' => $memberId,
                            'org' => $organizerId
                        ]);
                        $success = $stmt->rowCount()
                            ? 'Check-in staff access revoked.'
                            : 'No matching staff assignment was found.';
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE organizer_team_members
                             SET status = 'revoked'
                             WHERE id = :id
                               AND organizer_id = :org"
                        );
                        $stmt->execute([
                            'id' => $memberId,
                            'org' => $organizerId
                        ]);
                        $success = $stmt->rowCount()
                            ? 'Team member access revoked.'
                            : 'No matching team membership was found.';
                    }
                }
            }
        }
    }

    // Existing organizer team members.
    $stmt = $pdo->prepare(
        'SELECT
             tm.id AS membership_id,
             tm.team_role,
             tm.gate_name,
             tm.status,
             u.full_name,
             u.email,
             u.is_active
         FROM organizer_team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.organizer_id = :org
         ORDER BY tm.created_at DESC'
    );

    $stmt->execute(['org' => $organizerId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also show check-in staff assigned to this organizer's events.
    // Keep assignment IDs separate from organizer_team_members IDs by carrying
    // a member_type marker through the existing revoke action.
    $stmt = $pdo->prepare(
        "SELECT
             a.id AS membership_id,
             'checkin_staff' AS team_role,
             a.assigned_gate AS gate_name,
             a.status AS status,
             s.full_name,
             s.email,
             CASE WHEN LOWER(s.status) = 'active' THEN 1 ELSE 0 END AS is_active,
             'staff_assignment' AS member_type,
             e.title AS event_title
         FROM staff_event_assignments a
         INNER JOIN staff_users s ON s.id = a.staff_user_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE e.organizer_id = :org
         ORDER BY a.id DESC"
    );
    $stmt->execute(['org' => $organizerId]);
    $staffAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($members as &$member) {
        $member['member_type'] = 'team_member';
    }
    unset($member);
    $members = array_merge($members, $staffAssignments);

    // Include event and shift details for pending check-in invitations.
    $stmt = $pdo->prepare(
        "SELECT
             i.id,
             i.email,
             i.team_role,
             i.gate_name,
             i.event_id,
             i.shift_start,
             i.shift_end,
             i.expires_at,
             e.title AS event_title
         FROM organizer_team_invitations i
         LEFT JOIN events e ON e.id = i.event_id
         WHERE i.organizer_id = :org
           AND i.status = 'pending'
         ORDER BY i.created_at DESC"
    );

    $stmt->execute(['org' => $organizerId]);
    $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Ticketa team page: ' . $e->getMessage());
    $errors[] = 'Could not load team settings. Check the database setup.';
}

$csrf = authCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Team &amp; Permissions | TICKETA</title>

<style>
:root {
    --green: #10bea0;
    --green-dark: #08a98d;
    --ink: #171c2e;
    --muted: #737982;
    --border: #e8ecef;
    --bg: #fafbfc;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font-family: Inter, Arial, sans-serif;
}

.main { min-height: 100vh; padding-top: 90px; }
.team-content { padding: 24px 32px 40px; }

.toolbar {
    display: grid;
    grid-template-columns: auto minmax(220px, 1fr);
    gap: 26px;
    margin-bottom: 22px;
}

.invite-button {
    min-height: 54px;
    padding: 0 18px;
    border: 0;
    border-radius: 9px;
    background: var(--green);
    color: #fff;
    font: inherit;
    cursor: pointer;
}

.invite-button:hover, .submit-button:hover {
    background: var(--green-dark);
}

.search-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 16px;
    border-radius: 9px;
    background: #f1f4f3;
}

.search-wrap input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    font-size: 16px;
}

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(340px, .95fr);
    gap: 26px;
    align-items: start;
}

.panel {
    min-width: 0;
    padding: 20px;
    border: 1px solid #eceff0;
    border-radius: 12px;
    background: white;
}

.panel h2 {
    margin: 0 0 20px;
    font-size: 21px;
    font-weight: 600;
}

.member-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto auto;
    gap: 12px;
    align-items: center;
    padding: 13px 0;
    border-bottom: 1px solid #f0f1f2;
}

.person {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.avatar {
    display: flex;
    flex: 0 0 54px;
    width: 54px;
    height: 54px;
    align-items: center;
    justify-content: center;
    border: 2px solid #f2c34b;
    border-radius: 9px;
    background: #fff9e6;
    font-size: 21px;
    font-weight: 700;
}

.person strong {
    display: block;
    overflow-wrap: anywhere;
    font-size: 16px;
    font-weight: 500;
}

.person small {
    display: block;
    margin-top: 4px;
    color: #555;
    overflow-wrap: anywhere;
    font-size: 13px;
}

.pill {
    display: inline-block;
    min-width: 82px;
    padding: 8px;
    border-radius: 6px;
    color: #fff;
    text-align: center;
    font-size: 12px;
}

.role-admin { background: #969696; }
.role-organizer { background: #8bd849; }
.role-finance { background: #7900df; }
.role-checkin_staff { background: #c63bd1; }
.role-marketing, .role-support { background: #c9c51b; }

.status-active { background: var(--green); }
.status-revoked { background: #9299a1; }
.status-inactive { background: #e6a43c; }

.actions {
    display: flex;
    border: 1px solid #aaa;
    border-radius: 4px;
    overflow: hidden;
}

.actions button {
    height: 35px;
    width: 35px;
    border: 0;
    background: #fff;
    cursor: pointer;
}

.actions button + button { border-left: 1px solid #aaa; }

.permission-card {
    margin-bottom: 10px;
    overflow: hidden;
    border: 1px solid #e6e8e9;
    border-radius: 7px;
}

.permission-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: #f3f5f5;
    font-size: 16px;
    font-weight: 600;
}

.letter {
    display: inline-flex;
    width: 29px;
    height: 29px;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    background: #ffe500;
}

.permission-list {
    margin: 0;
    padding: 7px 14px 10px;
    list-style: none;
}

.permission-list li {
    padding: 5px 0;
    color: #454950;
    font-size: 13px;
}

.permission-list li:before {
    content: '✓';
    margin-right: 10px;
}

.notice {
    margin-bottom: 15px;
    padding: 12px 15px;
    border-radius: 7px;
    font-size: 14px;
    overflow-wrap: anywhere;
}

.success { background: #e8f8f0; color: #176a43; }
.error { background: #fff0f0; color: #a32929; }

.small-action {
    padding: 8px 12px;
    border: 1px solid #d5d9dc;
    border-radius: 5px;
    background: white;
    cursor: pointer;
}

.pending-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
    padding: 12px 0;
    border-top: 1px solid #eef0f1;
}

.pending-row strong { overflow-wrap: anywhere; }

.pending-row small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    overflow-wrap: anywhere;
}

.backdrop {
    position: fixed;
    z-index: 2000;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: #0004;
}

.backdrop.open { display: flex; }

.modal {
    width: min(700px, 100%);
    max-height: 90vh;
    overflow: auto;
    border-radius: 12px;
    background: white;
    box-shadow: 0 10px 35px #0003;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 21px 25px;
    border-bottom: 1px solid #e6e6e6;
}

.modal-header h2 {
    margin: 0;
    font-size: 23px;
    font-weight: 600;
}

.close {
    border: 0;
    background: transparent;
    font-size: 28px;
    cursor: pointer;
}

.modal-body { padding: 22px 25px; }

.field {
    display: grid;
    gap: 7px;
    margin-bottom: 17px;
}

.field label { font-size: 14px; font-weight: 500; }

.field input, .field select {
    width: 100%;
    min-height: 43px;
    padding: 10px;
    border: 1px solid #555;
    border-radius: 5px;
    background: #f7f7f7;
    font: inherit;
}

.role-guide {
    padding: 14px 17px;
    border-radius: 5px;
    background: #a9ffb5;
}

.guide-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
}

.guide-check {
    display: flex;
    width: 25px;
    height: 25px;
    flex: 0 0 25px;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #00a94f;
    color: white;
}

.guide-row strong { min-width: 90px; font-weight: 500; }
.guide-row small { color: #657b68; }

.modal-footer {
    display: flex;
    justify-content: flex-end;
    padding: 16px 25px 22px;
    border-top: 1px solid #e6e6e6;
}

.submit-button {
    min-height: 47px;
    padding: 0 26px;
    border: 0;
    border-radius: 6px;
    background: var(--green);
    color: white;
    font: inherit;
    cursor: pointer;
}

[hidden] { display: none !important; }

@media (max-width: 1200px) {
    .layout { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .main { padding-top: 80px; }
    .team-content { padding: 18px 14px 30px; }
    .toolbar { grid-template-columns: 1fr; gap: 12px; }
    .invite-button { justify-self: start; }
    .panel { padding: 15px; }

    .member-row {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
    }

    .person { grid-column: 1 / -1; }
    .actions { justify-self: end; }

    .modal-header, .modal-body {
        padding-right: 16px;
        padding-left: 16px;
    }

    .guide-row { flex-wrap: wrap; }
}
</style>
</head>

<body>
<div class="main">

<?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

<main class="team-content">

<?php foreach ($errors as $error): ?>
    <div class="notice error"><?= teamEscape($error) ?></div>
<?php endforeach; ?>

<?php if ($success !== ''): ?>
    <div class="notice success"><?= teamEscape($success) ?></div>
<?php endif; ?>

<div class="toolbar">
    <button class="invite-button" type="button" id="openModal">
        + Invite Team Member
    </button>

    <label class="search-wrap">
        ⌕
        <input id="teamSearch" type="search" placeholder="Search team members...">
    </label>
</div>

<div class="layout">

<section class="panel">
    <h2>Team Members</h2>

    <div class="member-row"
         data-search="<?= teamEscape(strtolower($organizerName . ' ' . ($organizer['email'] ?? $user['email'] ?? '') . ' admin owner')) ?>">

        <div class="person">
            <span class="avatar"><?= teamEscape(strtoupper(substr($organizerName, 0, 1))) ?></span>
            <div>
                <strong><?= teamEscape($organizerName) ?></strong>
                <small><?= teamEscape($organizer['email'] ?? $user['email'] ?? '') ?></small>
            </div>
        </div>

        <span class="pill role-admin">Admin</span>
        <span class="pill status-active">Owner</span>
        <span></span>
    </div>

    <?php if (!$members): ?>
        <p style="color:#737982;font-size:14px">
            No additional team members yet. Invite someone to help manage your events.
        </p>
    <?php endif; ?>

    <?php foreach ($members as $member):
        $role = (string) $member['team_role'];
        $status = $member['status'] === 'revoked'
            ? 'Revoked'
            : (((int) $member['is_active'] === 1 && $member['status'] === 'active')
                ? 'Active'
                : 'Inactive');
    ?>
        <div class="member-row"
             data-search="<?= teamEscape(strtolower($member['full_name'] . ' ' . $member['email'] . ' ' . teamRoleLabel($role) . ' ' . $status)) ?>">

            <div class="person">
                <span class="avatar"><?= teamEscape(strtoupper(substr($member['full_name'], 0, 1))) ?></span>
                <div>
                    <strong><?= teamEscape($member['full_name']) ?></strong>
                    <small>
                        <?= teamEscape($member['email']) ?>
                        <?php if ($role === 'checkin_staff' && !empty($member['gate_name'])): ?>
                            <br>Gate: <?= teamEscape($member['gate_name']) ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>

            <span class="pill role-<?= teamEscape($role) ?>">
                <?= teamEscape(teamRoleLabel($role)) ?>
            </span>

            <span class="pill status-<?= strtolower($status) ?>">
                <?= teamEscape($status) ?>
            </span>

            <div class="actions">
                <button type="button" class="editRole" title="Edit role">✎</button>
                <button type="button"
                        class="revokeMember"
                        data-id="<?= (int) $member['membership_id'] ?>"
                        data-type="<?= teamEscape($member['member_type'] ?? 'team_member') ?>"
                        title="Revoke access">•••</button>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($invitations): ?>
        <h2 style="margin-top:24px">Pending Invitations</h2>

        <?php foreach ($invitations as $inv): ?>
            <div class="pending-row">
                <div>
                    <strong><?= teamEscape($inv['email']) ?></strong>

                    <small>
                        <?= teamEscape(teamRoleLabel($inv['team_role'])) ?>

                        <?php if ($inv['team_role'] === 'checkin_staff'): ?>
                            <br>Event: <?= teamEscape($inv['event_title'] ?? 'Event unavailable') ?>
                            <br>Gate: <?= teamEscape($inv['gate_name'] ?? '') ?>

                            <?php if (!empty($inv['shift_start']) && !empty($inv['shift_end'])): ?>
                                <br>Shift:
                                <?= teamEscape(date('M j, Y H:i', strtotime($inv['shift_start']))) ?>
                                –
                                <?= teamEscape(date('M j, Y H:i', strtotime($inv['shift_end']))) ?>
                            <?php endif; ?>
                        <?php endif; ?>

                        <br>Expires <?= teamEscape(date('M j, Y', strtotime($inv['expires_at']))) ?>
                    </small>
                </div>

                <form method="post"
                      onsubmit="return confirm('Revoke this invitation?')">

                    <input type="hidden" name="csrf_token" value="<?= teamEscape($csrf) ?>">
                    <input type="hidden" name="action" value="revoke_invitation">
                    <input type="hidden" name="invitation_id" value="<?= (int) $inv['id'] ?>">

                    <button class="small-action" type="submit">Revoke</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Permission Levels</h2>

    <article class="permission-card">
        <div class="permission-heading"><span class="letter">A</span>Admin</div>
        <ul class="permission-list">
            <li>Full organization access</li>
            <li>Manage organization and billing</li>
            <li>Add and remove team members</li>
        </ul>
    </article>

    <article class="permission-card">
        <div class="permission-heading"><span class="letter">O</span>Organizer</div>
        <ul class="permission-list">
            <li>Create and manage events</li>
            <li>Manage tickets and pricing</li>
            <li>View sales analytics</li>
        </ul>
    </article>

    <article class="permission-card">
        <div class="permission-heading"><span class="letter">F</span>Finance / Accountant</div>
        <ul class="permission-list">
            <li>View revenue and payouts</li>
            <li>Process refunds</li>
            <li>Access financial reports</li>
        </ul>
    </article>

    <article class="permission-card">
        <div class="permission-heading"><span class="letter">C</span>Check-in Staff</div>
        <ul class="permission-list">
            <li>Scan tickets assigned to their event</li>
            <li>Perform manual check-ins</li>
            <li>View permitted attendee information</li>
        </ul>
    </article>

    <article class="permission-card">
        <div class="permission-heading"><span class="letter">M</span>Marketing / Promotions</div>
        <ul class="permission-list">
            <li>Manage promo codes</li>
            <li>View campaign analytics (read-only)</li>
            <li>Access event sharing tools</li>
        </ul>
    </article>

    <article class="permission-card">
        <div class="permission-heading"><span class="letter">S</span>Support</div>
        <ul class="permission-list">
            <li>Help resolve attendee issues</li>
            <li>View permitted order details</li>
            <li>No access to payout settings</li>
        </ul>
    </article>
</section>

</div>
</main>
</div>

<div class="backdrop"
     id="inviteModal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="modalTitle">

    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Invite New Team Member</h2>
            <button type="button" class="close" id="closeModal" aria-label="Close">×</button>
        </div>

        <form method="post" id="inviteForm">
            <input type="hidden" name="csrf_token" value="<?= teamEscape($csrf) ?>">
            <input type="hidden" name="action" value="invite_member">

            <div class="modal-body">
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email"
                           id="email"
                           name="email"
                           placeholder="Enter email"
                           maxlength="190"
                           required>
                </div>

                <div class="field">
                    <label for="teamRole">Organizer Type / Role</label>

                    <select name="team_role" id="teamRole" required>
                        <option value="organizer">Organizer</option>
                        <option value="admin">Admin</option>
                        <option value="finance">Finance / Accountant</option>
                        <option value="checkin_staff">Check-in Staff</option>
                        <option value="marketing">Marketing / Promotions</option>
                        <option value="support">Support</option>
                    </select>
                </div>

                <div id="staffAssignmentFields" hidden>
                    <div class="field">
                        <label for="eventId">Assign Event</label>

                        <select name="event_id" id="eventId">
                            <option value="">Select an event</option>

                            <?php foreach ($events as $event): ?>
                                <option value="<?= (int) $event['id'] ?>">
                                    <?= teamEscape($event['title']) ?>
                                    — <?= teamEscape(
                                        date('M j, Y', strtotime($event['event_date']))
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!$events): ?>
                            <small>You need to create an event before assigning check-in staff.</small>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="gateName">Assign Gate</label>
                        <input type="text"
                               name="gate_name"
                               id="gateName"
                               maxlength="100"
                               placeholder="e.g. Main Entrance">
                    </div>

                    <div class="field">
                        <label for="shiftStart">Shift Start</label>
                        <input type="datetime-local"
                               name="shift_start"
                               id="shiftStart">
                    </div>

                    <div class="field">
                        <label for="shiftEnd">Shift End</label>
                        <input type="datetime-local"
                               name="shift_end"
                               id="shiftEnd">
                    </div>
                </div>

                <div class="role-guide">
                    <div class="guide-row">
                        <span class="guide-check">✓</span>
                        <strong>Admin</strong>
                        <small>Full organization access</small>
                    </div>
                    <div class="guide-row">
                        <span class="guide-check">✓</span>
                        <strong>Organizer</strong>
                        <small>Manage events</small>
                    </div>
                    <div class="guide-row">
                        <span class="guide-check">✓</span>
                        <strong>Finance</strong>
                        <small>Financial tasks</small>
                    </div>
                    <div class="guide-row">
                        <span class="guide-check">✓</span>
                        <strong>Check-in Staff</strong>
                        <small>Check-in for an assigned event</small>
                    </div>
                    <div class="guide-row">
                        <span class="guide-check">✓</span>
                        <strong>Marketing</strong>
                        <small>Promotions and sharing</small>
                    </div>
                    <div class="guide-row">
                        <span class="guide-check">✓</span>
                        <strong>Support</strong>
                        <small>Attendee support</small>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit"
                        class="submit-button"
                        <?= !$events ? 'data-no-events="1"' : '' ?>>
                    Send Invite
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('inviteModal');
    const openButton = document.getElementById('openModal');
    const closeButton = document.getElementById('closeModal');

    const role = document.getElementById('teamRole');
    const staffFields = document.getElementById('staffAssignmentFields');
    const eventId = document.getElementById('eventId');
    const gate = document.getElementById('gateName');
    const shiftStart = document.getElementById('shiftStart');
    const shiftEnd = document.getElementById('shiftEnd');
    const inviteForm = document.getElementById('inviteForm');

    function showModal() {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        document.getElementById('email').focus();
    }

    function hideModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
        openButton.focus();
    }

    openButton.addEventListener('click', showModal);
    closeButton.addEventListener('click', hideModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            hideModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('open')) {
            hideModal();
        }
    });

    function updateStaffFields() {
        const isStaff = role.value === 'checkin_staff';

        staffFields.hidden = !isStaff;
        eventId.required = isStaff;
        gate.required = isStaff;
        shiftStart.required = isStaff;
        shiftEnd.required = isStaff;

        // In browsers that support it, prevent selecting an end time
        // earlier than the selected start time.
        shiftEnd.min = shiftStart.value || '';
    }

    role.addEventListener('change', updateStaffFields);

    shiftStart.addEventListener('change', function () {
        shiftEnd.min = shiftStart.value || '';
    });

    inviteForm.addEventListener('submit', function (event) {
        if (
            role.value === 'checkin_staff' &&
            eventId.options.length <= 1
        ) {
            event.preventDefault();
            alert('Create an event before inviting check-in staff.');
        }
    });

    updateStaffFields();

    document.getElementById('teamSearch').addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();

        document.querySelectorAll('.member-row').forEach(function (row) {
            row.hidden = !row.dataset.search.includes(query);
        });
    });

    document.querySelectorAll('.revokeMember').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('Revoke this team member access?')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'post';

            const fields = {
                csrf_token: <?= json_encode($csrf) ?>,
                action: 'revoke_member',
                member_id: button.dataset.id,
                member_type: button.dataset.type || 'team_member'
            };

            Object.entries(fields).forEach(function ([name, value]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        });
    });

    document.querySelectorAll('.editRole').forEach(function (button) {
        button.addEventListener('click', function () {
            alert('Role editing will be added in a separate step.');
        });
    });

    <?php if (
        $errors &&
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        (($_POST['action'] ?? '') === 'invite_member')
    ): ?>
    showModal();
    <?php endif; ?>
})();
</script>

</body>
</html>
