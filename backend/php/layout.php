<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

/**
 * BillGuard shared public layout.
 * Phase 1: visual shell only. Authentication and routes are implemented later.
 */

function bg_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bg_page_start(string $title, string $page = 'home'): void
{
    bg_secure_start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // After login OTP, show Home and Logout. For guests, use handout navigation.
    $loggedIn = isset($_SESSION['user_id']) && is_string($_SESSION['user_id']);
    if ($loggedIn) {
        $navigation = ['home' => ['Home', 'index.php']];
    } elseif ($page === 'login' || $page === 'login-otp') {
        $navigation = ['home' => ['Home', 'index.php'], 'register' => ['Register', 'register.php']];
    } elseif ($page === 'register' || $page === 'verify-otp') {
        $navigation = ['home' => ['Home', 'index.php'], 'login' => ['Log-in', 'login.php']];
    } else {
        $navigation = [
            'home' => ['Home', 'index.php'],
            'register' => ['Register', 'register.php'],
            'login' => ['Log-in', 'login.php'],
        ];
    }

    $safeTitle = bg_escape($title);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0D0D0D">
    <meta name="description" content="BillGuard - Secure Household Utility Bill Monitoring System.">
    <title><?= $safeTitle ?> | BillGuard</title>
    <link rel="stylesheet" href="css/cyberpunk.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="site-wrap">
    <div class="site-prospect" aria-label="System name">
        <span>BillGuard // Secure Household Utility Bill Monitoring System</span>
        <span class="site-prospect__status">IT 107 • Secure household utilities</span>
    </div>
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="BillGuard home">
            <span class="brand__symbol" aria-hidden="true">B</span>
            <span>
                <span class="brand__title glitch">BILLGUARD</span>
                <span class="brand__subtitle">Financial data // access protected</span>
            </span>
        </a>
        <nav class="site-nav" aria-label="Primary navigation">
            <?php foreach ($navigation as $key => [$label, $href]): ?>
                <a href="<?= bg_escape($href) ?>"
                   class="<?= $key === 'register' ? 'site-nav__highlight' : '' ?>"
                   <?= $page === $key ? 'aria-current="page"' : '' ?>><?= bg_escape($label) ?></a>
            <?php endforeach; ?>
            <?php if ($loggedIn): ?>
                <form action="logout.php" method="post" class="nav-logout">
                    <?= bg_csrf_field() ?>
                    <button type="submit" class="site-nav__logout">Logout</button>
                </form>
            <?php endif; ?>
        </nav>
    </header>
    <main id="main-content" class="main-content">
    <?php
}

function bg_page_end(): void
{
    ?>
    </main>
    <footer class="site-footer">
        <div class="site-footer__inner">
            <p>&copy; 2026 BillGuard. All rights reserved.</p>
            <span class="site-footer__meta">SECURE BY DESIGN // IT 107</span>
        </div>
    </footer>
</div>
</body>
</html>
    <?php
}
