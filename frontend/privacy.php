<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_page_start('Privacy Notice Preview', 'privacy');
?>
<section class="page-intro">
    <p class="eyebrow">RA 10173 / document preview</p>
    <h1>PRIVACY MATTERS.</h1>
    <p>The formal Privacy Notice will be finalized alongside the approved system proposal, before registration is opened.</p>
</section>
<section class="section explainer" aria-label="Planned privacy notice information">
    <div class="notice notice--cyan">
        <strong>DESIGN DRAFT:</strong> This is not yet the final Privacy Notice.
        BillGuard currently collects no registration information.
    </div>
    <div class="explainer__grid">
        <article class="card card--dark">
            <h3>01 / Information to collect</h3>
            <p>Required registration data from the IT 107 handout and the approved household utility fields, including utility account identifiers and financial records.</p>
        </article>
        <article class="card card--dark">
            <h3>02 / Purpose of processing</h3>
            <p>To create protected accounts, manage each user's own utility bill records, and detect potentially malicious account activity.</p>
        </article>
        <article class="card card--dark">
            <h3>03 / Security measures</h3>
            <p>Planned controls include password hashing, OTP verification, role-based access, AES-256 encryption for the selected sensitive field and security monitoring.</p>
        </article>
        <article class="card card--dark">
            <h3>04 / Retention and contact</h3>
            <p>The final retention period, privacy contact, request process and any data sharing details must be filled in and approved before collecting real data.</p>
        </article>
    </div>
    <div class="form-row">
        <a class="button button--ghost" href="index.php">Back to Home</a>
        <a href="register.php">Registration preview</a>
    </div>
</section>
<?php bg_page_end(); ?>
