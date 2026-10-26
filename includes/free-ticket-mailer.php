<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/qr-helper.php';

/** Send a free-registration confirmation with an embedded QR code per ticket. */
function sendFreeTicketConfirmationEmail(
    string $recipientEmail,
    string $recipientName,
    int $orderId,
    string $eventTitle,
    string $eventDate,
    string $venue,
    string $city,
    array $tickets
): void {
    $config = require dirname(__DIR__) . '/config/mail.php';

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The ticket recipient email is invalid.');
    }
    if ($orderId < 1 || $tickets === []) {
        throw new InvalidArgumentException('The order ID or ticket list is invalid.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['username'];
    $mail->Password = (string) $config['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $config['port'];
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 20;
    $mail->setFrom((string) $config['from_email'], (string) $config['from_name']);
    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = 'Your free ticket confirmation — TICKETA';

    $safe = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $ticketRows = '';
    $plainTickets = '';

    foreach ($tickets as $index => $ticket) {
        $ticketName = (string) ($ticket['ticket_name'] ?? 'Event ticket');
        $ticketCode = (string) ($ticket['ticket_code'] ?? '');
        $attendee = (string) ($ticket['attendee_name'] ?? $recipientName);
        $qrToken = strtolower(trim((string) ($ticket['qr_token'] ?? '')));
        if ($ticketCode === '' || !preg_match('/^[a-f0-9]{64}$/', $qrToken)) {
            throw new InvalidArgumentException('A ticket is missing its code or valid QR token.');
        }

        $cid = 'ticketa_free_qr_' . $index . '_' . bin2hex(random_bytes(5));
        $mail->addStringEmbeddedImage(generateTicketQrPng($qrToken), $cid, 'ticket-qr-' . ($index + 1) . '.png', 'base64', 'image/png');
        $ticketRows .= '<tr><td style="padding:12px;border-bottom:1px solid #eee">' . $safe($ticketName) . '</td>'
            . '<td style="padding:12px;border-bottom:1px solid #eee">' . $safe($attendee) . '</td>'
            . '<td style="padding:12px;border-bottom:1px solid #eee;word-break:break-word;font-weight:bold">' . $safe($ticketCode) . '</td></tr>'
            . '<tr><td colspan="3" style="padding:20px;text-align:center;border-bottom:1px solid #eee">'
            . '<p style="font-weight:bold">Admission QR code</p><img src="cid:' . $cid . '" width="220" height="220" alt="Admission QR code" style="display:block;width:220px;height:220px;max-width:100%;margin:auto">'
            . '<p style="font-size:12px;color:#667085">Present this QR code at the event entrance.</p></td></tr>';
        $plainTickets .= "Ticket: {$ticketName}\nAttendee: {$attendee}\nTicket code: {$ticketCode}\nQR token: {$qrToken}\n\n";
    }

    $mail->Body = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;padding:24px;color:#172033">'
        . '<h1 style="color:#10c6a4">TICKETA</h1><h2>Your free registration is confirmed!</h2>'
        . '<p>Hi ' . $safe($recipientName) . ',</p><p>Your registration is complete. No payment was required.</p>'
        . '<div style="padding:18px;background:#f7f8fc;border-radius:12px"><h3>' . $safe($eventTitle) . '</h3>'
        . '<p><strong>Date:</strong> ' . $safe($eventDate) . '</p><p><strong>Venue:</strong> ' . $safe($venue) . '</p>'
        . '<p><strong>City:</strong> ' . $safe($city) . '</p><p><strong>Order reference:</strong> ' . $safe((string) $orderId) . '</p></div>'
        . '<h3>Your tickets and admission QR codes</h3><p>Present the QR code for each ticket at the entrance. Keep your QR codes private.</p>'
        . '<table style="width:100%;border-collapse:collapse;text-align:left;font-size:13px"><thead><tr>'
        . '<th style="padding:12px;background:#f0f2f6">Ticket type</th><th style="padding:12px;background:#f0f2f6">Attendee</th>'
        . '<th style="padding:12px;background:#f0f2f6">Ticket code</th></tr></thead><tbody>' . $ticketRows . '</tbody></table>'
        . '<p>We look forward to seeing you!</p><p><strong>The TICKETA Team</strong></p></div>';
    $mail->AltBody = "Hi {$recipientName},\n\nYour free registration is confirmed.\nEvent: {$eventTitle}\nDate: {$eventDate}\nVenue: {$venue}, {$city}\nOrder: {$orderId}\n\n{$plainTickets}";
    $mail->send();
}
