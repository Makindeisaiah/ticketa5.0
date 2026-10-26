<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once dirname(__DIR__) . '/config/database.php';

if (empty($_SESSION['staff_user']['id'])) { header('Location: login.php'); exit; }
$staffId = (int) $_SESSION['staff_user']['id'];
$errors = []; $notice = ''; $results = []; $selectedAssignment = null; $assignments = []; $staff = null; $recentCheckins = [];
$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
$searchMode = (string)($_POST['search_mode'] ?? 'contact');
$searchTerm = trim((string)($_POST['search_term'] ?? ''));
$csrfToken = (string)($_SESSION['staff_dashboard_csrf'] ?? '');
if ($csrfToken === '') { $_SESSION['staff_dashboard_csrf'] = bin2hex(random_bytes(32)); $csrfToken = $_SESSION['staff_dashboard_csrf']; }

function staffEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function staffDate($value, string $format = 'D, d M Y · H:i'): string { if (empty($value)) return 'Not specified'; $time = strtotime((string)$value); return $time === false ? 'Not specified' : date($format, $time); }
function staffInitials(string $name): string { $parts = preg_split('/\s+/', trim($name)) ?: []; $out = ''; foreach (array_slice($parts, 0, 2) as $part) { if ($part !== '') $out .= strtoupper(substr($part, 0, 1)); } return $out ?: 'S'; }
function staffRoleLabel(string $role): string { $labels = ['checkin_staff'=>'Check-in Staff','supervisor'=>'Supervisor','admin'=>'Administrator']; return $labels[$role] ?? ucwords(str_replace('_', ' ', $role)); }
function staffEventImageUrl($path): string { $path = trim((string)$path); if ($path === '') return ''; if (preg_match('~^https?://~i', $path)) return filter_var($path, FILTER_VALIDATE_URL) ? $path : ''; if (str_starts_with($path, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_contains($path, "\0")) return ''; $path = str_replace('\\', '/', $path); foreach (explode('/', $path) as $part) if ($part === '..') return ''; $path = ltrim($path, '/'); $folder = basename(dirname(__DIR__)); return '/' . (str_starts_with($path, $folder . '/') ? $path : $folder . '/' . $path); }
function staffColumnExists(PDO $pdo, string $table, string $column): bool { $q = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'); $q->execute(['t'=>$table,'c'=>$column]); return (int)$q->fetchColumn() > 0; }

try {
    $stmt = $pdo->prepare("SELECT id, full_name, email, phone, role, status FROM staff_users WHERE id = :id LIMIT 1");
    $stmt->execute(['id'=>$staffId]); $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff || $staff['status'] !== 'active') { unset($_SESSION['staff_user']); header('Location: login.php'); exit; }
    $_SESSION['staff_user']['full_name'] = $staff['full_name']; $_SESSION['staff_user']['email'] = $staff['email']; $_SESSION['staff_user']['role'] = $staff['role'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
        $sent = (string)($_POST['csrf_token'] ?? '');
        if ($sent !== '' && hash_equals($csrfToken, $sent)) { unset($_SESSION['staff_user'], $_SESSION['staff_dashboard_csrf']); session_regenerate_id(true); header('Location: login.php'); exit; }
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }
    $stmt = $pdo->prepare("SELECT a.id AS assignment_id, a.event_id, a.assigned_gate, a.assignment_role, a.shift_start, a.shift_end, a.status AS assignment_status, e.title AS event_title, e.event_date, e.event_end_date, e.venue, e.address, e.city, e.image_url FROM staff_event_assignments a INNER JOIN events e ON e.id = a.event_id WHERE a.staff_user_id = :staff_id AND a.status IN ('scheduled','active','completed') ORDER BY CASE WHEN a.status='active' THEN 0 WHEN a.status='scheduled' THEN 1 ELSE 2 END, a.shift_start ASC");
    $stmt->execute(['staff_id'=>$staffId]); $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($assignments) { if ($assignmentId) foreach ($assignments as $a) if ((int)$a['assignment_id'] === $assignmentId) { $selectedAssignment = $a; break; } if (!$selectedAssignment) $selectedAssignment = $assignments[0]; }
    if ($selectedAssignment) {
        $eventId = (int)$selectedAssignment['event_id'];
        // Build optional contact-field search from columns that actually exist in this database.
        $searchColumns = ['it.attendee_name'];
        foreach (['attendee_email','email','attendee_phone','phone'] as $column) {
            if (staffColumnExists($pdo, 'issued_tickets', $column)) {
                $searchColumns[] = 'it.' . $column;
            }
        }
        // Only search order/customer contact columns when the orders table can
        // actually be joined through order_items. Otherwise SQL would refer to
        // alias "o" without declaring it, causing the contact search to fail.
        $orderJoin = '';
        if (staffColumnExists($pdo, 'order_items', 'order_id') && staffColumnExists($pdo, 'orders', 'id')) {
            $orderJoin = ' LEFT JOIN orders o ON o.id = oi.order_id ';
            foreach (['email','customer_email','phone','customer_phone'] as $column) {
                if (staffColumnExists($pdo, 'orders', $column)) {
                    $searchColumns[] = 'o.' . $column;
                }
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['search_attendee','confirm_checkin'], true)) {
            $submitted = (string)($_POST['csrf_token'] ?? '');
            if ($submitted === '' || !hash_equals($csrfToken, $submitted)) { $errors[] = 'Your session expired. Refresh the page and try again.'; }
            elseif (($_POST['action'] ?? '') === 'search_attendee') {
                if ($searchTerm === '') $errors[] = 'Enter an email, phone number, attendee name or ticket ID.';
                else {
                    $conditions = [];
                    $params = ['event_id' => $eventId];
                    $termIndex = 0;
                    foreach (array_unique($searchColumns) as $col) {
                        if ($searchMode === 'ticket') {
                            if ($col === 'it.ticket_code') {
                                $conditions[] = "$col = :ticket";
                            }
                        } elseif ($searchMode === 'name') {
                            if (str_ends_with($col, 'attendee_name')) {
                                $placeholder = ':term' . $termIndex++;
                                $conditions[] = "$col LIKE $placeholder";
                                $params[substr($placeholder, 1)] = '%' . $searchTerm . '%';
                            }
                        } else {
                            // PDO may use native MySQL prepares, which do not allow the
                            // same named placeholder to be reused multiple times.
                            $placeholder = ':term' . $termIndex++;
                            $conditions[] = "$col LIKE $placeholder";
                            $params[substr($placeholder, 1)] = '%' . $searchTerm . '%';
                        }
                    }
                    if ($searchMode === 'ticket') {
                        $conditions = ['it.ticket_code = :ticket'];
                        $params['ticket'] = $searchTerm;
                    }
                    if (!$conditions) {
                        $conditions[] = 'it.attendee_name LIKE :term0';
                        $params['term0'] = '%' . $searchTerm . '%';
                    }
                    $sql = "SELECT it.id, it.ticket_code, it.attendee_name, it.status, it.checked_in_at, tt.name AS ticket_name, tt.event_id FROM issued_tickets it INNER JOIN order_items oi ON oi.id = it.order_item_id INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id $orderJoin WHERE tt.event_id = :event_id AND (" . implode(' OR ', array_unique($conditions)) . ') ORDER BY it.id DESC LIMIT 20';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!$results) $notice = 'No matching attendee or ticket was found for this event.';
                }
            } else {
                $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$ticketId) $errors[] = 'Select a valid ticket before confirming check-in.';
                else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT it.id, it.ticket_code, it.attendee_name, it.status, tt.event_id FROM issued_tickets it INNER JOIN order_items oi ON oi.id = it.order_item_id INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE it.id = :ticket_id AND tt.event_id = :event_id LIMIT 1 FOR UPDATE");
                    $stmt->execute(['ticket_id'=>$ticketId,'event_id'=>$eventId]); $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$ticket) { $pdo->rollBack(); $errors[] = 'This ticket does not belong to your assigned event.'; }
                    elseif ($ticket['status'] === 'used') { $pdo->rollBack(); $errors[] = 'This ticket has already been checked in.'; }
                    elseif ($ticket['status'] !== 'valid') { $pdo->rollBack(); $errors[] = 'This ticket is not valid for check-in.'; }
                    else {
                        $stmt = $pdo->prepare("UPDATE issued_tickets SET status = 'used', checked_in_at = NOW() WHERE id = :id AND status = 'valid'"); $stmt->execute(['id'=>$ticketId]);
                        if ($stmt->rowCount() !== 1) { $pdo->rollBack(); $errors[] = 'The ticket status changed. Search again before retrying.'; }
                        else { $pdo->commit(); $notice = 'Check-in completed successfully for ' . (string)($ticket['attendee_name'] ?: 'the attendee') . '.'; $results = []; $searchTerm = ''; }
                    }
                }
            }
        }
        $stmt = $pdo->prepare("SELECT it.id, it.ticket_code, it.attendee_name, it.checked_in_at, tt.name AS ticket_name FROM issued_tickets it INNER JOIN order_items oi ON oi.id = it.order_item_id INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id WHERE tt.event_id = :event_id AND it.status = 'used' AND it.checked_in_at IS NOT NULL ORDER BY it.checked_in_at DESC LIMIT 6");
        $stmt->execute(['event_id'=>$eventId]); $recentCheckins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); error_log('Ticketa manual check-in error: ' . $e->getMessage()); $errors[] = 'Some information could not be loaded. Check the PHP error log.'; }

$staffName = (string)($staff['full_name'] ?? 'Staff Member'); $staffRole = (string)($staff['role'] ?? 'checkin_staff'); $csrfToken = (string)($_SESSION['staff_dashboard_csrf'] ?? '');
$eventTitle = (string)($selectedAssignment['event_title'] ?? 'No event assigned'); $eventVenue = trim((string)($selectedAssignment['venue'] ?? '') . (!empty($selectedAssignment['city']) ? ', ' . $selectedAssignment['city'] : ''));
$gateName = (string)($selectedAssignment['assigned_gate'] ?? 'Not assigned'); $shiftStart = $selectedAssignment['shift_start'] ?? null; $shiftEnd = $selectedAssignment['shift_end'] ?? null; $eventImage = staffEventImageUrl($selectedAssignment['image_url'] ?? '');
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Manual Check-In | TICKETA</title>
<style>
:root{--teal:#10bea0;--teal-dark:#08a98d;--ink:#202124;--muted:#777b82;--border:#e6e8eb;--page:#f8f9fb;--purple:#8b5cf6;--sidebar-width:240px;--topbar-height:112px}*{box-sizing:border-box}html{min-height:100%;scroll-padding-top:calc(var(--topbar-height) + 15px)}body{margin:0;min-height:100vh;background:var(--page);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px}button,input{font:inherit}button,a{-webkit-tap-highlight-color:transparent}a{color:inherit;text-decoration:none}button{cursor:pointer}[hidden]{display:none!important}
/* Shared sidebar styles copied from dashboard.php */
.sidebar{position:fixed;inset:0 auto 0 0;z-index:1000;display:flex;width:var(--sidebar-width);height:100vh;height:100dvh;flex-direction:column;overflow-x:hidden;overflow-y:hidden;padding:22px 16px 14px;border-right:1px solid var(--border);background:#fff;scrollbar-width:thin}.brand{display:flex;flex:0 0 auto;align-items:center;gap:9px;margin:0 0 32px;padding:0 3px;color:#111;font-size:23px;font-weight:600;letter-spacing:-1px}.brand-mark{display:inline-flex;width:37px;height:30px;align-items:center;justify-content:center;border:3px solid var(--teal);border-radius:5px;color:var(--teal);font-size:21px;font-weight:700;line-height:1}.nav{display:grid;flex:0 0 auto;gap:8px}.nav-link{display:flex;min-width:0;min-height:43px;align-items:center;gap:12px;padding:9px 12px;border-radius:5px;color:#74777d;font-size:14px;transition:background .2s ease,color .2s ease}.nav-link:hover{background:#f1faf8;color:var(--teal-dark)}.nav-link.active{background:var(--teal);color:#fff;box-shadow:0 3px 8px #10bea022}.nav-icon{display:inline-flex;width:22px;flex:0 0 22px;align-items:center;justify-content:center;font-size:19px}.nav-text{min-width:0;overflow-wrap:anywhere}.nav-count{display:inline-flex;min-width:21px;height:21px;align-items:center;justify-content:center;margin-left:auto;border-radius:50%;background:#fee2e2;color:#e5484d;font-size:11px;font-weight:700}.profile-card{flex:0 0 auto;margin-top:auto;padding:13px;border:1px solid #dedfe3;border-radius:11px;background:#fcfcfd}.profile-details{display:flex;min-width:0;align-items:center;gap:10px}.avatar{display:inline-flex;width:39px;height:39px;flex:0 0 39px;align-items:center;justify-content:center;overflow:hidden;border-radius:50%;background:#e1f7f1;color:#078f79;font-weight:700}.profile-copy{min-width:0}.profile-copy strong{display:block;overflow:hidden;font-size:13px;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.profile-copy small{display:block;margin-top:4px;color:var(--muted);font-size:12px}.online-status{display:flex;align-items:center;gap:7px;margin:10px 0 9px 48px;color:#62676d;font-size:12px}.online-dot{width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:var(--teal)}.logout-form{margin:0;padding-top:8px;border-top:1px solid #e5e6e8}.logout-button{display:flex;width:100%;align-items:center;gap:10px;padding:5px 2px;border:0;background:transparent;color:#70747a;text-align:left;font-size:12px}.logout-button:hover{color:#d33}
.main{min-width:0;min-height:100vh;margin-left:var(--sidebar-width)}.topbar{position:fixed;top:0;right:0;left:var(--sidebar-width);z-index:900;display:flex;min-height:var(--topbar-height);align-items:center;justify-content:space-between;gap:20px;padding:14px 25px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.97);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}.event-heading{display:flex;min-width:0;align-items:center;gap:15px}.event-poster{display:flex;width:64px;height:76px;flex:0 0 64px;align-items:center;justify-content:center;overflow:hidden;border-radius:6px;background:linear-gradient(145deg,#e3f7f4,#d9e6ff);color:#078f79;font-size:25px;font-weight:700}.event-poster img{display:block;width:100%;height:100%;object-fit:cover;object-position:center}.event-copy{min-width:0}.event-copy h1{margin:0 0 7px;overflow-wrap:anywhere;font-size:clamp(18px,1.7vw,25px);font-weight:600;letter-spacing:-.5px}.event-meta{display:flex;flex-wrap:wrap;align-items:center;gap:7px;color:var(--muted);font-size:13px;line-height:1.5}.event-meta .separator{color:var(--teal)}.assignment-meta{display:flex;flex:0 0 auto;align-items:center;border:1px solid #dedfe3;border-radius:15px;background:#fff}.assignment-meta-item{display:flex;min-width:145px;align-items:center;gap:10px;padding:12px 14px}.assignment-meta-item+.assignment-meta-item{border-left:1px solid #e3e4e7}.meta-icon{display:inline-flex;width:36px;height:36px;flex:0 0 36px;align-items:center;justify-content:center;border:1px solid #e4e5e8;border-radius:50%;font-size:17px}.meta-icon.purple{color:var(--purple)}.meta-icon.blue{color:#5b91f5}.assignment-meta small{display:block;margin-bottom:4px;color:var(--muted);font-size:11px}.assignment-meta strong{display:block;max-width:145px;overflow-wrap:anywhere;font-size:13px;font-weight:500}.content{padding:calc(var(--topbar-height) + 22px) 25px 24px}.notice{margin-bottom:15px;padding:12px 15px;border:1px solid #f1d4d4;border-radius:8px;background:#fff0f0;color:#a32929;overflow-wrap:anywhere}.notice.success{border-color:#bcebe0;background:#e9faf6;color:#087c68}.page-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(290px,.8fr);gap:20px;align-items:start}.column{display:grid;min-width:0;gap:18px}.panel{min-width:0;padding:20px;border:1px solid #f0f0f2;border-radius:13px;background:#fff;box-shadow:0 3px 7px #161b2d12,0 7px 14px #161b2d08}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.panel-heading h2{margin:0;font-size:17px;font-weight:600;letter-spacing:-.3px}.text-link{color:#48bda9;font-size:12px}.search-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;border-bottom:1px solid #d9dcdf;margin-bottom:22px}.search-tab{display:flex;min-width:0;align-items:center;justify-content:center;gap:9px;padding:12px 7px;border:0;border-bottom:3px solid transparent;background:transparent;color:#73777d;font-size:13px}.search-tab.active{border-bottom-color:var(--teal);color:#32b9a4}.field-label{display:block;margin-bottom:9px;font-size:13px;font-weight:500}.search-input-wrap{display:flex;align-items:center;gap:10px;padding:0 14px;border:1px solid #d8dadd;border-radius:9px;background:#fff}.search-input-wrap:focus-within{border-color:var(--teal);box-shadow:0 0 0 3px #10bea01c}.search-input-wrap span{color:#777b82;font-size:21px}.search-input{width:100%;min-width:0;height:49px;border:0;outline:0;background:transparent;color:var(--ink)}.hint{margin:9px 0 20px;color:var(--muted);font-size:12px;line-height:1.5}.primary-button{display:flex;width:100%;min-height:47px;align-items:center;justify-content:center;gap:9px;padding:10px 18px;border:0;border-radius:7px;background:var(--teal);color:#fff;font-weight:500}.primary-button:hover{background:var(--teal-dark)}.results{display:grid;gap:10px;margin-top:18px}.result-card{padding:15px;border:1px solid var(--border);border-radius:10px}.result-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.result-top h3{margin:0 0 6px;font-size:15px}.result-meta{color:var(--muted);font-size:12px;line-height:1.6;overflow-wrap:anywhere}.status{display:inline-block;padding:4px 8px;border-radius:5px;background:#e5f8f4;color:#087f6d;font-size:11px}.status.used{background:#fff0e6;color:#a65314}.status.invalid{background:#ffe7e7;color:#b42323}.confirm-form{margin-top:12px}.confirm-button{display:inline-flex;min-height:37px;align-items:center;justify-content:center;padding:8px 12px;border:0;border-radius:7px;background:var(--teal);color:#fff;font-size:12px}.confirm-button:disabled{cursor:not-allowed;background:#c5c9cd}.steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.step{position:relative;min-height:150px;padding:15px;border:1px solid #d9dcdf;border-radius:10px;background:#fafafa}.step-number{display:flex;width:30px;height:30px;align-items:center;justify-content:center;margin-bottom:14px;border-radius:50%;background:var(--teal);color:#fff;font-weight:600}.step h3{margin:0 0 10px;font-size:13px;font-weight:600}.step p{margin:0;color:var(--muted);font-size:12px;line-height:1.5}.recent-list{display:grid}.recent-row{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid #eceef0}.recent-row:last-child{border-bottom:0}.attendee-avatar{display:flex;width:38px;height:38px;flex:0 0 38px;align-items:center;justify-content:center;border-radius:50%;background:#e1f6f1;color:#07967f;font-size:12px;font-weight:700}.attendee-info{min-width:0;flex:1}.attendee-info strong{display:block;overflow:hidden;font-size:13px;font-weight:500;text-overflow:ellipsis;white-space:nowrap}.attendee-info small{display:block;margin-top:5px;color:#8a8e95;font-size:11px;overflow-wrap:anywhere}.checkin-time{flex:0 0 auto;color:#777b82;font-size:11px;text-align:right}.checkin-time span{display:block;margin-top:5px;color:var(--teal);font-size:15px}.tips-panel{background:#e9f8f6}.tip{display:flex;align-items:flex-start;gap:12px;padding:10px 0}.tip-icon{display:flex;width:40px;height:40px;flex:0 0 40px;align-items:center;justify-content:center;border-radius:50%;background:#fff;color:#34bba5;font-size:19px}.tip strong{display:block;margin-bottom:5px;color:#35b9a4;font-size:13px;font-weight:500}.tip p{margin:0;color:#7c8586;font-size:12px;line-height:1.5}.empty-state{padding:22px 10px;color:var(--muted);text-align:center;font-size:13px;line-height:1.7}.connection-bar{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:15px 18px;border:1px solid #f0f0f2;border-radius:12px;background:#fff;box-shadow:0 3px 7px #161b2d12}.connection-left{display:flex;align-items:center;gap:10px;color:#51a976;font-size:12px}.connection-icon{display:flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:50%;background:#dff6e8;font-size:17px}.connection-right{color:#888d94;font-size:11px}.connection-right span{color:var(--teal)}.no-assignment{max-width:600px;margin:35px auto;padding:40px 25px;border:1px solid var(--border);border-radius:14px;background:#fff;text-align:center;box-shadow:0 3px 8px #00000008}.no-assignment h2{margin:0 0 10px;font-size:22px}.no-assignment p{margin:0;color:var(--muted);line-height:1.7}
@media(max-height:760px) and (min-width:1051px){.sidebar{padding-top:13px;padding-bottom:10px}.brand{margin-bottom:20px}.nav{gap:5px}.nav-link{min-height:37px;padding-top:7px;padding-bottom:7px}.profile-card{padding:10px}.online-status{margin-top:6px;margin-bottom:6px}}
@media(max-width:1350px) and (min-width:1051px){:root{--sidebar-width:220px;--topbar-height:105px}.sidebar{padding-right:12px;padding-left:12px}.topbar{padding-right:17px;padding-left:17px}.content{padding-right:17px;padding-left:17px}.page-grid{grid-template-columns:minmax(0,1fr) minmax(275px,.8fr);gap:15px}.assignment-meta-item{min-width:115px;padding:9px;gap:7px}.meta-icon{width:30px;height:30px;flex-basis:30px}}
@media(max-width:1050px){:root{--sidebar-width:0px;--topbar-height:0px}.sidebar{position:relative;inset:auto;width:100%;height:auto;overflow:visible;padding:13px 16px;border-right:0;border-bottom:1px solid var(--border)}.brand{margin-bottom:14px}.nav{display:flex;flex-wrap:wrap;gap:6px}.nav-link{min-height:38px;padding:8px 10px}.profile-card{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:12px;padding:9px 12px}.online-status{margin:0 auto 0 0}.logout-form{padding:0;border-top:0}.logout-button{width:auto;padding:8px}.main{margin-left:0}.topbar{position:relative;top:auto;right:auto;left:auto;min-height:auto;flex-wrap:wrap;padding:17px}.content{padding:17px}.assignment-meta{max-width:100%}.assignment-meta-item{min-width:0;flex:1}.page-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
@media(max-width:760px){.topbar{align-items:flex-start;flex-direction:column;gap:14px}.assignment-meta{width:100%}.page-grid{grid-template-columns:minmax(0,1fr)}.content{padding:14px}.panel{padding:16px}}
@media(max-width:480px){.sidebar{padding:12px}.brand{font-size:21px}.nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.nav-link{font-size:12px}.profile-card{flex-wrap:wrap}.online-status{margin-left:0}.event-heading{align-items:flex-start;gap:11px}.event-poster{width:52px;height:62px;flex-basis:52px}.assignment-meta-item{gap:7px;padding:9px}.assignment-meta strong{font-size:11px}.search-tabs{grid-template-columns:1fr}.search-tab{justify-content:flex-start}.steps{grid-template-columns:1fr}.step{min-height:0}.connection-bar{align-items:flex-start;flex-direction:column}}
</style></head><body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<?php require __DIR__ . '/includes/topbar.php'; ?>
<section class="content">
<?php foreach ($errors as $error): ?><div class="notice"><?= staffEscape($error) ?></div><?php endforeach; ?>
<?php if ($notice !== ''): ?><div class="notice success"><?= staffEscape($notice) ?></div><?php endif; ?>
<?php if (!$selectedAssignment): ?>
<div class="no-assignment"><h2>No event assignment yet</h2><p>You are signed in successfully, but no event has been assigned to your staff account. Contact your organizer to confirm your event, gate and shift.</p></div>
<?php else: ?>
<div class="page-grid"><div class="column">
<section class="panel"><div class="panel-heading"><h2>Find Attendee</h2></div>
<form method="post" id="searchForm"><input type="hidden" name="action" value="search_attendee"><input type="hidden" name="csrf_token" value="<?= staffEscape($csrfToken) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$selectedAssignment['assignment_id'] ?>"><input type="hidden" name="search_mode" id="searchMode" value="<?= staffEscape($searchMode) ?>">
<div class="search-tabs" role="tablist"><button type="button" class="search-tab <?= $searchMode==='contact'?'active':'' ?>" data-mode="contact">⌕ <span>Search by Email or Phone</span></button><button type="button" class="search-tab <?= $searchMode==='name'?'active':'' ?>" data-mode="name">♙ <span>Search by Name</span></button><button type="button" class="search-tab <?= $searchMode==='ticket'?'active':'' ?>" data-mode="ticket">▤ <span>Search by Ticket ID</span></button></div>
<label class="field-label" id="searchLabel" for="searchTerm"><?= $searchMode==='name'?'Attendee Name':($searchMode==='ticket'?'Ticket ID':'Email or Phone Number') ?></label><div class="search-input-wrap"><span>✉</span><input class="search-input" id="searchTerm" name="search_term" value="<?= staffEscape($searchTerm) ?>" placeholder="<?= $searchMode==='name'?'Enter attendee full name':($searchMode==='ticket'?'Enter ticket ID or code':'Enter email address or phone number') ?>" required></div><p class="hint">Search only checks tickets for the event assigned to your staff account.</p><button class="primary-button" type="submit">⌕ &nbsp; Search Attendee</button></form>
<?php if ($results): ?><div class="results"><h3>Search Results (<?= count($results) ?>)</h3><?php foreach ($results as $ticket): ?><article class="result-card"><div class="result-top"><div><h3><?= staffEscape($ticket['attendee_name'] ?: 'Attendee') ?></h3><div class="result-meta"><?= staffEscape($ticket['ticket_name'] ?? 'Event Ticket') ?> · <?= staffEscape($ticket['ticket_code'] ?? '') ?></div></div><span class="status <?= $ticket['status']==='used'?'used':($ticket['status']!=='valid'?'invalid':'') ?>"><?= staffEscape(ucfirst((string)$ticket['status'])) ?></span></div><?php if ($ticket['status']==='valid'): ?><form class="confirm-form" method="post" onsubmit="return confirm('Confirm manual check-in for this attendee?');"><input type="hidden" name="action" value="confirm_checkin"><input type="hidden" name="csrf_token" value="<?= staffEscape($csrfToken) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$selectedAssignment['assignment_id'] ?>"><input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>"><button class="confirm-button" type="submit">Confirm Check-In</button></form><?php elseif ($ticket['status']==='used'): ?><div class="result-meta">Already checked in<?= !empty($ticket['checked_in_at'])?' at '.staffEscape(staffDate($ticket['checked_in_at'],'g:i A')):'' ?>.</div><?php else: ?><div class="result-meta">This ticket cannot be used for check-in.</div><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="panel"><div class="panel-heading"><h2>How Manual Check-In Works</h2></div><div class="steps"><div class="step"><div class="step-number">1</div><h3>Find Attendee</h3><p>Search using email, phone number, name or ticket ID.</p></div><div class="step"><div class="step-number">2</div><h3>Verify Details</h3><p>Confirm the attendee name, ticket code and ticket status.</p></div><div class="step"><div class="step-number">3</div><h3>Check-In</h3><p>Confirm check-in. Used or invalid tickets cannot be checked in again.</p></div></div></section>
<div class="connection-bar"><div class="connection-left"><span class="connection-icon">◉</span><span>Connected</span></div><div class="connection-right">Server status <span>●</span></div></div>
</div><aside class="column"><section class="panel"><div class="panel-heading"><h2>Recent Manual Check-Ins</h2><a class="text-link" href="todays-checkins.php?assignment_id=<?= (int)$selectedAssignment['assignment_id'] ?>">View All</a></div><div class="recent-list"><?php if (!$recentCheckins): ?><div class="empty-state">No check-ins recorded yet. Completed check-ins will appear here.</div><?php else: foreach ($recentCheckins as $recent): $name=(string)($recent['attendee_name'] ?: 'Attendee'); ?><div class="recent-row"><span class="attendee-avatar"><?= staffEscape(staffInitials($name)) ?></span><div class="attendee-info"><strong><?= staffEscape($name) ?></strong><small><?= staffEscape($recent['ticket_name'] ?? 'Event Ticket') ?> · <?= staffEscape($recent['ticket_code'] ?? '') ?></small></div><div class="checkin-time"><?= staffEscape(staffDate($recent['checked_in_at'],'g:i A')) ?><span>●</span></div></div><?php endforeach; endif; ?></div></section>
<section class="panel tips-panel"><div class="panel-heading"><h2>Tips for Manual Check-In</h2></div><div class="tip"><span class="tip-icon">♧</span><div><strong>Verify Identity</strong><p>Always confirm the attendee's identity before completing the check-in.</p></div></div><div class="tip"><span class="tip-icon">▢</span><div><strong>Check Ticket Type</strong><p>Ensure the ticket type matches the assigned gate and access level.</p></div></div><div class="tip"><span class="tip-icon">△</span><div><strong>Report Issues</strong><p>If you encounter problems, report them to your supervisor immediately.</p></div></div></section></aside></div>
<?php endif; ?>
</section></main>
<script>
(() => {const tabs=document.querySelectorAll('.search-tab');const mode=document.getElementById('searchMode');const input=document.getElementById('searchTerm');const label=document.getElementById('searchLabel');if(!tabs.length||!mode||!input||!label)return;const config={contact:{label:'Email or Phone Number',placeholder:'Enter email address or phone number'},name:{label:'Attendee Name',placeholder:'Enter attendee full name'},ticket:{label:'Ticket ID',placeholder:'Enter ticket ID or code'}};tabs.forEach(tab=>tab.addEventListener('click',()=>{const selected=tab.dataset.mode;mode.value=selected;tabs.forEach(t=>t.classList.toggle('active',t===tab));label.textContent=config[selected].label;input.placeholder=config[selected].placeholder;input.focus();}));})();
</script></body></html>
