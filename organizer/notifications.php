
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

$currentPage = 'notifications.php';
$topbarSearchPlaceholder = 'Search settings';

$successMessage = '';
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Notification preferences
|--------------------------------------------------------------------------
*/

$notificationGroups = [
    'Events' => [
        'event_published' => 'Event published',
        'event_approved' => 'Event approved',
        'event_starting' => 'Event starting soon',
        'ticket_reminders' => 'Ticket and event reminders',
        'low_ticket' => 'Low ticket availability',
        'event_cancelled' => 'Event cancelled',
    ],
    'Sales & Payments' => [
        'ticket_sold' => 'Ticket sold',
        'ticket_refunded' => 'Ticket refunded',
        'payment_failed' => 'Payment failed',
        'payout_processed' => 'Payout processed',
        'refund_request' => 'Refund request received',
    ],
    'Team' => [
        'team_invited' => 'Team invitation received',
        'team_invite_accepted' => 'Team invitation accepted',
        'team_role_changed' => 'Team role changed',
    ],
    'Platform Updates' => [
        'feature_updates' => 'New features and product updates',
        'maintenance_alerts' => 'Maintenance and service alerts',
        'policy_updates' => 'Policy and terms updates',
    ],
];

$preferenceColumns = [];

foreach ($notificationGroups as $groupItems) {
    foreach ($groupItems as $key => $label) {
        $preferenceColumns[] = $key . '_email';
        $preferenceColumns[] = $key . '_in_app';
    }
}

/*
|--------------------------------------------------------------------------
| Load or create default preferences
|--------------------------------------------------------------------------
*/

try {
    $createDefaults = $pdo->prepare(
        'INSERT IGNORE INTO organizer_notification_preferences (user_id)
         VALUES (:user_id)'
    );

    $createDefaults->execute([
        ':user_id' => $userId,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Save preferences
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!authVerifyCsrf()) {
            $errorMessage = 'Your session has expired. Refresh the page and try again.';
        } else {
            $postedPreferences = $_POST['prefs'] ?? [];

            if (!is_array($postedPreferences)) {
                $postedPreferences = [];
            }

            $setParts = [];
            $parameters = [
                ':user_id' => $userId,
            ];

            foreach ($preferenceColumns as $column) {
                // Only accept the known preference columns.
                $setParts[] = "`$column` = :$column";

                $parameters[':' . $column] =
                    isset($postedPreferences[$column]) &&
                    (string) $postedPreferences[$column] === '1'
                        ? 1
                        : 0;
            }

            $sql = 'UPDATE organizer_notification_preferences
                    SET ' . implode(', ', $setParts) . '
                    WHERE user_id = :user_id';

            $updatePreferences = $pdo->prepare($sql);
            $updatePreferences->execute($parameters);

            header('Location: notifications.php?saved=1');
            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch current preferences
    |--------------------------------------------------------------------------
    */

    $selectPreferences = $pdo->prepare(
        'SELECT * FROM organizer_notification_preferences
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $selectPreferences->execute([
        ':user_id' => $userId,
    ]);

    $preferences = $selectPreferences->fetch(PDO::FETCH_ASSOC);

    if (!$preferences) {
        throw new RuntimeException(
            'Notification preferences could not be loaded.'
        );
    }

    if (isset($_GET['saved']) && $_GET['saved'] === '1') {
        $successMessage = 'Your notification preferences have been saved.';
    }
} catch (Throwable $exception) {
    error_log('Ticketa notifications settings error: ' . $exception->getMessage());

    if ($errorMessage === '') {
        $errorMessage = 'Something went wrong while loading or saving your preferences. Please try again.';
    }

    $preferences = $preferences ?? [];
}

/*
|--------------------------------------------------------------------------
| HTML escaping helper
|--------------------------------------------------------------------------
*/

function notificationSettingsEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Notifications Settings | Ticketa</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8f9fc;
            color: #202536;
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            font-size: 14px;
        }

        .main {
            min-height: 100vh;
            padding-top: 90px;
        }

        .settings-content {
            width: min(100% - 48px, 1050px);
            margin: 0 auto;
            padding: 30px 0 60px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0 0 9px;
            font-size: 28px;
            line-height: 1.3;
            font-weight: 750;
            letter-spacing: -0.7px;
            color: #171b2c;
        }

        .page-heading p {
            margin: 0;
            max-width: 650px;
            color: #777e90;
            font-size: 14px;
            line-height: 1.7;
        }

        .notice {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 10px;
            line-height: 1.5;
        }

        .notice-success {
            color: #176b45;
            background: #eaf8f0;
            border: 1px solid #ccebd9;
        }

        .notice-error {
            color: #a12b36;
            background: #fff0f1;
            border: 1px solid #f4cdd1;
        }

        .settings-card {
            background: #fff;
            border: 1px solid #e8eaf0;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(24, 32, 56, 0.025);
        }

        .card-heading {
            padding: 21px 24px;
            border-bottom: 1px solid #eceef3;
        }

        .card-heading h2 {
            margin: 0 0 6px;
            font-size: 16px;
            font-weight: 700;
            color: #22273a;
        }

        .card-heading p {
            margin: 0;
            color: #81879a;
            font-size: 13px;
            line-height: 1.6;
        }

        .notification-table-heading {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 95px 95px;
            gap: 12px;
            align-items: center;
            padding: 13px 24px;
            background: #fafbfc;
            border-bottom: 1px solid #eceef3;
        }

        .notification-table-heading span {
            color: #777e90;
            font-size: 12px;
            font-weight: 650;
        }

        .notification-table-heading span:not(:first-child) {
            text-align: center;
        }

        .notification-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 95px 95px;
            gap: 12px;
            align-items: center;
            min-height: 72px;
            padding: 15px 24px;
            border-bottom: 1px solid #f0f1f5;
        }

        .notification-row:last-child {
            border-bottom: none;
        }

        .notification-name {
            color: #303548;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.5;
        }

        .notification-description {
            margin-top: 4px;
            color: #969bad;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.5;
        }

        .switch-cell {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .switch {
            position: relative;
            display: inline-flex;
            width: 40px;
            height: 23px;
            flex: 0 0 auto;
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
            background: #dfe2e9;
            border-radius: 30px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .switch-track::before {
            content: "";
            position: absolute;
            width: 17px;
            height: 17px;
            top: 3px;
            left: 3px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.14);
            transition: transform 0.2s ease;
        }

        .switch input:checked + .switch-track {
            background: #f2c94c;
        }

        .switch input:checked + .switch-track::before {
            transform: translateX(17px);
        }

        .switch input:focus-visible + .switch-track {
            outline: 3px solid rgba(103, 80, 220, 0.3);
            outline-offset: 3px;
        }

        .save-area {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 14px;
            margin-top: 24px;
        }

        .save-note {
            color: #858b9d;
            font-size: 12px;
        }

        .save-button {
            min-height: 44px;
            padding: 0 23px;
            border: none;
            border-radius: 9px;
            background: #6554d9;
            color: #fff;
            font: inherit;
            font-weight: 650;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .save-button:hover {
            background: #5543c5;
        }

        .save-button:active {
            transform: translateY(1px);
        }

        .save-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        @media (max-width: 700px) {
            .main {
                padding-top: 80px;
            }

            .settings-content {
                width: calc(100% - 28px);
                padding-top: 24px;
            }

            .page-heading h1 {
                font-size: 24px;
            }

            .card-heading {
                padding: 18px;
            }

            .notification-table-heading {
                grid-template-columns: minmax(0, 1fr) 65px 65px;
                gap: 8px;
                padding: 12px 14px;
            }

            .notification-table-heading span {
                font-size: 11px;
            }

            .notification-row {
                grid-template-columns: minmax(0, 1fr) 65px 65px;
                gap: 8px;
                padding: 15px 14px;
                min-height: 70px;
            }

            .notification-name {
                font-size: 12px;
            }

            .notification-description {
                font-size: 11px;
            }

            .switch {
                transform: scale(0.9);
            }

            .save-area {
                flex-direction: column;
                align-items: stretch;
            }

            .save-note {
                text-align: center;
            }

            .save-button {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

    <main class="settings-content">

        <header class="page-heading">
            <h1>Notifications</h1>
            <p>
                Manage how and when Ticketa notifies you about your events,
                ticket sales, payments, team activity and platform updates.
            </p>
        </header>

        <?php if ($successMessage !== ''): ?>
            <div class="notice notice-success" role="status">
                <?= notificationSettingsEscape($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="notice notice-error" role="alert">
                <?= notificationSettingsEscape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="notifications.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= notificationSettingsEscape(authCsrfToken()) ?>"
            >

            <?php foreach ($notificationGroups as $groupName => $groupItems): ?>

                <section class="settings-card">

                    <div class="card-heading">
                        <h2><?= notificationSettingsEscape($groupName) ?></h2>

                        <p>
                            Choose which notifications you want to receive
                            by email or inside Ticketa.
                        </p>
                    </div>

                    <div class="notification-table-heading">
                        <span>Notification</span>
                        <span>Email</span>
                        <span>In-App</span>
                    </div>

                    <?php foreach ($groupItems as $key => $label): ?>

                        <?php
                            $emailColumn = $key . '_email';
                            $inAppColumn = $key . '_in_app';

                            $emailEnabled = (int) (
                                $preferences[$emailColumn] ?? 1
                            ) === 1;

                            $inAppEnabled = (int) (
                                $preferences[$inAppColumn] ?? 1
                            ) === 1;
                        ?>

                        <div class="notification-row">

                            <div>
                                <div class="notification-name">
                                    <?= notificationSettingsEscape($label) ?>
                                </div>

                                <div class="notification-description">
                                    Receive updates about
                                    <?= notificationSettingsEscape(
                                        strtolower($label)
                                    ) ?>.
                                </div>
                            </div>

                            <div class="switch-cell">
                                <label class="switch">
                                    <input
                                        type="checkbox"
                                        name="prefs[<?= notificationSettingsEscape($emailColumn) ?>]"
                                        value="1"
                                        aria-label="<?= notificationSettingsEscape($label) ?> by email"
                                        <?= $emailEnabled ? 'checked' : '' ?>
                                    >
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                            <div class="switch-cell">
                                <label class="switch">
                                    <input
                                        type="checkbox"
                                        name="prefs[<?= notificationSettingsEscape($inAppColumn) ?>]"
                                        value="1"
                                        aria-label="<?= notificationSettingsEscape($label) ?> in app"
                                        <?= $inAppEnabled ? 'checked' : '' ?>
                                    >
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </section>

            <?php endforeach; ?>

            <div class="save-area">
                <span class="save-note">
                    Your preferences apply to your organizer account.
                </span>

                <button type="submit" class="save-button">
                    Save preferences
                </button>
            </div>

        </form>

    </main>

</div>

</body>
</html>
