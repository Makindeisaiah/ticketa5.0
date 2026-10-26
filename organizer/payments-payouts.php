
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

$currentPage = 'payments-payouts.php';
$topbarSearchPlaceholder = 'Search payments and payouts';

/*
|--------------------------------------------------------------------------
| Initial release configuration
|--------------------------------------------------------------------------
| This page is an interface only.
| It does not create payouts, refunds, or financial transactions.
| Paystack is currently in test mode, as confirmed by the developer.
*/

$paymentMode = 'TEST MODE';

function paymentsPageEscape($value): string
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

    <title>Payments &amp; Payouts | Ticketa</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f7f9fb;
            color: #252a38;
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            font-size: 14px;
        }

        .main {
            min-height: 100vh;
            padding-top: 90px;
        }

        .payments-content {
            width: min(100% - 48px, 1440px);
            margin: 0 auto;
            padding: 30px 0 50px;
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            font-size: 27px;
            font-weight: 750;
            letter-spacing: -0.6px;
        }

        .page-heading p {
            margin: 0;
            color: #7b8292;
            line-height: 1.7;
        }

        .mode-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            padding: 9px 12px;
            border: 1px solid #f2d89a;
            border-radius: 8px;
            background: #fff7df;
            color: #875d08;
            font-size: 12px;
            font-weight: 750;
        }

        .mode-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e5a91d;
        }

        .panel {
            min-width: 0;
            padding: 22px;
            border: 1px solid #e6e9ef;
            border-radius: 13px;
            background: #fff;
        }

        .tabs-panel {
            margin-bottom: 20px;
        }

        .tabs {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: -4px 0 22px;
            border-bottom: 1px solid #eceef2;
        }

        .tab-button {
            position: relative;
            padding: 13px 20px 15px;
            border: 0;
            background: transparent;
            color: #747b8a;
            font: inherit;
            font-size: 15px;
            font-weight: 650;
            cursor: pointer;
            white-space: nowrap;
        }

        .tab-button::after {
            position: absolute;
            right: 0;
            bottom: -1px;
            left: 0;
            height: 3px;
            background: transparent;
            content: "";
        }

        .tab-button.active {
            color: #242a37;
        }

        .tab-button.active::after {
            background: #16bfa4;
        }

        .tab-button:focus-visible,
        button:focus-visible,
        input:focus-visible,
        select:focus-visible {
            outline: 3px solid rgba(22, 191, 164, .28);
            outline-offset: 3px;
        }

        .tab-panel[hidden] {
            display: none !important;
        }

        .notice {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 15px 17px;
            margin-bottom: 22px;
            border: 1px solid #c6efd7;
            border-radius: 9px;
            background: #e8fbef;
            color: #245c3e;
            line-height: 1.6;
        }

        .notice-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            border-radius: 9px;
            background: #0fbea2;
            color: #fff;
            font-weight: 800;
        }

        .notice-copy {
            flex: 1;
            min-width: 0;
        }

        .notice-copy strong {
            display: block;
            margin-bottom: 3px;
        }

        .notice-copy p {
            margin: 0;
            font-size: 12px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .summary-card {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0;
            min-height: 125px;
            padding: 20px 17px;
            border: 1px solid #e7e9ef;
            border-radius: 10px;
        }

        .summary-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border-radius: 50%;
            background: #e4f9f4;
            color: #12b89d;
            font-size: 21px;
            font-weight: 750;
        }

        .summary-label {
            margin-bottom: 9px;
            color: #747b8a;
            font-size: 12px;
            line-height: 1.5;
        }

        .summary-value {
            overflow-wrap: anywhere;
            color: #252a38;
            font-size: clamp(17px, 1.5vw, 23px);
            font-weight: 750;
        }

        .summary-help {
            margin-top: 6px;
            color: #9096a4;
            font-size: 11px;
            line-height: 1.5;
        }

        .section-title {
            margin: 0 0 18px;
            font-size: 18px;
            font-weight: 700;
        }

        .overview-bottom {
            display: grid;
            grid-template-columns: minmax(0, .9fr) minmax(0, 1.35fr);
            gap: 20px;
        }

        .gateway-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .gateway-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #eef0f4;
        }

        .gateway-row:first-child {
            padding-top: 0;
        }

        .gateway-row:last-child {
            border-bottom: 0;
        }

        .gateway-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 39px;
            height: 39px;
            flex-shrink: 0;
            border: 1px solid #e9ebf0;
            border-radius: 9px;
            background: #fafbfc;
            color: #303648;
            font-size: 12px;
            font-weight: 800;
        }

        .gateway-copy {
            flex: 1;
            min-width: 0;
        }

        .gateway-name {
            font-weight: 650;
        }

        .gateway-description {
            margin-top: 4px;
            color: #878d9b;
            font-size: 12px;
            line-height: 1.5;
        }

        .status-label {
            display: inline-flex;
            align-items: center;
            padding: 5px 8px;
            border-radius: 20px;
            background: #fff4d7;
            color: #8b650e;
            font-size: 10px;
            font-weight: 750;
            white-space: nowrap;
        }

        .status-label.neutral {
            background: #eef0f4;
            color: #666e7d;
        }

        .info-banner {
            margin-top: 17px;
            padding: 13px;
            border-radius: 8px;
            background: #e8fbef;
            color: #34694b;
            font-size: 12px;
            line-height: 1.7;
        }

        .empty-state {
            display: flex;
            min-height: 245px;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
            text-align: center;
        }

        .empty-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 53px;
            height: 53px;
            margin-bottom: 15px;
            border-radius: 14px;
            background: #e7faf5;
            color: #0eaf95;
            font-size: 24px;
        }

        .empty-state h3 {
            margin: 0 0 9px;
            font-size: 15px;
        }

        .empty-state p {
            max-width: 390px;
            margin: 0;
            color: #858c9a;
            font-size: 12px;
            line-height: 1.8;
        }

        .table-toolbar {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
        }

        .search-input,
        .filter-select {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #e1e4ea;
            border-radius: 8px;
            background: #fff;
            color: #303646;
            font: inherit;
        }

        .search-input {
            flex: 1;
            min-width: 0;
        }

        .filter-select {
            width: 190px;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e9ebf0;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            padding: 13px 14px;
            background: #12bfa3;
            color: #fff;
            font-size: 12px;
            font-weight: 650;
            white-space: nowrap;
        }

        td {
            padding: 14px;
            border-bottom: 1px solid #eff1f5;
            color: #777e8c;
            font-size: 12px;
        }

        .table-empty {
            padding: 30px 14px;
            text-align: center;
            line-height: 1.7;
        }

        .table-empty strong {
            display: block;
            margin-bottom: 5px;
            color: #343949;
        }

        .sub-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .detail-list {
            display: flex;
            flex-direction: column;
        }

        .detail-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 15px 0;
            border-bottom: 1px solid #eef0f4;
        }

        .detail-row:first-child {
            padding-top: 0;
        }

        .detail-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            color: #7b8291;
            line-height: 1.6;
        }

        .detail-value {
            color: #303646;
            font-weight: 650;
            text-align: right;
            line-height: 1.6;
        }

        .coming-soon {
            display: inline-flex;
            margin-bottom: 14px;
            padding: 6px 10px;
            border-radius: 20px;
            background: #fff4d7;
            color: #8b650e;
            font-size: 11px;
            font-weight: 750;
        }

        .page-footnote {
            margin-top: 20px;
            color: #858c9a;
            font-size: 11px;
            line-height: 1.8;
        }

        @media (max-width: 1050px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .overview-bottom {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .main {
                padding-top: 80px;
            }

            .payments-content {
                width: calc(100% - 28px);
                padding-top: 23px;
            }

            .page-heading {
                flex-direction: column;
                gap: 13px;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .panel {
                padding: 16px;
            }

            .tabs-panel {
                padding: 14px;
            }

            .tabs {
                gap: 0;
                overflow-x: auto;
                margin-bottom: 17px;
            }

            .tab-button {
                padding: 13px 12px 15px;
                font-size: 12px;
            }

            .notice {
                align-items: flex-start;
                padding: 12px;
            }

            .notice-icon {
                width: 33px;
                height: 33px;
            }

            .summary-grid {
                gap: 10px;
            }

            .summary-card {
                min-height: 115px;
                flex-direction: column;
                align-items: flex-start;
                gap: 9px;
                padding: 13px;
            }

            .summary-icon {
                width: 34px;
                height: 34px;
                font-size: 17px;
            }

            .summary-label {
                margin-bottom: 5px;
                font-size: 11px;
            }

            .summary-value {
                font-size: 17px;
            }

            .sub-grid {
                grid-template-columns: 1fr;
            }

            .table-toolbar {
                flex-direction: column;
            }

            .filter-select {
                width: 100%;
            }

            th,
            td {
                padding: 11px;
            }
        }
    </style>
</head>

<body>

<div class="main">

    <?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

    <main class="payments-content">

        <header class="page-heading">
            <div>
                <h1>Payments &amp; Payouts</h1>
                <p>
                    Manage ticket payments, organizer earnings,
                    payouts, refunds, and payment fees.
                </p>
            </div>

            <span class="mode-badge">
                <span class="mode-dot"></span>
                <?= paymentsPageEscape($paymentMode) ?>
            </span>
        </header>

        <section class="panel tabs-panel">

            <div class="tabs" role="tablist"
                 aria-label="Payments and payouts sections">

                <button class="tab-button active"
                        id="tab-overview"
                        type="button"
                        role="tab"
                        aria-selected="true"
                        aria-controls="panel-overview"
                        data-tab="overview">
                    Overview
                </button>

                <button class="tab-button"
                        id="tab-payouts"
                        type="button"
                        role="tab"
                        aria-selected="false"
                        aria-controls="panel-payouts"
                        data-tab="payouts"
                        tabindex="-1">
                    Payouts
                </button>

                <button class="tab-button"
                        id="tab-refunds"
                        type="button"
                        role="tab"
                        aria-selected="false"
                        aria-controls="panel-refunds"
                        data-tab="refunds"
                        tabindex="-1">
                    Refunds &amp; Fees
                </button>

            </div>

            <!-- OVERVIEW TAB -->
            <div class="tab-panel"
                 id="panel-overview"
                 role="tabpanel"
                 aria-labelledby="tab-overview">

                <div class="notice">
                    <div class="notice-icon">P</div>

                    <div class="notice-copy">
                        <strong>Paystack test mode</strong>
                        <p>
                            The payment integration is being tested.
                            No real settlements or payouts are represented here.
                        </p>
                    </div>
                </div>

                <div class="summary-grid">

                    <article class="summary-card">
                        <div class="summary-icon">₣</div>
                        <div>
                            <div class="summary-label">
                                Available for payout
                            </div>
                            <div class="summary-value">0 FCFA</div>
                            <div class="summary-help">
                                No live payout balance connected
                            </div>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon">◷</div>
                        <div>
                            <div class="summary-label">Pending funds</div>
                            <div class="summary-value">0 FCFA</div>
                            <div class="summary-help">
                                Verified transaction data not connected
                            </div>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon">↗</div>
                        <div>
                            <div class="summary-label">Total ticket sales</div>
                            <div class="summary-value">0 FCFA</div>
                            <div class="summary-help">
                                No sales figures loaded
                            </div>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon">▦</div>
                        <div>
                            <div class="summary-label">Next payout</div>
                            <div class="summary-value">Not scheduled</div>
                            <div class="summary-help">
                                Payout integration not configured
                            </div>
                        </div>
                    </article>

                </div>

            </div>

            <!-- PAYOUTS TAB -->
            <div class="tab-panel"
                 id="panel-payouts"
                 role="tabpanel"
                 aria-labelledby="tab-payouts"
                 hidden>

                <div class="sub-grid">

                    <section class="panel">
                        <h2 class="section-title">Payout Settings</h2>

                        <span class="coming-soon">
                            Setup required
                        </span>

                        <div class="detail-list">
                            <div class="detail-row">
                                <span class="detail-label">
                                    Payout schedule
                                </span>
                                <span class="detail-value">
                                    Not configured
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Available balance
                                </span>
                                <span class="detail-value">
                                    Not connected
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Transfer status
                                </span>
                                <span class="detail-value">
                                    Not enabled
                                </span>
                            </div>
                        </div>

                        <div class="info-banner">
                            Ticket checkout and organizer payouts are
                            separate workflows. We will enable payouts
                            after verifying the supported transfer method.
                        </div>
                    </section>

                    <section class="panel">
                        <h2 class="section-title">Payout Destination</h2>

                        <div class="empty-state">
                            <div class="empty-icon">⌂</div>

                            <h3>No payout destination configured</h3>

                            <p>
                                Supported bank or mobile-money destinations
                                will appear here after the payout integration
                                and verification process are implemented.
                            </p>
                        </div>
                    </section>

                </div>

                <section class="panel" style="margin-top:20px">
                    <h2 class="section-title">Payout History</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Payout date</th>
                                    <th>Amount</th>
                                    <th>Destination</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <td colspan="4" class="table-empty">
                                        <strong>No payout records yet</strong>
                                        Payout history will appear when
                                        verified payout records are connected.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>

            <!-- REFUNDS AND FEES TAB -->
            <div class="tab-panel"
                 id="panel-refunds"
                 role="tabpanel"
                 aria-labelledby="tab-refunds"
                 hidden>

                <div class="sub-grid">

                    <section class="panel">
                        <h2 class="section-title">Refund Overview</h2>

                        <div class="detail-list">
                            <div class="detail-row">
                                <span class="detail-label">
                                    Refund requests
                                </span>
                                <span class="detail-value">Not connected</span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Completed refunds
                                </span>
                                <span class="detail-value">Not connected</span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Total refunded
                                </span>
                                <span class="detail-value">Not calculated</span>
                            </div>
                        </div>

                        <div class="info-banner">
                            Refund processing will be connected to
                            verified transaction records and the applicable
                            event refund policy.
                        </div>
                    </section>

                    <section class="panel">
                        <h2 class="section-title">Fees Breakdown</h2>

                        <div class="detail-list">
                            <div class="detail-row">
                                <span class="detail-label">
                                    Gross ticket sales
                                </span>
                                <span class="detail-value">Not connected</span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Paystack processing fees
                                </span>
                                <span class="detail-value">Not calculated</span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Ticketa platform fees
                                </span>
                                <span class="detail-value">Not configured</span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">
                                    Net organizer proceeds
                                </span>
                                <span class="detail-value">Not calculated</span>
                            </div>
                        </div>

                    </section>

                </div>

                <section class="panel" style="margin-top:20px">
                    <h2 class="section-title">Refund History</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Refund date</th>
                                    <th>Ticket reference</th>
                                    <th>Amount</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <td colspan="5" class="table-empty">
                                        <strong>No refund records yet</strong>
                                        Refund records will appear here
                                        after the refund workflow is connected.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>

        </section>

        <p class="page-footnote">
            This page currently displays interface placeholders.
            It does not initiate payments, calculate actual balances,
            process refunds, or send payouts. All displayed zero amounts
            are empty-state placeholders, not verified account balances.
        </p>

    </main>

</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.tab-button');
    const panels = document.querySelectorAll('.tab-panel');

    function activateTab(tabName, updateUrl = true) {
        buttons.forEach(function (button) {
            const isActive = button.dataset.tab === tabName;

            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', String(isActive));
            button.tabIndex = isActive ? 0 : -1;
        });

        panels.forEach(function (panel) {
            panel.hidden = panel.id !== 'panel-' + tabName;
        });

        if (updateUrl && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        }
    }

    buttons.forEach(function (button, index) {
        button.addEventListener('click', function () {
            activateTab(button.dataset.tab);
        });

        button.addEventListener('keydown', function (event) {
            if (
                event.key === 'ArrowRight' ||
                event.key === 'ArrowLeft'
            ) {
                event.preventDefault();

                const direction = event.key === 'ArrowRight' ? 1 : -1;
                const nextIndex =
                    (index + direction + buttons.length) % buttons.length;

                buttons[nextIndex].focus();
                activateTab(buttons[nextIndex].dataset.tab);
            }
        });
    });

    const initialTab = new URLSearchParams(window.location.search)
        .get('tab');

    const validTabs = ['overview', 'payouts', 'refunds'];

    activateTab(
        validTabs.includes(initialTab) ? initialTab : 'overview',
        false
    );
})();
</script>

</body>
</html>
