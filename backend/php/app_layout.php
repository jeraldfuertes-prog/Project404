<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/auth.php';

/**
 * A shared, keyboard-accessible sliding sidebar for all authenticated pages.
 * Uses the existing secure session, CSRF logout and Cyberpunk visual identity.
 */
function bg_app_start(string $title, string $active, array $user): void
{
    bg_page_start($title, $active);
    $menu = [
        ['dashboard', 'Dashboard', 'dashboard.php', '▦'],
        ['bills', 'Utility Bills', 'bills.php', '▤'],
        ['payments', 'Payments', 'payments.php', '₱'],
        ['reminders', 'Reminders', 'reminders.php', '◷'],
        ['profile', 'My Profile', 'profile.php', '◉'],
    ];
    // Display admin-only navigation only for a server-authenticated admin.
    // The page and JSON feed independently recheck permissions on every visit.
    if (($user['role'] ?? null) === 'admin') {
        $menu[] = ['security', 'Security Monitor', 'admin-security.php', '◆'];
    }
    ?>
    <div class="app-shell" id="app-shell">
        <button type="button" class="app-scrim" data-close-sidebar
            aria-label="Close sidebar navigation" tabindex="-1"></button>
        <aside class="app-sidebar" id="app-sidebar" aria-label="Account navigation">
            <div class="app-sidebar__top">
                <a href="dashboard.php" class="app-sidebar__logo" aria-label="BillGuard Dashboard">
                    <span aria-hidden="true" class="app-sidebar__mark">B</span>
                    <span class="app-sidebar__logo-text">BILLGUARD<small>UTILITY CONTROL</small></span>
                </a>
                <button type="button" class="app-toggle" data-sidebar-toggle
                    aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse sidebar"
                    title="Collapse or expand sidebar">☰</button>
            </div>
            <p class="app-sidebar__section">NAVIGATION</p>
            <nav class="app-sidebar__nav" aria-label="Dashboard navigation">
                <?php foreach ($menu as [$key, $label, $href, $symbol]): ?>
                    <a class="app-sidebar__link <?= $active === $key ? 'is-active' : '' ?>"
                        href="<?= bg_safe($href) ?>"
                        <?= $active === $key ? 'aria-current="page"' : '' ?>
                        title="<?= bg_safe($label) ?>">
                        <span class="app-sidebar__icon" aria-hidden="true"><?= bg_safe($symbol) ?></span>
                        <span class="app-sidebar__label"><?= bg_safe($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="app-sidebar__footer">
                <span class="app-sidebar__secure"><span aria-hidden="true">●</span> <span class="app-sidebar__label">VERIFIED SESSION</span></span>
                <form action="logout.php" method="post" class="app-sidebar__logout">
                    <?= bg_csrf_field() ?>
                    <button type="submit" class="app-sidebar__link app-sidebar__link--logout" title="Logout">
                        <span class="app-sidebar__icon" aria-hidden="true">⇥</span>
                        <span class="app-sidebar__label">Logout</span>
                    </button>
                </form>
            </div>
        </aside>
        <div class="app-content">
            <div class="app-toolbar">
                <button type="button" class="app-mobile-menu" data-sidebar-toggle
                    aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation menu">
                    <span aria-hidden="true">☰</span> Menu
                </button>
                <span class="app-toolbar__title"><?= bg_safe($title) ?></span>
                <span class="app-toolbar__profile">Hello, <?= bg_safe((string) $user['first_name']) ?></span>
            </div>
    <?php
}

/** Match server authentication's seeded idle timeout for UI metadata. */
function bg_app_idle_seconds(): int
{
    $config = billguard_config();
    $seed = bg_seed($config);
    return $seed === null ? 0 : bg_idle_timeout_seconds($seed);
}

/** Share the server's short-session-safe warning threshold with JavaScript. */
function bg_app_warning_lead_seconds(): int
{
    $seed = bg_seed(billguard_config());
    return $seed === null ? 0 : bg_session_warning_lead_seconds($seed);
}

function bg_app_end(): void
{
    ?>
        </div>
    </div>
    <div
        id="bg-session-timer"
        data-server-time="<?= (int) time() ?>"
        data-expires-at="<?= (int) ($_SESSION['last_activity'] ?? 0) + bg_app_idle_seconds() ?>"
        data-idle-seconds="<?= (int) bg_app_idle_seconds() ?>"
        data-warning-seconds="<?= (int) bg_app_warning_lead_seconds() ?>"
        hidden
    ></div>
    <section class="session-warning-overlay" id="bg-session-warning" role="dialog"
             aria-modal="true" aria-labelledby="bg-session-warning-title"
             aria-describedby="bg-session-warning-description" hidden>
        <div class="session-warning-card" tabindex="-1">
            <p class="eyebrow">BillGuard // Session Safety</p>
            <h2 id="bg-session-warning-title">SESSION EXPIRING SOON</h2>
            <p id="bg-session-warning-description">
                You have been inactive. Your account session expires in
                <strong id="bg-session-remaining">01:00</strong>.
            </p>
            <p class="session-warning-card__hint">
                For your security, you will be signed out automatically unless
                you choose to stay signed in.
            </p>
            <p class="session-warning-card__error" id="bg-session-error"
               role="alert" hidden></p>
            <div class="session-warning-card__actions">
                <form action="logout.php" method="post">
                    <?= bg_csrf_field() ?>
                    <button class="button button--ghost" type="submit">Log Out</button>
                </form>
                <form action="session-renew.php" method="post" id="bg-session-renew-form">
                    <?= bg_csrf_field() ?>
                    <button class="button" id="bg-stay-signed-in" type="submit">
                        Stay Signed In
                    </button>
                </form>
            </div>
        </div>
    </section>
    <script src="js/dashboard-nav.js" defer></script>
    <script src="js/session-warning.js" defer></script>
    <?php
    bg_page_end();
}

