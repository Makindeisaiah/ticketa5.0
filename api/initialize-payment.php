<?php

declare(strict_types=1);



if (session_status() !== PHP_SESSION_ACTIVE) {

    session_start();

}



require_once __DIR__ . '/../config/database.php';



header('Content-Type: text/html; charset=UTF-8');



/**

 * Redirect back to checkout with an error message.

 */

function paymentFail(string $message): void

{

    $_SESSION['payment_error'] = $message;



    header('Location: ../checkout.php');

    exit;

}



/**

 * Generate a cryptographically random payment reference.

 */

function generatePaymentReference(): string

{

    return 'TICKETA' . strtoupper(bin2hex(random_bytes(16)));

}



/**

 * Normalize phone numbers for validation.

 */

function normalizePhone(string $phone): string

{

    return preg_replace('/\D+/', '', $phone) ?? '';

}



// Only accept POST requests.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    header('Allow: POST');

    exit('Method not allowed.');

}



// Validate the checkout CSRF token.

$submittedToken = $_POST['checkout_csrf'] ?? '';

$sessionToken = $_SESSION['checkout_csrf'] ?? '';



if (

    !is_string($submittedToken) ||

    !is_string($sessionToken) ||

    $sessionToken === '' ||

    !hash_equals($sessionToken, $submittedToken)

) {

    paymentFail('Your checkout session expired. Please try again.');

}



// Confirm checkout data exists.

$checkout = $_SESSION['checkout'] ?? null;



if (!is_array($checkout)) {

    paymentFail('Your checkout session has expired. Please select your tickets again.');

}



// Validate buyer details.

$fullName = trim((string) ($_POST['full_name'] ?? ''));

$email = trim((string) ($_POST['email'] ?? ''));

$phoneInput = trim((string) ($_POST['phone'] ?? ''));

$phoneCountry = trim((string) ($_POST['phone_country'] ?? ''));



if (

    $fullName === '' ||

    mb_strlen($fullName) > 150 ||

    preg_match('/[\x00-\x1F\x7F]/', $fullName)

) {

    paymentFail('Please enter a valid full name.');

}



if (

    strlen($email) > 254 ||

    filter_var($email, FILTER_VALIDATE_EMAIL) === false

) {

    paymentFail('Please enter a valid email address.');

}



$phoneDigits = normalizePhone($phoneInput);



if (strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {

    paymentFail('Please enter a valid phone number.');

}



// Accept only the country-code values your checkout form supplies.

// The phone field should contain the subscriber number if the country

// code is supplied separately.

$allowedCountryCodes = [

    '+225', '+234', '+228', '+233', '+221',

    '+223', '+226', '+227', '+229', '+237',

    '+254', '+27', '+33', '+1'

];



if (!in_array($phoneCountry, $allowedCountryCodes, true)) {

    paymentFail('Please select a valid phone country code.');

}



$phone = $phoneCountry . $phoneDigits;



// Validate event and ticket information stored by start-checkout.php.

$eventId = filter_var(

    $checkout['event_id'] ?? null,

    FILTER_VALIDATE_INT,

    ['options' => ['min_range' => 1]]

);



$tickets = $checkout['tickets'] ?? null;



if ($eventId === false || !is_array($tickets) || count($tickets) === 0) {

    paymentFail('Your ticket selection is invalid. Please select tickets again.');

}



if (count($tickets) > 20) {

    paymentFail('Too many ticket types were selected.');

}



// Load Paystack configuration.

$paystackConfig = require __DIR__ . '/../config/paystack.php';



if (!is_array($paystackConfig)) {

    error_log('Ticketa: Invalid Paystack configuration.');

    paymentFail('Payment configuration is unavailable. Please contact support.');

}



$secretKey = trim((string) ($paystackConfig['secret_key'] ?? ''));

$currency = strtoupper(trim((string) ($paystackConfig['currency'] ?? 'XOF')));

$callbackUrl = trim((string) ($paystackConfig['callback_url'] ?? ''));



if ($secretKey === '' || !str_starts_with($secretKey, 'sk_test_')) {

    error_log('Ticketa: A valid Paystack test secret key is not configured.');

    paymentFail('Test payment is not configured correctly.');

}



if (

    !in_array($currency, ['XOF'], true) ||

    !filter_var($callbackUrl, FILTER_VALIDATE_URL) ||

    strtolower((string) parse_url($callbackUrl, PHP_URL_SCHEME)) !== 'https'

) {

    error_log('Ticketa: Invalid Paystack currency or HTTPS callback URL.');

    paymentFail('Payment settings are invalid. Please contact support.');

}



if (!extension_loaded('curl')) {

    error_log('Ticketa: PHP cURL extension is not enabled.');

    paymentFail('The payment service is unavailable. Please try again later.');

}



if (!isset($pdo) || !($pdo instanceof PDO)) {

    error_log('Ticketa: PDO connection was not initialized.');

    paymentFail('The database is unavailable. Please try again later.');

}



// Use the database as the source of truth for event, prices and availability.

$transactionStarted = false;

$paymentReference = generatePaymentReference();



try {

    $pdo->beginTransaction();

    $transactionStarted = true;



    $eventStmt = $pdo->prepare(

        "SELECT id, title, status

         FROM events

         WHERE id = :event_id

         LIMIT 1"

    );



    $eventStmt->execute(['event_id' => $eventId]);

    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);



    if (!$event || strtolower((string) $event['status']) !== 'published') {

        throw new RuntimeException('This event is not available for booking.');

    }



    // Expire stale holds before calculating inventory.
    $expireReservationsStmt = $pdo->prepare(
        "UPDATE ticket_inventory_reservations
         SET status = 'expired'
         WHERE status = 'pending' AND expires_at <= NOW()"
    );
    $expireReservationsStmt->execute();

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



        // Do not accept duplicate rows for the same ticket type in one checkout.
        if (isset($seenTicketTypes[(int) $ticketTypeId])) {
            throw new RuntimeException('Please select each ticket type only once.');
        }
        $seenTicketTypes[(int) $ticketTypeId] = true;

        // Lock the ticket type while checking availability.

        $ticketStmt = $pdo->prepare(

            "SELECT id, event_id, name, price, quantity_total,

                    quantity_sold, sales_start, sales_end, is_active

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



        $now = new DateTimeImmutable('now');

        $salesStart = !empty($ticketType['sales_start'])

            ? new DateTimeImmutable($ticketType['sales_start'])

            : null;

        $salesEnd = !empty($ticketType['sales_end'])

            ? new DateTimeImmutable($ticketType['sales_end'])

            : null;



        if (

            ($salesStart && $now < $salesStart) ||

            ($salesEnd && $now > $salesEnd)

        ) {

            throw new RuntimeException('Ticket sales are not currently open for one of your selections.');

        }



        // Pending, unexpired reservations also consume available inventory.
        $reservedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM ticket_inventory_reservations
             WHERE ticket_type_id = :ticket_type_id
               AND status = 'pending'
               AND expires_at > NOW()"
        );
        $reservedStmt->execute(['ticket_type_id' => (int) $ticketType['id']]);
        $reservedQuantity = (int) $reservedStmt->fetchColumn();

        $available = (int) $ticketType['quantity_total']
            - (int) $ticketType['quantity_sold']
            - $reservedQuantity;



        if ($quantity > $available) {

            throw new RuntimeException(

                'There are not enough tickets available for ' .

                (string) $ticketType['name'] . '.'

            );

        }



        $unitPrice = (float) $ticketType['price'];



        if (!is_finite($unitPrice) || $unitPrice < 0) {

            throw new RuntimeException('A ticket price is invalid.');

        }



        // Work in XOF whole currency units, rounded to avoid float drift.

        $unitPrice = (int) round($unitPrice);



        if ($unitPrice > 100000000 || $quantity > 10) {

            throw new RuntimeException('The ticket amount is outside the permitted range.');

        }



        $lineTotal = $unitPrice * $quantity;

        $subtotal += $lineTotal;



        if ($subtotal > 1000000000) {

            throw new RuntimeException('The order total is outside the permitted range.');

        }



        $validatedItems[] = [

            'ticket_type_id' => (int) $ticketType['id'],

            'ticket_name' => (string) $ticketType['name'],

            'quantity' => $quantity,

            'unit_price' => $unitPrice,

            'line_total' => $lineTotal

        ];

    }



    // Ticketa currently charges no service fee.

    $serviceFee = 0;

    $total = $subtotal + $serviceFee;



    if ($total <= 0) {

        throw new RuntimeException(

            'This checkout has a zero total. Free tickets need a separate checkout flow.'

        );

    }



    /*

     * Paystack expects the amount in the currency's supported subunit.

     * Confirm the XOF amount format with your Paystack account before

     * accepting live payments. XOF is a zero-decimal currency.

     */

    $amountForPaystack = $total * 100;



    // Store a nullable user ID for guest checkout.

    $userId = null;



    if (isset($_SESSION['user']['id'])) {

        $candidateUserId = filter_var(

            $_SESSION['user']['id'],

            FILTER_VALIDATE_INT,

            ['options' => ['min_range' => 1]]

        );



        if ($candidateUserId !== false) {

            $userId = $candidateUserId;

        }

    }



    // Create a unique order number.

    $orderNumber = 'TKT-' . strtoupper(bin2hex(random_bytes(6)));



    $orderStmt = $pdo->prepare(

        "INSERT INTO orders

            (user_id, order_number, event_id, customer_name,

             customer_email, customer_phone, currency, subtotal,

             service_fee, total_amount, status)

         VALUES

            (:user_id, :order_number, :event_id, :customer_name,

             :customer_email, :customer_phone, :currency, :subtotal,

             :service_fee, :total_amount, 'pending')"

    );



    $orderStmt->execute([

        'user_id' => $userId,

        'order_number' => $orderNumber,

        'event_id' => $eventId,

        'customer_name' => $fullName,

        'customer_email' => $email,

        'customer_phone' => $phone,

        'currency' => $currency,

        'subtotal' => $subtotal,

        'service_fee' => $serviceFee,

        'total_amount' => $total

    ]);



    $orderId = (int) $pdo->lastInsertId();



    $itemStmt = $pdo->prepare(

        "INSERT INTO order_items

            (order_id, ticket_type_id, ticket_name, quantity,

             unit_price, line_total)

         VALUES

            (:order_id, :ticket_type_id, :ticket_name, :quantity,

             :unit_price, :line_total)"

    );



    $reservationStmt = $pdo->prepare(
        "INSERT INTO ticket_inventory_reservations
            (order_id, ticket_type_id, quantity, status, expires_at)
         VALUES
            (:order_id, :ticket_type_id, :quantity, 'pending', DATE_ADD(NOW(), INTERVAL 15 MINUTE))"
    );

    foreach ($validatedItems as $item) {
        $itemStmt->execute([
            'order_id' => $orderId,
            'ticket_type_id' => $item['ticket_type_id'],
            'ticket_name' => $item['ticket_name'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'line_total' => $item['line_total']
        ]);

        // Hold these tickets while the customer completes Paystack checkout.
        $reservationStmt->execute([
            'order_id' => $orderId,
            'ticket_type_id' => $item['ticket_type_id'],
            'quantity' => $item['quantity']
        ]);
    }

    // Create the pending payment record.

    $paymentStmt = $pdo->prepare(

        "INSERT INTO payments

            (order_id, provider, reference, amount, currency, status)

         VALUES

            (:order_id, 'paystack', :reference, :amount, :currency, 'initialized')"

    );



    $paymentStmt->execute([

        'order_id' => $orderId,

        'reference' => $paymentReference,

        'amount' => $total,

        'currency' => $currency

    ]);



    /*

     * Keep the order and payment in the same transaction until Paystack

     * confirms it has accepted the initialization request.

     */

    $payload = [

        'email' => $email,

        'amount' => $amountForPaystack,

        'currency' => $currency,

        'reference' => $paymentReference,

        'callback_url' => $callbackUrl,

        'metadata' => [

            'order_id' => $orderId,

            'order_number' => $orderNumber,

            'event_id' => (int) $eventId,

            'customer_name' => $fullName,

            'custom_fields' => [

                [

                    'display_name' => 'Order Number',

                    'variable_name' => 'order_number',

                    'value' => $orderNumber

                ]

            ]

        ]

    ];



    $curl = curl_init('https://api.paystack.co/transaction/initialize');



    if ($curl === false) {

        throw new RuntimeException('Unable to initialize the payment connection.');

    }



    curl_setopt_array($curl, [

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => json_encode(

            $payload,

            JSON_THROW_ON_ERROR

        ),

        CURLOPT_HTTPHEADER => [

            'Authorization: Bearer ' . $secretKey,

            'Content-Type: application/json',

            'Accept: application/json'

        ],

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 10,

        CURLOPT_TIMEOUT => 30

    ]);



    $responseBody = curl_exec($curl);

    $curlError = curl_error($curl);

    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);



    curl_close($curl);



    if ($responseBody === false) {

        error_log('Ticketa Paystack connection failed: ' . $curlError);

        throw new RuntimeException('Could not connect to the payment provider. Please try again.');

    }



    $response = json_decode($responseBody, true);



    if (

        $httpStatus < 200 ||

        $httpStatus >= 300 ||

        !is_array($response) ||

        empty($response['status']) ||

        empty($response['data']['authorization_url'])

    ) {

        $providerMessage = is_array($response)

            ? (string) ($response['message'] ?? 'Unknown provider error')

            : 'Invalid provider response';



        error_log(

            'Ticketa Paystack initialization failed. HTTP ' .

            $httpStatus . ': ' . $providerMessage

        );



        throw new RuntimeException(

            'The payment provider could not start your payment. Please try again.'

        );

    }



    // Save the transaction and payment reference before redirecting.

    $pdo->commit();

    $transactionStarted = false;



    // Avoid duplicate submissions after successful initialization.

    unset($_SESSION['checkout_csrf']);



    $_SESSION['pending_payment'] = [

        'order_id' => $orderId,

        'reference' => $paymentReference,

        'order_number' => $orderNumber

    ];



    header('Location: ' . $response['data']['authorization_url'], true, 302);

    exit;



} catch (Throwable $e) {

    if ($transactionStarted && $pdo->inTransaction()) {

        $pdo->rollBack();

    }



    error_log('Ticketa payment initialization error: ' . $e->getMessage());



    // Show a safe message instead of exposing database/API details.

    if ($e instanceof RuntimeException) {

        paymentFail($e->getMessage());

    }



    paymentFail('We could not start your payment. Please check your details and try again.');

}
