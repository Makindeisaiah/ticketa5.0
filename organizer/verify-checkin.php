<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'message' => 'POST requests only.']);
}

if (!authIsLoggedIn()) {
    respond(401, ['success' => false, 'message' => 'Please log in again.']);
}

if (($_SESSION['user']['role'] ?? '') !== 'organizer') {
    respond(403, ['success' => false, 'message' => 'Organizer access required.']);
}

if (!isset($_SESSION['checkin_csrf'])) {
    respond(403, ['success' => false, 'message' => 'Security token missing. Refresh the page.']);
}

$submittedCsrf = $_POST['csrf_token'] ?? '';
if (!is_string($submittedCsrf) || !hash_equals((string) $_SESSION['checkin_csrf'], $submittedCsrf)) {
    respond(403, ['success' => false, 'message' => 'Security check failed. Refresh the page.']);
}

$token = trim((string) ($_POST['token'] ?? ''));
$method = $_POST['method'] ?? 'qr';
$eventId = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);

if (!in_array($method, ['qr', 'manual'], true)) {
    respond(400, ['success' => false, 'message' => 'Invalid check-in method.']);
}
if ($token === '' || strlen($token) > 100) {
    respond(400, ['success' => false, 'message' => 'Enter a valid ticket code or scan a QR code.']);
}
if ($method === 'qr' && !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    respond(400, ['success' => false, 'message' => 'Invalid QR code.']);
}
if ($eventId === false || $eventId < 1) {
    $eventId = null;
}

try {
    $profileStmt = $pdo->prepare('SELECT id FROM organizers WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => $_SESSION['user']['id']]);
    $organizerId = $profileStmt->fetchColumn();

    if (!$organizerId) {
        respond(403, ['success' => false, 'message' => 'Organizer profile not found.']);
    }

    $pdo->beginTransaction();
    $ticketColumn = $method === 'qr' ? 'it.qr_token' : 'it.ticket_code';

    $sql = "
        SELECT
            it.id AS ticket_id,
            it.ticket_code,
            it.status AS ticket_status,
            it.attendee_name,
            it.qr_token,
            it.checked_in_at,
            oi.ticket_name,
            o.status AS order_status,
            e.id AS event_id,
            e.title AS event_title
        FROM issued_tickets it
        INNER JOIN order_items oi ON oi.id = it.order_item_id
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN events e ON e.id = o.event_id
        INNER JOIN organizers org ON org.id = e.organizer_id
        WHERE {$ticketColumn} = :token
          AND org.id = :organizer_id
        LIMIT 1
        FOR UPDATE
    ";

    $ticketStmt = $pdo->prepare($sql);
    $ticketStmt->execute(['token' => $token, 'organizer_id' => $organizerId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        $pdo->rollBack();
        respond(404, ['success' => false, 'message' => 'Ticket not found for your events.']);
    }

    if ($eventId !== null && (int) $ticket['event_id'] !== $eventId) {
        $pdo->rollBack();
        respond(403, ['success' => false, 'message' => 'This ticket belongs to another event.']);
    }

    // Paid tickets require a successful payment; free tickets are valid without payment.
    if (!in_array(strtolower((string) $ticket['order_status']), ['paid', 'free'], true)) {
        $pdo->rollBack();
        respond(400, ['success' => false, 'message' => 'This ticket has not been paid for or its order is not confirmed.']);
    }

    // Duplicate scans return full ticket context but never change the existing check-in.
    if ($ticket['ticket_status'] === 'used') {
        $pdo->rollBack();
        respond(409, [
            'success' => false,
            'message' => 'Ticket Already Checked In. This ticket cannot be checked in again.',
            'ticket' => [
                'attendee' => $ticket['attendee_name'],
                'event' => $ticket['event_title'],
                'ticket_type' => $ticket['ticket_name'],
                'ticket_code' => $ticket['ticket_code'],
                'checked_in_at' => $ticket['checked_in_at']
            ]
        ]);
    }

    if ($ticket['ticket_status'] !== 'valid') {
        $pdo->rollBack();
        respond(400, ['success' => false, 'message' => 'This ticket is not valid.']);
    }

    $updateStmt = $pdo->prepare("
        UPDATE issued_tickets
        SET status = 'used',
            checked_in_at = NOW(),
            checkin_method = :method
        WHERE id = :ticket_id
          AND status = 'valid'
    ");
    $updateStmt->execute(['method' => $method, 'ticket_id' => $ticket['ticket_id']]);

    if ($updateStmt->rowCount() !== 1) {
        $pdo->rollBack();
        respond(409, ['success' => false, 'message' => 'Ticket status changed. Please scan again.']);
    }

    $pdo->commit();
    respond(200, [
        'success' => true,
        'message' => 'Ticket verified. Check-in successful!',
        'ticket' => [
            'attendee' => $ticket['attendee_name'],
            'ticket_code' => $ticket['ticket_code'],
            'ticket_type' => $ticket['ticket_name'],
            'event' => $ticket['event_title']
        ]
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ticketa check-in error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
