

<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!authIsLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];

if (($user['role'] ?? '') !== 'organizer') {
    header('Location: ../index.php');
    exit;
}

$organizerName = $user['full_name'] ?? 'Organizer';
$organizerId = 0;
$stats = [
    'revenue' => 0,
    'tickets_sold' => 0,
    'upcoming_events' => 0,
    'check_ins' => 0,
];
$dashboardEvents = [];
$dashboardError = '';
$chartPeriod = isset($_GET['period']) && in_array($_GET['period'], ['daily', 'weekly', 'monthly'], true)
    ? (string) $_GET['period']
    : 'daily';
$chartRows = [];
$chartValues = [];
$chartHasRevenue = false;

function dashboardEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dashboardDate(?string $date): string
{
    if (!$date) {
        return 'Date not set';
    }
    try {
        return (new DateTime($date))->format('D, d M Y · H:i');
    } catch (Throwable $e) {
        return 'Date unavailable';
    }
}

function dashboardMoney(int|float|string $amount): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' FCFA';
}

function dashboardEventStatus(array $event): string
{
    $status = (string) ($event['status'] ?? 'draft');
    if ($status === 'cancelled') return 'Cancelled';
    if ($status === 'completed') return 'Completed';
    if ($status === 'draft') return 'Draft';
    if ($status !== 'published') return ucfirst($status);

    $start = strtotime((string) ($event['event_date'] ?? ''));
    $end = !empty($event['event_end_date'])
        ? strtotime((string) $event['event_end_date'])
        : $start;

    if ($end !== false && $end < time()) return 'Completed';
    if ($start !== false && $start <= time()) return 'Live';
    return 'Upcoming';
}

try {
    // The events table uses organizers.id, not users.id.
    $profileStmt = $pdo->prepare(
        'SELECT id, business_name
         FROM organizers
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $profileStmt->execute(['user_id' => (int) $user['id']]);
    $organizer = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if ($organizer) {
        $organizerId = (int) $organizer['id'];
        if (!empty($organizer['business_name'])) {
            $organizerName = (string) $organizer['business_name'];
        }

        $statsStmt = $pdo->prepare(
            "SELECT
                COALESCE((
                    SELECT SUM(o.total_amount)
                    FROM orders o
                    INNER JOIN events e ON e.id = o.event_id
                    WHERE e.organizer_id = :revenue_organizer_id
                      AND o.status = 'paid'
                ), 0) AS revenue,
                COALESCE((
                    SELECT SUM(tt.quantity_sold)
                    FROM ticket_types tt
                    INNER JOIN events e ON e.id = tt.event_id
                    WHERE e.organizer_id = :tickets_organizer_id
                ), 0) AS tickets_sold,
                COALESCE((
                    SELECT COUNT(*)
                    FROM events e
                    WHERE e.organizer_id = :upcoming_organizer_id
                      AND e.status = 'published'
                      AND (
                        (e.event_end_date IS NOT NULL AND e.event_end_date >= NOW())
                        OR (e.event_end_date IS NULL AND e.event_date >= NOW())
                      )
                ), 0) AS upcoming_events,
                COALESCE((
                    SELECT COUNT(*)
                    FROM issued_tickets it
                    INNER JOIN order_items oi ON oi.id = it.order_item_id
                    INNER JOIN orders o ON o.id = oi.order_id
                    INNER JOIN events e ON e.id = o.event_id
                    WHERE e.organizer_id = :checkin_organizer_id
                      AND o.status = 'paid'
                      AND (it.status = 'used' OR it.checked_in_at IS NOT NULL)
                ), 0) AS check_ins"
        );
        $statsStmt->execute([
            'revenue_organizer_id' => $organizerId,
            'tickets_organizer_id' => $organizerId,
            'upcoming_organizer_id' => $organizerId,
            'checkin_organizer_id' => $organizerId,
        ]);
        $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);
        if ($statsRow) {
            $stats = [
                'revenue' => (float) $statsRow['revenue'],
                'tickets_sold' => (int) $statsRow['tickets_sold'],
                'upcoming_events' => (int) $statsRow['upcoming_events'],
                'check_ins' => (int) $statsRow['check_ins'],
            ];
        }

        // Show the organizer's latest events, including drafts and past events.
        $eventsStmt = $pdo->prepare(
            'SELECT id, title, event_date, event_end_date, venue, city, image_url, status
             FROM events
             WHERE organizer_id = :organizer_id
             ORDER BY event_date DESC, id DESC
             LIMIT 6'
        );
        $eventsStmt->execute(['organizer_id' => $organizerId]);
        $dashboardEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load actual paid-order revenue for the selected chart period.
        if ($chartPeriod === 'weekly') {
            $periodExpression = "DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY)";
            $labelExpression = "DATE_FORMAT($periodExpression, '%d %b')";
            $dateLimit = '8 WEEK';
        } elseif ($chartPeriod === 'monthly') {
            $periodExpression = "DATE_FORMAT(o.created_at, '%Y-%m-01')";
            $labelExpression = "DATE_FORMAT($periodExpression, '%b %Y')";
            $dateLimit = '6 MONTH';
        } else {
            $periodExpression = 'DATE(o.created_at)';
            $labelExpression = "DATE_FORMAT($periodExpression, '%d %b')";
            $dateLimit = '7 DAY';
        }
        // $dateLimit is selected only from the fixed allow-list above.
        $chartSql = "SELECT $periodExpression AS period_key,
                            $labelExpression AS period_label,
                            COALESCE(SUM(o.total_amount), 0) AS revenue
                     FROM orders o
                     INNER JOIN events e ON e.id = o.event_id
                     WHERE e.organizer_id = :organizer_id
                       AND o.status = 'paid'
                       AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL $dateLimit)
                     GROUP BY period_key, period_label
                     ORDER BY period_key ASC";
        $chartStmt = $pdo->prepare($chartSql);
        $chartStmt->execute(['organizer_id' => $organizerId]);
        $chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chartRows as $chartRow) {
            $chartValues[] = [
                'label' => (string) $chartRow['period_label'],
                'revenue' => (float) $chartRow['revenue'],
            ];
            if ((float) $chartRow['revenue'] > 0) {
                $chartHasRevenue = true;
            }
        }
    }
} catch (Throwable $e) {
    error_log('Ticketa dashboard data error: ' . $e->getMessage());
    $dashboardError = 'We could not load your dashboard data. Please refresh the page.';
}
?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Organizer Dashboard | TICKETA</title>



    <style>

        :root {

            --green: #10c6a4;

            --green-dark: #08ad91;

            --ink: #171c2e;

            --muted: #778094;

            --border: #e9ecef;

            --background: #fafbfc;

            --white: #fff;

        }



        * {

            box-sizing: border-box;

        }



        body {

            margin: 0;

            background: var(--background);

            color: var(--ink);

            font-family: Inter, Arial, sans-serif;

        }



        button, input {

            font: inherit;

        }



        a {

            color: inherit;

            text-decoration: none;

        }



        .sidebar {

            position: fixed;

            inset: 0 auto 0 0;

            z-index: 1100;

            display: flex;

            flex-direction: column;

            width: 250px;

            padding: 24px 22px;

            border-right: 1px solid var(--border);

            background: var(--white);

        }



        .brand {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 58px;

            font-size: 26px;

            font-weight: 800;

            letter-spacing: -1px;

        }



        .brand-icon {

            display: grid;

            width: 39px;

            height: 31px;

            place-items: center;

            border: 3px solid var(--green);

            border-radius: 7px;

            color: var(--green-dark);

            font-size: 19px;

            font-weight: 800;

        }



        .nav {

            display: grid;

            gap: 14px;

        }



        .nav-link {

            display: flex;

            align-items: center;

            gap: 10px;
            min-height: 42px;
            padding: 10px 12px;

            border-radius: 4px;

            color: #777b83;

            font-size: 15px;

            transition: background .2s, color .2s;

        }



        .nav-link:hover {

            background: #edfaf7;

            color: #087f6b;

        }



        .nav-link.active {

            background: var(--green);

            color: #fff;

            box-shadow: 0 3px 0 rgba(8, 173, 145, .18);

        }



        .nav-icon {

            display: inline-grid;

            width: 22px;

            place-items: center;

            font-size: 20px;

        }



        .main {
            min-height: 100vh;
            width: auto;
            min-width: 0;
            margin-left: 250px;
            /* Keep page content below the shared organizer topbar. */
            padding-top: 90px;
        }



        .topbar {
            min-width: 0;
            position: sticky;

            top: 0;

            z-index: 1000;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            min-height: 90px;

            padding: 18px 32px;

            border-bottom: 1px solid var(--border);

            background: rgba(255, 255, 255, .96);

            backdrop-filter: blur(12px);

        }



        .search {

            display: flex;

            align-items: center;

            gap: 12px;

            width: min(345px, 45%);

            padding: 13px 15px;

            border-radius: 12px;

            background: #f4f6f6;

        }



        .search span {

            font-size: 22px;

        }



        .search input {

            width: 100%;

            border: 0;

            outline: 0;

            background: transparent;

            color: var(--ink);

        }



        .search input::placeholder {

            color: #777;

        }



        .topbar-right {

            display: flex;

            align-items: center;

            gap: 22px;

        }



        .icon-button {

            position: relative;

            display: grid;

            width: 38px;

            height: 38px;

            place-items: center;

            border: 0;

            background: transparent;

            font-size: 23px;

            cursor: pointer;

        }



        .notification-dot {

            position: absolute;

            top: 1px;

            right: 0;

            display: grid;

            width: 17px;

            height: 17px;

            place-items: center;

            border-radius: 50%;

            background: #ffc83d;

            color: #fff;

            font-size: 10px;

        }



        .organizer-profile {

            display: flex;

            align-items: center;

            gap: 10px;

            max-width: 220px;

            font-size: 14px;

            font-weight: 600;

        }



        .avatar {

            display: grid;

            width: 35px;

            height: 35px;

            flex-shrink: 0;

            place-items: center;

            overflow: hidden;

            border: 1px solid var(--border);

            border-radius: 50%;

            background: #e5faf4;

            color: #087f6b;

            font-weight: 800;

        }



        .organizer-name {

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

        }



        .content {
            min-width: 0;
            padding: 16px 32px 36px;
        }

        .panel,
        .stats,
        .panel-header,
        .event-row,
        .event-info {
            min-width: 0;
        }



        .page-heading {

            margin: 0 0 6px;

            font-size: clamp(25px, 3vw, 32px);

            letter-spacing: -.7px;

        }



        .page-subtitle {

            margin: 0 0 16px;

            color: var(--muted);

            font-size: 16px;

        }



        .stats {

            display: grid;

            grid-template-columns: repeat(4, minmax(0, 1fr));

            gap: 12px;

            margin-bottom: 20px;

        }



        .stat-card {

            display: flex;

            align-items: center;

            gap: 12px;

            min-width: 0;

            min-height: 102px;

            padding: 15px 12px;

            border: 1px solid var(--border);

            border-radius: 12px;

            background: var(--white);

        }



        .stat-icon {

            display: grid;

            width: 48px;

            height: 48px;

            flex-shrink: 0;

            place-items: center;

            border-radius: 50%;

            background: var(--green);

            color: #fff;

            font-size: 23px;

        }



        .stat-label {

            margin-bottom: 7px;

            color: #292929;

            font-size: 14px;

            line-height: 1.4;

        }



        .stat-value {

            overflow-wrap: anywhere;

            font-size: clamp(17px, 1.6vw, 23px);

            font-weight: 750;

            letter-spacing: .2px;

        }



        .panel {

            margin-bottom: 20px;

            padding: 20px;

            border: 1px solid var(--border);

            border-radius: 12px;

            background: var(--white);

        }



        .panel-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            margin-bottom: 20px;

        }



        .panel-title {

            margin: 0;

            font-size: 20px;

            font-weight: 600;

        }



        .period-controls {

            display: flex;

            overflow: hidden;

            border-radius: 4px;

            background: #dedede;

        }



        .period-button {

            min-width: 100px;

            padding: 8px 14px;

            border: 0;

            border-right: 1px solid #b8b8b8;

            background: transparent;

            cursor: pointer;

        }



        .period-button:last-child {

            border-right: 0;

        }



        .period-button.active {

            background: #ffc83d;

        }



        .chart-wrap {

            width: 100%;

            min-height: 245px;

        }



        .chart-wrap svg {

            display: block;

            width: 100%;

            height: auto;

            overflow: visible;

        }



        .chart-grid {

            stroke: #a5a5a5;

            stroke-dasharray: 6 6;

            stroke-width: 1.2;

        }



        .chart-line {

            fill: none;

            stroke: #35c5aa;

            stroke-width: 3;

            stroke-linecap: round;

            stroke-linejoin: round;

        }



        .chart-area {

            fill: #35c5aa;

            opacity: .12;

        }



        .chart-point {

            fill: #35c5aa;

        }



        .chart-label {

            fill: #555;

            font-size: 11px;

        }



        .chart-empty {

            display: none;

            min-height: 230px;

            place-items: center;

            padding: 25px;

            color: var(--muted);

            text-align: center;

        }



        .chart-empty.visible {

            display: grid;

        }



        .event-row {

            display: grid;

            grid-template-columns: minmax(0, 1fr) auto;

            align-items: center;

            gap: 18px;

            padding: 14px 0;

            border-bottom: 1px solid var(--border);

        }



        .event-row:last-child {

            border-bottom: 0;

        }



        .event-info {

            display: flex;

            align-items: center;

            gap: 14px;

            min-width: 0;

        }



        .event-thumbnail {

            display: grid;

            width: 52px;

            height: 62px;

            flex-shrink: 0;

            place-items: center;

            border-radius: 5px;

            background: #e5faf4;

            color: #087f6b;

            font-size: 22px;

        }

        .event-thumbnail img { width: 100%; height: 100%; object-fit: cover; border-radius: 5px; }



        .event-title {

            margin: 0 0 8px;

            font-size: 16px;

            font-weight: 650;

        }



        .event-meta {

            margin: 0;

            color: #8a8a8a;

            font-size: 13px;

            line-height: 1.5;

        }



        .event-actions {

            display: flex;

            flex-wrap: wrap;

            justify-content: flex-end;

            gap: 10px;

        }



        .action-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 125px;

            min-height: 40px;

            padding: 9px 14px;

            border: 1px solid var(--green);

            border-radius: 5px;

            background: var(--green);

            color: #fff;

            font-size: 13px;

            font-weight: 600;

            cursor: pointer;

        }



        .action-button:hover {

            background: var(--green-dark);

        }



        .more-link {

            font-size: 14px;

            font-weight: 600;

        }



        .empty-events {

            padding: 35px 15px;

            color: var(--muted);

            text-align: center;

        }



        .empty-events strong {

            display: block;

            margin-bottom: 8px;

            color: var(--ink);

            font-size: 17px;

        }



        .primary-link {

            display: inline-flex;

            margin-top: 14px;

            padding: 11px 16px;

            border-radius: 7px;

            background: var(--green);

            color: #fff;

            font-size: 14px;

            font-weight: 700;

        }



        .menu-toggle {

            display: none;

            width: 40px;

            height: 40px;

            border: 1px solid var(--border);

            border-radius: 8px;

            background: #fff;

            font-size: 22px;

            cursor: pointer;

        }



        .sidebar-backdrop {

            display: none;

        }



        @media (max-width: 1250px) {

            .sidebar {

                width: 220px;

                padding: 22px 16px;

            }



            .main {
                margin-left: 220px;
                min-width: 0;
            }



            .content {

                padding-right: 22px;

                padding-left: 22px;

            }



            .topbar {

                padding-right: 22px;

                padding-left: 22px;

            }



            .stats {

                grid-template-columns: repeat(2, minmax(0, 1fr));

            }

        }



        @media (max-width: 800px) {

            .sidebar {

                transform: translateX(-100%);

                transition: transform .25s ease;

            }



            .sidebar.open {

                transform: translateX(0);

            }



            .sidebar-backdrop.visible {

                position: fixed;

                inset: 0;

                z-index: 1050;

                display: block;

                background: rgba(20, 30, 35, .4);

            }



            .main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
                /* Match the shorter shared topbar on mobile. */
                padding-top: 75px;
            }



            .menu-toggle {

                display: inline-grid;

                flex-shrink: 0;

                place-items: center;

            }



            .topbar {

                min-height: 75px;

                gap: 12px;

                padding: 14px 18px;

            }



            .search {

                width: auto;

                flex: 1;

                padding: 11px;

            }



            .topbar-right {

                gap: 10px;

            }



            .organizer-name {

                display: none;

            }



            .content {

                padding: 22px 18px;

            }

        }



        @media (max-width: 560px) {

            .stats {

                grid-template-columns: 1fr;

            }



            .stat-card {

                min-height: 86px;

            }



            .panel {

                padding: 15px;

            }



            .panel-header {

                align-items: flex-start;

                flex-direction: column;

            }



            .period-controls {

                width: 100%;

            }



            .period-button {

                flex: 1;

                min-width: 0;

                padding: 9px 5px;

                font-size: 12px;

            }



            .event-row {

                grid-template-columns: 1fr;

            }



            .event-actions {

                justify-content: flex-start;

            }



            .action-button {

                flex: 1;

                min-width: 0;

            }



            .event-title {

                font-size: 14px;

            }



            .event-meta {

                font-size: 12px;

            }



            .search {

                min-width: 0;

            }



            .search input {

                min-width: 0;

            }



            .icon-button {

                width: 30px;

            }

        }


    </style>

</head>



<body>

    <?php include dirname(__DIR__) . '/includes/organizer-sidebar.php'; ?>



    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>



    <div class="main">

        <?php
        $topbarSearchPlaceholder = 'Search events';
        include dirname(__DIR__) . '/includes/organizer-topbar.php';
        ?>



        <main class="content">

            <h1 class="page-heading">

                Welcome, <?= dashboardEscape($organizerName) ?>

            </h1>



            <p class="page-subtitle">

                Here are your current event stats

            </p>
            <?php if ($dashboardError !== ''): ?>
                <div role="alert" style="margin:0 0 18px;padding:13px 16px;border-radius:8px;background:#fff0f0;color:#a32929;">
                    <?= dashboardEscape($dashboardError) ?> Check your PHP error log for the technical details.
                </div>
            <?php endif; ?>



            <section class="stats" aria-label="Event statistics">

                <article class="stat-card">

                    <div class="stat-icon">₣</div>

                    <div>

                        <div class="stat-label">Total Revenue</div>

                        <div class="stat-value"><?= dashboardEscape(dashboardMoney($stats['revenue'])) ?></div>

                    </div>

                </article>



                <article class="stat-card">

                    <div class="stat-icon">▰</div>

                    <div>

                        <div class="stat-label">Total Ticket Sold</div>

                        <div class="stat-value"><?= number_format($stats['tickets_sold']) ?></div>

                    </div>

                </article>



                <article class="stat-card">

                    <div class="stat-icon">▣</div>

                    <div>

                        <div class="stat-label">Upcoming Events</div>

                        <div class="stat-value"><?= number_format($stats['upcoming_events']) ?></div>

                    </div>

                </article>



                <article class="stat-card">

                    <div class="stat-icon">✓</div>

                    <div>

                        <div class="stat-label">Total Check-Ins</div>

                        <div class="stat-value"><?= number_format($stats['check_ins']) ?></div>

                    </div>

                </article>

            </section>



            <section class="panel">

                <div class="panel-header">

                    <h2 class="panel-title">Revenue Performance</h2>



                    <div class="period-controls" aria-label="Chart period">

                        <button

                            type="button"

                            class="period-button <?= $chartPeriod === 'daily' ? 'active' : '' ?>"

                            data-period="daily"

                        >Daily</button>



                        <button

                            type="button"

                            class="period-button <?= $chartPeriod === 'weekly' ? 'active' : '' ?>"

                            data-period="weekly"

                        >Weekly</button>



                        <button

                            type="button"

                            class="period-button <?= $chartPeriod === 'monthly' ? 'active' : '' ?>"

                            data-period="monthly"

                        >Monthly</button>

                    </div>

                </div>



                <?php if ($chartHasRevenue && $chartValues): ?>
                    <?php
                        $chartWidth = 900;
                        $chartLeft = 100;
                        $chartRight = 885;
                        $chartTop = 35;
                        $chartBottom = 235;
                        $chartCount = count($chartValues);
                        $chartMax = max(array_column($chartValues, 'revenue'));
                        $chartMax = $chartMax > 0 ? $chartMax : 1;
                        $chartStep = $chartCount > 1 ? ($chartRight - $chartLeft) / ($chartCount - 1) : 0;
                        $chartCoords = [];
                        foreach ($chartValues as $i => $point) {
                            $x = $chartCount > 1 ? $chartLeft + ($chartStep * $i) : ($chartLeft + $chartRight) / 2;
                            $y = $chartBottom - (((float) $point['revenue'] / $chartMax) * ($chartBottom - $chartTop));
                            $chartCoords[] = ['x' => $x, 'y' => $y, 'label' => $point['label'], 'revenue' => $point['revenue']];
                        }
                        $linePath = '';
                        foreach ($chartCoords as $i => $point) {
                            $linePath .= ($i === 0 ? 'M' : ' L') . number_format($point['x'], 2, '.', '') . ' ' . number_format($point['y'], 2, '.', '');
                        }
                        $areaPath = $linePath . ' L' . $chartRight . ' ' . $chartBottom . ' L' . $chartLeft . ' ' . $chartBottom . ' Z';
                    ?>
                    <div class="chart-wrap" id="chartWrap">
                        <svg viewBox="0 0 900 290" role="img" aria-label="Paid revenue performance chart">
                            <g class="chart-grid">
                                <?php for ($i = 0; $i < 5; $i++): $gy = $chartTop + (($chartBottom - $chartTop) * $i / 4); ?>
                                    <line x1="100" y1="<?= $gy ?>" x2="885" y2="<?= $gy ?>" />
                                <?php endfor; ?>
                            </g>
                            <g class="chart-label">
                                <?php for ($i = 0; $i < 5; $i++): $value = $chartMax * (4 - $i) / 4; $gy = $chartTop + (($chartBottom - $chartTop) * $i / 4); ?>
                                    <text x="90" y="<?= $gy + 4 ?>" text-anchor="end"><?= dashboardEscape(number_format($value, 0, ',', ' ')) ?></text>
                                <?php endfor; ?>
                                <?php foreach ($chartCoords as $point): ?>
                                    <text x="<?= number_format($point['x'], 2, '.', '') ?>" y="260" text-anchor="middle"><?= dashboardEscape($point['label']) ?></text>
                                <?php endforeach; ?>
                            </g>
                            <path class="chart-area" d="<?= dashboardEscape($areaPath) ?>" />
                            <path class="chart-line" d="<?= dashboardEscape($linePath) ?>" />
                            <g class="chart-point">
                                <?php foreach ($chartCoords as $point): ?>
                                    <circle cx="<?= number_format($point['x'], 2, '.', '') ?>" cy="<?= number_format($point['y'], 2, '.', '') ?>" r="4">
                                        <title><?= dashboardEscape($point['label'] . ': ' . dashboardMoney($point['revenue'])) ?></title>
                                    </circle>
                                <?php endforeach; ?>
                            </g>
                        </svg>
                    </div>
                <?php else: ?>
                    <div class="chart-empty visible" id="chartEmpty">
                        <p>No paid-order revenue has been recorded for this period yet.</p>
                    </div>
                <?php endif; ?>

            </section>



            <section class="panel">

                <div class="panel-header">

                    <h2 class="panel-title">Upcoming Events</h2>

                    <a class="more-link" href="events.php">More&nbsp; ›</a>

                </div>



                <?php if ($dashboardError !== ''): ?>
                    <div class="empty-events">
                        <strong>Dashboard data unavailable</strong>
                        <p><?= dashboardEscape($dashboardError) ?></p>
                    </div>
                <?php elseif (!$dashboardEvents): ?>
                    <div class="empty-events">
                        <strong>No events yet</strong>
                        <p>Create your first event and it will appear here with its date, venue, and status.</p>
                        <a class="primary-link" href="create-event.php">＋ Create your first event</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($dashboardEvents as $event): ?>
                        <?php
                            $eventTitle = (string) ($event['title'] ?? 'Untitled event');
                            $venue = trim((string) ($event['venue'] ?? ''));
                            $city = trim((string) ($event['city'] ?? ''));
                            $venueText = $venue !== '' ? $venue : 'Venue to be announced';
                            if ($city !== '') $venueText .= ', ' . $city;
                            $imageUrl = trim((string) ($event['image_url'] ?? ''));
                            if ($imageUrl !== '' && !preg_match('~^(?:https?://|/)~i', $imageUrl)) {
                                $imageUrl = '../' . ltrim($imageUrl, '/');
                            }
                            $statusLabel = dashboardEventStatus($event);
                        ?>
                        <article class="event-row">
                            <div class="event-info">
                                <div class="event-thumbnail">
                                    <?php if ($imageUrl !== ''): ?>
                                        <img src="<?= dashboardEscape($imageUrl) ?>"
                                             alt="<?= dashboardEscape($eventTitle) ?>"
                                             loading="lazy"
                                             style="width:100%;height:100%;object-fit:cover;border-radius:5px"
                                             onerror="this.style.display='none';this.parentElement.textContent='▣';">
                                    <?php else: ?>
                                        <span aria-hidden="true">▣</span>
                                    <?php endif; ?>
                                </div>
                                <div style="min-width:0">
                                    <h3 class="event-title"><?= dashboardEscape($eventTitle) ?></h3>
                                    <p class="event-meta">
                                        <?= dashboardEscape(dashboardDate($event['event_date'] ?? null)) ?><br>
                                        <?= dashboardEscape($venueText) ?>
                                    </p>
                                    <p class="event-meta"><strong>Status:</strong> <?= dashboardEscape($statusLabel) ?></p>
                                </div>
                            </div>
                            <div class="event-actions">
                                <a class="action-button" href="edit-event.php?id=<?= (int) $event['id'] ?>">View / Edit</a>
                                <a class="action-button" href="ticket-sales.php?event_id=<?= (int) $event['id'] ?>">Ticket Sales</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <a class="primary-link" href="events.php">View all events</a>
                <?php endif; ?>
            

        </main>

    </div>



    <script>

        const sidebar = document.getElementById('sidebar');

        const menuToggle = document.getElementById('menuToggle');

        const backdrop = document.getElementById('sidebarBackdrop');



        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('visible');
            if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
        }

        if (menuToggle && sidebar && backdrop) {
            menuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('open');
                backdrop.classList.toggle('visible', isOpen);
                menuToggle.setAttribute('aria-expanded', String(isOpen));
            });
            backdrop.addEventListener('click', closeSidebar);
        }



        document.querySelectorAll('.nav-link').forEach((link) => {

            link.addEventListener('click', closeSidebar);

        });



        document.querySelectorAll('.period-button').forEach((button) => {
            button.addEventListener('click', () => {
                const period = button.dataset.period;
                window.location.href = 'dashboard.php?period=' + encodeURIComponent(period);
            });
        });



        const search = document.getElementById('dashboardSearch');
        if (search) {
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                document.querySelectorAll('.event-row').forEach((row) => {
                    row.hidden = !row.textContent.toLowerCase().includes(query);
                });
            });
        }

    </script>

</body>

</html>
