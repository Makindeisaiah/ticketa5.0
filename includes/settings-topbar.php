
<?php
/*
 * Ticketa Settings Topbar
 * Expected variables:
 * $settingsPageTitle
 * $settingsBackUrl
 */

$settingsPageTitle = $settingsPageTitle ?? 'Organization Info';
$settingsBackUrl = $settingsBackUrl ?? 'settings.php';

if (!isset($pdo)) {
    require_once dirname(__DIR__) . '/config/database.php';
}

$settingsUser = $_SESSION['user'] ?? [];
$settingsUserId = (int) ($settingsUser['id'] ?? 0);
$settingsDisplayName = $settingsUser['full_name']
    ?? $settingsUser['name']
    ?? 'Organizer';

$settingsBusinessName = $settingsDisplayName;
$settingsLogoUrl = null;

if ($settingsUserId > 0 && isset($pdo)) {
    try {
        $topbarStmt = $pdo->prepare(
            'SELECT business_name, logo_url
             FROM organizers
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $topbarStmt->execute(['user_id' => $settingsUserId]);
        $topbarOrganization = $topbarStmt->fetch(PDO::FETCH_ASSOC);

        if ($topbarOrganization) {
            $settingsBusinessName =
                $topbarOrganization['business_name'] ?: $settingsDisplayName;
            $settingsLogoUrl = $topbarOrganization['logo_url'] ?: null;
        }
    } catch (Throwable $e) {
        // Keep the topbar usable if the organization profile is unavailable.
    }
}
?>

<style>
.settings-topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    width: 100%;
    min-height: 90px;
    box-sizing: border-box;
    display: grid;
    grid-template-columns: 260px minmax(250px, 1fr) auto;
    align-items: center;
    gap: 28px;
    padding: 16px 30px;
    background: #fff;
    border-bottom: 1px solid #e9e9e9;
    z-index: 1000;
    font-family: Arial, Helvetica, sans-serif;
}

.settings-brand {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    color: #111;
    text-decoration: none;
    width: fit-content;
}

.settings-brand-mark {
    width: 39px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 3px solid #20b99a;
    border-radius: 5px;
    color: #20b99a;
    font-size: 23px;
    font-weight: 800;
    line-height: 1;
    position: relative;
    box-sizing: border-box;
}

.settings-brand-mark::before,
.settings-brand-mark::after {
    content: "";
    position: absolute;
    left: -3px;
    width: 7px;
    height: 7px;
    background: #fff;
    border-top: 2px solid #20b99a;
    border-bottom: 2px solid #20b99a;
}

.settings-brand-mark::before { top: 5px; }
.settings-brand-mark::after { bottom: 5px; }

.settings-brand-name {
    font-size: 26px;
    font-weight: 700;
    letter-spacing: -1px;
}

.settings-breadcrumb {
    min-height: 52px;
    max-width: 470px;
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 0 15px;
    border-radius: 12px;
    background: #f5f6f6;
    color: #747474;
    font-size: 15px;
    font-weight: 600;
    box-sizing: border-box;
}

.settings-back-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #222;
    text-decoration: none;
    font-size: 28px;
    line-height: 1;
    flex-shrink: 0;
}

.settings-back-link:hover { color: #20b99a; }

.settings-topbar-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 24px;
    min-width: 0;
}

.settings-notification {
    position: relative;
    width: 35px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #242424;
    text-decoration: none;
}

.settings-notification svg {
    width: 27px;
    height: 27px;
}

.settings-notification-count {
    position: absolute;
    top: -1px;
    right: -5px;
    width: 17px;
    height: 17px;
    border-radius: 50%;
    background: #f4c644;
    color: #222;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
}

.settings-user {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    color: #222;
    font-size: 15px;
    font-weight: 600;
}

.settings-user-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #f0f0f0;
    border: 1px solid #e3e3e3;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}

.settings-user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.settings-user-name {
    max-width: 170px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (max-width: 900px) {
    .settings-topbar {
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 16px;
        padding: 15px 20px;
    }

    .settings-brand-name { font-size: 21px; }
    .settings-brand-mark { width: 34px; height: 29px; }
    .settings-breadcrumb { font-size: 13px; }
    .settings-topbar-actions { gap: 14px; }
}

@media (max-width: 600px) {
    .settings-topbar {
        min-height: 75px;
        grid-template-columns: 1fr auto;
        gap: 12px;
        padding: 12px 15px;
    }

    .settings-brand-name { font-size: 19px; }
    .settings-brand { gap: 8px; }

    .settings-breadcrumb {
        grid-column: 1 / -1;
        grid-row: 2;
        max-width: none;
        min-height: 43px;
        padding: 0 10px;
    }

    .settings-topbar-actions {
        grid-column: 2;
        grid-row: 1;
        gap: 12px;
    }

    .settings-user-name { max-width: 95px; }
}
</style>

<header class="settings-topbar">
    <a class="settings-brand"
       href="<?= htmlspecialchars($settingsBackUrl, ENT_QUOTES, 'UTF-8') ?>"
       aria-label="Ticketa Settings">
        <span class="settings-brand-mark" aria-hidden="true">T</span>
        <span class="settings-brand-name">TICKETA</span>
    </a>

    <div class="settings-breadcrumb">
        <a class="settings-back-link"
           href="<?= htmlspecialchars($settingsBackUrl, ENT_QUOTES, 'UTF-8') ?>"
           aria-label="Back to Settings"
           title="Back to Settings">&#8592;</a>
        <span>Settings&nbsp; / &nbsp;<?= htmlspecialchars($settingsPageTitle, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="settings-topbar-actions">
        <a class="settings-notification"
           href="notifications.php"
           aria-label="Notifications"
           title="Notifications">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 22a2.4 2.4 0 0 0 2.35-2h-4.7A2.4 2.4 0 0 0 12 22Zm7-5-1.6-1.9V10a5.4 5.4 0 0 0-4.4-5.3V4a1 1 0 0 0-2 0v.7A5.4 5.4 0 0 0 6.6 10v5.1L5 17v1h14v-1Z"/>
            </svg>
            <span class="settings-notification-count">!</span>
        </a>

        <div class="settings-user">
            <div class="settings-user-avatar">
                <?php if ($settingsLogoUrl): ?>
                    <img src="<?= htmlspecialchars($settingsLogoUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Organization logo">
                <?php else: ?>
                    <span aria-hidden="true">👤</span>
                <?php endif; ?>
            </div>
            <span class="settings-user-name">
                <?= htmlspecialchars($settingsBusinessName, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
    </div>
</header>
