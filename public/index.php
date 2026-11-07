<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/php/layout.php';
bg_page_start('Home', 'home');
?>
<section class="hero" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">The next generation of utility tracking</p>
        <h1 id="hero-title" class="hero__title glitch">HOUSEHOLD BILLS. <span>UNDER YOUR CONTROL.</span></h1>
        <p class="hero__copy">
            BillGuard is a planned secure space to track electricity, water and internet bills,
            organize payment history and protect household financial information.
        </p>
        <div class="hero__actions">
            <a class="button" href="login.php">Explore log-in design <span aria-hidden="true">↗</span></a>
            <a class="button button--ghost" href="#features">View features <span aria-hidden="true">↓</span></a>
        </div>
        <div class="hero__foot">
            <span>PRIVATE ACCOUNTS</span>
            <span>SECURITY MONITORING</span>
            <span>ENCRYPTED DATA</span>
        </div>
    </div>

    <div class="hud" aria-label="Illustrative household expense dashboard preview">
        <div class="hud__top">
            <span>HOUSEHOLD / SAMPLE OVERVIEW</span>
            <span class="hud__dots" aria-hidden="true"><i></i><i></i><i></i></span>
        </div>
        <div class="hud__body">
            <p class="hud__label">Sample monthly utilities</p>
            <p class="hud__amount">₱4,875.00</p>
            <div class="hud__visual" aria-hidden="true">
                <div class="hud__bar" style="height: 43%"></div>
                <div class="hud__bar" style="height: 66%"></div>
                <div class="hud__bar" style="height: 52%"></div>
                <div class="hud__bar" style="height: 89%"></div>
                <div class="hud__bar" style="height: 72%"></div>
                <div class="hud__bar" style="height: 100%"></div>
                <div class="hud__bar" style="height: 61%"></div>
            </div>
            <div class="hud__row"><span>Electricity</span><strong>₱2,320.00</strong></div>
            <div class="hud__row"><span>Water</span><strong>₱655.00</strong></div>
            <div class="hud__row"><span>Internet</span><strong>₱1,900.00</strong></div>
            <div class="hud__row"><span>Account security</span><strong class="hud__paid">PLANNED</strong></div>
        </div>
        <div class="hud__bottom">DISPLAY MODE: <span>DEMO DATA / NOT A LIVE ACCOUNT</span></div>
    </div>
</section>

<section id="features" class="section" aria-labelledby="features-title">
    <div class="section__heading">
        <p class="eyebrow">Planned system modules</p>
        <h2 id="features-title">KEEP EVERY BILL IN CHECK.</h2>
        <p>Simple tools for managing household expenses, backed by the authentication and protection features required by IT 107.</p>
    </div>
    <div class="features">
        <article class="card feature">
            <span class="feature__index">MODULE_01</span>
            <span class="feature__icon" aria-hidden="true">▤</span>
            <h3>Utility Bills</h3>
            <p>Add electricity, water and internet bills, amounts, due dates and payment status.</p>
        </article>
        <article class="card feature">
            <span class="feature__index">MODULE_02</span>
            <span class="feature__icon" aria-hidden="true">₱</span>
            <h3>Payment History</h3>
            <p>Record payments and review monthly household utility spending.</p>
        </article>
        <article class="card feature">
            <span class="feature__index">MODULE_03</span>
            <span class="feature__icon" aria-hidden="true">⌁</span>
            <h3>Account Security</h3>
            <p>Planned OTP verification, CAPTCHA, secure sessions, activity logs and admin monitoring.</p>
        </article>
    </div>
</section>
<section class="section" aria-label="Current development status">
    <div class="notice">
        <strong>PHASE 1 / DESIGN PREVIEW:</strong> This interface uses illustrative data only.
        Registration, account access and bill storage are not yet enabled.
        System approval and security implementation come next.
    </div>
</section>
<?php bg_page_end(); ?>
