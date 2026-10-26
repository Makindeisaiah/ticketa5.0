
<?php
require_once __DIR__ . '/auth.php';

if (!isset($pageTitle)) {
    $pageTitle = 'TICKETA | Discover Events';
}

if (!isset($pageStyles) || !is_array($pageStyles)) {
    $pageStyles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="stylesheet" href="assets/css/style.css?v=4">

    <?php foreach ($pageStyles as $stylesheet): ?>
        <link
            rel="stylesheet"
            href="<?= htmlspecialchars($stylesheet, ENT_QUOTES, 'UTF-8') ?>"
        >
    <?php endforeach; ?>
</head>
<body>

<header class="site-header">
    <div class="container">
        <div class="header-content">

            <a href="index.php" class="logo" aria-label="Ticketa home">
                TICKETA<span>.</span>
            </a>

            <button
                type="button"
                class="mobile-menu-toggle"
                id="mobileMenuToggle"
                aria-label="Toggle navigation"
                aria-controls="mainNavigation"
                aria-expanded="false"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="main-navigation" id="mainNavigation">

                <a href="events.php" data-i18n="nav_browse">
                    Browse Events
                </a>

                <a href="my-tickets.php" data-i18n="nav_how">
                    How it works
                </a>

                <a href="help.php" data-i18n="nav_help">
                    Help
                </a>

                <div class="header-actions">

                    <label class="visually-hidden" for="languageSelector">
                        Language
                    </label>

                    <select
                        id="languageSelector"
                        class="language-selector"
                        aria-label="Select language"
                    >
                        <option value="en">EN</option>
                        <option value="fr">FR</option>
                    </select>

                    <?php if (authIsLoggedIn()): ?>

                        <a href="account.php" class="account-link">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M5 21v-2a7 7 0 0 1 14 0v2"></path>
                            </svg>

                            <span>My Account</span>
                        </a>

                    <?php else: ?>

                        <a
                            href="login.php"
                            class="login-button"
                            data-i18n="nav_login"
                        >
                            Login
                        </a>

                    <?php endif; ?>

                    <a
                        href="organizer/register.php"
                        class="sell-tickets-button"
                        data-i18n="nav_sell"
                    >
                        Sell Tickets
                    </a>

                </div>
            </nav>

        </div>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('mobileMenuToggle');
    const navigation = document.getElementById('mainNavigation');

    if (toggle && navigation) {
        toggle.addEventListener('click', function () {
            const isOpen = navigation.classList.toggle('is-open');

            toggle.setAttribute('aria-expanded', String(isOpen));
        });
    }
});
</script>

</body>
</html>
