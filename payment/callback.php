
<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/../config/paystack.php';
$secretKey = (string) ($config['secret_key'] ?? '');
$reference = trim((string) ($_GET['reference'] ?? ''));

function paymentPage(string $title, string $message): never {
    http_response_code(200);
    echo '<!doctype html><html lang="en"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | TICKETA</title>';
    echo '<body style="font-family:Arial,sans-serif;max-width:600px;margin:60px auto;padding:20px">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<a href="../events.php">Browse events</a>';
    echo '</body></html>';
    exit;
}

if ($secretKey === '' || $reference === '' || strlen($reference) > 100) {
    paymentPage('Payment pending', 'We could not confirm this payment. Contact support with your order reference.');
}

$url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);
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
$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($response === false || $curlError !== '' || $statusCode < 200 || $statusCode >= 300) {
    error_log('Paystack verification request failed for reference: ' . $reference);
    paymentPage('Payment verification pending', 'Your payment status is not confirmed yet. Please do not pay again immediately.');
}

$result = json_decode($response, true);
$transaction = $result['data'] ?? [];

if (
    !is_array($result) ||
    empty($result['status']) ||
    ($transaction['status'] ?? '') !== 'success'
) {
    paymentPage('Payment not completed', 'Paystack has not confirmed a successful payment. If you were charged, contact support.');
}

/*
 * CRITICAL:
 * Before marking an order as paid, look up the order using the saved
 * transaction reference in your existing database. Compare:
 * - reference
 * - expected order total (in Paystack amount units)
 * - currency (XOF)
 * - order/payment status
 *
 * Then update the existing order/payment record in a database transaction.
 * Make the operation idempotent so refreshes cannot issue duplicate tickets.
 * Issue tickets only after verification and the database update succeed.
 */

if (($transaction['currency'] ?? '') !== 'XOF') {
    error_log('Unexpected currency for Paystack reference: ' . $reference);
    paymentPage('Payment under review', 'We could not match the payment currency to your order. Contact support.');
}

paymentPage(
    'Payment received for verification',
    'Paystack reports a successful transaction. Ticket confirmation will appear after Ticketa matches it to your order.'
);
