<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mailer.php';

try {
    $orderId = 9;

    $stmt = $pdo->prepare(
        "SELECT
            o.id,
            o.customer_name,
            o.customer_email,
            e.title AS event_title,
            e.event_date,
            e.venue,
            e.city
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         JOIN ticket_types tt ON tt.id = oi.ticket_type_id
         JOIN events e ON e.id = tt.event_id
         WHERE o.id = :order_id
           AND o.status = 'paid'
         LIMIT 1"
    );

    $stmt->execute(['order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException('Paid order not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT
            oi.ticket_name,
            it.ticket_code,
            it.attendee_name
         FROM order_items oi
         JOIN issued_tickets it ON it.order_item_id = oi.id
         WHERE oi.order_id = :order_id
         ORDER BY oi.id, it.id"
    );

    $stmt->execute(['order_id' => $orderId]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$tickets) {
        throw new RuntimeException('No issued tickets found.');
    }

    sendTicketConfirmationEmail(
        $order['customer_email'],
        $order['customer_name'],
        (int) $order['id'],
        $order['event_title'],
        $order['event_date'],
        $order['venue'],
        $order['city'],
        $tickets
    );

    echo 'Ticket confirmation email sent successfully.';

} catch (Throwable $e) {
    error_log('Ticket email test failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Email test failed. Check the PHP error log.';
}
