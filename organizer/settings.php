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

$organizerName = $user['full_name'] ?? 'Organizer';
$currentPage = 'settings.php';
$topbarSearchPlaceholder = 'Search settings';

if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

function settingsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$organizer = [
    'id' => null,
    'business_name' => '',
    'organizer_type' => '',
    'contact_name' => '',
    'email' => '',
    'phone' => '',
    'country' => '',
    'description' => '',
];

$successMessage = '';
$errorMessage = '';
$showProfileForm = isset($_GET['profile']);

try {
    $profileStmt = $pdo->prepare(
        'SELECT id, business_name, organizer_type, contact_name,
                email, phone, country, description
         FROM organizers
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $profileStmt->execute([
        'user_id' => $user['id'],
    ]);

    $profileData = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!$profileData) {
        throw new RuntimeException(
            'No organizer profile is linked to your account.'
        );
    }

    $organizer = array_merge($organizer, $profileData);

    if (!empty($organizer['business_name'])) {
        $organizerName = $organizer['business_name'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($postedToken) ||
            !hash_equals($_SESSION['settings_csrf'], $postedToken)
        ) {
            $errorMessage = 'Your session has expired. Refresh the page and try again.';
            $showProfileForm = true;
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'update_profile') {
                $businessName = trim((string) ($_POST['business_name'] ?? ''));
                $organizerType = trim((string) ($_POST['organizer_type'] ?? ''));
                $contactName = trim((string) ($_POST['contact_name'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $country = trim((string) ($_POST['country'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));

                if ($businessName === '') {
                    $errorMessage = 'Organization name is required.';
                } elseif (mb_strlen($businessName) > 150) {
                    $errorMessage = 'Organization name must not exceed 150 characters.';
                } elseif ($contactName === '') {
                    $errorMessage = 'Contact name is required.';
                } elseif (mb_strlen($contactName) > 150) {
                    $errorMessage = 'Contact name must not exceed 150 characters.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = 'Enter a valid contact email address.';
                } elseif (mb_strlen($email) > 190) {
                    $errorMessage = 'Email address is too long.';
                } elseif (mb_strlen($phone) > 35) {
                    $errorMessage = 'Phone number must not exceed 35 characters.';
                } elseif (mb_strlen($country) > 100) {
                    $errorMessage = 'Country must not exceed 100 characters.';
                } elseif (mb_strlen($description) > 5000) {
                    $errorMessage = 'Description must not exceed 5000 characters.';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE organizers
                         SET business_name = :business_name,
                             organizer_type = :organizer_type,
                             contact_name = :contact_name,
                             email = :email,
                             phone = :phone,
                             country = :country,
                             description = :description
                         WHERE id = :organizer_id
                           AND user_id = :user_id'
                    );

                    $updateStmt->execute([
                        'business_name' => $businessName,
                        'organizer_type' => $organizerType,
                        'contact_name' => $contactName,
                        'email' => $email,
                        'phone' => $phone,
                        'country' => $country,
                        'description' => $description,
                        'organizer_id' => $organizer['id'],
                        'user_id' => $user['id'],
                    ]);

                    $successMessage = 'Organization profile updated successfully.';
                    $showProfileForm = true;

                    $organizer['business_name'] = $businessName;
                    $organizer['organizer_type'] = $organizerType;
                    $organizer['contact_name'] = $contactName;
                    $organizer['email'] = $email;
                    $organizer['phone'] = $phone;
                    $organizer['country'] = $country;
                    $organizer['description'] = $description;
                    $organizerName = $businessName;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Ticketa settings error: ' . $e->getMessage());

    if ($errorMessage === '') {
        $errorMessage = 'We could not load your settings. Please check the database configuration.';
    }
}

// Each card has an 'href'. Rename the filenames to match your real pages.
// Set 'available' => false on any card whose page isn't built yet.
$settingsCards = [
    [
        'icon' => 'building-2',
        'title' => 'Organization Profile',
        'description' => 'Manage your organization details and public organizer information.',
        'button' => 'Update Profile',
        'key' => 'profile',
        'href' => 'organization-info.php',
        'available' => true,
    ],
    [
        'icon' => 'users',
        'title' => 'Team & Permissions',
        'description' => 'Invite team members and control access across your events.',
        'button' => 'Manage Team',
        'key' => 'team',
        'href' => 'team-permissions.php',
        'available' => true,
    ],
    [
        'icon' => 'shield-check',
        'title' => 'Account & Security',
        'description' => 'Secure your account and manage login preferences.',
        'button' => 'Security Settings',
        'key' => 'security',
        'href' => 'account-security.php',
        'available' => true,
    ],
    [
        'icon' => 'wallet',
        'title' => 'Payments & Payouts',
        'description' => 'Set up how you receive earnings from ticket sales.',
        'button' => 'Configure Payments',
        'key' => 'payments',
        'href' => 'payments-payouts.php',
        'available' => true,
    ],
    [
        'icon' => 'credit-card',
        'title' => 'Billing & Subscription',
        'description' => 'Manage your subscription plan and billing information.',
        'button' => 'Manage Subscription',
        'key' => 'billing',
        'href' => 'billing-subcription.php',
        'available' => true,
    ],
    [
        'icon' => 'bell',
        'title' => 'Notifications',
        'description' => 'Control how and when you receive platform alerts.',
        'button' => 'Manage Notifications',
        'key' => 'notifications',
        'href' => 'notifications.php',
        'available' => true,
    ],
    [
        'icon' => 'settings',
        'title' => 'Default Event Settings',
        'description' => 'Set default rules applied to newly created events.',
        'button' => 'Edit Defaults',
        'key' => 'defaults',
        'href' => 'default-event-settings.php',
        'available' => true,
    ],
    [
        'icon' => 'workflow',
        'title' => 'Integrations',
        'description' => 'Connect external tools to extend your event workflow.',
        'button' => 'View Integrations',
        'key' => 'integrations',
        'href' => 'integrations.php',
        'available' => true,
    ],
    [
        'icon' => 'scale',
        'title' => 'Legal & Compliance',
        'description' => 'Manage legal requirements and compliance information.',
        'button' => 'Manage Legal',
        'key' => 'legal',
        'href' => 'legal-compliance.php',
        'available' => true,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | TICKETA</title>

    <style>
        :root {
            --settings-green: #10bea0;
            --settings-green-dark: #08a98d;
            --settings-ink: #171c2e;
            --settings-muted: #7b7b7b;
            --settings-border: #e9ecef;
            --settings-bg: #fafbfc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--settings-bg);
            color: var(--settings-ink);
            font-family: Inter, Arial, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 250px;
            padding-top: 90px;
        }

        .settings-content {
            padding: 20px 32px 40px;
        }

        .settings-heading {
            margin-bottom: 18px;
        }

        .settings-heading h1 {
            margin: 0 0 8px;
            font-size: clamp(25px, 3vw, 32px);
            letter-spacing: -.6px;
        }

        .settings-heading p {
            margin: 0;
            color: var(--settings-muted);
            font-size: 16px;
            line-height: 1.5;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
        }

        .settings-card {
            display: flex;
            min-width: 0;
            min-height: 239px;
            flex-direction: column;
            align-items: flex-start;
            padding: 24px 16px 26px;
            border: 1px solid var(--settings-border);
            border-radius: 12px;
            background: #fff;
        }

        .settings-card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 18px;
            font-size: clamp(17px, 1.5vw, 21px);
            font-weight: 600;
            line-height: 1.35;
        }

        .settings-card-icon {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            color: var(--settings-green);
        }

        .settings-card-icon svg {
            width: 29px;
            height: 29px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .settings-card-description {
            margin: 0 0 24px;
            color: var(--settings-muted);
            font-size: 16px;
            line-height: 1.5;
        }

        .settings-card-actions {
            margin-top: auto;
        }

        .settings-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 16px;
            border: 0;
            border-radius: 6px;
            background: var(--settings-green);
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            transition: background .2s ease;
        }

        .settings-button:hover {
            background: var(--settings-green-dark);
        }

        .settings-button:focus-visible,
        .settings-close:focus-visible {
            outline: 3px solid rgba(16, 190, 160, .3);
            outline-offset: 3px;
        }

        .settings-button.disabled {
            background: #edf0f2;
            color: #7b838b;
            cursor: not-allowed;
        }

        .settings-button.disabled:hover {
            background: #edf0f2;
        }

        .settings-notice {
            margin-bottom: 18px;
            padding: 13px 16px;
            border-radius: 7px;
            font-size: 14px;
            line-height: 1.5;
        }

        .settings-notice.success {
            background: #e9f7ef;
            color: #267447;
        }

        .settings-notice.error {
            background: #fff0f0;
            color: #a52b2b;
        }

        .settings-form-panel {
            margin-bottom: 24px;
            padding: 24px;
            border: 1px solid var(--settings-border);
            border-radius: 12px;
            background: #fff;
        }

        .settings-form-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        .settings-form-header h2 {
            margin: 0 0 6px;
            font-size: 21px;
        }

        .settings-form-header p {
            margin: 0;
            color: var(--settings-muted);
            font-size: 14px;
            line-height: 1.5;
        }

        .settings-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            border: 1px solid var(--settings-border);
            border-radius: 8px;
            background: #fff;
            color: var(--settings-ink);
            font-size: 22px;
            text-decoration: none;
        }

        .settings-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .settings-field {
            display: grid;
            gap: 8px;
        }

        .settings-field.full-width {
            grid-column: 1 / -1;
        }

        .settings-field label {
            font-size: 14px;
            font-weight: 600;
        }

        .settings-field input,
        .settings-field select,
        .settings-field textarea {
            width: 100%;
            min-width: 0;
            padding: 12px 13px;
            border: 1px solid #d9dde1;
            border-radius: 7px;
            background: #fff;
            color: var(--settings-ink);
            font: inherit;
        }

        .settings-field textarea {
            min-height: 115px;
            resize: vertical;
        }

        .settings-field input:focus,
        .settings-field select:focus,
        .settings-field textarea:focus {
            border-color: var(--settings-green);
            outline: 2px solid rgba(16, 190, 160, .15);
        }

        .settings-form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 22px;
        }

        .settings-button.secondary {
            border: 1px solid var(--settings-border);
            background: #fff;
            color: var(--settings-ink);
        }

        .settings-button.secondary:hover {
            background: #f5f7f8;
        }

        .settings-availability {
            margin-top: 12px;
            color: #858585;
            font-size: 12px;
        }

        @media (max-width: 1250px) {
            .main {
                margin-left: 220px;
                padding-top: 90px;
            }

            .settings-content {
                padding-right: 22px;
                padding-left: 22px;
            }

            .settings-grid {
                gap: 18px;
            }

            .settings-card {
                padding: 22px 15px;
            }
        }

        @media (max-width: 1050px) {
            .settings-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                padding-top: 75px;
            }

            .settings-content {
                padding: 22px 16px 32px;
            }

            .settings-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
            }

            .settings-card {
                min-height: 240px;
                padding: 18px 14px;
            }

            .settings-card-title {
                align-items: flex-start;
                gap: 9px;
                font-size: 17px;
            }

            .settings-card-description {
                font-size: 14px;
            }

            .settings-button {
                padding: 11px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 560px) {
            .settings-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .settings-card {
                min-height: 205px;
                padding: 22px 18px;
            }

            .settings-card-title {
                align-items: center;
                font-size: 19px;
            }

            .settings-card-description {
                font-size: 15px;
            }

            .settings-form-panel {
                padding: 18px 14px;
            }

            .settings-form-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .settings-field.full-width {
                grid-column: auto;
            }

            .settings-form-header h2 {
                font-size: 19px;
            }
        }
    </style>
</head>

<body>

<?php include dirname(__DIR__) . '/includes/organizer-sidebar.php'; ?>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/organizer-topbar.php'; ?>

    <main class="settings-content">

        <header class="settings-heading">
            <h1>Settings</h1>
            <p>Manage your organization, account, and event preferences</p>
        </header>
        

        <section class="settings-grid" aria-label="Organizer settings">

            <?php foreach ($settingsCards as $card): ?>
                <article class="settings-card">

                    <h2 class="settings-card-title">
                        <span class="settings-card-icon" aria-hidden="true">
                            <?php if ($card['icon'] === 'building-2'): ?>
                                <svg viewBox="0 0 24 24">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                                    <path d="M9 21V9h6v12M7 7h.01M12 7h.01M17 7h.01M7 11h.01M17 11h.01M7 15h.01M17 15h.01"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'users'): ?>
                                <svg viewBox="0 0 24 24">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="10" cy="7" r="4"/>
                                    <path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'shield-check'): ?>
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/>
                                    <path d="m9 12 2 2 4-4"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'wallet'): ?>
                                <svg viewBox="0 0 24 24">
                                    <rect x="2" y="5" width="20" height="15" rx="2"/>
                                    <path d="M2 9h20M16 15h.01"/>
                                    <path d="M5 5V3h14v2"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'credit-card'): ?>
                                <svg viewBox="0 0 24 24">
                                    <rect x="2" y="4" width="20" height="16" rx="2"/>
                                    <path d="M2 10h20M6 15h4"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'bell'): ?>
                                <svg viewBox="0 0 24 24">
                                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                                    <path d="M10 21h4"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'settings'): ?>
                                <svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="3"/>
                                    <path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.5-.9l-1.7.6-1.4-2.4 1.4-1.1a8 8 0 0 1 0-1.8L6.9 12l1.4-2.4 1.7.6a8 8 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.5.9l1.7-.6 1.4 2.4-1.4 1.1a8 8 0 0 1 0 1.9z"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'workflow'): ?>
                                <svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="3"/>
                                    <circle cx="12" cy="3" r="1.5"/>
                                    <circle cx="21" cy="12" r="1.5"/>
                                    <circle cx="12" cy="21" r="1.5"/>
                                    <circle cx="3" cy="12" r="1.5"/>
                                    <path d="M12 4.5V9M15 12h4.5M12 15v4.5M9 12H4.5"/>
                                </svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 3 2 7l10 4 10-4-10-4z"/>
                                    <path d="M6 9v6c3 3 9 3 12 0V9M2 7v6"/>
                                    <path d="M19 10v6"/>
                                </svg>
                            <?php endif; ?>
                        </span>

                        <?= settingsEscape($card['title']) ?>
                    </h2>

                    <p class="settings-card-description">
                        <?= settingsEscape($card['description']) ?>
                    </p>

                    <div class="settings-card-actions">
                        <?php if (!empty($card['available']) && !empty($card['href'])): ?>
                            <a
                                href="<?= settingsEscape($card['href']) ?>"
                                class="settings-button"
                            >
                                <?= settingsEscape($card['button']) ?>
                            </a>
                        <?php else: ?>
                            <button
                                type="button"
                                class="settings-button disabled"
                                disabled
                                title="This feature has not been implemented yet"
                            >
                                <?= settingsEscape($card['button']) ?>
                            </button>
                        <?php endif; ?>
                    </div>

                </article>
            <?php endforeach; ?>

        </section>

    </main>
</div>

<script>
(function () {
    const searchInput = document.getElementById('dashboardSearch');
    const settingsCards = document.querySelectorAll('.settings-card');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLowerCase();

            settingsCards.forEach(function (card) {
                card.hidden = !card.textContent.toLowerCase().includes(query);
            });
        });
    }
})();
</script>

</body>
</html>