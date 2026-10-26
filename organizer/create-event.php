<?php
// TICKETA - Create Event
// No strict_types declaration is needed for this page.

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (!authIsLoggedIn()) {
    $_SESSION['auth_return_to'] = 'organizer/create-event.php';
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];

if (($user['role'] ?? '') !== 'organizer') {
    http_response_code(403);
    exit('Access denied. Organizer accounts only.');
}

// Find the organizer profile belonging to the logged-in user.
$stmt = $pdo->prepare(
    'SELECT id, business_name
     FROM organizers
     WHERE user_id = :user_id
     LIMIT 1'
);
$stmt->execute(['user_id' => (int) $user['id']]);
$organizer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$organizer) {
    header('Location: onboarding.php');
    exit;
}

$organizerId = (int) $organizer['id'];
$businessName = (string) $organizer['business_name'];

// Load event categories.
$categories = $pdo->query(
    'SELECT id, name
     FROM categories
     WHERE is_active = 1
     ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function eventSlug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'event';
}

function validEventDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);

    return $date !== false && $date->format('Y-m-d\TH:i') === $value;
}

$values = [
    'title' => '',
    'category_id' => '',
    'description' => '',
    'event_type' => 'paid',
    'event_date' => '',
    'event_end_date' => '',
    'venue' => '',
    'address' => '',
    'city' => 'Abidjan',
    'visibility' => 'public',
];

$tickets = [[
    'name' => 'General Admission',
    'price' => '0',
    'quantity_total' => '50',
    'description' => '',
    'sales_start' => '',
    'sales_end' => '',
]];

$errors = [];

/*
|--------------------------------------------------------------------------
| Handle event submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    $eventType = $_POST['event_type'] ?? 'paid';
    $values['event_type'] = is_string($eventType) && in_array($eventType, ['free', 'paid'], true)
        ? $eventType
        : 'paid';

    $action = $_POST['action'] ?? 'draft';

    if (!in_array($action, ['draft', 'publish'], true)) {
        $action = 'draft';
    }

    $status = $action === 'publish' ? 'published' : 'draft';

    $names = $_POST['ticket_name'] ?? [];
    $prices = $_POST['ticket_price'] ?? [];
    $quantities = $_POST['ticket_quantity'] ?? [];
    $descriptions = $_POST['ticket_description'] ?? [];
    $salesStarts = $_POST['ticket_sales_start'] ?? [];
    $salesEnds = $_POST['ticket_sales_end'] ?? [];

    $tickets = [];

    if (is_array($names)) {
        foreach ($names as $i => $name) {
            $tickets[] = [
                'name' => is_string($name) ? trim($name) : '',
                'price' => is_array($prices) ? trim((string)($prices[$i] ?? '')) : '',
                'quantity_total' => is_array($quantities) ? trim((string)($quantities[$i] ?? '')) : '',
                'description' => is_array($descriptions) ? trim((string)($descriptions[$i] ?? '')) : '',
                'sales_start' => is_array($salesStarts) ? trim((string)($salesStarts[$i] ?? '')) : '',
                'sales_end' => is_array($salesEnds) ? trim((string)($salesEnds[$i] ?? '')) : '',
            ];
        }
    }

    // CSRF protection.
    if (!authVerifyCsrf()) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    }

    // Validate event details.
    if ($values['title'] === '' || mb_strlen($values['title']) > 220) {
        $errors[] = 'Enter an event title of 220 characters or fewer.';
    }

    $validCategoryIds = array_map(
        static fn($category) => (int)$category['id'],
        $categories
    );

    if (
        !ctype_digit($values['category_id'])
        || !in_array((int)$values['category_id'], $validCategoryIds, true)
    ) {
        $errors[] = 'Choose a valid event category.';
    }

    if ($values['description'] === '') {
        $errors[] = 'Enter an event description.';
    }

    if (!validEventDate($values['event_date'])) {
        $errors[] = 'Choose a valid event start date and time.';
    }

    if ($values['event_end_date'] !== '') {
        if (!validEventDate($values['event_end_date'])) {
            $errors[] = 'Choose a valid event end date and time.';
        } elseif (
            validEventDate($values['event_date'])
            && strtotime($values['event_end_date']) < strtotime($values['event_date'])
        ) {
            $errors[] = 'The event end must be after the start.';
        }
    }

    if ($values['venue'] === '' || mb_strlen($values['venue']) > 220) {
        $errors[] = 'Enter a valid venue name.';
    }

    if (mb_strlen($values['address']) > 300) {
        $errors[] = 'The address must be 300 characters or fewer.';
    }

    if ($values['city'] === '' || mb_strlen($values['city']) > 100) {
        $errors[] = 'Enter a valid city.';
    }

    if (!in_array($values['visibility'], ['public', 'private'], true)) {
        $values['visibility'] = 'public';
    }

    // Free events must never accept a positive ticket price, even if the
    // browser submits a modified form.
    if ($values['event_type'] === 'free') {
        foreach ($tickets as &$ticket) {
            $ticket['price'] = '0';
        }
        unset($ticket);
    }

    // Validate ticket types.
    if (count($tickets) < 1 || count($tickets) > 30) {
        $errors[] = 'Add between 1 and 30 ticket types.';
    }

    foreach ($tickets as $i => $ticket) {
        $number = $i + 1;

        if ($ticket['name'] === '' || mb_strlen($ticket['name']) > 120) {
            $errors[] = "Ticket {$number}: enter a valid ticket name.";
        }

        if (
            $ticket['price'] === ''
            || !ctype_digit($ticket['price'])
            || strlen($ticket['price']) > 19
        ) {
            $errors[] = "Ticket {$number}: enter a whole-number price in XOF.";
        }

        if (
            $ticket['quantity_total'] === ''
            || !ctype_digit($ticket['quantity_total'])
            || (int)$ticket['quantity_total'] < 1
            || (int)$ticket['quantity_total'] > 2147483647
        ) {
            $errors[] = "Ticket {$number}: enter a quantity greater than zero.";
        }

        if (
            $ticket['sales_start'] !== ''
            && !validEventDate($ticket['sales_start'])
        ) {
            $errors[] = "Ticket {$number}: invalid sales start date.";
        }

        if (
            $ticket['sales_end'] !== ''
            && !validEventDate($ticket['sales_end'])
        ) {
            $errors[] = "Ticket {$number}: invalid sales end date.";
        }

        if (
            validEventDate($ticket['sales_start'])
            && validEventDate($ticket['sales_end'])
            && strtotime($ticket['sales_end']) < strtotime($ticket['sales_start'])
        ) {
            $errors[] = "Ticket {$number}: sales must end after they start.";
        }
    }

    // Validate optional banner upload.
    $imagePath = null;
    $tempImage = null;
    $destination = null;
    $imageMoved = false;

    if (
        isset($_FILES['event_image'])
        && $_FILES['event_image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $file = $_FILES['event_image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The banner upload failed.';
        } elseif (
            !isset($file['tmp_name'], $file['size'])
            || !is_uploaded_file($file['tmp_name'])
        ) {
            $errors[] = 'The uploaded banner is invalid.';
        } elseif ((int)$file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The banner must be 5 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowed[$mime])) {
                $errors[] = 'Use a JPG, PNG, or WebP banner.';
            } else {
                $directory = dirname(__DIR__) . '/assets/uploads/events';

                if (
                    !is_dir($directory)
                    && !mkdir($directory, 0755, true)
                    && !is_dir($directory)
                ) {
                    $errors[] = 'Could not create the banner upload directory.';
                } elseif (!is_writable($directory)) {
                    $errors[] = 'The banner directory is not writable.';
                } else {
                    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                    $imagePath = 'assets/uploads/events/' . $filename;
                    $tempImage = $file['tmp_name'];
                    $destination = $directory . '/' . $filename;
                }
            }
        }
    }

    /*
     * Save event and tickets in one database transaction.
     */
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $baseSlug = eventSlug($values['title']);
            $slug = $baseSlug;
            $suffix = 2;

            $slugStmt = $pdo->prepare(
                'SELECT id FROM events WHERE slug = :slug LIMIT 1'
            );

            while (true) {
                $slugStmt->execute(['slug' => $slug]);

                if (!$slugStmt->fetchColumn()) {
                    break;
                }

                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }

            $startDate = str_replace('T', ' ', $values['event_date']) . ':00';

            $endDate = $values['event_end_date'] !== ''
                ? str_replace('T', ' ', $values['event_end_date']) . ':00'
                : null;

            // Visibility is not persisted because the confirmed events schema
            // does not include a visibility column.
            $eventStmt = $pdo->prepare(
                'INSERT INTO events (
                    organizer_id, category_id, title, slug, description, event_type,
                    event_date, event_end_date, venue, address, city,
                    country_code, image_url, status
                ) VALUES (
                    :organizer_id, :category_id, :title, :slug, :description, :event_type,
                    :event_date, :event_end_date, :venue, :address, :city,
                    :country_code, :image_url, :status
                )'
            );

            $eventStmt->execute([
                'organizer_id' => $organizerId,
                'category_id' => (int)$values['category_id'],
                'title' => $values['title'],
                'slug' => $slug,
                'description' => $values['description'],
                'event_type' => $values['event_type'],
                'event_date' => $startDate,
                'event_end_date' => $endDate,
                'venue' => $values['venue'],
                'address' => $values['address'] !== '' ? $values['address'] : null,
                'city' => $values['city'],
                'country_code' => 'CI',
                'image_url' => $imagePath,
                'status' => $status,
            ]);

            $eventId = (int)$pdo->lastInsertId();

            $ticketStmt = $pdo->prepare(
                'INSERT INTO ticket_types (
                    event_id, name, description, price, quantity_total,
                    quantity_sold, sales_start, sales_end, is_active
                ) VALUES (
                    :event_id, :name, :description, :price, :quantity_total,
                    0, :sales_start, :sales_end, 1
                )'
            );

            foreach ($tickets as $ticket) {
                $ticketStmt->execute([
                    'event_id' => $eventId,
                    'name' => $ticket['name'],
                    'description' => $ticket['description'] !== ''
                        ? $ticket['description']
                        : null,
                    'price' => $ticket['price'],
                    'quantity_total' => (int)$ticket['quantity_total'],
                    'sales_start' => $ticket['sales_start'] !== ''
                        ? str_replace('T', ' ', $ticket['sales_start']) . ':00'
                        : null,
                    'sales_end' => $ticket['sales_end'] !== ''
                        ? str_replace('T', ' ', $ticket['sales_end']) . ':00'
                        : null,
                ]);
            }

            // Move the validated image before committing.
            if ($destination !== null && $tempImage !== null) {
                if (!move_uploaded_file($tempImage, $destination)) {
                    throw new RuntimeException('Could not save the event banner.');
                }

                $imageMoved = true;
            }

            $pdo->commit();

            $_SESSION['create_event_flash'] = $status === 'published'
                ? 'Your event has been published successfully.'
                : 'Your event draft has been saved successfully.';

            header('Location: events.php');
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($imageMoved && $destination !== null && is_file($destination)) {
                @unlink($destination);
            }

            error_log('TICKETA create-event error: ' . $exception->getMessage());

            $errors[] = 'The event could not be saved. Check the details and try again.';
        }
    }
}

$csrfToken = authCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Event | TICKETA</title>

<style>
:root {
    --primary:#10c6a4;
    --primary-dark:#08ad91;
    --ink:#181a2a;
    --muted:#73778b;
    --line:#e8e9f1;
    --background:#f7f8fc;
}
* { box-sizing:border-box; }
body {
    margin:0;
    background:var(--background);
    color:var(--ink);
    font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
button,input,select,textarea { font:inherit; }
button { cursor:pointer; }
.topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    min-height:72px;
    padding:15px 5%;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    background:#fff;
    border-bottom:1px solid var(--line);
}
.brand {
    color:var(--primary);
    font-size:25px;
    font-weight:850;
    text-decoration:none;
    letter-spacing:-1px;
}
.brand span { color:var(--ink); }
.top-right { display:flex; align-items:center; gap:12px; color:var(--muted); font-size:13px; }
.avatar {
    width:38px;height:38px;border-radius:50%;
    display:grid;place-items:center;
    background:#e5faf4;color:var(--primary);font-weight:800;
}
.page { max-width:1120px; margin:auto; padding:32px 22px 60px; }
.back { color:var(--muted); font-size:14px; text-decoration:none; }
.heading { margin:22px 0 25px; }
.heading h1 { margin:0 0 8px; font-size:clamp(27px,4vw,35px); letter-spacing:-1px; }
.heading p { margin:0; color:var(--muted); font-size:14px; line-height:1.7; }
.steps { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:22px; }
.step {
    display:flex; align-items:center; gap:10px; min-width:0;
    padding:13px 10px; border:1px solid var(--line);
    border-radius:12px; background:#fff; color:var(--muted);
}
.step-number {
    width:30px;height:30px;flex:0 0 30px;
    display:grid;place-items:center;border-radius:50%;
    background:#f0f1f7;font-size:13px;font-weight:800;
}
.step strong { display:block; font-size:12px; }
.step small { display:block; margin-top:4px; font-size:10px; }
.step.active { color:var(--primary); border-color:#a9e9dc; background:#edfaf7; }
.step.active .step-number,.step.done .step-number { background:var(--primary); color:#fff; }
.layout { display:grid; grid-template-columns:minmax(0,1fr) 260px; gap:22px; align-items:start; }
.card {
    min-width:0;padding:25px;border:1px solid var(--line);
    border-radius:14px;background:#fff;box-shadow:0 5px 22px #191d3c06;
}
.panel { display:none; }
.panel.active { display:block; }
.section-title { margin:0 0 7px; font-size:21px; }
.section-description { margin:0 0 25px; color:var(--muted); font-size:13px; line-height:1.7; }
.field { margin-bottom:19px; }
.field label { display:block; margin-bottom:8px; font-size:13px; font-weight:700; }
.required { color:#df4560; }
.field input:not([type=file]):not([type=checkbox]),.field select,.field textarea {
    display:block;width:100%;min-height:45px;padding:12px;
    border:1px solid #dfe1eb;border-radius:9px;background:#fff;color:var(--ink);outline:none;
}
.field textarea { min-height:110px; resize:vertical; line-height:1.6; }
.field input:focus,.field select:focus,.field textarea:focus {
    border-color:var(--primary);box-shadow:0 0 0 3px #10c6a41a;
}
.hint { margin:7px 0 0; color:var(--muted); font-size:12px; line-height:1.6; }
.two-col { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px; }
.upload {
    display:block;padding:24px 15px;border:1.5px dashed #c9cce0;
    border-radius:12px;background:#fafaff;text-align:center;cursor:pointer;
}
.upload strong { display:block;margin-bottom:6px; }
.upload span { color:var(--muted);font-size:12px; }
.upload input { display:block;width:100%;margin-top:15px; }
.preview { display:none;max-width:100%;max-height:220px;margin:15px auto 0;border-radius:9px; }
.ticket-card { margin-bottom:15px;padding:18px;border:1px solid var(--line);border-radius:12px; }
.ticket-header { display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:18px; }
.ticket-header h3 { margin:0;font-size:15px; }
.remove-ticket { border:0;background:transparent;color:#c23b50;font-size:12px;font-weight:700; }
.add-ticket {
    width:100%;min-height:45px;border:1px dashed #8be0d0;
    border-radius:9px;background:#f0fcf9;color:var(--primary);font-weight:750;
}
.notice {
    margin-bottom:18px;padding:14px 15px;border:1px solid #ccefe8;
    border-radius:10px;background:#f0fcf9;color:#4b4d67;font-size:13px;line-height:1.7;
}
.notice.error { border-color:#ffd4d4;background:#fff0f0;color:#8f2020; }
.payment-option { margin-bottom:12px;padding:17px;border:1px solid var(--line);border-radius:11px; }
.payment-option strong { display:block;margin-bottom:7px; }
.payment-option p { margin:0;color:var(--muted);font-size:12px;line-height:1.7; }
.check-row { display:flex;align-items:flex-start;gap:10px;margin:15px 0;color:#505469;font-size:13px;line-height:1.6; }
.check-row input { margin-top:4px; }
.actions {
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;
    margin-top:27px;padding-top:21px;border-top:1px solid var(--line);
}
.button {
    display:inline-flex;align-items:center;justify-content:center;min-height:44px;
    padding:0 16px;border:1px solid transparent;border-radius:9px;
    font-size:13px;font-weight:750;text-decoration:none;
}
.button.primary { background:var(--primary);color:#fff; }
.button.primary:hover { background:var(--primary-dark); }
.button.secondary { background:#fff;color:#41445a;border-color:#dfe1eb; }
.sidebar .card { margin-bottom:15px;padding:20px; }
.sidebar h3 { margin:0 0 12px;font-size:15px; }
.sidebar p,.sidebar ul { color:var(--muted);font-size:12px;line-height:1.9; }
.sidebar p { margin:0; }
.sidebar ul { margin:0;padding-left:18px; }
.summary-row { display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--line);font-size:12px; }
.summary-row span { color:var(--muted); }
.summary-row strong { max-width:65%;text-align:right;overflow-wrap:anywhere; }
@media(max-width:850px) {
    .layout { grid-template-columns:1fr; }
    .sidebar { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px; }
    .sidebar .card { margin-bottom:0; }
}
@media(max-width:600px) {
    .topbar { padding:14px 17px; }
    .business-name { display:none; }
    .page { padding:22px 13px 40px; }
    .steps { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .step { padding:11px 9px; }
    .card { padding:18px 14px; }
    .two-col,.sidebar { grid-template-columns:1fr;gap:0; }
    .actions .button { flex:1; }
}
</style>
</head>

<body>
<header class="topbar">
    <a href="../index.php" class="brand">TICKETA<span>.</span></a>
    <div class="top-right">
        <span class="business-name"><?= h($businessName) ?></span>
        <div class="avatar"><?= h(strtoupper(substr($businessName, 0, 1))) ?></div>
    </div>
</header>

<main class="page">
    <a href="events.php" class="back">← Back to My Events</a>

    <div class="heading">
        <h1>Create a new event</h1>
        <p>Set up your event, configure tickets, and prepare for your audience.</p>
    </div>

    <?php if ($errors): ?>
        <div class="notice error" role="alert">
            <?= h(implode(' ', $errors)) ?>
        </div>
    <?php endif; ?>

    <div class="steps">
        <div class="step active" data-step-indicator="0"><div class="step-number">1</div><div><strong>Event details</strong><small>Basic information</small></div></div>
        <div class="step" data-step-indicator="1"><div class="step-number">2</div><div><strong>Tickets</strong><small>Price and quantity</small></div></div>
        <div class="step" data-step-indicator="2"><div class="step-number">3</div><div><strong>Payments</strong><small>Payment readiness</small></div></div>
        <div class="step" data-step-indicator="3"><div class="step-number">4</div><div><strong>Review</strong><small>Save or publish</small></div></div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="eventForm">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div class="layout">
            <div class="card">

                <section class="panel active" data-panel="0">
                    <h2 class="section-title">Event details</h2>
                    <p class="section-description">Tell your audience what your event is about.</p>

                    <div class="field">
                        <label for="title">Event name <span class="required">*</span></label>
                        <input id="title" name="title" maxlength="220" required value="<?= h($values['title']) ?>" placeholder="e.g. Abidjan Tech Summit">
                    </div>

                    <div class="field">
                        <label for="category_id">Category <span class="required">*</span></label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int)$category['id'] ?>" <?= (string)$category['id'] === $values['category_id'] ? 'selected' : '' ?>>
                                    <?= h($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="description">Event description <span class="required">*</span></label>
                        <textarea id="description" name="description" required placeholder="Describe your event and what attendees can expect."><?= h($values['description']) ?></textarea>
                    </div>

                    <div class="field">
                        <label for="event_type">Event type <span class="required">*</span></label>
                        <select id="event_type" name="event_type" required>
                            <option value="paid" <?= $values['event_type'] === 'paid' ? 'selected' : '' ?>>Paid event — attendees purchase tickets</option>
                            <option value="free" <?= $values['event_type'] === 'free' ? 'selected' : '' ?>>Free event — attendees register at no cost</option>
                        </select>
                        <p class="hint">Free events skip payment readiness. Paid events keep the existing payment preparation step.</p>
                    </div>

                    <div class="two-col">
                        <div class="field">
                            <label for="event_date">Start date and time <span class="required">*</span></label>
                            <input type="datetime-local" id="event_date" name="event_date" required value="<?= h($values['event_date']) ?>">
                        </div>
                        <div class="field">
                            <label for="event_end_date">End date and time</label>
                            <input type="datetime-local" id="event_end_date" name="event_end_date" value="<?= h($values['event_end_date']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="venue">Venue name <span class="required">*</span></label>
                        <input id="venue" name="venue" maxlength="220" required value="<?= h($values['venue']) ?>" placeholder="e.g. Palais de la Culture">
                    </div>

                    <div class="field">
                        <label for="address">Street address</label>
                        <input id="address" name="address" maxlength="300" value="<?= h($values['address']) ?>" placeholder="Enter the venue address">
                    </div>

                    <div class="field">
                        <label for="city">City <span class="required">*</span></label>
                        <input id="city" name="city" maxlength="100" required value="<?= h($values['city']) ?>">
                    </div>

                    <div class="field">
                        <label for="event_image">Event banner</label>
                        <label class="upload" for="event_image">
                            <strong>Choose your event image</strong>
                            <span>JPG, PNG or WebP · Maximum 5 MB</span>
                            <input type="file" id="event_image" name="event_image" accept="image/jpeg,image/png,image/webp">
                            <img id="imagePreview" class="preview" alt="Event banner preview">
                        </label>
                    </div>

                    <div class="actions">
                        <a href="events.php" class="button secondary">Cancel</a>
                        <button type="button" class="button primary next-button">Continue to tickets →</button>
                    </div>
                </section>

                <section class="panel" data-panel="1">
                    <h2 class="section-title">Ticket types</h2>
                    <p class="section-description">Set your ticket prices in CFA francs (XOF).</p>

                    <div id="ticketContainer">
                        <?php foreach ($tickets as $i => $ticket): ?>
                            <div class="ticket-card" data-ticket>
                                <div class="ticket-header">
                                    <h3>Ticket type <span class="ticket-number"><?= $i + 1 ?></span></h3>
                                    <button type="button" class="remove-ticket">Remove</button>
                                </div>

                                <div class="field">
                                    <label>Ticket name <span class="required">*</span></label>
                                    <input name="ticket_name[]" maxlength="120" required value="<?= h($ticket['name']) ?>" placeholder="e.g. Early Bird">
                                </div>

                                <div class="two-col">
                                    <div class="field">
                                        <label>Price (XOF) <span class="required">*</span></label>
                                        <input type="number" name="ticket_price[]" min="0" step="1" required value="<?= h($ticket['price']) ?>">
                                        <p class="hint">Enter 0 for a free ticket.</p>
                                    </div>
                                    <div class="field">
                                        <label>Available quantity <span class="required">*</span></label>
                                        <input type="number" name="ticket_quantity[]" min="1" step="1" required value="<?= h($ticket['quantity_total']) ?>">
                                    </div>
                                </div>

                                <div class="field">
                                    <label>Ticket description</label>
                                    <textarea name="ticket_description[]" maxlength="5000" placeholder="What does this ticket include?"><?= h($ticket['description']) ?></textarea>
                                </div>

                                <div class="two-col">
                                    <div class="field">
                                        <label>Sales start</label>
                                        <input type="datetime-local" name="ticket_sales_start[]" value="<?= h($ticket['sales_start']) ?>">
                                    </div>
                                    <div class="field">
                                        <label>Sales end</label>
                                        <input type="datetime-local" name="ticket_sales_end[]" value="<?= h($ticket['sales_end']) ?>">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="addTicket" class="add-ticket">+ Add another ticket type</button>

                    <div class="actions">
                        <button type="button" class="button secondary back-button">← Back</button>
                        <button type="button" class="button primary next-button">Continue to payments →</button>
                    </div>
                </section>

                <section class="panel" data-panel="2">
                    <h2 class="section-title">Payment readiness</h2>
                    <p class="section-description">Prepare payment processing before accepting ticket orders.</p>

                    <div class="notice">
                        This is not a live payment integration. Your current database schema does not include event-level payment settings.
                    </div>

                    <div class="payment-option">
                        <strong>Mobile Money — Côte d'Ivoire</strong>
                        <p>Configure a payment provider and merchant account. Confirm supported operators, fees, and settlement terms before enabling payments.</p>
                    </div>

                    <div class="payment-option">
                        <strong>Card payments</strong>
                        <p>Enable only after server-side payment verification and successful test transactions.</p>
                    </div>

                    <div class="notice">
                        Never mark an order as paid based only on a browser redirect. Verify payment securely on the server.
                    </div>

                    <label class="check-row">
                        <input type="checkbox" id="paymentAcknowledged">
                        <span>I understand that payment processing must be configured and tested before accepting paid orders.</span>
                    </label>

                    <div class="actions">
                        <button type="button" class="button secondary back-button">← Back</button>
                        <button type="button" class="button primary next-button">Review event →</button>
                    </div>
                </section>

                <section class="panel" data-panel="3">
                    <h2 class="section-title">Review your event</h2>
                    <p class="section-description">Check your event information before saving.</p>

                    <div class="summary-row"><span>Event name</span><strong id="summaryTitle">—</strong></div>
                    <div class="summary-row"><span>Event type</span><strong id="summaryEventType">Paid event</strong></div>
                    <div class="summary-row"><span>Category</span><strong id="summaryCategory">—</strong></div>
                    <div class="summary-row"><span>Start date</span><strong id="summaryDate">—</strong></div>
                    <div class="summary-row"><span>Venue</span><strong id="summaryVenue">—</strong></div>
                    <div class="summary-row"><span>City</span><strong id="summaryCity">—</strong></div>
                    <div class="summary-row"><span>Ticket types</span><strong id="summaryTicketCount">1</strong></div>
                    <div class="summary-row"><span>Total inventory</span><strong id="summaryQuantity">50</strong></div>

                    <div class="field" style="margin-top:24px">
                        <label for="visibility">Intended event visibility</label>
                        <select id="visibility" name="visibility">
                            <option value="public" <?= $values['visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                            <option value="private" <?= $values['visibility'] === 'private' ? 'selected' : '' ?>>Private</option>
                        </select>
                        <p class="hint">Visibility is not enforced because the confirmed events table has no visibility column.</p>
                    </div>

                    <div class="notice">
                        Save draft creates an unpublished event. Publish event saves it with published status. Payment processing is not activated by publishing.
                    </div>

                    <label class="check-row">
                        <input type="checkbox" id="confirmDetails">
                        <span>I have reviewed the event details and ticket quantities.</span>
                    </label>

                    <div class="actions">
                        <button type="button" class="button secondary back-button">← Back</button>
                        <button type="submit" name="action" value="draft" class="button secondary">Save draft</button>
                        <button type="submit" name="action" value="publish" class="button primary">Publish event</button>
                    </div>
                </section>
            </div>

            <aside class="sidebar">
                <div class="card">
                    <h3>Event creation tips</h3>
                    <ul>
                        <li>Choose a clear event title.</li>
                        <li>Enter the correct venue and date.</li>
                        <li>Describe what attendees will experience.</li>
                        <li>Set realistic ticket quantities.</li>
                        <li>Test payment processing separately.</li>
                    </ul>
                </div>

                <div class="card">
                    <h3>Your organizer profile</h3>
                    <div class="summary-row"><span>Organizer</span><strong><?= h($businessName) ?></strong></div>
                    <p style="margin-top:12px">Your event will be linked to this organizer profile.</p>
                </div>

                <div class="card">
                    <h3>Need more time?</h3>
                    <p>Save your event as a draft while you prepare the listing.</p>
                </div>
            </aside>
        </div>
    </form>
</main>

<script>
(() => {
    const form = document.getElementById('eventForm');
    const panels = [...document.querySelectorAll('[data-panel]')];
    const indicators = [...document.querySelectorAll('[data-step-indicator]')];
    const ticketContainer = document.getElementById('ticketContainer');
    const eventTypeSelect = document.getElementById('event_type');
    const paymentIndicator = document.querySelector('[data-step-indicator="2"]');
    const paymentAcknowledged = document.getElementById('paymentAcknowledged');

    let currentStep = 0;
    let previewUrl = null;

    function showStep(index) {
        currentStep = Math.max(0, Math.min(index, panels.length - 1));

        panels.forEach((panel, i) => panel.classList.toggle('active', i === currentStep));

        indicators.forEach((indicator, i) => {
            const skippedPayment = eventTypeSelect.value === 'free' && i === 2;
            indicator.style.display = skippedPayment ? 'none' : '';
            indicator.classList.toggle('active', i === currentStep);
            indicator.classList.toggle('done', i < currentStep || (eventTypeSelect.value === 'free' && i === 2 && currentStep === 3));
        });

        if (currentStep === 3) updateSummary();

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function validatePanel(index) {
        for (const control of panels[index].querySelectorAll('input, select, textarea')) {
            if (
                control.disabled ||
                ['file', 'hidden', 'checkbox'].includes(control.type)
            ) continue;

            if (!control.checkValidity()) {
                control.reportValidity();
                control.focus();
                return false;
            }
        }

        return true;
    }

    document.querySelectorAll('.next-button').forEach(button => {
        button.addEventListener('click', () => {
            if (!validatePanel(currentStep)) return;

            if (
                currentStep === 2 &&
                !document.getElementById('paymentAcknowledged').checked
            ) {
                alert('Please acknowledge the payment setup requirements.');
                return;
            }

            if (currentStep === 1 && eventTypeSelect.value === 'free') {
                showStep(3);
            } else {
                showStep(currentStep + 1);
            }
        });
    });

    document.querySelectorAll('.back-button').forEach(button => {
        button.addEventListener('click', () => showStep(currentStep - 1));
    });

    function syncEventType() {
        const isFree = eventTypeSelect.value === 'free';

        ticketContainer.querySelectorAll('[name="ticket_price[]"]').forEach(input => {
            if (isFree) {
                if (!input.readOnly && input.value !== '0') {
                    input.dataset.previousPaidPrice = input.value;
                }
                input.value = '0';
                input.readOnly = true;
                input.setAttribute('aria-label', 'Price (XOF), zero for a free event');
            } else {
                input.readOnly = false;
                if (input.dataset.previousPaidPrice !== undefined) {
                    input.value = input.dataset.previousPaidPrice;
                    delete input.dataset.previousPaidPrice;
                }
                input.removeAttribute('aria-label');
            }
        });

        document.querySelectorAll('[data-panel="1"] .section-description')[0].textContent =
            isFree
                ? 'This is a free event. Ticket prices are set to 0 XOF; configure ticket quantities and registration limits.'
                : 'Set your ticket prices in CFA francs (XOF).';

        document.querySelector('[data-panel="1"] .next-button').textContent =
            isFree ? 'Continue to review →' : 'Continue to payments →';

        paymentIndicator.style.display = isFree ? 'none' : '';
        if (isFree) paymentAcknowledged.checked = false;

        if (currentStep === 2 && isFree) showStep(3);
        if (currentStep === 3) updateSummary();
    }

    eventTypeSelect.addEventListener('change', syncEventType);

    function updateTicketNumbers() {
        const cards = [...ticketContainer.querySelectorAll('[data-ticket]')];

        cards.forEach((card, index) => {
            card.querySelector('.ticket-number').textContent = index + 1;
            card.querySelector('.remove-ticket').style.visibility =
                cards.length > 1 ? 'visible' : 'hidden';
        });
    }

    document.getElementById('addTicket').addEventListener('click', () => {
        if (ticketContainer.querySelectorAll('[data-ticket]').length >= 30) {
            alert('You can add a maximum of 30 ticket types.');
            return;
        }

        const card = document.createElement('div');
        card.className = 'ticket-card';
        card.setAttribute('data-ticket', '');

        card.innerHTML = `
            <div class="ticket-header">
                <h3>Ticket type <span class="ticket-number"></span></h3>
                <button type="button" class="remove-ticket">Remove</button>
            </div>
            <div class="field">
                <label>Ticket name <span class="required">*</span></label>
                <input name="ticket_name[]" maxlength="120" required placeholder="e.g. VIP">
            </div>
            <div class="two-col">
                <div class="field">
                    <label>Price (XOF) <span class="required">*</span></label>
                    <input type="number" name="ticket_price[]" min="0" step="1" value="0" required>
                    <p class="hint">Enter 0 for a free ticket.</p>
                </div>
                <div class="field">
                    <label>Available quantity <span class="required">*</span></label>
                    <input type="number" name="ticket_quantity[]" min="1" step="1" value="50" required>
                </div>
            </div>
            <div class="field">
                <label>Ticket description</label>
                <textarea name="ticket_description[]" maxlength="5000" placeholder="What does this ticket include?"></textarea>
            </div>
            <div class="two-col">
                <div class="field">
                    <label>Sales start</label>
                    <input type="datetime-local" name="ticket_sales_start[]">
                </div>
                <div class="field">
                    <label>Sales end</label>
                    <input type="datetime-local" name="ticket_sales_end[]">
                </div>
            </div>
        `;

        ticketContainer.appendChild(card);
        updateTicketNumbers();
        syncEventType();
        card.querySelector('[name="ticket_name[]"]').focus();
    });

    ticketContainer.addEventListener('click', event => {
        const button = event.target.closest('.remove-ticket');
        if (!button) return;

        if (ticketContainer.querySelectorAll('[data-ticket]').length <= 1) return;

        button.closest('[data-ticket]').remove();
        updateTicketNumbers();
    });

    function updateSummary() {
        const category = document.getElementById('category_id');

        document.getElementById('summaryTitle').textContent =
            document.getElementById('title').value.trim() || 'Not entered';

        document.getElementById('summaryEventType').textContent =
            eventTypeSelect.value === 'free' ? 'Free event' : 'Paid event';

        document.getElementById('summaryCategory').textContent =
            category.selectedOptions[0]?.textContent || 'Not selected';

        document.getElementById('summaryDate').textContent =
            document.getElementById('event_date').value.replace('T', ' ') || 'Not selected';

        document.getElementById('summaryVenue').textContent =
            document.getElementById('venue').value.trim() || 'Not entered';

        document.getElementById('summaryCity').textContent =
            document.getElementById('city').value.trim() || 'Not entered';

        const cards = [...ticketContainer.querySelectorAll('[data-ticket]')];

        const quantity = cards.reduce((total, card) => {
            return total + (Number(
                card.querySelector('[name="ticket_quantity[]"]').value
            ) || 0);
        }, 0);

        document.getElementById('summaryTicketCount').textContent = cards.length;
        document.getElementById('summaryQuantity').textContent = quantity.toLocaleString();
    }

    document.getElementById('event_image').addEventListener('change', event => {
        const file = event.target.files[0];
        const preview = document.getElementById('imagePreview');

        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        preview.style.display = 'none';

        if (!file) return;

        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            alert('Choose a JPG, PNG, or WebP image.');
            event.target.value = '';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('The banner must be 5 MB or smaller.');
            event.target.value = '';
            return;
        }

        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
        preview.style.display = 'block';
    });

    form.addEventListener('submit', event => {
        if (currentStep !== 3) {
            event.preventDefault();
            showStep(0);
            return;
        }

        if (!validatePanel(0) || !validatePanel(1)) {
            event.preventDefault();
            return;
        }

        if (!document.getElementById('confirmDetails').checked) {
            event.preventDefault();
            alert('Please confirm that you reviewed the event details.');
            return;
        }

        if (
            eventTypeSelect.value === 'paid' &&
            !paymentAcknowledged.checked
        ) {
            event.preventDefault();
            alert('Please acknowledge the payment setup requirements.');
        }
    });

    updateTicketNumbers();
    syncEventType();
})();
</script>
</body>
</html>
