
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

$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

$currentPage = 'default-event-settings.php';
$topbarSearchPlaceholder = 'Search event settings';

$successMessage = '';
$errorMessage = '';

$defaults = [
    'default_visibility' => 'public',
    'default_location_type' => 'physical',
    'default_timezone' => 'Africa/Abidjan',
    'default_ticket_type' => 'paid',
    'default_ticket_quantity' => 100,
    'sales_start_enabled' => 1,
    'event_published_enabled' => 1,
    'custom_date_enabled' => 1,
    'default_checkin_method' => 'qr',
    'allow_reentry' => 0,
    'auto_close_checkin' => 0,
    'refund_window_hours' => 48,
    'auto_approve_refunds' => 0,
    'allow_partial_refunds' => 0,
];

$settings = $defaults;

function defaultEventEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function defaultEventCheckbox($value): bool
{
    return (int) $value === 1;
}

try {
    /*
    |--------------------------------------------------------------------------
    | Create the organizer's default row if it doesn't exist.
    |--------------------------------------------------------------------------
    */

    $insertDefaults = $pdo->prepare(
        'INSERT IGNORE INTO organizer_event_defaults (user_id)
         VALUES (:user_id)'
    );

    $insertDefaults->execute([
        ':user_id' => $userId,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Save or reset preferences
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!authVerifyCsrf()) {
            $errorMessage =
                'Your session has expired. Refresh the page and try again.';
        } else {
            $action = $_POST['action'] ?? 'save';

            if ($action === 'reset') {
                $resetSql = '
                    UPDATE organizer_event_defaults
                    SET
                        default_visibility = :visibility,
                        default_location_type = :location_type,
                        default_timezone = :timezone,
                        default_ticket_type = :ticket_type,
                        default_ticket_quantity = :quantity,
                        sales_start_enabled = :sales_start,
                        event_published_enabled = :published,
                        custom_date_enabled = :custom_date,
                        default_checkin_method = :checkin,
                        allow_reentry = :reentry,
                        auto_close_checkin = :auto_close,
                        refund_window_hours = :refund_window,
                        auto_approve_refunds = :auto_approve,
                        allow_partial_refunds = :partial_refunds
                    WHERE user_id = :user_id
                ';

                $resetStatement = $pdo->prepare($resetSql);

                $resetStatement->execute([
                    ':visibility' => $defaults['default_visibility'],
                    ':location_type' => $defaults['default_location_type'],
                    ':timezone' => $defaults['default_timezone'],
                    ':ticket_type' => $defaults['default_ticket_type'],
                    ':quantity' => $defaults['default_ticket_quantity'],
                    ':sales_start' => $defaults['sales_start_enabled'],
                    ':published' => $defaults['event_published_enabled'],
                    ':custom_date' => $defaults['custom_date_enabled'],
                    ':checkin' => $defaults['default_checkin_method'],
                    ':reentry' => $defaults['allow_reentry'],
                    ':auto_close' => $defaults['auto_close_checkin'],
                    ':refund_window' => $defaults['refund_window_hours'],
                    ':auto_approve' => $defaults['auto_approve_refunds'],
                    ':partial_refunds' => $defaults['allow_partial_refunds'],
                    ':user_id' => $userId,
                ]);

                header(
                    'Location: default-event-settings.php?saved=reset'
                );
                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate submitted settings
            |--------------------------------------------------------------------------
            */

            $visibility = $_POST['default_visibility'] ?? '';
            $locationType = $_POST['default_location_type'] ?? '';
            $timezone = $_POST['default_timezone'] ?? '';
            $ticketType = $_POST['default_ticket_type'] ?? '';
            $quantity = filter_var(
                $_POST['default_ticket_quantity'] ?? null,
                FILTER_VALIDATE_INT
            );
            $checkinMethod = $_POST['default_checkin_method'] ?? '';
            $refundWindow = filter_var(
                $_POST['refund_window_hours'] ?? null,
                FILTER_VALIDATE_INT
            );

            $allowedTimezones = [
                'Africa/Abidjan',
                'Africa/Accra',
                'Africa/Lagos',
                'Europe/London',
                'Europe/Paris',
                'America/New_York',
            ];

            $valid =
                in_array($visibility, ['public', 'private'], true)
                && in_array(
                    $locationType,
                    ['physical', 'online', 'hybrid'],
                    true
                )
                && in_array($timezone, $allowedTimezones, true)
                && in_array($ticketType, ['free', 'paid'], true)
                && in_array($checkinMethod, ['qr', 'manual'], true)
                && $quantity !== false
                && $quantity >= 1
                && $quantity <= 1000000
                && $refundWindow !== false
                && in_array($refundWindow, [0, 24, 48, 72, 168], true);

            if (!$valid) {
                $errorMessage =
                    'Some settings are invalid. Please review your selections.';
            } else {
                $settings = [
                    'default_visibility' => $visibility,
                    'default_location_type' => $locationType,
                    'default_timezone' => $timezone,
                    'default_ticket_type' => $ticketType,
                    'default_ticket_quantity' => $quantity,
                    'sales_start_enabled' =>
                        isset($_POST['sales_start_enabled']) ? 1 : 0,
                    'event_published_enabled' =>
                        isset($_POST['event_published_enabled']) ? 1 : 0,
                    'custom_date_enabled' =>
                        isset($_POST['custom_date_enabled']) ? 1 : 0,
                    'default_checkin_method' => $checkinMethod,
                    'allow_reentry' =>
                        isset($_POST['allow_reentry']) ? 1 : 0,
                    'auto_close_checkin' =>
                        isset($_POST['auto_close_checkin']) ? 1 : 0,
                    'refund_window_hours' => $refundWindow,
                    'auto_approve_refunds' =>
                        isset($_POST['auto_approve_refunds']) ? 1 : 0,
                    'allow_partial_refunds' =>
                        isset($_POST['allow_partial_refunds']) ? 1 : 0,
                ];

                $saveSql = '
                    UPDATE organizer_event_defaults
                    SET
                        default_visibility = :visibility,
                        default_location_type = :location_type,
                        default_timezone = :timezone,
                        default_ticket_type = :ticket_type,
                        default_ticket_quantity = :quantity,
                        sales_start_enabled = :sales_start,
                        event_published_enabled = :published,
                        custom_date_enabled = :custom_date,
                        default_checkin_method = :checkin,
                        allow_reentry = :reentry,
                        auto_close_checkin = :auto_close,
                        refund_window_hours = :refund_window,
                        auto_approve_refunds = :auto_approve,
                        allow_partial_refunds = :partial_refunds
                    WHERE user_id = :user_id
                ';

                $saveStatement = $pdo->prepare($saveSql);

                $saveStatement->execute([
                    ':visibility' => $settings['default_visibility'],
                    ':location_type' => $settings['default_location_type'],
                    ':timezone' => $settings['default_timezone'],
                    ':ticket_type' => $settings['default_ticket_type'],
                    ':quantity' => $settings['default_ticket_quantity'],
                    ':sales_start' => $settings['sales_start_enabled'],
                    ':published' => $settings['event_published_enabled'],
                    ':custom_date' => $settings['custom_date_enabled'],
                    ':checkin' => $settings['default_checkin_method'],
                    ':reentry' => $settings['allow_reentry'],
                    ':auto_close' => $settings['auto_close_checkin'],
                    ':refund_window' => $settings['refund_window_hours'],
                    ':auto_approve' => $settings['auto_approve_refunds'],
                    ':partial_refunds' => $settings['allow_partial_refunds'],
                    ':user_id' => $userId,
                ]);

                header(
                    'Location: default-event-settings.php?saved=1'
                );
                exit;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Load saved preferences
    |--------------------------------------------------------------------------
    */

    $loadStatement = $pdo->prepare(
        'SELECT *
         FROM organizer_event_defaults
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $loadStatement->execute([
        ':user_id' => $userId,
    ]);

    $savedSettings = $loadStatement->fetch(PDO::FETCH_ASSOC);

    if ($savedSettings) {
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $savedSettings)) {
                $settings[$key] = $savedSettings[$key];
            }
        }
    }

    if (isset($_GET['saved'])) {
        if ($_GET['saved'] === '1') {
            $successMessage = 'Your default event settings have been saved.';
        } elseif ($_GET['saved'] === 'reset') {
            $successMessage = 'Settings have been reset to Ticketa defaults.';
        }
    }
} catch (Throwable $exception) {
    error_log(
        'Ticketa default event settings error: '
        . $exception->getMessage()
    );

    if ($errorMessage === '') {
        $errorMessage =
            'Unable to load or save your settings. Check the database table and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Default Event Settings | Ticketa</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8f9fb;
            color: #262a38;
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            font-size: 14px;
        }

        .main {
            min-height: 100vh;
            padding-top: 90px;
        }

        .settings-content {
            width: min(100% - 48px, 1440px);
            margin: 0 auto;
            padding: 30px 0 55px;
        }

        .settings-card {
            padding: 22px;
            border: 1px solid #e6e9ef;
            border-radius: 13px;
            background: #fff;
        }

        .page-title {
            margin: 0 0 22px;
            font-size: 23px;
            font-weight: 750;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 20px;
        }

        .setting-section {
            min-width: 0;
            padding: 20px;
            border: 1px solid #e7e9ee;
            border-radius: 8px;
        }

        .event-basics {
            grid-column: span 3;
        }

        .ticket-settings {
            grid-column: span 3;
        }

        .fees-settings,
        .checkin-settings,
        .refund-settings {
            grid-column: span 2;
        }

        .setting-section h2 {
            margin: 0 0 20px;
            font-size: 18px;
            font-weight: 700;
        }

        .field {
            margin-bottom: 18px;
        }

        .field:last-child {
            margin-bottom: 0;
        }

        .field label,
        .field-label {
            display: block;
            margin-bottom: 8px;
            color: #414654;
            font-size: 13px;
        }

        select,
        input[type="number"] {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #daddE5;
            border-radius: 6px;
            background: #fff;
            color: #303443;
            font: inherit;
        }

        input[type="number"] {
            max-width: 130px;
        }

        .radio-list,
        .checkbox-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .radio-option,
        .checkbox-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #454a58;
            line-height: 1.5;
            cursor: pointer;
        }

        .radio-option input,
        .checkbox-option input {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            margin: 0;
            accent-color: #10b981;
            cursor: pointer;
        }

        .toggle-list {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            color: #454a58;
            line-height: 1.5;
        }

        .switch {
            position: relative;
            display: inline-flex;
            width: 46px;
            height: 26px;
            flex-shrink: 0;
        }

        .switch input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
        }

        .switch-track {
            position: absolute;
            inset: 0;
            border-radius: 30px;
            background: #dfe2e9;
            cursor: pointer;
            transition: background .2s ease;
        }

        .switch-track::before {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,.15);
            content: "";
            transition: transform .2s ease;
        }

        .switch input:checked + .switch-track {
            background: #f5c83e;
        }

        .switch input:checked + .switch-track::before {
            transform: translateX(20px);
        }

        .switch input:focus-visible + .switch-track {
            outline: 3px solid rgba(16,185,129,.25);
            outline-offset: 3px;
        }

        .section-note {
            margin: 0 0 18px;
            color: #777e8c;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 25px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 43px;
            padding: 11px 17px;
            border: 1px solid transparent;
            border-radius: 6px;
            font: inherit;
            font-weight: 650;
            cursor: pointer;
        }

        .button-primary {
            background: #079c4a;
            color: #fff;
        }

        .button-primary:hover {
            background: #078540;
        }

        .button-secondary {
            border-color: #cbd0d8;
            background: #fff;
            color: #424857;
        }

        .button-secondary:hover {
            background: #f7f8fa;
        }

        .notice {
            padding: 13px 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            line-height: 1.6;
        }

        .notice-success {
            border: 1px solid #c8ecd6;
            background: #e9f9ef;
            color: #176b45;
        }

        .notice-error {
            border: 1px solid #f2cbd0;
            background: #fff0f1;
            color: #a12b36;
        }

        .helper-text {
            margin-top: 7px;
            color: #858c99;
            font-size: 12px;
            line-height: 1.6;
        }

        .info-placeholder {
            padding: 12px;
            border-radius: 8px;
            background: #e8fbef;
            color: #34694b;
            font-size: 12px;
            line-height: 1.7;
        }


        @media (max-width: 1000px) {
            .event-basics,
            .ticket-settings {
                grid-column: span 3;
            }

            .fees-settings,
            .checkin-settings,
            .refund-settings {
                grid-column: span 3;
            }
        }

        @media (max-width: 650px) {
            .main {
                padding-top: 80px;
            }

            .settings-content {
                width: calc(100% - 28px);
                padding-top: 22px;
            }

            .settings-card {
                padding: 15px;
            }

            .page-title {
                font-size: 21px;
            }

            .settings-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .event-basics,
            .ticket-settings,
            .fees-settings,
            .checkin-settings,
            .refund-settings {
                grid-column: span 1;
            }

            .setting-section {
                padding: 16px;
            }

            .actions {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

    <main class="settings-content">

        <section class="settings-card">

            <h1 class="page-title">Default Event Settings</h1>

            <?php if ($successMessage !== ''): ?>
                <div class="notice notice-success" role="status">
                    <?= defaultEventEscape($successMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="notice notice-error" role="alert">
                    <?= defaultEventEscape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="default-event-settings.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= defaultEventEscape(authCsrfToken()) ?>"
                >

                <div class="settings-grid">

                    <!-- EVENT BASICS -->
                    <section class="setting-section event-basics">
                        <h2>Event Basics</h2>

                        <div class="field">
                            <span class="field-label">
                                Default Event Visibility
                            </span>

                            <div class="radio-list">
                                <label class="radio-option">
                                    <input
                                        type="radio"
                                        name="default_visibility"
                                        value="public"
                                        <?= $settings['default_visibility'] === 'public' ? 'checked' : '' ?>
                                    >
                                    <span>Public</span>
                                </label>

                                <label class="radio-option">
                                    <input
                                        type="radio"
                                        name="default_visibility"
                                        value="private"
                                        <?= $settings['default_visibility'] === 'private' ? 'checked' : '' ?>
                                    >
                                    <span>Private</span>
                                </label>
                            </div>
                        </div>

                        <div class="field">
                            <label for="location-type">Location Type</label>

                            <select id="location-type" name="default_location_type">
                                <option value="physical" <?= $settings['default_location_type'] === 'physical' ? 'selected' : '' ?>>
                                    Physical location
                                </option>

                                <option value="online" <?= $settings['default_location_type'] === 'online' ? 'selected' : '' ?>>
                                    Online event
                                </option>

                                <option value="hybrid" <?= $settings['default_location_type'] === 'hybrid' ? 'selected' : '' ?>>
                                    Hybrid event
                                </option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="timezone">Time Zone</label>

                            <select id="timezone" name="default_timezone">
                                <option value="Africa/Abidjan" <?= $settings['default_timezone'] === 'Africa/Abidjan' ? 'selected' : '' ?>>
                                    Abidjan (GMT+0)
                                </option>

                                <option value="Africa/Accra" <?= $settings['default_timezone'] === 'Africa/Accra' ? 'selected' : '' ?>>
                                    Accra (GMT+0)
                                </option>

                                <option value="Africa/Lagos" <?= $settings['default_timezone'] === 'Africa/Lagos' ? 'selected' : '' ?>>
                                    Lagos (GMT+1)
                                </option>

                                <option value="Europe/London" <?= $settings['default_timezone'] === 'Europe/London' ? 'selected' : '' ?>>
                                    London
                                </option>

                                <option value="Europe/Paris" <?= $settings['default_timezone'] === 'Europe/Paris' ? 'selected' : '' ?>>
                                    Paris
                                </option>

                                <option value="America/New_York" <?= $settings['default_timezone'] === 'America/New_York' ? 'selected' : '' ?>>
                                    New York
                                </option>
                            </select>
                        </div>
                    </section>

                    <!-- TICKET SETTINGS -->
                    <section class="setting-section ticket-settings">
                        <h2>Ticket Settings</h2>

                        <div class="field">
                            <label for="ticket-type">Default Ticket Type</label>

                            <select id="ticket-type" name="default_ticket_type">
                                <option value="paid" <?= $settings['default_ticket_type'] === 'paid' ? 'selected' : '' ?>>
                                    Paid
                                </option>

                                <option value="free" <?= $settings['default_ticket_type'] === 'free' ? 'selected' : '' ?>>
                                    Free
                                </option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="ticket-quantity">
                                Default Ticket Quantity
                            </label>

                            <input
                                type="number"
                                id="ticket-quantity"
                                name="default_ticket_quantity"
                                min="1"
                                max="1000000"
                                required
                                value="<?= defaultEventEscape($settings['default_ticket_quantity']) ?>"
                            >
                        </div>

                        <div class="toggle-list">

                            <div class="toggle-row">
                                <span>Sales start enabled</span>

                                <label class="switch">
                                    <input
                                        type="checkbox"
                                        name="sales_start_enabled"
                                        value="1"
                                        <?= defaultEventCheckbox($settings['sales_start_enabled']) ? 'checked' : '' ?>
                                    >
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                            <div class="toggle-row">
                                <span>Event published by default</span>

                                <label class="switch">
                                    <input
                                        type="checkbox"
                                        name="event_published_enabled"
                                        value="1"
                                        <?= defaultEventCheckbox($settings['event_published_enabled']) ? 'checked' : '' ?>
                                    >
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                            <div class="toggle-row">
                                <span>Enable custom dates</span>

                                <label class="switch">
                                    <input
                                        type="checkbox"
                                        name="custom_date_enabled"
                                        value="1"
                                        <?= defaultEventCheckbox($settings['custom_date_enabled']) ? 'checked' : '' ?>
                                    >
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                        </div>

                        <p class="helper-text">
                            These options will be used as starting values
                            when creating a new event. Publishing an event
                            automatically will be handled separately.
                        </p>
                    </section>

                    <!-- FEES & PAYMENTS -->
                    <section class="setting-section fees-settings">
                        <h2>Fees &amp; Payments</h2>

                        <p class="section-note">
                            Payment processing and Ticketa platform fees
                            will be configured separately for Côte d’Ivoire.
                        </p>

                        <div class="field">
                            <label for="payment-currency">
                                Default Currency
                            </label>

                            <select id="payment-currency" disabled>
                                <option selected>XOF — CFA Franc (FCFA)</option>
                            </select>

                            <p class="helper-text">
                                Currency is currently displayed as a
                                platform default and is not saved by this form.
                            </p>
                        </div>

                        <div class="info-placeholder">
                            Paystack is currently in test mode. No payment
                            fees or live payment settings are changed here.
                        </div>
                    </section>

                    <!-- CHECK-IN -->
                    <section class="setting-section checkin-settings">
                        <h2>Check-In &amp; Entry</h2>

                        <div class="field">
                            <label for="checkin-method">
                                Default Check-In Method
                            </label>

                            <select id="checkin-method" name="default_checkin_method">
                                <option value="qr" <?= $settings['default_checkin_method'] === 'qr' ? 'selected' : '' ?>>
                                    QR code scanning
                                </option>

                                <option value="manual" <?= $settings['default_checkin_method'] === 'manual' ? 'selected' : '' ?>>
                                    Manual check-in
                                </option>
                            </select>
                        </div>

                        <div class="checkbox-list">
                            <label class="checkbox-option">
                                <input
                                    type="checkbox"
                                    name="allow_reentry"
                                    value="1"
                                    <?= defaultEventCheckbox($settings['allow_reentry']) ? 'checked' : '' ?>
                                >
                                <span>Allow attendee re-entry</span>
                            </label>

                            <label class="checkbox-option">
                                <input
                                    type="checkbox"
                                    name="auto_close_checkin"
                                    value="1"
                                    <?= defaultEventCheckbox($settings['auto_close_checkin']) ? 'checked' : '' ?>
                                >
                                <span>Automatically close check-in</span>
                            </label>
                        </div>

                        <p class="helper-text">
                            These are saved preferences. Your check-in
                            workflow must read them before applying them
                            to new events.
                        </p>
                    </section>

                    <!-- REFUND POLICY -->
                    <section class="setting-section refund-settings">
                        <h2>Refund Policy</h2>

                        <div class="field">
                            <label for="refund-window">
                                Refund Window
                            </label>

                            <select id="refund-window" name="refund_window_hours">
                                <option value="0" <?= (int) $settings['refund_window_hours'] === 0 ? 'selected' : '' ?>>
                                    No refund window
                                </option>

                                <option value="24" <?= (int) $settings['refund_window_hours'] === 24 ? 'selected' : '' ?>>
                                    Up to 24 hours before the event
                                </option>

                                <option value="48" <?= (int) $settings['refund_window_hours'] === 48 ? 'selected' : '' ?>>
                                    Up to 48 hours before the event
                                </option>

                                <option value="72" <?= (int) $settings['refund_window_hours'] === 72 ? 'selected' : '' ?>>
                                    Up to 72 hours before the event
                                </option>

                                <option value="168" <?= (int) $settings['refund_window_hours'] === 168 ? 'selected' : '' ?>>
                                    Up to 7 days before the event
                                </option>
                            </select>
                        </div>

                        <div class="checkbox-list">
                            <label class="checkbox-option">
                                <input
                                    type="checkbox"
                                    name="auto_approve_refunds"
                                    value="1"
                                    <?= defaultEventCheckbox($settings['auto_approve_refunds']) ? 'checked' : '' ?>
                                >
                                <span>Automatically approve eligible refunds</span>
                            </label>

                            <label class="checkbox-option">
                                <input
                                    type="checkbox"
                                    name="allow_partial_refunds"
                                    value="1"
                                    <?= defaultEventCheckbox($settings['allow_partial_refunds']) ? 'checked' : '' ?>
                                >
                                <span>Allow partial refunds</span>
                            </label>
                        </div>

                        <p class="helper-text">
                            These preferences do not issue refunds.
                            Refund processing must enforce the saved policy.
                        </p>
                    </section>

                </div>

                <div class="actions">
                    <button
                        type="submit"
                        name="action"
                        value="save"
                        class="button button-primary"
                    >
                        Save Defaults
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="reset"
                        class="button button-secondary"
                        formnovalidate
                        onclick="return confirm('Reset your settings to Ticketa defaults?');"
                    >
                        Reset to Ticketa Defaults
                    </button>
                </div>

            </form>

        </section>

    </main>

</div>

</body>
</html>
