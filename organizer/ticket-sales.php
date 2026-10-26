
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
$currentPage = 'ticket-sales.php';
$topbarSearchPlaceholder = 'Search orders';

function salesEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function salesMoney($amount): string
{
    return 'FCFA ' . number_format((float) $amount, 0, ',', ' ');
}

$organizerId = null;
$events = [];
$ticketRows = [];
$orders = [];
$chartRows = [];

$metrics = [
    'tickets_sold' => 0,
    'tickets_total' => 0,
    'revenue' => 0,
    'fees' => 0,
    'net_revenue' => 0,
];

$errorMessage = '';

$eventFilter = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$eventFilter = $eventFilter ?: null;

$period = $_GET['period'] ?? 'daily';

if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    $period = 'daily';
}

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
            'No organizer profile is linked to this account.'
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
        $eventFilter !== null &&
        !in_array((int) $eventFilter, $validEventIds, true)
    ) {
        $eventFilter = null;
    }

    $eventCondition = '';
    $params = ['organizer_id' => $organizerId];

    if ($eventFilter !== null) {
        $eventCondition = ' AND e.id = :event_id';
        $params['event_id'] = $eventFilter;
    }

    // Ticket inventory and quantities sold.
    $inventoryStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(tt.quantity_sold), 0) AS tickets_sold,
            COALESCE(SUM(tt.quantity_total), 0) AS tickets_total
         FROM ticket_types tt
         INNER JOIN events e ON e.id = tt.event_id
         WHERE e.organizer_id = :organizer_id
         $eventCondition"
    );

    $inventoryStmt->execute($params);
    $inventory = $inventoryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $metrics['tickets_sold'] = (int) ($inventory['tickets_sold'] ?? 0);
    $metrics['tickets_total'] = (int) ($inventory['tickets_total'] ?? 0);

    // Paid-order financial summary.
    $financialStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(
                CASE WHEN o.status = 'paid'
                     THEN o.subtotal ELSE 0 END
            ), 0) AS revenue,

            COALESCE(SUM(
                CASE WHEN o.status = 'paid'
                     THEN o.service_fee ELSE 0 END
            ), 0) AS fees

         FROM orders o
         INNER JOIN events e ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
         $eventCondition"
    );

    $financialStmt->execute($params);
    $financial = $financialStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $metrics['revenue'] = (float) ($financial['revenue'] ?? 0);
    $metrics['fees'] = (float) ($financial['fees'] ?? 0);
    $metrics['net_revenue'] = $metrics['revenue'] - $metrics['fees'];

    // Revenue chart period configuration.
    if ($period === 'daily') {
        $chartLabelFormat = '%b %e';
        $chartGroupFormat = '%Y-%m-%d';
        $chartStart = 'DATE_SUB(CURDATE(), INTERVAL 8 DAY)';
        $chartEnd = 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
        $chartCount = 9;
    } elseif ($period === 'weekly') {
        $chartLabelFormat = 'Week %u';
        $chartGroupFormat = '%x-%v';
        $chartStart = 'DATE_SUB(CURDATE(), INTERVAL 8 WEEK)';
        $chartEnd = 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
        $chartCount = 9;
    } else {
        $chartLabelFormat = '%b';
        $chartGroupFormat = '%Y-%m';
        $chartStart = "DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 8 MONTH)";
        $chartEnd = 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
        $chartCount = 9;
    }

    $chartStmt = $pdo->prepare(
        "SELECT
            DATE_FORMAT(o.created_at, '$chartGroupFormat') AS period_key,
            COALESCE(SUM(o.subtotal), 0) AS revenue
         FROM orders o
         INNER JOIN events e ON e.id = o.event_id
         WHERE e.organizer_id = :organizer_id
           AND o.status = 'paid'
           AND o.created_at >= $chartStart
           AND o.created_at < $chartEnd
           $eventCondition
         GROUP BY DATE_FORMAT(o.created_at, '$chartGroupFormat')
         ORDER BY period_key ASC"
    );

    $chartStmt->execute($params);
    $chartResult = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

    $revenueMap = [];

    foreach ($chartResult as $row) {
        $revenueMap[$row['period_key']] = (float) $row['revenue'];
    }

    if ($period === 'daily') {
        for ($i = 8; $i >= 0; $i--) {
            $date = new DateTimeImmutable('today');
            $date = $date->modify("-$i days");
            $key = $date->format('Y-m-d');

            $chartRows[] = [
                'key' => $key,
                'label' => $date->format('M j'),
                'revenue' => $revenueMap[$key] ?? 0,
            ];
        }
    } elseif ($period === 'weekly') {
        $today = new DateTimeImmutable('today');
        $monday = $today->modify('monday this week');

        for ($i = 8; $i >= 0; $i--) {
            $weekStart = $monday->modify("-$i weeks");
            $key = $weekStart->format('o-W');

            $chartRows[] = [
                'key' => $key,
                'label' => 'W' . $weekStart->format('W'),
                'revenue' => $revenueMap[$key] ?? 0,
            ];
        }
    } else {
        $firstMonth = new DateTimeImmutable('first day of this month');

        for ($i = 8; $i >= 0; $i--) {
            $month = $firstMonth->modify("-$i months");
            $key = $month->format('Y-m');

            $chartRows[] = [
                'key' => $key,
                'label' => $month->format('M'),
                'revenue' => $revenueMap[$key] ?? 0,
            ];
        }
    }

    // Ticket type performance.
    $ticketStmt = $pdo->prepare(
        "SELECT
            tt.id,
            tt.name,
            tt.price,
            COALESCE(tt.quantity_sold, 0) AS sold,
            GREATEST(
                COALESCE(tt.quantity_total, 0) -
                COALESCE(tt.quantity_sold, 0),
                0
            ) AS remaining,
            COALESCE(tt.quantity_sold * tt.price, 0) AS estimated_revenue
         FROM ticket_types tt
         INNER JOIN events e ON e.id = tt.event_id
         WHERE e.organizer_id = :organizer_id
         $eventCondition
         ORDER BY tt.quantity_sold DESC, tt.name ASC"
    );

    $ticketStmt->execute($params);
    $ticketRows = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

    // Latest orders for the organizer's events.
    $ordersStmt = $pdo->prepare(
        "SELECT
            o.id,
            o.customer_name,
            o.customer_email,
            o.currency,
            o.total_amount,
            o.status,
            o.created_at,
            e.title AS event_title,
            COALESCE(oi.ticket_name, 'Ticket') AS ticket_name,
            COALESCE(oi.quantity, 0) AS quantity
         FROM orders o
         INNER JOIN events e ON e.id = o.event_id
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE e.organizer_id = :organizer_id
         $eventCondition
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT 50"
    );

    $ordersStmt->execute($params);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Ticketa ticket sales error: ' . $e->getMessage());
    $errorMessage = 'We could not load your sales information. Please check the database configuration and try again.';
}

$chartMax = 0;

foreach ($chartRows as $row) {
    $chartMax = max($chartMax, (float) $row['revenue']);
}

$chartPoints = [];
$plotLeft = 55;
$plotRight = 695;
$plotTop = 28;
$plotBottom = 220;
$plotWidth = $plotRight - $plotLeft;
$plotHeight = $plotBottom - $plotTop;
$pointCount = count($chartRows);

foreach ($chartRows as $index => $row) {
    $x = $pointCount > 1
        ? $plotLeft + ($index * ($plotWidth / ($pointCount - 1)))
        : ($plotLeft + $plotWidth / 2);

    $y = $chartMax > 0
        ? $plotBottom - (($row['revenue'] / $chartMax) * $plotHeight)
        : $plotBottom;

    $chartPoints[] = [
        'x' => $x,
        'y' => $y,
        'label' => $row['label'],
        'revenue' => $row['revenue'],
    ];
}

$linePath = '';

foreach ($chartPoints as $index => $point) {
    $linePath .= ($index === 0 ? 'M ' : ' L ')
        . round($point['x'], 2) . ' '
        . round($point['y'], 2);
}

$areaPath = $linePath;

if (!empty($chartPoints)) {
    $first = $chartPoints[0];
    $last = $chartPoints[count($chartPoints) - 1];

    $areaPath .= ' L ' . $last['x'] . ' ' . $plotBottom;
    $areaPath .= ' L ' . $first['x'] . ' ' . $plotBottom . ' Z';
}

$filterQuery = $eventFilter !== null
    ? '&event_id=' . (int) $eventFilter
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Sales | TICKETA</title>

    <style>
        :root {
            --sales-green: #10bea0;
            --sales-green-dark: #08a98d;
            --sales-ink: #171c2e;
            --sales-muted: #778094;
            --sales-border: #e9ecef;
            --sales-bg: #fafbfc;
            --sales-yellow: #ffc83d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--sales-bg);
            color: var(--sales-ink);
            font-family: Inter, Arial, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 250px;
            padding-top: 90px;
        }

        .sales-content {
            padding: 20px 32px 40px;
        }

        .sales-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
        }

        .sales-heading h1 {
            margin: 0 0 8px;
            font-size: clamp(25px, 3vw, 32px);
            letter-spacing: -.6px;
        }

        .sales-heading p {
            margin: 0;
            color: var(--sales-muted);
            font-size: 16px;
        }

        .event-filter {
            min-width: 160px;
            max-width: 250px;
            padding: 12px 16px;
            border: 0;
            border-radius: 6px;
            background: var(--sales-green);
            color: white;
            font-size: 14px;
            cursor: pointer;
        }

        .event-filter option {
            background: white;
            color: var(--sales-ink);
        }

        .sales-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .sales-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            min-height: 103px;
            padding: 16px 14px;
            border: 1px solid var(--sales-border);
            border-radius: 12px;
            background: white;
        }

        .sales-stat-icon {
            display: grid;
            width: 50px;
            height: 50px;
            flex-shrink: 0;
            place-items: center;
            border-radius: 50%;
            background: var(--sales-green);
            color: white;
            font-size: 23px;
            font-weight: 700;
        }

        .sales-stat-label {
            margin-bottom: 8px;
            color: #454545;
            font-size: 14px;
            line-height: 1.4;
        }

        .sales-stat-value {
            overflow-wrap: anywhere;
            font-size: clamp(16px, 1.5vw, 22px);
            font-weight: 750;
        }

        .sales-panel {
            min-width: 0;
            margin-bottom: 22px;
            padding: 20px;
            border: 1px solid var(--sales-border);
            border-radius: 12px;
            background: white;
        }

        .sales-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .sales-panel-title {
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
            min-width: 80px;
            padding: 9px 14px;
            border: 0;
            border-right: 1px solid #b8b8b8;
            background: transparent;
            color: #292929;
            text-decoration: none;
            text-align: center;
            font-size: 14px;
        }

        .period-button:last-child {
            border-right: 0;
        }

        .period-button.active {
            background: var(--sales-yellow);
        }

        .sales-chart {
            width: 100%;
        }

        .sales-chart svg {
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

        .chart-area {
            fill: var(--sales-green);
            opacity: .13;
        }

        .chart-line {
            fill: none;
            stroke: #35c5aa;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .chart-point {
            fill: #119b5b;
        }

        .chart-label {
            fill: #555;
            font-size: 11px;
        }

        .chart-empty {
            padding: 50px 15px;
            color: var(--sales-muted);
            text-align: center;
        }

        .sales-tables {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
        }

        .sales-tables .sales-panel {
            margin-bottom: 0;
            padding: 0;
            overflow: hidden;
        }

        .sales-tables .sales-panel-header {
            padding: 20px 20px 0;
        }

        .sales-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .sales-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .sales-table th {
            padding: 12px 10px;
            background: var(--sales-green);
            color: white;
            font-weight: 500;
            text-align: left;
            white-space: nowrap;
        }

        .sales-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f0f1f2;
            color: #424242;
            white-space: nowrap;
        }

        .sales-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .ticket-progress {
            width: 100%;
            min-width: 65px;
            height: 8px;
            overflow: hidden;
            border-radius: 20px;
            background: #dcefed;
        }

        .ticket-progress span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: var(--sales-green);
        }

        .order-status {
            display: inline-block;
            min-width: 75px;
            padding: 6px 9px;
            border-radius: 4px;
            background: #e9f7ef;
            color: #31875a;
            text-align: center;
            text-transform: capitalize;
        }

        .order-status.pending {
            background: #fff6dd;
            color: #9b7600;
        }

        .order-status.failed,
        .order-status.cancelled,
        .order-status.refunded,
        .order-status.expired {
            background: #fff0f0;
            color: #b23b3b;
        }

        .empty-state {
            padding: 30px 16px;
            color: var(--sales-muted);
            text-align: center;
            font-size: 14px;
        }

        .sales-error {
            margin-bottom: 20px;
            padding: 14px 16px;
            border: 1px solid #f2c6c6;
            border-radius: 8px;
            background: #fff5f5;
            color: #9b2828;
        }

        .sales-note {
            margin: 14px 16px;
            color: var(--sales-muted);
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 1250px) {
            .main {
                margin-left: 220px;
                padding-top: 90px;
            }

            .sales-content {
                padding-right: 22px;
                padding-left: 22px;
            }

            .sales-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sales-tables {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                padding-top: 75px;
            }

            .sales-content {
                padding: 22px 16px 32px;
            }

            .sales-heading {
                align-items: stretch;
                flex-direction: column;
            }

            .event-filter {
                width: 100%;
                max-width: none;
            }

            .sales-panel-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .period-controls {
                width: 100%;
            }

            .period-button {
                flex: 1;
                min-width: 0;
                padding: 9px 6px;
            }

            .sales-panel {
                padding: 16px;
            }
        }

        @media (max-width: 560px) {
            .sales-stats {
                grid-template-columns: minmax(0, 1fr);
            }

            .sales-stat {
                min-height: 85px;
            }

            .sales-panel-title {
                font-size: 18px;
            }

            .sales-tables .sales-panel-header {
                padding: 16px 16px 0;
            }
        }
    </style>
</head>

<body>

<?php include dirname(__DIR__) . '/includes/organizer-sidebar.php'; ?>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/organizer-topbar.php'; ?>

    <main class="sales-content">

        <header class="sales-heading">
            <div>
                <h1>Ticket Sales</h1>
                <p>Track and manage your ticket sales</p>
            </div>

            <form method="get">
                <input type="hidden" name="period" value="<?= salesEscape($period) ?>">

                <select
                    name="event_id"
                    class="event-filter"
                    aria-label="Filter sales by event"
                    onchange="this.form.submit()"
                >
                    <option value="">All Events</option>

                    <?php foreach ($events as $event): ?>
                        <option
                            value="<?= (int) $event['id'] ?>"
                            <?= $eventFilter === (int) $event['id'] ? 'selected' : '' ?>
                        >
                            <?= salesEscape($event['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </header>

        <?php if ($errorMessage !== ''): ?>
            <div class="sales-error">
                <?= salesEscape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="sales-stats" aria-label="Sales summary">

            <article class="sales-stat">
                <div class="sales-stat-icon">▰</div>
                <div>
                    <div class="sales-stat-label">Total Tickets Sold</div>
                    <div class="sales-stat-value">
                        <?= number_format($metrics['tickets_sold']) ?>
                        /
                        <?= number_format($metrics['tickets_total']) ?>
                    </div>
                </div>
            </article>

            <article class="sales-stat">
                <div class="sales-stat-icon">₣</div>
                <div>
                    <div class="sales-stat-label">Total Revenue</div>
                    <div class="sales-stat-value">
                        <?= salesEscape(salesMoney($metrics['revenue'])) ?>
                    </div>
                </div>
            </article>

            <article class="sales-stat">
                <div class="sales-stat-icon">₣</div>
                <div>
                    <div class="sales-stat-label">Platform Fees</div>
                    <div class="sales-stat-value">
                        <?= salesEscape(salesMoney($metrics['fees'])) ?>
                    </div>
                </div>
            </article>

            <article class="sales-stat">
                <div class="sales-stat-icon">↗</div>
                <div>
                    <div class="sales-stat-label">Net Revenue</div>
                    <div class="sales-stat-value">
                        <?= salesEscape(salesMoney($metrics['net_revenue'])) ?>
                    </div>
                </div>
            </article>

        </section>

        <section class="sales-panel">

            <div class="sales-panel-header">
                <h2 class="sales-panel-title">Revenue Performance</h2>

                <nav class="period-controls" aria-label="Revenue reporting period">
                    <a
                        class="period-button <?= $period === 'daily' ? 'active' : '' ?>"
                        href="?period=daily<?= $filterQuery ?>"
                    >Daily</a>

                    <a
                        class="period-button <?= $period === 'weekly' ? 'active' : '' ?>"
                        href="?period=weekly<?= $filterQuery ?>"
                    >Weekly</a>

                    <a
                        class="period-button <?= $period === 'monthly' ? 'active' : '' ?>"
                        href="?period=monthly<?= $filterQuery ?>"
                    >Monthly</a>
                </nav>
            </div>

            <?php if (!empty($chartPoints) && $chartMax > 0): ?>
                <div class="sales-chart">
                    <svg viewBox="0 0 730 275" role="img" aria-label="Paid ticket revenue chart">
                        <g class="chart-grid">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <?php $gridY = $plotTop + ($i * ($plotHeight / 4)); ?>
                                <line
                                    x1="<?= $plotLeft ?>"
                                    y1="<?= $gridY ?>"
                                    x2="<?= $plotRight ?>"
                                    y2="<?= $gridY ?>"
                                />
                            <?php endfor; ?>
                        </g>

                        <g class="chart-label">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <?php
                                $gridValue = $chartMax * (1 - ($i / 4));
                                $gridY = $plotTop + ($i * ($plotHeight / 4)) + 4;
                                ?>
                                <text x="47" y="<?= $gridY ?>" text-anchor="end">
                                    <?= salesEscape(number_format($gridValue, 0, ',', ' ')) ?>
                                </text>
                            <?php endfor; ?>

                            <?php foreach ($chartPoints as $point): ?>
                                <text
                                    x="<?= round($point['x'], 2) ?>"
                                    y="247"
                                    text-anchor="middle"
                                ><?= salesEscape($point['label']) ?></text>
                            <?php endforeach; ?>
                        </g>

                        <path class="chart-area" d="<?= salesEscape($areaPath) ?>" />
                        <path class="chart-line" d="<?= salesEscape($linePath) ?>" />

                        <g class="chart-point">
                            <?php foreach ($chartPoints as $point): ?>
                                <circle
                                    cx="<?= round($point['x'], 2) ?>"
                                    cy="<?= round($point['y'], 2) ?>"
                                    r="4"
                                >
                                    <title>
                                        <?= salesEscape($point['label']) ?>:
                                        <?= salesEscape(salesMoney($point['revenue'])) ?>
                                    </title>
                                </circle>
                            <?php endforeach; ?>
                        </g>
                    </svg>
                </div>
            <?php else: ?>
                <div class="chart-empty">
                    No paid ticket revenue is available for this period yet.
                </div>
            <?php endif; ?>

        </section>

        <div class="sales-tables">

            <section class="sales-panel">
                <div class="sales-panel-header">
                    <h2 class="sales-panel-title">Ticket Type Performance</h2>
                </div>

                <?php if (!empty($ticketRows)): ?>
                    <div class="sales-table-wrap">
                        <table class="sales-table">
                            <thead>
                                <tr>
                                    <th>Ticket Type</th>
                                    <th>Price</th>
                                    <th>Tickets Sold</th>
                                    <th>Tickets Left</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($ticketRows as $ticket): ?>
                                    <tr>
                                        <td><?= salesEscape($ticket['name']) ?></td>

                                        <td>
                                            <?= salesEscape(salesMoney($ticket['price'])) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $ticket['sold']) ?>
                                        </td>

                                        <td>
                                            <?= number_format((int) $ticket['remaining']) ?>
                                        </td>

                                        <td>
                                            <?= salesEscape(salesMoney($ticket['estimated_revenue'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="sales-note">
                        Ticket-type revenue is estimated using price multiplied by quantity sold.
                        Confirmed revenue is calculated from paid orders.
                    </p>
                <?php else: ?>
                    <div class="empty-state">
                        No ticket types found for this event selection.
                    </div>
                <?php endif; ?>
            </section>

            <section class="sales-panel">
                <div class="sales-panel-header">
                    <h2 class="sales-panel-title">Orders &amp; Buyers</h2>
                </div>

                <?php if (!empty($orders)): ?>
                    <div class="sales-table-wrap">
                        <table class="sales-table" id="ordersTable">
                            <thead>
                                <tr>
                                    <th>Buyer Name</th>
                                    <th>Ticket Type</th>
                                    <th>Qty</th>
                                    <th>Payment</th>
                                    <th>Purchase Date</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <?php
                                    $status = strtolower((string) $order['status']);
                                    $statusClass = preg_replace(
                                        '/[^a-z]/',
                                        '',
                                        $status
                                    );
                                    ?>
                                    <tr>
                                        <td>
                                            <span><?= salesEscape($order['customer_name'] ?: 'Guest') ?></span>
                                            <?php if (!empty($order['customer_email'])): ?>
                                                <br>
                                                <small style="color:#778094;">
                                                    <?= salesEscape($order['customer_email']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <td><?= salesEscape($order['ticket_name']) ?></td>

                                        <td><?= number_format((int) $order['quantity']) ?></td>

                                        <td>
                                            <span class="order-status <?= salesEscape($statusClass) ?>">
                                                <?= salesEscape($status) ?>
                                            </span>
                                            <br>
                                            <small>
                                                <?= salesEscape(salesMoney($order['total_amount'])) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= salesEscape(
                                                date('M j, Y', strtotime($order['created_at']))
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        No orders have been recorded for this event selection yet.
                    </div>
                <?php endif; ?>
            </section>

        </div>

    </main>
</div>

<script>
(function () {
    const searchInput = document.getElementById('dashboardSearch');
    const ordersTable = document.getElementById('ordersTable');

    if (searchInput && ordersTable) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLowerCase();

            ordersTable.querySelectorAll('tbody tr').forEach(function (row) {
                row.hidden = !row.textContent.toLowerCase().includes(query);
            });
        });
    }
})();
</script>

</body>
</html>
