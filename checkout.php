
<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * SESSION CONTRACT
 * Connect your existing ticket-selection code to these values:
 *
 * $_SESSION['checkout'] = [
 *   'event_id' => 123,
 *   'event_title' => 'Example Event',
 *   'event_date' => '2026-11-20',
 *   'event_time' => '20:00',
 *   'venue' => 'Palais de la Culture',
 *   'city' => 'Abidjan',
 *   'image' => 'assets/images/event-poster.jpg',
 *   'tickets' => [
 *      ['name' => 'Regular', 'quantity' => 2, 'unit_price' => 10000],
 *      ['name' => 'VIP', 'quantity' => 1, 'unit_price' => 25000],
 *   ],
 *   'service_fee' => 500
 * ];
 *
 * Prices are XOF. These are example values, not real event data.
 */

$checkout = $_SESSION['checkout'] ?? null;

if (!is_array($checkout) || empty($checkout['tickets'])) {
    http_response_code(400);
    $checkoutError = 'Your ticket selection is missing. Please return to the event and select your tickets.';
} else {
    $checkoutError = '';
}

function e(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$eventTitle = $checkout['event_title'] ?? 'Your selected event';
$eventDate  = $checkout['event_date'] ?? '';
$eventTime  = $checkout['event_time'] ?? '';
$venue      = $checkout['venue'] ?? '';
$city       = $checkout['city'] ?? '';
$image      = $checkout['image'] ?? 'assets/images/event-placeholder.jpg';
$serviceFee = max(0, (int) ($checkout['service_fee'] ?? 0));

$subtotal = 0;
$tickets = [];

foreach (($checkout['tickets'] ?? []) as $ticket) {
    $quantity = max(0, (int) ($ticket['quantity'] ?? 0));
    $price = max(0, (int) ($ticket['unit_price'] ?? 0));

    if ($quantity < 1) {
        continue;
    }

    $lineTotal = $quantity * $price;
    $subtotal += $lineTotal;

    $tickets[] = [
        'name' => (string) ($ticket['name'] ?? 'Ticket'),
        'quantity' => $quantity,
        'unit_price' => $price,
        'line_total' => $lineTotal
    ];
}

$total = $subtotal + $serviceFee;

$buyer = $_SESSION['checkout_buyer'] ?? [];
$csrfToken = $_SESSION['checkout_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['checkout_csrf'] = $csrfToken;

$pageTitle = 'Checkout | TICKETA';
$pageStyles = ['assets/css/checkout.css'];

require __DIR__ . '/includes/header.php';
?>

<main class="checkout-page">
    <div class="checkout-container">
        <nav class="checkout-breadcrumb" aria-label="Breadcrumb">
            <a href="events.php">Browse Events</a>
            <span aria-hidden="true">›</span>
            <a href="event-details.php">
                <?= e($eventTitle) ?>
            </a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Checkout</span>
        </nav>

        <?php if ($checkoutError): ?>
            <section class="checkout-error" role="alert">
                <h1>Ticket selection not found</h1>
                <p><?= e($checkoutError) ?></p>
                <a class="checkout-primary-link" href="events.php">
                    Browse events
                </a>
            </section>
        <?php else: ?>

        <form
            class="checkout-form"
            id="checkoutForm"
            action="api/initialize-payment.php"
            method="post"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <div class="checkout-layout">
                <!-- LEFT: ORDER SUMMARY -->
                <section class="checkout-card order-card">
                    <h1 class="checkout-card-title">Order Summary</h1>

                    <div class="event-summary">
                        <img
                            class="event-poster"
                            src="<?= e($image) ?>"
                            alt="<?= e($eventTitle) ?> event poster"
                            onerror="this.onerror=null;this.src='assets/images/event-placeholder.jpg';"
                        >

                        <div class="event-summary-details">
                            <h2><?= e($eventTitle) ?></h2>

                            <?php if ($eventDate): ?>
                            <p>
                                <span class="summary-icon" aria-hidden="true">▦</span>
                                <?= e($eventDate) ?>
                            </p>
                            <?php endif; ?>

                            <?php if ($eventTime): ?>
                            <p>
                                <span class="summary-icon" aria-hidden="true">◷</span>
                                <?= e($eventTime) ?>
                            </p>
                            <?php endif; ?>

                            <?php if ($venue): ?>
                            <p>
                                <span class="summary-icon" aria-hidden="true">⌂</span>
                                <?= e($venue) ?>
                            </p>
                            <?php endif; ?>

                            <?php if ($city): ?>
                            <p>
                                <span class="summary-icon" aria-hidden="true">●</span>
                                <?= e($city) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ticket-summary">
                        <h2>Tickets</h2>

                        <?php foreach ($tickets as $ticket): ?>
                        <div class="price-row">
                            <span>
                                <?= e($ticket['quantity']) ?>
                                <?= e($ticket['name']) ?>
                                <small>× <?= e($ticket['quantity']) ?></small>
                            </span>
                            <span>
                                <?= number_format($ticket['line_total'], 0, ',', ' ') ?>
                                XOF
                            </span>
                        </div>
                        <?php endforeach; ?>

                        <div class="price-row subtotal-row">
                            <span>Subtotal</span>
                            <span><?= number_format($subtotal, 0, ',', ' ') ?> XOF</span>
                        </div>

                        <div class="price-row">
                            <span>Service fee</span>
                            <span><?= number_format($serviceFee, 0, ',', ' ') ?> XOF</span>
                        </div>

                        <div class="price-row total-row">
                            <strong>Total</strong>
                            <strong><?= number_format($total, 0, ',', ' ') ?> XOF</strong>
                        </div>
                    </div>
                </section>

                <!-- RIGHT: BUYER INFORMATION -->
                <div class="checkout-right-column">
                    <section class="checkout-card buyer-card">
                        <h2 class="checkout-card-title">Buyer Information</h2>

                        <div class="form-field">
                            <label for="fullName">Full Name</label>
                            <input
                                id="fullName"
                                name="full_name"
                                type="text"
                                autocomplete="name"
                                maxlength="120"
                                value="<?= e($buyer['full_name'] ?? '') ?>"
                                placeholder="Enter your full name"
                                required
                            >
                        </div>

                        <div class="form-field">
                            <label for="email">Email Address</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autocomplete="email"
                                maxlength="190"
                                value="<?= e($buyer['email'] ?? '') ?>"
                                placeholder="you@example.com"
                                required
                            >
                            <small>Your order and ticket confirmation will be sent here.</small>
                        </div>

                        <div class="form-field">
                            <label for="phone">Phone Number</label>
                            <div class="phone-input-group">
                                <select
                                    name="phone_country"
                                    id="phoneCountry"
                                    aria-label="Phone country code"
                                    required
                                >
                                    <option value="+225" selected>🇨🇮 +225</option>
                                    <option value="+234">🇳🇬 +234</option>
                                    <option value="+221">🇸🇳 +221</option>
                                    <option value="+223">🇲🇱 +223</option>
                                    <option value="+226">🇧🇫 +226</option>
                                    <option value="+228">🇹🇬 +228</option>
                                    <option value="+229">🇧🇯 +229</option>
                                    <option value="+233">🇬🇭 +233</option>
                                </select>

                                <input
                                    id="phone"
                                    name="phone"
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="tel-national"
                                    maxlength="20"
                                    value="<?= e($buyer['phone'] ?? '') ?>"
                                    placeholder="07 00 00 00 00"
                                    required
                                >
                            </div>
                        </div>
                    </section>

                    <!-- NO CARD FIELDS OR PAYMENT-METHOD SELECTORS HERE -->
                    <section class="checkout-card payment-redirect-card">
                        <div class="payment-intro-icon" aria-hidden="true">
                            <span>✓</span>
                        </div>

                        <h2>Secure payment</h2>
                        <p>
                            Continue to Paystack to choose an available payment
                            method and complete your purchase securely.
                        </p>

                        <ul class="payment-benefits">
                            <li>Mobile money options where supported</li>
                            <li>Credit and debit cards where available</li>
                            <li>Your payment details are handled by Paystack</li>
                        </ul>

                        <button
                            class="checkout-pay-button"
                            type="submit"
                            id="payButton"
                            <?= $total <= 0 ? 'disabled' : '' ?>
                        >
                            <span id="payButtonText">
                                Continue to Payment ·
                                <?= number_format($total, 0, ',', ' ') ?> XOF
                            </span>
                            <span class="button-spinner" aria-hidden="true"></span>
                        </button>

                        <p class="secure-payment-note">
                            <span aria-hidden="true">🔒</span>
                            Secure payment processing
                        </p>

                        <p class="checkout-form-message" id="checkoutMessage" role="status"></p>
                    </section>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
