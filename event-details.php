<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| TICKETA EVENT DETAILS PAGE
|--------------------------------------------------------------------------
| IMPORTANT:
| Connect your existing database query below.
| It must populate:
|   $event       - associative array containing event details
|   $ticketTypes - array of ticket types for this event
|
| Expected event fields:
| id, title, description, event_date, event_time, venue, city, image,
| category, organizer_name, address
|
| Expected ticket fields:
| id, name, price, available_quantity
|
| Do not create a new database schema just for this page.
*/

// Load the event and ticket types from the existing Ticketa database.
require_once __DIR__ . '/config/database.php';

$event = null;
$ticketTypes = [];
$databaseError = false;
$eventId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($eventId && $eventId > 0) {
    try {
        $eventStmt = $pdo->prepare("
            SELECT e.*, c.name AS category_name
            FROM events e
            LEFT JOIN categories c ON c.id = e.category_id
            WHERE e.id = :id AND e.status = 'published'
            LIMIT 1
        ");
        $eventStmt->execute(['id' => $eventId]);
        $row = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $timestamp = !empty($row['event_date']) ? strtotime($row['event_date']) : false;
            $image = trim((string) ($row['image_url'] ?? ''));
            if ($image === '') {
                $image = 'assets/images/event-placeholder.jpg';
            }

            $event = [
                'id' => (int) $row['id'],
                'title' => (string) ($row['title'] ?? 'Event'),
                'description' => (string) ($row['description'] ?? ''),
                'event_date' => $timestamp ? date('F j, Y', $timestamp) : '',
                'event_time' => $timestamp ? date('g:i A', $timestamp) : '',
                'venue' => (string) ($row['venue'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'address' => (string) ($row['address'] ?? ''),
                'image' => $image,
                'category' => (string) ($row['category_name'] ?? ''),
                'organizer_name' => '',
            ];

            // Organizer display is optional; event and ticket data still load if unavailable.
            if (!empty($row['organizer_id'])) {
                try {
                    $organizerStmt = $pdo->prepare(
                        'SELECT name FROM organizers WHERE id = :id LIMIT 1'
                    );
                    $organizerStmt->execute(['id' => (int) $row['organizer_id']]);
                    $organizerName = $organizerStmt->fetchColumn();
                    if ($organizerName !== false) {
                        $event['organizer_name'] = (string) $organizerName;
                    }
                } catch (PDOException $organizerError) {
                    error_log('Ticketa organizer lookup error: ' . $organizerError->getMessage());
                }
            }

            $ticketStmt = $pdo->prepare("
                SELECT
                    tt.id,
                    tt.name,
                    tt.price,
                    GREATEST(
                        tt.quantity_total
                        - tt.quantity_sold
                        - COALESCE(reserved.reserved_quantity, 0),
                        0
                    ) AS available_quantity
                FROM ticket_types tt
                LEFT JOIN (
                    SELECT ticket_type_id, SUM(quantity) AS reserved_quantity
                    FROM ticket_inventory_reservations
                    WHERE status = 'pending'
                      AND expires_at > NOW()
                    GROUP BY ticket_type_id
                ) reserved ON reserved.ticket_type_id = tt.id
                WHERE tt.event_id = :event_id
                  AND tt.is_active = 1
                ORDER BY tt.price ASC, tt.id ASC
            ");
            $ticketStmt->execute(['event_id' => $eventId]);
            $ticketTypes = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Ticketa event details database error: ' . $e->getMessage());
        $databaseError = true;
        http_response_code(500);
    }
}

function eventEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$eventIsAvailable = is_array($event) && !empty($event['id']);
$allTicketTypesSoldOut = !empty($ticketTypes);

if ($allTicketTypesSoldOut) {
    foreach ($ticketTypes as $ticketType) {
        if ((int) ($ticketType['available_quantity'] ?? 0) > 0) {
            $allTicketTypesSoldOut = false;
            break;
        }
    }
}

$pageTitle = $eventIsAvailable
    ? (string) ($event['title'] ?? 'Event') . ' | TICKETA'
    : 'Event Details | TICKETA';

$pageStyles = ['assets/css/event-details.css'];

if ($eventIsAvailable) {
    $eventId = (int) $event['id'];

    if (!isset($_SESSION['event_checkout_catalog'])) {
        $_SESSION['event_checkout_catalog'] = [];
    }

    $_SESSION['event_checkout_catalog'][(string) $eventId] = [
        'event' => [
            'id' => $eventId,
            'title' => (string) ($event['title'] ?? ''),
            'event_date' => (string) ($event['event_date'] ?? ''),
            'event_time' => (string) ($event['event_time'] ?? ''),
            'venue' => (string) ($event['venue'] ?? ''),
            'city' => (string) ($event['city'] ?? ''),
            'image' => (string) ($event['image'] ?? ''),
            'description' => (string) ($event['description'] ?? ''),
            'category' => (string) ($event['category'] ?? ''),
            'organizer_name' => (string) ($event['organizer_name'] ?? ''),
            'address' => (string) ($event['address'] ?? ''),
        ],
        'tickets' => [],
    ];

    foreach ($ticketTypes as $ticket) {
        if (!isset($ticket['id'])) {
            continue;
        }

        $ticketId = (string) $ticket['id'];

        $_SESSION['event_checkout_catalog'][(string) $eventId]['tickets'][$ticketId] = [
            'id' => (int) $ticket['id'],
            'name' => (string) ($ticket['name'] ?? 'Ticket'),
            'unit_price' => max(0, (int) ($ticket['price'] ?? 0)),
            'available_quantity' => max(
                0,
                (int) ($ticket['available_quantity'] ?? 0)
            ),
        ];
    }

    if (empty($_SESSION['event_checkout_csrf'])) {
        $_SESSION['event_checkout_csrf'] = bin2hex(random_bytes(32));
    }
}

require __DIR__ . '/includes/header.php';
?>

<style>
    /* Sold-out presentation only; event queries and checkout logic remain unchanged. */
    .event-details-page .tickets-unavailable.event-sold-out {
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 22px 18px;
        background: #fff5f5;
        text-align: center;
    }

    .event-details-page .tickets-unavailable.event-sold-out::before {
        content: 'SOLD OUT';
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
        padding: 6px 12px;
        border-radius: 999px;
        background: #dc2626;
        color: #fff;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
    }

    .event-details-page .tickets-unavailable.event-sold-out h3 {
        margin: 0 0 8px;
        color: #991b1b;
        font-size: 22px;
        font-weight: 750;
    }

    .event-details-page .tickets-unavailable.event-sold-out p {
        margin: 0;
        color: #7f1d1d;
        line-height: 1.6;
    }

    .event-details-page .ticket-sold-out {
        display: inline-flex;
        align-items: center;
        padding: 5px 9px;
        border-radius: 999px;
        background: #fee2e2;
        color: #b91c1c;
        font-size: 12px;
        font-weight: 700;
    }
</style>

<main class="event-details-page">

    <?php if (!$eventIsAvailable): ?>

        <section class="event-not-found">
            <h1><?= !empty($databaseError) ? 'Unable to load event' : 'Event unavailable' ?></h1>
            <p>
                <?= !empty($databaseError) ? 'We could not load event information right now. Please try again.' : "We couldn't find this published event. Please return to the events page and try again." ?>
            </p>
            <a class="event-primary-button" href="events.php">
                Browse Events
            </a>
        </section>

    <?php else: ?>

        <nav class="event-breadcrumb" aria-label="Breadcrumb">
            <a href="index.php">Home</a>
            <span aria-hidden="true">›</span>
            <a href="events.php">Browse Events</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">
                <?= eventEscape($event['title'] ?? 'Event') ?>
            </span>
        </nav>

        <section class="event-hero">
            <div class="event-hero-image">
                <img
                    src="<?= eventEscape($event['image'] ?? 'assets/images/event-placeholder.jpg') ?>"
                    alt="<?= eventEscape($event['title'] ?? 'Event') ?>"
                    onerror="this.onerror=null;this.src='assets/images/event-placeholder.jpg';"
                >
            </div>

            <div class="event-hero-overlay"></div>

            <div class="event-hero-content">
                <?php if (!empty($event['category'])): ?>
                    <span class="event-category">
                        <?= eventEscape($event['category']) ?>
                    </span>
                <?php endif; ?>

                <h1><?= eventEscape($event['title'] ?? 'Event') ?></h1>

                <?php if (!empty($event['organizer_name'])): ?>
                    <p class="event-organizer">
                        Organized by
                        <?= eventEscape($event['organizer_name']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <div class="event-details-layout">

            <div class="event-main-column">

                <section class="event-info-card">
                    <h2>Event Information</h2>

                    <div class="event-info-grid">

                        <?php if (!empty($event['event_date'])): ?>
                            <div class="event-info-item">
                                <span class="event-info-icon" aria-hidden="true">▦</span>
                                <div>
                                    <strong>Date</strong>
                                    <p><?= eventEscape($event['event_date']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($event['event_time'])): ?>
                            <div class="event-info-item">
                                <span class="event-info-icon" aria-hidden="true">◷</span>
                                <div>
                                    <strong>Time</strong>
                                    <p><?= eventEscape($event['event_time']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($event['venue'])): ?>
                            <div class="event-info-item">
                                <span class="event-info-icon" aria-hidden="true">⌂</span>
                                <div>
                                    <strong>Venue</strong>
                                    <p><?= eventEscape($event['venue']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($event['city'])): ?>
                            <div class="event-info-item">
                                <span class="event-info-icon" aria-hidden="true">●</span>
                                <div>
                                    <strong>Location</strong>
                                    <p><?= eventEscape($event['city']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>

                    <?php if (!empty($event['address'])): ?>
                        <p class="event-full-address">
                            <?= eventEscape($event['address']) ?>
                        </p>
                    <?php endif; ?>
                </section>

                <section class="event-description-card">
                    <h2>About This Event</h2>

                    <?php if (!empty($event['description'])): ?>
                        <div class="event-description">
                            <?= nl2br(eventEscape($event['description'])) ?>
                        </div>
                    <?php else: ?>
                        <p>More information will be provided by the organizer.</p>
                    <?php endif; ?>
                </section>

            </div>

            <aside class="event-ticket-sidebar" id="ticket-selection">
                <section class="event-ticket-card">

                    <div class="ticket-card-heading">
                        <div>
                            <h2>Select Your Tickets</h2>
                            <p>Choose your ticket type and quantity.</p>
                        </div>
                        <span class="ticket-heading-icon" aria-hidden="true">♧</span>
                    </div>

                    <?php if (empty($ticketTypes)): ?>

                        <div class="tickets-unavailable">
                            <h3>No tickets available</h3>
                            <p>
                                Ticket information is not available for this event.
                                Please try again later.
                            </p>
                        </div>

                    <?php elseif ($allTicketTypesSoldOut): ?>

                        <div class="tickets-unavailable event-sold-out">
                            <h3>Sold Out</h3>
                            <p>
                                All tickets for this event have been sold or are
                                currently reserved. Please check back later.
                            </p>
                        </div>

                    <?php else: ?>

                        <form
                            id="ticketSelectionForm"
                            action="start-checkout.php"
                            method="post"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= eventEscape($_SESSION['event_checkout_csrf']) ?>"
                            >

                            <input
                                type="hidden"
                                name="event_id"
                                value="<?= (int) $event['id'] ?>"
                            >

                            <div class="ticket-options">

                                <?php foreach ($ticketTypes as $ticket): ?>
                                    <?php
                                    $ticketId = (int) ($ticket['id'] ?? 0);
                                    $ticketName = (string) ($ticket['name'] ?? 'Ticket');
                                    $ticketPrice = max(0, (int) ($ticket['price'] ?? 0));
                                    $available = max(
                                        0,
                                        (int) ($ticket['available_quantity'] ?? 0)
                                    );
                                    $maxQuantity = min(10, $available);
                                    ?>

                                    <article class="ticket-option">

                                        <div class="ticket-option-top">
                                            <div>
                                                <h3><?= eventEscape($ticketName) ?></h3>
                                                <p class="ticket-price">
                                                    <?= number_format($ticketPrice, 0, ',', ' ') ?>
                                                    XOF
                                                </p>
                                            </div>

                                            <?php if ($available > 0): ?>
                                                <span class="ticket-availability">
                                                    Available
                                                </span>
                                            <?php else: ?>
                                                <span class="ticket-sold-out">
                                                    Sold out
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="ticket-option-bottom">
                                            <span class="ticket-quantity-label">
                                                Quantity
                                            </span>

                                            <div class="ticket-quantity">
                                                <button
                                                    type="button"
                                                    class="quantity-btn"
                                                    data-action="decrease"
                                                    aria-label="Decrease <?= eventEscape($ticketName) ?>"
                                                    <?= $maxQuantity === 0 ? 'disabled' : '' ?>
                                                >−</button>

                                                <input
                                                    type="number"
                                                    name="tickets[<?= $ticketId ?>]"
                                                    value="0"
                                                    min="0"
                                                    max="<?= $maxQuantity ?>"
                                                    step="1"
                                                    inputmode="numeric"
                                                    data-ticket-quantity
                                                    data-price="<?= $ticketPrice ?>"
                                                    aria-label="<?= eventEscape($ticketName) ?> quantity"
                                                    <?= $maxQuantity === 0 ? 'disabled' : '' ?>
                                                >

                                                <button
                                                    type="button"
                                                    class="quantity-btn"
                                                    data-action="increase"
                                                    aria-label="Increase <?= eventEscape($ticketName) ?>"
                                                    <?= $maxQuantity === 0 ? 'disabled' : '' ?>
                                                >+</button>
                                            </div>
                                        </div>

                                    </article>
                                <?php endforeach; ?>

                            </div>

                            <div class="ticket-order-total">
                                <span>Estimated total</span>
                                <strong id="ticketSelectionTotal">0 XOF</strong>
                            </div>

                            <p
                                class="ticket-selection-message"
                                id="ticketSelectionMessage"
                                role="status"
                                aria-live="polite"
                            ></p>

                            <button
                                class="event-primary-button checkout-ticket-button"
                                type="submit"
                                id="continueToCheckout"
                                disabled
                            >
                                Continue to Checkout
                                <span aria-hidden="true">→</span>
                            </button>

                            <p class="ticket-checkout-note">
                                Enter your name, email and phone on the next page.
                            </p>

                        </form>

                    <?php endif; ?>

                </section>
            </aside>

        </div>

    <?php endif; ?>

</main>


<script>
(function () {
    const form = document.getElementById('ticketSelectionForm');
    if (!form) return;

    const inputs = Array.from(form.querySelectorAll('[data-ticket-quantity]'));
    const total = document.getElementById('ticketSelectionTotal');
    const submit = document.getElementById('continueToCheckout');
    const message = document.getElementById('ticketSelectionMessage');
    const money = value => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(value) + ' XOF';

    function update() {
        let amount = 0;
        let count = 0;
        inputs.forEach(input => {
            const max = Math.max(0, parseInt(input.max || '0', 10) || 0);
            let qty = parseInt(input.value || '0', 10);
            if (!Number.isFinite(qty)) qty = 0;
            qty = Math.max(0, Math.min(max, qty));
            input.value = String(qty);
            amount += qty * Math.max(0, parseInt(input.dataset.price || '0', 10) || 0);
            count += qty;
        });
        if (total) total.textContent = money(amount);
        if (submit) submit.disabled = count === 0;
        if (message) message.textContent = count ? count + (count === 1 ? ' ticket selected.' : ' tickets selected.') : '';
    }

    form.querySelectorAll('.quantity-btn').forEach(button => {
        button.addEventListener('click', () => {
            const wrap = button.closest('.ticket-quantity');
            const input = wrap ? wrap.querySelector('[data-ticket-quantity]') : null;
            if (!input || input.disabled) return;
            const current = parseInt(input.value || '0', 10) || 0;
            const max = parseInt(input.max || '0', 10) || 0;
            input.value = String(button.dataset.action === 'increase' ? Math.min(max, current + 1) : Math.max(0, current - 1));
            update();
        });
    });
    inputs.forEach(input => input.addEventListener('input', update));
    form.addEventListener('submit', event => {
        if (!inputs.some(input => (parseInt(input.value || '0', 10) || 0) > 0)) {
            event.preventDefault();
            if (message) message.textContent = 'Please select at least one ticket.';
        }
    });
    update();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
