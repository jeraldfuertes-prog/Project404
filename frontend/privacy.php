<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/security.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

try {
    $cfg = billguard_config();
    $contact = (string) ($cfg['privacy_contact'] ?? '');
    $retention = (string) ($cfg['privacy_retention'] ?? '');
} catch (Throwable $e) {
    $contact = '';
    $retention = '';
}
$complete = filter_var($contact, FILTER_VALIDATE_EMAIL)
    && $retention !== ''
    && !str_contains($retention, 'REPLACE_WITH_');

bg_page_start('Privacy Notice', 'privacy');
?>
<section class="page-intro">
    <p class="eyebrow">RA 10173 / Data Privacy Act of 2012</p>
    <h1>PRIVACY NOTICE.</h1>
    <p>BillGuard's household finance data is private. Read this notice before registering and giving consent.</p>
</section>
<section class="section explainer" aria-label="BillGuard Privacy Notice">
    <?php if (!$complete): ?>
        <div class="notice" role="alert">
            <strong>NOT YET COMPLETE:</strong> The project owner must set a valid privacy contact email and an accurate retention policy
            in the private backend configuration. Registration remains disabled until this is done.
        </div>
    <?php endif; ?>
    <div class="explainer__grid">
        <article class="card card--dark">
            <h3>01 / Information collected</h3>
            <p>BillGuard collects your ID Number, names, date of birth, six address fields, email, username,
                approved utility account number, primary utility type, service start date, household utility budget,
                utility bills, payment records, and account security event records. We also record the date and time of consent.</p>
            <p>Passwords, OTPs and recovery answers are used for account protection and stored only as hashes.</p>
        </article>
        <article class="card card--dark">
            <h3>02 / Purpose of processing</h3>
            <p>The information is used to create and verify your protected account,
                monitor your household's electricity, water and internet billing and payment history,
                compare it with your budget, recover your account, and detect suspicious or unauthorized access.</p>
        </article>
        <article class="card card--dark">
            <h3>03 / Information protection</h3>
            <p>BillGuard uses server-side validation, password and recovery-answer hashing, email verification,
                CAPTCHA, access controls, security monitoring and AES-256-GCM encryption for the utility account number.
                Regular users can access only their own financial records when authenticated.
                The security administrator's monitoring dashboard is limited to audit data.</p>
        </article>
        <article class="card card--dark">
            <h3>04 / Retention of your information</h3>
            <p><?= $complete ? bg_safe($retention) : 'Retention period and deletion arrangements will be finalized before registration opens.' ?></p>
        </article>
        <article class="card card--dark">
            <h3>05 / Contact and privacy requests</h3>
            <p>For questions about your information or requests to access, correct or remove information,
                contact the BillGuard project administrator through
                <?php if ($complete): ?><a href="mailto:<?= bg_safe($contact) ?>"><?= bg_safe($contact) ?></a><?php else: ?>
                    the privacy contact address to be configured before launch.<?php endif; ?></p>
        </article>
        <article class="card card--dark">
            <h3>06 / Consent and limited sharing</h3>
            <p>You must agree to this Privacy Notice before registration. BillGuard stores your consent timestamp.
                Account information is not sold. Email verification messages use the configured SMTP email service.
                Any additional data sharing would require a revised notice and appropriate authorization.</p>
        </article>
    </div>
    <div class="form-row">
        <a class="button button--ghost" href="register.php">Back to Registration</a>
        <a href="index.php">Return home</a>
    </div>
</section>
<?php bg_page_end(); ?>
