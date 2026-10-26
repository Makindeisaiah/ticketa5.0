
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
$currentPage = 'legal-compliance';
$topbarSearchPlaceholder = 'Search settings...';

$defaults = [
    'privacy_policy' => '',
    'terms_of_service' => '',
    'refund_policy' => '',
    'attendee_consent_enabled' => 1,
    'marketing_opt_in_enabled' => 1,
    'retention_days' => 365,
];

$settings = $defaults;
$message = '';
$messageType = 'success';

try {
    $stmt = $pdo->prepare(
        'SELECT * FROM organizer_legal_settings WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($saved) {
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $saved)) {
                $settings[$key] = $saved[$key];
            }
        }
    }
} catch (PDOException $e) {
    error_log('Legal settings load error: ' . $e->getMessage());
    $message = 'Could not load your legal settings. Check that the database table has been created.';
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!is_string($csrf) || !authVerifyCsrf($csrf)) {
        $message = 'Your session security token is invalid. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'reset') {
            try {
                $stmt = $pdo->prepare(
                    'DELETE FROM organizer_legal_settings WHERE user_id = ?'
                );
                $stmt->execute([$userId]);

                header('Location: legal-compliance.php?reset=1');
                exit;
            } catch (PDOException $e) {
                error_log('Legal settings reset error: ' . $e->getMessage());
                $message = 'Unable to reset settings. Please try again.';
                $messageType = 'error';
            }
        } else {
            $privacyPolicy = trim((string) ($_POST['privacy_policy'] ?? ''));
            $terms = trim((string) ($_POST['terms_of_service'] ?? ''));
            $refundPolicy = trim((string) ($_POST['refund_policy'] ?? ''));

            $consent = isset($_POST['attendee_consent_enabled']) ? 1 : 0;
            $marketing = isset($_POST['marketing_opt_in_enabled']) ? 1 : 0;

            $retentionDays = filter_var(
                $_POST['retention_days'] ?? 365,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 36500]]
            );

            if ($retentionDays === false) {
                $message = 'Enter a data-retention period between 1 and 36500 days.';
                $messageType = 'error';
            } elseif (
                strlen($privacyPolicy) > 50000 ||
                strlen($terms) > 50000 ||
                strlen($refundPolicy) > 50000
            ) {
                $message = 'Each policy must be no longer than 50,000 characters.';
                $messageType = 'error';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO organizer_legal_settings
                            (user_id, privacy_policy, terms_of_service,
                             refund_policy, attendee_consent_enabled,
                             marketing_opt_in_enabled, retention_days)
                         VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            privacy_policy = VALUES(privacy_policy),
                            terms_of_service = VALUES(terms_of_service),
                            refund_policy = VALUES(refund_policy),
                            attendee_consent_enabled = VALUES(attendee_consent_enabled),
                            marketing_opt_in_enabled = VALUES(marketing_opt_in_enabled),
                            retention_days = VALUES(retention_days)'
                    );

                    $stmt->execute([
                        $userId,
                        $privacyPolicy,
                        $terms,
                        $refundPolicy,
                        $consent,
                        $marketing,
                        $retentionDays,
                    ]);

                    header('Location: legal-compliance.php?saved=1');
                    exit;
                } catch (PDOException $e) {
                    error_log('Legal settings save error: ' . $e->getMessage());
                    $message = 'Could not save your settings. Please check the database and try again.';
                    $messageType = 'error';
                }
            }

            $settings = [
                'privacy_policy' => $privacyPolicy,
                'terms_of_service' => $terms,
                'refund_policy' => $refundPolicy,
                'attendee_consent_enabled' => $consent,
                'marketing_opt_in_enabled' => $marketing,
                'retention_days' => $retentionDays === false ? 365 : $retentionDays,
            ];
        }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Your legal settings have been saved.';
    $messageType = 'success';
} elseif (isset($_GET['reset'])) {
    $message = 'Settings have been reset to Ticketa defaults.';
    $messageType = 'success';
}

$csrfToken = authCsrfToken();

$cards = [
    [
        'id' => 'gdpr',
        'title' => 'GDPR & Privacy Compliance',
        'description' => 'Review privacy practices and the data-protection requirements that apply to your organization.',
        'tag' => 'Privacy'
    ],
    [
        'id' => 'policies',
        'title' => 'Privacy Policy & Terms',
        'description' => 'Manage the privacy policy and terms that attendees should be able to review.',
        'tag' => 'Documents'
    ],
    [
        'id' => 'consent',
        'title' => 'Attendee Consent',
        'description' => 'Configure consent-related settings for attendee data collection.',
        'tag' => 'Consent'
    ],
    [
        'id' => 'data',
        'title' => 'Data Protection',
        'description' => 'Set a proposed retention period and review data-access and deletion procedures.',
        'tag' => 'Data'
    ],
    [
        'id' => 'refunds',
        'title' => 'Refund & Cancellation Policy',
        'description' => 'Write the refund and cancellation policy that applies to your events.',
        'tag' => 'Refunds'
    ],
    [
        'id' => 'spam',
        'title' => 'Anti-Spam & Opt-In Preferences',
        'description' => 'Configure your marketing opt-in preference for future email workflows.',
        'tag' => 'Email'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal & Compliance | Ticketa</title>

    <style>
        :root {
            --green: #12bea6;
            --green-dark: #079e8a;
            --text: #202124;
            --muted: #6c7078;
            --border: #e6e8eb;
            --background: #ffffff;
            --soft: #f7f9fa;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #fff;
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                         "Segoe UI", sans-serif;
        }

        .main {
            padding: 30px 28px 45px;
            max-width: 1550px;
            margin: 0 auto;
        }

        .page-shell {
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 22px 20px;
            min-height: 78vh;
            display: flex;
            flex-direction: column;
        }

        .page-heading {
            font-size: 21px;
            font-weight: 650;
            margin: 0 0 22px;
        }

        .intro {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
            margin: -12px 0 22px;
        }

        .notice {
            border: 1px solid #ccefe9;
            background: #f0fbf9;
            color: #246c61;
            padding: 13px 15px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.6;
        }

        .flash {
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            margin-bottom: 18px;
        }

        .flash.success {
            color: #126b4d;
            background: #eaf8f1;
        }

        .flash.error {
            color: #a32936;
            background: #fff0f1;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px 26px;
        }

        .legal-card {
            min-height: 218px;
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 21px 20px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            background: #fff;
        }

        .legal-card h2 {
            font-size: 19px;
            line-height: 1.4;
            font-weight: 600;
            margin: 0 0 19px;
        }

        .legal-card p {
            font-size: 14px;
            line-height: 1.55;
            color: #3f4145;
            margin: 0 0 24px;
        }

        .btn {
            border: 1px solid transparent;
            background: var(--green);
            color: #fff;
            border-radius: 5px;
            padding: 12px 17px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn:hover { background: var(--green-dark); }

        .btn-outline {
            background: #fff;
            border-color: #aaa;
            color: #333;
        }

        .btn-outline:hover {
            background: #f7f7f7;
            color: #222;
        }

        .card-button { margin-top: auto; }

        .bottom-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: auto;
            padding-top: 35px;
        }

        .editor {
            display: none;
            margin-top: 25px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 22px;
            background: #fff;
        }

        .editor.open { display: block; }

        .editor-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 18px;
        }

        .editor h2 { margin: 0 0 7px; font-size: 19px; }

        .editor-head p {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        .close-editor {
            background: #f2f4f6;
            border: 0;
            border-radius: 7px;
            padding: 8px 11px;
            cursor: pointer;
            font-size: 13px;
        }

        .field { margin-bottom: 18px; }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 650;
            margin-bottom: 8px;
        }

        .field small {
            display: block;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            margin-top: 7px;
        }

        textarea, input[type="number"] {
            width: 100%;
            border: 1px solid #dfe2e7;
            border-radius: 7px;
            padding: 12px;
            font: inherit;
            font-size: 13px;
            background: #fff;
        }

        textarea {
            min-height: 135px;
            resize: vertical;
            line-height: 1.6;
        }

        input[type="number"] { max-width: 230px; }

        .check-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 14px 0;
            font-size: 13px;
            line-height: 1.6;
        }

        .check-row input {
            margin-top: 3px;
            accent-color: var(--green);
        }

        .subheading {
            font-size: 14px;
            font-weight: 700;
            margin: 22px 0 10px;
        }

        .legal-link {
            color: #087f72;
            text-decoration: underline;
        }

        @media (max-width: 1000px) {
            .card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 650px) {
            .main { padding: 18px 12px 30px; }
            .page-shell { padding: 17px 13px; }
            .card-grid { grid-template-columns: 1fr; gap: 14px; }
            .legal-card { min-height: 190px; padding: 18px; }
            .legal-card h2 { font-size: 18px; }
            .bottom-actions { flex-direction: column; }
            .bottom-actions .btn { width: 100%; }
            .editor { padding: 16px; }
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__) . '/includes/settings-topbar.php'; ?>

<main class="main">
    <section class="page-shell">

        <h1 class="page-heading">Legal &amp; Compliance</h1>

        <p class="intro">
            Manage your policies and privacy-related settings for your Ticketa
            organizer account. Review the requirements that apply to your
            activities before publishing policies.
        </p>

        <div class="notice">
            <strong>For Côte d’Ivoire:</strong>
            Review Law No. 2013-450 on personal-data protection.
            GDPR may also apply depending on your activities and the people
            whose data you process.
            <a class="legal-link"
               href="https://artci.ci/en/law-no-2013-450-of-19-june-2013-relating-to-the-protection-of-personal-data/"
               target="_blank" rel="noopener noreferrer">Read the official law ↗</a>
        </div>

        <?php if ($message !== ''): ?>
            <div class="flash <?= $messageType === 'error' ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="card-grid">
            <?php foreach ($cards as $card): ?>
                <article class="legal-card">
                    <h2><?= htmlspecialchars($card['title']) ?></h2>
                    <p><?= htmlspecialchars($card['description']) ?></p>
                    <button
                        class="btn card-button open-editor"
                        type="button"
                        data-panel="<?= htmlspecialchars($card['id']) ?>"
                    >View Details</button>
                </article>
            <?php endforeach; ?>
        </div>

        <form method="post" action="legal-compliance.php" id="legalSettingsForm">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" id="formAction" value="save">

            <section class="editor" id="panel-gdpr">
                <div class="editor-head">
                    <div>
                        <h2>GDPR &amp; Privacy Compliance</h2>
                        <p>Review your privacy responsibilities and keep your policies up to date.</p>
                    </div>
                    <button type="button" class="close-editor">Close</button>
                </div>
                <div class="notice">
                    This checklist is informational. It does not determine whether
                    your organization is legally compliant.
                </div>
                <label class="check-row">
                    <input type="checkbox" disabled>
                    <span>Review what attendee data Ticketa collects and why.</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" disabled>
                    <span>Review retention, access controls and data-sharing arrangements.</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" disabled>
                    <span>Confirm whether registration, declarations or other steps with the relevant authority apply.</span>
                </label>
                <p>
                    Official reference:
                    <a class="legal-link"
                       href="https://artci.ci/en/law-no-2013-450-of-19-june-2013-relating-to-the-protection-of-personal-data/"
                       target="_blank" rel="noopener noreferrer">Côte d’Ivoire personal-data law ↗</a>
                </p>
            </section>

            <section class="editor" id="panel-policies">
                <div class="editor-head">
                    <div>
                        <h2>Privacy Policy &amp; Terms</h2>
                        <p>Save the policy text for later use in the attendee-facing pages.</p>
                    </div>
                    <button type="button" class="close-editor">Close</button>
                </div>
                <div class="field">
                    <label for="privacy_policy">Privacy policy</label>
                    <textarea name="privacy_policy" id="privacy_policy"
                              maxlength="50000"
                              placeholder="Explain what personal data is collected, why it is used, who it may be shared with, how long it is retained, and how people can exercise their rights."><?= htmlspecialchars((string) $settings['privacy_policy']) ?></textarea>
                    <small>Saved here only. It is not automatically published on your website.</small>
                </div>
                <div class="field">
                    <label for="terms_of_service">Terms of service</label>
                    <textarea name="terms_of_service" id="terms_of_service"
                              maxlength="50000"
                              placeholder="Enter the terms attendees should review when using Ticketa or registering for an event."><?= htmlspecialchars((string) $settings['terms_of_service']) ?></textarea>
                    <small>Have the terms reviewed for the jurisdictions and services involved.</small>
                </div>
            </section>

            <section class="editor" id="panel-consent">
                <div class="editor-head">
                    <div>
                        <h2>Attendee Consent</h2>
                        <p>Set the intended defaults for consent-related features.</p>
                    </div>
                    <button type="button" class="close-editor">Close</button>
                </div>
                <label class="check-row">
                    <input type="checkbox" name="attendee_consent_enabled" value="1"
                        <?= (int) $settings['attendee_consent_enabled'] === 1 ? 'checked' : '' ?>>
                    <span>
                        Enable attendee consent settings.
                        This preference will not add a consent checkbox to checkout
                        until that workflow is connected.
                    </span>
                </label>
                <div class="notice">
                    Keep marketing consent separate from consent or other lawful
                    grounds needed to process an order or issue a ticket.
                </div>
            </section>

            <section class="editor" id="panel-data">
                <div class="editor-head">
                    <div>
                        <h2>Data Protection</h2>
                        <p>Configure a proposed data-retention period for planning purposes.</p>
                    </div>
                    <button type="button" class="close-editor">Close</button>
                </div>
                <div class="field">
                    <label for="retention_days">Proposed retention period (days)</label>
                    <input type="number" name="retention_days" id="retention_days"
                           min="1" max="36500"
                           value="<?= (int) $settings['retention_days'] ?>" required>
                    <small>
                        This is only a saved setting. It does not automatically
                        delete records. Retention must be appropriate to the
                        purpose and applicable legal obligations.
                    </small>
                </div>
                <div class="notice">
                    Access, correction and deletion requests still need a secure
                    operational workflow. This page does not process those requests.
                </div>
            </section>

            <section class="editor" id="panel-refunds">
                <div class="editor-head">
                    <div>
                        <h2>Refund &amp; Cancellation Policy</h2>
                        <p>Save the policy text you intend to show to attendees.</p>
                    </div>
                    <button type="button" class="close-editor">Close</button>
                </div>
                <div class="field">
                    <label for="refund_policy">Refund and cancellation policy</label>
                    <textarea name="refund_policy" id="refund_policy"
                              maxlength="50000"
                              placeholder="Explain cancellation conditions, refund eligibility, deadlines, organizer cancellations, and how attendees can request help."><?= htmlspecialchars((string) $settings['refund_policy']) ?></textarea>
                    <small>
                        This text is not yet connected to checkout, ticket pages,
                        or the payment/refund workflow.
                    </small>
                </div>
            </section>

            <section class="editor" id="panel-spam">
                <div class="editor-head">
                    <div>
                        <h2>Anti-Spam &amp; Opt-In Preferences</h2>
                        <p>Set the intended preference for marketing email opt-in features.</p>
                    </div>
                    <button type="button" class="close-editor">Close</button>
                </div>
                <label class="check-row">
                    <input type="checkbox" name="marketing_opt_in_enabled" value="1"
                        <?= (int) $settings['marketing_opt_in_enabled'] === 1 ? 'checked' : '' ?>>
                    <span>
                        Enable the marketing opt-in preference in future attendee
                        forms. This does not subscribe attendees automatically.
                    </span>
                </label>
                <div class="notice">
                    Marketing emails should respect the attendee's choice and
                    provide a working unsubscribe method. The actual email
                    subscription and unsubscribe workflow must be implemented separately.
                </div>
            </section>

            <div class="bottom-actions">
                <button class="btn" type="submit"
                        onclick="document.getElementById('formAction').value='save'">
                    Save Defaults
                </button>

                <button class="btn btn-outline" type="submit"
                        onclick="return confirm('Reset all saved legal settings to Ticketa defaults?') &&
                            (document.getElementById('formAction').value='reset', true)">
                    Reset to Ticketa Defaults
                </button>
            </div>
        </form>

    </section>
</main>

<script>
(() => {
    const panels = [...document.querySelectorAll('.editor')];

    function openPanel(id) {
        panels.forEach(panel => panel.classList.remove('open'));

        const target = document.getElementById('panel-' + id);
        if (target) {
            target.classList.add('open');
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    document.querySelectorAll('.open-editor').forEach(button => {
        button.addEventListener('click', () => {
            openPanel(button.dataset.panel);
        });
    });

    document.querySelectorAll('.close-editor').forEach(button => {
        button.addEventListener('click', () => {
            button.closest('.editor')?.classList.remove('open');
        });
    });
})();
</script>

</body>
</html>
