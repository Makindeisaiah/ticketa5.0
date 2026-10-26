<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Send account verification email.
 * Existing functionality preserved.
 */
function sendVerificationEmail(
    string $recipientEmail,
    string $recipientName,
    string $verificationUrl
): void {
    $config = require dirname(__DIR__) . '/config/mail.php';

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $config['port'];
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($recipientEmail, $recipientName);

    $safeName = htmlspecialchars(
        $recipientName,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $safeUrl = htmlspecialchars(
        $verificationUrl,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $mail->isHTML(true);
    $mail->Subject = 'Verify your TICKETA email address';

    $mail->Body = "
        <h2>Welcome to TICKETA, {$safeName}!</h2>
        <p>Please verify your email address to activate your account.</p>
        <p>
            <a href=\"{$safeUrl}\"
               style=\"display:inline-block;padding:12px 20px;
                      background:#10c6a4;color:#102a26;
                      text-decoration:none;border-radius:8px;\">
                Verify my email
            </a>
        </p>
        <p>This link expires in 30 minutes and can only be used once.</p>
        <p>If you did not create this account, you can ignore this email.</p>
    ";

    $mail->AltBody =
        "Welcome to TICKETA. Verify your email using this link: "
        . $verificationUrl
        . "\nThis link expires in 30 minutes and can only be used once.";

    $mail->send();
}


/**
 * Send an organizer team invitation email.
 */
function sendTeamInvitationEmail(
    string $recipientEmail,
    string $organizerName,
    string $teamRole,
    string $invitationUrl
): void {
    $config = require dirname(__DIR__) . '/config/mail.php';

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'The invitation recipient email is invalid.'
        );
    }

    if (!filter_var($invitationUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException(
            'The invitation URL is invalid.'
        );
    }

    $allowedRoles = [
        'admin',
        'organizer',
        'finance',
        'checkin_staff',
        'marketing',
        'support',
    ];

    if (!in_array($teamRole, $allowedRoles, true)) {
        throw new InvalidArgumentException(
            'The selected team role is invalid.'
        );
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;

        $mail->setFrom(
            $config['from_email'],
            $config['from_name']
        );

        $mail->addAddress($recipientEmail);

        $safeOrganizer = htmlspecialchars(
            $organizerName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $safeRole = htmlspecialchars(
            ucwords(str_replace('_', ' ', $teamRole)),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $safeUrl = htmlspecialchars(
            $invitationUrl,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $mail->isHTML(true);
        $mail->Subject = 'You are invited to join a TICKETA team';

        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;
                        max-width:560px;margin:auto;padding:24px;
                        color:#172033;\">
                <h1 style=\"color:#10c6a4;\">TICKETA</h1>

                <h2>You have been invited!</h2>

                <p>
                    <strong>{$safeOrganizer}</strong>
                    has invited you to join their team on TICKETA.
                </p>

                <p>
                    Your assigned role:
                    <strong>{$safeRole}</strong>
                </p>

                <p>Accept the invitation using the button below.</p>

                <p style=\"margin:28px 0;\">
                    <a href=\"{$safeUrl}\"
                       style=\"display:inline-block;padding:14px 24px;
                              background:#10c6a4;color:#102a26;
                              text-decoration:none;border-radius:8px;
                              font-weight:bold;\">
                        Accept Invitation
                    </a>
                </p>

                <p style=\"font-size:13px;color:#667085;\">
                    This invitation expires after seven days.
                    If you were not expecting this email, ignore it.
                </p>

                <p style=\"font-size:12px;color:#98a2b3;\">
                    TICKETA Team
                </p>
            </div>
        ";

        $mail->AltBody =
            "{$organizerName} invited you to join their TICKETA team.\n\n"
            . "Role: " . ucwords(str_replace('_', ' ', $teamRole)) . "\n\n"
            . "Accept the invitation here:\n"
            . $invitationUrl
            . "\n\nThis invitation expires after seven days.";

        $mail->send();

    } catch (\Throwable $e) {
        error_log(
            'Ticketa team invitation email failed: '
            . $e->getMessage()
        );

        throw new RuntimeException(
            'The team invitation email could not be sent. '
            . 'Check the SMTP configuration and PHP error log.',
            0,
            $e
        );
    }
}



/**
 * Send a ticket purchase confirmation email with an embedded QR code per ticket.
 * Existing account-verification and team-invitation functions remain unchanged.
 */
function sendTicketConfirmationEmail(
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
    require_once __DIR__ . '/qr-helper.php';

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The ticket recipient email is invalid.');
    }
    if ($orderId < 1 || empty($tickets)) {
        throw new InvalidArgumentException('The order ID or ticket list is invalid.');
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = 'Your ticket confirmation — TICKETA';

        $safe = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $ticketRows = '';
        $plainTickets = '';
        foreach ($tickets as $index => $ticket) {
            $ticketNameRaw = (string) ($ticket['ticket_name'] ?? 'Event ticket');
            $ticketCodeRaw = (string) ($ticket['ticket_code'] ?? '');
            $attendeeRaw = (string) ($ticket['attendee_name'] ?? $recipientName);
            $qrToken = strtolower(trim((string) ($ticket['qr_token'] ?? '')));

            if ($ticketCodeRaw === '') {
                throw new InvalidArgumentException('A ticket is missing its unique ticket code.');
            }
            if (!preg_match('/^[a-f0-9]{64}$/', $qrToken)) {
                throw new InvalidArgumentException('A ticket is missing a valid QR token.');
            }

            $qrPng = generateTicketQrPng($qrToken);
            $cid = 'ticketa_qr_' . $index . '_' . bin2hex(random_bytes(6));
            $mail->addStringEmbeddedImage(
                $qrPng,
                $cid,
                'ticket-qr-' . ($index + 1) . '.png',
                'base64',
                'image/png'
            );

            $ticketRows .= '
                <tr>
                    <td style="padding:12px;border-bottom:1px solid #eee;">' . $safe($ticketNameRaw) . '</td>
                    <td style="padding:12px;border-bottom:1px solid #eee;">' . $safe($attendeeRaw) . '</td>
                    <td style="padding:12px;border-bottom:1px solid #eee;font-weight:bold;word-break:break-word;">' . $safe($ticketCodeRaw) . '</td>
                </tr>
                <tr>
                    <td colspan="3" style="padding:20px;text-align:center;border-bottom:1px solid #eee;">
                        <p style="font-weight:bold;margin:0 0 10px;">Your admission QR code</p>
                        <img src="cid:' . $cid . '" width="220" height="220" alt="Admission QR code" style="display:block;width:220px;height:220px;max-width:100%;margin:auto;">
                        <p style="font-size:12px;color:#667085;">Present this QR code at the event entrance.</p>
                    </td>
                </tr>';

            $plainTickets .= "Ticket: {$ticketNameRaw}\nAttendee: {$attendeeRaw}\nTicket code: {$ticketCodeRaw}\n\n";
        }

        $safeName = $safe($recipientName);
        $safeEvent = $safe($eventTitle);
        $safeDate = $safe($eventDate);
        $safeVenue = $safe($venue);
        $safeCity = $safe($city);

        $mail->Body = '
            <div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;padding:24px;color:#172033;">
                <h1 style="color:#10c6a4;">TICKETA</h1>
                <h2>Your ticket purchase is confirmed!</h2>
                <p>Hi ' . $safeName . ',</p>
                <p>Your payment has been verified successfully. Thank you for booking with TICKETA.</p>
                <div style="padding:18px;background:#f7f8fc;border-radius:12px;">
                    <h3>' . $safeEvent . '</h3>
                    <p><strong>Date:</strong> ' . $safeDate . '</p>
                    <p><strong>Venue:</strong> ' . $safeVenue . '</p>
                    <p><strong>City:</strong> ' . $safeCity . '</p>
                    <p><strong>Order number:</strong> #' . $orderId . '</p>
                </div>
                <h3>Your tickets and admission QR codes</h3>
                <p>Present the QR code for each ticket at the event entrance. Keep your QR codes private.</p>
                <table style="width:100%;border-collapse:collapse;text-align:left;font-size:13px;">
                    <thead><tr>
                        <th style="padding:12px;background:#f0f2f6;">Type</th>
                        <th style="padding:12px;background:#f0f2f6;">Attendee</th>
                        <th style="padding:12px;background:#f0f2f6;">Ticket code</th>
                    </tr></thead>
                    <tbody>' . $ticketRows . '</tbody>
                </table>
                <p style="margin-top:24px;">Keep this email safe. We look forward to seeing you!</p>
                <p><strong>The TICKETA Team</strong></p>
            </div>';

        $mail->AltBody =
            "Hi {$recipientName},\n\nYour TICKETA payment has been verified.\n\n" .
            "Event: {$eventTitle}\nDate: {$eventDate}\nVenue: {$venue}, {$city}\n" .
            "Order number: #{$orderId}\n\nYOUR TICKETS\n\n" . $plainTickets .
            "The QR images are included in the HTML version of this email.";

        $mail->send();
    } catch (Throwable $e) {
        error_log('Ticketa ticket confirmation email failed for order #' . $orderId . ': ' . $e->getMessage());
        throw new RuntimeException('The ticket confirmation email could not be sent.', 0, $e);
    }
}

