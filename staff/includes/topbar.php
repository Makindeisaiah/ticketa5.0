<?php
/** Shared Ticketa staff event topbar. Expects event/assignment variables from the page. */
?>
<header class="topbar">
    <div class="event-heading">
        <div class="event-poster">
            <?php if ($eventImage !== ''): ?>
                <img src="<?= staffEscape($eventImage) ?>" alt="<?= staffEscape($eventTitle) ?>"
                     onerror="this.onerror=null;this.style.display='none';this.parentElement.textContent='T';">
            <?php else: ?>T<?php endif; ?>
        </div>
        <div class="event-copy">
            <h1><?= staffEscape($eventTitle) ?></h1>
            <div class="event-meta">
                <span><?= staffEscape($selectedAssignment ? staffDate($selectedAssignment['event_date'], 'D, d M Y · H:i') : 'No event assignment yet') ?></span>
                <?php if ($eventVenue !== ''): ?><span class="separator">•</span><span><?= staffEscape($eventVenue) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($selectedAssignment): ?>
        <div class="assignment-meta">
            <div class="assignment-meta-item"><span class="meta-icon purple">▣</span><div><small>Assigned Gate</small><strong><?= staffEscape($gateName) ?></strong></div></div>
            <div class="assignment-meta-item"><span class="meta-icon blue">◷</span><div><small>Session Time</small><strong><?= staffEscape(staffDate($shiftStart, 'g:i A')) ?> – <?= staffEscape(staffDate($shiftEnd, 'g:i A')) ?></strong></div></div>
        </div>
    <?php endif; ?>
</header>
