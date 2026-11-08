<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/auth.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed missing');
    $db = billguard_db();
    $user = bg_auth_user($db, $seed);
    if ($user === null) {
        header('Location: login.php', true, 303);
        exit;
    }

    $today = bg_now()->format('Y-m-d');
    $month = bg_now()->format('Y-m-01');
    $nextMonth = bg_now()->modify('first day of next month')->format('Y-m-d');

    // All household queries must bind the authenticated user's ID.
    $summary = $db->prepare('SELECT COUNT(*) AS bill_count,
        COALESCE(SUM(amount), 0) AS bill_total,
        COALESCE(SUM(CASE WHEN status = :unpaid THEN amount ELSE 0 END), 0) AS unpaid_total
        FROM utility_bills WHERE user_id = :user_id AND billing_month >= :month
            AND billing_month < :next_month');
    $summary->execute([
        ':unpaid' => 'unpaid',
        ':user_id' => $user['id_number'],
        ':month' => $month,
        ':next_month' => $nextMonth,
    ]);
    $totals = $summary->fetch();

    $payment = $db->prepare('SELECT COALESCE(SUM(p.amount),0)
        FROM utility_payments p INNER JOIN utility_bills b ON p.bill_id = b.id
        WHERE b.user_id = :user_id AND p.paid_on >= :month AND p.paid_on < :next_month');
    $payment->execute([
        ':user_id' => $user['id_number'],
        ':month' => $month,
        ':next_month' => $nextMonth,
    ]);
    $paid = (float) $payment->fetchColumn();

    $upcoming = $db->prepare('SELECT utility_type, description, due_date, amount, status
        FROM utility_bills WHERE user_id = :user_id
        ORDER BY due_date ASC, id DESC LIMIT 8');
    $upcoming->execute([':user_id' => $user['id_number']]);
    $bills = $upcoming->fetchAll();

} catch (Throwable $e) {
    error_log('BillGuard: dashboard query failed.');
    http_response_code(503);
    exit('The dashboard is temporarily unavailable. Please try again later.');
}
$money = static fn(float $amount): string => '₱' . number_format($amount, 2);
$name = (string) $user['first_name'];
bg_page_start('Dashboard', 'dashboard');
?>
<section class="dashboard-intro">
    <div>
        <p class="eyebrow">Your household overview</p>
        <h1>WELCOME, <?= bg_safe($name) ?>.</h1>
        <p>View your household utility bills and payments in one place.</p>
    </div>
    <div class="dashboard-status"><span class="status-dot" aria-hidden="true"></span> VERIFIED SESSION</div>
</section>
<section aria-labelledby="overview-title">
    <h2 id="overview-title" class="dashboard-section-title">THIS MONTH'S SUMMARY</h2>
    <div class="dashboard-stats">
        <article class="card dashboard-stat">
            <span class="dashboard-stat__label">Total utility bills</span>
            <strong><?= bg_safe($money((float) ($totals['bill_total'] ?? 0))) ?></strong>
            <small><?= (int) ($totals['bill_count'] ?? 0) ?> bills this month</small>
        </article>
        <article class="card dashboard-stat">
            <span class="dashboard-stat__label">Unpaid bill value</span>
            <strong><?= bg_safe($money((float) ($totals['unpaid_total'] ?? 0))) ?></strong>
            <small>Unpaid bills for this billing month</small>
        </article>
        <article class="card dashboard-stat">
            <span class="dashboard-stat__label">Payments made</span>
            <strong><?= bg_safe($money($paid)) ?></strong>
            <small>Payments recorded this month</small>
        </article>
    </div>
</section>
<section class="dashboard-panel" aria-labelledby="bill-list-title">
    <div class="dashboard-panel__heading">
        <div>
            <p class="eyebrow">Personal account / <?= bg_safe(bg_now()->format('F Y')) ?></p>
            <h2 id="bill-list-title">YOUR UTILITY BILLS</h2>
        </div>
        <span class="dashboard-state-label">PRIVATE ACCOUNT VIEW</span>
    </div>
    <?php if (empty($bills)): ?>
        <div class="dashboard-empty">
            <span class="dashboard-empty__symbol" aria-hidden="true">▣</span>
            <h3>No bills recorded yet</h3>
            <p>Your account is ready. Adding and editing household bills will be available when the Bill Management module is built.</p>
        </div>
    <?php else: ?>
        <div class="dashboard-table-wrap">
            <table class="dashboard-table">
                <thead><tr><th>Utility</th><th>Description</th><th>Due date</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($bills as $bill): ?>
                    <tr>
                        <td><?= bg_safe($bill['utility_type']) ?></td>
                        <td><?= bg_safe($bill['description']) ?></td>
                        <td><?= bg_safe($bill['due_date']) ?></td>
                        <td><?= bg_safe($money((float) $bill['amount'])) ?></td>
                        <td><span class="dashboard-state"><?= bg_safe(ucfirst($bill['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<section class="notice notice--cyan" aria-label="Development notice">
    <strong>BillGuard development:</strong> This is a real, session-protected household dashboard using your database.
    Adding, editing and paying bills will be completed in the Bill Management phase.
</section>
<?php bg_page_end(); ?>
