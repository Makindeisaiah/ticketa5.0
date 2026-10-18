
<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store');

function fail(string $message, int $status = 400): never {
    http_response_code($status);
    echo '<!doctype html><html lang="en"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Checkout error | TICKETA</title><body style="font-family:Arial,sans-serif;padding:32px">';
    echo '<h1>We could not start your payment</h1><p>';
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '</p><p><a href="../checkout.php">Return to checkout</a></p></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail('Please submit the checkout form.');
}

$postedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['checkout_csrf'] ?? '');

if (
    $postedToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $postedToken)
) {
    fail('Your checkout session expired. Refresh the checkout page and try again.');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$countryCode = trim((string) ($_POST['phone_country'] ?? '+225'));

if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
    fail('Enter a valid full name.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fail('Enter a valid email address.');
}

$allowedCountryCodes = ['+225', '+234', '+221', '+223', '+226', '+228', '+229', '+233'];
if (!in_array($countryCode, $allowedCountryCodes, true)) {
    fail('Choose a valid country code.');
}

$phoneDigits = preg_replace('/\D+/', '', $phone);
if (!$phoneDigits || strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
    fail('Enter a valid phone number.');
}

$checkout = $_SESSION['checkout'] ?? null;

if (!is_array($checkout) || empty($checkout['tickets'])) {
    fail('Your ticket selection has expired. Select your tickets again.');
}

/*
 * IMPORTANT INTEGRATION STEP:
 * Before live payments, load the event, ticket prices and availability
 * from your existing database here, and reserve/decrement inventory safely.
 *
 * Create or reuse a PENDING order in your existing order system and save
 * the buyer details and the authoritative total. Store that order ID in
 * $orderId below. Never trust an amount supplied by the browser.
 *
 * This template uses session ticket data only as a development adapter.
 * It does not create a database order. Do not enable live payments until
 * the existing database/order adapter has been connected.
 */

$subtotal = 0;
$lineItems = [];

foreach ($checkout['tickets'] as $ticket) {
    $quantity = (int) ($ticket['quantity'] ?? 0);
    $unitPrice = (int) ($ticket['unit_price'] ?? -1);

    if ($quantity < 1 || $quantity > 10 || $unitPrice < 0) {
        fail('One or more selected ticket quantities or prices are invalid.');
    }

    if ($quantity > intdiv(PHP_INT_MAX, max(1, $unitPrice))) {
        fail('The selected order total is invalid.');
    }

    $lineTotal = $quantity * $unitPrice;
    $subtotal += $lineTotal;

    $lineItems[] = [
        'name' => (string) ($ticket['name'] ?? 'Ticket'),
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'line_total' => $lineTotal,
    ];
}

$serviceFee = max(0, (int) ($checkout['service_fee'] ?? 0));
$total = $subtotal + $serviceFee;

if ($total <= 0) {
    fail('Your order total must be greater than zero.');
}

// Store buyer details in the server session, not in a URL.
$_SESSION['checkout_buyer'] = [
    'full_name' => $fullName,
    'email' => $email,
    'phone' => $phoneDigits,
    'phone_country' => $countryCode,
];

$config = require __DIR__ . '/../config/paystack.php';
$secretKey = (string) ($config['secret_key'] ?? '');
$callbackUrl = (string) ($config['callback_url'] ?? '');

if ($secretKey === '' || $callbackUrl === '') {
    fail('Payment configuration is incomplete. Please try again later.', 503);
}

if (!filter_var($callbackUrl, FILTER_VALIDATE_URL) ||
    parse_url($callbackUrl, PHP_URL_SCHEME) !== 'https') {
    fail('The payment callback URL must be a valid HTTPS URL.', 503);
}

/*
 * STOP HERE until connected to the actual order database.
 *
 * The next integration must:
 * 1. Insert/update a pending order using your existing database connection.
 * 2. Store the order ID and server-calculated total.
 * 3. Use the same order ID as a unique payment reference.
 *
 * Do not simply remove this guard for production.
 */
$orderId = $_SESSION['pending_order_id'] ?? null;

if (!$orderId) {
    fail(
        'The checkout design is ready, but the order database connection is not configured yet. ' .
        'Connect the existing order-creation logic before enabling payment.',
        503
    );
}

$reference = 'TKT-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $orderId);

$payload = [
    'email' => $email,
    'amount' => (string) $total, // Confirm XOF amount units with Paystack.
    'currency' => $config['currency'] ?? 'XOF',
    'reference' => $reference,
    'callback_url' => $callbackUrl,
    'metadata' => [
        'order_id' => (string) $orderId,
        'event_title' => (string) ($checkout['event_title'] ?? ''),
        'buyer_name' => $fullName,
        'buyer_phone' => $countryCode . $phoneDigits,
        'custom_fields' => [
            [
                'display_name' => 'Order reference',
                'variable_name' => 'order_reference',
                'value' => $reference,
            ],
        ],
    ],
];

$curl = curl_init('https://api.paystack.co/transaction/initialize');

curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
]);

$response = curl_exec($curl);
$curlError = curl_error($curl);
$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($response === false || $curlError !== '') {
    error_log('Paystack initialization network error: ' . $curlError);
    fail('We could not reach the payment service. Please try again.', 502);
}

$result = json_decode($response, true);

if (
    $statusCode < 200 ||
    $statusCode >= 300 ||
    !is_array($result) ||
    empty($result['status']) ||
    empty($result['data']['authorization_url'])
) {
    error_log('Paystack initialization failed; HTTP status ' . $statusCode);
    fail('Paystack could not initialize your payment. Please try again.', 502);
}

/*
 * Before redirecting, save the payment reference and pending status
 * against the existing order in your database.
 */
$_SESSION['last_payment_reference'] = $reference;

header('Location: ' . $result['data']['authorization_url'], true, 303);
exit;
