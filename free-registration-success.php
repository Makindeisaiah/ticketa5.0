<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$registration = $_SESSION['free_registration_success'] ?? null;
// Keep the ticket payload for this page render, then clear it after use.
unset($_SESSION['free_registration_success']);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$qrHelperAvailable = is_file(__DIR__ . '/includes/qr-helper.php');
if ($qrHelperAvailable) {
    require_once __DIR__ . '/includes/qr-helper.php';
}

$pageTitle = 'Registration confirmed | TICKETA';
$pageStyles = ['assets/css/checkout.css'];
require __DIR__ . '/includes/header.php';
?>
<main class="checkout-page">
    <div class="checkout-container">
        <?php if (!is_array($registration)): ?>
            <section class="checkout-card" role="status">
                <h1>Registration details unavailable</h1>
                <p>Please check your email for confirmation, or browse events to register again.</p>
                <a class="checkout-primary-link" href="events.php">Browse events</a>
            </section>
        <?php else: ?>
            <section class="checkout-card" role="status">
                <div class="payment-intro-icon" aria-hidden="true"><span>✓</span></div>
                <h1>Registration confirmed!</h1>
                <p>Your free registration for <strong><?= e($registration['event_title'] ?? 'your event') ?></strong> is complete.</p>
                <p>Order reference: <strong><?= e($registration['order_number'] ?? '') ?></strong></p>
                <p>Tickets issued: <strong><?= (int) ($registration['ticket_count'] ?? 0) ?></strong></p>
                <?php if (!empty($registration['email_sent'])): ?>
                    <p>A confirmation email with your ticket QR code<?= (int) ($registration['ticket_count'] ?? 0) === 1 ? '' : 's' ?> has been sent to your email address.</p>
                <?php else: ?>
                    <p>Your registration is saved, but the confirmation email could not be sent right now. Please keep this page open long enough to view your ticket QR code, and contact support if needed.</p>
                <?php endif; ?>
            </section>

            <?php foreach (($registration['tickets'] ?? []) as $ticket): ?>
                <?php
                $qrImage = null;
                $qrToken = strtolower(trim((string) ($ticket['qr_token'] ?? '')));
                if ($qrHelperAvailable && preg_match('/^[a-f0-9]{64}$/', $qrToken)) {
                    try {
                        $qrImage = base64_encode(generateTicketQrPng($qrToken));
                    } catch (Throwable $qrError) {
                        error_log('Ticketa free-registration QR display failed: ' . $qrError->getMessage());
                    }
                }
                ?>
                <section class="checkout-card" style="margin-top:20px;text-align:center">
                    <h2><?= e($ticket['ticket_name'] ?? 'Event ticket') ?></h2>
                    <p>Attendee: <strong><?= e($ticket['attendee_name'] ?? '') ?></strong></p>
                    <p>Ticket code: <strong><?= e($ticket['ticket_code'] ?? '') ?></strong></p>
                    <?php if ($qrImage !== null): ?>
                        <img src="data:image/png;base64,<?= e($qrImage) ?>" alt="Admission QR code for ticket <?= e($ticket['ticket_code'] ?? '') ?>" width="240" height="240" style="display:block;width:240px;height:240px;max-width:100%;margin:16px auto">
                        <p>Present this QR code at the event entrance.</p>
                    <?php else: ?>
                        <p>Your QR code could not be displayed. Your ticket code is shown above; contact support before the event.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <p style="margin-top:20px"><a class="checkout-primary-link" href="events.php">Browse more events</a></p>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
