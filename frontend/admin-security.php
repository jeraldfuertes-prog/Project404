<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/admin_monitor_data.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';
bg_secure_start();

try {
    $seed = bg_seed(billguard_config());
    if ($seed === null) throw new RuntimeException('Seed configuration missing.');
    $db = billguard_db();
    $admin = bg_admin_audit_authorize($db, $seed, 'page');
    if ($admin === null) {
        http_response_code(403);
        exit('Access denied.');
    }
    $filters = bg_monitor_filters($_GET);
    $data = bg_monitor_dashboard_data($db, $filters);
    bg_admin_audit_viewed($db, $admin, 'page');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit('Invalid dashboard filters.');
} catch (Throwable $e) {
    error_log('BillGuard: protected security monitor view unavailable.');
    http_response_code(503);
    exit('Security monitoring is temporarily unavailable.');
}

$maximum = max(1, ...array_column($data['hourly'], 'failures'));
bg_app_start('Security Monitor', 'security', $admin);
?>
<header class="app-page__header">
    <div>
        <p class="eyebrow">BillGuard // Security Operations // Admin Only</p>
        <h1>SECURITY MONITOR.</h1>
        <p>Read-only monitoring of authentication threats, unusual activity and security flags.</p>
    </div>
    <div class="bg-monitor__refresh">
        <span aria-hidden="true">●</span> Auto refresh every 60 seconds
        <small id="bg-monitor-updated"><?= bg_safe($data['updated_at']) ?></small>
    </div>
</header>
<div id="bg-monitor-status" class="bg-monitor__status" role="status" aria-live="polite">
    Overview covers the last 24 hours in Philippine time (PHT). Event filters apply to the activity table only.
</div>
<section aria-label="Security overview, last 24 hours">
    <div class="app-stat-grid bg-monitor__stats">
        <article class="app-stat">
            <span class="app-stat__label">Failed logins</span>
            <strong class="app-stat__value" id="bg-monitor-failed"><?= (int) $data['summary']['failed_logins'] ?></strong>
            <small class="app-stat__detail">Across all sources / 24h</small>
        </article>
        <article class="app-stat">
            <span class="app-stat__label">Successful logins</span>
            <strong class="app-stat__value" id="bg-monitor-success"><?= (int) $data['summary']['successful_logins'] ?></strong>
            <small class="app-stat__detail">Verified by login OTP / 24h</small>
        </article>
        <article class="app-stat">
            <span class="app-stat__label">OTP failures</span>
            <strong class="app-stat__value" id="bg-monitor-otp"><?= (int) $data['summary']['otp_failures'] ?></strong>
            <small class="app-stat__detail">All OTP purposes / 24h</small>
        </article>
        <article class="app-stat">
            <span class="app-stat__label">Security flags</span>
            <strong class="app-stat__value" id="bg-monitor-flags"><?= (int) $data['summary']['flags'] ?></strong>
            <small class="app-stat__detail">New flags in the last 24h</small>
        </article>
    </div>
</section>
<div class="bg-monitor__columns">
    <section class="app-panel" aria-labelledby="monitor-chart-title">
        <div class="app-panel__top">
            <div>
                <h2 id="monitor-chart-title">FAILED LOGINS // HOURLY</h2>
                <p>Most recent 24 hourly buckets, PHT. Zero-count hours included.</p>
            </div>
        </div>
        <div class="bg-monitor__chart-scroll">
            <div class="bg-monitor__chart" id="bg-monitor-chart"
                 role="img" aria-label="Bar chart of hourly failed login counts">
                <?php foreach ($data['hourly'] as $point): ?>
                    <?php $count = (int) $point['failures']; ?>
                    <div class="bg-monitor__bar-group">
                        <span class="bg-monitor__bar-value"><?= $count ?></span>
                        <span class="bg-monitor__bar"
                              style="height:<?= $count > 0 ? max(4, round(100 * $count / $maximum)) : 2 ?>%"
                              aria-hidden="true"></span>
                        <span class="bg-monitor__bar-label"><?= bg_safe($point['hour']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="app-panel" aria-labelledby="monitor-ip-title">
        <div class="app-panel__top">
            <div>
                <h2 id="monitor-ip-title">TOP FIVE SOURCE IPs</h2>
                <p>Highest failed-login counts over the past 24 hours.</p>
            </div>
        </div>
        <div class="dashboard-table-wrap">
            <table class="dashboard-table">
                <thead><tr><th>Source IP</th><th>Failures</th></tr></thead>
                <tbody id="bg-monitor-ips">
                    <?php if (!$data['top_ips']): ?>
                        <tr><td colspan="2">No failed logins in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['top_ips'] as $row): ?>
                            <tr><td><?= bg_safe($row['ip_address']) ?></td>
                                <td><?= (int) $row['failures'] ?></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<section class="app-panel app-panel--table" aria-labelledby="monitor-flags-title">
    <div class="app-panel__top">
        <div>
            <h2 id="monitor-flags-title">RECENT SECURITY FLAGS</h2>
            <p>Generated by the CLI monitor. At most one per pattern, target and PHT day.</p>
        </div>
    </div>
    <div class="dashboard-table-wrap">
        <table class="dashboard-table">
            <thead><tr><th>Pattern</th><th>Target</th><th>Severity</th><th>Flagged at (PHT)</th></tr></thead>
            <tbody id="bg-monitor-flag-table">
                <?php if (!$data['flags']): ?>
                    <tr><td colspan="4">No automated flags yet. Run the CLI security monitor.</td></tr>
                <?php else: ?>
                    <?php foreach ($data['flags'] as $flag): ?>
                        <tr><td><?= bg_safe($flag['pattern']) ?></td>
                            <td><?= bg_safe($flag['target']) ?></td>
                            <td><strong><?= bg_safe($flag['severity']) ?></strong></td>
                            <td><?= bg_safe($flag['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="app-panel app-panel--table" aria-labelledby="monitor-events-title">
    <div class="app-panel__top">
        <div>
            <h2 id="monitor-events-title">LATEST 50 ACTIVITY EVENTS</h2>
            <p>Filtered log entries, newest first. No passwords, OTPs or raw metadata displayed.</p>
        </div>
    </div>
    <form class="bg-monitor__filters" method="get" action="admin-security.php"
          id="bg-monitor-filter-form" aria-label="Filter security activity events">
        <label>Date (PHT)
            <input type="date" name="date" value="<?= bg_safe($filters['date']) ?>">
        </label>
        <label>Event name
            <input type="text" name="event" maxlength="64"
                   placeholder="LOGIN_FAILED" value="<?= bg_safe($filters['event']) ?>">
        </label>
        <label>Username contains
            <input type="text" name="username" maxlength="80"
                   placeholder="Account username" value="<?= bg_safe($filters['username']) ?>">
        </label>
        <label>IP contains
            <input type="text" name="ip" maxlength="45" placeholder="Source IP"
                   value="<?= bg_safe($filters['ip']) ?>">
        </label>
        <button type="submit" class="button button--small">Apply filters</button>
        <a class="button button--ghost button--small" href="admin-security.php">Clear</a>
    </form>
    <div class="dashboard-table-wrap">
        <table class="dashboard-table">
            <thead><tr><th>Event type</th><th>Typed username</th>
                <th>IP address</th><th>Time (PHT)</th></tr></thead>
            <tbody id="bg-monitor-events">
                <?php if (!$data['events']): ?>
                    <tr><td colspan="4">No activity matches these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($data['events'] as $event): ?>
                        <tr><td><?= bg_safe($event['event_type']) ?></td>
                            <td><?= bg_safe((string) ($event['username_typed'] ?? '—')) ?></td>
                            <td><?= bg_safe($event['ip_address']) ?></td>
                            <td><?= bg_safe($event['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<p class="app-mini-note bg-monitor__footer">
    Security events are append-only in the application. The monitor scans the last
    24 hours when the CLI task runs; opening this page does not create threat flags.
    This page and its JSON feed are separately protected by server-side admin checks.
</p>
<script src="js/admin-security.js" defer></script>
<?php bg_app_end(); ?>
