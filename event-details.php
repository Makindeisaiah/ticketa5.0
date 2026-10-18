
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

// TODO: Insert your EXISTING event and ticket database queries here.
// Do not leave these variables undefined.
$event = $event ?? null;
$ticketTypes = $ticketTypes ?? [];

function eventEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$eventIsAvailable = is_array($event) && !empty($event['id']);

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

<main class="event-details-page">

    <?php if (!$eventIsAvailable): ?>

        <section class="event-not-found">
            <h1>Event unavailable</h1>
            <p>
                We couldn't load this event. Please return to the events page
                and try again.
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

<?php require __DIR__ . '/includes/footer.php'; ?>
