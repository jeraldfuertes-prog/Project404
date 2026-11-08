<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/security.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();
$confirmed = ($_SESSION['registration_verified_notice'] ?? null) === true;
unset($_SESSION['registration_verified_notice']);
bg_page_start('Account Verified', 'verified');
?>
<section class="page-intro">
    <p class="eyebrow">Account verification / outcome</p>
    <?php if ($confirmed): ?>
        <h1>ACCOUNT VERIFIED.</h1>
        <p>Your BillGuard registration email was confirmed. Your account is now active. Sign in using your username, password, and email security code to open your dashboard.</p>
        <div class="notice notice--cyan" role="status"><strong>SUCCESS:</strong> Your registration OTP was accepted and cannot be reused. Verification does not log you in.</div>
    <?php else: ?>
        <h1>VERIFICATION REQUIRED.</h1>
        <p>This success page does not grant access or change account status. Complete registration and email OTP verification first.</p>
    <?php endif; ?>
    <div class="hero__actions">
        <a class="button" href="index.php">Return home</a>
        <a class="button button--ghost" href="login.php">Continue to Login</a>
    </div>
</section>
<?php bg_page_end(); ?>
