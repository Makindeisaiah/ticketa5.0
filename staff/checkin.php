<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__) . '/config/database.php';

if (empty($_SESSION['staff_user']['id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$staffId = (int) $_SESSION['staff_user']['id'];
$errors = [];
$assignments = [];
$selectedAssignment = null;
$stats = ['total' => 0, 'valid' => 0, 'pending' => 0, 'invalid' => 0];

function staffEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function staffInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : 'S';
}
function staffRoleLabel(string $role): string {
    $labels = ['checkin_staff' => 'Check-in Staff', 'supervisor' => 'Supervisor', 'admin' => 'Administrator'];
    return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
}
function staffDate($value, string $format = 'D, d M Y · H:i'): string {
    if (empty($value)) return 'Not specified';
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? 'Not specified' : date($format, $timestamp);
}
function staffEventImageUrl($imagePath): string {
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') return '';
    if (preg_match('~^https?://~i', $imagePath)) {
        return filter_var($imagePath, FILTER_VALIDATE_URL) ? $imagePath : '';
    }
    if (str_starts_with($imagePath, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $imagePath) || str_contains($imagePath, "\0")) return '';
    $imagePath = str_replace('\\', '/', $imagePath);
    foreach (explode('/', $imagePath) as $segment) if ($segment === '..') return '';
    $imagePath = ltrim($imagePath, '/');
    $projectFolder = basename(dirname(__DIR__));
    return '/' . (str_starts_with($imagePath, $projectFolder . '/') ? $imagePath : $projectFolder . '/' . $imagePath);
}

if (empty($_SESSION['staff_dashboard_csrf'])) $_SESSION['staff_dashboard_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['staff_dashboard_csrf'];
$staffName = (string) ($_SESSION['staff_user']['full_name'] ?? 'Staff Member');
$staffRole = (string) ($_SESSION['staff_user']['role'] ?? 'checkin_staff');
$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);

try {
    $staffStmt = $pdo->prepare('SELECT id, full_name, email, role, status FROM staff_users WHERE id = :id LIMIT 1');
    $staffStmt->execute(['id' => $staffId]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff || $staff['status'] !== 'active') {
        unset($_SESSION['staff_user']);
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }
    $staffName = (string) $staff['full_name'];
    $staffRole = (string) $staff['role'];
    $_SESSION['staff_user']['full_name'] = $staffName;
    $_SESSION['staff_user']['email'] = (string) $staff['email'];
    $_SESSION['staff_user']['role'] = $staffRole;

    $assignmentStmt = $pdo->prepare(
        "SELECT a.id AS assignment_id, a.event_id, a.assigned_gate, a.assignment_role,
                a.shift_start, a.shift_end, a.status AS assignment_status,
                e.title AS event_title, e.event_date, e.event_end_date, e.venue,
                e.address, e.city, e.image_url
         FROM staff_event_assignments a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.staff_user_id = :staff_id
           AND a.status IN ('scheduled', 'active', 'completed')
         ORDER BY CASE WHEN a.status = 'active' THEN 0 WHEN a.status = 'scheduled' THEN 1 ELSE 2 END,
                  a.shift_start ASC"
    );
    $assignmentStmt->execute(['staff_id' => $staffId]);
    $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as $item) {
        if ($assignmentId && (int) $item['assignment_id'] === (int) $assignmentId) {
            $selectedAssignment = $item;
            break;
        }
    }
    if (!$selectedAssignment && $assignments) $selectedAssignment = $assignments[0];

    if ($selectedAssignment) {
        $eventId = (int) $selectedAssignment['event_id'];
        $statsStmt = $pdo->prepare(
            "SELECT COUNT(it.id) AS total_tickets,
                    COALESCE(SUM(it.status = 'used'), 0) AS used_tickets,
                    COALESCE(SUM(it.status = 'valid'), 0) AS valid_tickets,
                    COALESCE(SUM(it.status IN ('cancelled','refunded')), 0) AS invalid_tickets
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE tt.event_id = :event_id"
        );
        $statsStmt->execute(['event_id' => $eventId]);
        $row = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = [
            'total' => (int) ($row['total_tickets'] ?? 0),
            'valid' => (int) ($row['used_tickets'] ?? 0),
            'pending' => (int) ($row['valid_tickets'] ?? 0),
            'invalid' => (int) ($row['invalid_tickets'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    error_log('Ticketa staff check-in load error: ' . $e->getMessage());
    $errors[] = 'We could not load check-in information. Please check the PHP error log.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_ticket') {
    header('Content-Type: application/json; charset=utf-8');
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
        exit;
    }
    if (!$selectedAssignment) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No event assignment is available for this account.']);
        exit;
    }
    $rawToken = trim((string) ($_POST['token'] ?? ''));
    if ($rawToken === '' || strlen($rawToken) > 255) {
        echo json_encode(['success' => false, 'message' => 'Scan a QR code or enter a ticket code.']);
        exit;
    }
    // Accept a plain code, a 64-character QR token (alone, in a URL or in JSON), a ticket URL, or a JSON payload
    if (preg_match('/[a-f0-9]{64}/i', $rawToken, $hex)) {
        $rawToken = strtolower($hex[0]);
    } elseif (preg_match('~^https?://~i', $rawToken)) {
        $u = parse_url($rawToken);
        parse_str($u['query'] ?? '', $q);
        $found = '';
        foreach (['ticket_code', 'code', 'ticket', 'token', 'id'] as $k) {
            if (!empty($q[$k])) { $found = trim((string) $q[$k]); break; }
        }
        $rawToken = $found !== '' ? $found : basename(trim((string) ($u['path'] ?? ''), '/'));
    } elseif ($rawToken !== '' && $rawToken[0] === '{') {
        $j = json_decode($rawToken, true);
        if (is_array($j)) {
            foreach (['ticket_code', 'code', 'ticket', 'token', 'id'] as $k) {
                if (!empty($j[$k])) { $rawToken = trim((string) $j[$k]); break; }
            }
        }
    }

    try {
        $pdo->beginTransaction();
        $ticketStmt = $pdo->prepare(
            "SELECT it.id, it.ticket_code, it.attendee_name, it.status AS ticket_status,
                    it.checked_in_at, tt.name AS ticket_name, tt.event_id, e.title AS event_title
             FROM issued_tickets it
             LEFT JOIN order_items oi ON oi.id = it.order_item_id
             LEFT JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             LEFT JOIN events e ON e.id = tt.event_id
             WHERE it.qr_token = :qr_token OR it.qr_token = :qr_hash
                OR it.ticket_code = :ticket_code OR CAST(it.id AS CHAR) = :ticket_id
             LIMIT 1 FOR UPDATE"
        );
        $ticketStmt->execute([
            'qr_token' => $rawToken,
            'qr_hash' => hash('sha256', $rawToken),
            'ticket_code' => $rawToken,
            'ticket_id' => $rawToken,
        ]);
        $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'No ticket with this code exists in the system. Scanned value: ' . mb_substr($rawToken, 0, 80)]);
            exit;
        }
        if (empty($ticket['event_id'])) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Ticket found, but it is not linked to an event (check order_items and ticket_types).']);
            exit;
        }
        if ((int) $ticket['event_id'] !== (int) $selectedAssignment['event_id']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'This ticket is for "' . $ticket['event_title'] . '", not your assigned event.']);
            exit;
        }
        $ticketInfo = [
            'attendee' => $ticket['attendee_name'] ?: 'Guest',
            'event' => $ticket['event_title'],
            'ticket_type' => $ticket['ticket_name'],
            'ticket_code' => $ticket['ticket_code'],
        ];
        if ($ticket['ticket_status'] === 'used') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'This ticket has already been checked in.', 'ticket' => $ticketInfo + [
                'checked_in_at' => $ticket['checked_in_at'] ? staffDate($ticket['checked_in_at']) : 'Previously checked in',
            ]]);
            exit;
        }
        if ($ticket['ticket_status'] !== 'valid') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'This ticket is not valid for check-in.', 'ticket' => $ticketInfo]);
            exit;
        }
        $updateStmt = $pdo->prepare("UPDATE issued_tickets SET status = 'used', checked_in_at = NOW(), checkin_method = 'staff' WHERE id = :id AND status = 'valid'");
        $updateStmt->execute(['id' => (int) $ticket['id']]);
        if ($updateStmt->rowCount() !== 1) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Ticket status changed while processing. Please scan again.']);
            exit;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Check-in successful. Welcome ' . ($ticket['attendee_name'] ?: 'Guest') . '!', 'ticket' => $ticketInfo + ['checked_in_at' => date('D, d M Y · H:i')]]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Ticketa staff ticket check-in error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'A server error occurred while checking in this ticket.']);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken !== '' && hash_equals($csrfToken, $postedToken)) {
        unset($_SESSION['staff_user'], $_SESSION['staff_dashboard_csrf']);
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }
    $errors[] = 'Your session expired. Refresh the page and try again.';
}

$eventTitle = (string) ($selectedAssignment['event_title'] ?? 'No event assigned');
$eventImage = staffEventImageUrl($selectedAssignment['image_url'] ?? '');
$eventVenue = trim((string) ($selectedAssignment['venue'] ?? '') . (!empty($selectedAssignment['city']) ? ', ' . (string) $selectedAssignment['city'] : ''));
$gateName = (string) ($selectedAssignment['assigned_gate'] ?? 'Not assigned');
$shiftStart = $selectedAssignment['shift_start'] ?? null;
$shiftEnd = $selectedAssignment['shift_end'] ?? null;
$recentCheckins = [];
if ($selectedAssignment) {
    try {
        $recentStmt = $pdo->prepare(
            "SELECT it.id, it.ticket_code, it.attendee_name, it.checked_in_at, tt.name AS ticket_name
             FROM issued_tickets it
             INNER JOIN order_items oi ON oi.id = it.order_item_id
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE tt.event_id = :event_id AND it.status = 'used' AND it.checked_in_at IS NOT NULL
             ORDER BY it.checked_in_at DESC LIMIT 6"
        );
        $recentStmt->execute(['event_id' => (int) $selectedAssignment['event_id']]);
        $recentCheckins = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Ticketa recent check-ins error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scan Ticket | TICKETA Staff</title>
<style>
:root{--teal:#10bea0;--teal-dark:#08a98d;--ink:#202124;--muted:#777b82;--border:#e6e8eb;--page:#f8f9fb;--purple:#8b5cf6;--sidebar-width:240px;--topbar-height:112px}
*{box-sizing:border-box}html{min-height:100%;scroll-padding-top:calc(var(--topbar-height) + 15px)}body{margin:0;min-height:100vh;background:var(--page);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px}button,input{font:inherit}a{color:inherit;text-decoration:none}button{cursor:pointer}[hidden]{display:none!important}
.sidebar{position:fixed;inset:0 auto 0 0;z-index:1000;display:flex;width:var(--sidebar-width);height:100vh;height:100dvh;flex-direction:column;overflow:hidden;padding:22px 16px 14px;border-right:1px solid var(--border);background:#fff}
.brand{display:flex;flex:0 0 auto;align-items:center;gap:9px;margin:0 0 32px;padding:0 3px;color:#111;font-size:23px;font-weight:600;letter-spacing:-1px}.brand-mark{display:inline-flex;width:37px;height:30px;align-items:center;justify-content:center;border:3px solid var(--teal);border-radius:5px;color:var(--teal);font-size:21px;font-weight:700;line-height:1}
.nav{display:grid;flex:0 0 auto;gap:8px}.nav-link{display:flex;min-width:0;min-height:43px;align-items:center;gap:12px;padding:9px 12px;border-radius:5px;color:#74777d;font-size:14px;transition:background .2s ease,color .2s ease}.nav-link:hover{background:#f1faf8;color:var(--teal-dark)}.nav-link.active{background:var(--teal);color:#fff;box-shadow:0 3px 8px #10bea022}.nav-icon{display:inline-flex;width:22px;flex:0 0 22px;align-items:center;justify-content:center;font-size:19px}.nav-text{min-width:0;overflow-wrap:anywhere}.nav-count{display:inline-flex;min-width:21px;height:21px;align-items:center;justify-content:center;margin-left:auto;border-radius:50%;background:#fee2e2;color:#e5484d;font-size:11px;font-weight:700}
.profile-card{flex:0 0 auto;margin-top:auto;padding:13px;border:1px solid #dedfe3;border-radius:11px;background:#fcfcfd}.profile-details{display:flex;min-width:0;align-items:center;gap:10px}.avatar{display:inline-flex;width:39px;height:39px;flex:0 0 39px;align-items:center;justify-content:center;overflow:hidden;border-radius:50%;background:#e1f7f1;color:#078f79;font-weight:700}.profile-copy{min-width:0}.profile-copy strong{display:block;overflow:hidden;font-size:13px;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.profile-copy small{display:block;margin-top:4px;color:var(--muted);font-size:12px}.online-status{display:flex;align-items:center;gap:7px;margin:10px 0 9px 48px;color:#62676d;font-size:12px}.online-dot{width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:var(--teal)}.logout-form{margin:0;padding-top:8px;border-top:1px solid #e5e6e8}.logout-button{display:flex;width:100%;align-items:center;gap:10px;padding:5px 2px;border:0;background:transparent;color:#70747a;text-align:left;font-size:12px}.logout-button:hover{color:#d33}
.main{min-width:0;min-height:100vh;margin-left:var(--sidebar-width)}.topbar{position:fixed;top:0;right:0;left:var(--sidebar-width);z-index:900;display:flex;min-height:var(--topbar-height);align-items:center;justify-content:space-between;gap:20px;padding:14px 25px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.97);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}.event-heading{display:flex;min-width:0;align-items:center;gap:15px}.event-poster{display:flex;width:64px;height:76px;flex:0 0 64px;align-items:center;justify-content:center;overflow:hidden;border-radius:6px;background:linear-gradient(145deg,#e3f7f4,#d9e6ff);color:#078f79;font-size:25px;font-weight:700}.event-poster img{display:block;width:100%;height:100%;object-fit:cover}.event-copy{min-width:0}.event-copy h1{margin:0 0 7px;overflow-wrap:anywhere;font-size:clamp(18px,1.7vw,25px);font-weight:600;letter-spacing:-.5px}.event-meta{display:flex;flex-wrap:wrap;align-items:center;gap:7px;color:var(--muted);font-size:13px;line-height:1.5}.event-meta .separator{color:var(--teal)}.assignment-meta{display:flex;flex:0 0 auto;align-items:center;border:1px solid #dedfe3;border-radius:15px;background:#fff}.assignment-meta-item{display:flex;min-width:145px;align-items:center;gap:10px;padding:12px 14px}.assignment-meta-item+.assignment-meta-item{border-left:1px solid #e3e4e7}.meta-icon{display:inline-flex;width:36px;height:36px;flex:0 0 36px;align-items:center;justify-content:center;border:1px solid #e4e5e8;border-radius:50%;font-size:17px}.meta-icon.purple{color:var(--purple)}.meta-icon.blue{color:#5b91f5}.assignment-meta small{display:block;margin-bottom:4px;color:var(--muted);font-size:11px}.assignment-meta strong{display:block;max-width:145px;overflow-wrap:anywhere;font-size:13px;font-weight:500}
.content{padding:calc(var(--topbar-height) + 22px) 25px 24px}.notice{margin-bottom:15px;padding:12px 15px;border-radius:8px;background:#fff0f0;color:#a32929;overflow-wrap:anywhere}.page-title-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:20px}.page-title-row h2{margin:0;font-size:26px;font-weight:600;letter-spacing:-.5px}.top-actions{display:flex;gap:10px}.outline-button{display:inline-flex;min-height:42px;align-items:center;justify-content:center;gap:9px;padding:0 15px;border:1px solid #66d7c4;border-radius:10px;background:#effaf8;color:#3cbda8;font-weight:500}.assignment-select{max-width:260px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--ink)}
.checkin-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start}.left-stack,.right-stack{display:grid;gap:20px;min-width:0}.event-card,.panel,.connection-bar,.quick-action{border:1px solid #f0f0f2;border-radius:13px;background:#fff;box-shadow:0 3px 7px #161b2d12,0 7px 14px #161b2d08}.event-card{display:flex;align-items:center;gap:18px;padding:15px}.event-card-poster{width:100px;height:122px;flex:0 0 100px;overflow:hidden;border-radius:7px;background:linear-gradient(145deg,#e3f7f4,#d9e6ff);display:grid;place-items:center;color:#078f79;font-size:28px;font-weight:700}.event-card-poster img{width:100%;height:100%;object-fit:cover}.event-card-copy{min-width:0;flex:1}.event-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.event-card h2{margin:0 0 12px;font-size:clamp(18px,1.7vw,27px);font-weight:600;letter-spacing:-.5px;overflow-wrap:anywhere}.status-pill{padding:6px 10px;border-radius:6px;background:#e4f8f4;color:#29ad98;font-size:12px;white-space:nowrap}.event-detail-row{display:flex;flex-wrap:wrap;gap:9px 16px;margin-top:8px;color:#777b82;font-size:14px}.event-detail{display:inline-flex;align-items:center;gap:8px}.detail-icon{font-size:18px;color:var(--teal)}.event-detail .separator{color:var(--teal)}
.panel{padding:18px}.scanner-box{position:relative;display:flex;min-height:460px;align-items:center;justify-content:center;overflow:hidden;padding:30px;border-radius:15px;background:#202020;color:#fff;text-align:center}.scanner-frame{position:absolute;inset:22% 18%;pointer-events:none}.corner{position:absolute;width:46px;height:46px;border-color:#28c7ad;border-style:solid}.corner.tl{top:0;left:0;border-width:2px 0 0 2px;border-radius:16px 0 0}.corner.tr{top:0;right:0;border-width:2px 2px 0 0;border-radius:0 16px 0 0}.corner.bl{bottom:0;left:0;border-width:0 0 2px 2px;border-radius:0 0 0 16px}.corner.br{right:0;bottom:0;border-width:0 2px 2px 0;border-radius:0 0 16px}.scanner-inner{position:relative;z-index:1;max-width:360px}.qr-icon{display:flex;width:72px;height:72px;align-items:center;justify-content:center;margin:0 auto 16px;border-radius:15px;background:#ffffff14;font-size:42px}.scanner-inner h3{margin:0 0 6px;font-size:17px;font-weight:500}.scanner-inner p{margin:0 0 18px;color:#d2d4d8;font-size:12px;line-height:1.6}.scanner-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.quick-action{display:flex;min-width:0;min-height:68px;align-items:center;justify-content:center;gap:9px;padding:12px 8px;text-align:center;font-size:13px;transition:transform .2s ease}.quick-action:hover{transform:translateY(-2px)}.quick-icon{font-size:21px;color:var(--teal)}.quick-action.purple .quick-icon{color:#8b5cf6}.quick-action.red .quick-icon{color:#ef5350}
.tips-panel{background:#e8f7f6}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:15px}.panel-heading h3{margin:0;font-size:17px;font-weight:500}.text-link{color:#45bda7;font-size:12px;white-space:nowrap}.tip-list{display:grid;gap:14px;margin:0;padding:0;list-style:none}.tip-list li{display:flex;align-items:center;gap:10px;font-size:13px;line-height:1.5}.tip-icon{display:inline-flex;width:42px;height:42px;flex:0 0 42px;align-items:center;justify-content:center;border-radius:50%;background:#fff;color:#37bea8;font-size:20px}.recent-list{display:grid}.recent-item{display:flex;min-width:0;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #eceef0}.recent-item:last-child{border-bottom:0}.recent-avatar{display:flex;width:38px;height:38px;flex:0 0 38px;align-items:center;justify-content:center;border-radius:50%;background:#e1f7f1;color:#078f79;font-size:12px;font-weight:700}.recent-copy{min-width:0;flex:1}.recent-copy strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:500}.recent-copy small{display:block;margin-top:5px;overflow:hidden;color:var(--muted);font-size:10px;text-overflow:ellipsis;white-space:nowrap}.recent-time{flex:0 0 auto;color:#888d94;font-size:10px;text-align:right}.recent-time span{display:block;margin-top:6px;color:var(--teal);font-size:14px}.recent-footer{display:flex;justify-content:center;align-items:center;gap:8px;margin-top:14px;padding-top:10px;color:#45bda7;font-size:12px}.connection-bar{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:16px}.connection-left{display:flex;align-items:center;gap:10px;color:#58a46f;font-size:12px}.connection-icon{display:grid;width:36px;height:36px;place-items:center;border-radius:50%;background:#b6e6c0;color:#299b52;font-size:18px}.connection-right{color:#888d94;font-size:11px}.connection-right span{color:var(--teal);margin-left:5px}.manual-form{display:flex;flex-wrap:wrap;gap:10px;margin-top:15px}.manual-form label{display:grid;flex:1;min-width:180px;gap:7px;color:#555;font-size:12px}.manual-form input{width:100%;min-height:43px;padding:10px 12px;border:1px solid #d8dce1;border-radius:7px}.manual-form input:focus{outline:2px solid #10bea033;border-color:var(--teal)}.primary-button{display:inline-flex;min-height:42px;align-items:center;justify-content:center;gap:8px;padding:0 17px;border:0;border-radius:8px;background:var(--teal);color:#fff;font-weight:600}.primary-button:hover{background:var(--teal-dark)}.result{margin-top:14px;padding:13px 14px;border-radius:8px;line-height:1.5;overflow-wrap:anywhere}.result.success{background:#e8f8ef;color:#176b3a}.result.error{background:#fff0f0;color:#a32929}.result strong{display:block;margin-bottom:5px}.result-details{margin-top:8px;font-size:12px}.result-details div{margin-top:3px}.empty-state{padding:30px 20px;border:1px solid var(--border);border-radius:13px;background:#fff;text-align:center}.empty-state h2{margin:0 0 10px}.empty-state p{color:var(--muted);line-height:1.6}
.qr-scanner-modal[hidden]{display:none!important}.qr-scanner-modal{position:fixed;inset:0;z-index:99999;display:grid;place-items:center;overflow:auto;padding:16px;background:#0a0f19c7}.qr-scanner-card{position:relative;width:min(100%,520px);padding:24px;border-radius:16px;background:#fff;box-shadow:0 20px 60px #0004}.qr-scanner-card h2{margin:0 40px 8px 0}.qr-scanner-card p{color:#666;line-height:1.5}.qr-close{position:absolute;top:14px;right:14px;width:36px;height:36px;border:0;border-radius:50%;background:#f0f2f4;font-size:18px}#qrReader{width:100%;margin:18px 0}#qrReader video{border-radius:10px}.qr-scanner-card .result{margin:12px 0}.qr-scanner-card .primary-button{width:100%;margin-top:10px}

/* Short laptop screens: same rule as dashboard.php so Sign Out stays visible */
@media(max-height:760px) and (min-width:1051px){.sidebar{padding-top:13px;padding-bottom:10px}.brand{margin-bottom:20px}.nav{gap:5px}.nav-link{min-height:37px;padding-top:7px;padding-bottom:7px}.profile-card{padding:10px}.online-status{margin-top:6px;margin-bottom:6px}}

@media(max-width:1350px) and (min-width:1051px){:root{--sidebar-width:220px;--topbar-height:105px}.sidebar{padding-right:12px;padding-left:12px}.topbar{padding-right:17px;padding-left:17px}.content{padding-right:17px;padding-left:17px}.checkin-grid{grid-template-columns:minmax(0,1fr) 300px;gap:15px}.assignment-meta-item{min-width:115px;padding:9px}.meta-icon{width:30px;height:30px;flex-basis:30px}.scanner-box{min-height:400px}}
@media(max-width:1050px){
:root{--sidebar-width:0px;--topbar-height:0px}
.sidebar{position:relative;inset:auto;width:100%;height:auto;overflow:visible;padding:13px 16px;border-right:0;border-bottom:1px solid var(--border)}
.brand{margin-bottom:14px}
.nav{display:flex;flex-wrap:wrap;gap:6px}
.nav-link{min-height:38px;padding:8px 10px}
.profile-card{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:12px;padding:9px 12px}
.online-status{margin:0 auto 0 0}
.logout-form{padding:0;border-top:0}
.logout-button{width:auto;padding:8px}
.main{margin-left:0}
.topbar{position:relative;top:auto;right:auto;left:auto;min-height:auto;flex-wrap:wrap;padding:17px}
.content{padding:17px}
.checkin-grid{grid-template-columns:minmax(0,1fr) 300px;gap:15px}
.assignment-meta-item{min-width:0;flex:1}
}
@media(max-width:760px){
.topbar{align-items:flex-start;flex-direction:column;gap:14px;padding:15px}
.content{padding:14px}
.checkin-grid{grid-template-columns:minmax(0,1fr)}
.scanner-box{min-height:340px}
.scanner-frame{inset:20% 13%}
.right-stack{grid-template-columns:minmax(0,1fr)}
}
@media(max-width:650px){
.topbar{padding:13px}
.content{padding:18px 13px}
.event-heading{align-items:flex-start}
.event-poster{width:45px;height:54px;flex-basis:45px}
.assignment-meta{width:100%}
.assignment-meta-item{flex:1;min-width:0}
}
@media(max-width:480px){.sidebar{padding:12px}.brand{font-size:21px}.nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.nav-link{font-size:12px;padding:8px}.profile-card{flex-wrap:wrap}.online-status{margin-left:0}.topbar{padding:13px}.content{padding:14px}.event-heading{align-items:flex-start;gap:11px}.event-poster{width:52px;height:62px;flex-basis:52px}.assignment-meta-item{gap:7px;padding:9px}.assignment-meta strong{font-size:11px}.event-card{gap:11px;padding:12px}.event-card-poster{width:62px;height:78px;flex-basis:62px}.event-card h2{font-size:16px}.event-card-heading{flex-wrap:wrap}.event-detail-row{font-size:12px;gap:7px}.scanner-box{min-height:290px;padding:20px 13px}.scanner-frame{inset:22% 8%}.corner{width:34px;height:34px}.scanner-actions{grid-template-columns:1fr}.quick-action{min-height:54px}.tip-icon{width:36px;height:36px;flex-basis:36px}.connection-bar{align-items:flex-start;flex-direction:column}.connection-right{padding-left:46px}}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
    <?php require __DIR__ . '/includes/topbar.php'; ?>
    <section class="content">
        <?php foreach ($errors as $error): ?><div class="notice"><?= staffEscape($error) ?></div><?php endforeach; ?>


        <?php if (!$selectedAssignment): ?>
            <div class="empty-state"><h2>No event assignment yet</h2><p>You are signed in successfully, but no event has been assigned to your staff account. Contact your organizer to confirm your event, gate and shift.</p></div>
        <?php else: ?>
        <div class="checkin-grid">
            <div class="left-stack">


                <section class="scanner-box" aria-label="QR ticket scanner">
                    <div class="scanner-frame" aria-hidden="true"><span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span></div>
                    <div class="scanner-inner"><div class="qr-icon">▦</div><h3>Position QR code within the frame</h3><p>Scanning will start automatically once the camera is open.</p><button type="button" class="primary-button" id="openQrScanner">▦ &nbsp; Start Scanning</button></div>
                </section>

                <div class="scanner-actions">
                    <a class="quick-action" href="manual-checkin.php?assignment_id=<?= (int)$selectedAssignment['assignment_id'] ?>"><span class="quick-icon">♙</span>Manual Check-In</a>
                    <a class="quick-action purple" href="search-attendee.php?assignment_id=<?= (int)$selectedAssignment['assignment_id'] ?>"><span class="quick-icon">⌕</span>Search Attendee</a>
                    <a class="quick-action red" href="invalid-tickets.php?assignment_id=<?= (int)$selectedAssignment['assignment_id'] ?>"><span class="quick-icon">⊘</span>View Invalid Tickets</a>
                </div>

                <section class="connection-bar"><div class="connection-left"><span class="connection-icon">◉</span><span id="connectionText">Connected</span></div><div class="connection-right">Last sync: <span id="syncTime"><?= date('g:i A') ?></span><span>●</span></div></section>

                <form class="manual-form" id="ticketCheckinForm" hidden>
                    <label for="ticketCodeInput">Ticket code<input id="ticketCodeInput" name="token" type="text" maxlength="255" autocomplete="off" placeholder="Enter or paste ticket code" required></label>
                    <button type="submit" class="primary-button" id="submitTicketCode">Verify Ticket</button>
                    <input type="hidden" name="csrf_token" value="<?= staffEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="check_ticket">
                </form>
                <div id="checkinResult" class="result" role="status" aria-live="polite" hidden></div>
            </div>

            <aside class="right-stack">
                <section class="panel tips-panel">
                    <div class="panel-heading"><h3>Scanning Tips</h3></div>
                    <ul class="tip-list">
                        <li><span class="tip-icon">▦</span><span>Ask the attendee to present their QR code.</span></li>
                        <li><span class="tip-icon">▢</span><span>Position the QR code within the frame.</span></li>
                        <li><span class="tip-icon">☼</span><span>Hold steady and avoid glare or shadows.</span></li>
                        <li><span class="tip-icon">ϟ</span><span>Scanning starts automatically when the camera is active.</span></li>
                    </ul>
                </section>
                <section class="panel">
                    <div class="panel-heading"><h3>Recent Check-Ins</h3><a class="text-link" href="todays-checkins.php?assignment_id=<?= (int)$selectedAssignment['assignment_id'] ?>">View All</a></div>
                    <div class="recent-list">
                    <?php if (!$recentCheckins): ?>
                        <div class="empty-state">No check-ins recorded yet.</div>
                    <?php else: foreach ($recentCheckins as $item): $attendee = (string)($item['attendee_name'] ?: 'Attendee'); ?>
                        <div class="recent-item"><span class="recent-avatar"><?= staffEscape(staffInitials($attendee)) ?></span><div class="recent-copy"><strong><?= staffEscape($attendee) ?></strong><small><?= staffEscape($item['ticket_name'] ?? 'Event Ticket') ?> &nbsp; • &nbsp; <?= staffEscape($item['ticket_code'] ?? '') ?></small></div><div class="recent-time"><?= staffEscape(staffDate($item['checked_in_at'] ?? null, 'g:i A')) ?><span>●</span></div></div>
                    <?php endforeach; endif; ?>
                    </div>
                    <a class="recent-footer" href="todays-checkins.php?assignment_id=<?= (int)$selectedAssignment['assignment_id'] ?>">◷ &nbsp; View Today's Check-Ins</a>
                </section>
            </aside>
        </div>
        <?php endif; ?>
    </section>
</main>

<div id="qrScannerModal" class="qr-scanner-modal" hidden><section class="qr-scanner-card" role="dialog" aria-modal="true" aria-labelledby="qrScannerTitle"><button type="button" id="closeQrScanner" class="qr-close" aria-label="Close scanner">✕</button><h2 id="qrScannerTitle">Scan Ticket QR Code</h2><p>Position the attendee's QR code inside the camera frame.</p><div id="qrReader"></div><div id="qrScanResult" class="result" role="status" aria-live="polite" hidden></div><button type="button" id="stopQrScanner" class="primary-button">Stop Scanner</button></section></div>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" defer></script>
<script>
(function(){
    const form=document.getElementById('ticketCheckinForm');
    const input=document.getElementById('ticketCodeInput');
    const modal=document.getElementById('qrScannerModal');
    const result=document.getElementById('checkinResult');
    const scanResult=document.getElementById('qrScanResult');
    const openScanner=document.getElementById('openQrScanner');
    const showManual=document.getElementById('showManualEntry');
    const closeButton=document.getElementById('closeQrScanner');
    const stopButton=document.getElementById('stopQrScanner');
    const flashlightButton=document.getElementById('flashlightButton');
    const flipButton=document.getElementById('flipCameraButton');
    if(!form)return;
    let scanner=null,busy=false,starting=false,facing='environment',torchOn=false;
    function paintResult(target,data){
        target.replaceChildren();target.className='result '+(data.success?'success':'error');
        const title=document.createElement('strong');title.textContent=data.message||'Ticket verification complete.';target.appendChild(title);
        if(data.ticket){const details=document.createElement('div');details.className='result-details';[['Attendee',data.ticket.attendee],['Event',data.ticket.event],['Ticket type',data.ticket.ticket_type],['Ticket code',data.ticket.ticket_code],['Checked in at',data.ticket.checked_in_at]].forEach(([label,value])=>{if(value){const line=document.createElement('div');const b=document.createElement('b');b.textContent=label+': ';line.append(b,document.createTextNode(String(value)));details.appendChild(line);}});target.appendChild(details);}
        target.hidden=false;
    }
    async function stopCamera(){if(scanner){const current=scanner;scanner=null;try{if(current.isScanning)await current.stop();}catch(e){}try{await current.clear();}catch(e){}}starting=false;busy=false;torchOn=false;if(flashlightButton)flashlightButton.textContent='☼ Flashlight';}
    async function closeCamera(){await stopCamera();modal.hidden=true;}
    async function submitToken(token,target){
        if(busy)return;busy=true;target.hidden=false;target.className='result';target.textContent='Verifying ticket…';
        try{const payload=new FormData(form);payload.set('token',token.trim());payload.set('action','check_ticket');const response=await fetch(window.location.href,{method:'POST',body:payload,headers:{'X-Requested-With':'XMLHttpRequest'}});const data=await response.json();paintResult(target,data);if(data.success){if(input)input.value='';const sync=document.getElementById('syncTime');if(sync)sync.textContent=new Date().toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});}}
        catch(e){paintResult(target,{success:false,message:'Unable to verify the ticket. Check your connection and try again.'});}finally{busy=false;}
    }
    if(showManual)showManual.addEventListener('click',()=>{form.hidden=!form.hidden;if(!form.hidden)input.focus();});
    form.addEventListener('submit',e=>{e.preventDefault();const token=input.value.trim();if(token)submitToken(token,result);else input.focus();});
    async function startCamera(){
        if(starting||scanner)return;if(typeof Html5Qrcode==='undefined'){paintResult(result,{success:false,message:'The QR scanner library could not load. Check your internet connection and refresh.'});return;}
        starting=true;modal.hidden=false;scanResult.hidden=true;
        try{scanner=new Html5Qrcode('qrReader');await scanner.start({facingMode:facing},{fps:10,qrbox:{width:240,height:240}},async decoded=>{if(busy||!scanner)return;try{await scanner.pause(true);}catch(e){}await submitToken(decoded,scanResult);if(scanner){try{await scanner.resume();}catch(e){}}},()=>{});}
        catch(e){paintResult(scanResult,{success:false,message:'Camera could not start. Allow camera permission or use the manual check-in page.'});await stopCamera();}
    }
    if(openScanner)openScanner.addEventListener('click',startCamera);
    if(closeButton)closeButton.addEventListener('click',closeCamera);
    if(stopButton)stopButton.addEventListener('click',closeCamera);
    modal.addEventListener('click',e=>{if(e.target===modal)closeCamera();});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeCamera();});
    if(flipButton)flipButton.addEventListener('click',async()=>{facing=facing==='environment'?'user':'environment';if(scanner){await closeCamera();await startCamera();}else{flipButton.textContent=facing==='environment'?'◎ Flip Camera':'◎ Use Rear Camera';}});
    if(flashlightButton)flashlightButton.addEventListener('click',async()=>{if(!scanner||!scanner.isScanning){paintResult(result,{success:false,message:'Start the scanner before using the flashlight.'});return;}try{const video=document.querySelector('#qrReader video');const track=video&&video.srcObject&&video.srcObject.getVideoTracks()[0];if(!track||!track.getCapabilities().torch){paintResult(scanResult,{success:false,message:'Flashlight is not supported by this camera.'});return;}torchOn=!torchOn;await track.applyConstraints({advanced:[{torch:torchOn}]});flashlightButton.textContent=torchOn?'☼ Turn Flashlight Off':'☼ Flashlight';}catch(e){paintResult(scanResult,{success:false,message:'Could not change the flashlight setting on this device.'});}});
    window.addEventListener('beforeunload',()=>{if(scanner&&scanner.isScanning)scanner.stop().catch(()=>{});});
})();
</script>
</body>
</html>