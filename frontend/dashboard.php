<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/billing.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';

try {
    [$db, $user] = bg_app_context();
    $ready = bg_bill_schema_ready($db);
    $today = bg_now()->format('Y-m-d');
    $month = bg_now()->format('Y-m-01');
    $nextMonth = bg_now()->modify('first day of next month')->format('Y-m-d');
    $bills = $ready ? bg_user_bills($db, $user['id_number']) : [];
    $billedMonth = 0;
    $countMonth = 0;
    $outstanding = 0;
    $dueSoon = 0;
    $overdue = 0;
    $pendingBills = [];
    foreach ($bills as $bill) {
        if ($bill['billing_month'] >= $month && $bill['billing_month'] < $nextMonth) {
            $billedMonth += $bill['amount_cents'];
            $countMonth++;
        }
        $outstanding += $bill['balance_cents'];
        $dueLabel = bg_bill_due_label($bill['due_date'], $bill['balance_cents'], $today);
        if ($dueLabel === 'Overdue') $overdue++;
        if (in_array($dueLabel, ['Due Today', 'Due Soon'], true)) $dueSoon++;
        if ($bill['balance_cents'] > 0) {
            $bill['due_label'] = $dueLabel;
            $pendingBills[] = $bill;
        }
    }
    $pendingBills = array_slice($pendingBills, 0, 8);
    $paidThisMonth = 0;
    if ($ready) {
        $payment = $db->prepare('SELECT COALESCE(SUM(p.amount), 0)
            FROM utility_payments p INNER JOIN utility_bills b ON b.id = p.bill_id
            WHERE b.user_id = :uid AND p.paid_on >= :month AND p.paid_on < :next');
        $payment->execute([':uid' => $user['id_number'], ':month' => $month, ':next' => $nextMonth]);
        $paidThisMonth = bg_bill_cents((string) $payment->fetchColumn(), true) ?? 0;
    }
} catch (Throwable $e) {
    error_log('BillGuard: dashboard setup failed.');
    http_response_code(503);
    exit('The dashboard is temporarily unavailable. Check the private configuration and database.');
}

bg_app_start('Dashboard', 'dashboard', $user);
?>
<header class="app-page__header">
    <div>
        <p class="eyebrow">Your household overview / <?= bg_safe(bg_now()->format('F Y')) ?></p>
        <h1>WELCOME, <?= bg_safe((string) $user['first_name']) ?>.</h1>
        <p>Monitor bills, record payments, and stay ahead of due dates.</p>
    </div>
    <div class="app-quick-links"><a class="button button--small" href="bills.php#bill-form">+ Add Bill</a></div>
</header>
<?php if (!$ready): ?>
    <div class="notice notice--error" role="alert">
        <strong>One-time database update needed:</strong>
        Import <code>backend/database/migrations/2026-11-08_add_other_utility.sql</code>
        into your existing <code>billguard_dev</code> database using phpMyAdmin. Back up the database first.
        Do not re-import the entire schema.
    </div>
<?php endif; ?>
<section aria-labelledby="overview-title">
    <h2 id="overview-title" class="dashboard-section-title">HOUSEHOLD SUMMARY</h2>
    <div class="app-stat-grid">
        <article class="app-stat">
            <span class="app-stat__label">Billed This Month</span>
            <strong class="app-stat__value"><?= bg_safe(bg_bill_peso($billedMonth)) ?></strong>
            <small class="app-stat__detail"><?= $countMonth ?> bills in this billing month</small>
        </article>
        <article class="app-stat">
            <span class="app-stat__label">Remaining Balance</span>
            <strong class="app-stat__value"><?= bg_safe(bg_bill_peso($outstanding)) ?></strong>
            <small class="app-stat__detail">Across all your unpaid and partial bills</small>
        </article>
        <article class="app-stat">
            <span class="app-stat__label">Payments This Month</span>
            <strong class="app-stat__value"><?= bg_safe(bg_bill_peso($paidThisMonth)) ?></strong>
            <small class="app-stat__detail">Payments recorded this calendar month</small>
        </article>
        <article class="app-stat">
            <span class="app-stat__label">Due Within 7 Days</span>
            <strong class="app-stat__value"><?= $dueSoon ?> <?= $dueSoon === 1 ? 'bill' : 'bills' ?></strong>
            <small class="app-stat__detail"><?= $overdue ?> already overdue</small>
        </article>
    </div>
</section>
<section class="app-panel app-panel--table" aria-labelledby="upcoming-title">
    <div class="app-panel__top">
        <div>
            <h2 id="upcoming-title">UPCOMING &amp; OVERDUE BILLS</h2>
            <p>Only your outstanding bills; nearest due date first.</p>
        </div>
        <a href="reminders.php">View Reminders</a>
    </div>
    <?php if (!$pendingBills): ?>
        <div class="app-empty">
            <strong>No outstanding bills to show.</strong>
            <?= $ready ? 'Add your first utility bill, or enjoy your cleared balances.' : 'Import the database migration to load bills.' ?>
        </div>
    <?php else: ?>
        <div class="dashboard-table-wrap">
            <table class="dashboard-table">
                <thead><tr><th>Utility</th><th>Description</th><th>Due date</th><th>Remaining</th><th>Reminder</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pendingBills as $bill): ?>
                    <tr>
                        <td><?= bg_safe($bill['utility_label']) ?></td>
                        <td><?= bg_safe($bill['description']) ?></td>
                        <td><?= bg_safe($bill['due_date']) ?></td>
                        <td><?= bg_safe(bg_bill_peso($bill['balance_cents'])) ?></td>
                        <td><span class="app-badge <?= $bill['due_label'] === 'Overdue' ? 'app-badge--overdue' : 'app-badge--soon' ?>"><?= bg_safe($bill['due_label']) ?></span></td>
                        <td><a href="payments.php?bill_id=<?= (int) $bill['id'] ?>">Record Payment</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<div class="app-panel">
    <div class="app-panel__top">
        <h2>QUICK ACTIONS</h2>
    </div>
    <p class="app-mini-note">Payments are manual records only; BillGuard does not transfer money to utility providers.</p>
    <div class="app-quick-links">
        <a class="button button--small" href="bills.php#bill-form">+ Add Utility Bill</a>
        <a class="button button--ghost button--small" href="payments.php">Record Payment</a>
        <a class="button button--ghost button--small" href="reminders.php">Due-Date Reminders</a>
        <a class="button button--ghost button--small" href="profile.php">Account Profile</a>
    </div>
</div>
<?php bg_app_end(); ?>
