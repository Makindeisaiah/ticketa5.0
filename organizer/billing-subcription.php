
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

$currentPage = 'billing-subscription.php';
$topbarSearchPlaceholder = 'Search billing settings';

$organizerName = $user['full_name'] ?? 'Organizer';

/*
|--------------------------------------------------------------------------
| Starter plan — initial launch configuration
|--------------------------------------------------------------------------
| Starter is the active plan for this first version.
| Pro is displayed as coming soon.
| No payments are processed on this page.
*/

$currentPlan = [
    'name' => 'Starter',
    'price' => 'Free',
    'description' => 'For organizers getting started on Ticketa',
    'features' => [
        'Create and manage events',
        'Sell event tickets',
        'Basic ticket check-in',
        'Standard Ticketa platform fees',
        'Ticketa branding',
        'Standard support',
    ],
];

$proPlan = [
    'name' => 'Pro',
    'price' => 'Coming soon',
    'description' => 'Advanced tools for growing event organizers',
    'features' => [
        'Multiple active events',
        'Reduced ticket fees',
        'Advanced QR check-in',
        'Team management and permissions',
        'Sales analytics and reports',
        'Priority support',
    ],
];

$billingHistory = [];
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Billing history
|--------------------------------------------------------------------------
| Do not display invented transactions.
| Until a real billing ledger is implemented, show an empty state.
*/

try {
    // The billing history table will be connected when real billing
    // records and subscription payments are implemented.
} catch (Throwable $exception) {
    error_log(
        'Ticketa billing page error: ' . $exception->getMessage()
    );

    $errorMessage = 'Billing information is temporarily unavailable.';
}

function billingPageEscape($value): string
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

    <title>Billing &amp; Subscription | Ticketa</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8f9fc;
            color: #242735;
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            font-size: 14px;
        }

        .main {
            min-height: 100vh;
            padding-top: 90px;
        }

        .billing-content {
            width: min(100% - 48px, 1440px);
            margin: 0 auto;
            padding: 30px 0 55px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 750;
            letter-spacing: -0.7px;
        }

        .page-heading p {
            margin: 0;
            max-width: 650px;
            color: #777e90;
            font-size: 14px;
            line-height: 1.7;
        }

        .panel {
            background: #fff;
            border: 1px solid #e7e9ef;
            border-radius: 13px;
            padding: 22px;
            min-width: 0;
        }

        .panel-title {
            margin: 0 0 20px;
            color: #262a38;
            font-size: 19px;
            font-weight: 700;
        }

        .plans-panel {
            margin-bottom: 20px;
        }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
        }

        .plan-card {
            display: flex;
            flex-direction: column;
            min-width: 0;
            padding: 24px;
            border: 1px solid #e3e6eb;
            border-radius: 9px;
        }

        .plan-card.current {
            background: #e0fbe8;
            border-color: #d1f0dc;
        }

        .plan-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 7px;
        }

        .plan-name {
            margin: 0;
            font-size: 21px;
            font-weight: 700;
        }

        .plan-price {
            margin: 5px 0 9px;
            font-size: 23px;
            font-weight: 750;
            color: #202535;
        }

        .plan-description {
            margin: 0 0 17px;
            color: #777e90;
            line-height: 1.6;
        }

        .current .plan-description {
            color: #547262;
        }

        .plan-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 20px;
            background: #d2f4de;
            color: #176b45;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .coming-badge {
            background: #fff4d5;
            color: #95640b;
        }

        .feature-list {
            display: flex;
            flex-direction: column;
            gap: 13px;
            padding: 0;
            margin: 5px 0 24px;
            list-style: none;
        }

        .feature-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #454a58;
            line-height: 1.5;
        }

        .feature-check {
            flex-shrink: 0;
            color: #19b99f;
            font-size: 17px;
            font-weight: 800;
        }

        .plan-footer {
            margin-top: auto;
        }

        .plan-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 43px;
            padding: 11px 15px;
            border: 0;
            border-radius: 6px;
            background: #0fbea2;
            color: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 650;
            text-align: center;
            text-decoration: none;
        }

        .plan-button.disabled {
            background: #e8e9ee;
            color: #7b8190;
            cursor: not-allowed;
        }

        .plan-note {
            margin: 10px 0 0;
            color: #858b98;
            font-size: 12px;
            line-height: 1.5;
            text-align: center;
        }

        .billing-grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(280px, 0.95fr);
            gap: 24px;
            align-items: stretch;
        }

        .billing-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 225px;
            padding: 25px 15px;
            text-align: center;
        }

        .placeholder-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            margin-bottom: 15px;
            border-radius: 14px;
            background: #e9faf6;
            color: #0bb99e;
        }

        .placeholder-icon svg {
            width: 25px;
            height: 25px;
        }

        .billing-placeholder h3 {
            margin: 0 0 8px;
            font-size: 15px;
        }

        .billing-placeholder p {
            max-width: 370px;
            margin: 0;
            color: #858b98;
            font-size: 13px;
            line-height: 1.7;
        }

        .billing-info {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .info-item {
            padding-bottom: 16px;
            border-bottom: 1px solid #eff0f4;
        }

        .info-item:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .info-label {
            display: block;
            margin-bottom: 7px;
            color: #858b98;
            font-size: 12px;
        }

        .info-value {
            color: #292d3b;
            font-size: 14px;
            font-weight: 650;
        }

        .info-help {
            margin-top: 6px;
            color: #858b98;
            font-size: 12px;
            line-height: 1.6;
        }

        .info-status {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e0f8e9;
            color: #176b45;
            font-size: 12px;
            font-weight: 650;
        }

        .error-notice {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 9px;
            background: #fff0f1;
            border: 1px solid #f3cbd0;
            color: #a12b36;
        }

        @media (max-width: 900px) {
            .billing-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .main {
                padding-top: 80px;
            }

            .billing-content {
                width: calc(100% - 28px);
                padding-top: 24px;
            }

            .page-heading h1 {
                font-size: 24px;
            }

            .panel {
                padding: 16px;
            }

            .plans-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .plan-card {
                padding: 18px;
            }

            .panel-title {
                font-size: 17px;
            }
        }
    </style>
</head>

<body>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

    <main class="billing-content">

        <header class="page-heading">
            <h1>Billing &amp; Subscription</h1>
            <p>
                Manage your Ticketa plan, review billing information,
                and discover the tools available to grow your events.
            </p>
        </header>

        <?php if ($errorMessage !== ''): ?>
            <div class="error-notice" role="alert">
                <?= billingPageEscape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Plan Details -->
        <section class="panel plans-panel">

            <h2 class="panel-title">Plan Details</h2>

            <div class="plans-grid">

                <!-- Current Starter plan -->
                <article class="plan-card current">

                    <div class="plan-top">
                        <h3 class="plan-name">
                            <?= billingPageEscape($currentPlan['name']) ?>
                        </h3>

                        <span class="plan-badge">Current plan</span>
                    </div>

                    <div class="plan-price">
                        <?= billingPageEscape($currentPlan['price']) ?>
                    </div>

                    <p class="plan-description">
                        <?= billingPageEscape($currentPlan['description']) ?>
                    </p>

                    <ul class="feature-list">
                        <?php foreach ($currentPlan['features'] as $feature): ?>
                            <li>
                                <span class="feature-check" aria-hidden="true">✓</span>
                                <span><?= billingPageEscape($feature) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="plan-footer">
                        <div class="plan-button">
                            Current Plan
                        </div>

                        <p class="plan-note">
                            You're on the free Starter plan.
                        </p>
                    </div>

                </article>

                <!-- Pro plan: not yet available -->
                <article class="plan-card">

                    <div class="plan-top">
                        <h3 class="plan-name">
                            <?= billingPageEscape($proPlan['name']) ?>
                        </h3>

                        <span class="plan-badge coming-badge">
                            Coming soon
                        </span>
                    </div>

                    <div class="plan-price">
                        <?= billingPageEscape($proPlan['price']) ?>
                    </div>

                    <p class="plan-description">
                        <?= billingPageEscape($proPlan['description']) ?>
                    </p>

                    <ul class="feature-list">
                        <?php foreach ($proPlan['features'] as $feature): ?>
                            <li>
                                <span class="feature-check" aria-hidden="true">✓</span>
                                <span><?= billingPageEscape($feature) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="plan-footer">
                        <div class="plan-button disabled"
                             aria-disabled="true">
                            Pro — Coming Soon
                        </div>

                        <p class="plan-note">
                            Paid plans will be announced when available.
                        </p>
                    </div>

                </article>

            </div>

        </section>

        <!-- Billing History and Account Billing Information -->
        <div class="billing-grid">

            <section class="panel">

                <h2 class="panel-title">Billing History</h2>

                <div class="billing-placeholder">

                    <div class="placeholder-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="3" width="14" height="18" rx="2"></rect>
                            <path d="M9 8h6M9 12h6M9 16h3"></path>
                        </svg>
                    </div>

                    <h3>No billing transactions yet</h3>

                    <p>
                        Your subscription payments and receipts will appear
                        here when paid billing becomes available.
                    </p>

                </div>

            </section>

            <section class="panel">

                <h2 class="panel-title">Billing Overview</h2>

                <div class="billing-info">

                    <div class="info-item">
                        <span class="info-label">Current plan</span>
                        <div class="info-value">Starter</div>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Subscription status</span>
                        <span class="info-status">Free plan active</span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Plan price</span>
                        <div class="info-value">Free</div>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Next billing date</span>
                        <div class="info-value">Not applicable</div>
                        <div class="info-help">
                            The Starter plan has no subscription renewal date.
                        </div>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Payment method</span>
                        <div class="info-value">Not required</div>
                        <div class="info-help">
                            You don't need to add a payment method to use
                            the free Starter plan.
                        </div>
                    </div>

                </div>

            </section>

        </div>

    </main>

</div>

</body>
</html>
