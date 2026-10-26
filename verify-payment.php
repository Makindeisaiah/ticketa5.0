<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mailer.php';

header('Cache-Control: no-store');

function paymentVerificationError(
    string $message,
    int $status = 400
): never {
    http_response_code($status);

    $safeMessage = htmlspecialchars(
        $message,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Payment status | TICKETA</title></head>';
    echo '<body style="font-family:Arial,sans-serif;max-width:650px;margin:60px auto;padding:20px">';
    echo '<h1>Payment verification</h1><p>' . $safeMessage . '</p>';
    echo '<p><a href="index.php">Return to Ticketa</a></p></body></html>';
    exit;
}

$reference = trim((string) ($_GET['reference'] ?? ''));

if (
    $reference === '' ||
    strlen($reference) > 100 ||
    !preg_match('/^[A-Za-z0-9_-]+$/', $reference)
) {
    paymentVerificationError(
        'The payment reference is missing or invalid.'
    );
}

$config = require __DIR__ . '/config/paystack.php';
$secretKey = (string) ($config['secret_key'] ?? '');

if ($secretKey === '' || !extension_loaded('curl')) {
    paymentVerificationError(
        'Payment verification is temporarily unavailable.',
        503
    );
}

try {
    /*
     * 1. Find the payment and order.
     */
    $stmt = $pdo->prepare(
        "SELECT
            p.id AS payment_id,
            p.order_id,
            p.reference,
            p.amount AS payment_amount,
            p.currency AS payment_currency,
            o.total_amount,
            o.currency AS order_currency
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE p.reference = :reference
           AND p.provider = 'paystack'
         LIMIT 1"
    );

    $stmt->execute(['reference' => $reference]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        paymentVerificationError('We could not find this payment.');
    }

    /*
     * 2. Verify the transaction directly with Paystack.
     */
    $url = 'https://api.paystack.co/transaction/verify/' .
        rawurlencode($reference);

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (
        $response === false ||
        $curlError !== '' ||
        $httpStatus < 200 ||
        $httpStatus >= 300
    ) {
        error_log(
            'Ticketa Paystack verification request failed: ' . $curlError
        );

        paymentVerificationError(
            'We could not confirm your payment yet. Please contact support before paying again.',
            502
        );
    }

    $result = json_decode($response, true);
    $transaction = $result['data'] ?? null;

    if (
        !is_array($result) ||
        empty($result['status']) ||
        !is_array($transaction)
    ) {
        paymentVerificationError(
            'Paystack did not return a valid verification result.',
            502
        );
    }

    /*
     * Paystack amount is stored in subunits.
     * Database amounts remain in XOF units.
     */
    $expectedAmount = (int) $payment['payment_amount'] * 100;

    $verified =
        ($transaction['status'] ?? '') === 'success' &&
        ($transaction['reference'] ?? '') === $reference &&
        strtoupper((string) ($transaction['currency'] ?? '')) === 'XOF' &&
        (int) ($transaction['amount'] ?? -1) === $expectedAmount &&
        strtoupper((string) $payment['payment_currency']) === 'XOF' &&
        strtoupper((string) $payment['order_currency']) === 'XOF' &&
        (int) $payment['payment_amount'] ===
            (int) $payment['total_amount'];

    if (!$verified) {
        paymentVerificationError(
            'Payment has not been confirmed. Your order has not been marked as paid.'
        );
    }

    $orderId = (int) $payment['order_id'];

    /*
     * 3. Mark payment as successful and issue missing tickets
     *    inside one database transaction.
     */
    $pdo->beginTransaction();

    $lock = $pdo->prepare(
        "SELECT
            p.status AS payment_status,
            o.status AS order_status
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE p.id = :payment_id
         FOR UPDATE"
    );

    $lock->execute(['payment_id' => $payment['payment_id']]);
    $locked = $lock->fetch(PDO::FETCH_ASSOC);

    if (!$locked) {
        throw new RuntimeException('Payment record disappeared.');
    }

    if (
        $locked['payment_status'] !== 'success' ||
        $locked['order_status'] !== 'paid'
    ) {
        /*
         * Count inventory only when this order first transitions to paid.
         * Lock each ticket type and account for other customers' live
         * reservations before incrementing quantity_sold.
         */
        if ($locked['order_status'] !== 'paid') {
            $inventoryItemsStmt = $pdo->prepare(
                "SELECT ticket_type_id, SUM(quantity) AS quantity
                 FROM order_items
                 WHERE order_id = :order_id
                 GROUP BY ticket_type_id
                 ORDER BY ticket_type_id ASC"
            );
            $inventoryItemsStmt->execute(['order_id' => $orderId]);
            $inventoryItems = $inventoryItemsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$inventoryItems) {
                throw new RuntimeException(
                    'No order items found while confirming ticket inventory.'
                );
            }

            $ticketLockStmt = $pdo->prepare(
                "SELECT id, quantity_total, quantity_sold
                 FROM ticket_types
                 WHERE id = :ticket_type_id
                 FOR UPDATE"
            );

            $otherReservationsStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(quantity), 0)
                 FROM ticket_inventory_reservations
                 WHERE ticket_type_id = :ticket_type_id
                   AND order_id <> :order_id
                   AND status = 'pending'
                   AND expires_at > NOW()"
            );

            $incrementSoldStmt = $pdo->prepare(
                "UPDATE ticket_types
                 SET quantity_sold = quantity_sold + :quantity
                 WHERE id = :ticket_type_id
                   AND quantity_sold + :quantity_check <= quantity_total"
            );

            foreach ($inventoryItems as $inventoryItem) {
                $ticketTypeId = (int) $inventoryItem['ticket_type_id'];
                $quantityToSell = (int) $inventoryItem['quantity'];

                if ($ticketTypeId < 1 || $quantityToSell < 1) {
                    throw new RuntimeException(
                        'An order contains invalid ticket inventory data.'
                    );
                }

                $ticketLockStmt->execute([
                    'ticket_type_id' => $ticketTypeId
                ]);
                $ticketType = $ticketLockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$ticketType) {
                    throw new RuntimeException(
                        'A ticket type for this order no longer exists.'
                    );
                }

                $otherReservationsStmt->execute([
                    'ticket_type_id' => $ticketTypeId,
                    'order_id' => $orderId
                ]);
                $otherReserved = (int) $otherReservationsStmt->fetchColumn();

                $available = (int) $ticketType['quantity_total']
                    - (int) $ticketType['quantity_sold']
                    - $otherReserved;

                /*
                 * A payment can complete after its reservation expires.
                 * Never oversell: if stock has since gone, leave the
                 * transaction uncommitted and flag it for manual review.
                 */
                if ($quantityToSell > $available) {
                    throw new RuntimeException(
                        'Verified payment has insufficient remaining ticket inventory. '
                        . 'Order ID: ' . $orderId
                        . ', ticket type ID: ' . $ticketTypeId
                    );
                }

                $incrementSoldStmt->execute([
                    'quantity' => $quantityToSell,
                    'quantity_check' => $quantityToSell,
                    'ticket_type_id' => $ticketTypeId
                ]);

                if ($incrementSoldStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Ticket inventory could not be updated safely.'
                    );
                }
            }

            $confirmReservationsStmt = $pdo->prepare(
                "UPDATE ticket_inventory_reservations
                 SET status = 'confirmed'
                 WHERE order_id = :order_id
                   AND status = 'pending'"
            );
            $confirmReservationsStmt->execute(['order_id' => $orderId]);
        }

        $updatePayment = $pdo->prepare(
            "UPDATE payments
             SET status = 'success',
                 channel = :channel,
                 provider_transaction_id = :transaction_id,
                 verified_at = NOW()
             WHERE id = :payment_id"
        );

        $updatePayment->execute([
            'channel' => (string) ($transaction['channel'] ?? ''),
            'transaction_id' => isset($transaction['id'])
                ? (string) $transaction['id']
                : null,
            'payment_id' => $payment['payment_id'],
        ]);

        $updateOrder = $pdo->prepare(
            "UPDATE orders
             SET status = 'paid', updated_at = NOW()
             WHERE id = :order_id AND status <> 'paid'"
        );

        $updateOrder->execute(['order_id' => $orderId]);
    }

    // Retrieve all items belonging to this order.
    $itemsStmt = $pdo->prepare(
        "SELECT
            oi.id AS order_item_id,
            oi.quantity,
            o.customer_name
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.order_id = :order_id
         ORDER BY oi.id ASC
         FOR UPDATE"
    );

    $itemsStmt->execute(['order_id' => $orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$orderItems) {
        throw new RuntimeException(
            'No order items found for this paid order.'
        );
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM issued_tickets
         WHERE order_item_id = :order_item_id"
    );

    $insertTicketStmt = $pdo->prepare(
        "INSERT INTO issued_tickets
            (order_item_id, ticket_code, attendee_name, qr_token)
         VALUES
            (:order_item_id, :ticket_code, :attendee_name, :qr_token)"
    );

    foreach ($orderItems as $item) {
        $orderItemId = (int) $item['order_item_id'];
        $quantity = (int) $item['quantity'];
        $attendeeName = (string) $item['customer_name'];

        if ($quantity < 1) {
            throw new RuntimeException(
                'An order item has an invalid ticket quantity.'
            );
        }

        $countStmt->execute([
            'order_item_id' => $orderItemId
        ]);

        $alreadyIssued = (int) $countStmt->fetchColumn();

        if ($alreadyIssued > $quantity) {
            throw new RuntimeException(
                'The number of issued tickets exceeds the purchased quantity.'
            );
        }

        for ($i = $alreadyIssued; $i < $quantity; $i++) {
            $ticketCode = 'TKT-' .
                strtoupper(bin2hex(random_bytes(16)));

            // The QR code contains only this random token, not personal data.
            $qrToken = bin2hex(random_bytes(32));

            $insertTicketStmt->execute([
                'order_item_id' => $orderItemId,
                'ticket_code' => $ticketCode,
                'attendee_name' => $attendeeName,
                'qr_token' => $qrToken,
            ]);
        }
    }

    /*
     * 4. Reserve the email notification so concurrent callbacks
     *    don't normally send duplicate confirmation emails.
     */
    $emailOrderStmt = $pdo->prepare(
        "SELECT
            o.id,
            o.customer_name,
            o.customer_email,
            o.ticket_email_sent_at,
            e.title AS event_title,
            e.event_date,
            e.venue,
            e.city
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
         INNER JOIN events e ON e.id = tt.event_id
         WHERE o.id = :order_id
           AND o.status = 'paid'
         LIMIT 1
         FOR UPDATE"
    );

    $emailOrderStmt->execute(['order_id' => $orderId]);
    $emailOrder = $emailOrderStmt->fetch(PDO::FETCH_ASSOC);

    $emailClaimed = false;

    if (
        $emailOrder &&
        $emailOrder['ticket_email_sent_at'] === null &&
        filter_var(
            $emailOrder['customer_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $emailTicketsStmt = $pdo->prepare(
            "SELECT
                oi.ticket_name,
                it.ticket_code,
                it.attendee_name,
                it.qr_token
             FROM order_items oi
             INNER JOIN issued_tickets it
                ON it.order_item_id = oi.id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id, it.id"
        );

        $emailTicketsStmt->execute(['order_id' => $orderId]);
        $emailTickets = $emailTicketsStmt->fetchAll(PDO::FETCH_ASSOC);

        $purchasedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM order_items
             WHERE order_id = :order_id"
        );

        $purchasedStmt->execute(['order_id' => $orderId]);
        $ticketsPurchased = (int) $purchasedStmt->fetchColumn();

        if (
            $ticketsPurchased > 0 &&
            count($emailTickets) === $ticketsPurchased
        ) {
            // Claim the notification before releasing the order lock.
            $claimEmailStmt = $pdo->prepare(
                "UPDATE orders
                 SET ticket_email_sent_at = NOW()
                 WHERE id = :order_id
                   AND ticket_email_sent_at IS NULL"
            );

            $claimEmailStmt->execute(['order_id' => $orderId]);
            $emailClaimed = $claimEmailStmt->rowCount() === 1;
        }
    }

    $pdo->commit();

    /*
     * 5. Send email after the database transaction commits.
     *    If email fails, leave the payment and issued tickets intact.
     */
    if ($emailClaimed && $emailOrder) {
        try {
            sendTicketConfirmationEmail(
                (string) $emailOrder['customer_email'],
                (string) $emailOrder['customer_name'],
                $orderId,
                (string) $emailOrder['event_title'],
                (string) $emailOrder['event_date'],
                (string) $emailOrder['venue'],
                (string) $emailOrder['city'],
                $emailTickets
            );

            // Store the actual successful send time.
            $markSentStmt = $pdo->prepare(
                "UPDATE orders
                 SET ticket_email_sent_at = NOW()
                 WHERE id = :order_id"
            );

            $markSentStmt->execute(['order_id' => $orderId]);

        } catch (Throwable $emailError) {
            error_log(
                'Ticketa ticket email failed for order #' .
                $orderId . ': ' . $emailError->getMessage()
            );

            // Release the claim so a later callback can retry.
            try {
                $resetEmailStmt = $pdo->prepare(
                    "UPDATE orders
                     SET ticket_email_sent_at = NULL
                     WHERE id = :order_id"
                );

                $resetEmailStmt->execute(['order_id' => $orderId]);

            } catch (Throwable $resetError) {
                error_log(
                    'Ticketa email claim reset failed for order #' .
                    $orderId . ': ' . $resetError->getMessage()
                );
            }
        }
    }

    unset(
        $_SESSION['pending_order_id'],
        $_SESSION['last_payment_reference'],
        $_SESSION['checkout'],
        $_SESSION['checkout_buyer'],
        $_SESSION['checkout_csrf']
    );

    $pageTitle = 'Payment Successful | TICKETA';
    $pageStyles = [];

    require __DIR__ . '/includes/header.php';
    ?>

    <main style="max-width:650px;margin:60px auto;padding:24px">
        <h1>Payment successful!</h1>

        <p>Your payment has been verified by Paystack.</p>

        <p>
            Order number:
            <strong>
                <?= htmlspecialchars(
                    (string) $orderId,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </strong>
        </p>

        <p>Your ticket has been issued successfully.</p>

        <p>
            If your email address is valid and the email service is
            available, your ticket confirmation will be sent to your inbox.
        </p>

        <p><a href="my-tickets.php">View My Tickets</a></p>
        <p><a href="index.php">Return to Ticketa</a></p>
    </main>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Ticketa payment verification error: ' . $e->getMessage()
    );

    paymentVerificationError(
        'We could not finish verifying your payment. Please contact support before trying to pay again.',
        500
    );
}
