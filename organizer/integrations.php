
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

$currentPage = 'integrations';
$topbarSearchPlaceholder = 'Search integrations...';

/*
|--------------------------------------------------------------------------
| UI-only integration settings
|--------------------------------------------------------------------------
| These statuses are display placeholders, not verified live connections.
| Paystack remains in test mode until its server-side integration is tested.
*/

$integrations = [
    'payments' => [
        [
            'name' => 'Paystack',
            'description' => 'Accept card and supported mobile-money payments.',
            'category' => 'Primary payment gateway',
            'status' => 'Test mode',
            'icon' => '₱',
            'class' => 'paystack',
            'features' => ['XOF / FCFA', 'Wave', 'Orange Money', 'MTN Money', 'Visa / Mastercard'],
            'action' => 'Configure Paystack',
            'url' => 'https://dashboard.paystack.com/'
        ],
    ],
    'communication' => [
        [
            'name' => 'Ticketa Mailer',
            'description' => 'Send ticket confirmations and important event emails.',
            'category' => 'Transactional email',
            'status' => 'Not configured',
            'icon' => '✉',
            'class' => 'mailer',
            'features' => ['Ticket confirmations', 'Email verification', 'Event updates'],
            'action' => 'Configure email',
            'url' => ''
        ],
        [
            'name' => 'Zoom',
            'description' => 'Connect online events to Zoom meetings.',
            'category' => 'Virtual events',
            'status' => 'Not connected',
            'icon' => 'Z',
            'class' => 'zoom',
            'features' => ['Online events', 'Meeting links'],
            'action' => 'Connect Zoom',
            'url' => 'https://marketplace.zoom.us/'
        ],
        [
            'name' => 'Google Meet',
            'description' => 'Use Google Meet links for virtual events.',
            'category' => 'Virtual events',
            'status' => 'Not connected',
            'icon' => 'M',
            'class' => 'meet',
            'features' => ['Online events', 'Meeting links'],
            'action' => 'Connect Google Meet',
            'url' => 'https://workspace.google.com/products/meet/'
        ],
    ],
    'analytics' => [
        [
            'name' => 'Google Analytics 4',
            'description' => 'Measure visits to Ticketa pages and event listings.',
            'category' => 'Website analytics',
            'status' => 'Not configured',
            'icon' => 'G',
            'class' => 'analytics',
            'features' => ['Page views', 'Traffic sources', 'Event-page performance'],
            'action' => 'Configure analytics',
            'url' => 'https://analytics.google.com/'
        ],
        [
            'name' => 'Meta Pixel',
            'description' => 'Measure supported advertising campaign conversions.',
            'category' => 'Marketing analytics',
            'status' => 'Not configured',
            'icon' => '∞',
            'class' => 'meta',
            'features' => ['Campaign measurement', 'Conversion events'],
            'action' => 'Configure Meta Pixel',
            'url' => 'https://business.facebook.com/'
        ],
    ],
];

$webhookUrl = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
        ? 'https://' : 'http://'
) . ($_SERVER['HTTP_HOST'] ?? 'localhost')
  . '/ticketa/webhooks/paystack.php';

$activeTab = $_GET['tab'] ?? 'all';
$validTabs = ['all', 'payments', 'communication', 'analytics', 'developer'];

if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'all';
}

function integrationStatusClass(string $status): string
{
    return match ($status) {
        'Connected' => 'status-connected',
        'Test mode' => 'status-test',
        default => 'status-off'
    };
}

$totalIntegrations = 0;

foreach ($integrations as $group) {
    $totalIntegrations += count($group);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrations | Ticketa Organizer</title>

    <style>
        :root {
            --primary: #6d45e8;
            --primary-dark: #5632cc;
            --navy: #111827;
            --text: #172033;
            --muted: #788195;
            --border: #e8eaf1;
            --surface: #ffffff;
            --page: #f7f8fc;
            --green: #168653;
            --green-bg: #eaf8f0;
            --amber: #a86b09;
            --amber-bg: #fff5df;
            --red: #c13b45;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page);
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                         "Segoe UI", sans-serif;
        }

        .main {
            padding: 90px 28px 50px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 25px;
        }

        .eyebrow {
            color: var(--primary);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 8px;
        }

        h1 {
            margin: 0 0 9px;
            font-size: clamp(25px, 3vw, 34px);
            letter-spacing: -1px;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            max-width: 650px;
        }

        .test-banner {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 17px 19px;
            border: 1px solid #f1d79c;
            border-radius: 14px;
            background: #fff9e9;
            margin-bottom: 25px;
        }

        .test-icon {
            width: 35px;
            height: 35px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: #ffedc2;
            color: #9a6400;
            font-weight: 800;
        }

        .test-banner strong {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .test-banner p {
            color: #7b6743;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 26px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 19px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 25px;
            font-weight: 800;
        }

        .stat-note {
            font-size: 12px;
            color: var(--muted);
            margin-top: 6px;
        }

        .section-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            background: #eef0f7;
            border-radius: 12px;
            padding: 5px;
        }

        .tab {
            text-decoration: none;
            color: #626b7f;
            padding: 10px 13px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 650;
        }

        .tab.active {
            background: #fff;
            color: var(--primary);
            box-shadow: 0 2px 7px #202b4810;
        }

        .search-box {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            padding: 11px 13px;
            width: min(100%, 260px);
            outline: none;
            font: inherit;
            font-size: 13px;
        }

        .integration-section {
            margin-bottom: 30px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 17px;
        }

        .section-heading span {
            color: var(--muted);
            font-size: 12px;
        }

        .integration-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .integration-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            min-width: 0;
            transition: border-color .2s, transform .2s;
        }

        .integration-card:hover {
            border-color: #d6cef8;
            transform: translateY(-2px);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .integration-identity {
            display: flex;
            gap: 13px;
            min-width: 0;
        }

        .integration-icon {
            width: 47px;
            height: 47px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            border-radius: 13px;
            font-size: 21px;
            font-weight: 800;
        }

        .paystack { background: #e8f0ff; color: #1459b8; }
        .mailer { background: #f0eaff; color: #6d45e8; }
        .zoom { background: #e5f1ff; color: #1764c0; }
        .meet { background: #e6f7ed; color: #198754; }
        .analytics { background: #fff0d9; color: #a86600; }
        .meta { background: #e9edff; color: #3c55c9; }

        .integration-name {
            font-size: 15px;
            font-weight: 800;
            margin: 2px 0 5px;
        }

        .integration-category {
            color: var(--muted);
            font-size: 12px;
        }

        .status {
            flex-shrink: 0;
            border-radius: 20px;
            padding: 6px 9px;
            font-size: 11px;
            font-weight: 750;
            white-space: nowrap;
        }

        .status-connected {
            color: var(--green);
            background: var(--green-bg);
        }

        .status-test {
            color: var(--amber);
            background: var(--amber-bg);
        }

        .status-off {
            color: #747c8d;
            background: #f0f1f5;
        }

        .description {
            color: #697286;
            font-size: 13px;
            line-height: 1.65;
            margin: 17px 0 13px;
        }

        .features {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-bottom: 18px;
        }

        .feature {
            border: 1px solid #eceef4;
            background: #fafbfe;
            color: #626c80;
            border-radius: 7px;
            padding: 6px 8px;
            font-size: 11px;
        }

        .card-footer {
            border-top: 1px solid #eff0f5;
            padding-top: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .local-label {
            font-size: 11px;
            color: #7b8495;
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border: 0;
            border-radius: 9px;
            padding: 10px 13px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            font-size: 12px;
            font-weight: 750;
            cursor: pointer;
            font-family: inherit;
        }

        .btn:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: #fff;
            color: #404a5d;
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #f7f7fb;
        }

        .developer-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 20px;
        }

        .developer-card h3 {
            margin: 0 0 7px;
            font-size: 16px;
        }

        .developer-card > p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.65;
            margin: 0 0 20px;
        }

        .field {
            margin-bottom: 17px;
        }

        .field label {
            display: block;
            font-size: 12px;
            font-weight: 750;
            margin-bottom: 8px;
        }

        .field-row {
            display: flex;
            gap: 8px;
        }

        .field input {
            width: 100%;
            min-width: 0;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: #596276;
            background: #f9fafe;
            font: inherit;
            font-size: 12px;
        }

        .security-note {
            padding: 13px 15px;
            background: #f3f0ff;
            color: #57449b;
            border-radius: 10px;
            font-size: 12px;
            line-height: 1.7;
        }

        .empty-state {
            display: none;
            padding: 24px;
            text-align: center;
            color: var(--muted);
            background: white;
            border: 1px dashed var(--border);
            border-radius: 12px;
            font-size: 13px;
        }

        .toast {
            display: none;
            position: fixed;
            bottom: 22px;
            right: 22px;
            z-index: 1000;
            max-width: calc(100% - 44px);
            padding: 14px 18px;
            color: white;
            background: #20283a;
            border-radius: 10px;
            box-shadow: 0 8px 25px #0002;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .main {
                padding: 85px 18px 35px;
            }

            .integration-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .main {
                padding: 82px 14px 30px;
            }

            .page-heading {
                flex-direction: column;
            }

            .stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-value {
                font-size: 22px;
            }

            .section-toolbar {
                align-items: stretch;
            }

            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }

            .tab {
                white-space: nowrap;
            }

            .search-box {
                width: 100%;
            }

            .integration-card {
                padding: 16px;
            }

            .card-top {
                flex-wrap: wrap;
            }

            .card-footer {
                align-items: flex-start;
                flex-direction: column;
            }

            .card-footer .btn {
                width: 100%;
            }

            .field-row {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

<main class="main">

    <div class="page-heading">
        <div>
            <div class="eyebrow">Workspace settings</div>
            <h1>Integrations</h1>
            <p class="subtitle">
                Manage the services that power your events, payments,
                communication and analytics on Ticketa.
            </p>
        </div>
    </div>

    <div class="test-banner">
        <div class="test-icon">!</div>
        <div>
            <strong>Payment integration is in test mode</strong>
            <p>
                Ticketa is currently being configured. Test payments before
                accepting real payments from event attendees. Do not mark a
                service as connected until its connection has been verified.
            </p>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Available integrations</div>
            <div class="stat-value"><?= (int) $totalIntegrations ?></div>
            <div class="stat-note">Services available to configure</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Payment currency</div>
            <div class="stat-value">XOF</div>
            <div class="stat-note">West African CFA franc</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Payment environment</div>
            <div class="stat-value" style="color:#a86b09;">Test</div>
            <div class="stat-note">Live payments are not enabled here</div>
        </div>
    </div>

    <div class="section-toolbar">
        <nav class="tabs" aria-label="Integration categories">
            <a class="tab <?= $activeTab === 'all' ? 'active' : '' ?>"
               href="?tab=all">All integrations</a>

            <a class="tab <?= $activeTab === 'payments' ? 'active' : '' ?>"
               href="?tab=payments">Payments</a>

            <a class="tab <?= $activeTab === 'communication' ? 'active' : '' ?>"
               href="?tab=communication">Communication</a>

            <a class="tab <?= $activeTab === 'analytics' ? 'active' : '' ?>"
               href="?tab=analytics">Analytics</a>

            <a class="tab <?= $activeTab === 'developer' ? 'active' : '' ?>"
               href="?tab=developer">Developer & API</a>
        </nav>

        <input
            type="search"
            id="integrationSearch"
            class="search-box"
            placeholder="Search integrations..."
            aria-label="Search integrations"
        >
    </div>

    <?php
    $sectionTitles = [
        'payments' => 'Payments',
        'communication' => 'Communication',
        'analytics' => 'Marketing & Analytics',
    ];

    foreach ($integrations as $sectionKey => $items):
        if ($activeTab !== 'all' && $activeTab !== $sectionKey) {
            continue;
        }
    ?>

        <section class="integration-section">
            <div class="section-heading">
                <h2><?= htmlspecialchars($sectionTitles[$sectionKey]) ?></h2>
                <span><?= count($items) ?> service<?= count($items) === 1 ? '' : 's' ?></span>
            </div>

            <div class="integration-grid">
                <?php foreach ($items as $item): ?>
                    <article
                        class="integration-card"
                        data-search="<?= htmlspecialchars(strtolower(
                            $item['name'] . ' ' .
                            $item['description'] . ' ' .
                            implode(' ', $item['features'])
                        )) ?>"
                    >
                        <div class="card-top">
                            <div class="integration-identity">
                                <div class="integration-icon <?= htmlspecialchars($item['class']) ?>">
                                    <?= htmlspecialchars($item['icon']) ?>
                                </div>

                                <div>
                                    <div class="integration-name">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </div>
                                    <div class="integration-category">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </div>
                                </div>
                            </div>

                            <span class="status <?= integrationStatusClass($item['status']) ?>">
                                <?= htmlspecialchars($item['status']) ?>
                            </span>
                        </div>

                        <p class="description">
                            <?= htmlspecialchars($item['description']) ?>
                        </p>

                        <div class="features">
                            <?php foreach ($item['features'] as $feature): ?>
                                <span class="feature">
                                    <?= htmlspecialchars($feature) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <div class="card-footer">
                            <span class="local-label">
                                <?= $sectionKey === 'payments'
                                    ? 'For Côte d’Ivoire'
                                    : 'Optional integration' ?>
                            </span>

                            <?php if ($item['url'] !== ''): ?>
                                <a class="btn <?= $item['name'] === 'Paystack' ? '' : 'btn-secondary' ?>"
                                   href="<?= htmlspecialchars($item['url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer">
                                    <?= htmlspecialchars($item['action']) ?> ↗
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary"
                                        type="button"
                                        data-message="Email configuration will be connected in the next step.">
                                    <?= htmlspecialchars($item['action']) ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    <?php endforeach; ?>

    <?php if ($activeTab === 'all' || $activeTab === 'developer'): ?>
        <section class="integration-section">
            <div class="section-heading">
                <h2>Developer & API</h2>
                <span>Technical configuration</span>
            </div>

            <div class="developer-card">
                <h3>Paystack webhook endpoint</h3>
                <p>
                    Paystack sends payment-event notifications to your server.
                    Configure the URL in your Paystack dashboard after the
                    actual webhook endpoint has been created.
                </p>

                <div class="field">
                    <label for="webhookUrl">Suggested webhook URL</label>
                    <div class="field-row">
                        <input
                            type="text"
                            id="webhookUrl"
                            value="<?= htmlspecialchars($webhookUrl) ?>"
                            readonly
                        >
                        <button class="btn btn-secondary"
                                type="button"
                                id="copyWebhook">
                            Copy URL
                        </button>
                    </div>
                </div>

                <div class="security-note">
                    <strong>Important:</strong> This is a suggested URL, not
                    a confirmation that the endpoint exists. Do not configure
                    it until
                    <code>webhooks/paystack.php</code> has been created and
                    tested. For a production deployment, use your real HTTPS
                    domain rather than localhost.
                </div>
            </div>

            <div class="developer-card">
                <h3>API keys</h3>
                <p>
                    API credentials are not stored or displayed by this
                    UI-only page. Keep secret keys on the PHP server and
                    outside public HTML, JavaScript, screenshots and source
                    control.
                </p>

                <div class="field">
                    <label>Payment gateway</label>
                    <input type="text" value="Paystack — Test environment" readonly>
                </div>

                <div class="security-note">
                    No secret key is being generated, saved, or exposed here.
                    We will connect secure server-side configuration separately.
                </div>
            </div>

            <div class="developer-card">
                <h3>Documentation</h3>
                <p>Use the official resources when configuring the real connections.</p>

                <div class="field-row">
                    <a class="btn btn-secondary"
                       href="https://paystack.com/docs/payments/payment-channels/"
                       target="_blank" rel="noopener noreferrer">
                        Payment channels ↗
                    </a>

                    <a class="btn btn-secondary"
                       href="https://paystack.com/docs/payments/webhooks/"
                       target="_blank" rel="noopener noreferrer">
                        Webhook guide ↗
                    </a>

                    <a class="btn btn-secondary"
                       href="https://paystack.com/docs/api/authentication/"
                       target="_blank" rel="noopener noreferrer">
                        API security ↗
                    </a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div id="emptyState" class="empty-state">
        No integrations match your search.
    </div>

</main>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script>
(() => {
    const searchInput = document.getElementById('integrationSearch');
    const emptyState = document.getElementById('emptyState');
    const toast = document.getElementById('toast');

    function showToast(message) {
        toast.textContent = message;
        toast.style.display = 'block';

        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => {
            toast.style.display = 'none';
        }, 3500);
    }

    searchInput?.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();
        const cards = [...document.querySelectorAll('.integration-card')];
        let visibleCount = 0;

        cards.forEach(card => {
            const matches = (card.dataset.search || '').includes(query);
            card.style.display = matches ? '' : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        document.querySelectorAll('.integration-section').forEach(section => {
            const sectionCards = [...section.querySelectorAll('.integration-card')];
            const hasVisibleCards = sectionCards.some(card => card.style.display !== 'none');

            section.style.display = sectionCards.length === 0 || hasVisibleCards ? '' : 'none';
        });

        emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
    });

    document.querySelectorAll('[data-message]').forEach(button => {
        button.addEventListener('click', () => {
            showToast(button.dataset.message);
        });
    });

    document.getElementById('copyWebhook')?.addEventListener('click', async () => {
        const field = document.getElementById('webhookUrl');

        try {
            await navigator.clipboard.writeText(field.value);
            showToast('Webhook URL copied.');
        } catch (error) {
            field.select();
            const copied = document.execCommand('copy');
            showToast(copied
                ? 'Webhook URL copied.'
                : 'Select the URL and copy it manually.');
        }
    });
})();
</script>

</body>
</html>
