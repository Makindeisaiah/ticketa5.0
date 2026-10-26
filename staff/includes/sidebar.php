<?php
/** Shared Ticketa staff sidebar. Included by staff pages after staff/session data is loaded. */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$navActive = static function (string $page) use ($currentPage): string {
    return $currentPage === $page ? ' active' : '';
};
?>
<aside class="sidebar">
    <a class="brand" href="dashboard.php" aria-label="TICKETA dashboard">
        <span class="brand-mark">T</span>
        <span>TICKETA</span>
    </a>
    <nav class="nav" aria-label="Staff navigation">
        <a class="nav-link<?= $navActive('dashboard.php') ?>" href="dashboard.php">
            <span class="nav-icon">▦</span><span class="nav-text">Dashboard</span>
        </a>
        <a class="nav-link<?= $navActive('checkin.php') ?>" href="checkin.php<?= $selectedAssignment ? '?assignment_id=' . (int) $selectedAssignment['assignment_id'] : '' ?>">
            <span class="nav-icon">▥</span><span class="nav-text">Scan Ticket</span>
        </a>
        <a class="nav-link<?= $navActive('manual-checkin.php') ?>" href="manual-checkin.php<?= $selectedAssignment ? '?assignment_id=' . (int) $selectedAssignment['assignment_id'] : '' ?>">
            <span class="nav-icon">♙</span><span class="nav-text">Manual Check-In</span>
        </a>
        <a class="nav-link<?= $navActive('search-attendee.php') ?>" href="search-attendee.php<?= $selectedAssignment ? '?assignment_id=' . (int) $selectedAssignment['assignment_id'] : '' ?>">
            <span class="nav-icon">⌕</span><span class="nav-text">Search Attendee</span>
        </a>
        <a class="nav-link<?= $navActive('todays-checkins.php') ?>" href="todays-checkins.php<?= $selectedAssignment ? '?assignment_id=' . (int) $selectedAssignment['assignment_id'] : '' ?>">
            <span class="nav-icon">▣</span><span class="nav-text">Today's Check-Ins</span>
        </a>
        <a class="nav-link<?= $navActive('vip-list.php') ?>" href="vip-list.php<?= $selectedAssignment ? '?assignment_id=' . (int) $selectedAssignment['assignment_id'] : '' ?>">
            <span class="nav-icon">☷</span><span class="nav-text">VIP List</span>
        </a>
        <a class="nav-link<?= $navActive('invalid-tickets.php') ?>" href="invalid-tickets.php<?= $selectedAssignment ? '?assignment_id=' . (int) $selectedAssignment['assignment_id'] : '' ?>">
            <span class="nav-icon">⊘</span><span class="nav-text">Invalid Tickets</span>
            <?php if (($stats['invalid'] ?? 0) > 0): ?><span class="nav-count"><?= number_format((int) $stats['invalid']) ?></span><?php endif; ?>
        </a>
        <a class="nav-link<?= $navActive('settings.php') ?>" href="settings.php">
            <span class="nav-icon">⚙</span><span class="nav-text">Settings</span>
        </a>
    </nav>
    <div class="profile-card">
        <div class="profile-details">
            <span class="avatar"><?= staffEscape(staffInitials($staffName)) ?></span>
            <div class="profile-copy"><strong><?= staffEscape($staffName) ?></strong><small><?= staffEscape(staffRoleLabel($staffRole)) ?></small></div>
        </div>
        <div class="online-status"><span class="online-dot"></span><span>Online</span></div>
        <form class="logout-form" method="post">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= staffEscape($csrfToken) ?>">
            <button class="logout-button" type="submit"><span aria-hidden="true">⇥</span><span>Sign Out</span></button>
        </form>
    </div>
</aside>
