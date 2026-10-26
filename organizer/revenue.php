<?php
declare(strict_types=1);

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

function revenueEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function revenueMoney(float|int|string|null $amount): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' FCFA';
}

$event = null;
$ticketBreakdown = [];
$salesOverTime = [];
$totalRevenue = 0.0;
$netRevenue = 0.0;
$platformRevenue = 0.0;
$processingFees = 0.0;
$ticketsSold = 0;
$ticketCapacity = 0;
$loadError = '';

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
if (!$eventId || $eventId < 1) {
    http_response_code(400);
    $loadError = 'A valid event was not selected. Please return to Events and choose Revenue for an event.';
} else {
    try {
        // events.organizer_id refers to organizers.id, not users.id.
        $profileStmt = $pdo->prepare(
            'SELECT id, business_name
             FROM organizers
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $profileStmt->execute(['user_id' => (int) ($user['id'] ?? 0)]);
        $organizer = $profileStmt->fetch(PDO::FETCH_ASSOC);

        if (!$organizer) {
            throw new RuntimeException('No organizer profile is linked to this account.');
        }

        // Ownership is checked in the event query so another organizer's event cannot be viewed.
        $eventStmt = $pdo->prepare(
            'SELECT id, title, event_date, event_end_date, venue, address, city, status, image_url
             FROM events
             WHERE id = :event_id AND organizer_id = :organizer_id
             LIMIT 1'
        );
        $eventStmt->execute([
            'event_id' => $eventId,
            'organizer_id' => (int) $organizer['id'],
        ]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            http_response_code(404);
            throw new RuntimeException('This event could not be found in your organizer account.');
        }

        // Only paid orders count as revenue.
        $revenueStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(o.total_amount), 0) AS total_revenue,
                COALESCE(SUM(o.subtotal), 0) AS ticket_subtotal,
                COALESCE(SUM(o.service_fee), 0) AS service_fees
             FROM orders o
             WHERE o.event_id = :event_id AND o.status = 'paid'"
        );
        $revenueStmt->execute(['event_id' => $eventId]);
        $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalRevenue = (float) ($revenue['total_revenue'] ?? 0);
        $platformRevenue = (float) ($revenue['service_fees'] ?? 0);

        // No processing-fee column is assumed because the schema provided so far does not confirm one.
        // Net revenue is shown as paid ticket subtotal until payment-provider fee data is connected.
        $netRevenue = (float) ($revenue['ticket_subtotal'] ?? 0);
        $processingFees = max(0, $totalRevenue - $netRevenue - $platformRevenue);

        $ticketStmt = $pdo->prepare(
            'SELECT
                tt.id,
                tt.name,
                tt.price,
                tt.quantity_total,
                tt.quantity_sold,
                (tt.price * tt.quantity_sold) AS ticket_gross
             FROM ticket_types tt
             WHERE tt.event_id = :event_id
             ORDER BY tt.price ASC, tt.id ASC'
        );
        $ticketStmt->execute(['event_id' => $eventId]);
        $ticketBreakdown = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ticketBreakdown as $ticket) {
            $ticketsSold += (int) $ticket['quantity_sold'];
            $ticketCapacity += (int) $ticket['quantity_total'];
        }

        // Daily paid-order totals for the Sales Over Time chart.
        $salesStmt = $pdo->prepare(
            "SELECT DATE(o.created_at) AS sale_day,
                    COALESCE(SUM(o.total_amount), 0) AS day_revenue
             FROM orders o
             WHERE o.event_id = :event_id
               AND o.status = 'paid'
             GROUP BY DATE(o.created_at)
             ORDER BY sale_day ASC"
        );
        $salesStmt->execute(['event_id' => $eventId]);
        $salesOverTime = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Ticketa event revenue error: ' . $e->getMessage());
        if ($loadError === '') {
            $loadError = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'We could not load this event revenue. Please check the database and try again.';
        }
    }
}

$eventStatus = (string) ($event['status'] ?? '');
$isPayoutAvailable = $eventStatus === 'completed';
$eventTitle = (string) ($event['title'] ?? 'Event Revenue');
$venueParts = array_filter([
    trim((string) ($event['venue'] ?? '')),
    trim((string) ($event['city'] ?? '')),
]);
$venueText = $venueParts ? implode(', ', $venueParts) : 'Venue to be announced';
$eventDate = !empty($event['event_date'])
    ? date('D, d M Y · H:i', strtotime((string) $event['event_date']))
    : 'Date not set';

$maxChartRevenue = 0.0;
foreach ($salesOverTime as $sale) {
    $maxChartRevenue = max($maxChartRevenue, (float) $sale['day_revenue']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Revenue | TICKETA</title>
    <style>
        :root{--green:#10c6a4;--green-dark:#08ad91;--ink:#171c2e;--muted:#778094;--border:#e9ecef;--background:#fafbfc;--white:#fff}
        *{box-sizing:border-box}
        body{margin:0;background:var(--white);color:var(--ink);font-family:Inter,Arial,sans-serif}
        a{color:inherit;text-decoration:none}
        button{font:inherit}
        .sidebar{position:fixed;inset:0 auto 0 0;z-index:10;width:250px;padding:24px 22px;border-right:1px solid var(--border);background:var(--white)}
        .brand{display:flex;align-items:center;gap:12px;margin-bottom:58px;font-size:26px;font-weight:800;letter-spacing:-1px}
        .brand-icon{display:grid;width:39px;height:31px;place-items:center;border:3px solid var(--green);border-radius:7px;color:var(--green-dark);font-size:19px;font-weight:800}
        .nav{display:grid;gap:14px}
        .nav-link{display:flex;align-items:center;gap:14px;min-height:46px;padding:12px 15px;border-radius:4px;color:#777b83;font-size:15px;transition:background .2s,color .2s}
        .nav-link:hover{background:#edfaf7;color:#087f6b}
        .nav-link.active{background:var(--green);color:#fff;box-shadow:0 3px 0 rgba(8,173,145,.18)}
        .nav-icon{display:inline-grid;width:22px;place-items:center;font-size:20px}
        .main{min-height:100vh;margin-left:250px}
        .topbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:90px;padding:18px 32px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.96);backdrop-filter:blur(12px)}
        .breadcrumb{display:flex;align-items:center;gap:12px;min-width:0;max-width:600px;padding:13px 15px;border-radius:12px;background:#f4f6f6;color:#777b83;font-size:15px;font-weight:600}
        .breadcrumb a{font-size:25px;color:#222}
        .topbar-right{display:flex;align-items:center;gap:18px}
        .avatar{display:grid;width:35px;height:35px;place-items:center;border:1px solid var(--border);border-radius:50%;background:#e5faf4;color:#087f6b;font-weight:800}
        .organizer-profile{display:flex;align-items:center;gap:10px;max-width:220px;font-size:14px;font-weight:600}
        .content{padding:16px 32px 36px}
        .heading-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px}
        h1{margin:0 0 7px;font-size:clamp(27px,3vw,32px);letter-spacing:-.7px}
        .subtitle{margin:0;color:var(--muted);font-size:16px}
        .button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 17px;border:0;border-radius:5px;background:var(--green);color:#fff;font-size:14px;font-weight:650;cursor:pointer}
        .button:hover{background:var(--green-dark)}
        .button.disabled{background:#a9dcd3;cursor:not-allowed}
        .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) minmax(240px,1.5fr);gap:12px;margin-bottom:42px}
        .card{min-width:0;padding:18px;border:1px solid var(--border);border-radius:5px;background:#fff}
        .stat-label{margin-bottom:10px;color:#777;font-size:16px}
        .stat-value{overflow-wrap:anywhere;font-size:clamp(18px,1.8vw,24px);font-weight:750}
        .stat-help{margin:12px 0 0;color:#777;font-size:13px;line-height:1.45}
        .payout-heading{display:flex;align-items:center;gap:12px;color:#777;font-size:16px}
        .payout-amount{margin:14px 0;text-align:center;font-size:clamp(19px,1.8vw,24px);font-weight:750}
        .payout-card .button{width:100%;margin-top:4px}
        .section-title{margin:0 0 6px;font-size:25px;font-weight:500;letter-spacing:-.5px}
        .section-subtitle{margin:0 0 14px;color:var(--muted);font-size:15px}
        .revenue-layout{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(270px,1fr);gap:24px}
        .left-column,.right-column{display:grid;align-content:start;gap:24px;min-width:0}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:650px}
        th,td{padding:13px 10px;text-align:left;vertical-align:top;font-size:14px}
        th{color:#777;font-weight:500;line-height:1.4}
        td{color:#292929}
        tfoot td{border-top:1px solid var(--border);font-weight:700}
        .chart-title,.panel-title{display:flex;align-items:center;gap:10px;margin:0 0 16px;font-size:18px;font-weight:600}
        .chart{display:flex;align-items:flex-end;gap:10px;min-height:180px;padding:12px 0 0;border-bottom:1px solid var(--border)}
        .bar-item{display:flex;flex:1;flex-direction:column;align-items:center;justify-content:flex-end;gap:7px;min-width:0;height:165px}
        .bar{width:min(38px,70%);min-height:2px;border-radius:5px 5px 0 0;background:#a8e7d9}
        .bar-label{max-width:100%;overflow:hidden;color:#777;font-size:11px;text-overflow:ellipsis;white-space:nowrap}
        .bar-value{max-width:100%;overflow:hidden;color:#777;font-size:10px;text-overflow:ellipsis;white-space:nowrap}
        .notice{display:flex;gap:12px;align-items:flex-start;line-height:1.5;color:#333}
        .notice-icon{font-size:22px}
        .payout-copy{margin:0 0 12px;font-size:16px;line-height:1.5}
        .muted-copy{margin:0;color:#777;font-size:13px;line-height:1.5}
        .alert{margin-bottom:18px;padding:14px 16px;border:1px solid #f0cccc;border-radius:8px;background:#fff2f2;color:#a52828;font-size:14px;line-height:1.6}
        .empty{padding:24px 10px;text-align:center;color:var(--muted)}
        @media(max-width:1350px){.sidebar{width:220px;padding-right:16px;padding-left:16px}.main{margin-left:220px}.content{padding-right:20px;padding-left:20px}.topbar{padding-right:20px;padding-left:20px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.revenue-layout{grid-template-columns:minmax(0,1.6fr) minmax(250px,1fr)}}
        @media(max-width:950px){.sidebar{z-index:20;width:250px;transform:translateX(-100%);transition:transform .25s ease}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.menu-toggle{display:grid}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.revenue-layout{grid-template-columns:1fr}.topbar{min-height:75px;padding:14px 18px}.content{padding:22px 18px}.breadcrumb{font-size:13px}.organizer-name{display:none}}
        @media(min-width:951px){.menu-toggle{display:none}}
        @media(max-width:560px){.heading-row{align-items:flex-start;flex-direction:column}.heading-row .button{width:100%}.stats{grid-template-columns:1fr}.content{padding:18px 14px}.card{padding:15px}.topbar{gap:8px;padding:12px}.topbar-right{gap:8px}.breadcrumb{padding:10px;gap:7px}.organizer-profile{gap:0}}
    </style>
</head>
<body>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="dashboard.php"><span class="brand-icon">T</span><span>TICKETA</span></a>
    <nav class="nav" aria-label="Organizer navigation">
        <a class="nav-link" href="dashboard.php"><span class="nav-icon">▦</span>Dashboard</a>
        <a class="nav-link active" href="events.php"><span class="nav-icon">▣</span>Events</a>
        <a class="nav-link" href="analytics.php"><span class="nav-icon">♧</span>Analytics</a>
        <a class="nav-link" href="ticket-sales.php?event_id=<?= (int) $eventId ?>"><span class="nav-icon">▤</span>Ticket Sales</a>
        <a class="nav-link" href="check-ins.php?event_id=<?= (int) $eventId ?>"><span class="nav-icon">▣</span>Check-Ins</a>
        <a class="nav-link" href="settings.php"><span class="nav-icon">⚙</span>Settings</a>
    </nav>
</aside>
<div class="main">
    <header class="topbar">
        <div class="breadcrumb"><a href="events.php" aria-label="Back to events">←</a><span>Events / <?= revenueEscape($eventTitle) ?> / Revenue</span></div>
        <div class="topbar-right">
            <span aria-label="Notifications" title="Notifications">♟</span>
            <div class="organizer-profile"><span class="avatar"><?= revenueEscape(strtoupper(mb_substr((string) ($user['full_name'] ?? 'O'), 0, 1))) ?></span><span class="organizer-name"><?= revenueEscape((string) ($user['full_name'] ?? 'Organizer')) ?></span></div>
        </div>
    </header>
    <main class="content">
        <?php if ($loadError !== ''): ?>
            <div class="alert" role="alert"><?= revenueEscape($loadError) ?></div>
            <a class="button" href="events.php">← Back to Events</a>
        <?php elseif ($event): ?>
            <div class="heading-row">
                <div><h1><?= revenueEscape($eventTitle) ?></h1><p class="subtitle"><?= revenueEscape($venueText) ?></p><p class="subtitle"><?= revenueEscape($eventDate) ?></p></div>
                <button class="button" type="button" onclick="window.print()">Export Report</button>
            </div>

            <section class="stats" aria-label="Event revenue summary">
                <article class="card">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value"><?= revenueEscape(revenueMoney($totalRevenue)) ?></div>
                    <p class="stat-help">Total amount from paid orders for this event.</p>
                </article>
                <article class="card">
                    <div class="stat-label">Net Revenue</div>
                    <div class="stat-value"><?= revenueEscape(revenueMoney($netRevenue)) ?></div>
                    <p class="stat-help">Paid ticket subtotal before any unconfirmed provider-fee calculation.</p>
                </article>
                <article class="card">
                    <div class="stat-label">Total Tickets Sold</div>
                    <div class="stat-value"><?= number_format($ticketsSold) ?> / <?= number_format($ticketCapacity) ?></div>
                    <p class="stat-help">Tickets marked sold across this event's ticket types.</p>
                </article>
                <article class="card payout-card">
                    <div class="payout-heading"><span><?= $isPayoutAvailable ? '▤' : '🔒' ?></span><span>Payout Status <?= $isPayoutAvailable ? 'Available' : 'Locked' ?></span></div>
                    <div class="payout-amount"><?= $isPayoutAvailable ? revenueEscape(revenueMoney($netRevenue)) : 'Funds are locked' ?></div>
                    <p class="stat-help"><?= $isPayoutAvailable ? 'The event is marked completed. Withdrawal will be connected in a later step.' : 'Ticket revenue is securely held and will be released when the event is marked completed and payout eligibility is confirmed.' ?></p>
                    <button class="button <?= $isPayoutAvailable ? '' : 'disabled' ?>" type="button" <?= $isPayoutAvailable ? 'disabled title="Withdrawal flow will be connected later"' : 'disabled aria-disabled="true"' ?>><?= $isPayoutAvailable ? 'Withdraw Funds (Coming Later)' : 'Withdraw (Locked)' ?></button>
                </article>
            </section>

            <h2 class="section-title">Revenue Breakdown</h2>
            <p class="section-subtitle">Revenue by ticket type, based on ticket prices and recorded sales.</p>
            <div class="revenue-layout">
                <div class="left-column">
                    <section class="card table-wrap" aria-label="Revenue by ticket type">
                        <table>
                            <thead><tr><th>Ticket Type</th><th>Ticket Revenue</th><th>Tickets Sold</th><th>Ticket Price</th></tr></thead>
                            <tbody>
                            <?php if (!$ticketBreakdown): ?>
                                <tr><td colspan="4" class="empty">No ticket types have been created for this event yet.</td></tr>
                            <?php else: foreach ($ticketBreakdown as $ticket): ?>
                                <tr>
                                    <td><?= revenueEscape((string) $ticket['name']) ?></td>
                                    <td><?= revenueEscape(revenueMoney((float) $ticket['ticket_gross'])) ?></td>
                                    <td><?= number_format((int) $ticket['quantity_sold']) ?></td>
                                    <td><?= revenueEscape(revenueMoney((float) $ticket['price'])) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                            <tfoot><tr><td>Total</td><td><?= revenueEscape(revenueMoney(array_sum(array_map(static fn($t) => (float) $t['ticket_gross'], $ticketBreakdown)))) ?></td><td><?= number_format($ticketsSold) ?></td><td>—</td></tr></tfoot>
                        </table>
                    </section>
                    <section class="card notice"><span class="notice-icon">▤</span><span>Ticketa securely holds ticket revenue until event completion to protect attendees and organizers.</span></section>
                </div>
                <div class="right-column">
                    <section class="card">
                        <h3 class="chart-title">↗ Sales Over Time</h3>
                        <?php if (!$salesOverTime): ?>
                            <div class="empty">Sales history will appear here after paid orders are recorded.</div>
                        <?php else: ?>
                            <div class="chart" role="img" aria-label="Daily paid-order revenue chart">
                                <?php foreach ($salesOverTime as $sale):
                                    $value = (float) $sale['day_revenue'];
                                    $height = $maxChartRevenue > 0 ? max(3, ($value / $maxChartRevenue) * 130) : 3;
                                ?>
                                    <div class="bar-item" title="<?= revenueEscape((string) $sale['sale_day']) ?>: <?= revenueEscape(revenueMoney($value)) ?>">
                                        <span class="bar-value"><?= revenueEscape(number_format($value / 1000, 0)) ?>k</span>
                                        <div class="bar" style="height:<?= (float) $height ?>px"></div>
                                        <span class="bar-label"><?= revenueEscape(date('d M', strtotime((string) $sale['sale_day']))) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="card">
                        <h3 class="panel-title">▢ Next Payout</h3>
                        <p class="payout-copy"><?= $isPayoutAvailable ? 'Event marked completed' : 'Payout Scheduled After Event Completion' ?></p>
                        <p class="muted-copy"><?= $isPayoutAvailable ? 'Your event is marked completed. The withdrawal flow will be added separately.' : 'All eligible ticket revenue remains locked until the event is marked completed.' ?></p>
                    </section>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
