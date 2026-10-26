<?php
// TICKETA - Edit Event
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!authIsLoggedIn()) {
    $_SESSION['auth_return_to'] = 'organizer/edit-event.php';
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'] ?? [];
if (($user['role'] ?? '') !== 'organizer') {
    http_response_code(403);
    exit('Access denied. Organizer accounts only.');
}
$userId = (int) ($user['id'] ?? 0);
$eventId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$eventId || $eventId < 1) {
    header('Location: events.php');
    exit;
}

function editEventEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function editEventDateInput($value): string
{
    if (!$value) return '';
    try { return (new DateTime((string) $value))->format('Y-m-d\\TH:i'); }
    catch (Throwable $e) { return ''; }
}

function editEventSlug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'event';
}

$errors = [];
$success = '';
$organizerStmt = $pdo->prepare('SELECT id, business_name FROM organizers WHERE user_id = :user_id LIMIT 1');
$organizerStmt->execute(['user_id' => $userId]);
$organizer = $organizerStmt->fetch(PDO::FETCH_ASSOC);
if (!$organizer) {
    header('Location: onboarding.php');
    exit;
}
$organizerId = (int) $organizer['id'];

$eventStmt = $pdo->prepare('SELECT e.* FROM events e WHERE e.id = :event_id AND e.organizer_id = :organizer_id LIMIT 1');
$eventStmt->execute(['event_id' => $eventId, 'organizer_id' => $organizerId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    exit('Event not found, or you do not have permission to edit it.');
}

$categories = $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$ticketStmt = $pdo->prepare('SELECT id, name, description, price, quantity_total, quantity_sold, sales_start, sales_end, is_active FROM ticket_types WHERE event_id = :event_id ORDER BY id ASC');
$ticketStmt->execute(['event_id' => $eventId]);
$ticketRows = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

$values = [
    'title' => (string) ($event['title'] ?? ''),
    'category_id' => (string) ($event['category_id'] ?? ''),
    'description' => (string) ($event['description'] ?? ''),
    'event_date' => editEventDateInput($event['event_date'] ?? ''),
    'event_end_date' => editEventDateInput($event['event_end_date'] ?? ''),
    'venue' => (string) ($event['venue'] ?? ''),
    'address' => (string) ($event['address'] ?? ''),
    'city' => (string) ($event['city'] ?? ''),
    'status' => (string) ($event['status'] ?? 'draft'),
];
$tickets = [];
foreach ($ticketRows as $row) {
    $tickets[] = [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'description' => (string) ($row['description'] ?? ''),
        'price' => (string) $row['price'],
        'quantity_total' => (string) $row['quantity_total'],
        'quantity_sold' => (string) $row['quantity_sold'],
        'sales_start' => editEventDateInput($row['sales_start'] ?? ''),
        'sales_end' => editEventDateInput($row['sales_end'] ?? ''),
        'is_active' => (string) ($row['is_active'] ?? '1'),
    ];
}
if (!$tickets) {
    $tickets[] = ['id'=>'','name'=>'General Admission','description'=>'','price'=>'0','quantity_total'=>'50','quantity_sold'=>'0','sales_start'=>'','sales_end'=>'','is_active'=>'1'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['title','category_id','description','event_date','event_end_date','venue','address','city','status'] as $key) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) $values[$key] = trim($_POST[$key]);
    }

    $names = $_POST['ticket_name'] ?? [];
    $ids = $_POST['ticket_id'] ?? [];
    $prices = $_POST['ticket_price'] ?? [];
    $quantities = $_POST['ticket_quantity'] ?? [];
    $descriptions = $_POST['ticket_description'] ?? [];
    $salesStarts = $_POST['ticket_sales_start'] ?? [];
    $salesEnds = $_POST['ticket_sales_end'] ?? [];
    $activeValues = $_POST['ticket_active'] ?? [];
    $tickets = [];
    if (is_array($names)) {
        foreach ($names as $i => $name) {
            $tickets[] = [
                'id' => is_array($ids) ? trim((string) ($ids[$i] ?? '')) : '',
                'name' => is_string($name) ? trim($name) : '',
                'description' => is_array($descriptions) ? trim((string) ($descriptions[$i] ?? '')) : '',
                'price' => is_array($prices) ? trim((string) ($prices[$i] ?? '')) : '',
                'quantity_total' => is_array($quantities) ? trim((string) ($quantities[$i] ?? '')) : '',
                'quantity_sold' => '0',
                'sales_start' => is_array($salesStarts) ? trim((string) ($salesStarts[$i] ?? '')) : '',
                'sales_end' => is_array($salesEnds) ? trim((string) ($salesEnds[$i] ?? '')) : '',
                'is_active' => (is_array($activeValues) && isset($activeValues[$i])) ? '1' : '0',
            ];
        }
    }

    if (!authVerifyCsrf()) $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    if ($values['title'] === '' || mb_strlen($values['title']) > 220) $errors[] = 'Enter an event title of 220 characters or fewer.';
    $validCategoryIds = array_map(static fn($c) => (int) $c['id'], $categories);
    if (!ctype_digit($values['category_id']) || !in_array((int) $values['category_id'], $validCategoryIds, true)) $errors[] = 'Choose a valid event category.';
    if ($values['description'] === '') $errors[] = 'Enter an event description.';
    $dateFormat = 'Y-m-d\\TH:i';
    $startDateObj = DateTime::createFromFormat('!' . $dateFormat, $values['event_date']);
    if (!$startDateObj || $startDateObj->format($dateFormat) !== $values['event_date']) $errors[] = 'Choose a valid event start date and time.';
    $endDateObj = null;
    if ($values['event_end_date'] !== '') {
        $endDateObj = DateTime::createFromFormat('!' . $dateFormat, $values['event_end_date']);
        if (!$endDateObj || $endDateObj->format($dateFormat) !== $values['event_end_date']) $errors[] = 'Choose a valid event end date and time.';
        elseif ($startDateObj && $endDateObj < $startDateObj) $errors[] = 'The event end must be after the start.';
    }
    if ($values['venue'] === '' || mb_strlen($values['venue']) > 220) $errors[] = 'Enter a valid venue name.';
    if (mb_strlen($values['address']) > 300) $errors[] = 'The address must be 300 characters or fewer.';
    if ($values['city'] === '' || mb_strlen($values['city']) > 100) $errors[] = 'Enter a valid city.';
    if (!in_array($values['status'], ['draft','published','cancelled','completed'], true)) $errors[] = 'Choose a valid event status.';
    if (count($tickets) < 1 || count($tickets) > 30) $errors[] = 'Keep between 1 and 30 ticket types.';

    $existingTicketMap = [];
    foreach ($ticketRows as $row) $existingTicketMap[(string) $row['id']] = $row;
    foreach ($tickets as $i => $ticket) {
        $n = $i + 1;
        if ($ticket['name'] === '' || mb_strlen($ticket['name']) > 120) $errors[] = "Ticket {$n}: enter a valid ticket name.";
        if ($ticket['price'] === '' || !is_numeric($ticket['price']) || (float)$ticket['price'] < 0 || (float)$ticket['price'] > 999999999999) $errors[] = "Ticket {$n}: enter a valid non-negative price in XOF.";
        if ($ticket['quantity_total'] === '' || !ctype_digit($ticket['quantity_total']) || (int)$ticket['quantity_total'] < 1 || (int)$ticket['quantity_total'] > 2147483647) $errors[] = "Ticket {$n}: enter a quantity greater than zero.";
        if ($ticket['id'] !== '' && !isset($existingTicketMap[$ticket['id']])) $errors[] = "Ticket {$n}: invalid ticket record.";
        if ($ticket['id'] !== '' && isset($existingTicketMap[$ticket['id']]) && (int)$ticket['quantity_total'] < (int)$existingTicketMap[$ticket['id']]['quantity_sold']) $errors[] = "Ticket {$n}: quantity cannot be lower than the number already sold.";
        foreach (['sales_start','sales_end'] as $dateKey) {
            if ($ticket[$dateKey] !== '') {
                $dt = DateTime::createFromFormat('!' . $dateFormat, $ticket[$dateKey]);
                if (!$dt || $dt->format($dateFormat) !== $ticket[$dateKey]) $errors[] = "Ticket {$n}: invalid " . ($dateKey === 'sales_start' ? 'sales start' : 'sales end') . ' date.';
            }
        }
        if ($ticket['sales_start'] !== '' && $ticket['sales_end'] !== '' && strtotime($ticket['sales_end']) < strtotime($ticket['sales_start'])) $errors[] = "Ticket {$n}: sales must end after they start.";
    }

    $newImagePath = null;
    $uploadDestination = null;
    if (isset($_FILES['event_image']) && $_FILES['event_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['event_image'];
        if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'The banner upload failed. Please try again.';
        } elseif ((int) $file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The banner must be 5 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) $errors[] = 'Use a JPG, PNG, or WebP banner.';
            else {
                $uploadDir = dirname(__DIR__) . '/assets/uploads/events';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) $errors[] = 'Could not create the event banner folder.';
                elseif (!is_writable($uploadDir)) $errors[] = 'The event banner folder is not writable.';
                else {
                    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                    $newImagePath = 'assets/uploads/events/' . $filename;
                    $uploadDestination = $uploadDir . '/' . $filename;
                }
            }
        }
    }

    if (!$errors) {
        $imageMoved = false;
        try {
            $pdo->beginTransaction();
            $baseSlug = editEventSlug($values['title']);
            $slug = $baseSlug;
            $suffix = 2;
            $slugStmt = $pdo->prepare('SELECT id FROM events WHERE slug = :slug AND id <> :event_id LIMIT 1');
            while (true) {
                $slugStmt->execute(['slug'=>$slug, 'event_id'=>$eventId]);
                if (!$slugStmt->fetchColumn()) break;
                $slug = $baseSlug . '-' . $suffix++;
            }
            $imagePath = $newImagePath ?? ($event['image_url'] ?? null);
            $updateEvent = $pdo->prepare('UPDATE events SET category_id = :category_id, title = :title, slug = :slug, description = :description, event_date = :event_date, event_end_date = :event_end_date, venue = :venue, address = :address, city = :city, image_url = :image_url, status = :status WHERE id = :event_id AND organizer_id = :organizer_id');
            $updateEvent->execute([
                'category_id'=>(int)$values['category_id'], 'title'=>$values['title'], 'slug'=>$slug,
                'description'=>$values['description'],
                'event_date'=>str_replace('T',' ',$values['event_date']) . ':00',
                'event_end_date'=>$values['event_end_date'] !== '' ? str_replace('T',' ',$values['event_end_date']) . ':00' : null,
                'venue'=>$values['venue'], 'address'=>$values['address'] !== '' ? $values['address'] : null,
                'city'=>$values['city'], 'image_url'=>$imagePath, 'status'=>$values['status'],
                'event_id'=>$eventId, 'organizer_id'=>$organizerId,
            ]);

            $updateTicket = $pdo->prepare('UPDATE ticket_types SET name = :name, description = :description, price = :price, quantity_total = :quantity_total, sales_start = :sales_start, sales_end = :sales_end, is_active = :is_active WHERE id = :ticket_id AND event_id = :event_id');
            $insertTicket = $pdo->prepare('INSERT INTO ticket_types (event_id, name, description, price, quantity_total, quantity_sold, sales_start, sales_end, is_active) VALUES (:event_id, :name, :description, :price, :quantity_total, 0, :sales_start, :sales_end, :is_active)');
            foreach ($tickets as $ticket) {
                $data = [
                    'name'=>$ticket['name'], 'description'=>$ticket['description'] !== '' ? $ticket['description'] : null,
                    'price'=>(float)$ticket['price'], 'quantity_total'=>(int)$ticket['quantity_total'],
                    'sales_start'=>$ticket['sales_start'] !== '' ? str_replace('T',' ',$ticket['sales_start']) . ':00' : null,
                    'sales_end'=>$ticket['sales_end'] !== '' ? str_replace('T',' ',$ticket['sales_end']) . ':00' : null,
                    'is_active'=>(int)$ticket['is_active'],
                ];
                if ($ticket['id'] !== '') {
                    $data['ticket_id'] = (int)$ticket['id'];
                    $data['event_id'] = $eventId;
                    $updateTicket->execute($data);
                } else {
                    $data['event_id'] = $eventId;
                    $insertTicket->execute($data);
                }
            }
            if ($uploadDestination !== null) {
                if (!move_uploaded_file($_FILES['event_image']['tmp_name'], $uploadDestination)) throw new RuntimeException('Could not save the uploaded banner.');
                $imageMoved = true;
            }
            $pdo->commit();
            $_SESSION['edit_event_flash'] = 'Event updated successfully.';
            header('Location: events.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($imageMoved && $uploadDestination && is_file($uploadDestination)) @unlink($uploadDestination);
            error_log('TICKETA edit-event error: ' . $e->getMessage());
            $errors[] = 'The event could not be updated. Please check your details and try again.';
        }
    }
}

$csrfToken = authCsrfToken();
$imageUrl = trim((string) ($event['image_url'] ?? ''));
if ($imageUrl !== '' && !preg_match('~^(?:https?://|/)~i', $imageUrl)) $imageUrl = '../' . ltrim($imageUrl, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Event | TICKETA</title>
<style>
:root{--primary:#10c6a4;--primary-dark:#08ad91;--ink:#181a2a;--muted:#73778b;--line:#e8e9f1;--bg:#f7f8fc;--danger:#b42335}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input,select,textarea{font:inherit}.topbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;gap:15px;padding:17px 5%;background:#fff;border-bottom:1px solid var(--line)}.brand{font-size:25px;font-weight:850;color:var(--primary);text-decoration:none;letter-spacing:-1px}.brand span{color:var(--ink)}.top-actions{display:flex;gap:10px;align-items:center}.page{max-width:1000px;margin:auto;padding:30px 20px 60px}.back{color:var(--muted);text-decoration:none;font-size:14px}.heading{margin:22px 0}.heading h1{margin:0 0 7px;font-size:clamp(27px,4vw,35px);letter-spacing:-.8px}.heading p{margin:0;color:var(--muted);font-size:14px;line-height:1.6}.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;margin-bottom:20px;box-shadow:0 5px 22px #191d3c06}.card h2{margin:0 0 8px;font-size:20px}.card-intro{margin:0 0 23px;color:var(--muted);font-size:13px;line-height:1.6}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}.field{margin-bottom:17px;min-width:0}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:8px;font-size:13px;font-weight:700}.field input:not([type=checkbox]):not([type=file]),.field select,.field textarea{display:block;width:100%;min-height:44px;padding:11px 12px;border:1px solid #dfe1eb;border-radius:9px;background:#fff;color:var(--ink);outline:none}.field textarea{min-height:115px;resize:vertical;line-height:1.6}.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px #10c6a41a}.muted{font-size:12px;color:var(--muted);line-height:1.6}.banner{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.banner img{width:150px;height:100px;object-fit:cover;border-radius:9px;background:#f1f3f7}.banner input{max-width:100%;font-size:13px}.ticket{padding:18px;border:1px solid var(--line);border-radius:12px;margin:14px 0}.ticket h3{margin:0 0 17px;font-size:15px}.ticket small{display:block;margin-top:6px;color:var(--muted);font-size:12px}.check{display:flex;align-items:flex-start;gap:9px;font-size:13px;line-height:1.5}.check input{margin-top:3px}.notice{padding:13px 15px;border-radius:9px;background:#f0fcf9;color:#3e665f;font-size:13px;line-height:1.6;margin:14px 0}.errors{padding:14px 18px;border:1px solid #ffcaca;border-radius:10px;background:#fff1f1;color:#8f2020;margin:16px 0;font-size:13px;line-height:1.7}.actions{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:43px;padding:0 16px;border:1px solid #dfe1eb;border-radius:9px;background:#fff;color:#41445a;text-decoration:none;font-size:13px;font-weight:750;cursor:pointer}.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}.btn.primary:hover{background:var(--primary-dark)}.btn.danger{color:var(--danger)}.btn:focus-visible{outline:3px solid #10c6a455;outline-offset:2px}@media(max-width:650px){.page{padding:22px 13px 40px}.card{padding:17px}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.topbar{padding:14px 16px}.brand{font-size:22px}.actions .btn{flex:1}.banner img{width:115px;height:82px}}
</style>
</head>
<body>
<header class="topbar"><a class="brand" href="../index.php">TICKETA<span>.</span></a><div class="top-actions"><a class="btn" href="events.php">Back to events</a></div></header>
<main class="page">
<a class="back" href="events.php">← Back to Events</a>
<div class="heading"><h1>Edit event</h1><p>Update your event information and ticket settings. Changes are saved to your Ticketa account.</p></div>
<?php if ($errors): ?><div class="errors" role="alert"><strong>Please fix the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= editEventEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" novalidate>
<input type="hidden" name="csrf_token" value="<?= editEventEscape($csrfToken) ?>">
<section class="card"><h2>Event information</h2><p class="card-intro">Edit the event details shown to attendees.</p>
<div class="grid">
<div class="field full"><label for="title">Event name *</label><input id="title" name="title" maxlength="220" required value="<?= editEventEscape($values['title']) ?>"></div>
<div class="field"><label for="category_id">Category *</label><select id="category_id" name="category_id" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= (string)$category['id'] === $values['category_id'] ? 'selected' : '' ?>><?= editEventEscape($category['name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="status">Event status *</label><select id="status" name="status"><?php foreach (['draft'=>'Draft','published'=>'Published','cancelled'=>'Cancelled','completed'=>'Completed'] as $value=>$label): ?><option value="<?= $value ?>" <?= $values['status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="field full"><label for="description">Description *</label><textarea id="description" name="description" required><?= editEventEscape($values['description']) ?></textarea></div>
<div class="field"><label for="event_date">Start date and time *</label><input type="datetime-local" id="event_date" name="event_date" required value="<?= editEventEscape($values['event_date']) ?>"></div>
<div class="field"><label for="event_end_date">End date and time</label><input type="datetime-local" id="event_end_date" name="event_end_date" value="<?= editEventEscape($values['event_end_date']) ?>"></div>
<div class="field"><label for="venue">Venue name *</label><input id="venue" name="venue" maxlength="220" required value="<?= editEventEscape($values['venue']) ?>"></div>
<div class="field"><label for="city">City *</label><input id="city" name="city" maxlength="100" required value="<?= editEventEscape($values['city']) ?>"></div>
<div class="field full"><label for="address">Street address</label><input id="address" name="address" maxlength="300" value="<?= editEventEscape($values['address']) ?>"></div>
<div class="field full"><label for="event_image">Event banner (optional)</label><div class="banner"><?php if ($imageUrl !== ''): ?><img src="<?= editEventEscape($imageUrl) ?>" alt="Current event banner" onerror="this.style.display='none'"> <?php endif; ?><div><input type="file" id="event_image" name="event_image" accept="image/jpeg,image/png,image/webp"><p class="muted">JPG, PNG or WebP, maximum 5 MB. Leave empty to keep the current banner.</p></div></div></div>
</div></section>
<section class="card"><h2>Ticket types</h2><p class="card-intro">Edit ticket names, prices, quantities, and sales windows. The number of tickets already sold cannot be reduced.</p>
<?php foreach ($tickets as $i=>$ticket): ?><div class="ticket"><h3>Ticket type <?= $i+1 ?><?php if ((int)$ticket['id'] > 0): ?> · <?= number_format((int)$ticket['quantity_sold']) ?> sold<?php else: ?> · New ticket<?php endif; ?></h3>
<input type="hidden" name="ticket_id[]" value="<?= editEventEscape($ticket['id']) ?>">
<div class="grid"><div class="field"><label>Ticket name *</label><input name="ticket_name[]" maxlength="120" required value="<?= editEventEscape($ticket['name']) ?>"></div><div class="field"><label>Price (XOF) *</label><input type="number" name="ticket_price[]" min="0" step="1" required value="<?= editEventEscape($ticket['price']) ?>"></div><div class="field"><label>Total quantity *</label><input type="number" name="ticket_quantity[]" min="1" step="1" required value="<?= editEventEscape($ticket['quantity_total']) ?>"><small>Already sold: <?= number_format((int)$ticket['quantity_sold']) ?></small></div><div class="field"><label>Ticket status</label><label class="check"><input type="checkbox" name="ticket_active[<?= $i ?>]" value="1" <?= (int)$ticket['is_active'] === 1 ? 'checked' : '' ?>> Available for sale</label></div><div class="field full"><label>Ticket description</label><textarea name="ticket_description[]"><?= editEventEscape($ticket['description']) ?></textarea></div><div class="field"><label>Sales start</label><input type="datetime-local" name="ticket_sales_start[]" value="<?= editEventEscape($ticket['sales_start']) ?>"></div><div class="field"><label>Sales end</label><input type="datetime-local" name="ticket_sales_end[]" value="<?= editEventEscape($ticket['sales_end']) ?>"></div></div></div><?php endforeach; ?>
<div class="notice">To avoid accidentally deleting ticket records linked to past orders, this page edits existing ticket types and lets you add new ones. It does not delete existing ticket types.</div>
<div id="newTickets"></div><button type="button" class="btn" id="addTicket">+ Add ticket type</button>
</section>
<div class="actions"><a class="btn" href="events.php">Cancel</a><button class="btn primary" type="submit">Save changes</button></div>
</form>
</main>
<script>
(() => {
 const container = document.getElementById('newTickets');
 document.getElementById('addTicket').addEventListener('click', () => {
   if (document.querySelectorAll('.ticket').length >= 30) { alert('You can have up to 30 ticket types.'); return; }
   const card = document.createElement('div'); card.className = 'ticket';
   card.innerHTML = `<h3>New ticket type</h3><input type="hidden" name="ticket_id[]" value=""><div class="grid"><div class="field"><label>Ticket name *</label><input name="ticket_name[]" maxlength="120" required></div><div class="field"><label>Price (XOF) *</label><input type="number" name="ticket_price[]" min="0" step="1" value="0" required></div><div class="field"><label>Total quantity *</label><input type="number" name="ticket_quantity[]" min="1" step="1" value="50" required></div><div class="field"><label>Ticket status</label><label class="check"><input type="checkbox" name="ticket_active[NEW_INDEX]" value="1" checked> Available for sale</label></div><div class="field full"><label>Ticket description</label><textarea name="ticket_description[]"></textarea></div><div class="field"><label>Sales start</label><input type="datetime-local" name="ticket_sales_start[]"></div><div class="field"><label>Sales end</label><input type="datetime-local" name="ticket_sales_end[]"></div></div><button type="button" class="btn danger remove-new">Remove this new ticket</button>`;
   const index = document.querySelectorAll('.ticket').length;
   card.querySelector('[name="ticket_active[NEW_INDEX]"]').name = 'ticket_active[' + index + ']';
   container.appendChild(card);
 });
 container.addEventListener('click', event => { const button = event.target.closest('.remove-new'); if (button) button.closest('.ticket').remove(); });
})();
</script>
</body>
</html>
