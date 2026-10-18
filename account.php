
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

// Account pages require an authenticated session.
if (!authIsLoggedIn()) {
    $_SESSION['auth_return_to'] = 'account.php';
    header('Location: login.php');
    exit;
}

$user = null;

try {
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, role, email_verified_at, created_at
         FROM users
         WHERE id = :id
           AND is_active = 1
         LIMIT 1'
    );

    $stmt->execute([
        'id' => (int) $_SESSION['user']['id'],
    ]);

    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

    if ($user['role'] !== 'attendee') {
        http_response_code(403);
        exit('You do not have permission to access this attendee page.');
    }

    if ($user['email_verified_at'] === null) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

} catch (PDOException $e) {
    error_log('Ticketa account page error: ' . $e->getMessage());
    http_response_code(500);
    exit('We could not load your account right now. Please try again later.');
}

function accountEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$pageTitle = 'My Account | TICKETA';
$pageStyles = [];

// Note: your existing header.php currently closes the HTML document.
// That should be corrected separately so page content remains inside <body>.
require __DIR__ . '/includes/header.php';
?>

<main class="account-page">
    <div class="account-container">

        <section class="account-heading">
            <div>
                <p class="account-eyebrow">YOUR TICKETA ACCOUNT</p>
                <h1>Welcome, <?= accountEscape($user['full_name']) ?></h1>
                <p>Manage your profile and continue discovering events.</p>
            </div>

            <span class="verified-badge">
                <span aria-hidden="true">&#10003;</span>
                Email verified
            </span>
        </section>

        <section class="account-grid">

            <article class="account-card profile-card">
                <div class="account-card-heading">
                    <div class="account-icon" aria-hidden="true">
                        &#9786;
                    </div>

                    <div>
                        <h2>Personal information</h2>
                        <p>Your registered account details.</p>
                    </div>
                </div>

                <div class="account-detail">
                    <span>Full name</span>
                    <strong><?= accountEscape($user['full_name']) ?></strong>
                </div>

                <div class="account-detail">
                    <span>Email address</span>
                    <strong><?= accountEscape($user['email']) ?></strong>
                </div>

                <div class="account-detail">
                    <span>Account type</span>
                    <strong>Attendee</strong>
                </div>

                <div class="account-detail">
                    <span>Email status</span>
                    <strong class="status-verified">Verified</strong>
                </div>

                <?php if (!empty($user['created_at'])): ?>
                    <div class="account-detail">
                        <span>Member since</span>
                        <strong>
                            <?= accountEscape(
                                date(
                                    'd M Y',
                                    strtotime($user['created_at'])
                                )
                            ) ?>
                        </strong>
                    </div>
                <?php endif; ?>
            </article>

            <article class="account-card tickets-card">
                <div class="account-card-heading">
                    <div class="account-icon" aria-hidden="true">
                        &#127915;
                    </div>

                    <div>
                        <h2>Your events</h2>
                        <p>Discover experiences worth attending.</p>
                    </div>
                </div>

                <p class="tickets-intro">
                    Your account is ready. Explore upcoming events and
                    find your next experience on Ticketa.
                </p>

                <a class="account-primary-button" href="events.php">
                    Browse events
                    <span aria-hidden="true">&rarr;</span>
                </a>

                <p class="tickets-note">
                    Your purchased-ticket history will be connected here
                    once orders are linked to attendee accounts.
                </p>
            </article>

        </section>

        <section class="account-actions">
            <div>
                <h2>Account actions</h2>
                <p>Finished using Ticketa? You can safely sign out here.</p>
            </div>

            <form action="logout.php" method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        authCsrfToken(),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <button type="submit" class="account-logout-button">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M10 17l5-5-5-5"></path>
                        <path d="M15 12H3"></path>
                        <path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"></path>
                    </svg>
                    Logout
                </button>
            </form>
        </section>

        <section class="account-footer">
            <div>
                <h2>Ready for your next experience?</h2>
                <p>Discover events and make your next outing memorable.</p>
            </div>

            <a href="events.php" class="account-secondary-button">
                Explore events
            </a>
        </section>

    </div>
</main>

<style>
.account-page {
    min-height: 70vh;
    padding: 48px 20px 64px;
    background: #f7f9fc;
    color: #17212b;
}

.account-container {
    max-width: 1080px;
    margin: 0 auto;
}

.account-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 30px;
}

.account-eyebrow {
    margin: 0 0 10px;
    color: #079b82;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 1.5px;
}

.account-heading h1 {
    margin: 0 0 10px;
    font-size: clamp(26px, 4vw, 36px);
    line-height: 1.2;
}

.account-heading > div > p:last-child {
    margin: 0;
    color: #657181;
    line-height: 1.6;
}

.verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 13px;
    border-radius: 999px;
    background: #e5faf3;
    color: #087c62;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

.account-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}

.account-card {
    min-width: 0;
    padding: 26px;
    border: 1px solid #e5eaf0;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(20, 35, 50, 0.035);
}

.account-card-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 25px;
}

.account-icon {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: #e8faf6;
    color: #079b82;
    font-size: 25px;
}

.account-card h2,
.account-footer h2,
.account-actions h2 {
    margin: 0 0 6px;
    font-size: 19px;
}

.account-card-heading p,
.account-footer p,
.account-actions p {
    margin: 0;
    color: #6b7685;
    font-size: 14px;
    line-height: 1.5;
}

.account-detail {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 15px 0;
    border-top: 1px solid #edf0f4;
}

.account-detail span {
    color: #758092;
    font-size: 13px;
}

.account-detail strong {
    overflow-wrap: anywhere;
    font-size: 15px;
    font-weight: 650;
}

.status-verified {
    color: #087c62;
}

.tickets-intro {
    margin: 0 0 22px;
    color: #596678;
    font-size: 14px;
    line-height: 1.8;
}

.account-primary-button,
.account-secondary-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 13px 18px;
    border-radius: 9px;
    background: #10c6a4;
    color: #102a26;
    font-size: 14px;
    font-weight: 750;
    text-decoration: none;
    transition: background 0.2s ease;
}

.account-primary-button:hover,
.account-secondary-button:hover {
    background: #0bb394;
}

.tickets-note {
    margin: 22px 0 0;
    color: #758092;
    font-size: 12px;
    line-height: 1.7;
}

.account-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-top: 24px;
    padding: 24px 26px;
    border: 1px solid #e5eaf0;
    border-radius: 15px;
    background: #fff;
}

.account-logout-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 11px 18px;
    border: 1px solid #f0ceca;
    border-radius: 9px;
    background: #fff;
    color: #c0392b;
    font-size: 14px;
    font-weight: 650;
    cursor: pointer;
    transition: background 0.2s ease, border-color 0.2s ease;
}

.account-logout-button:hover {
    background: #fff4f2;
    border-color: #dfaaa4;
}

.account-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-top: 24px;
    padding: 25px;
    border-radius: 15px;
    background: #eaf8f5;
}

.account-footer h2 {
    font-size: 18px;
}

.account-footer p {
    margin-top: 5px;
}

.account-footer .account-secondary-button {
    flex-shrink: 0;
}

@media (max-width: 700px) {
    .account-page {
        padding: 30px 16px 44px;
    }

    .account-heading {
        flex-direction: column;
        align-items: flex-start;
    }

    .account-grid {
        grid-template-columns: 1fr;
    }

    .account-card {
        padding: 21px;
    }

    .account-actions,
    .account-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
</body>
</html>
