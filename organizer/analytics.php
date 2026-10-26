
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
$currentPage = 'analytics.php';
$topbarSearchPlaceholder = 'Search events';

function analyticsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function analyticsMoney($amount): string
{
    return 'FCFA ' . number_format((float) $amount, 0, ',', ' ');
}

$organizerId = null;
$events = [];
$ticketRows = [];
$monthlyRevenue = [];
$metrics = [
    'revenue' => 0,
    'tickets_sold' => 0,
    'tickets_remaining' => 0,
    'refunds' => 0,
    'paid_orders' => 0,
    'total_orders' => 0,
];

$selectedEventId = filter_input(
    INPUT_GET,
    'event_id',
    FILTER_VALIDATE_INT
);

$errorMessage = '';

try {
    // Resolve the organizer profile belonging to the signed-in user.
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
            'No organizer profile is linked to this account.'
        );
    }

    $organizerId = (int) $organizer['id'];

    if (!empty($organizer['business_name'])) {
        $organizerName = $organizer['business_name'];
    }

    // Only show events belonging to the current organizer.
    $eventStmt = $pdo->prepare(
        'SELECT id, title, event_date, status
         FROM events
         WHERE organizer_id = :organizer_id
         ORDER BY event_date DESC'
    );
    $eventStmt->execute(['organizer_id' => $organizerId]);
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

    $validEventIds = array_map(
        static fn($event) => (int) $event['id'],
        $events
    );

    if (
        $selectedEventId &&
        in_array((int) $selectedEventId, $validEventIds, true)
    ) {
        $selectedEventId = (int) $selectedEventId;
    } else {
        $selectedEventId = null;
    }

    $eventCondition = '';
    $params = ['organizer_id' => $organizerId];

    if ($selectedEventId !== null) {
        $eventCondition = ' AND e.id = :event_id';
        $params['event_id'] = $selectedEventId;
    }

    // Revenue and order totals are based on paid orders only.
    $ordersStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(
                CASE WHEN o.status = 'paid'
                     THEN o.total_amount ELSE 0 END
            ), 0) AS revenue,

            COALESCE(SUM(
                CASE WHEN o.status = 'refunded'
                     THEN o.total_amount ELSE 0 END
            ), 0) AS refunds,

            COALESCE(SUM(
                CASE WHEN o.status = 'paid'
                     THEN 1 ELSE 0 END
            ), 0) AS paid_orders,

            COUNT(*) AS total_orders

         FROM orders o
         INNER JOIN events e ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
         $eventCondition"
    );
    $ordersStmt->execute($params);
    $orderMetrics = $ordersStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $metrics['revenue'] = (float) ($orderMetrics['revenue'] ?? 0);
    $metrics['refunds'] = (float) ($orderMetrics['refunds'] ?? 0);
    $metrics['paid_orders'] = (int) ($orderMetrics['paid_orders'] ?? 0);
    $metrics['total_orders'] = (int) ($orderMetrics['total_orders'] ?? 0);

    // Ticket inventory and sales for the organizer's events.
    $ticketsStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(tt.quantity_sold), 0) AS sold,
            COALESCE(SUM(
                GREATEST(tt.quantity_total - tt.quantity_sold, 0)
            ), 0) AS remaining
         FROM ticket_types tt
         INNER JOIN events e ON e.id = tt.event_id
         WHERE e.organizer_id = :organizer_id
           AND tt.is_active = 1
           $eventCondition"
    );
    $ticketsStmt->execute($params);
    $ticketMetrics = $ticketsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $metrics['tickets_sold'] = (int) ($ticketMetrics['sold'] ?? 0);
    $metrics['tickets_remaining'] = (int) ($ticketMetrics['remaining'] ?? 0);

    // Breakdown by ticket type. Revenue is estimated from sold quantity
    // multiplied by ticket price, not represented as confirmed order revenue.
    $ticketRowsStmt = $pdo->prepare(
        "SELECT
            tt.name,
            COALESCE(SUM(tt.quantity_sold), 0) AS sold,
            COALESCE(SUM(
                GREATEST(tt.quantity_total - tt.quantity_sold, 0)
            ), 0) AS remaining,
            COALESCE(SUM(tt.quantity_total), 0) AS total_quantity,
            COALESCE(SUM(tt.quantity_sold * tt.price), 0) AS estimated_revenue
         FROM ticket_types tt
         INNER JOIN events e ON e.id = tt.event_id
         WHERE e.organizer_id = :organizer_id
           $eventCondition
         GROUP BY tt.name
         ORDER BY sold DESC, tt.name ASC"
    );
    $ticketRowsStmt->execute($params);
    $ticketRows = $ticketRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Paid-order revenue grouped by month for the last six months.
    $chartParams = ['organizer_id' => $organizerId];
    $chartEventCondition = '';

    if ($selectedEventId !== null) {
        $chartEventCondition = ' AND e.id = :event_id';
        $chartParams['event_id'] = $selectedEventId;
    }

    $chartStmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(o.created_at, '%Y-%m') AS month_key,
            COALESCE(SUM(o.total_amount), 0) AS revenue
         FROM orders o
         INNER JOIN events e ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
           AND o.status = 'paid'
           AND o.created_at >= DATE_FORMAT(
               DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01'
           )
           $chartEventCondition
         GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
         ORDER BY month_key ASC"
    );
    $chartStmt->execute($chartParams);

    $revenueByMonth = [];

    foreach ($chartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $revenueByMonth[$row['month_key']] = (float) $row['revenue'];
    }

    for ($i = 5; $i >= 0; $i--) {
        $monthDate = new DateTime('first day of this month');
        $monthDate->modify("-$i months");
        $key = $monthDate->format('Y-m');

        $monthlyRevenue[] = [
            'label' => $monthDate->format('M'),
            'revenue' => $revenueByMonth[$key] ?? 0,
        ];
    }
} catch (Throwable $e) {
    error_log('Ticketa analytics error: ' . $e->getMessage());
    $errorMessage = 'Analytics data could not be loaded. Please check your database configuration and try again.';
}

$maxRevenue = 0;

foreach ($monthlyRevenue as $month) {
    $maxRevenue = max($maxRevenue, (float) $month['revenue']);
}

$chartPoints = [];
$chartCount = count($monthlyRevenue);

foreach ($monthlyRevenue as $index => $month) {
    $x = $chartCount > 1
        ? 55 + ($index * (640 / ($chartCount - 1)))
        : 375;

    $ratio = $maxRevenue > 0
        ? $month['revenue'] / $maxRevenue
        : 0;

    $y = 220 - ($ratio * 170);

    $chartPoints[] = [
        'x' => $x,
        'y' => $y,
        'label' => $month['label'],
        'revenue' => $month['revenue'],
    ];
}

$linePath = '';

foreach ($chartPoints as $index => $point) {
    $linePath .= ($index === 0 ? 'M' : ' L')
        . round($point['x'], 2) . ' '
        . round($point['y'], 2);
}

$areaPath = $linePath;

if (!empty($chartPoints)) {
    $firstPoint = $chartPoints[0];
    $lastPoint = $chartPoints[count($chartPoints) - 1];

    $areaPath .= ' L ' . $lastPoint['x'] . ' 220';
    $areaPath .= ' L ' . $firstPoint['x'] . ' 220 Z';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Overview | TICKETA</title>

    <style>
        :root {
            --analytics-green: #10c6a4;
            --analytics-green-dark: #08ad91;
            --analytics-ink: #171c2e;
            --analytics-muted: #778094;
            --analytics-border: #e9ecef;
            --analytics-background: #fafbfc;
            --analytics-white: #fff;
            --analytics-yellow: #ffc83d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--analytics-background);
            color: var(--analytics-ink);
            font-family: Inter, Arial, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 250px;
            padding-top: 90px;
        }

        .analytics-content {
            padding: 22px 32px 40px;
        }

        .analytics-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .analytics-heading h1 {
            margin: 0 0 8px;
            font-size: clamp(25px, 3vw, 32px);
            letter-spacing: -.7px;
        }

        .analytics-heading p {
            margin: 0;
            color: var(--analytics-muted);
            font-size: 16px;
        }

        .event-filter {
            max-width: 230px;
            min-width: 150px;
            padding: 12px 15px;
            border: 0;
            border-radius: 6px;
            background: var(--analytics-green);
            color: #fff;
            font-size: 14px;
            cursor: pointer;
        }

        .event-filter option {
            background: #fff;
            color: var(--analytics-ink);
        }

        .analytics-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .analytics-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            min-height: 103px;
            padding: 16px 14px;
            border: 1px solid var(--analytics-border);
            border-radius: 12px;
            background: var(--analytics-white);
        }

        .analytics-stat-icon {
            display: grid;
            width: 49px;
            height: 49px;
            flex-shrink: 0;
            place-items: center;
            border-radius: 50%;
            background: var(--analytics-green);
            color: #fff;
            font-size: 23px;
        }

        .analytics-stat-label {
            margin-bottom: 8px;
            color: #454545;
            font-size: 14px;
            line-height: 1.4;
        }

        .analytics-stat-value {
            overflow-wrap: anywhere;
            font-size: clamp(17px, 1.5vw, 23px);
            font-weight: 750;
            letter-spacing: .1px;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.9fr) minmax(240px, .75fr);
            align-items: start;
            gap: 22px;
        }

        .analytics-panel {
            min-width: 0;
            margin-bottom: 22px;
            padding: 20px;
            border: 1px solid var(--analytics-border);
            border-radius: 12px;
            background: var(--analytics-white);
        }

        .analytics-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 20px;
        }

        .analytics-panel-title {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .period-controls {
            display: flex;
            overflow: hidden;
            flex-shrink: 0;
            border-radius: 5px;
            background: #dedede;
        }

        .period-button {
            min-width: 75px;
            padding: 9px 12px;
            border: 0;
            border-right: 1px solid #b8b8b8;
            background: transparent;
            color: #292929;
            cursor: pointer;
        }

        .period-button:last-child {
            border-right: 0;
        }

        .period-button.active {
            background: var(--analytics-yellow);
        }

        .revenue-chart {
            width: 100%;
            min-height: 230px;
        }

        .revenue-chart svg {
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
            opacity: .14;
        }

        .chart-point {
            fill: #119b5b;
        }

        .chart-label {
            fill: #555;
            font-size: 11px;
        }

        .chart-empty {
            padding: 45px 15px;
            color: var(--analytics-muted);
            text-align: center;
        }

        .ticket-table-wrap {
            overflow-x: auto;
        }

        .ticket-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .ticket-table th,
        .ticket-table td {
            padding: 14px 8px;
            text-align: left;
            white-space: nowrap;
        }

        .ticket-table th {
            color: #555;
            font-weight: 500;
        }

        .ticket-table tbody tr + tr {
            border-top: 1px solid #f0f1f2;
        }

        .ticket-progress {
            width: 100%;
            min-width: 100px;
            height: 10px;
            overflow: hidden;
            border-radius: 20px;
            background: #dcefed;
        }

        .ticket-progress span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: var(--analytics-green);
        }

        .side-panel {
            margin-bottom: 22px;
            padding: 20px;
            border: 1px solid var(--analytics-border);
            border-radius: 12px;
            background: var(--analytics-white);
        }

        .side-panel h2 {
            margin: 0 0 20px;
            font-size: 18px;
            font-weight: 600;
        }

        .data-list {
            display: grid;
            gap: 20px;
        }

        .data-list-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 14px;
        }

        .data-list-label {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .data-list-icon {
            width: 25px;
            flex-shrink: 0;
            color: #4d535c;
            font-size: 20px;
            text-align: center;
        }

        .unavailable-note {
            margin: 0;
            color: var(--analytics-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .payment-placeholder {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .payment-placeholder-circle {
            display: grid;
            width: 105px;
            height: 105px;
            flex-shrink: 0;
            place-items: center;
            border: 13px solid #e5e7eb;
            border-radius: 50%;
            color: #7b8190;
            font-size: 12px;
            text-align: center;
        }

        .empty-ticket-data {
            padding: 25px 10px;
            color: var(--analytics-muted);
            text-align: center;
        }

        .analytics-error {
            margin-bottom: 20px;
            padding: 14px 16px;
            border: 1px solid #f2c6c6;
            border-radius: 8px;
            background: #fff5f5;
            color: #9b2828;
            line-height: 1.5;
        }

        @media (max-width: 1250px) {
            .main {
                margin-left: 220px;
                padding-top: 90px;
            }

            .analytics-content {
                padding-right: 22px;
                padding-left: 22px;
            }

            .analytics-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .analytics-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .analytics-side-column {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0 16px;
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                padding-top: 75px;
            }

            .analytics-content {
                padding: 22px 18px 35px;
            }

            .analytics-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .event-filter {
                width: 100%;
                max-width: none;
            }

            .analytics-panel-header {
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
            }

            .analytics-side-column {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 560px) {
            .analytics-stats {
                grid-template-columns: minmax(0, 1fr);
            }

            .analytics-stat {
                min-height: 86px;
            }

            .analytics-panel,
            .side-panel {
                padding: 15px;
            }

            .analytics-panel-title {
                font-size: 18px;
            }

            .payment-placeholder {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<?php include dirname(__DIR__) . '/includes/organizer-sidebar.php'; ?>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/organizer-topbar.php'; ?>

    <main class="analytics-content">

        <div class="analytics-heading">
            <div>
                <h1>Analytics Overview</h1>
                <p>Track your event performance, sales, and activity.</p>
            </div>

            <form method="get">
                <select
                    class="event-filter"
                    name="event_id"
                    aria-label="Filter analytics by event"
                    onchange="this.form.submit()"
                >
                    <option value="">All Events</option>

                    <?php foreach ($events as $event): ?>
                        <option
                            value="<?= (int) $event['id'] ?>"
                            <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>
                        >
                            <?= analyticsEscape($event['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="analytics-error">
                <?= analyticsEscape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="analytics-stats" aria-label="Analytics summary">

            <article class="analytics-stat">
                <div class="analytics-stat-icon">▰</div>
                <div>
                    <div class="analytics-stat-label">Total Revenue</div>
                    <div class="analytics-stat-value">
                        <?= analyticsEscape(analyticsMoney($metrics['revenue'])) ?>
                    </div>
                </div>
            </article>

            <article class="analytics-stat">
                <div class="analytics-stat-icon">₣</div>
                <div>
                    <div class="analytics-stat-label">Tickets Sold / Remaining</div>
                    <div class="analytics-stat-value">
                        <?= number_format($metrics['tickets_sold']) ?>
                        /
                        <?= number_format($metrics['tickets_remaining']) ?>
                    </div>
                </div>
            </article>

            <article class="analytics-stat">
                <div class="analytics-stat-icon">%</div>
                <div>
                    <div class="analytics-stat-label">Conversion Rate</div>
                    <div class="analytics-stat-value">—</div>
                    <small class="unavailable-note">Tracking not configured</small>
                </div>
            </article>

            <article class="analytics-stat">
                <div class="analytics-stat-icon">↩</div>
                <div>
                    <div class="analytics-stat-label">Refund Issued</div>
                    <div class="analytics-stat-value">
                        <?= analyticsEscape(analyticsMoney($metrics['refunds'])) ?>
                    </div>
                </div>
            </article>

        </section>

        <div class="analytics-grid">

            <div class="analytics-main-column">

                <section class="analytics-panel">
                    <div class="analytics-panel-header">
                        <h2 class="analytics-panel-title">Revenue Performance</h2>

                        <div class="period-controls" aria-label="Revenue period">
                            <button type="button" class="period-button" data-period="daily">Daily</button>
                            <button type="button" class="period-button" data-period="weekly">Weekly</button>
                            <button type="button" class="period-button active" data-period="monthly">Monthly</button>
                        </div>
                    </div>

                    <?php if ($maxRevenue > 0 && !empty($chartPoints)): ?>
                        <div class="revenue-chart">
                            <svg viewBox="0 0 730 275" role="img" aria-label="Paid revenue for the last six months">
                                <g class="chart-grid">
                                    <?php for ($i = 0; $i < 5; $i++): ?>
                                        <?php $gridY = 50 + ($i * 42.5); ?>
                                        <line x1="55" y1="<?= $gridY ?>" x2="695" y2="<?= $gridY ?>" />
                                    <?php endfor; ?>
                                </g>

                                <g class="chart-label">
                                    <?php for ($i = 0; $i < 5; $i++): ?>
                                        <?php
                                        $gridValue = $maxRevenue * (1 - ($i / 4));
                                        $gridY = 54 + ($i * 42.5);
                                        ?>
                                        <text x="47" y="<?= $gridY ?>" text-anchor="end">
                                            <?= analyticsEscape(number_format($gridValue, 0, ',', ' ')) ?>
                                        </text>
                                    <?php endfor; ?>

                                    <?php foreach ($chartPoints as $point): ?>
                                        <text
                                            x="<?= round($point['x'], 2) ?>"
                                            y="248"
                                            text-anchor="middle"
                                        ><?= analyticsEscape($point['label']) ?></text>
                                    <?php endforeach; ?>
                                </g>

                                <path class="chart-area" d="<?= analyticsEscape($areaPath) ?>" />
                                <path class="chart-line" d="<?= analyticsEscape($linePath) ?>" />

                                <g class="chart-point">
                                    <?php foreach ($chartPoints as $point): ?>
                                        <circle
                                            cx="<?= round($point['x'], 2) ?>"
                                            cy="<?= round($point['y'], 2) ?>"
                                            r="4"
                                        >
                                            <title>
                                                <?= analyticsEscape($point['label']) ?>:
                                                <?= analyticsEscape(analyticsMoney($point['revenue'])) ?>
                                            </title>
                                        </circle>
                                    <?php endforeach; ?>
                                </g>
                            </svg>
                        </div>
                    <?php else: ?>
                        <div class="chart-empty">
                            <p>No paid revenue data is available for this period yet.</p>
                            <p>Revenue will appear here when orders are successfully paid.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-header">
                        <h2 class="analytics-panel-title">Ticket Performance by Type</h2>
                    </div>

                    <?php if (!empty($ticketRows)): ?>
                        <div class="ticket-table-wrap">
                            <table class="ticket-table">
                                <thead>
                                    <tr>
                                        <th>Ticket Type</th>
                                        <th>Sold</th>
                                        <th>Remaining</th>
                                        <th>Sales Progress</th>
                                        <th>Est. Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ticketRows as $ticket): ?>
                                        <?php
                                        $total = (int) $ticket['total_quantity'];
                                        $sold = (int) $ticket['sold'];
                                        $progress = $total > 0
                                            ? min(100, ($sold / $total) * 100)
                                            : 0;
                                        ?>
                                        <tr>
                                            <td><?= analyticsEscape($ticket['name']) ?></td>
                                            <td><?= number_format($sold) ?></td>
                                            <td><?= number_format((int) $ticket['remaining']) ?></td>
                                            <td>
                                                <div
                                                    class="ticket-progress"
                                                    role="progressbar"
                                                    aria-valuemin="0"
                                                    aria-valuemax="100"
                                                    aria-valuenow="<?= (int) round($progress) ?>"
                                                    aria-label="<?= analyticsEscape($ticket['name']) ?> sales progress"
                                                >
                                                    <span style="width: <?= $progress ?>%"></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?= analyticsEscape(analyticsMoney($ticket['estimated_revenue'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="unavailable-note">
                            Estimated ticket revenue is calculated from ticket price × quantity sold.
                            It may differ from paid-order revenue.
                        </p>
                    <?php else: ?>
                        <div class="empty-ticket-data">
                            No ticket types are available for this event selection yet.
                        </div>
                    <?php endif; ?>
                </section>

            </div>

            <aside class="analytics-side-column">

                <section class="side-panel">
                    <h2>Traffic Source</h2>

                    <p class="unavailable-note">
                        Traffic-source analytics are not yet connected. Once Ticketa records
                        visits and referral sources, this panel can show direct links,
                        social media, QR codes, and search traffic.
                    </p>
                </section>

                <section class="side-panel">
                    <h2>Orders &amp; Buyers</h2>

                    <div class="data-list">
                        <div class="data-list-row">
                            <span class="data-list-label">
                                <span class="data-list-icon">▣</span>
                                Paid orders
                            </span>
                            <strong><?= number_format($metrics['paid_orders']) ?></strong>
                        </div>

                        <div class="data-list-row">
                            <span class="data-list-label">
                                <span class="data-list-icon">◷</span>
                                All orders
                            </span>
                            <strong><?= number_format($metrics['total_orders']) ?></strong>
                        </div>
                    </div>

                    <p class="unavailable-note" style="margin-top: 18px;">
                        Social-platform buyer attribution is not available yet.
                    </p>
                </section>

                <section class="side-panel">
                    <h2>Payment Method Used</h2>

                    <div class="payment-placeholder">
                        <div class="payment-placeholder-circle">
                            No data
                        </div>
                        <p class="unavailable-note">
                            Payment-method statistics will appear when the payment records
                            and their method fields are connected to this report.
                        </p>
                    </div>
                </section>

            </aside>

        </div>
    </main>
</div>

<script>
(function () {
    // Period controls are visual for now. The chart currently shows monthly data.
    const periodButtons = document.querySelectorAll('.period-button');

    periodButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            periodButtons.forEach(function (item) {
                item.classList.remove('active');
            });

            button.classList.add('active');

            if (button.dataset.period !== 'monthly') {
                alert(
                    button.dataset.period.charAt(0).toUpperCase()
                    + button.dataset.period.slice(1)
                    + ' revenue reporting will be connected in the next analytics update.'
                );
            }
        });
    });

    // Shared topbar search: filter ticket-performance rows when present.
    const searchInput = document.getElementById('dashboardSearch');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLowerCase();

            document.querySelectorAll('.ticket-table tbody tr').forEach(function (row) {
                row.hidden = !row.textContent.toLowerCase().includes(query);
            });
        });
    }
})();
</script>

</body>
</html>
