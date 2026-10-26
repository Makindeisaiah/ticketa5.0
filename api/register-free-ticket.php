<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function freeRegistrationFail(string $message): never
{
    $_SESSION['payment_error'] = $message;
    header('Location: ../checkout.php');
    exit;
}

function normalizeFreePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = $_POST['checkout_csrf'] ?? '';
$sessionToken = $_SESSION['checkout_csrf'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    freeRegistrationFail('Your checkout session expired. Please try again.');
}

$checkout = $_SESSION['checkout'] ?? null;
if (!is_array($checkout) || empty($checkout['tickets'])) {
    freeRegistrationFail('Your ticket selection has expired. Please select your tickets again.');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phoneInput = trim((string) ($_POST['phone'] ?? ''));
$phoneCountry = trim((string) ($_POST['phone_country'] ?? ''));

if (
    $fullName === '' ||
    mb_strlen($fullName) > 150 ||
    preg_match('/[\x00-\x1F\x7F]/', $fullName)
) {
    freeRegistrationFail('Please enter a valid full name.');
}

if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    freeRegistrationFail('Please enter a valid email address.');
}

$phoneDigits = normalizeFreePhone($phoneInput);
$allowedCountryCodes = ['+225', '+234', '+221', '+223', '+226', '+228', '+229', '+233'];
if (
    strlen($phoneDigits) < 8 ||
    strlen($phoneDigits) > 15 ||
    !in_array($phoneCountry, $allowedCountryCodes, true)
) {
    freeRegistrationFail('Please enter a valid phone number and country code.');
}
$phone = $phoneCountry . $phoneDigits;

$eventId = filter_var(
    $checkout['event_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$tickets = $checkout['tickets'];

if ($eventId === false || !is_array($tickets) || count($tickets) < 1 || count($tickets) > 20) {
    freeRegistrationFail('Your ticket selection is invalid. Please select tickets again.');
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('Ticketa free registration: PDO connection unavailable.');
    freeRegistrationFail('The database is unavailable. Please try again later.');
}

try {
    $pdo->beginTransaction();

    $eventStmt = $pdo->prepare(
        "SELECT id, title, status FROM events WHERE id = :event_id LIMIT 1 FOR UPDATE"
    );
    $eventStmt->execute(['event_id' => $eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event || strtolower((string) $event['status']) !== 'published') {
        throw new RuntimeException('This event is not available for registration.');
    }

    $expireStmt = $pdo->prepare(
        "UPDATE ticket_inventory_reservations
         SET status = 'expired'
         WHERE status = 'pending' AND expires_at <= NOW()"
    );
    $expireStmt->execute();

    $subtotal = 0;
    $validatedItems = [];
    $seenTicketTypes = [];

    foreach ($tickets as $ticket) {
        if (!is_array($ticket)) {
            throw new RuntimeException('Invalid ticket selection.');
        }

        $ticketTypeId = filter_var(
            $ticket['ticket_type_id'] ?? $ticket['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $quantity = filter_var(
            $ticket['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10]]
        );

        if ($ticketTypeId === false || $quantity === false) {
            throw new RuntimeException('A selected ticket or quantity is invalid.');
        }
        if (isset($seenTicketTypes[(int) $ticketTypeId])) {
            throw new RuntimeException('Please select each ticket type only once.');
        }
        $seenTicketTypes[(int) $ticketTypeId] = true;

        $ticketStmt = $pdo->prepare(
            "SELECT id, event_id, name, price, quantity_total, quantity_sold,
                    sales_start, sales_end, is_active
             FROM ticket_types
             WHERE id = :ticket_id AND event_id = :event_id
             FOR UPDATE"
        );
        $ticketStmt->execute([
            'ticket_id' => $ticketTypeId,
            'event_id' => $eventId
        ]);
        $ticketType = $ticketStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticketType || (int) $ticketType['is_active'] !== 1) {
            throw new RuntimeException('One of your selected ticket types is unavailable.');
        }

        // Free checkout must be verified against database prices, not session totals.
        if ((int) $ticketType['price'] !== 0) {
            throw new RuntimeException('This selection contains a paid ticket. Please use the payment checkout.');
        }

        $now = new DateTimeImmutable('now');
        $salesStart = !empty($ticketType['sales_start']) ? new DateTimeImmutable($ticketType['sales_start']) : null;
        $salesEnd = !empty($ticketType['sales_end']) ? new DateTimeImmutable($ticketType['sales_end']) : null;

        if (($salesStart && $now < $salesStart) || ($salesEnd && $now > $salesEnd)) {
            throw new RuntimeException('Ticket sales are not currently open for one of your selections.');
        }

        $reservedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM ticket_inventory_reservations
             WHERE ticket_type_id = :ticket_type_id
               AND status = 'pending'
               AND expires_at > NOW()"
        );
        $reservedStmt->execute(['ticket_type_id' => $ticketTypeId]);
        $pendingReserved = (int) $reservedStmt->fetchColumn();

        $quantityTotal = (int) $ticketType['quantity_total'];
        $quantitySold = (int) $ticketType['quantity_sold'];
        if ($quantityTotal > 0 && ($quantityTotal - $quantitySold - $pendingReserved) < $quantity) {
            throw new RuntimeException('There are not enough tickets remaining for one of your selections.');
        }

        $lineTotal = 0;
        $subtotal += $lineTotal;
        $validatedItems[] = [
            'ticket_type_id' => (int) $ticketType['id'],
            'ticket_name' => (string) $ticketType['name'],
            'quantity' => (int) $quantity,
            'unit_price' => 0,
            'line_total' => 0
        ];
    }

    // This endpoint is only for genuinely zero-value registrations.
    if ($subtotal !== 0 || (int) ($checkout['service_fee'] ?? 0) !== 0) {
        throw new RuntimeException('This checkout is not free. Please continue through the payment checkout.');
    }

    $orderNumber = 'TKT-FREE-' . strtoupper(bin2hex(random_bytes(8)));
    $userId = isset($_SESSION['user_id']) && filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT)
        ? (int) $_SESSION['user_id']
        : null;

    $orderStmt = $pdo->prepare(
        "INSERT INTO orders
         (user_id, order_number, event_id, customer_name, customer_email, customer_phone,
          currency, subtotal, service_fee, total_amount, status)
         VALUES
         (:user_id, :order_number, :event_id, :customer_name, :customer_email, :customer_phone,
          'XOF', 0, 0, 0, 'free')"
    );
    $orderStmt->execute([
        'user_id' => $userId,
        'order_number' => $orderNumber,
        'event_id' => $eventId,
        'customer_name' => $fullName,
        'customer_email' => $email,
        'customer_phone' => $phone
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        "INSERT INTO order_items
         (order_id, ticket_type_id, ticket_name, quantity, unit_price, line_total)
         VALUES (:order_id, :ticket_type_id, :ticket_name, :quantity, 0, 0)"
    );
    $reservationStmt = $pdo->prepare(
        "INSERT INTO ticket_inventory_reservations
         (order_id, ticket_type_id, quantity, status, expires_at)
         VALUES (:order_id, :ticket_type_id, :quantity, 'confirmed', NOW())"
    );
    $soldStmt = $pdo->prepare(
        "UPDATE ticket_types SET quantity_sold = quantity_sold + :quantity WHERE id = :ticket_type_id"
    );
    $issuedStmt = $pdo->prepare(
        "INSERT INTO issued_tickets
         (order_item_id, ticket_code, attendee_name, status, qr_token)
         VALUES (:order_item_id, :ticket_code, :attendee_name, 'valid', :qr_token)"
    );

    $issuedCount = 0;
    foreach ($validatedItems as $item) {
        $itemStmt->execute([
            'order_id' => $orderId,
            'ticket_type_id' => $item['ticket_type_id'],
            'ticket_name' => $item['ticket_name'],
            'quantity' => $item['quantity']
        ]);
        $orderItemId = (int) $pdo->lastInsertId();

        $reservationStmt->execute([
            'order_id' => $orderId,
            'ticket_type_id' => $item['ticket_type_id'],
            'quantity' => $item['quantity']
        ]);

        $soldStmt->execute([
            'quantity' => $item['quantity'],
            'ticket_type_id' => $item['ticket_type_id']
        ]);

        for ($i = 0; $i < $item['quantity']; $i++) {
            $ticketCode = 'TKT-' . strtoupper(bin2hex(random_bytes(8)));
            $qrToken = bin2hex(random_bytes(32));
            $issuedStmt->execute([
                'order_item_id' => $orderItemId,
                'ticket_code' => $ticketCode,
                'attendee_name' => $fullName,
                'qr_token' => $qrToken
            ]);
            $issuedCount++;
        }
    }

    $pdo->commit();

    // Read the issued tickets back after commit for the email and success page.
    $issuedTicketsStmt = $pdo->prepare(
        "SELECT oi.ticket_name, it.ticket_code, it.attendee_name, it.qr_token
         FROM issued_tickets it
         INNER JOIN order_items oi ON oi.id = it.order_item_id
         WHERE oi.order_id = :order_id
         ORDER BY it.id ASC"
    );
    $issuedTicketsStmt->execute(['order_id' => $orderId]);
    $issuedTickets = $issuedTicketsStmt->fetchAll(PDO::FETCH_ASSOC);

    $emailSent = false;
    try {
        require_once __DIR__ . '/../includes/free-ticket-mailer.php';
        sendFreeTicketConfirmationEmail(
            $email,
            $fullName,
            $orderId,
            (string) $event['title'],
            (string) ($checkout['event_date'] ?? ''),
            (string) ($checkout['venue'] ?? ''),
            (string) ($checkout['city'] ?? ''),
            $issuedTickets
        );
        $emailSent = true;
    } catch (Throwable $mailError) {
        // Registration is already committed; never roll it back because SMTP failed.
        error_log('Ticketa free-ticket email failed for order ' . $orderNumber . ': ' . $mailError->getMessage());
    }

    // Clear the checkout selection to reduce accidental duplicate submissions.
    unset($_SESSION['checkout'], $_SESSION['checkout_buyer'], $_SESSION['checkout_csrf']);
    $_SESSION['free_registration_success'] = [
        'order_number' => $orderNumber,
        'event_title' => (string) $event['title'],
        'ticket_count' => $issuedCount,
        'email_sent' => $emailSent,
        'tickets' => $issuedTickets
    ];

    header('Location: ../free-registration-success.php');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ticketa free registration failed: ' . $e->getMessage());
    $safeMessage = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'We could not complete your free registration. Please try again.';
    freeRegistrationFail($safeMessage);
}
