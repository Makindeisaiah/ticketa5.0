<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$navItems = [
    ['dashboard.php', '▦', 'Dashboard'],
    ['events.php', '▣', 'Events'],
    ['analytics.php', '♧', 'Analytics'],
    ['ticket-sales.php', '▤', 'Ticket Sales'],
    ['check-ins.php', '▣', 'Check-Ins'],
    ['settings.php', '⚙', 'Settings'],
];
?>

<style>
.ticketa-sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 1200;
    width: 250px;
    padding: 24px 22px;
    overflow-y: auto;
    border-right: 1px solid #e9ecef;
    background: #fff;
    transition: transform .25s ease;
}

.ticketa-sidebar .brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 58px;
    color: #171c2e;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -1px;
    text-decoration: none;
}

.ticketa-sidebar .brand-icon {
    display: grid;
    width: 39px;
    height: 31px;
    place-items: center;
    border: 3px solid #10c6a4;
    border-radius: 7px;
    color: #08ad91;
    font-size: 19px;
}

.ticketa-sidebar .nav {
    display: grid;
    gap: 14px;
}

.ticketa-sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 46px;
    padding: 12px 15px;
    border-radius: 4px;
    color: #777b83;
    font-size: 15px;
    text-decoration: none;
    transition: background .2s, color .2s;
}

.ticketa-sidebar .nav-link:hover {
    background: #edfaf7;
    color: #087f6b;
}

.ticketa-sidebar .nav-link.active {
    background: #10c6a4;
    color: #fff;
    box-shadow: 0 3px 0 rgba(8, 173, 145, .18);
}

.ticketa-sidebar .nav-icon {
    display: inline-grid;
    width: 22px;
    flex-shrink: 0;
    place-items: center;
    font-size: 20px;
}

.ticketa-sidebar-backdrop {
    display: none;
}

@media (max-width: 1350px) {
    .ticketa-sidebar {
        width: 220px;
        padding-right: 16px;
        padding-left: 16px;
    }
}

@media (max-width: 800px) {
    .ticketa-sidebar {
        z-index: 1200;
        width: 250px;
        transform: translateX(-100%);
    }

    .ticketa-sidebar.open {
        transform: translateX(0);
    }

    .ticketa-sidebar-backdrop.visible {
        position: fixed;
        inset: 0;
        z-index: 1150;
        display: block;
        background: rgba(20, 30, 35, .4);
    }
}
</style>

<aside class="sidebar ticketa-sidebar" id="sidebar">
    <a class="brand" href="dashboard.php">
        <span class="brand-icon">T</span>
        <span>TICKETA</span>
    </a>

    <nav class="nav" aria-label="Organizer navigation">
        <?php foreach ($navItems as [$file, $icon, $label]): ?>
            <?php $active = $currentPage === $file; ?>
            <a
                class="nav-link<?= $active ? ' active' : '' ?>"
                href="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>"
                <?= $active ? 'aria-current="page"' : '' ?>
            >
                <span class="nav-icon" aria-hidden="true">
                    <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<div class="sidebar-backdrop ticketa-sidebar-backdrop"
     id="sidebarBackdrop"></div>

<script>
(() => {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('menuToggle');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (!sidebar || !backdrop) return;

    function closeSidebar() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('visible');

        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    if (toggle) {
        toggle.addEventListener('click', () => {
            const open = sidebar.classList.toggle('open');
            backdrop.classList.toggle('visible', open);
            toggle.setAttribute('aria-expanded', String(open));
        });
    }

    backdrop.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', closeSidebar);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeSidebar();
    });
})();
</script>
```