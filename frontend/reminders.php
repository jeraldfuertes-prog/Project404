<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/billing.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';

try {
    [$db, $user] = bg_app_context();
    $ready = bg_bill_schema_ready($db);
    $today = bg_now()->format('Y-m-d');
    $bills = $ready ? bg_user_bills($db, $user['id_number']) : [];
} catch (Throwable $e) {
    error_log('BillGuard: reminder page setup failed.');
    http_response_code(503);
    exit('Reminders are temporarily unavailable.');
}
$groups = ['Overdue' => [], 'Due Today' => [], 'Due Soon' => [], 'Scheduled' => []];
foreach ($bills as $bill) {
    if ($bill['balance_cents'] === 0) continue;
    $label = bg_bill_due_label($bill['due_date'], $bill['balance_cents'], $today);
    $groups[$label][] = $bill;
}
$alerts = count($groups['Overdue']) + count($groups['Due Today']) + count($groups['Due Soon']);

bg_app_start('Reminders', 'reminders', $user);
?>
<header class="app-page__header">
    <div><p class="eyebrow">Automatic due-date checks</p><h1>PAYMENT REMINDERS.</h1>
        <p>Unpaid bills are checked against today's date in Philippine time.</p></div>
</header>
<div class="app-stat-grid">
    <div class="app-stat"><span class="app-stat__label">Overdue</span>
        <strong class="app-stat__value"><?= count($groups['Overdue']) ?></strong>
        <small class="app-stat__detail">Past due, balance remains</small></div>
    <div class="app-stat"><span class="app-stat__label">Due Today</span>
        <strong class="app-stat__value"><?= count($groups['Due Today']) ?></strong>
        <small class="app-stat__detail">Requires attention today</small></div>
    <div class="app-stat"><span class="app-stat__label">Due Soon</span>
        <strong class="app-stat__value"><?= count($groups['Due Soon']) ?></strong>
        <small class="app-stat__detail">Within the next 7 days</small></div>
    <div class="app-stat"><span class="app-stat__label">Scheduled</span>
        <strong class="app-stat__value"><?= count($groups['Scheduled']) ?></strong>
        <small class="app-stat__detail">More than 7 days remaining</small></div>
</div>
<?php if (!$ready): ?>
    <div class="notice notice--error" role="alert">
        First run the database migration
        <code>backend/database/migrations/2026-11-08_add_other_utility.sql</code>.
        Back up <code>billguard_dev</code> and import the migration once using phpMyAdmin.
    </div>
<?php elseif ($alerts > 0): ?>
    <div class="notice" role="status">
        You have <strong><?= $alerts ?> bill<?= $alerts === 1 ? '' : 's' ?></strong> that
        <?= $alerts === 1 ? 'needs' : 'need' ?> attention. Select <strong>Record Payment</strong>
        after you've made a payment through your utility provider.
    </div>
<?php else: ?>
    <div class="notice notice--cyan" role="status">
        No bills are overdue or due within the next 7 days.
    </div>
<?php endif; ?>
<?php if ($ready): ?>
    <?php foreach ($groups as $label => $list): ?>
        <section class="app-panel app-panel--table" aria-labelledby="reminder-<?= bg_safe(strtolower(str_replace(' ', '-', $label))) ?>">
            <div class="app-panel__top">
                <h2 id="reminder-<?= bg_safe(strtolower(str_replace(' ', '-', $label))) ?>"><?= bg_safe(strtoupper($label)) ?></h2>
                <span class="app-badge <?= $label === 'Overdue' ? 'app-badge--overdue' : ($label === 'Scheduled' ? '' : 'app-badge--soon') ?>"><?= count($list) ?> BILLS</span>
            </div>
            <?php if (!$list): ?>
                <div class="app-empty">No <?= bg_safe(strtolower($label)) ?> bills.</div>
            <?php else: ?>
                <div class="dashboard-table-wrap">
                    <table class="dashboard-table">
                        <thead><tr><th>Utility</th><th>Description</th><th>Due Date</th><th>Remaining Balance</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $bill): ?>
                            <tr>
                                <td><?= bg_safe($bill['utility_label']) ?></td>
                                <td><?= bg_safe($bill['description']) ?></td>
                                <td><?= bg_safe($bill['due_date']) ?></td>
                                <td><?= bg_safe(bg_bill_peso($bill['balance_cents'])) ?></td>
                                <td><a href="payments.php?bill_id=<?= (int) $bill['id'] ?>">Record Payment</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <p class="app-mini-note">These reminders appear automatically when you open the dashboard or Reminders page. Email/push reminders are not yet enabled.</p>
<?php endif; ?>
<?php bg_app_end(); ?>
