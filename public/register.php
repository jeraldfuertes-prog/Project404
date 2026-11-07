<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/layout.php';
bg_page_start('Register', 'register');
?>
<section class="page-intro">
    <p class="eyebrow">New account / planned workflow</p>
    <h1>CREATE AN ACCOUNT.</h1>
    <p>Registration form development begins after the Appendix A System Proposal and personal seed table receive instructor approval.</p>
</section>
<section class="auth-layout" aria-label="Registration preview">
    <aside class="auth-aside">
        <p class="eyebrow">Required protection layers</p>
        <h2>SECURITY FROM DAY ONE.</h2>
        <ul>
            <li>Validated personal information</li>
            <li>Privacy Notice and recorded consent</li>
            <li>Three personal seed-based questions</li>
            <li>CAPTCHA and email OTP verification</li>
        </ul>
    </aside>
    <div class="card card--dark auth-card">
        <p class="eyebrow">Registration module / awaiting approval</p>
        <h2>PRE-APPROVAL PREVIEW</h2>
        <p>These four proposed system-specific fields will be included alongside the handout's required registration fields once the instructor approves them.</p>
        <div class="explainer__grid">
            <div class="card">
                <h3>Utility Account Number</h3>
                <p>Formatted identifier. Planned AES-256 encryption at rest.</p>
            </div>
            <div class="card">
                <h3>Primary Utility Type</h3>
                <p>Electricity, water or internet; checked again on the server.</p>
            </div>
            <div class="card">
                <h3>Service Start Date</h3>
                <p>Date input with server-side validation.</p>
            </div>
            <div class="card">
                <h3>Monthly Utility Budget</h3>
                <p>Numeric amount in Philippine pesos.</p>
            </div>
        </div>
        <p class="preview-note"><strong>REGISTRATION DISABLED:</strong> No personal data is being collected at this stage.</p>
        <div class="form-row">
            <a class="button button--ghost" href="login.php">View log-in design</a>
            <a href="privacy.php">Privacy Notice preview</a>
        </div>
    </div>
</section>
<?php bg_page_end(); ?>
