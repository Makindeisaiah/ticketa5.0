<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/database.php';

/* Authentication */
if (empty($_SESSION['staff_user']['id'])) {
    header('Location: login.php');
    exit;
}

$staffId = (int) $_SESSION['staff_user']['id'];

$errors = [];
$assignments = [];
$selectedAssignment = null;

/* $stats is read by includes/sidebar.php for the Invalid Tickets badge */
$stats = ['total' => 0, 'valid' => 0, 'pending' => 0, 'invalid' => 0];

$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);

$tab = (string) ($_GET['tab'] ?? 'general');
if (!in_array($tab, ['general', 'account'], true)) {
    $tab = 'general';
}

$saved = ($_GET['saved'] ?? '') === '1';
$formPosted = false;
$form = ['full_name' => '', 'phone' => ''];
$memberSince = null;

/* ---- Helpers (same as the other staff pages) ---- */

function staffEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function staffDate($value, string $format = 'D, d M Y · H:i'): string
{
    if (empty($value)) {
        return 'Not specified';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false
        ? 'Not specified'
        : date($format, $timestamp);
}

function staffInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return $initials !== '' ? $initials : 'S';
}

function staffRoleLabel(string $role): string
{
    $labels = [
        'checkin_staff' => 'Check-in Staff',
        'supervisor' => 'Supervisor',
        'admin' => 'Administrator',
    ];

    return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function staffEventImageUrl($imagePath): string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $imagePath)) {
        return filter_var($imagePath, FILTER_VALIDATE_URL)
            ? $imagePath
            : '';
    }

    if (
        str_starts_with($imagePath, '//') ||
        preg_match('~^[a-z][a-z0-9+.-]*:~i', $imagePath) ||
        str_contains($imagePath, "\0")
    ) {
        return '';
    }

    $imagePath = str_replace('\\', '/', $imagePath);

    foreach (explode('/', $imagePath) as $segment) {
        if ($segment === '..') {
            return '';
        }
    }

    $imagePath = ltrim($imagePath, '/');
    $projectFolder = basename(dirname(__DIR__));

    if (str_starts_with($imagePath, $projectFolder . '/')) {
        return '/' . $imagePath;
    }

    return '/' . $projectFolder . '/' . $imagePath;
}

/* CSRF token (used by the sidebar sign-out form and the account form) */
if (empty($_SESSION['staff_dashboard_csrf'])) {
    $_SESSION['staff_dashboard_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['staff_dashboard_csrf'];
$staffName = (string) ($_SESSION['staff_user']['full_name'] ?? 'Staff Member');
$staffRole = (string) ($_SESSION['staff_user']['role'] ?? 'checkin_staff');

/* Account update: name and phone only */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account') {
    $tab = 'account';
    $formPosted = true;

    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $name = trim((string) preg_replace('/\s+/', ' ', (string) ($_POST['full_name'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    $form = ['full_name' => $name, 'phone' => $phone];

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Enter your full name (2 to 100 characters).';
    } elseif ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) {
        $errors[] = 'Enter a valid phone number, or leave it empty.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'UPDATE staff_users
                 SET full_name = :full_name, phone = :phone
                 WHERE id = :id'
            );

            $stmt->execute([
                'full_name' => $name,
                'phone' => $phone,
                'id' => $staffId,
            ]);

            $_SESSION['staff_user']['full_name'] = $name;

            header(
                'Location: settings.php?tab=account&saved=1' .
                ($assignmentId ? '&assignment_id=' . (int) $assignmentId : '')
            );
            exit;
        } catch (Throwable $e) {
            error_log('Ticketa staff account update error: ' . $e->getMessage());
            $errors[] = 'Your details could not be saved. Check the PHP error log.';
        }
    }
}

try {
    /* Load staff account */
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, phone, role, status
         FROM staff_users
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute(['id' => $staffId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$staff || $staff['status'] !== 'active') {
        unset($_SESSION['staff_user']);
        header('Location: login.php');
        exit;
    }

    $staffName = (string) $staff['full_name'];
    $staffRole = (string) $staff['role'];

    $_SESSION['staff_user']['full_name'] = $staffName;
    $_SESSION['staff_user']['email'] = (string) $staff['email'];
    $_SESSION['staff_user']['role'] = $staffRole;

    if (!$formPosted) {
        $form = [
            'full_name' => $staffName,
            'phone' => (string) ($staff['phone'] ?? ''),
        ];
    }

    /* "Member since" only if the table has a created_at column */
    try {
        $stmt = $pdo->prepare('SELECT created_at FROM staff_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $staffId]);
        $memberSince = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $memberSince = null;
    }

    /* Load assignments belonging to this staff account */
    $stmt = $pdo->prepare(
        "SELECT
            a.id AS assignment_id,
            a.event_id,
            a.assigned_gate,
            a.assignment_role,
            a.shift_start,
            a.shift_end,
            a.status AS assignment_status,
            e.title AS event_title,
            e.event_date,
            e.event_end_date,
            e.venue,
            e.address,
            e.city,
            e.image_url
         FROM staff_event_assignments a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.staff_user_id = :staff_id
           AND a.status IN ('scheduled', 'active', 'completed')
         ORDER BY
            CASE
                WHEN a.status = 'active' THEN 0
                WHEN a.status = 'scheduled' THEN 1
                ELSE 2
            END,
            a.shift_start ASC"
    );

    $stmt->execute(['staff_id' => $staffId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($assignments) {
        if ($assignmentId) {
            foreach ($assignments as $assignment) {
                if ((int) $assignment['assignment_id'] === $assignmentId) {
                    $selectedAssignment = $assignment;
                    break;
                }
            }
        }

        if (!$selectedAssignment) {
            $selectedAssignment = $assignments[0];
        }

        /* Invalid ticket count for the sidebar badge (same definition as the other pages) */
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(it.id) AS all_tickets,
                SUM(CASE WHEN it.status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END) AS invalid_tickets
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE tt.event_id = :event_id"
        );

        $stmt->execute(['event_id' => (int) $selectedAssignment['event_id']]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stats['total'] = (int) ($s['all_tickets'] ?? 0);
        $stats['invalid'] = (int) ($s['invalid_tickets'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('Ticketa staff settings error: ' . $e->getMessage());
    $errors[] = 'Some settings information could not be loaded. Check the PHP error log.';
}

/* Logout (the shared sidebar posts to the current page) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if ($postedToken !== '' && hash_equals($csrfToken, $postedToken)) {
        unset($_SESSION['staff_user'], $_SESSION['staff_dashboard_csrf']);
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

    $errors[] = 'Your session expired. Refresh the page and try again.';
}

/* Variables expected by includes/topbar.php */
$eventTitle = (string) ($selectedAssignment['event_title'] ?? 'No event assigned');
$eventVenue = trim(
    (string) ($selectedAssignment['venue'] ?? '') .
    (!empty($selectedAssignment['city'])
        ? ', ' . $selectedAssignment['city']
        : '')
);

$gateName = (string) ($selectedAssignment['assigned_gate'] ?? 'Not assigned');
$shiftStart = $selectedAssignment['shift_start'] ?? null;
$shiftEnd = $selectedAssignment['shift_end'] ?? null;
$eventImage = staffEventImageUrl($selectedAssignment['image_url'] ?? '');

/* Page-only values */
$aid = (int) ($selectedAssignment['assignment_id'] ?? 0);
$aidQuery = $aid > 0 ? '&assignment_id=' . $aid : '';

$staffEmail = (string) ($staff['email'] ?? '');
$staffPhone = (string) ($staff['phone'] ?? '');
$staffStatus = (string) ($staff['status'] ?? 'active');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Settings | TICKETA</title>

<style>
/* =====================================================================
   SHARED STAFF STYLES: same block as dashboard.php / todays-checkins.php
   ===================================================================== */
:root {
    --teal: #10bea0;
    --teal-dark: #08a98d;
    --ink: #202124;
    --muted: #777b82;
    --border: #e6e8eb;
    --page: #f8f9fb;
    --purple: #8b5cf6;
    --blue: #5b91f5;
    --yellow: #d6a21e;
    --red: #ef5350;
    --sidebar-width: 240px;
    --topbar-height: 112px;
}

* { box-sizing: border-box; }

html {
    min-height: 100%;
    scroll-padding-top: calc(var(--topbar-height) + 15px);
}

body {
    margin: 0;
    min-height: 100vh;
    background: var(--page);
    color: var(--ink);
    font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    font-size: 14px;
}

button, input, select { font: inherit; }
button, a { -webkit-tap-highlight-color: transparent; }
a { color: inherit; text-decoration: none; }
button { cursor: pointer; }
[hidden] { display: none !important; }

/* SIDEBAR */
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 1000;
    display: flex;
    width: var(--sidebar-width);
    height: 100vh;
    height: 100dvh;
    flex-direction: column;
    overflow-x: hidden;
    overflow-y: hidden;
    padding: 22px 16px 14px;
    border-right: 1px solid var(--border);
    background: #fff;
    scrollbar-width: thin;
}

.brand {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 9px;
    margin: 0 0 32px;
    padding: 0 3px;
    color: #111;
    font-size: 23px;
    font-weight: 600;
    letter-spacing: -1px;
}

.brand-mark {
    display: inline-flex;
    width: 37px;
    height: 30px;
    align-items: center;
    justify-content: center;
    border: 3px solid var(--teal);
    border-radius: 5px;
    color: var(--teal);
    font-size: 21px;
    font-weight: 700;
    line-height: 1;
}

.nav { display: grid; flex: 0 0 auto; gap: 8px; }

.nav-link {
    display: flex;
    min-width: 0;
    min-height: 43px;
    align-items: center;
    gap: 12px;
    padding: 9px 12px;
    border-radius: 5px;
    color: #74777d;
    font-size: 14px;
    transition: background .2s ease, color .2s ease;
}

.nav-link:hover { background: #f1faf8; color: var(--teal-dark); }
.nav-link.active { background: var(--teal); color: white; box-shadow: 0 3px 8px #10bea022; }

.nav-icon {
    display: inline-flex;
    width: 22px;
    flex: 0 0 22px;
    align-items: center;
    justify-content: center;
    font-size: 19px;
}

.nav-text { min-width: 0; overflow-wrap: anywhere; }

.nav-count {
    display: inline-flex;
    min-width: 21px;
    height: 21px;
    align-items: center;
    justify-content: center;
    margin-left: auto;
    border-radius: 50%;
    background: #fee2e2;
    color: #e5484d;
    font-size: 11px;
    font-weight: 700;
}

/* STAFF PROFILE AND SIGN OUT */
.profile-card {
    flex: 0 0 auto;
    margin-top: auto;
    padding: 13px;
    border: 1px solid #dedfe3;
    border-radius: 11px;
    background: #fcfcfd;
}

.profile-details { display: flex; min-width: 0; align-items: center; gap: 10px; }

.avatar {
    display: inline-flex;
    width: 39px;
    height: 39px;
    flex: 0 0 39px;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: 50%;
    background: #e1f7f1;
    color: #078f79;
    font-weight: 700;
}

.profile-copy { min-width: 0; }

.profile-copy strong {
    display: block;
    overflow: hidden;
    font-size: 13px;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.profile-copy small { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; }

.online-status {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 10px 0 9px 48px;
    color: #62676d;
    font-size: 12px;
}

.online-dot { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: var(--teal); }

.logout-form { margin: 0; padding-top: 8px; border-top: 1px solid #e5e6e8; }

.logout-button {
    display: flex;
    width: 100%;
    align-items: center;
    gap: 10px;
    padding: 5px 2px;
    border: 0;
    background: transparent;
    color: #70747a;
    text-align: left;
    font-size: 12px;
}

.logout-button:hover { color: #d33; }

/* MAIN AREA */
.main { min-width: 0; min-height: 100vh; margin-left: var(--sidebar-width); }

/* FIXED TOPBAR */
.topbar {
    position: fixed;
    top: 0;
    right: 0;
    left: var(--sidebar-width);
    z-index: 900;
    display: flex;
    min-height: var(--topbar-height);
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 14px 25px;
    border-bottom: 1px solid var(--border);
    background: rgba(255, 255, 255, .97);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

.event-heading { display: flex; min-width: 0; align-items: center; gap: 15px; }

.event-poster {
    display: flex;
    width: 64px;
    height: 76px;
    flex: 0 0 64px;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: 6px;
    background: linear-gradient(145deg, #e3f7f4, #d9e6ff);
    color: #078f79;
    font-size: 25px;
    font-weight: 700;
}

.event-poster img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; }

.event-copy { min-width: 0; }

.event-copy h1 {
    margin: 0 0 7px;
    overflow-wrap: anywhere;
    font-size: clamp(18px, 1.7vw, 25px);
    font-weight: 600;
    letter-spacing: -.5px;
}

.event-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
}

.event-meta .separator { color: var(--teal); }

.assignment-meta {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    border: 1px solid #dedfe3;
    border-radius: 15px;
    background: white;
}

.assignment-meta-item { display: flex; min-width: 145px; align-items: center; gap: 10px; padding: 12px 14px; }
.assignment-meta-item + .assignment-meta-item { border-left: 1px solid #e3e4e7; }

.meta-icon {
    display: inline-flex;
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    align-items: center;
    justify-content: center;
    border: 1px solid #e4e5e8;
    border-radius: 50%;
    font-size: 17px;
}

.meta-icon.purple { color: var(--purple); }
.meta-icon.blue { color: var(--blue); }

.assignment-meta small { display: block; margin-bottom: 4px; color: var(--muted); font-size: 11px; }
.assignment-meta strong { display: block; max-width: 145px; overflow-wrap: anywhere; font-size: 13px; font-weight: 500; }

/* CONTENT MUST START BELOW FIXED TOPBAR */
.content { padding: calc(var(--topbar-height) + 22px) 25px 24px; }

.notice {
    margin-bottom: 15px;
    padding: 12px 15px;
    border-radius: 8px;
    background: #fff0f0;
    color: #a32929;
    overflow-wrap: anywhere;
}

.panel {
    min-width: 0;
    padding: 20px;
    border: 1px solid #f0f0f2;
    border-radius: 13px;
    background: white;
    box-shadow: 0 3px 7px #161b2d12, 0 7px 14px #161b2d08;
}

.panel-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 15px; }
.panel-heading h2 { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -.3px; }
.panel-heading p { margin: 6px 0 0; color: var(--muted); font-size: 13px; line-height: 1.5; }

.stat-card { min-width: 0; min-height: 135px; padding: 12px 10px; border: 1px solid #dfe2e8; border-radius: 11px; }
.stat-card.teal { background: #ddf8f3; }
.stat-card.blue { background: #e5edff; }
.stat-card.yellow { background: #fbf2dc; }
.stat-card.red { background: #ffe4e4; }

.stat-icon { margin-bottom: 9px; font-size: 22px; }
.stat-card.teal .stat-icon, .stat-card.teal .stat-foot { color: #19bca1; }
.stat-card.blue .stat-icon, .stat-card.blue .stat-foot { color: #5489ed; }
.stat-card.yellow .stat-icon, .stat-card.yellow .stat-foot { color: #d6a21e; }
.stat-card.red .stat-icon, .stat-card.red .stat-foot { color: #ed5353; }

.stat-number { display: block; overflow-wrap: anywhere; font-size: clamp(19px, 1.8vw, 25px); font-weight: 500; line-height: 1.2; }
.stat-label { display: block; margin-top: 7px; color: #6f747b; font-size: 11px; line-height: 1.45; }
.stat-foot { display: block; margin-top: 9px; font-size: 10px; }

.attendee-avatar {
    display: flex;
    width: 37px;
    height: 37px;
    flex: 0 0 37px;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #e1f6f1;
    color: #07967f;
    font-size: 12px;
    font-weight: 700;
}

.empty-state { padding: 28px 10px; color: var(--muted); text-align: center; font-size: 13px; line-height: 1.7; }

.no-assignment {
    max-width: 600px;
    margin: 35px auto;
    padding: 40px 25px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: white;
    text-align: center;
    box-shadow: 0 3px 8px #00000008;
}

.empty-symbol { margin-bottom: 14px; color: var(--teal); font-size: 40px; }
.no-assignment h2 { margin: 0 0 10px; font-size: 22px; }
.no-assignment p { margin: 0; color: var(--muted); line-height: 1.7; }

/* Shared responsive rules */
@media (max-height: 760px) and (min-width: 1051px) {
    .sidebar { padding-top: 13px; padding-bottom: 10px; }
    .brand { margin-bottom: 20px; }
    .nav { gap: 5px; }
    .nav-link { min-height: 37px; padding-top: 7px; padding-bottom: 7px; }
    .profile-card { padding: 10px; }
    .online-status { margin-top: 6px; margin-bottom: 6px; }
    .panel { padding: 16px; }
    .stat-card { min-height: 125px; padding: 10px 8px; }
}

@media (max-width: 1350px) and (min-width: 1051px) {
    :root { --sidebar-width: 220px; --topbar-height: 105px; }
    .sidebar { padding-right: 12px; padding-left: 12px; }
    .topbar { padding-right: 17px; padding-left: 17px; }
    .content { padding-right: 17px; padding-left: 17px; }
    .assignment-meta-item { min-width: 115px; padding: 9px; gap: 7px; }
    .meta-icon { width: 30px; height: 30px; flex-basis: 30px; }
    .stat-card { min-height: 115px; }
}

@media (max-width: 1050px) {
    :root { --sidebar-width: 0px; --topbar-height: 0px; }

    .sidebar {
        position: relative;
        inset: auto;
        width: 100%;
        height: auto;
        overflow: visible;
        padding: 13px 16px;
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }

    .brand { margin-bottom: 14px; }
    .nav { display: flex; flex-wrap: wrap; gap: 6px; }
    .nav-link { min-height: 38px; padding: 8px 10px; }

    .profile-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-top: 12px;
        padding: 9px 12px;
    }

    .online-status { margin: 0 auto 0 0; }
    .logout-form { padding: 0; border-top: 0; }
    .logout-button { width: auto; padding: 8px; }

    .main { margin-left: 0; }

    .topbar {
        position: relative;
        top: auto;
        right: auto;
        left: auto;
        min-height: auto;
        flex-wrap: wrap;
        padding: 17px;
    }

    .content { padding: 17px; }
    .assignment-meta { max-width: 100%; }
    .assignment-meta-item { min-width: 0; flex: 1; }
}

@media (max-width: 760px) {
    .topbar { align-items: flex-start; flex-direction: column; }
    .assignment-meta { width: 100%; }
    .content { padding: 14px; }
    .panel { padding: 16px; }
}

@media (max-width: 480px) {
    .sidebar { padding: 12px; }
    .brand { font-size: 21px; }
    .nav { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .nav-link { font-size: 12px; }
    .profile-card { flex-wrap: wrap; }
    .online-status { margin-left: 0; }
    .event-heading { align-items: flex-start; gap: 11px; }
    .event-poster { width: 52px; height: 62px; flex-basis: 52px; }
    .assignment-meta-item { gap: 7px; padding: 9px; }
    .assignment-meta strong { font-size: 11px; }
    .stat-card { min-height: 120px; }
}

/* =====================================================================
   PAGE-SPECIFIC: Settings
   ===================================================================== */
.settings-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(290px, .8fr);
    gap: 22px;
    align-items: start;
}

.settings-main {
    display: grid;
    grid-template-columns: 200px minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}

.column { display: grid; min-width: 0; gap: 20px; }

.notice.success { background: #e8f8ef; color: #176b3a; }

.settings-nav { display: grid; gap: 6px; padding: 14px; }

.settings-nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 44px;
    padding: 0 12px;
    border-radius: 8px;
    color: #4a4f56;
    font-size: 14px;
}

.settings-nav a:hover { background: #f1faf8; color: var(--teal-dark); }
.settings-nav a.active { background: #dff5f0; color: var(--ink); font-weight: 500; }
.settings-nav .nav-glyph { width: 22px; flex: 0 0 22px; text-align: center; font-size: 18px; color: var(--teal); }

.settings-content h2 { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -.3px; }
.settings-content .lead { margin: 6px 0 18px; color: var(--muted); font-size: 13px; line-height: 1.5; }

.setting-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 0;
    border-bottom: 1px solid #eceef0;
}

.setting-icon {
    display: grid;
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    place-items: center;
    border-radius: 50%;
    background: #dff5f0;
    color: #19bca1;
    font-size: 18px;
}

.setting-copy { min-width: 0; flex: 1; }
.setting-copy strong { display: block; font-size: 14px; font-weight: 500; }
.setting-copy small { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; line-height: 1.4; }

.setting-control { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; }

.setting-control select {
    min-height: 40px;
    min-width: 170px;
    padding: 0 12px;
    border: 1px solid #dedfe3;
    border-radius: 8px;
    background: #fff;
    color: var(--ink);
    font-size: 13px;
}

.setting-control select:focus,
.field input:focus { border-color: var(--teal); outline: 2px solid #10bea033; }

.mini-btn {
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid #dedfe3;
    border-radius: 8px;
    background: #fff;
    color: #45484e;
    font-size: 12px;
}

.mini-btn:hover { border-color: var(--teal); color: var(--teal-dark); }

.switch { position: relative; display: inline-block; width: 48px; height: 28px; flex: none; }
.switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }

.slider {
    position: absolute;
    inset: 0;
    border-radius: 28px;
    background: #d3d6db;
    pointer-events: none;
    transition: background .2s ease;
}

.slider::before {
    content: "";
    position: absolute;
    top: 3px;
    left: 3px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px #0003;
    transition: transform .2s ease;
}

.switch input:checked + .slider { background: var(--teal); }
.switch input:checked + .slider::before { transform: translateX(20px); }
.switch input:focus-visible + .slider { outline: 2px solid #10bea066; outline-offset: 2px; }

.settings-footer { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 14px; margin-top: 22px; }
.settings-footer .hint { margin-right: auto; color: var(--muted); font-size: 12px; }
.saved-msg { color: #176b3a; font-size: 13px; }

.primary-button {
    display: inline-flex;
    min-height: 44px;
    align-items: center;
    justify-content: center;
    padding: 0 28px;
    border: 0;
    border-radius: 10px;
    background: var(--teal);
    color: #fff;
    font-weight: 600;
}

.primary-button:hover { background: var(--teal-dark); }

.field { display: grid; gap: 6px; margin-bottom: 16px; }
.field label { color: #62676d; font-size: 12px; }

.field input {
    min-height: 44px;
    padding: 0 12px;
    border: 1px solid #dedfe3;
    border-radius: 8px;
    background: #fff;
    color: var(--ink);
    font-size: 14px;
}

.field input[readonly] { background: #f4f5f7; color: var(--muted); }
.field small { color: var(--muted); font-size: 11px; }

.profile-top { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.profile-top .attendee-avatar { width: 48px; height: 48px; flex-basis: 48px; font-size: 15px; }
.profile-top strong { display: block; font-size: 16px; font-weight: 600; overflow-wrap: anywhere; }
.profile-top small { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; }
.badge-active { margin-left: auto; padding: 5px 12px; border-radius: 6px; background: #dff5f0; color: var(--teal-dark); font-size: 12px; }
.badge-inactive { margin-left: auto; padding: 5px 12px; border-radius: 6px; background: #f0f1f3; color: #6b7078; font-size: 12px; }

.kv { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; padding: 12px 0; border-bottom: 1px solid #eceef0; font-size: 13px; }
.kv:last-child { border-bottom: 0; padding-bottom: 0; }
.kv span:first-child { color: #62676d; }
.kv span:last-child { text-align: right; overflow-wrap: anywhere; font-weight: 500; }

@media (max-width: 1050px) {
    .settings-grid { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 760px) {
    .settings-main { grid-template-columns: minmax(0, 1fr); }
    .settings-nav { display: flex; gap: 6px; padding: 10px; overflow-x: auto; }
    .settings-nav a { flex: 0 0 auto; }
    .setting-row { flex-wrap: wrap; }
    .setting-control { width: 100%; justify-content: flex-end; }
}
</style>
</head>

<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">

    <?php require __DIR__ . '/includes/topbar.php'; ?>

    <section class="content">

        <?php if ($saved): ?>
            <div class="notice success">Your details were saved.</div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="notice"><?= staffEscape($error) ?></div>
        <?php endforeach; ?>

        <div class="settings-grid">

            <div class="settings-main">

                <nav class="panel settings-nav" aria-label="Settings sections">
                    <a class="<?= $tab === 'general' ? 'active' : '' ?>" href="?tab=general<?= staffEscape($aidQuery) ?>"><span class="nav-glyph">⚙</span>General</a>
                    <a class="<?= $tab === 'account' ? 'active' : '' ?>" href="?tab=account<?= staffEscape($aidQuery) ?>"><span class="nav-glyph">♙</span>Account</a>
                </nav>

                <section class="panel settings-content">

                <?php if ($tab === 'general'): ?>

                    <h2>General Settings</h2>
                    <p class="lead">Configure general preferences for the check-in system</p>

                    <div id="prefsUnavailable" class="notice" hidden>
                        The preferences script (<code>staff/assets/staff-prefs.js</code>) could not be loaded, so these settings are disabled.
                    </div>

                    <div class="setting-row">
                        <span class="setting-icon">♪</span>
                        <div class="setting-copy">
                            <strong>Sound Alert</strong>
                            <small>Play a sound for successful or failed check-ins.</small>
                        </div>
                        <div class="setting-control">
                            <button class="mini-btn" type="button" id="testSound">Test</button>
                            <label class="switch" aria-label="Sound alert">
                                <input type="checkbox" id="prefSound">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="setting-row">
                        <span class="setting-icon">⇥</span>
                        <div class="setting-copy">
                            <strong>Auto Logout</strong>
                            <small>Automatically log out after a period of inactivity.</small>
                        </div>
                        <div class="setting-control">
                            <select id="prefLogout" aria-label="Auto logout">
                                <option value="0">Never</option>
                                <option value="15">15 Minutes</option>
                                <option value="30">30 Minutes</option>
                                <option value="60">60 Minutes</option>
                            </select>
                        </div>
                    </div>

                    <div class="setting-row">
                        <span class="setting-icon">▦</span>
                        <div class="setting-copy">
                            <strong>Default Camera</strong>
                            <small>Which camera the QR scanner opens with.</small>
                        </div>
                        <div class="setting-control">
                            <select id="prefCamera" aria-label="Default camera">
                                <option value="environment">Rear camera</option>
                                <option value="user">Front camera</option>
                            </select>
                        </div>
                    </div>

                    <div class="settings-footer">
                        <span class="hint">Saved on this device only.</span>
                        <span class="saved-msg" id="prefsSaved" role="status" aria-live="polite" hidden>Saved.</span>
                        <button class="primary-button" type="button" id="savePrefs">Save Changes</button>
                    </div>

                <?php else: ?>

                    <h2>Account</h2>
                    <p class="lead">Update the details shown on your staff profile</p>

                    <form method="post" action="?tab=account<?= staffEscape($aidQuery) ?>" novalidate>
                        <input type="hidden" name="action" value="update_account">
                        <input type="hidden" name="csrf_token" value="<?= staffEscape($csrfToken) ?>">

                        <div class="field">
                            <label for="fullName">Full name</label>
                            <input id="fullName" name="full_name" type="text" maxlength="100" autocomplete="name" value="<?= staffEscape($form['full_name']) ?>" required>
                        </div>

                        <div class="field">
                            <label for="email">Email</label>
                            <input id="email" type="email" value="<?= staffEscape($staffEmail) ?>" readonly>
                            <small>Contact an administrator to change your email address.</small>
                        </div>

                        <div class="field">
                            <label for="phone">Phone</label>
                            <input id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= staffEscape($form['phone']) ?>" placeholder="+234 801 234 5678">
                        </div>

                        <div class="field">
                            <label for="role">Role</label>
                            <input id="role" type="text" value="<?= staffEscape(staffRoleLabel($staffRole)) ?>" readonly>
                        </div>

                        <div class="settings-footer">
                            <button class="primary-button" type="submit">Save Changes</button>
                        </div>
                    </form>

                <?php endif; ?>

                </section>
            </div>

            <div class="column">

                <section class="panel">
                    <div class="panel-heading">
                        <div><h2>Profile Summary</h2></div>
                    </div>

                    <div class="profile-top">
                        <span class="attendee-avatar"><?= staffEscape(staffInitials($staffName)) ?></span>
                        <div>
                            <strong><?= staffEscape($staffName) ?></strong>
                            <small><?= staffEscape(staffRoleLabel($staffRole)) ?></small>
                        </div>
                        <?php if ($staffStatus === 'active'): ?>
                            <span class="badge-active">Active</span>
                        <?php else: ?>
                            <span class="badge-inactive"><?= staffEscape(ucfirst($staffStatus)) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="kv"><span>Email</span><span><?= staffEscape($staffEmail !== '' ? $staffEmail : 'Not set') ?></span></div>
                    <div class="kv"><span>Phone</span><span><?= staffEscape($staffPhone !== '' ? $staffPhone : 'Not set') ?></span></div>
                    <div class="kv"><span>Role</span><span><?= staffEscape(staffRoleLabel($staffRole)) ?></span></div>
                    <div class="kv"><span>Assigned Gate</span><span><?= staffEscape($gateName) ?></span></div>
                    <?php if ($memberSince): ?>
                        <div class="kv"><span>Member Since</span><span><?= staffEscape(staffDate($memberSince, 'd M Y, g:i A')) ?></span></div>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><h2>System Information</h2></div>
                    </div>

                    <div class="kv"><span>Server Time</span><span><?= staffEscape(date('D, d M Y · g:i A')) ?></span></div>
                    <div class="kv"><span>Time Zone</span><span><?= staffEscape(date_default_timezone_get()) ?></span></div>
                    <div class="kv"><span>Assigned Events</span><span><?= number_format(count($assignments)) ?></span></div>
                </section>

            </div>

        </div>

    </section>
</main>

<script src="assets/staff-prefs.js"></script>
<script>
(function () {
    var save = document.getElementById('savePrefs');
    if (!save) return;

    var sound = document.getElementById('prefSound');
    var logout = document.getElementById('prefLogout');
    var camera = document.getElementById('prefCamera');
    var test = document.getElementById('testSound');
    var msg = document.getElementById('prefsSaved');
    var unavailable = document.getElementById('prefsUnavailable');

    if (!window.TicketaPrefs) {
        unavailable.hidden = false;
        [sound, logout, camera, test, save].forEach(function (el) { el.disabled = true; });
        return;
    }

    var prefs = window.TicketaPrefs.get();
    sound.checked = !!prefs.sound;
    logout.value = String(prefs.autoLogout);
    camera.value = prefs.camera === 'user' ? 'user' : 'environment';

    if (logout.value !== String(prefs.autoLogout)) {
        logout.value = '30';
    }

    test.addEventListener('click', function () {
        window.TicketaPrefs.beep('success', true);
    });

    save.addEventListener('click', function () {
        window.TicketaPrefs.set({
            sound: sound.checked,
            autoLogout: Number(logout.value),
            camera: camera.value
        });

        msg.hidden = false;
        setTimeout(function () { msg.hidden = true; }, 2500);
    });
})();
</script>

</body>
</html>