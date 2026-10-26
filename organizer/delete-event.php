<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!authIsLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'] ?? [];
if (($user['role'] ?? '') !== 'organizer') {
    header('Location: ../index.php');
    exit;
}

function redirectDeleteError(string $message): void
{
    header('Location: events.php?delete_error=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken) || !authVerifyCsrf($csrfToken)) {
    redirectDeleteError('Your security token has expired. Refresh the page and try again.');
}

$confirmation = $_POST['confirmation'] ?? '';
if (!is_string($confirmation) || $confirmation !== 'DELETE') {
    redirectDeleteError('Confirmation failed. Type DELETE exactly.');
}

$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($eventId === false || $eventId === null) {
    redirectDeleteError('Invalid event selected.');
}

try {
    $pdo->beginTransaction();

    $profileStmt = $pdo->prepare('SELECT id FROM organizers WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => (int) ($user['id'] ?? 0)]);
    $organizerId = $profileStmt->fetchColumn();

    if (!$organizerId) {
        $pdo->rollBack();
        redirectDeleteError('Organizer profile not found.');
    }

    $eventStmt = $pdo->prepare(
        'SELECT id, title FROM events WHERE id = :event_id AND organizer_id = :organizer_id FOR UPDATE'
    );
    $eventStmt->execute(['event_id' => (int) $eventId, 'organizer_id' => (int) $organizerId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        $pdo->rollBack();
        redirectDeleteError('Event not found, or you do not have permission to delete it.');
    }

    // Keep every order record, including pending/failed orders, intact.
    $ordersStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE event_id = :event_id');
    $ordersStmt->execute(['event_id' => (int) $eventId]);
    if ((int) $ordersStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        redirectDeleteError('This event has order history and cannot be permanently deleted. Cancel or archive it instead to protect attendee and payment records.');
    }

    // With no orders, ticket types may be deleted before the event.
    $ticketTypesStmt = $pdo->prepare('DELETE FROM ticket_types WHERE event_id = :event_id');
    $ticketTypesStmt->execute(['event_id' => (int) $eventId]);

    $deleteStmt = $pdo->prepare('DELETE FROM events WHERE id = :event_id AND organizer_id = :organizer_id');
    $deleteStmt->execute(['event_id' => (int) $eventId, 'organizer_id' => (int) $organizerId]);

    if ($deleteStmt->rowCount() !== 1) {
        throw new RuntimeException('The event could not be deleted.');
    }

    $pdo->commit();
    header('Location: events.php?deleted=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ticketa event deletion error: ' . $e->getMessage());
    redirectDeleteError('The event could not be deleted. It may have related records that must be handled first.');
}
