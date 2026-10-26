<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/qr-helper.php';

// Require a signed-in customer.
$user = $_SESSION['user'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$userEmail = trim((string) ($user['email'] ?? ''));

if ($userId < 1 || $userEmail === '') {
    $_SESSION['auth_return_to'] = 'my-tickets.php';
    header('Location: login.php');
    exit;
}

function mtEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function mtDate(mixed $value): string
{
    if (empty($value)) {
        return 'Date to be announced';
    }

    try {
        return (new DateTimeImmutable((string) $value))
            ->format('D, d M Y · H:i');
    } catch (Throwable) {
        return (string) $value;
    }
}

function mtMoney(mixed $amount, mixed $currency = 'XOF'): string
{
    return number_format((float) $amount, 0, ',', ' ')
        . ' '
        . (string) $currency;
}

$tickets = [];
$pageError = '';

try {
    /*
     * Only display tickets for paid orders belonging to this user.
     * Email matching also supports purchases made before sign-in.
     */
    $stmt = $pdo->prepare(
        "SELECT
            it.id AS issued_ticket_id,
            it.ticket_code,
            it.qr_token,
            it.attendee_name,
            it.status AS ticket_status,
            it.issued_at,
            it.checked_in_at,

            oi.id AS order_item_id,
            oi.ticket_name,
            oi.quantity,
            oi.unit_price,
            oi.line_total,

            o.id AS order_id,
            o.order_number,
            o.currency,
            o.customer_name,
            o.customer_email,

            e.id AS event_id,
            e.title AS event_title,
            e.event_date,
            e.venue,
            e.city,
            e.image_url

         FROM issued_tickets it

         INNER JOIN order_items oi
            ON oi.id = it.order_item_id

         INNER JOIN orders o
            ON o.id = oi.order_id

         INNER JOIN events e
            ON e.id = o.event_id

         WHERE o.status = 'paid'
           AND (
                o.user_id = :user_id
                OR LOWER(o.customer_email) = LOWER(:email)
           )

         ORDER BY e.event_date ASC, it.issued_at DESC, it.id DESC"
    );

    $stmt->execute([
        'user_id' => $userId,
        'email' => $userEmail,
    ]);

    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Ticketa my-tickets page error: ' . $e->getMessage());
    $pageError = 'We could not load your tickets right now. Please try again later.';
}

$totalTickets = count($tickets);
$pageTitle = 'My Tickets | TICKETA';
$pageStyles = [];

require __DIR__ . '/includes/header.php';
?>

<style>
.my-tickets-page {
    min-height: 70vh;
    padding: 48px 20px 80px;
    background: var(--background, #f8fafc);
    color: var(--text, #111827);
}

.my-tickets-container {
    max-width: 1100px;
    margin: 0 auto;
}

.my-tickets-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    margin-bottom: 30px;
}

.my-tickets-heading h1 {
    margin: 0 0 8px;
    font-size: clamp(28px, 4vw, 38px);
    letter-spacing: -1px;
}

.my-tickets-heading p {
    margin: 0;
    color: #64748b;
    line-height: 1.6;
}

.ticket-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 15px;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    font-weight: 700;
    white-space: nowrap;
}

.ticket-count span {
    color: #6d28d9;
}

.my-tickets-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
}

.my-ticket-card {
    overflow: hidden;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
}

.my-ticket-event {
    display: flex;
    gap: 16px;
    padding: 20px;
    align-items: center;
}

.my-ticket-poster {
    width: 100px;
    height: 110px;
    flex: 0 0 100px;
    border-radius: 12px;
    object-fit: cover;
    background: #f1f5f9;
}

.my-ticket-event-details {
    min-width: 0;
}

.my-ticket-event-details h2 {
    margin: 0 0 10px;
    font-size: 19px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.my-ticket-meta {
    display: grid;
    gap: 7px;
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
}

.my-ticket-divider {
    position: relative;
    height: 1px;
    border-top: 1px dashed #cbd5e1;
    margin: 0 18px;
}

.my-ticket-divider::before,
.my-ticket-divider::after {
    content: "";
    position: absolute;
    top: -9px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--background, #f8fafc);
    border: 1px solid #e5e7eb;
}

.my-ticket-divider::before {
    left: -28px;
}

.my-ticket-divider::after {
    right: -28px;
}

.my-ticket-code-section {
    padding: 20px;
}

.my-ticket-type-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 18px;
}

.my-ticket-type-row small,
.my-ticket-code-label {
    display: block;
    margin-bottom: 5px;
    color: #64748b;
    font-size: 12px;
}

.my-ticket-type-row strong {
    font-size: 16px;
}

.my-ticket-status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    background: #dcfce7;
    color: #166534;
    font-size: 12px;
    font-weight: 700;
    text-transform: capitalize;
}

.my-ticket-status.used {
    background: #e2e8f0;
    color: #334155;
}

.my-ticket-status.cancelled,
.my-ticket-status.invalid {
    background: #fee2e2;
    color: #991b1b;
}

.my-ticket-code {
    display: block;
    padding: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-family: monospace;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
    user-select: all;
}

/* QR code */
.my-ticket-qr {
    margin-top: 22px;
    padding: 20px 12px;
    text-align: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}

.my-ticket-qr img {
    display: block;
    width: 220px;
    max-width: 100%;
    height: auto;
    margin: 12px auto;
    image-rendering: pixelated;
}

.my-ticket-qr p {
    margin: 6px 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
}

.my-ticket-qr strong {
    color: #111827;
}

.my-ticket-bottom {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 14px 20px;
    border-top: 1px solid #f1f5f9;
    color: #64748b;
    font-size: 12px;
}

.my-tickets-empty,
.my-tickets-error {
    padding: 52px 24px;
    text-align: center;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
}

.my-tickets-empty-icon {
    display: inline-flex;
    width: 64px;
    height: 64px;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
    background: #f3e8ff;
    border-radius: 50%;
    color: #6d28d9;
    font-size: 30px;
}

.my-tickets-empty h2,
.my-tickets-error h2 {
    margin: 0 0 10px;
}

.my-tickets-empty p,
.my-tickets-error p {
    max-width: 500px;
    margin: 0 auto 22px;
    color: #64748b;
    line-height: 1.7;
}

.my-tickets-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 18px;
    border-radius: 10px;
    background: #6d28d9;
    color: #fff;
    text-decoration: none;
    font-weight: 700;
}

.my-tickets-button:hover {
    background: #5b21b6;
}

@media (max-width: 760px) {
    .my-tickets-page {
        padding: 32px 14px 56px;
    }

    .my-tickets-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 420px) {
    .my-ticket-event {
        align-items: flex-start;
        gap: 12px;
        padding: 15px;
    }

    .my-ticket-poster {
        width: 78px;
        height: 94px;
        flex-basis: 78px;
    }

    .my-ticket-event-details h2 {
        font-size: 16px;
    }

    .my-ticket-code-section {
        padding: 16px;
    }
}
</style>

<main class="my-tickets-page">
    <div class="my-tickets-container">

        <header class="my-tickets-heading">
            <div>
                <h1>My Tickets</h1>
                <p>All your issued event tickets, together in one place.</p>
            </div>

            <?php if ($pageError === ''): ?>
                <div class="ticket-count">
                    <span><?= $totalTickets ?></span>
                    <?= $totalTickets === 1 ? 'ticket' : 'tickets' ?>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($pageError !== ''): ?>

            <section class="my-tickets-error" role="alert">
                <h2>Tickets temporarily unavailable</h2>
                <p><?= mtEscape($pageError) ?></p>
                <a class="my-tickets-button" href="my-tickets.php">
                    Try again
                </a>
            </section>

        <?php elseif (empty($tickets)): ?>

            <section class="my-tickets-empty">
                <div class="my-tickets-empty-icon" aria-hidden="true">♧</div>

                <h2>No tickets yet</h2>

                <p>
                    When you purchase tickets and your payment is confirmed,
                    your issued tickets will appear here.
                </p>

                <a class="my-tickets-button" href="events.php">
                    Explore events
                </a>
            </section>

        <?php else: ?>

            <div class="my-tickets-grid">

                <?php foreach ($tickets as $ticket): ?>
                    <?php
                    $status = strtolower(
                        trim((string) ($ticket['ticket_status'] ?? 'valid'))
                    );

                    $statusClass = in_array(
                        $status,
                        ['used', 'cancelled', 'invalid'],
                        true
                    ) ? $status : 'valid';

                    $imageUrl = trim((string) ($ticket['image_url'] ?? ''));
                    $qrImage = null;

                    /*
                     * Generate this ticket's QR code from its stored token.
                     * Do not expose customer personal information in the QR.
                     */
                    try {
                        $qrToken = (string) ($ticket['qr_token'] ?? '');

                        if (
                            $qrToken !== '' &&
                            preg_match('/^[a-f0-9]{64}$/', $qrToken)
                        ) {
                            $qrImage = base64_encode(
                                generateTicketQrPng($qrToken)
                            );
                        }
                    } catch (Throwable $qrError) {
                        error_log(
                            'Ticketa QR display error for ticket #' .
                            (int) $ticket['issued_ticket_id'] . ': ' .
                            $qrError->getMessage()
                        );
                    }
                    ?>

                    <article class="my-ticket-card">

                        <div class="my-ticket-event">

                            <?php if ($imageUrl !== ''): ?>
                                <img
                                    class="my-ticket-poster"
                                    src="<?= mtEscape($imageUrl) ?>"
                                    alt="<?= mtEscape($ticket['event_title']) ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none';"
                                >
                            <?php else: ?>
                                <div
                                    class="my-ticket-poster"
                                    aria-hidden="true"
                                ></div>
                            <?php endif; ?>

                            <div class="my-ticket-event-details">
                                <h2><?= mtEscape($ticket['event_title']) ?></h2>

                                <div class="my-ticket-meta">
                                    <span>
                                        📅 <?= mtEscape(mtDate($ticket['event_date'])) ?>
                                    </span>

                                    <?php if (!empty($ticket['venue'])): ?>
                                        <span>
                                            📍 <?= mtEscape($ticket['venue']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($ticket['city'])): ?>
                                        <span><?= mtEscape($ticket['city']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>

                        <div class="my-ticket-divider"></div>

                        <div class="my-ticket-code-section">

                            <div class="my-ticket-type-row">
                                <div>
                                    <small>Ticket type</small>
                                    <strong><?= mtEscape($ticket['ticket_name']) ?></strong>
                                </div>

                                <span class="my-ticket-status <?= mtEscape($statusClass) ?>">
                                    <?= mtEscape($status ?: 'valid') ?>
                                </span>
                            </div>

                            <div class="my-ticket-type-row">
                                <div>
                                    <small>Attendee</small>
                                    <strong>
                                        <?= mtEscape(
                                            $ticket['attendee_name']
                                            ?: $ticket['customer_name']
                                        ) ?>
                                    </strong>
                                </div>

                                <div>
                                    <small>Order</small>
                                    <strong>
                                        <?= mtEscape(
                                            $ticket['order_number']
                                            ?: ('#' . $ticket['order_id'])
                                        ) ?>
                                    </strong>
                                </div>
                            </div>

                            <span class="my-ticket-code-label">
                                Unique ticket code
                            </span>

                            <code class="my-ticket-code"><?= mtEscape($ticket['ticket_code']) ?></code>

                            <section class="my-ticket-qr">
                                <strong>Your admission QR code</strong>

                                <?php if ($qrImage !== null): ?>
                                    <img
                                        src="data:image/png;base64,<?= mtEscape($qrImage) ?>"
                                        alt="QR code for ticket <?= mtEscape($ticket['ticket_code']) ?>"
                                        width="220"
                                        height="220"
                                    >

                                    <p>Present this QR code at the event entrance.</p>
                                    <p>Keep your ticket private to prevent unauthorized use.</p>
                                <?php else: ?>
                                    <p>Your QR code is temporarily unavailable.</p>
                                <?php endif; ?>
                            </section>

                        </div>

                        <footer class="my-ticket-bottom">
                            <span>
                                Issued: <?= mtEscape(mtDate($ticket['issued_at'])) ?>
                            </span>

                            <span>
                                <?= mtEscape(
                                    mtMoney(
                                        $ticket['unit_price'],
                                        $ticket['currency']
                                    )
                                ) ?>
                            </span>
                        </footer>

                    </article>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
