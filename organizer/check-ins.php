<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!authIsLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'] ?? [];

if (($user['role'] ?? '') !== 'organizer') {
    header('Location: ../index.php');
    exit;
}

$organizerName = $user['full_name'] ?? 'Organizer';
$currentPage = 'check-ins.php';
$topbarSearchPlaceholder = 'Search check-ins';

if (empty($_SESSION['checkin_csrf'])) {
    $_SESSION['checkin_csrf'] = bin2hex(random_bytes(32));
}

function checkinEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function checkinNumber($value): string
{
    return number_format((int) $value);
}

$organizerId = null;
$events = [];
$ticketTypes = [];
$activity = [];
$selectedEventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$selectedEventId = $selectedEventId ?: null;
$message = '';
$messageType = 'success';
$errorMessage = '';

$summary = [
    'sold' => 0,
    'checked_in' => 0,
    'remaining' => 0,
    'rate' => 0,
];

$methodStats = [
    'qr' => 0,
    'manual' => 0,
];

try {
    $profileStmt = $pdo->prepare(
        'SELECT id, business_name
         FROM organizers
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $profileStmt->execute(['user_id' => $user['id']]);
    $organizer = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$organizer) {
        throw new RuntimeException(
            'No organizer profile is linked to your account.'
        );
    }

    $organizerId = (int) $organizer['id'];

    if (!empty($organizer['business_name'])) {
        $organizerName = $organizer['business_name'];
    }

    $eventsStmt = $pdo->prepare(
        'SELECT id, title, event_date, status
         FROM events
         WHERE organizer_id = :organizer_id
         ORDER BY event_date DESC'
    );

    $eventsStmt->execute(['organizer_id' => $organizerId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $validEventIds = array_map(
        static fn($event) => (int) $event['id'],
        $events
    );

    if (
        $selectedEventId !== null &&
        !in_array((int) $selectedEventId, $validEventIds, true)
    ) {
        $selectedEventId = null;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';
        $ticketCode = trim((string) ($_POST['ticket_code'] ?? ''));
        $postedEventId = filter_input(
            INPUT_POST,
            'event_id',
            FILTER_VALIDATE_INT
        );

        if (
            !is_string($postedToken) ||
            !hash_equals($_SESSION['checkin_csrf'], $postedToken)
        ) {
            $message = 'Your session verification failed. Refresh the page and try again.';
            $messageType = 'error';
        } elseif ($ticketCode === '' || strlen($ticketCode) > 100) {
            $message = 'Enter a valid ticket code.';
            $messageType = 'error';
        } else {
            $pdo->beginTransaction();

            try {
                $ticketStmt = $pdo->prepare(
                    "SELECT
                        it.id,
                        it.ticket_code,
                        it.attendee_name,
                        it.status AS ticket_status,
                        oi.ticket_name,
                        o.id AS order_id,
                        o.status AS order_status,
                        e.id AS event_id,
                        e.title AS event_title
                     FROM issued_tickets it
                     INNER JOIN order_items oi
                        ON oi.id = it.order_item_id
                     INNER JOIN orders o
                        ON o.id = oi.order_id
                     INNER JOIN events e
                        ON e.id = o.event_id
                     INNER JOIN organizers org
                        ON org.id = e.organizer_id
                     WHERE it.ticket_code = :ticket_code
                       AND org.id = :organizer_id
                     LIMIT 1
                     FOR UPDATE"
                );

                $ticketStmt->execute([
                    'ticket_code' => $ticketCode,
                    'organizer_id' => $organizerId,
                ]);

                $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

                if (!$ticket) {
                    $pdo->rollBack();
                    $message = 'Ticket not found in your events. Check the code and try again.';
                    $messageType = 'error';
                } elseif (
                    $postedEventId &&
                    (int) $ticket['event_id'] !== (int) $postedEventId
                ) {
                    $pdo->rollBack();
                    $message = 'This ticket belongs to a different event than the selected event.';
                    $messageType = 'error';
                } elseif (!in_array($ticket['order_status'], ['paid', 'free'], true)) {
                    $pdo->rollBack();
                    $message = 'This ticket cannot be checked in because its order is not confirmed.';
                    $messageType = 'error';
                } elseif ($ticket['ticket_status'] === 'used') {
                    $pdo->rollBack();
                    $message = 'This ticket has already been checked in.';
                    $messageType = 'error';
                } elseif ($ticket['ticket_status'] !== 'valid') {
                    $pdo->rollBack();
                    $message = 'This ticket is not valid for check-in.';
                    $messageType = 'error';
                } else {
                    $updateStmt = $pdo->prepare(
                        "UPDATE issued_tickets
                         SET status = 'used',
                             checked_in_at = NOW()
                         WHERE id = :ticket_id
                           AND status = 'valid'"
                    );

                    $updateStmt->execute([
                        'ticket_id' => $ticket['id'],
                    ]);

                    if ($updateStmt->rowCount() !== 1) {
                        $pdo->rollBack();
                        $message = 'This ticket was just checked in by another action. Refresh and verify its status.';
                        $messageType = 'error';
                    } else {
                        $pdo->commit();

                        $message = 'Check-in successful for '
                            . ($ticket['attendee_name'] ?: 'the attendee')
                            . ' — '
                            . $ticket['event_title']
                            . '.';

                        $messageType = 'success';
                        $selectedEventId = (int) $ticket['event_id'];
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        }
    }

    $eventCondition = '';
    $params = ['organizer_id' => $organizerId];

    if ($selectedEventId !== null) {
        $eventCondition = ' AND e.id = :event_id';
        $params['event_id'] = $selectedEventId;
    }

    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS sold,
            COALESCE(SUM(it.status = 'used'), 0) AS checked_in,
            COALESCE(SUM(it.status = 'valid'), 0) AS remaining
         FROM issued_tickets it
         INNER JOIN order_items oi
            ON oi.id = it.order_item_id
         INNER JOIN orders o
            ON o.id = oi.order_id
         INNER JOIN events e
            ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
           AND o.status IN ('paid', 'free')
           AND it.status IN ('valid', 'used')
           $eventCondition"
    );

    $summaryStmt->execute($params);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $summary['sold'] = (int) ($summaryData['sold'] ?? 0);
    $summary['checked_in'] = (int) ($summaryData['checked_in'] ?? 0);
    $summary['remaining'] = (int) ($summaryData['remaining'] ?? 0);

    $summary['rate'] = $summary['sold'] > 0
        ? round(($summary['checked_in'] / $summary['sold']) * 100, 1)
        : 0;

    $ticketTypeStmt = $pdo->prepare(
        "SELECT
            oi.ticket_type_id,
            oi.ticket_name,
            COUNT(it.id) AS issued_count,
            COALESCE(SUM(it.status = 'used'), 0) AS checked_count,
            COALESCE(SUM(it.status = 'valid'), 0) AS remaining_count
         FROM issued_tickets it
         INNER JOIN order_items oi
            ON oi.id = it.order_item_id
         INNER JOIN orders o
            ON o.id = oi.order_id
         INNER JOIN events e
            ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
           AND o.status IN ('paid', 'free')
           AND it.status IN ('valid', 'used')
           $eventCondition
         GROUP BY oi.ticket_type_id, oi.ticket_name
         ORDER BY issued_count DESC, oi.ticket_name ASC"
    );

    $ticketTypeStmt->execute($params);
    $ticketTypes = $ticketTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    $activityStmt = $pdo->prepare(
        "SELECT
            it.id,
            it.ticket_code,
            it.attendee_name,
            it.checked_in_at,
            it.status AS ticket_status,
            oi.ticket_name,
            e.title AS event_title
         FROM issued_tickets it
         INNER JOIN order_items oi
            ON oi.id = it.order_item_id
         INNER JOIN orders o
            ON o.id = oi.order_id
         INNER JOIN events e
            ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
           AND o.status IN ('paid', 'free')
           AND it.status IN ('valid', 'used')
           $eventCondition
         ORDER BY
            CASE WHEN it.checked_in_at IS NULL THEN 1 ELSE 0 END,
            it.checked_in_at DESC,
            it.issued_at DESC
         LIMIT 100"
    );

    $activityStmt->execute($params);
    $activity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Ticketa check-ins error: ' . $e->getMessage());

    $errorMessage = 'We could not load check-in information. Please verify the database connection and table structure.';
}

$eventQuery = $selectedEventId !== null
    ? '&event_id=' . (int) $selectedEventId
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-Ins | TICKETA</title>

    <style>
        :root {
            --checkin-green: #10bea0;
            --checkin-green-dark: #08a98d;
            --checkin-yellow: #f5b900;
            --checkin-ink: #171c2e;
            --checkin-muted: #778094;
            --checkin-border: #e9ecef;
            --checkin-bg: #fafbfc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--checkin-bg);
            color: var(--checkin-ink);
            font-family: Inter, Arial, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 250px;
            padding-top: 90px;
        }

        .checkin-content {
            padding: 20px 32px 40px;
        }

        .checkin-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .checkin-heading h1 {
            margin: 0 0 8px;
            font-size: clamp(25px, 3vw, 32px);
            letter-spacing: -.6px;
        }

        .checkin-heading p {
            margin: 0;
            color: var(--checkin-muted);
            font-size: 16px;
        }

        .event-filter {
            min-width: 160px;
            max-width: 260px;
            padding: 12px 16px;
            border: 0;
            border-radius: 6px;
            background: var(--checkin-green);
            color: #fff;
            cursor: pointer;
        }

        .event-filter option {
            background: #fff;
            color: var(--checkin-ink);
        }

        .checkin-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .checkin-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            min-height: 102px;
            padding: 16px 14px;
            border: 1px solid var(--checkin-border);
            border-radius: 12px;
            background: #fff;
        }

        .checkin-stat-icon {
            display: grid;
            width: 50px;
            height: 50px;
            flex-shrink: 0;
            place-items: center;
            border-radius: 50%;
            background: var(--checkin-green);
            color: #fff;
            font-size: 23px;
            font-weight: 700;
        }

        .checkin-stat-label {
            margin-bottom: 8px;
            color: #454545;
            font-size: 14px;
            line-height: 1.4;
        }

        .checkin-stat-value {
            font-size: clamp(18px, 1.7vw, 24px);
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .checkin-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(300px, .9fr);
            gap: 20px;
            margin-bottom: 22px;
        }

        .checkin-panel {
            min-width: 0;
            overflow: hidden;
            border: 1px solid var(--checkin-border);
            border-radius: 12px;
            background: #fff;
        }

        .checkin-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 20px;
        }

        .checkin-panel-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .checkin-panel-header p {
            margin: 8px 0 0;
            color: #555;
            font-size: 14px;
        }

        .checkin-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .checkin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .checkin-table th {
            padding: 11px 12px;
            background: var(--checkin-green);
            color: #fff;
            font-weight: 500;
            text-align: left;
            white-space: nowrap;
        }

        .checkin-table td {
            padding: 16px 12px;
            border-bottom: 1px solid #f1f2f3;
            color: #414141;
            white-space: nowrap;
        }

        .checkin-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .method-content {
            display: grid;
            grid-template-columns: minmax(120px, 1fr) minmax(140px, 1fr);
            align-items: center;
            gap: 18px;
            padding: 4px 20px 24px;
        }

        .method-chart {
            display: grid;
            width: 100%;
            max-width: 180px;
            aspect-ratio: 1;
            place-items: center;
            margin: 0 auto;
            border-radius: 50%;
            background: conic-gradient(
                #d4d4d4 0deg 360deg
            );
            position: relative;
        }

        .method-chart::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: transparent;
        }

        .method-chart-inner {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            color: #fff;
            text-align: center;
            font-size: 13px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, .25);
        }

        .method-chart-inner strong {
            font-size: 23px;
        }

        .method-legend {
            display: grid;
            gap: 14px;
        }

        .method-legend-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 14px;
        }

        .method-legend-label {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .method-dot {
            width: 10px;
            height: 10px;
            flex-shrink: 0;
            border-radius: 50%;
        }

        .method-note {
            margin: 0;
            padding: 0 20px 20px;
            color: var(--checkin-muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .activity-panel {
            margin-bottom: 22px;
        }

        .activity-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            padding: 0 20px 18px;
        }

        .activity-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .activity-filters select {
            min-width: 155px;
            padding: 10px 12px;
            border: 1px solid #bdbdbd;
            border-radius: 6px;
            background: #fff;
            color: #333;
        }

        .activity-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .checkin-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border: 0;
            border-radius: 6px;
            background: var(--checkin-green);
            color: #fff;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        .checkin-button.manual {
            background: #efb92f;
            color: #fff;
        }

        .checkin-button:hover {
            filter: brightness(.96);
        }

        .ticket-checkin-form {
            display: none;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
            padding: 0 20px 20px;
        }

        .ticket-checkin-form.visible {
            display: flex;
        }

        .ticket-checkin-form label {
            display: grid;
            flex: 1;
            min-width: 180px;
            gap: 7px;
            color: #555;
            font-size: 13px;
        }

        .ticket-checkin-form input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7dadd;
            border-radius: 6px;
            font: inherit;
        }

        .ticket-checkin-form input:focus {
            outline: 2px solid rgba(16, 190, 160, .2);
            border-color: var(--checkin-green);
        }

        .checkin-result {
            margin: 0 20px 18px;
            padding: 13px 15px;
            border-radius: 7px;
            font-size: 14px;
            line-height: 1.5;
        }

        .checkin-result.success {
            background: #e9f7ef;
            color: #267447;
        }

        .checkin-result.error {
            background: #fff0f0;
            color: #a52b2b;
        }

        .scanner-ticket-details {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid currentColor;
            display: grid;
            gap: 5px;
        }

        .scanner-ticket-details p { margin: 0; }
        .scanner-ticket-details strong { font-weight: 700; }

        .ticket-status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 4px;
            background: #e9f7ef;
            color: #27824c;
            font-size: 12px;
        }

        .ticket-status.valid {
            background: #fff6dd;
            color: #987400;
        }

        .empty-state {
            padding: 28px 18px;
            color: var(--checkin-muted);
            text-align: center;
            font-size: 14px;
        }

        @media (max-width: 1250px) {
            .main {
                margin-left: 220px;
                padding-top: 90px;
            }

            .checkin-content {
                padding-right: 22px;
                padding-left: 22px;
            }

            .checkin-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .checkin-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                padding-top: 75px;
            }

            .checkin-content {
                padding: 22px 16px 32px;
            }

            .checkin-heading {
                align-items: stretch;
                flex-direction: column;
            }

            .event-filter {
                width: 100%;
                max-width: none;
            }

            .checkin-panel-header {
                padding: 16px;
            }

            .activity-toolbar {
                align-items: stretch;
                flex-direction: column;
                padding: 0 16px 16px;
            }

            .activity-actions {
                width: 100%;
            }

            .activity-actions .checkin-button {
                flex: 1;
            }

            .method-content {
                grid-template-columns: minmax(100px, .8fr) minmax(120px, 1fr);
                padding-right: 16px;
                padding-left: 16px;
            }
        }

        @media (max-width: 560px) {
            .checkin-stats {
                grid-template-columns: minmax(0, 1fr);
            }

            .checkin-stat {
                min-height: 85px;
            }

            .method-content {
                grid-template-columns: minmax(0, 1fr);
            }

            .method-chart {
                width: 155px;
            }

            .activity-filters {
                flex-direction: column;
            }

            .activity-filters select {
                width: 100%;
            }

            .activity-actions {
                flex-direction: column;
            }
        }
    

        /* QR scanner modal */
        .qr-scanner-modal[hidden] { display: none !important; }
        .qr-scanner-modal {
            position: fixed; inset: 0; z-index: 99999; display: grid;
            place-items: center; overflow-y: auto; padding: 20px;
            background: rgba(10, 15, 25, .78);
        }
        .qr-scanner-card {
            position: relative; width: min(100%, 520px); padding: 24px;
            border-radius: 16px; background: #fff;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        }
        .qr-scanner-card h2 { margin: 8px 35px 8px 0; }
        .qr-scanner-card p { color: #666; line-height: 1.5; }
        .qr-close {
            position: absolute; top: 15px; right: 15px; width: 36px; height: 36px;
            border: 0; border-radius: 50%; background: #f0f2f4; cursor: pointer;
            font-size: 18px;
        }
        #qrReader { width: 100%; margin: 18px 0; }
        #qrReader video { border-radius: 10px; }
        .qr-scanner-card > .checkin-button { width: 100%; }
        .qr-scanner-card .checkin-result { margin: 12px 0; }
        @media (max-width: 560px) {
            .qr-scanner-modal { padding: 10px; }
            .qr-scanner-card { padding: 20px 14px; }
        }

    </style>
</head>

<body>

<?php include dirname(__DIR__) . '/includes/organizer-sidebar.php'; ?>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/organizer-topbar.php'; ?>

    <main class="checkin-content">

        <header class="checkin-heading">
            <div>
                <h1>Check-Ins</h1>
                <p>Monitor event entry and manage guest check-ins</p>
            </div>

            <form method="get">
                <select
                    name="event_id"
                    class="event-filter"
                    aria-label="Filter by event"
                    onchange="this.form.submit()"
                >
                    <option value="">All Events</option>

                    <?php foreach ($events as $event): ?>
                        <option
                            value="<?= (int) $event['id'] ?>"
                            <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>
                        >
                            <?= checkinEscape($event['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </header>

        <?php if ($errorMessage !== ''): ?>
            <div class="checkin-result error">
                <?= checkinEscape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="checkin-result <?= checkinEscape($messageType) ?>">
                <?= checkinEscape($message) ?>
            </div>
        <?php endif; ?>

        <section class="checkin-stats">

            <article class="checkin-stat">
                <div class="checkin-stat-icon">▰</div>
                <div>
                    <div class="checkin-stat-label">Total Tickets Sold</div>
                    <div class="checkin-stat-value">
                        <?= checkinNumber($summary['sold']) ?>
                    </div>
                </div>
            </article>

            <article class="checkin-stat">
                <div class="checkin-stat-icon">✓</div>
                <div>
                    <div class="checkin-stat-label">Checked-In</div>
                    <div class="checkin-stat-value">
                        <?= checkinNumber($summary['checked_in']) ?>
                    </div>
                </div>
            </article>

            <article class="checkin-stat">
                <div class="checkin-stat-icon">☷</div>
                <div>
                    <div class="checkin-stat-label">Still to Check-In</div>
                    <div class="checkin-stat-value">
                        <?= checkinNumber($summary['remaining']) ?>
                    </div>
                </div>
            </article>

            <article class="checkin-stat">
                <div class="checkin-stat-icon">◷</div>
                <div>
                    <div class="checkin-stat-label">Check-In Rate</div>
                    <div class="checkin-stat-value">
                        <?= number_format($summary['rate'], 1) ?>%
                    </div>
                </div>
            </article>

        </section>

        <div class="checkin-grid">

            <section class="checkin-panel">
                <div class="checkin-panel-header">
                    <h2>Ticket Type Check-Ins</h2>
                </div>

                <?php if ($ticketTypes): ?>
                    <div class="checkin-table-wrap">
                        <table class="checkin-table">
                            <thead>
                                <tr>
                                    <th>Ticket Type</th>
                                    <th>Sold</th>
                                    <th>Check-In</th>
                                    <th>Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ticketTypes as $type): ?>
                                    <tr>
                                        <td><?= checkinEscape($type['ticket_name']) ?></td>
                                        <td><?= checkinNumber($type['issued_count']) ?></td>
                                        <td><?= checkinNumber($type['checked_count']) ?></td>
                                        <td><?= checkinNumber($type['remaining_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        No issued tickets are available for this event selection.
                    </div>
                <?php endif; ?>
            </section>

            <section class="checkin-panel">
                <div class="checkin-panel-header">
                    <div>
                        <h2>Check-In Method Breakdown</h2>
                        <p>Check-in method reporting</p>
                    </div>
                </div>

                <div class="method-content">
                    <div class="method-chart" aria-label="Check-in methods not yet recorded">
                        <div class="method-chart-inner">
                            <strong>—</strong>
                            <span>Not tracked</span>
                        </div>
                    </div>

                    <div class="method-legend">
                        <div class="method-legend-row">
                            <span class="method-legend-label">
                                <span class="method-dot" style="background:#f5b900"></span>
                                QR Scan
                            </span>
                            <strong>—</strong>
                        </div>

                        <div class="method-legend-row">
                            <span class="method-legend-label">
                                <span class="method-dot" style="background:#d4d4d4"></span>
                                Manual
                            </span>
                            <strong>—</strong>
                        </div>

                        <div class="method-legend-row">
                            <span>Checked in</span>
                            <strong><?= checkinNumber($summary['checked_in']) ?></strong>
                        </div>

                        <div class="method-legend-row">
                            <span>Not checked in</span>
                            <strong><?= checkinNumber($summary['remaining']) ?></strong>
                        </div>
                    </div>
                </div>

                <p class="method-note">
                    QR versus manual totals will appear when the database records
                    the check-in method for each ticket.
                </p>
            </section>

        </div>

        <section class="checkin-panel activity-panel">

            <div class="checkin-panel-header">
                <h2>Live Check-In Activity</h2>

                <div class="activity-actions">
                    <button
                        type="button"
                        class="checkin-button"
                        id="openQrCodeForm"
                    >▦ &nbsp; Scan QR Code</button>

                    <button
                        type="button"
                        class="checkin-button manual"
                        id="openManualForm"
                    >☷ &nbsp; Manual</button>
                </div>
            </div>

            <div class="activity-toolbar">
                <div class="activity-filters">
                    <select id="activityTimeFilter" aria-label="Filter activity by time">
                        <option value="all">All activity</option>
                        <option value="10">Last 10 minutes</option>
                        <option value="60">Last hour</option>
                        <option value="1440">Last 24 hours</option>
                    </select>

                    <select id="activityTicketFilter" aria-label="Filter by ticket type">
                        <option value="all">All Ticket Types</option>
                        <?php
                        $uniqueTicketNames = [];

                        foreach ($ticketTypes as $type) {
                            $uniqueTicketNames[$type['ticket_name']] = true;
                        }
                        ?>

                        <?php foreach (array_keys($uniqueTicketNames) as $ticketName): ?>
                            <option value="<?= checkinEscape($ticketName) ?>">
                                <?= checkinEscape($ticketName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <form
                method="post"
                class="ticket-checkin-form"
                id="ticketCheckinForm"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= checkinEscape($_SESSION['checkin_csrf']) ?>"
                >

                <input
                    type="hidden"
                    name="event_id"
                    value="<?= $selectedEventId !== null ? (int) $selectedEventId : '' ?>"
                >

                <label>
                    Ticket code
                    <input
                        type="text"
                        name="ticket_code"
                        id="ticketCodeInput"
                        maxlength="100"
                        placeholder="Enter or scan a ticket code"
                        autocomplete="off"
                        required
                    >
                </label>

                <button type="submit" class="checkin-button">
                    Confirm Check-In
                </button>
            </form>

            <?php if ($activity): ?>
                <div class="checkin-table-wrap">
                    <table class="checkin-table" id="activityTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Ticket Type</th>
                                <th>Event</th>
                                <th>Time Checked-In</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($activity as $item): ?>
                                <?php
                                $checkedIn = $item['ticket_status'] === 'used';
                                $timestamp = $item['checked_in_at']
                                    ? strtotime($item['checked_in_at'])
                                    : null;
                                ?>
                                <tr
                                    data-ticket-type="<?= checkinEscape($item['ticket_name']) ?>"
                                    data-time="<?= $timestamp ?: 0 ?>"
                                    data-checked-in="<?= $checkedIn ? '1' : '0' ?>"
                                >
                                    <td>
                                        <?= checkinEscape(
                                            $item['attendee_name'] ?: 'Guest'
                                        ) ?>
                                    </td>

                                    <td><?= checkinEscape($item['ticket_name']) ?></td>

                                    <td><?= checkinEscape($item['event_title']) ?></td>

                                    <td>
                                        <?= $timestamp
                                            ? checkinEscape(date('g:i A', $timestamp))
                                            : '—'
                                        ?>
                                    </td>

                                    <td>
                                        <?php if ($checkedIn): ?>
                                            <span class="ticket-status">Checked-In</span>
                                        <?php else: ?>
                                            <span class="ticket-status valid">Not checked in</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    No issued tickets found yet. Once ticket orders have issued tickets,
                    attendee activity will appear here.
                </div>
            <?php endif; ?>

        </section>



        <div id="qrScannerModal" class="qr-scanner-modal" hidden>
            <section class="qr-scanner-card" role="dialog" aria-modal="true" aria-labelledby="qrScannerTitle">
                <button type="button" id="closeQrScanner" class="qr-close" aria-label="Close scanner">✕</button>
                <h2 id="qrScannerTitle">Scan Ticket QR Code</h2>
                <p>Position the attendee's QR code inside the camera frame.</p>
                <div id="qrReader"></div>
                <div id="qrScanResult" class="checkin-result" role="status" aria-live="polite" hidden></div>
                <button type="button" id="stopQrScanner" class="checkin-button manual">Stop Scanner</button>
            </section>
        </div>

    </main>
</div>

<script src="https://unpkg.com/html5-qrcode" defer></script>
<script>
(function () {
    const form = document.getElementById('ticketCheckinForm');
    const ticketInput = document.getElementById('ticketCodeInput');
    const qrButton = document.getElementById('openQrCodeForm');
    const manualButton = document.getElementById('openManualForm');
    const timeFilter = document.getElementById('activityTimeFilter');
    const ticketFilter = document.getElementById('activityTicketFilter');
    const activityTable = document.getElementById('activityTable');
    const scannerModal = document.getElementById('qrScannerModal');
    const scannerResult = document.getElementById('qrScanResult');
    const closeScannerButton = document.getElementById('closeQrScanner');
    const stopScannerButton = document.getElementById('stopQrScanner');

    let qrScanner = null;
    let scannerBusy = false;
    let scannerStarting = false;

    function showScannerResult(message, type, ticket) {
        scannerResult.replaceChildren();
        scannerResult.className = 'checkin-result ' + type;

        const messageLine = document.createElement('p');
        messageLine.textContent = message || 'Ticket verification failed.';
        messageLine.style.margin = '0';
        scannerResult.appendChild(messageLine);

        // Use textContent for ticket fields so scanned/database values cannot inject HTML.
        if (ticket && type === 'error') {
            const details = document.createElement('div');
            details.className = 'scanner-ticket-details';
            const rows = [
                ['Attendee', ticket.attendee],
                ['Event', ticket.event],
                ['Ticket type', ticket.ticket_type],
                ['Ticket code', ticket.ticket_code],
                ['First checked in', ticket.checked_in_at]
            ];

            rows.forEach(function (row) {
                if (row[1] === undefined || row[1] === null || String(row[1]).trim() === '') return;
                const line = document.createElement('p');
                const label = document.createElement('strong');
                label.textContent = row[0] + ': ';
                const value = document.createElement('span');
                value.textContent = String(row[1]);
                line.append(label, value);
                details.appendChild(line);
            });

            if (details.childElementCount) scannerResult.appendChild(details);
        }
        scannerResult.hidden = false;
    }

    async function stopScanner() {
        if (qrScanner) {
            const scanner = qrScanner;
            qrScanner = null;
            try {
                if (scanner.isScanning) await scanner.stop();
            } catch (error) {
                console.warn('Could not stop QR camera:', error);
            }
            try { await scanner.clear(); } catch (error) {
                console.warn('Could not clear QR scanner:', error);
            }
        }
        scannerBusy = false;
        scannerStarting = false;
    }

    async function closeScanner() {
        await stopScanner();
        scannerModal.hidden = true;
    }

    async function startScanner() {
        if (scannerStarting || qrScanner) return;
        if (typeof Html5Qrcode === 'undefined') {
            alert('The QR scanner library could not load. Check your internet connection and refresh the page.');
            return;
        }

        scannerStarting = true;
        scannerModal.hidden = false;
        scannerResult.hidden = true;

        try {
            qrScanner = new Html5Qrcode('qrReader');
            const scanner = qrScanner;
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                async function (decodedText) {
                    if (scannerBusy || !qrScanner) return;
                    scannerBusy = true;
                    try {
                        if (scanner.isScanning) await scanner.pause(true);
                        const payload = new URLSearchParams();
                        payload.set('token', decodedText.trim());
                        payload.set('method', 'qr');
                        const csrfInput = form.querySelector('[name="csrf_token"]');
                        const eventInput = form.querySelector('[name="event_id"]');
                        payload.set('csrf_token', csrfInput ? csrfInput.value : '');
                        if (eventInput && eventInput.value) payload.set('event_id', eventInput.value);

                        const response = await fetch('verify-checkin.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                'Accept': 'application/json'
                            },
                            body: payload.toString(),
                            credentials: 'same-origin'
                        });
                        let result;
                        try { result = await response.json(); }
                        catch (_) { throw new Error('The server returned an invalid response. Check PHP errors.'); }

                        if (response.ok && result.success) {
                            const ticket = result.ticket || {};
                            showScannerResult(
                                result.message + ' Attendee: ' + (ticket.attendee || 'Guest') +
                                '. Ticket: ' + (ticket.ticket_code || '') +
                                '. Event: ' + (ticket.event || 'Unknown') + '.',
                                'success'
                            );
                            await stopScanner();
                            window.setTimeout(function () { window.location.reload(); }, 1800);
                        } else {
                            const duplicateTicket = Number(response.status) === 409 && result.ticket
                                ? result.ticket
                                : null;
                            showScannerResult(
                                result.message || 'Ticket verification failed.',
                                'error',
                                duplicateTicket
                            );
                            window.setTimeout(async function () {
                                if (qrScanner === scanner && scannerModal && !scannerModal.hidden) {
                                    try { await scanner.resume(); } catch (error) { console.warn(error); }
                                }
                                scannerBusy = false;
                            }, 1600);
                        }
                    } catch (error) {
                        showScannerResult(error.message || 'Could not verify the ticket. Try again.', 'error');
                        window.setTimeout(async function () {
                            if (qrScanner === scanner && scannerModal && !scannerModal.hidden) {
                                try { await scanner.resume(); } catch (resumeError) { console.warn(resumeError); }
                            }
                            scannerBusy = false;
                        }, 1600);
                    }
                },
                function () { /* Ignore frames without a QR code. */ }
            );
        } catch (error) {
            await stopScanner();
            showScannerResult('Unable to start the camera. Allow camera access in your browser settings and try again.', 'error');
        } finally {
            scannerStarting = false;
        }
    }

    if (qrButton) qrButton.addEventListener('click', startScanner);
    if (closeScannerButton) closeScannerButton.addEventListener('click', closeScanner);
    if (stopScannerButton) stopScannerButton.addEventListener('click', closeScanner);
    if (scannerModal) {
        scannerModal.addEventListener('click', function (event) {
            if (event.target === scannerModal) closeScanner();
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && scannerModal && !scannerModal.hidden) closeScanner();
    });

    function showCheckinForm() {
        if (!form) return;

        form.classList.add('visible');

        if (ticketInput) {
            ticketInput.focus();
        }
    }

    if (manualButton) {
        manualButton.addEventListener('click', showCheckinForm);
    }

    function filterActivity() {
        if (!activityTable) return;

        const rows = activityTable.querySelectorAll('tbody tr');
        const selectedMinutes = timeFilter ? timeFilter.value : 'all';
        const selectedType = ticketFilter ? ticketFilter.value : 'all';
        const nowSeconds = Math.floor(Date.now() / 1000);

        rows.forEach(function (row) {
            const ticketType = row.dataset.ticketType || '';
            const timestamp = Number(row.dataset.time || 0);

            let visible = true;

            if (
                selectedType !== 'all' &&
                ticketType !== selectedType
            ) {
                visible = false;
            }

            if (selectedMinutes !== 'all') {
                const minutes = Number(selectedMinutes);
                const cutoff = nowSeconds - (minutes * 60);

                if (!timestamp || timestamp < cutoff) {
                    visible = false;
                }
            }

            row.hidden = !visible;
        });
    }

    if (timeFilter) {
        timeFilter.addEventListener('change', filterActivity);
    }

    if (ticketFilter) {
        ticketFilter.addEventListener('change', filterActivity);
    }

    const searchInput = document.getElementById('dashboardSearch');

    if (searchInput && activityTable) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLowerCase();

            activityTable.querySelectorAll('tbody tr').forEach(function (row) {
                const matchesSearch = row.textContent.toLowerCase().includes(query);
                const matchesTime = !timeFilter ||
                    timeFilter.value === 'all' ||
                    (
                        Number(row.dataset.time || 0) > 0 &&
                        Number(row.dataset.time || 0) >=
                            Math.floor(Date.now() / 1000) -
                            Number(timeFilter.value) * 60
                    );

                const matchesTicket = !ticketFilter ||
                    ticketFilter.value === 'all' ||
                    row.dataset.ticketType === ticketFilter.value;

                row.hidden = !(matchesSearch && matchesTime && matchesTicket);
            });
        });
    }

    if (form && new URLSearchParams(window.location.search).has('checkin')) {
        form.classList.add('visible');
    }
})();
</script>

</body>
</html>
