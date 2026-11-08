<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/billing.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';

try {
    [$db, $user] = bg_app_context();
    $ready = bg_bill_schema_ready($db);
} catch (Throwable $e) {
    error_log('BillGuard: payments setup failed.');
    http_response_code(503);
    exit('The payment records page is temporarily unavailable.');
}
$errors = [];
$message = '';
$notice = (string) ($_SESSION['bg_payment_notice'] ?? '');
unset($_SESSION['bg_payment_notice']);
$values = ['amount' => '', 'paid_on' => bg_now()->format('Y-m-d'), 'note' => ''];
$requestedBill = filter_var($_GET['bill_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedBill = $requestedBill === false ? 0 : (int) $requestedBill;

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $selectedBill = filter_var($_POST['bill_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($selectedBill === false) $selectedBill = 0;
    foreach (['amount', 'paid_on', 'note'] as $name) $values[$name] = bg_form_input($_POST, $name);
    if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = 'Your request token expired. Refresh the page and retry.';
    } else {
        $result = bg_record_payment($db, $user['id_number'], $selectedBill, $_POST);
        $errors = $result['errors'] ?? [];
        $message = $result['message'] ?? '';
        if ($result['ok']) {
            $_SESSION['bg_payment_notice'] = 'Payment recorded. Your remaining balance has been updated.';
            header('Location: payments.php', true, 303);
            exit;
        }
    }
}

try {
    $bills = $ready ? bg_user_bills($db, $user['id_number']) : [];
    $history = $ready ? bg_user_payments($db, $user['id_number']) : [];
} catch (Throwable $e) {
    error_log('BillGuard: payments list failed.');
    http_response_code(503);
    exit('Payment records could not be loaded.');
}
$payable = array_values(array_filter($bills, static fn(array $bill): bool => $bill['balance_cents'] > 0));
$isValidSelection = false;
foreach ($payable as $bill) if ((int) $bill['id'] === $selectedBill) $isValidSelection = true;
if (!$isValidSelection && $selectedBill !== 0) {
    if ($message === '') $message = 'That bill is no longer payable or does not belong to your account.';
    $selectedBill = 0;
}
$v = static fn(string $name): string => bg_safe((string) ($values[$name] ?? ''));
$err = static fn(string $name): string => isset($errors[$name])
    ? '<p role="alert" class="app-form__error">' . bg_safe($errors[$name]) . '</p>' : '';

bg_app_start('Payments', 'payments', $user);
?>
<header class="app-page__header">
    <div><p class="eyebrow">Payments and balances</p><h1>RECORD PAYMENTS.</h1>
        <p>Log payments you've already made and see the exact remaining balance.</p></div>
</header>
<div class="notice notice--cyan" role="note">
    <strong>Important:</strong> Recording a payment here does not send money to a provider.
    It only updates your personal BillGuard payment history.
</div>
<?php if (!$ready): ?>
    <div class="notice notice--error" role="alert">
        Import the one-time migration <code>backend/database/migrations/2026-11-08_add_other_utility.sql</code>
        into <code>billguard_dev</code> first, after backing up your database.
    </div>
<?php else: ?>
    <?php if ($notice): ?><div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="notice notice--error" role="alert"><?= bg_safe($message) ?></div><?php endif; ?>
    <div class="app-split">
        <section class="app-panel" aria-labelledby="record-title">
            <div class="app-panel__top"><h2 id="record-title">NEW PAYMENT RECORD</h2></div>
            <?php if (!$payable): ?>
                <p class="app-text--muted">You have no unpaid balances. <a href="bills.php#bill-form">Add a bill</a> first.</p>
            <?php else: ?>
                <form class="app-form" method="post" action="payments.php" novalidate>
                    <?= bg_csrf_field() ?>
                    <div class="form-field">
                        <label class="form-label" for="bill_id">Select Utility Bill *</label>
                        <select class="form-control" name="bill_id" id="bill_id" required>
                            <option value="">Choose a bill</option>
                            <?php foreach ($payable as $bill): ?>
                                <option value="<?= (int) $bill['id'] ?>" <?= $selectedBill === (int) $bill['id'] ? 'selected' : '' ?>>
                                    <?= bg_safe($bill['utility_label'] . ' – ' . $bill['description'] . ' (Remaining ' . bg_bill_peso($bill['balance_cents']) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('bill_id') ?>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="amount">Amount Paid (PHP) *</label>
                        <input class="form-control" id="amount" name="amount" type="number" required
                            min="0.01" max="9999999999.99" step="0.01" value="<?= $v('amount') ?>" placeholder="0.00">
                        <?= $err('amount') ?>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="paid_on">Payment Date *</label>
                        <input class="form-control" id="paid_on" name="paid_on" type="date"
                            max="<?= bg_safe(bg_now()->format('Y-m-d')) ?>" required value="<?= $v('paid_on') ?>">
                        <?= $err('paid_on') ?>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="note">Payment Note (Optional)</label>
                        <input class="form-control" id="note" name="note" type="text" maxlength="255"
                            placeholder="e.g. Paid through provider's app" value="<?= $v('note') ?>">
                        <?= $err('note') ?>
                    </div>
                    <div class="app-form__actions">
                        <button class="button button--small" type="submit">Save Payment Record</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
        <section class="app-panel" aria-labelledby="balance-title">
            <div class="app-panel__top"><h2 id="balance-title">OUTSTANDING BALANCES</h2></div>
            <?php if (!$payable): ?>
                <p class="app-text--muted">Everything is paid up.</p>
            <?php else: ?>
                <?php foreach ($payable as $bill): ?>
                    <div class="app-inline-summary">
                        <div><strong><?= bg_safe($bill['utility_label']) ?></strong>
                            <div class="app-muted"><?= bg_safe($bill['description']) ?></div>
                            <div class="app-muted">Due <?= bg_safe($bill['due_date']) ?></div>
                        </div>
                        <strong><?= bg_safe(bg_bill_peso($bill['balance_cents'])) ?></strong>
                    </div>
                    <hr style="border:0;border-top:1px solid var(--cp-border-muted)">
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
    <section class="app-panel app-panel--table" aria-labelledby="history-title">
        <div class="app-panel__top">
            <div><h2 id="history-title">PAYMENT HISTORY</h2><p>Most recent 100 payments, newest first.</p></div>
        </div>
        <?php if (!$history): ?>
            <div class="app-empty"><strong>No payments recorded.</strong>Your payment history will appear here.</div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead><tr><th>Date</th><th>Utility</th><th>Bill Description</th><th>Amount</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $payment): ?>
                        <tr>
                            <td><?= bg_safe($payment['paid_on']) ?></td>
                            <td><?= bg_safe(bg_bill_type_label($payment)) ?></td>
                            <td><?= bg_safe($payment['description']) ?></td>
                            <td><?= bg_safe(bg_bill_peso(bg_bill_cents((string) $payment['amount'], true) ?? 0)) ?></td>
                            <td><?= bg_safe((string) ($payment['note'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php bg_app_end(); ?>
