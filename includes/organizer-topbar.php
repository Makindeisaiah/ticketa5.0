
<?php

$topbarOrganizerName = $organizerName ?? '';

if ($topbarOrganizerName === '') {
    $topbarOrganizerName = $_SESSION['user']['full_name'] ?? 'Organizer';
}

if (
    isset($pdo, $_SESSION['user']['id']) &&
    (!isset($organizerName) || $organizerName === '')
) {
    try {
        $topbarStmt = $pdo->prepare(
            'SELECT business_name
             FROM organizers
             WHERE user_id = :user_id
             LIMIT 1'
        );

        $topbarStmt->execute([
            'user_id' => $_SESSION['user']['id']
        ]);

        $businessName = $topbarStmt->fetchColumn();

        if (is_string($businessName) && $businessName !== '') {
            $topbarOrganizerName = $businessName;
        }
    } catch (Throwable $e) {
        error_log('Ticketa topbar profile error: ' . $e->getMessage());
    }
}

$topbarEscape = static function ($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$topbarInitial = function_exists('mb_substr')
    ? mb_substr($topbarOrganizerName, 0, 1, 'UTF-8')
    : substr($topbarOrganizerName, 0, 1);

$topbarSearchPlaceholder = $topbarSearchPlaceholder ?? 'Search events';
?>

<style>
    .organizer-topbar {
        position: fixed;
        top: 0;
        left: 250px;
        right: 0;
        z-index: 1200;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        width: auto;
        min-height: 90px;
        margin: 0;
        padding: 18px 32px;
        border-bottom: 1px solid #e9ecef;
        background: rgba(255, 255, 255, 0.97);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .organizer-topbar,
    .organizer-topbar * {
        box-sizing: border-box;
    }

    .organizer-topbar button,
    .organizer-topbar input {
        font: inherit;
    }

    .organizer-topbar .menu-toggle {
        display: none;
        width: 40px;
        height: 40px;
        flex-shrink: 0;
        place-items: center;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        background: #fff;
        color: #171c2e;
        font-size: 22px;
        cursor: pointer;
    }

    .organizer-topbar .search {
        display: flex;
        align-items: center;
        gap: 12px;
        width: min(345px, 45%);
        margin: 0;
        padding: 13px 15px;
        border-radius: 12px;
        background: #f4f6f6;
    }

    .organizer-topbar .search span {
        flex-shrink: 0;
        font-size: 22px;
    }

    .organizer-topbar .search input {
        width: 100%;
        min-width: 0;
        padding: 0;
        border: 0;
        outline: 0;
        background: transparent;
        color: #171c2e;
    }

    .organizer-topbar .search input::placeholder {
        color: #777;
    }

    .organizer-topbar .topbar-right {
        display: flex;
        align-items: center;
        gap: 22px;
    }

    .organizer-topbar .icon-button {
        position: relative;
        display: grid;
        width: 38px;
        height: 38px;
        flex-shrink: 0;
        place-items: center;
        border: 0;
        background: transparent;
        color: #171c2e;
        font-size: 23px;
        cursor: pointer;
    }

    .organizer-topbar .notification-dot {
        position: absolute;
        top: 1px;
        right: 0;
        display: grid;
        width: 17px;
        height: 17px;
        place-items: center;
        border-radius: 50%;
        background: #ffc83d;
        color: #fff;
        font-size: 10px;
    }

    /* Organizer profile dropdown */
    .organizer-topbar .profile-menu {
        position: relative;
    }

    .organizer-topbar .organizer-profile {
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        gap: 6px;
        width: auto;
        max-width: 240px;
        padding: 5px 8px;
        border: 0;
        border-radius: 10px;
        background: transparent;
        color: #171c2e;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }

    .organizer-topbar .profile-chevron {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-left: 0;
        line-height: 1;
        font-size: 14px;
    }

    .organizer-topbar .organizer-profile:hover {
        background: #f4f6f6;
    }

    .organizer-topbar .avatar {
        display: grid;
        width: 35px;
        height: 35px;
        flex-shrink: 0;
        place-items: center;
        overflow: hidden;
        border: 1px solid #e9ecef;
        border-radius: 50%;
        background: #e5faf4;
        color: #087f6b;
        font-weight: 800;
    }

    .organizer-topbar .organizer-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .organizer-topbar .profile-chevron {
        color: #777;
        font-size: 18px;
    }

    .organizer-topbar .profile-dropdown {
        position: absolute;
        top: calc(100% + 12px);
        right: 0;
        z-index: 1300;
        width: 230px;
        padding: 8px;
        border: 1px solid #e5e8e9;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.12);
    }

    .organizer-topbar .profile-dropdown[hidden] {
        display: none;
    }

    .organizer-topbar .profile-dropdown a {
        display: block;
        padding: 12px;
        border-radius: 7px;
        color: #171c2e;
        font-size: 14px;
        text-decoration: none;
    }

    .organizer-topbar .profile-dropdown a:hover {
        background: #f4f6f6;
    }

    .organizer-topbar .profile-dropdown-divider {
        height: 1px;
        margin: 6px 0;
        background: #e9ecef;
    }

    .organizer-topbar .profile-dropdown form {
        margin: 0;
    }

    .organizer-topbar .logout-button {
        display: block;
        width: 100%;
        padding: 12px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #c62828;
        font-size: 14px;
        text-align: left;
        cursor: pointer;
    }

    .organizer-topbar .logout-button:hover {
        background: #fff1f1;
    }

    .organizer-topbar .organizer-profile:focus-visible,
    .organizer-topbar .profile-dropdown a:focus-visible,
    .organizer-topbar .logout-button:focus-visible {
        outline: 2px solid #10bea0;
        outline-offset: 2px;
    }

    @media (max-width: 1250px) {
        .organizer-topbar {
            left: 220px;
            padding-right: 22px;
            padding-left: 22px;
        }
    }

    @media (max-width: 800px) {
        .organizer-topbar {
            left: 0;
            right: 0;
            min-height: 75px;
            gap: 12px;
            padding: 14px 18px;
        }

        .organizer-topbar .menu-toggle {
            display: inline-grid;
        }

        .organizer-topbar .search {
            width: auto;
            flex: 1;
            padding: 11px;
        }

        .organizer-topbar .topbar-right {
            gap: 10px;
        }

        .organizer-topbar .organizer-name {
            display: none;
        }

        .organizer-topbar .organizer-profile {
            gap: 5px;
            padding: 2px;
        }
    }

    @media (max-width: 560px) {
        .organizer-topbar {
            gap: 8px;
            padding-right: 12px;
            padding-left: 12px;
        }

        .organizer-topbar .icon-button {
            width: 30px;
        }

        .organizer-topbar .search {
            gap: 7px;
            padding: 10px 8px;
        }

        .organizer-topbar .profile-dropdown {
            right: 0;
            width: 215px;
        }
    }
</style>

<header class="organizer-topbar">

    <button
        type="button"
        class="menu-toggle"
        id="menuToggle"
        aria-label="Open navigation menu"
        aria-expanded="false"
        aria-controls="sidebar"
    >☰</button>

    <label class="search">
        <span aria-hidden="true">⌕</span>
        <input
            type="search"
            id="dashboardSearch"
            placeholder="<?= $topbarEscape($topbarSearchPlaceholder) ?>"
            aria-label="<?= $topbarEscape($topbarSearchPlaceholder) ?>"
        >
    </label>

    <div class="topbar-right">

        <button
            class="icon-button"
            type="button"
            aria-label="Notifications"
            title="Notifications"
        >
            ♟
            <span class="notification-dot">0</span>
        </button>

        <div class="profile-menu">

            <button
                type="button"
                class="organizer-profile"
                id="organizerProfileToggle"
                aria-expanded="false"
                aria-controls="organizerProfileDropdown"
            >
                <span class="avatar">
                    <?= $topbarEscape(strtoupper($topbarInitial ?: 'O')) ?>
                </span>

                <span class="organizer-name">
                    <?= $topbarEscape($topbarOrganizerName) ?>
                </span>

                <span class="profile-chevron" aria-hidden="true">⌄</span>
            </button>

            <div
                class="profile-dropdown"
                id="organizerProfileDropdown"
                hidden
            >
                <a href="dashboard.php">Dashboard</a>
                <a href="account-security.php">Account &amp; Security</a>

                <div class="profile-dropdown-divider"></div>

                <form method="post" action="logout.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= $topbarEscape(authCsrfToken()) ?>"
                    >
                    <button type="submit" class="logout-button">
                        Log out
                    </button>
                </form>
            </div>

        </div>
    </div>
</header>

<script>
(function () {
    // Organizer profile dropdown.
    const profileToggle = document.getElementById(
        'organizerProfileToggle'
    );

    const profileDropdown = document.getElementById(
        'organizerProfileDropdown'
    );

    if (profileToggle && profileDropdown) {
        function closeProfileDropdown() {
            profileDropdown.hidden = true;
            profileToggle.setAttribute('aria-expanded', 'false');
        }

        profileToggle.addEventListener('click', function () {
            const opening = profileDropdown.hidden;

            profileDropdown.hidden = !opening;
            profileToggle.setAttribute(
                'aria-expanded',
                String(opening)
            );
        });

        document.addEventListener('click', function (event) {
            if (
                !profileToggle.contains(event.target) &&
                !profileDropdown.contains(event.target)
            ) {
                closeProfileDropdown();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeProfileDropdown();
            }
        });
    }

    // Existing responsive sidebar behavior.
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (!sidebar || !menuToggle || !backdrop) {
        return;
    }

    if (menuToggle.dataset.sidebarHandlerAttached === 'true') {
        return;
    }

    menuToggle.dataset.sidebarHandlerAttached = 'true';

    function closeSidebar() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('visible');
        menuToggle.setAttribute('aria-expanded', 'false');
    }

    menuToggle.addEventListener('click', function () {
        const isOpen = sidebar.classList.toggle('open');

        backdrop.classList.toggle('visible', isOpen);
        menuToggle.setAttribute(
            'aria-expanded',
            String(isOpen)
        );
    });

    backdrop.addEventListener('click', closeSidebar);

    document.querySelectorAll('.nav-link').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });
})();
</script>
