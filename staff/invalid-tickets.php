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
$rows = [];
$types = [];

/* $stats is read by includes/sidebar.php for the Invalid Tickets badge */
$stats = ['total' => 0, 'valid' => 0, 'pending' => 0, 'invalid' => 0];

/* Invalid ticket numbers for this page (cancelled + refunded) */
$invalid = ['total' => 0, 'cancelled' => 0, 'refunded' => 0];

$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);

$allowedFilters = ['all', 'cancelled', 'refunded'];
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$totalRows = 0;
$totalPages = 1;
$offset = 0;

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

/* CSRF token (used by the sidebar sign-out form) */
if (empty($_SESSION['staff_dashboard_csrf'])) {
    $_SESSION['staff_dashboard_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['staff_dashboard_csrf'];
$staffName = (string) ($_SESSION['staff_user']['full_name'] ?? 'Staff Member');
$staffRole = (string) ($_SESSION['staff_user']['role'] ?? 'checkin_staff');

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

        $eventId = (int) $selectedAssignment['event_id'];

        /* Invalid ticket counts (same definition the sidebar badge uses) */
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(it.id) AS all_tickets,
                SUM(CASE WHEN it.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN it.status = 'refunded' THEN 1 ELSE 0 END) AS refunded
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE tt.event_id = :event_id"
        );

        $stmt->execute(['event_id' => $eventId]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $invalid['cancelled'] = (int) ($s['cancelled'] ?? 0);
        $invalid['refunded'] = (int) ($s['refunded'] ?? 0);
        $invalid['total'] = $invalid['cancelled'] + $invalid['refunded'];

        $stats['total'] = (int) ($s['all_tickets'] ?? 0);
        $stats['invalid'] = $invalid['total'];

        /* Ticket types that have invalid tickets (for the filter dropdown) */
        $stmt = $pdo->prepare(
            "SELECT tt.name AS type_name, COUNT(it.id) AS total
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE tt.event_id = :event_id
               AND it.status IN ('cancelled', 'refunded')
             GROUP BY tt.id, tt.name
             ORDER BY total DESC, tt.name ASC"
        );

        $stmt->execute(['event_id' => $eventId]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* Only accept a type filter that really exists here */
        if ($type !== '' && !in_array($type, array_column($types, 'type_name'), true)) {
            $type = '';
        }

        /* Build the list filter */
        $where = "tt.event_id = :event_id AND it.status IN ('cancelled', 'refunded')";
        $params = ['event_id' => $eventId];

        if ($filter === 'cancelled') {
            $where .= " AND it.status = 'cancelled'";
        } elseif ($filter === 'refunded') {
            $where .= " AND it.status = 'refunded'";
        }

        if ($type !== '') {
            $where .= ' AND tt.name = :type';
            $params['type'] = $type;
        }

        if ($search !== '') {
            $where .= ' AND (it.attendee_name LIKE :search_name OR it.ticket_code LIKE :search_code)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_code'] = '%' . $search . '%';
        }

        /* Count + page */
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE $where"
        );
        $stmt->execute($params);
        $totalRows = (int) $stmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT
                it.id,
                it.ticket_code,
                it.attendee_name,
                it.status,
                it.issued_at,
                tt.name AS ticket_name
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE $where
             ORDER BY it.issued_at DESC, it.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Ticketa invalid tickets error: ' . $e->getMessage());
    $errors[] = 'Some invalid ticket information could not be loaded. Check the PHP error log.';
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

/* Page-only helpers */
$aid = (int) ($selectedAssignment['assignment_id'] ?? 0);

$pct = static function (int $n) use ($invalid): float {
    return $invalid['total'] > 0 ? round(($n / $invalid['total']) * 100, 1) : 0.0;
};

$url = static function (array $override) use ($aid, $filter, $search, $type): string {
    $p = array_merge(
        ['assignment_id' => $aid, 'filter' => $filter, 'q' => $search, 'type' => $type],
        $override
    );

    foreach (['q', 'type'] as $key) {
        if (($p[$key] ?? '') === '') {
            unset($p[$key]);
        }
    }

    if (isset($p['page']) && (int) $p['page'] <= 1) {
        unset($p['page']);
    }

    return '?' . http_build_query($p);
};

/* Donut built from real numbers */
if ($invalid['total'] > 0) {
    $p1 = $invalid['cancelled'] / $invalid['total'] * 100;

    $donut = sprintf(
        'conic-gradient(#ef5350 0 %1$.2F%%, #5b91f5 %1$.2F%% 100%%)',
        $p1
    );
} else {
    $donut = 'conic-gradient(#e3e5e8 0 100%)';
}

$firstRow = $totalRows > 0 ? $offset + 1 : 0;
$lastRow = min($offset + $perPage, $totalRows);

$tabs = [
    'all' => ['All Invalid', $invalid['total']],
    'cancelled' => ['Cancelled', $invalid['cancelled']],
    'refunded' => ['Refunded', $invalid['refunded']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Invalid Tickets | TICKETA</title>

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
   PAGE-SPECIFIC: Invalid Tickets
   ===================================================================== */
.inv-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(290px, .8fr);
    gap: 22px;
    align-items: start;
}

.column { display: grid; min-width: 0; gap: 20px; }

.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    padding-bottom: 10px;
    margin-bottom: 6px;
    border-bottom: 1px solid #eceef0;
}

.tabs { display: flex; flex: 1; flex-wrap: wrap; gap: 18px; }
.tabs a { padding: 9px 2px; border-bottom: 2px solid transparent; color: #62676d; font-size: 13px; white-space: nowrap; }
.tabs a:hover { color: var(--teal-dark); }
.tabs a.active { border-color: var(--teal); color: var(--ink); font-weight: 500; }

.searchbar { display: flex; flex-wrap: wrap; gap: 8px; }

.searchbar input,
.searchbar select {
    min-height: 40px;
    padding: 0 12px;
    border: 1px solid #dedfe3;
    border-radius: 8px;
    background: #fff;
    color: var(--ink);
    font-size: 13px;
}

.searchbar input { width: 220px; max-width: 100%; }
.searchbar select { max-width: 180px; }
.searchbar input:focus, .searchbar select:focus { border-color: var(--teal); outline: 2px solid #10bea033; }

.pill-button {
    display: inline-flex;
    min-height: 40px;
    align-items: center;
    gap: 8px;
    padding: 0 14px;
    border: 1px solid #dedfe3;
    border-radius: 8px;
    background: #fff;
    color: #45484e;
    font-size: 13px;
}

button.pill-button:hover { border-color: var(--teal); color: var(--teal-dark); }

.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 640px; border-collapse: collapse; }

th {
    padding: 12px 8px;
    border-bottom: 1px solid #eceef0;
    color: #45484e;
    text-align: left;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}

td { padding: 12px 8px; border-bottom: 1px solid #eceef0; font-size: 12px; vertical-align: middle; }
tbody tr:last-child td { border-bottom: 0; }

td strong { font-weight: 500; font-size: 13px; }
td small { display: block; margin-top: 4px; color: #8a8e95; font-size: 10px; }

.cell-person { display: flex; min-width: 0; align-items: center; gap: 10px; }

.status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border: 1px solid;
    border-radius: 6px;
    font-size: 11px;
    white-space: nowrap;
}

.status.cancelled { background: #ffe4e4; border-color: #f4a9a9; color: #d33f3f; }
.status.refunded { background: #e5edff; border-color: #b5caff; color: #3f6fd0; }

.view {
    padding: 7px 11px;
    border: 1px solid var(--teal);
    border-radius: 6px;
    background: #fff;
    color: #45484e;
    font-size: 11px;
}

.view:hover { background: #f1faf8; }

.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 16px;
    color: #62676d;
    font-size: 12px;
}

.pages { display: flex; gap: 6px; }

.pages a, .pages span {
    min-width: 32px;
    padding: 7px 10px;
    border-radius: 6px;
    background: #eceef0;
    color: #45484e;
    text-align: center;
}

.pages a:hover { background: #dff3ef; }
.pages span.current { background: var(--teal); color: #fff; }

.summary { display: flex; flex-wrap: wrap; align-items: center; gap: 20px; }

.donut {
    display: grid;
    width: 140px;
    height: 140px;
    flex: none;
    place-items: center;
    border-radius: 50%;
}

.donut::before { content: ""; grid-area: 1 / 1; width: 98px; height: 98px; border-radius: 50%; background: #fff; }
.donut div { grid-area: 1 / 1; z-index: 1; text-align: center; font-size: 22px; font-weight: 500; line-height: 1.2; }
.donut small { display: block; color: var(--muted); font-size: 11px; font-weight: 400; }

.legend { display: grid; gap: 12px; }
.legend-item { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; }
.legend-dot { width: 12px; height: 12px; margin-top: 3px; flex: none; border-radius: 50%; }
.legend-item small { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; }

.type-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eceef0; font-size: 13px; }
.type-row:last-child { border-bottom: 0; padding-bottom: 0; }
.type-row strong { font-weight: 500; }

.help-text { margin: 0; color: #5f666d; font-size: 13px; line-height: 1.6; }

.quick-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }

.quick-btn {
    display: flex;
    min-height: 84px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 6px;
    border: 1px solid #dce0e5;
    border-radius: 11px;
    background: #fff;
    color: #45484e;
    text-align: center;
    font-size: 12px;
    line-height: 1.4;
    transition: transform .2s ease, box-shadow .2s ease;
}

.quick-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px #0000000d; }
.quick-btn .quick-icon { font-size: 24px; line-height: 1; color: var(--teal); }

.inv-dialog {
    width: min(92vw, 420px);
    padding: 0;
    border: 0;
    border-radius: 14px;
    box-shadow: 0 20px 60px #0004;
}

.inv-dialog::backdrop { background: #0a0f19c7; }
.inv-dialog-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 18px 20px 6px; }
.inv-dialog-head h3 { margin: 0; font-size: 17px; font-weight: 600; }
.inv-dialog-close { width: 34px; height: 34px; border: 0; border-radius: 50%; background: #f0f2f4; font-size: 16px; }
.inv-dialog dl { margin: 0; padding: 8px 20px 20px; }
.inv-dialog dt { margin-top: 12px; color: var(--muted); font-size: 11px; }
.inv-dialog dd { margin: 3px 0 0; overflow-wrap: anywhere; font-size: 14px; }

@media (max-width: 1050px) {
    .inv-grid { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 760px) {
    .filters { align-items: stretch; }
    .tabs { width: 100%; gap: 12px; }
    .searchbar { width: 100%; }
    .searchbar input { flex: 1; width: auto; }
    .pagination { align-items: flex-start; flex-direction: column; }
}

@media (max-width: 480px) {
    .quick-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
</head>

<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">

    <?php require __DIR__ . '/includes/topbar.php'; ?>

    <section class="content">

        <?php foreach ($errors as $error): ?>
            <div class="notice"><?= staffEscape($error) ?></div>
        <?php endforeach; ?>

        <?php if (!$selectedAssignment): ?>

            <div class="no-assignment">
                <div class="empty-symbol">▣</div>
                <h2>No event assignment yet</h2>
                <p>
                    You are signed in successfully, but no event has been assigned
                    to your staff account. Contact your organizer to confirm your
                    event, gate and shift.
                </p>
            </div>

        <?php else: ?>

            <div class="inv-grid">

                <div class="column">

                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <h2>Invalid Tickets</h2>
                                <p>Cancelled and refunded tickets for your assigned event</p>
                            </div>
                        </div>

                        <div class="filters">
                            <nav class="tabs">
                                <?php foreach ($tabs as $key => [$label, $count]): ?>
                                    <a class="<?= $filter === $key ? 'active' : '' ?>"
                                       href="<?= staffEscape($url(['filter' => $key, 'page' => 1])) ?>"><?= staffEscape($label) ?> (<?= number_format($count) ?>)</a>
                                <?php endforeach; ?>
                            </nav>

                            <form class="searchbar" method="get">
                                <input type="hidden" name="assignment_id" value="<?= $aid ?>">
                                <input type="hidden" name="filter" value="<?= staffEscape($filter) ?>">
                                <input type="search" name="q" value="<?= staffEscape($search) ?>" placeholder="Search by name or ticket ID...">

                                <?php if ($types): ?>
                                    <select name="type" aria-label="Filter by ticket type">
                                        <option value="">All ticket types</option>
                                        <?php foreach ($types as $t): ?>
                                            <option value="<?= staffEscape($t['type_name']) ?>" <?= $type === $t['type_name'] ? 'selected' : '' ?>>
                                                <?= staffEscape($t['type_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>

                                <button class="pill-button" type="submit">⌕ Filter</button>
                            </form>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Attendee</th>
                                        <th>Ticket ID</th>
                                        <th>Section / Access</th>
                                        <th>Status</th>
                                        <th>Issued</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$rows): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <div style="font-size:28px;margin-bottom:8px">⊘</div>
                                                No invalid tickets found.<br>
                                                Cancelled or refunded tickets for this event will appear here.
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: foreach ($rows as $r): ?>
                                    <?php
                                    $attendeeName = (string) ($r['attendee_name'] ?: 'Attendee');
                                    $statusClass = (string) $r['status'] === 'refunded' ? 'refunded' : 'cancelled';
                                    $statusLabel = $statusClass === 'refunded' ? 'Refunded' : 'Cancelled';
                                    $issuedAt = $r['issued_at'] ?? null;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="cell-person">
                                                <span class="attendee-avatar"><?= staffEscape(staffInitials($attendeeName)) ?></span>
                                                <strong><?= staffEscape($attendeeName) ?></strong>
                                            </div>
                                        </td>
                                        <td><?= staffEscape($r['ticket_code']) ?></td>
                                        <td><?= staffEscape($r['ticket_name'] ?? 'General') ?></td>
                                        <td><span class="status <?= $statusClass ?>">● <?= staffEscape($statusLabel) ?></span></td>
                                        <td>
                                            <?php if ($issuedAt): ?>
                                                <strong><?= staffEscape(staffDate($issuedAt, 'g:i A')) ?></strong>
                                                <small><?= staffEscape(staffDate($issuedAt, 'd M Y')) ?></small>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="view" type="button"
                                                    data-name="<?= staffEscape($attendeeName) ?>"
                                                    data-code="<?= staffEscape($r['ticket_code']) ?>"
                                                    data-section="<?= staffEscape($r['ticket_name'] ?? 'General') ?>"
                                                    data-status="<?= staffEscape($statusLabel) ?>"
                                                    data-time="<?= staffEscape($issuedAt ? staffDate($issuedAt, 'D, d M Y · g:i A') : 'Not specified') ?>">View Details</button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination">
                            <span>Showing <?= $firstRow ?> to <?= $lastRow ?> of <?= number_format($totalRows) ?></span>

                            <div class="pages">
                                <?php if ($page > 1): ?>
                                    <a href="<?= staffEscape($url(['page' => $page - 1])) ?>" aria-label="Previous page">‹</a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <?php if ($i === $page): ?>
                                        <span class="current" aria-current="page"><?= $i ?></span>
                                    <?php else: ?>
                                        <a href="<?= staffEscape($url(['page' => $i])) ?>"><?= $i ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="<?= staffEscape($url(['page' => $page + 1])) ?>" aria-label="Next page">›</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>

                </div>

                <div class="column">

                    <section class="panel">
                        <div class="panel-heading">
                            <div><h2>Invalid Summary</h2></div>
                        </div>

                        <div class="summary">
                            <div class="donut" style="background: <?= staffEscape($donut) ?>">
                                <div><?= number_format($invalid['total']) ?><small>Total Invalid</small></div>
                            </div>

                            <div class="legend">
                                <div class="legend-item">
                                    <span class="legend-dot" style="background:#ef5350"></span>
                                    <span>Cancelled<small><?= number_format($invalid['cancelled']) ?> (<?= number_format($pct($invalid['cancelled']), 1) ?>%)</small></span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-dot" style="background:#5b91f5"></span>
                                    <span>Refunded<small><?= number_format($invalid['refunded']) ?> (<?= number_format($pct($invalid['refunded']), 1) ?>%)</small></span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel-heading">
                            <div><h2>Total Invalid Reasons</h2></div>
                        </div>

                        <div class="type-row">
                            <span>Cancelled</span>
                            <strong><?= number_format($invalid['cancelled']) ?> (<?= number_format($pct($invalid['cancelled']), 1) ?>%)</strong>
                        </div>
                        <div class="type-row">
                            <span>Refunded</span>
                            <strong><?= number_format($invalid['refunded']) ?> (<?= number_format($pct($invalid['refunded']), 1) ?>%)</strong>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel-heading">
                            <div><h2>Need Help?</h2></div>
                        </div>

                        <p class="help-text">
                            If a ticket was marked invalid by mistake, contact the event
                            manager so it can be restored, then scan it again.
                        </p>
                    </section>

                    <section class="panel">
                        <div class="panel-heading">
                            <div><h2>Quick Actions</h2></div>
                        </div>

                        <div class="quick-grid">
                            <a class="quick-btn" href="search-attendee.php?assignment_id=<?= $aid ?>">
                                <span class="quick-icon">⌕</span>
                                Search Attendee
                            </a>

                            <a class="quick-btn" href="manual-checkin.php?assignment_id=<?= $aid ?>">
                                <span class="quick-icon">♙</span>
                                Manual Check-In
                            </a>

                            <a class="quick-btn" href="todays-checkins.php?assignment_id=<?= $aid ?>">
                                <span class="quick-icon">▣</span>
                                View All Check-Ins
                            </a>
                        </div>
                    </section>

                </div>

            </div>

        <?php endif; ?>

    </section>
</main>

<dialog class="inv-dialog" id="invDialog" aria-labelledby="invDialogTitle">
    <div class="inv-dialog-head">
        <h3 id="invDialogTitle">Ticket Details</h3>
        <button class="inv-dialog-close" type="button" id="invDialogClose" aria-label="Close">✕</button>
    </div>
    <dl>
        <dt>Attendee</dt><dd id="idName"></dd>
        <dt>Ticket ID</dt><dd id="idCode"></dd>
        <dt>Section / Access</dt><dd id="idSection"></dd>
        <dt>Status</dt><dd id="idStatus"></dd>
        <dt>Issued</dt><dd id="idTime"></dd>
    </dl>
</dialog>

<script>
(function () {
    var dialog = document.getElementById('invDialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    var map = {
        name: 'idName',
        code: 'idCode',
        section: 'idSection',
        status: 'idStatus',
        time: 'idTime'
    };

    document.querySelectorAll('.view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            Object.keys(map).forEach(function (key) {
                document.getElementById(map[key]).textContent = btn.dataset[key] || '';
            });
            dialog.showModal();
        });
    });

    document.getElementById('invDialogClose').addEventListener('click', function () {
        dialog.close();
    });

    dialog.addEventListener('click', function (e) {
        if (e.target === dialog) dialog.close();
    });
})();
</script>

</body>
</html>