<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

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

function bg_app_end(): void
{
    ?>
        </div>
    </div>
    <script src="js/dashboard-nav.js" defer></script>
    <?php
    bg_page_end();
}

