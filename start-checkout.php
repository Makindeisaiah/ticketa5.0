<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

function startCheckoutRedirect(string $message, string $url = 'events.php'): never
{
    $_SESSION['checkout_start_error'] = $message;
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['event_checkout_csrf'] ?? '');

if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    startCheckoutRedirect('Your session expired. Please reopen the event and select your tickets again.');
}

$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
$submittedTickets = $_POST['tickets'] ?? null;

if (!$eventId || $eventId < 1 || !is_array($submittedTickets)) {
    startCheckoutRedirect('Your ticket selection is invalid. Please select your tickets again.');
}

try {
    $eventStmt = $pdo->prepare("
        SELECT e.id, e.title, e.description, e.event_date, e.venue, e.address,
               e.city, e.image_url, e.organizer_id, c.name AS category_name
        FROM events e
        LEFT JOIN categories c ON c.id = e.category_id
        WHERE e.id = :event_id AND e.status = 'published'
        LIMIT 1
    ");
    $eventStmt->execute(['event_id' => $eventId]);
    $eventRow = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$eventRow) {
        startCheckoutRedirect('This event is no longer available. Please choose another event.');
    }

    $tickets = [];
    $subtotal = 0;

    $ticketStmt = $pdo->prepare("
        SELECT id, event_id, name, price, quantity_total, quantity_sold
        FROM ticket_types
        WHERE id = :ticket_id AND event_id = :event_id AND is_active = 1
        LIMIT 1
    ");

    foreach ($submittedTickets as $ticketIdInput => $quantityInput) {
        $ticketId = filter_var($ticketIdInput, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT);

        if ($ticketId === false || $ticketId < 1 || $quantity === false || $quantity < 0) {
            startCheckoutRedirect('A ticket quantity is invalid. Please try again.', 'event-details.php?id=' . (int) $eventId);
        }

        if ($quantity === 0) {
            continue;
        }

        if ($quantity > 10) {
            startCheckoutRedirect('You can select a maximum of 10 tickets of each type.', 'event-details.php?id=' . (int) $eventId);
        }

        $ticketStmt->execute(['ticket_id' => $ticketId, 'event_id' => $eventId]);
        $ticketRow = $ticketStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticketRow) {
            startCheckoutRedirect('A selected ticket is no longer available. Please select again.', 'event-details.php?id=' . (int) $eventId);
        }

        $available = max(0, (int) $ticketRow['quantity_total'] - (int) $ticketRow['quantity_sold']);
        if ($quantity > $available) {
            startCheckoutRedirect('Only ' . $available . ' ticket(s) remain for ' . (string) $ticketRow['name'] . '.', 'event-details.php?id=' . (int) $eventId);
        }

        $unitPrice = max(0, (int) $ticketRow['price']);
        $lineTotal = $unitPrice * $quantity;
        $subtotal += $lineTotal;

        $tickets[] = [
            'id' => (int) $ticketRow['id'],
            'name' => (string) $ticketRow['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    if (!$tickets) {
        startCheckoutRedirect('Please select at least one ticket before continuing.', 'event-details.php?id=' . (int) $eventId);
    }

    $timestamp = !empty($eventRow['event_date']) ? strtotime((string) $eventRow['event_date']) : false;
    $eventDate = $timestamp ? date('F j, Y', $timestamp) : '';
    $eventTime = $timestamp ? date('g:i A', $timestamp) : '';

    $organizerName = '';
    if (!empty($eventRow['organizer_id'])) {
        try {
            $organizerStmt = $pdo->prepare("SELECT name FROM organizers WHERE id = :organizer_id LIMIT 1");
            $organizerStmt->execute(['organizer_id' => (int) $eventRow['organizer_id']]);
            $organizer = $organizerStmt->fetchColumn();
            if ($organizer !== false) {
                $organizerName = (string) $organizer;
            }
        } catch (PDOException $organizerError) {
            error_log('Ticketa organizer lookup error: ' . $organizerError->getMessage());
        }
    }

    // Matches the session structure read by checkout.php.
    $_SESSION['checkout'] = [
        'event_id' => (int) $eventRow['id'],
        'event_title' => (string) $eventRow['title'],
        'event_date' => $eventDate,
        'event_time' => $eventTime,
        'venue' => (string) ($eventRow['venue'] ?? ''),
        'city' => (string) ($eventRow['city'] ?? ''),
        'image' => (string) (($eventRow['image_url'] ?? '') ?: 'assets/images/event-placeholder.jpg'),
        'description' => (string) ($eventRow['description'] ?? ''),
        'category' => (string) ($eventRow['category_name'] ?? ''),
        'organizer_name' => $organizerName,
        'tickets' => $tickets,
        'subtotal' => $subtotal,
        'service_fee' => 0,
        'total' => $subtotal,
    ];

    if (!isset($_SESSION['checkout_buyer']) || !is_array($_SESSION['checkout_buyer'])) {
        $_SESSION['checkout_buyer'] = [];
    }

    header('Location: checkout.php');
    exit;
} catch (PDOException $e) {
    error_log('Ticketa start checkout database error: ' . $e->getMessage());
    http_response_code(500);
    $pageTitle = 'Checkout Error | TICKETA';
    $pageStyles = ['assets/css/checkout.css'];
    require __DIR__ . '/includes/header.php';
    ?>
    <main class="checkout-page">
        <div class="checkout-container">
            <section class="checkout-error" role="alert">
                <h1>Unable to start checkout</h1>
                <p>We couldn't verify your tickets right now. Please return to the event and try again.</p>
                <a class="checkout-primary-link" href="events.php">Browse events</a>
            </section>
        </div>
    </main>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}
