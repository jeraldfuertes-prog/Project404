<?php
declare(strict_types=1);

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
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Phase 1 has no authentication; only public navigation is active.
    // The protected navigation is introduced with server-verified sessions in Phase 3.
    if ($page === 'login') {
        $navigation = ['home' => ['Home', 'index.php'], 'register' => ['Register', 'register.php']];
    } elseif ($page === 'register') {
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
        <span class="site-prospect__status">IT 107 • Interface preview</span>
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
