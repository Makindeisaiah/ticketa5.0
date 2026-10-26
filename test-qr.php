<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/qr-helper.php';

$stmt = $pdo->query(
    "SELECT qr_token
     FROM issued_tickets
     WHERE qr_token IS NOT NULL
     LIMIT 1"
);

$token = $stmt->fetchColumn();

if (!$token) {
    http_response_code(404);
    exit('No ticket QR token found.');
}

try {
    $png = generateTicketQrPng((string) $token);

    header('Content-Type: image/png');
    header('Cache-Control: no-store');

    echo $png;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo "QR generation failed.\n\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
