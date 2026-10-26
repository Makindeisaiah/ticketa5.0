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

$organizerId = 0; // Set from the linked organizer profile below.

$organizerName = $user['full_name'] ?? 'Organizer';

$errors = [];

$events = [];

$summary = [

    'total_events' => 0,

    'active_events' => 0,

    'tickets_sold' => 0,

    'net_revenue' => 0,

];

$search = trim((string) ($_GET['search'] ?? ''));

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

if ($page === false || $page === null || $page < 1) {

    $page = 1;

}

$perPage = 3;

$totalPages = 1;

$totalFilteredEvents = 0;

function eventsEscape(string $value): string

{

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

}

function eventsMoney(int|float|string $amount): string

{

    return number_format((float) $amount, 0, ',', ' ') . ' FCFA';

}

function eventsDate(string|null $date): string

{

    if (!$date) {

        return 'Date not set';

    }

    try {

        return (new DateTime($date))->format('D, d M · H:i');

    } catch (Throwable $e) {

        return 'Date not available';

    }

}

function eventsStatus(array $event): array

{

    $status = $event['status'] ?? 'draft';

    if ($status === 'cancelled') {

        return ['Cancelled', 'cancelled'];

    }

    if ($status === 'completed') {

        return ['Completed', 'completed'];

    }

    if ($status === 'draft') {

        return ['Draft', 'draft'];

    }

    if ($status !== 'published') {

        return ['Unknown', 'draft'];

    }

    $now = time();

    $start = strtotime((string) $event['event_date']);

    $end = !empty($event['event_end_date'])

        ? strtotime((string) $event['event_end_date'])

        : $start;

    if ($end !== false && $end < $now) {

        return ['Completed', 'completed'];

    }

    if ($start !== false && $start <= $now) {

        return ['Live', 'live'];

    }

    return ['Upcoming', 'upcoming'];

}

function eventsSalesStatus(float $percentage): array

{

    if ($percentage >= 70) {

        return ['Selling Fast', 'fast'];

    }

    if ($percentage >= 40) {

        return ['Average Sales', 'average'];

    }

    return ['Low Sales', 'low'];

}

function eventsPageUrl(int $page, string $search): string

{

    $params = ['page' => $page];

    if ($search !== '') {

        $params['search'] = $search;

    }

    return 'events.php?' . http_build_query($params);

}

try {

    // Get the organizer profile linked to the logged-in user.
    // events.organizer_id stores organizers.id, not users.id.
    $profileStmt = $pdo->prepare(
        'SELECT id, business_name
         FROM organizers
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $profileStmt->execute(['user_id' => (int) $user['id']]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new RuntimeException(
            'No organizer profile is linked to the logged-in account.'
        );
    }

    $organizerId = (int) $profile['id'];

    if (!empty($profile['business_name'])) {

        $organizerName = $profile['business_name'];

    }

    // Handle a confirmed event deletion. Ownership is checked server-side.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && (string) ($_POST['action'] ?? '') === 'delete_event') {

        if (!authVerifyCsrf()) {
            $errors[] = 'Your session expired. Refresh the page and try again.';
        } else {
            $deleteEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
            $typedConfirmation = strtoupper(trim((string) ($_POST['delete_confirmation'] ?? '')));

            if (!$deleteEventId || $deleteEventId < 1) {
                $errors[] = 'The selected event is invalid.';
            } elseif ($typedConfirmation !== 'DELETE') {
                $errors[] = 'Type DELETE exactly to confirm event deletion.';
            } else {
                $pdo->beginTransaction();
                try {
                    $ownedEventStmt = $pdo->prepare(
                        'SELECT id, title FROM events WHERE id = :event_id AND organizer_id = :organizer_id LIMIT 1 FOR UPDATE'
                    );
                    $ownedEventStmt->execute([
                        'event_id' => $deleteEventId,
                        'organizer_id' => $organizerId,
                    ]);
                    $ownedEvent = $ownedEventStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$ownedEvent) {
                        throw new RuntimeException('Event not found or you do not have permission to delete it.');
                    }

                    // Keep ticket/order history intact. Events with any orders cannot be deleted here.
                    $ordersStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM orders WHERE event_id = :event_id'
                    );
                    $ordersStmt->execute(['event_id' => $deleteEventId]);
                    if ((int) $ordersStmt->fetchColumn() > 0) {
                        throw new RuntimeException('This event has order records. For safety, cancel or archive it instead of deleting ticket/order history.');
                    }

                    $ticketsStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM issued_tickets it
                         INNER JOIN order_items oi ON oi.id = it.order_item_id
                         INNER JOIN orders o ON o.id = oi.order_id
                         WHERE o.event_id = :event_id'
                    );
                    $ticketsStmt->execute(['event_id' => $deleteEventId]);
                    if ((int) $ticketsStmt->fetchColumn() > 0) {
                        throw new RuntimeException('This event has issued tickets and cannot be deleted safely.');
                    }

                    $deleteTypesStmt = $pdo->prepare(
                        'DELETE FROM ticket_types WHERE event_id = :event_id'
                    );
                    $deleteTypesStmt->execute(['event_id' => $deleteEventId]);

                    $deleteEventStmt = $pdo->prepare(
                        'DELETE FROM events WHERE id = :event_id AND organizer_id = :organizer_id'
                    );
                    $deleteEventStmt->execute([
                        'event_id' => $deleteEventId,
                        'organizer_id' => $organizerId,
                    ]);
                    if ($deleteEventStmt->rowCount() !== 1) {
                        throw new RuntimeException('The event could not be deleted. Please refresh and try again.');
                    }

                    $pdo->commit();
                    $redirectParams = [];
                    if ($search !== '') { $redirectParams['search'] = $search; }
                    $redirectParams['deleted'] = '1';
                    header('Location: events.php?' . http_build_query($redirectParams));
                    exit;
                } catch (Throwable $deleteError) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $errors[] = $deleteError instanceof RuntimeException
                        ? $deleteError->getMessage()
                        : 'The event could not be deleted because related records may still exist. No changes were saved.';
                    error_log('Ticketa event deletion error: ' . $deleteError->getMessage());
                }
            }
        }
    }

    // Summary cards: only events owned by this organizer.

    $summaryStmt = $pdo->prepare(

        "SELECT

            COUNT(*) AS total_events,

            COALESCE(SUM(

                CASE

                    WHEN e.status = 'published'

                     AND (

                        e.event_end_date >= NOW()

                        OR (

                            e.event_end_date IS NULL

                            AND e.event_date >= NOW()

                        )

                     )

                    THEN 1

                    ELSE 0

                END

            ), 0) AS active_events,

            COALESCE(SUM((

                SELECT COALESCE(SUM(tt.quantity_sold), 0)

                FROM ticket_types tt

                WHERE tt.event_id = e.id

            )), 0) AS tickets_sold,

            COALESCE(SUM((

                SELECT COALESCE(SUM(o.total_amount), 0)

                FROM orders o

                WHERE o.event_id = e.id

                  AND o.status = 'paid'

            )), 0) AS net_revenue

         FROM events e

         WHERE e.organizer_id = :organizer_id"

    );

    $summaryStmt->execute(['organizer_id' => $organizerId]);

    $summaryResult = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    if ($summaryResult) {

        $summary = [

            'total_events' => (int) $summaryResult['total_events'],

            'active_events' => (int) $summaryResult['active_events'],

            'tickets_sold' => (int) $summaryResult['tickets_sold'],

            'net_revenue' => (float) $summaryResult['net_revenue'],

        ];

    }

    // Search is limited to the current organizer's events.

    $whereSql = ' WHERE e.organizer_id = :organizer_id';

    $queryParams = ['organizer_id' => $organizerId];

    if ($search !== '') {

        $whereSql .= ' AND (e.title LIKE :search_title

                        OR e.venue LIKE :search_venue

                        OR e.city LIKE :search_city)';

        $searchTerm = '%' . $search . '%';

        $queryParams['search_title'] = $searchTerm;

        $queryParams['search_venue'] = $searchTerm;

        $queryParams['search_city'] = $searchTerm;

    }

    // Count matching events before pagination.

    $countStmt = $pdo->prepare(

        'SELECT COUNT(*)

         FROM events e' . $whereSql

    );

    $countStmt->execute($queryParams);

    $totalFilteredEvents = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalFilteredEvents / $perPage));

    if ($page > $totalPages) {

        $page = $totalPages;

    }

    $offset = ($page - 1) * $perPage;

    // Load each event with its ticket, paid-order and check-in totals.

    $eventsSql = "

        SELECT

            e.id,

            e.title,

            e.slug,

            e.event_date,

            e.event_end_date,

            e.venue,

            e.address,

            e.city,

            e.image_url,

            e.status,

            COALESCE((

                SELECT SUM(tt.quantity_total)

                FROM ticket_types tt

                WHERE tt.event_id = e.id

            ), 0) AS ticket_capacity,

            COALESCE((

                SELECT SUM(tt.quantity_sold)

                FROM ticket_types tt

                WHERE tt.event_id = e.id

            ), 0) AS tickets_sold,

            COALESCE((

                SELECT SUM(o.total_amount)

                FROM orders o

                WHERE o.event_id = e.id

                  AND o.status = 'paid'

            ), 0) AS revenue,

            COALESCE((

                SELECT COUNT(*)

                FROM issued_tickets it

                INNER JOIN order_items oi

                    ON oi.id = it.order_item_id

                INNER JOIN orders o

                    ON o.id = oi.order_id

                WHERE o.event_id = e.id

                  AND o.status = 'paid'

                  AND (

                      it.status = 'used'

                      OR it.checked_in_at IS NOT NULL

                  )

            ), 0) AS check_ins

        FROM events e

        $whereSql

        ORDER BY

            CASE WHEN e.status = 'published' THEN 0 ELSE 1 END,

            e.event_date ASC,

            e.id DESC

        LIMIT :limit OFFSET :offset

    ";

    $eventsStmt = $pdo->prepare($eventsSql);

    foreach ($queryParams as $key => $value) {

        $eventsStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);

    }

    $eventsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);

    $eventsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $eventsStmt->execute();

    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Ticketa organizer events error: ' . $e->getMessage());

    $errors[] = 'We could not load your events. Please refresh and try again.';

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Events | TICKETA Organizer</title>

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

        a {

            color: inherit;

            text-decoration: none;

        }

        button,

        input {

            font: inherit;

        }

        .sidebar {

            position: fixed;

            inset: 0 auto 0 0;

            z-index: 1100;

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

            gap: 14px;

            min-height: 46px;

            padding: 12px 15px;

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
            margin-left: 250px;
            padding-top: 90px;
        }

        .topbar {

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

        .topbar-left {

            display: flex;

            align-items: center;

            flex: 1;

            gap: 12px;

            min-width: 0;

        }

        .search {

            display: flex;

            align-items: center;

            gap: 12px;

            width: min(345px, 100%);

            padding: 13px 15px;

            border-radius: 12px;

            background: #f4f6f6;

        }

        .search span {

            font-size: 22px;

        }

        .search input {

            width: 100%;

            min-width: 0;

            border: 0;

            outline: 0;

            background: transparent;

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

        .avatar {

            display: grid;

            width: 35px;

            height: 35px;

            flex-shrink: 0;

            place-items: center;

            border: 1px solid var(--border);

            border-radius: 50%;

            background: #e5faf4;

            color: #087f6b;

            font-weight: 800;

        }

        .organizer-profile {

            display: flex;

            align-items: center;

            gap: 10px;

            max-width: 220px;

            font-size: 14px;

            font-weight: 600;

        }

        .organizer-name {

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

        }

        .content {

            padding: 16px 32px 36px;

        }

        .page-heading-row {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 16px;

            margin-bottom: 16px;

        }

        .page-heading {

            margin: 0 0 7px;

            font-size: clamp(27px, 3vw, 32px);

            letter-spacing: -.7px;

        }

        .page-subtitle {

            margin: 0;

            color: var(--muted);

            font-size: 16px;

        }

        .create-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            flex-shrink: 0;

            padding: 12px 17px;

            border-radius: 5px;

            background: var(--green);

            color: #fff;

            font-size: 14px;

            font-weight: 650;

        }

        .create-button:hover {

            background: var(--green-dark);

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

            width: 49px;

            height: 49px;

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

        }

        .event-search {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 20px;

            padding: 13px 15px;

            border-radius: 12px;

            background: #f3f5f5;

        }

        .event-search .search-icon {

            font-size: 22px;

        }

        .event-search input {

            width: 100%;

            min-width: 0;

            border: 0;

            outline: 0;

            background: transparent;

            color: var(--ink);

            font-size: 15px;

        }

        .event-search button {

            padding: 8px 13px;

            border: 0;

            border-radius: 6px;

            background: var(--green);

            color: #fff;

            cursor: pointer;

        }

        .event-list {

            background: var(--white);

        }

        .event-row {

            display: grid;

            grid-template-columns: minmax(260px, 1.65fr) minmax(100px, .65fr)

                minmax(70px, .42fr) minmax(80px, .45fr) 54px;

            align-items: center;

            gap: 14px;

            padding: 20px 12px;

            border-bottom: 1px solid var(--border);

        }

        .event-info {

            display: flex;

            align-items: center;

            gap: 24px;

            min-width: 0;

        }

        .event-image {

            display: grid;

            width: 116px;

            height: 145px;

            flex-shrink: 0;

            place-items: center;

            overflow: hidden;

            border-radius: 5px;

            background: #e5faf4;

            color: #087f6b;

            font-size: 28px;

        }

        .event-image.image-missing {
            display: grid;
            place-items: center;
            color: #8a93a3;
            background: #f0f3f5;
            font-size: 28px;
        }

        .event-image img {

            width: 100%;

            height: 100%;

            object-fit: cover;

        }

        .event-title {

            margin: 0 0 12px;

            font-size: 16px;

            font-weight: 650;

            overflow-wrap: anywhere;

        }

        .event-venue {

            margin: 0 0 12px;

            color: #42464d;

            font-size: 13px;

            line-height: 1.5;

        }

        .event-date {

            margin: 0 0 13px;

            color: #898989;

            font-size: 12px;

            line-height: 1.5;

        }

        .progress-meta {

            display: flex;

            justify-content: space-between;

            gap: 8px;

            margin-bottom: 8px;

            color: #696969;

            font-size: 12px;

        }

        .progress-track {

            width: 100%;

            height: 10px;

            overflow: hidden;

            border-radius: 100px;

            background: #e8f7f4;

        }

        .progress-fill {

            height: 100%;

            border-radius: inherit;

            background: var(--green);

        }

        .progress-fill.average {

            background: #ffb52f;

        }

        .progress-fill.low {

            background: #f13232;

        }

        .event-metrics {

            display: flex;

            flex-direction: column;

            align-items: center;

            gap: 8px;

            text-align: center;

        }

        .status-pill {

            display: inline-block;

            padding: 10px 12px;

            border-radius: 5px;

            background: #e7f7f4;

            color: #3ebda8;

            font-size: 12px;

            text-align: center;

        }

        .status-pill.cancelled {

            background: #fff0f0;

            color: #d83030;

        }

        .status-pill.completed {

            background: #f0f1f3;

            color: #666b75;

        }

        .status-pill.draft {

            background: #fff6e8;

            color: #b57b15;

        }

        .metric-number {

            font-size: 20px;

            font-weight: 650;

            white-space: nowrap;

        }

        .metric-label {

            color: #666;

            font-size: 12px;

        }

        .sales-pill {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            width: 100%;

            padding: 9px 8px;

            border-radius: 5px;

            font-size: 12px;

            font-weight: 600;

            text-align: center;

        }

        .sales-pill.fast {

            background: var(--green);

            color: #fff;

        }

        .sales-pill.average {

            background: #fff3e3;

            color: #f1a42c;

        }

        .sales-pill.low {

            background: #f13232;

            color: #fff;

        }

        .revenue-amount {

            padding: 9px 8px;

            border-radius: 5px;

            background: #e7f7f4;

            color: #36bca5;

            font-size: 13px;

            font-weight: 650;

            text-align: center;

            overflow-wrap: anywhere;

        }

        .revenue-link {

            display: block;

            width: 100%;

            padding: 10px 8px;

            border-radius: 5px;

            background: var(--green);

            color: #fff;

            font-size: 12px;

            text-align: center;

        }

        .revenue-link:hover {

            background: var(--green-dark);

        }

        .event-menu-wrap {

            position: relative;

        }

        .event-menu-button {

            display: grid;

            width: 48px;

            height: 42px;

            place-items: center;

            border: 1px solid #999;

            border-radius: 6px;

            background: #fff;

            color: #333;

            font-size: 22px;

            cursor: pointer;

        }

        .event-menu {

            position: absolute;

            top: 48px;

            right: 0;

            z-index: 20;

            display: none;

            width: 160px;

            padding: 6px;

            border: 1px solid var(--border);

            border-radius: 8px;

            background: #fff;

            box-shadow: 0 8px 25px rgba(0, 0, 0, .1);

        }

        .event-menu.open {

            display: grid;

        }

        .event-menu a {

            padding: 10px;

            border-radius: 5px;

            font-size: 13px;

        }

        .event-menu a:hover {

            background: #edfaf7;

        }

        .event-menu .event-delete-trigger {
            width: 100%;
            padding: 10px;
            border: 0;
            border-radius: 5px;
            background: transparent;
            color: #c62828;
            font-size: 13px;
            text-align: left;
            cursor: pointer;
        }

        .event-menu .event-delete-trigger:hover {
            background: #fff0f0;
        }

        .delete-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(18, 24, 38, .58);
        }

        .delete-modal-backdrop.open { display: flex; }

        .delete-modal {
            width: min(100%, 440px);
            padding: 25px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 20px 70px rgba(0,0,0,.22);
        }

        .delete-modal h2 { margin: 0 0 10px; font-size: 22px; }
        .delete-modal p { color: #60697a; font-size: 14px; line-height: 1.6; }
        .delete-modal .delete-event-name { color: var(--ink); font-weight: 700; overflow-wrap: anywhere; }
        .delete-modal label { display: block; margin: 18px 0 7px; font-size: 13px; font-weight: 700; }
        .delete-modal input { width: 100%; padding: 12px; border: 1px solid #d8dde5; border-radius: 8px; outline: none; }
        .delete-modal input:focus { border-color: #c62828; box-shadow: 0 0 0 3px rgba(198,40,40,.1); }
        .delete-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .delete-cancel, .delete-confirm { padding: 11px 15px; border-radius: 7px; cursor: pointer; font-weight: 650; }
        .delete-cancel { border: 1px solid #dfe3e8; background: #fff; color: #333; }
        .delete-confirm { border: 0; background: #c62828; color: #fff; }
        .delete-confirm:disabled { opacity: .45; cursor: not-allowed; }

        .success-alert {
            margin-bottom: 18px; padding: 14px 16px; border: 1px solid #b8e8d7;
            border-radius: 8px; background: #effcf6; color: #087f5b; font-size: 14px;
        }

        .empty-state {

            padding: 55px 20px;

            text-align: center;

        }

        .empty-state-icon {

            display: grid;

            width: 65px;

            height: 65px;

            margin: 0 auto 18px;

            place-items: center;

            border-radius: 50%;

            background: #e5faf4;

            color: #087f6b;

            font-size: 29px;

        }

        .empty-state h2 {

            margin: 0 0 10px;

            font-size: 20px;

        }

        .empty-state p {

            max-width: 430px;

            margin: 0 auto;

            color: var(--muted);

            font-size: 14px;

            line-height: 1.7;

        }

        .pagination {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 16px;

            padding: 16px 12px;

        }

        .pagination-info {

            color: #555;

            font-size: 13px;

        }

        .pagination-controls {

            display: flex;

            align-items: center;

            gap: 7px;

        }

        .page-link {

            display: grid;

            min-width: 34px;

            height: 34px;

            padding: 0 8px;

            place-items: center;

            border: 0;

            border-radius: 5px;

            background: #b7b7b7;

            color: #fff;

            font-size: 14px;

        }

        .page-link.active,

        .page-link.arrow {

            background: var(--green);

        }

        .page-link.arrow {

            background: #f6b900;

        }

        .page-link.disabled {

            opacity: .45;

            pointer-events: none;

        }

        .alert {

            margin-bottom: 18px;

            padding: 14px 16px;

            border: 1px solid #f0cccc;

            border-radius: 8px;

            background: #fff2f2;

            color: #a52828;

            font-size: 14px;

            line-height: 1.6;

        }

        .menu-toggle {

            display: none;

            width: 40px;

            height: 40px;

            place-items: center;

            border: 1px solid var(--border);

            border-radius: 8px;

            background: #fff;

            font-size: 22px;

            cursor: pointer;

        }

        .sidebar-backdrop {

            display: none;

        }

        @media (max-width: 1350px) {

            .sidebar {

                width: 220px;

                padding-right: 16px;

                padding-left: 16px;

            }

            .main {
                margin-left: 220px;
                padding-top: 90px;
            }

            .content {

                padding-right: 20px;

                padding-left: 20px;

            }

            .topbar {

                padding-right: 20px;

                padding-left: 20px;

            }

            .stats {

                grid-template-columns: repeat(2, minmax(0, 1fr));

            }

            .event-row {

                grid-template-columns: minmax(220px, 1.5fr) minmax(85px, .6fr)

                    minmax(65px, .4fr) minmax(80px, .5fr) 45px;

                gap: 10px;

            }

            .event-info {

                gap: 14px;

            }

            .event-image {

                width: 85px;

                height: 110px;

            }

            .metric-number {

                font-size: 17px;

            }

        }

        @media (max-width: 1000px) {

            .event-row {

                grid-template-columns: minmax(0, 1fr) auto;

                gap: 16px;

            }

            .event-info {

                grid-column: 1 / -1;

            }

            .event-metrics {

                align-items: flex-start;

                text-align: left;

            }

            .event-menu-wrap {

                justify-self: end;

            }

            .status-pill,

            .sales-pill,

            .revenue-amount,

            .revenue-link {

                width: auto;

            }

        }

        @media (max-width: 800px) {

            .sidebar {

                z-index: 1200;

                width: 250px;

                transform: translateX(-100%);

                transition: transform .25s ease;

            }

            .sidebar.open {

                transform: translateX(0);

            }

            .sidebar-backdrop.visible {

                position: fixed;

                inset: 0;

                z-index: 1150;

                display: block;

                background: rgba(20, 30, 35, .4);

            }

           .main {
                margin-left: 0;
                padding-top: 75px;
            }

            .menu-toggle {

                display: grid;

                flex-shrink: 0;

            }

            .topbar {

                min-height: 75px;

                gap: 12px;

                padding: 14px 18px;

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

            .page-heading-row {

                align-items: flex-start;

                flex-direction: column;

            }

            .create-button {

                width: 100%;

            }

            .stats {

                grid-template-columns: 1fr;

            }

            .stat-card {

                min-height: 85px;

            }

            .event-row {

                padding: 18px 4px;

            }

            .event-info {

                align-items: flex-start;

                gap: 12px;

            }

            .event-image {

                width: 80px;

                height: 104px;

            }

            .event-title {

                font-size: 14px;

            }

            .event-venue,

            .event-date {

                font-size: 12px;

            }

            .event-metrics {

                gap: 7px;

            }

            .metric-number {

                font-size: 16px;

            }

            .pagination {

                align-items: flex-start;

                flex-direction: column;

            }

            .pagination-controls {

                flex-wrap: wrap;

            }

            .search {

                padding: 10px;

            }

            .topbar-right {

                gap: 4px;

            }

            .icon-button {

                width: 30px;

            }

        }

    </style>

</head>

<body>

    <?php
    include dirname(__DIR__) . '/includes/organizer-sidebar.php';
    ?>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="main">

        <?php
        $topbarSearchPlaceholder = 'Search events';
        include dirname(__DIR__) . '/includes/organizer-topbar.php';
        ?>


        <main class="content">

            <div class="page-heading-row">

                <div>

                    <h1 class="page-heading">Events</h1>

                    <p class="page-subtitle">Here are your event stats</p>

                </div>

                <a class="create-button" href="create-event.php">

                    <span aria-hidden="true">＋</span>

                    Create New Event

                </a>

            </div>

            <?php if (($_GET['deleted'] ?? '') === '1'): ?>
                <div class="success-alert" role="status">Event deleted successfully.</div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>

                <div class="alert" role="alert">

                    <?= eventsEscape($error) ?>

                </div>

            <?php endforeach; ?>

            <section class="stats" aria-label="Event statistics">

                <article class="stat-card">

                    <div class="stat-icon">▰</div>

                    <div>

                        <div class="stat-label">Total Event</div>

                        <div class="stat-value">

                            <?= number_format($summary['total_events']) ?>

                        </div>

                    </div>

                </article>

                <article class="stat-card">

                    <div class="stat-icon">₣</div>

                    <div>

                        <div class="stat-label">Active Events</div>

                        <div class="stat-value">

                            <?= number_format($summary['active_events']) ?>

                        </div>

                    </div>

                </article>

                <article class="stat-card">

                    <div class="stat-icon">▧</div>

                    <div>

                        <div class="stat-label">Total Ticket Sold</div>

                        <div class="stat-value">

                            <?= number_format($summary['tickets_sold']) ?>

                        </div>

                    </div>

                </article>

                <article class="stat-card">

                    <div class="stat-icon">₣</div>

                    <div>

                        <div class="stat-label">Net Revenue</div>

                        <div class="stat-value">

                            <?= eventsEscape(eventsMoney($summary['net_revenue'])) ?>

                        </div>

                    </div>

                </article>

            </section>

            <form class="event-search" method="get" action="events.php">

                <span class="search-icon" aria-hidden="true">⌕</span>

                <input

                    type="search"

                    id="eventSearch"

                    name="search"

                    value="<?= eventsEscape($search) ?>"

                    placeholder="Search events"

                    aria-label="Search events by title, venue, or city"

                >

                <button type="submit">Search</button>

            </form>

            <section class="event-list" aria-label="Your events">

                <?php if (!$errors && !$events): ?>

                    <div class="empty-state">

                        <div class="empty-state-icon">▣</div>

                        <?php if ($search !== ''): ?>

                            <h2>No events found</h2>

                            <p>

                                We couldn't find any of your events matching

                                "<?= eventsEscape($search) ?>". Try another search.

                            </p>

                            <a class="create-button" href="events.php"

                               style="margin-top:18px">

                                Clear search

                            </a>

                        <?php else: ?>

                            <h2>You haven't created an event yet</h2>

                            <p>

                                Your events will appear here after you create

                                them. Start by setting up your first event.

                            </p>

                            <a class="create-button" href="create-event.php"

                               style="margin-top:18px">

                                ＋ Create your first event

                            </a>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

                <?php foreach ($events as $event): ?>

                    <?php

                        $capacity = (int) $event['ticket_capacity'];

                        $sold = (int) $event['tickets_sold'];

                        $checkIns = (int) $event['check_ins'];

                        $revenue = (float) $event['revenue'];

                        $percentage = $capacity > 0

                            ? min(100, ($sold / $capacity) * 100)

                            : 0;

                        [$eventStatusLabel, $eventStatusClass] =

                            eventsStatus($event);

                        [$salesLabel, $salesClass] =

                            eventsSalesStatus($percentage);

                        $eventTitle = (string) $event['title'];

                        $eventVenue = trim(

                            (string) ($event['venue'] ?? '')

                        );

                        $eventCity = trim(

                            (string) ($event['city'] ?? '')

                        );

                        $venueText = $eventVenue !== ''

                            ? $eventVenue

                            : 'Venue to be announced';

                        if ($eventCity !== '') {

                            $venueText .= ', ' . $eventCity;

                        }

                        $imageUrl = trim(
                            (string) ($event['image_url'] ?? '')
                        );

                        // Resolve local image paths from the Ticketa project root.
                        // This also supports a project installed somewhere other than /ticketa.
                        if ($imageUrl !== '') {
                            $imageUrl = str_replace('\\', '/', $imageUrl);

                            // Leave absolute web URLs and embedded data URLs unchanged.
                            if (!preg_match('~^(?:https?://|data:)~i', $imageUrl)) {
                                $projectUrl = rtrim(
                                    dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/ticketa/organizer/events.php'))),
                                    '/'
                                );

                                if ($projectUrl === '' || $projectUrl === '.') {
                                    $projectUrl = '';
                                }

                                // Strip a leading ./ before resolving the project-relative path.
                                $imageUrl = preg_replace('~^\./+~', '', $imageUrl);

                                // Convert absolute-looking local paths to this project's URL.
                                if (str_starts_with($imageUrl, '/')) {
                                    if ($projectUrl !== '' && (
                                        $imageUrl === $projectUrl
                                        || str_starts_with($imageUrl, $projectUrl . '/')
                                    )) {
                                        // Already includes the project URL prefix.
                                    } else {
                                        $imageUrl = $projectUrl . '/' . ltrim($imageUrl, '/');
                                    }
                                } else {
                                    $imageUrl = $projectUrl . '/' . ltrim($imageUrl, '/');
                                }
                            }
                        }

                    ?>

                    <article class="event-row">

                        <div class="event-info">

                            <div class="event-image">

                                <?php if ($imageUrl !== ''): ?>

                                    <img

                                        src="<?= eventsEscape($imageUrl) ?>"

                                        alt="<?= eventsEscape($eventTitle) ?>"

                                        loading="lazy"

                                        onerror="this.remove(); this.parentElement.classList.add('image-missing'); this.parentElement.textContent='▣';"

                                    >

                                <?php else: ?>

                                    <span aria-hidden="true">▣</span>

                                <?php endif; ?>

                            </div>

                            <div style="min-width:0;flex:1">

                                <h2 class="event-title">

                                    <?= eventsEscape($eventTitle) ?>

                                </h2>

                                <p class="event-venue">

                                    <?= eventsEscape($venueText) ?>

                                </p>

                                <p class="event-date">

                                    <?= eventsEscape(eventsDate($event['event_date'])) ?>

                                </p>

                                <div class="progress-meta">

                                    <span>

                                        <?= number_format($sold) ?>

                                        / <?= number_format($capacity) ?> tickets

                                    </span>

                                    <span>

                                        <?= number_format($percentage) ?>%

                                    </span>

                                </div>

                                <div

                                    class="progress-track"

                                    role="progressbar"

                                    aria-label="Tickets sold"

                                    aria-valuemin="0"

                                    aria-valuemax="100"

                                    aria-valuenow="<?= (int) round($percentage) ?>"

                                >

                                    <div

                                        class="progress-fill <?= eventsEscape($salesClass) ?>"

                                        style="width:<?= $percentage ?>%"

                                    ></div>

                                </div>

                            </div>

                        </div>

                        <div class="event-metrics">

                            <span class="status-pill <?= eventsEscape($eventStatusClass) ?>">

                                <?= eventsEscape($eventStatusLabel) ?>

                            </span>

                            <span class="sales-pill <?= eventsEscape($salesClass) ?>">

                                <?php if ($salesClass === 'fast'): ?>

                                    ↟

                                <?php elseif ($salesClass === 'average'): ?>

                                    ↗

                                <?php else: ?>

                                    ↓

                                <?php endif; ?>

                                <?= eventsEscape($salesLabel) ?>

                            </span>

                        </div>

                        <div class="event-metrics">

                            <span class="metric-number">

                                <?= number_format($capacity) ?>

                            </span>

                            <span class="metric-label">Tickets</span>

                            <span class="revenue-amount">

                                <?= eventsEscape(eventsMoney($revenue)) ?>

                            </span>

                        </div>

                        <div class="event-metrics">

                            <span class="metric-number">

                                <?= number_format($checkIns) ?>

                            </span>

                            <span class="metric-label">Check-ins</span>

                            <a

                                class="revenue-link"

                                href="revenue.php?event_id=<?= (int) $event['id'] ?>"

                            >Revenue</a>

                        </div>

                        <div class="event-menu-wrap">

                            <button

                                type="button"

                                class="event-menu-button"

                                aria-label="More actions for <?= eventsEscape($eventTitle) ?>"

                                aria-expanded="false"

                            >···</button>

                            <div class="event-menu">

                                <a href="edit-event.php?id=<?= (int) $event['id'] ?>">

                                    Edit event

                                </a>

                                <a href="revenue.php?event_id=<?= (int) $event['id'] ?>">

                                    View ticket sales

                                </a>

                                <a href="check-ins.php?event_id=<?= (int) $event['id'] ?>">

                                    View check-ins

                                </a>

                                <button
                                    type="button"
                                    class="event-delete-trigger"
                                    data-event-id="<?= (int) $event['id'] ?>"
                                    data-event-title="<?= eventsEscape($eventTitle) ?>"
                                >Delete event</button>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </section>

            <?php if ($totalFilteredEvents > 0): ?>

                <?php

                    $startItem = $offset + 1;

                    $endItem = min(

                        $offset + count($events),

                        $totalFilteredEvents

                    );

                ?>

                <nav class="pagination" aria-label="Event pages">

                    <div class="pagination-info">

                        Showing <?= number_format($startItem) ?>

                        to <?= number_format($endItem) ?>

                        of <?= number_format($totalFilteredEvents) ?>

                    </div>

                    <div class="pagination-controls">

                        <a

                            class="page-link arrow <?= $page <= 1 ? 'disabled' : '' ?>"

                            href="<?= eventsEscape(eventsPageUrl(max(1, $page - 1), $search)) ?>"

                            aria-label="Previous page"

                        >◀</a>

                        <?php

                            $visiblePages = 4;

                            $firstPage = max(1, min(

                                $page - 1,

                                $totalPages - $visiblePages + 1

                            ));

                            $lastPage = min(

                                $totalPages,

                                $firstPage + $visiblePages - 1

                            );

                        ?>

                        <?php for ($number = $firstPage; $number <= $lastPage; $number++): ?>

                            <a

                                class="page-link <?= $number === $page ? 'active' : '' ?>"

                                href="<?= eventsEscape(eventsPageUrl($number, $search)) ?>"

                                <?= $number === $page ? 'aria-current="page"' : '' ?>

                            ><?= $number ?></a>

                        <?php endfor; ?>

                        <a

                            class="page-link arrow <?= $page >= $totalPages ? 'disabled' : '' ?>"

                            href="<?= eventsEscape(eventsPageUrl(min($totalPages, $page + 1), $search)) ?>"

                            aria-label="Next page"

                        >▶</a>

                    </div>

                </nav>

            <?php endif; ?>

        </main>

    </div>

    <div class="delete-modal-backdrop" id="deleteEventModal" aria-hidden="true">
        <section class="delete-modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
            <h2 id="deleteModalTitle">Delete event?</h2>
            <p>You are about to delete <span class="delete-event-name" id="deleteEventName">this event</span>. This cannot be undone. Events with orders cannot be deleted so ticket/order history stays safe.</p>
            <form method="post" action="events.php<?= $search !== '' ? '?search=' . rawurlencode($search) : '' ?>" id="deleteEventForm">
                <input type="hidden" name="csrf_token" value="<?= eventsEscape(authCsrfToken()) ?>">
                <input type="hidden" name="action" value="delete_event">
                <input type="hidden" name="event_id" id="deleteEventId" value="">
                <label for="deleteConfirmation">Type DELETE to confirm</label>
                <input type="text" name="delete_confirmation" id="deleteConfirmation" autocomplete="off" placeholder="DELETE" required>
                <div class="delete-modal-actions">
                    <button type="button" class="delete-cancel" id="cancelDeleteEvent">Cancel</button>
                    <button type="submit" class="delete-confirm" id="confirmDeleteEvent" disabled>Delete event</button>
                </div>
            </form>
        </section>
    </div>

    <script>

        const sidebar = document.getElementById('sidebar');

        const menuToggle = document.getElementById('menuToggle');

        const backdrop = document.getElementById('sidebarBackdrop');

        function closeSidebar() {

            sidebar.classList.remove('open');

            backdrop.classList.remove('visible');

            if (menuToggle) {

                menuToggle.setAttribute('aria-expanded', 'false');

            }

        }

        if (menuToggle) {

            menuToggle.addEventListener('click', () => {

                const isOpen = sidebar.classList.toggle('open');

                backdrop.classList.toggle('visible', isOpen);

                menuToggle.setAttribute('aria-expanded', String(isOpen));

            });

        }

        backdrop.addEventListener('click', closeSidebar);

        document.querySelectorAll('.nav-link').forEach((link) => {

            link.addEventListener('click', closeSidebar);

        });

        // Open one event action menu at a time.

        document.querySelectorAll('.event-menu-button').forEach((button) => {

            button.addEventListener('click', (event) => {

                event.stopPropagation();

                const currentMenu = button.nextElementSibling;

                const wasOpen = currentMenu.classList.contains('open');

                document.querySelectorAll('.event-menu').forEach((menu) => {

                    menu.classList.remove('open');

                });

                document.querySelectorAll('.event-menu-button').forEach((item) => {

                    item.setAttribute('aria-expanded', 'false');

                });

                if (!wasOpen) {

                    currentMenu.classList.add('open');

                    button.setAttribute('aria-expanded', 'true');

                }

            });

        });

        document.addEventListener('click', () => {

            document.querySelectorAll('.event-menu').forEach((menu) => {

                menu.classList.remove('open');

            });

            document.querySelectorAll('.event-menu-button').forEach((button) => {

                button.setAttribute('aria-expanded', 'false');

            });

        });

        // Delete-event modal: require the organizer to type DELETE.
        const deleteModal = document.getElementById('deleteEventModal');
        const deleteEventId = document.getElementById('deleteEventId');
        const deleteEventName = document.getElementById('deleteEventName');
        const deleteConfirmation = document.getElementById('deleteConfirmation');
        const confirmDeleteEvent = document.getElementById('confirmDeleteEvent');
        const cancelDeleteEvent = document.getElementById('cancelDeleteEvent');

        function closeDeleteModal() {
            deleteModal.classList.remove('open');
            deleteModal.setAttribute('aria-hidden', 'true');
            deleteConfirmation.value = '';
            confirmDeleteEvent.disabled = true;
        }

        document.querySelectorAll('.event-delete-trigger').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                document.querySelectorAll('.event-menu').forEach((menu) => menu.classList.remove('open'));
                document.querySelectorAll('.event-menu-button').forEach((item) => item.setAttribute('aria-expanded', 'false'));
                deleteEventId.value = button.dataset.eventId || '';
                deleteEventName.textContent = button.dataset.eventTitle || 'this event';
                deleteModal.classList.add('open');
                deleteModal.setAttribute('aria-hidden', 'false');
                deleteConfirmation.value = '';
                confirmDeleteEvent.disabled = true;
                window.setTimeout(() => deleteConfirmation.focus(), 50);
            });
        });

        deleteConfirmation.addEventListener('input', () => {
            confirmDeleteEvent.disabled = deleteConfirmation.value.trim().toUpperCase() !== 'DELETE';
        });
        cancelDeleteEvent.addEventListener('click', closeDeleteModal);
        deleteModal.addEventListener('click', (event) => {
            if (event.target === deleteModal) closeDeleteModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && deleteModal.classList.contains('open')) closeDeleteModal();
        });

        // Global search fills the Events search field.

        const globalSearch = document.getElementById('globalSearch');

        const eventSearch = document.getElementById('eventSearch');

        globalSearch.addEventListener('keydown', (event) => {

            if (event.key === 'Enter') {

                event.preventDefault();

                eventSearch.value = globalSearch.value;

                eventSearch.form.submit();

            }

        });

    </script>

</body>

</html>
