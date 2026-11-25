<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/admin_audit.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';
bg_secure_start();

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Private seed not configured.');
    $db = billguard_db();
    $admin = bg_admin_audit_authorize($db, $seed, 'page');
    if ($admin === null) {
        http_response_code(403);
        exit('Access denied.');
    }
    $events = bg_admin_audit_latest($db);
    bg_admin_audit_viewed($db, $admin, 'page');
} catch (Throwable $e) {
    error_log('BillGuard: admin activity view unavailable.');
    http_response_code(503);
    exit('Security activity is temporarily unavailable.');
}
bg_app_start('Security Activity', 'security', $admin);
?>
<header class="app-page__header">
    <div>
        <p class="eyebrow">BillGuard // Admin Security</p>
        <h1>SECURITY ACTIVITY.</h1>
        <p>Admin-only, read-only view of the latest 50 security and account events.</p>
    </div>
</header>
<section class="app-panel app-panel--table" aria-labelledby="audit-events-title">
    <div class="app-panel__top">
        <div>
            <h2 id="audit-events-title">LATEST AUDIT EVENTS</h2>
            <p>Times are Philippine time (PHT). Event details and credentials are not displayed.</p>
        </div>
    </div>
    <?php if (!$events): ?>
        <div class="app-empty"><strong>No activity has been recorded yet.</strong></div>
    <?php else: ?>
        <div class="dashboard-table-wrap">
            <table class="dashboard-table">
                <thead>
                    <tr><th>Event</th><th>Username (if supplied)</th><th>Source IP</th><th>Time (PHT)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= bg_safe((string) $event['event_type']) ?></td>
                        <td><?= bg_safe((string) ($event['username_typed'] ?? '—')) ?></td>
                        <td><?= bg_safe((string) $event['ip_address']) ?></td>
                        <td><?= bg_safe((string) $event['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<div class="app-panel">
    <p class="app-mini-note">
        This read-only activity view supports Protection #9. The full Phase 6
        dashboard will add charts, security flags, filters, and automated monitoring.
        Only admins can access this view or its JSON endpoint.
    </p>
</div>
<?php bg_app_end(); ?>
