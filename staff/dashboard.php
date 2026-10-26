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



$staff = null;

$assignments = [];

$recentCheckins = [];

$errors = [];

$selectedAssignment = null;



$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);



$stats = [

    'total' => 0,

    'valid' => 0,

    'pending' => 0,

    'invalid' => 0,

];



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



/**

 * Resolve an event image URL or local project-relative path.

 *

 * Example database value:

 * assets/uploads/events/example.jpg

 *

 * Result:

 * /ticketa/assets/uploads/events/example.jpg

 */

function staffEventImageUrl($imagePath): string

{

    $imagePath = trim((string) $imagePath);



    if ($imagePath === '') {

        return '';

    }



    // Allow only HTTP(S) absolute image URLs.

    if (preg_match('~^https?://~i', $imagePath)) {

        return filter_var($imagePath, FILTER_VALIDATE_URL)

            ? $imagePath

            : '';

    }



    // Reject protocol-relative URLs, other URL schemes and traversal.

    if (

        str_starts_with($imagePath, '//') ||

        preg_match('~^[a-z][a-z0-9+.-]*:~i', $imagePath) ||

        str_contains($imagePath, "\0")

    ) {

        return '';

    }



    $imagePath = str_replace('\\\\', '/', $imagePath);

    $segments = explode('/', $imagePath);



    foreach ($segments as $segment) {

        if ($segment === '..') {

            return '';

        }

    }



    $imagePath = ltrim($imagePath, '/');



    // Use the project root folder, not the current /staff/ directory.

    $projectFolder = basename(dirname(__DIR__));



    if (str_starts_with($imagePath, $projectFolder . '/')) {

        return '/' . $imagePath;

    }



    return '/' . $projectFolder . '/' . $imagePath;

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



    $_SESSION['staff_user']['full_name'] = $staff['full_name'];

    $_SESSION['staff_user']['email'] = $staff['email'];

    $_SESSION['staff_user']['role'] = $staff['role'];



    /* CSRF protection for logout */

    if (empty($_SESSION['staff_dashboard_csrf'])) {

        $_SESSION['staff_dashboard_csrf'] = bin2hex(random_bytes(32));

    }



    /* Logout */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $action = $_POST['action'] ?? '';



        if ($action === 'logout') {

            $submittedToken = (string) ($_POST['csrf_token'] ?? '');

            $sessionToken = (string) ($_SESSION['staff_dashboard_csrf'] ?? '');



            if (

                $sessionToken !== '' &&

                hash_equals($sessionToken, $submittedToken)

            ) {

                unset($_SESSION['staff_user']);

                unset($_SESSION['staff_dashboard_csrf']);



                session_regenerate_id(true);



                header('Location: login.php');

                exit;

            }



            $errors[] = 'Your session expired. Refresh the page and try again.';

        }

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



    /* Select requested assignment only if it belongs to this staff member */

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



        /* Ticket statistics for the assigned event */

        $stmt = $pdo->prepare(

            "SELECT

                COUNT(it.id) AS total_tickets,

                SUM(CASE WHEN it.status = 'used' THEN 1 ELSE 0 END) AS used_tickets,

                SUM(CASE WHEN it.status = 'valid' THEN 1 ELSE 0 END) AS valid_tickets,

                SUM(CASE

                    WHEN it.status IN ('cancelled', 'refunded') THEN 1

                    ELSE 0

                END) AS invalid_tickets

             FROM issued_tickets it

             INNER JOIN order_items oi ON oi.id = it.order_item_id

             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id

             WHERE tt.event_id = :event_id"

        );



        $stmt->execute(['event_id' => $eventId]);

        $ticketStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



        $stats['total'] = (int) ($ticketStats['total_tickets'] ?? 0);

        $stats['valid'] = (int) ($ticketStats['used_tickets'] ?? 0);

        $stats['pending'] = (int) ($ticketStats['valid_tickets'] ?? 0);

        $stats['invalid'] = (int) ($ticketStats['invalid_tickets'] ?? 0);



        /* Recent check-ins */

        $stmt = $pdo->prepare(

            "SELECT

                it.id,

                it.ticket_code,

                it.attendee_name,

                it.checked_in_at,

                tt.name AS ticket_name

             FROM issued_tickets it

             INNER JOIN order_items oi ON oi.id = it.order_item_id

             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id

             WHERE tt.event_id = :event_id

               AND it.status = 'used'

               AND it.checked_in_at IS NOT NULL

             ORDER BY it.checked_in_at DESC

             LIMIT 5"

        );



        $stmt->execute(['event_id' => $eventId]);

        $recentCheckins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    }



} catch (Throwable $e) {

    error_log('Ticketa staff dashboard error: ' . $e->getMessage());

    $errors[] = 'Some dashboard information could not be loaded. Check the PHP error log.';

}



$staffName = (string) ($staff['full_name'] ?? 'Staff Member');

$staffRole = (string) ($staff['role'] ?? 'checkin_staff');

$csrfToken = (string) ($_SESSION['staff_dashboard_csrf'] ?? '');



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



$shiftStatus = (string) ($selectedAssignment['assignment_status'] ?? 'scheduled');

$now = time();



if ($shiftStatus === 'completed') {

    $shiftLabel = 'Completed';

} elseif (

    $shiftStart &&

    $shiftEnd &&

    strtotime((string) $shiftStart) <= $now &&

    strtotime((string) $shiftEnd) >= $now

) {

    $shiftLabel = 'Now Active';

} elseif ($shiftStatus === 'active') {

    $shiftLabel = 'Active';

} elseif ($shiftStart && strtotime((string) $shiftStart) > $now) {

    $shiftLabel = 'Upcoming';

} else {

    $shiftLabel = ucfirst($shiftStatus);

}



$validPercent = $stats['total'] > 0

    ? round(($stats['valid'] / $stats['total']) * 100, 1)

    : 0;



$pendingPercent = $stats['total'] > 0

    ? round(($stats['pending'] / $stats['total']) * 100, 1)

    : 0;



$invalidPercent = $stats['total'] > 0

    ? round(($stats['invalid'] / $stats['total']) * 100, 1)

    : 0;

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">



<title>Staff Dashboard | TICKETA</title>



<style>

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



* {

    box-sizing: border-box;

}



html {

    min-height: 100%;

    scroll-padding-top: calc(var(--topbar-height) + 15px);

}



body {

    margin: 0;

    min-height: 100vh;

    background: var(--page);

    color: var(--ink);

    font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI",

                 Roboto, Arial, sans-serif;

    font-size: 14px;

}



button, input {

    font: inherit;

}



button, a {

    -webkit-tap-highlight-color: transparent;

}



a {

    color: inherit;

    text-decoration: none;

}



button {

    cursor: pointer;

}



[hidden] {

    display: none !important;

}



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



.nav {

    display: grid;

    flex: 0 0 auto;

    gap: 8px;

}



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



.nav-link:hover {

    background: #f1faf8;

    color: var(--teal-dark);

}



.nav-link.active {

    background: var(--teal);

    color: white;

    box-shadow: 0 3px 8px #10bea022;

}



.nav-icon {

    display: inline-flex;

    width: 22px;

    flex: 0 0 22px;

    align-items: center;

    justify-content: center;

    font-size: 19px;

}



.nav-text {

    min-width: 0;

    overflow-wrap: anywhere;

}



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



.profile-details {

    display: flex;

    min-width: 0;

    align-items: center;

    gap: 10px;

}



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



.profile-copy {

    min-width: 0;

}



.profile-copy strong {

    display: block;

    overflow: hidden;

    font-size: 13px;

    font-weight: 600;

    text-overflow: ellipsis;

    white-space: nowrap;

}



.profile-copy small {

    display: block;

    margin-top: 4px;

    color: var(--muted);

    font-size: 12px;

}



.online-status {

    display: flex;

    align-items: center;

    gap: 7px;

    margin: 10px 0 9px 48px;

    color: #62676d;

    font-size: 12px;

}



.online-dot {

    width: 8px;

    height: 8px;

    flex: 0 0 8px;

    border-radius: 50%;

    background: var(--teal);

}



.logout-form {

    margin: 0;

    padding-top: 8px;

    border-top: 1px solid #e5e6e8;

}



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



.logout-button:hover {

    color: #d33;

}



/* MAIN AREA */

.main {

    min-width: 0;

    min-height: 100vh;

    margin-left: var(--sidebar-width);

}



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



.event-heading {

    display: flex;

    min-width: 0;

    align-items: center;

    gap: 15px;

}



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



.event-poster img {

    display: block;

    width: 100%;

    height: 100%;

    object-fit: cover;

    object-position: center;

}



.event-copy {

    min-width: 0;

}



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



.event-meta .separator {

    color: var(--teal);

}



.assignment-meta {

    display: flex;

    flex: 0 0 auto;

    align-items: center;

    border: 1px solid #dedfe3;

    border-radius: 15px;

    background: white;

}



.assignment-meta-item {

    display: flex;

    min-width: 145px;

    align-items: center;

    gap: 10px;

    padding: 12px 14px;

}



.assignment-meta-item + .assignment-meta-item {

    border-left: 1px solid #e3e4e7;

}



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



.assignment-meta small {

    display: block;

    margin-bottom: 4px;

    color: var(--muted);

    font-size: 11px;

}



.assignment-meta strong {

    display: block;

    max-width: 145px;

    overflow-wrap: anywhere;

    font-size: 13px;

    font-weight: 500;

}



/* CONTENT MUST START BELOW FIXED TOPBAR */

.content {

    padding: calc(var(--topbar-height) + 22px) 25px 24px;

}



.notice {

    margin-bottom: 15px;

    padding: 12px 15px;

    border-radius: 8px;

    background: #fff0f0;

    color: #a32929;

    overflow-wrap: anywhere;

}



/* DASHBOARD COLUMNS */

.dashboard-grid {

    display: grid;

    grid-template-columns: minmax(0, 1.12fr) minmax(300px, .88fr);

    gap: 22px;

    align-items: start;

}



.column {

    display: grid;

    min-width: 0;

    gap: 20px;

}



.panel {

    min-width: 0;

    padding: 20px;

    border: 1px solid #f0f0f2;

    border-radius: 13px;

    background: white;

    box-shadow: 0 3px 7px #161b2d12, 0 7px 14px #161b2d08;

}



.panel-heading {

    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 12px;

    margin-bottom: 15px;

}



.panel-heading h2 {

    margin: 0;

    font-size: 17px;

    font-weight: 600;

    letter-spacing: -.3px;

}



.panel-heading p {

    margin: 6px 0 0;

    color: var(--muted);

    font-size: 13px;

    line-height: 1.5;

}



.text-link {

    flex: 0 0 auto;

    color: #48bda9;

    font-size: 12px;

}



.text-link:hover {

    color: var(--teal-dark);

}



/* SCANNER PREVIEW */

.theme-button {

    padding: 7px 11px;

    border: 1px solid #dedfe3;

    border-radius: 6px;

    background: white;

    color: #45484e;

    font-size: 12px;

    white-space: nowrap;

}



.scanner-box {

    position: relative;

    display: flex;

    min-height: 260px;

    align-items: center;

    justify-content: center;

    overflow: hidden;

    padding: 25px;

    border-radius: 15px;

    background: #202020;

    color: white;

    text-align: center;

    transition: background .2s, color .2s;

}



.scanner-box:before,

.scanner-box:after,

.scanner-corner-left-bottom,

.scanner-corner-right-bottom {

    position: absolute;

    width: 42px;

    height: 42px;

    border-color: var(--teal);

    border-style: solid;

    content: "";

}



.scanner-box:before {

    top: 26px;

    left: 27px;

    border-width: 2px 0 0 2px;

    border-radius: 15px 0 0;

}



.scanner-box:after {

    top: 26px;

    right: 27px;

    border-width: 2px 2px 0 0;

    border-radius: 0 15px 0 0;

}



.scanner-corner-left-bottom {

    bottom: 26px;

    left: 27px;

    border-width: 0 0 2px 2px;

    border-radius: 0 0 0 15px;

}



.scanner-corner-right-bottom {

    right: 27px;

    bottom: 26px;

    border-width: 0 2px 2px 0;

    border-radius: 0 0 15px;

}



.scanner-inner {

    position: relative;

    z-index: 1;

    max-width: 300px;

}



.qr-icon {

    display: flex;

    width: 70px;

    height: 70px;

    align-items: center;

    justify-content: center;

    margin: 0 auto 15px;

    border-radius: 15px;

    background: #ffffff14;

    font-size: 42px;

}



.scanner-inner h3 {

    margin: 0 0 8px;

    font-size: 16px;

    font-weight: 500;

}



.scanner-inner p {

    margin: 0 0 17px;

    color: #d2d4d8;

    font-size: 12px;

    line-height: 1.6;

}



.primary-button {

    display: inline-flex;

    min-height: 42px;

    align-items: center;

    justify-content: center;

    gap: 9px;

    padding: 0 18px;

    border: 0;

    border-radius: 8px;

    background: var(--teal);

    color: white;

    font-weight: 500;

}



.primary-button:hover {

    background: var(--teal-dark);

}



.scanner-help {

    margin-top: 15px;

    color: #555a60;

    text-align: center;

    font-size: 12px;

}



.scanner-help a {

    color: #45bda7;

    font-weight: 500;

}



/* OVERVIEW CARDS */

.stats-grid {

    display: grid;

    grid-template-columns: repeat(4, minmax(0, 1fr));

    gap: 9px;

}



.stat-card {

    min-width: 0;

    min-height: 135px;

    padding: 12px 10px;

    border: 1px solid #dfe2e8;

    border-radius: 11px;

}



.stat-card.teal { background: #ddf8f3; }

.stat-card.blue { background: #e5edff; }

.stat-card.yellow { background: #fbf2dc; }

.stat-card.red { background: #ffe4e4; }



.stat-icon {

    margin-bottom: 9px;

    font-size: 22px;

}



.stat-card.teal .stat-icon,

.stat-card.teal .stat-foot { color: #19bca1; }



.stat-card.blue .stat-icon,

.stat-card.blue .stat-foot { color: #5489ed; }



.stat-card.yellow .stat-icon,

.stat-card.yellow .stat-foot { color: #d6a21e; }



.stat-card.red .stat-icon,

.stat-card.red .stat-foot { color: #ed5353; }



.stat-number {

    display: block;

    overflow-wrap: anywhere;

    font-size: clamp(19px, 1.8vw, 25px);

    font-weight: 500;

    line-height: 1.2;

}



.stat-label {

    display: block;

    margin-top: 7px;

    color: #6f747b;

    font-size: 11px;

    line-height: 1.45;

}



.stat-foot {

    display: block;

    margin-top: 9px;

    font-size: 10px;

}



/* RECENT CHECK-INS */

.checkin-list {

    display: grid;

}



.checkin-row {

    display: flex;

    min-width: 0;

    align-items: center;

    gap: 10px;

    padding: 12px 0;

    border-bottom: 1px solid #eceef0;

}



.checkin-row:last-child {

    border-bottom: 0;

    padding-bottom: 0;

}



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



.attendee-info {

    min-width: 0;

    flex: 1;

}



.attendee-info strong {

    display: block;

    overflow: hidden;

    font-size: 13px;

    font-weight: 500;

    text-overflow: ellipsis;

    white-space: nowrap;

}



.attendee-info small {

    display: block;

    margin-top: 5px;

    overflow-wrap: anywhere;

    color: #8a8e95;

    font-size: 10px;

}



.checkin-time {

    flex: 0 0 auto;

    color: #777b82;

    font-size: 10px;

    text-align: right;

}



.checkin-time span {

    display: block;

    margin-top: 7px;

    color: var(--teal);

    font-size: 15px;

}



.empty-state {

    padding: 28px 10px;

    color: var(--muted);

    text-align: center;

    font-size: 13px;

    line-height: 1.7;

}



/* QUICK ACTIONS */

.quick-grid {

    display: grid;

    grid-template-columns: repeat(3, minmax(0, 1fr));

    gap: 10px;

}



.quick-action {

    display: flex;

    min-width: 0;

    min-height: 90px;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    gap: 9px;

    padding: 10px 5px;

    border: 1px solid #dce0e5;

    border-radius: 11px;

    text-align: center;

    transition: transform .2s ease, box-shadow .2s ease;

}



.quick-action:hover {

    transform: translateY(-2px);

    box-shadow: 0 4px 12px #0000000d;

}



.quick-icon {

    font-size: 27px;

    line-height: 1;

}



.quick-action span:last-child {

    font-size: 11px;

    line-height: 1.4;

}



.quick-action.teal { background: #ddf8f3; color: #16b99f; }

.quick-action.blue { background: #e5edff; color: #5489ed; }

.quick-action.purple { background: #eee5ff; color: #8959ec; }

.quick-action.gray { background: #f0f0f0; color: #777b82; }

.quick-action.yellow { background: #fbf2dc; color: #d6a21e; }

.quick-action.red { background: #ffe4e4; color: #ed5353; }



/* ASSIGNMENT FOOTER */

.connection-bar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-top: 20px;

    padding: 17px 20px;

    border: 1px solid #f0f0f2;

    border-radius: 12px;

    background: white;

    box-shadow: 0 3px 7px #161b2d12;

}



.connection-message {

    display: flex;

    min-width: 0;

    align-items: center;

    gap: 12px;

}



.security-icon {

    display: flex;

    width: 36px;

    height: 36px;

    flex: 0 0 36px;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: #e0f8ef;

    color: #11ae8e;

    font-size: 18px;

}



.connection-message strong {

    display: block;

    font-size: 13px;

    font-weight: 500;

}



.connection-message small {

    display: block;

    margin-top: 4px;

    color: var(--muted);

    font-size: 11px;

    line-height: 1.5;

}



.connection-status {

    flex: 0 0 auto;

    color: #51a976;

    font-size: 11px;

    text-align: right;

}



.connection-status span {

    display: block;

    margin-top: 4px;

    color: #888d94;

    font-size: 10px;

}



/* EMPTY ASSIGNMENT */

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



.empty-symbol {

    margin-bottom: 14px;

    color: var(--teal);

    font-size: 40px;

}



.no-assignment h2 {

    margin: 0 0 10px;

    font-size: 22px;

}



.no-assignment p {

    margin: 0;

    color: var(--muted);

    line-height: 1.7;

}



/* SHORT LAPTOP SCREENS */

@media (max-height: 760px) and (min-width: 1051px) {

    .sidebar {

        padding-top: 13px;

        padding-bottom: 10px;

    }



    .brand {

        margin-bottom: 20px;

    }



    .nav {

        gap: 5px;

    }



    .nav-link {

        min-height: 37px;

        padding-top: 7px;

        padding-bottom: 7px;

    }



    .profile-card {

        padding: 10px;

    }



    .online-status {

        margin-top: 6px;

        margin-bottom: 6px;

    }



    .panel {

        padding: 16px;

    }



    .column {

        gap: 15px;

    }



    .dashboard-grid {

        gap: 16px;

    }



    .scanner-box {

        min-height: 235px;

    }



    .stat-card {

        min-height: 125px;

        padding: 10px 8px;

    }



    .quick-action {

        min-height: 80px;

    }

}



/* MEDIUM DESKTOP */

@media (max-width: 1350px) and (min-width: 1051px) {

    :root {

        --sidebar-width: 220px;

        --topbar-height: 105px;

    }



    .sidebar {

        padding-right: 12px;

        padding-left: 12px;

    }



    .topbar {

        padding-right: 17px;

        padding-left: 17px;

    }



    .content {

        padding-right: 17px;

        padding-left: 17px;

    }



    .dashboard-grid {

        grid-template-columns: minmax(0, 1fr) minmax(275px, .88fr);

        gap: 15px;

    }



    .assignment-meta-item {

        min-width: 115px;

        padding: 9px;

        gap: 7px;

    }



    .meta-icon {

        width: 30px;

        height: 30px;

        flex-basis: 30px;

    }



    .stats-grid {

        grid-template-columns: repeat(2, minmax(0, 1fr));

    }



    .stat-card {

        min-height: 115px;

    }

}



/* TABLET */

@media (max-width: 1050px) {

    :root {

        --sidebar-width: 0px;

        --topbar-height: 0px;

    }



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



    .brand {

        margin-bottom: 14px;

    }



    .nav {

        display: flex;

        flex-wrap: wrap;

        gap: 6px;

    }



    .nav-link {

        min-height: 38px;

        padding: 8px 10px;

    }



    .profile-card {

        display: flex;

        align-items: center;

        justify-content: space-between;

        gap: 15px;

        margin-top: 12px;

        padding: 9px 12px;

    }



    .online-status {

        margin: 0 auto 0 0;

    }



    .logout-form {

        padding: 0;

        border-top: 0;

    }



    .logout-button {

        width: auto;

        padding: 8px;

    }



    .main {

        margin-left: 0;

    }



    .topbar {

        position: relative;

        top: auto;

        right: auto;

        left: auto;

        min-height: auto;

        flex-wrap: wrap;

        padding: 17px;

    }



    .content {

        padding: 17px;

    }



    .dashboard-grid {

        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

    }



    .assignment-meta {

        max-width: 100%;

    }



    .assignment-meta-item {

        min-width: 0;

        flex: 1;

    }

}



/* MOBILE */

@media (max-width: 760px) {

    .topbar {

        align-items: flex-start;

        flex-direction: column;

    }



    .assignment-meta {

        width: 100%;

    }



    .dashboard-grid {

        grid-template-columns: minmax(0, 1fr);

    }



    .content {

        padding: 14px;

    }



    .panel {

        padding: 16px;

    }



    .scanner-box {

        min-height: 235px;

    }



    .connection-bar {

        align-items: flex-start;

        flex-direction: column;

    }



    .connection-status {

        padding-left: 48px;

        text-align: left;

    }

}



@media (max-width: 480px) {

    .sidebar {

        padding: 12px;

    }



    .brand {

        font-size: 21px;

    }



    .nav {

        display: grid;

        grid-template-columns: repeat(2, minmax(0, 1fr));

    }



    .nav-link {

        font-size: 12px;

    }



    .profile-card {

        flex-wrap: wrap;

    }



    .online-status {

        margin-left: 0;

    }



    .event-heading {

        align-items: flex-start;

        gap: 11px;

    }



    .event-poster {

        width: 52px;

        height: 62px;

        flex-basis: 52px;

    }



    .assignment-meta-item {

        gap: 7px;

        padding: 9px;

    }



    .assignment-meta strong {

        font-size: 11px;

    }



    .stats-grid {

        gap: 8px;

    }



    .stat-card {

        min-height: 120px;

    }



    .quick-grid {

        gap: 8px;

    }



    .quick-action {

        min-height: 82px;

    }

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



            <div class="dashboard-grid">



                <div class="column">



                    <section class="panel">

                        <div class="panel-heading">

                            <div>

                                <h2>Scan Ticket</h2>

                                <p>Scan a QR code to check in an attendee</p>

                            </div>



                            <button

                                class="theme-button"

                                id="themeToggle"

                                type="button"

                            >☼ Light</button>

                        </div>



                        <div class="scanner-box" id="scannerBox">

                            <span class="scanner-corner-left-bottom"></span>

                            <span class="scanner-corner-right-bottom"></span>



                            <div class="scanner-inner">

                                <div class="qr-icon">▦</div>

                                <h3>Ready to scan tickets</h3>

                                <p>Open the check-in screen to start scanning.</p>



                                <a

                                    class="primary-button"

                                    href="checkin.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>"

                                >

                                    <span>▦</span>

                                    Open Scanner →

                                </a>

                            </div>

                        </div>



                        <div class="scanner-help">

                            Having trouble scanning?

                            <a href="manual-checkin.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                Try manual check-in

                            </a>

                        </div>

                    </section>



                    <section class="panel">

                        <div class="panel-heading">

                            <div>

                                <h2>Today's Overview</h2>

                                <p>Ticket activity for your assigned event</p>

                            </div>



                            <a

                                class="text-link"

                                href="todays-checkins.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>"

                            >View Full Report</a>

                        </div>



                        <div class="stats-grid">

                            <article class="stat-card teal">

                                <div class="stat-icon">♧</div>

                                <strong class="stat-number"><?= number_format($stats['total']) ?></strong>

                                <span class="stat-label">Total Tickets</span>

                                <span class="stat-foot">Assigned event</span>

                            </article>



                            <article class="stat-card blue">

                                <div class="stat-icon">✓</div>

                                <strong class="stat-number"><?= number_format($stats['valid']) ?></strong>

                                <span class="stat-label">Valid Check-Ins</span>

                                <span class="stat-foot"><?= number_format($validPercent, 1) ?>% of tickets</span>

                            </article>



                            <article class="stat-card yellow">

                                <div class="stat-icon">◷</div>

                                <strong class="stat-number"><?= number_format($stats['pending']) ?></strong>

                                <span class="stat-label">Pending Check-Ins</span>

                                <span class="stat-foot"><?= number_format($pendingPercent, 1) ?>% of tickets</span>

                            </article>



                            <article class="stat-card red">

                                <div class="stat-icon">⚠</div>

                                <strong class="stat-number"><?= number_format($stats['invalid']) ?></strong>

                                <span class="stat-label">Invalid Tickets</span>

                                <span class="stat-foot"><?= number_format($invalidPercent, 1) ?>% of tickets</span>

                            </article>

                        </div>

                    </section>



                </div>



                <div class="column">



                    <section class="panel">

                        <div class="panel-heading">

                            <div>

                                <h2>Recent Check-Ins</h2>

                                <p>Latest attendees checked in</p>

                            </div>



                            <a

                                class="text-link"

                                href="todays-checkins.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>"

                            >View All</a>

                        </div>



                        <div class="checkin-list">

                            <?php if (!$recentCheckins): ?>



                                <div class="empty-state">

                                    <div style="font-size:28px;margin-bottom:8px">◷</div>

                                    No check-ins recorded yet.<br>

                                    Recent attendees will appear here.

                                </div>



                            <?php else: ?>



                                <?php foreach ($recentCheckins as $checkin): ?>

                                    <?php

                                    $attendeeName = (string) (

                                        $checkin['attendee_name'] ?: 'Attendee'

                                    );

                                    ?>



                                    <div class="checkin-row">

                                        <span class="attendee-avatar">

                                            <?= staffEscape(staffInitials($attendeeName)) ?>

                                        </span>



                                        <div class="attendee-info">

                                            <strong><?= staffEscape($attendeeName) ?></strong>

                                            <small>

                                                <?= staffEscape($checkin['ticket_name'] ?? 'Event Ticket') ?>

                                                &nbsp; • &nbsp;

                                                <?= staffEscape($checkin['ticket_code'] ?? '') ?>

                                            </small>

                                        </div>



                                        <div class="checkin-time">

                                            <?= staffEscape(staffDate($checkin['checked_in_at'], 'g:i A')) ?>

                                            <span>●</span>

                                        </div>

                                    </div>

                                <?php endforeach; ?>



                            <?php endif; ?>

                        </div>

                    </section>



                    <section class="panel">

                        <div class="panel-heading">

                            <div>

                                <h2>Quick Actions</h2>

                                <p>Access your check-in tools</p>

                            </div>

                        </div>



                        <div class="quick-grid">

                            <a class="quick-action teal" href="checkin.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                <span class="quick-icon">▦</span>

                                <span>Scan Ticket</span>

                            </a>



                            <a class="quick-action blue" href="manual-checkin.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                <span class="quick-icon">♙</span>

                                <span>Manual Check-In</span>

                            </a>



                            <a class="quick-action purple" href="search-attendee.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                <span class="quick-icon">⌕</span>

                                <span>Search Attendee</span>

                            </a>



                            <a class="quick-action gray" href="vip-list.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                <span class="quick-icon">☷</span>

                                <span>VIP List</span>

                            </a>



                            <a class="quick-action yellow" href="todays-checkins.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                <span class="quick-icon">▣</span>

                                <span>Today's Check-Ins</span>

                            </a>



                            <a class="quick-action red" href="invalid-tickets.php?assignment_id=<?= (int) $selectedAssignment['assignment_id'] ?>">

                                <span class="quick-icon">⊘</span>

                                <span>Invalid Tickets</span>

                            </a>

                        </div>

                    </section>



                </div>

            </div>



            <div class="connection-bar">

                <div class="connection-message">

                    <span class="security-icon">♙</span>

                    <div>

                        <strong>

                            You are checking in for

                            <span style="color:#43bba6"><?= staffEscape($gateName) ?></span>

                        </strong>

                        <small>

                            Ensure every ticket is scanned and validated according

                            to your event's entry guidelines.

                        </small>

                    </div>

                </div>



                <div class="connection-status">

                    <strong>● Assignment loaded</strong>

                    <span>Session: <?= staffEscape($shiftLabel) ?></span>

                </div>

            </div>



        <?php endif; ?>



    </section>

</main>



<script>

(function () {

    const themeToggle = document.getElementById('themeToggle');

    const scannerBox = document.getElementById('scannerBox');



    if (themeToggle && scannerBox) {

        let dark = true;



        themeToggle.addEventListener('click', function () {

            dark = !dark;



            if (dark) {

                scannerBox.style.background = '#202020';

                scannerBox.style.color = '#ffffff';

                themeToggle.textContent = '☼ Light';

            } else {

                scannerBox.style.background = '#f0f4f7';

                scannerBox.style.color = '#202124';

                themeToggle.textContent = '☾ Dark';

            }



            scannerBox.querySelectorAll('.scanner-inner p').forEach(function (p) {

                p.style.color = dark ? '#d2d4d8' : '#606771';

            });

        });

    }

})();

</script>



</body>

</html>
