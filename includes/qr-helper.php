<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Generate a QR-code PNG for a ticket token.
 * Returns the image as binary PNG data.
 */
function generateTicketQrPng(string $token): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new InvalidArgumentException('Invalid ticket QR token.');
    }

    $builder = new \Endroid\QrCode\Builder\Builder(
        writer: new \Endroid\QrCode\Writer\PngWriter(),
        data: $token,
        size: 320,
        margin: 12
    );

    $result = $builder->build();

    return $result->getString();
}
