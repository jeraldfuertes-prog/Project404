<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_page_start('Log-in', 'login');
?>
<section class="page-intro">
    <p class="eyebrow">Identity verification</p>
    <h1>SECURE LOG-IN.</h1>
    <p>This page previews the future two-step sign-in experience. Authentication will be enabled during Phase 3.</p>
</section>
<section class="auth-layout" aria-label="Login preview">
    <aside class="auth-aside">
        <p class="eyebrow">Access protocol</p>
        <h2>PROTECT YOUR ACCOUNT.</h2>
        <ul>
            <li>Username and password check</li>
            <li>Server-verified CAPTCHA</li>
            <li>One-time email code</li>
            <li>Automatic login attempt monitoring</li>
        </ul>
    </aside>
    <div class="card card--dark auth-card">
        <p class="eyebrow">Account portal / design only</p>
        <h2>IDENTITY REQUIRED</h2>
        <p>Sample form only. Do not enter real credentials.</p>
        <form aria-label="Disabled login preview">
            <div class="form-field">
                <label class="form-label" for="login-username">Username<span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="login-username" type="text" placeholder="Enter your username" autocomplete="off" disabled>
            </div>
            <div class="form-field">
                <label class="form-label" for="login-password">Password<span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="login-password" type="password" placeholder="Enter your password" autocomplete="off" disabled>
                <p class="form-hint">Show Password, CAPTCHA and Forgot Password will be added with the authentication workflow.</p>
            </div>
            <div class="form-row">
                <button class="button" type="button" disabled>Log-in unavailable</button>
                <a href="register.php">Register preview</a>
            </div>
        </form>
        <p class="preview-note"><strong>NO LIVE AUTH:</strong> This page does not collect, submit, verify or store passwords.</p>
    </div>
</section>
<?php bg_page_end(); ?>
