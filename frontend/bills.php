<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/billing.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';

try {
    [$db, $user] = bg_app_context();
    $ready = bg_bill_schema_ready($db);
} catch (Throwable $e) {
    error_log('BillGuard: bills page setup failed.');
    http_response_code(503);
    exit('Bill management is temporarily unavailable.');
}

$errors = [];
$message = '';
$notice = (string) ($_SESSION['bg_bills_notice'] ?? '');
unset($_SESSION['bg_bills_notice']);
$editId = 0;
$values = [];
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = 'Your session token expired. Refresh the form and try again.';
    } else {
        $action = bg_form_input($_POST, 'action');
        $rawId = bg_form_input($_POST, 'bill_id');
        $id = $rawId === '' ? 0 : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($rawId !== '' && $id === false) {
            $message = 'Invalid bill ID.';
        } elseif ($action === 'save') {
            $editId = $id ?: 0;
            [$errors, $values] = bg_bill_validate($_POST);
            if ($errors === []) {
                $result = bg_save_bill($db, $user['id_number'], $_POST, $editId);
                $errors = $result['errors'] ?? [];
                $message = $result['message'] ?? '';
                if ($result['ok']) {
                    $_SESSION['bg_bills_notice'] = $editId ? 'Bill updated successfully.' : 'New utility bill added.';
                    header('Location: bills.php', true, 303);
                    exit;
                }
            }
            if ($values === []) {
                foreach (['utility_type', 'custom_utility_name', 'description', 'billing_month', 'due_date', 'amount'] as $field) {
                    $values[$field] = bg_form_input($_POST, $field);
                }
            }
            if (strlen($values['billing_month'] ?? '') === 10) {
                $values['billing_month'] = substr($values['billing_month'], 0, 7);
            }
        } elseif ($action === 'delete' && $id !== false && $id > 0) {
            $result = bg_delete_bill($db, $user['id_number'], $id);
            if ($result['ok']) {
                $_SESSION['bg_bills_notice'] = 'Bill deleted successfully.';
                header('Location: bills.php', true, 303);
                exit;
            }
            $message = $result['message'] ?? 'Could not delete bill.';
        } else {
            $message = 'Invalid bill action.';
        }
    }
}

try {
    $rows = $ready ? bg_user_bills($db, $user['id_number']) : [];
    if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['edit'])) {
        $candidate = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        foreach ($rows as $bill) {
            if ($candidate !== false && (int) $bill['id'] === $candidate) {
                if ((int) $bill['payment_count'] > 0) {
                    $message = 'Bills with payment records cannot be edited.';
                } else {
                    $editId = (int) $bill['id'];
                    $values = [
                        'utility_type' => $bill['utility_type'],
                        'custom_utility_name' => $bill['custom_utility_name'] ?? '',
                        'description' => $bill['description'],
                        'billing_month' => substr($bill['billing_month'], 0, 7),
                        'due_date' => $bill['due_date'],
                        'amount' => $bill['amount'],
                    ];
                }
                break;
            }
        }
    }
} catch (Throwable $e) {
    error_log('BillGuard: bill list failed.');
    http_response_code(503);
    exit('Could not load utility bills.');
}

$values += [
    'utility_type' => 'Electricity', 'custom_utility_name' => '', 'description' => '',
    'billing_month' => bg_now()->format('Y-m'), 'due_date' => bg_now()->format('Y-m-d'), 'amount' => '',
];
$v = static fn(string $key): string => bg_safe((string) ($values[$key] ?? ''));
$err = static fn(string $key): string => isset($errors[$key])
    ? '<p class="app-form__error" role="alert">' . bg_safe($errors[$key]) . '</p>' : '';
$today = bg_now()->format('Y-m-d');

bg_app_start('Utility Bills', 'bills', $user);
?>
<header class="app-page__header">
    <div><p class="eyebrow">Household financial records</p><h1>UTILITY BILLS.</h1>
        <p>Add, update and track your Electricity, Water, Internet or Other bills.</p></div>
</header>
<?php if (!$ready): ?>
    <div class="notice notice--error" role="alert">
        <strong>Database migration required:</strong> Back up your database, then import
        <code>backend/database/migrations/2026-11-08_add_other_utility.sql</code>
        into <code>billguard_dev</code> using phpMyAdmin. Run it only once.
    </div>
<?php else: ?>
    <?php if ($notice): ?><div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="notice notice--error" role="alert"><?= bg_safe($message) ?></div><?php endif; ?>
    <section class="app-panel" id="bill-form" aria-labelledby="form-title">
        <div class="app-panel__top">
            <h2 id="form-title"><?= $editId ? 'EDIT UTILITY BILL' : 'ADD NEW UTILITY BILL' ?></h2>
            <?php if ($editId): ?><a href="bills.php#bill-form">Cancel Edit</a><?php endif; ?>
        </div>
        <p class="app-mini-note">A bill cannot be edited or deleted after a payment has been recorded.</p>
        <form class="app-form" method="post" action="bills.php#bill-form" novalidate>
            <?= bg_csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="bill_id" value="<?= $editId ?: '' ?>">
            <div class="app-form__grid">
                <div class="form-field">
                    <label class="form-label" for="utility_type">Utility Type *</label>
                    <select class="form-control" id="utility_type" name="utility_type" required>
                        <?php foreach (['Electricity', 'Water', 'Internet', 'Other'] as $type): ?>
                            <option value="<?= bg_safe($type) ?>" <?= $values['utility_type'] === $type ? 'selected' : '' ?>><?= bg_safe($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('utility_type') ?>
                </div>
                <div class="form-field app-other-field" id="other-utility-field" <?= $values['utility_type'] !== 'Other' ? 'hidden' : '' ?>>
                    <label class="form-label" for="custom_utility_name">Specify Utility Type *</label>
                    <input class="form-control" type="text" name="custom_utility_name"
                        id="custom_utility_name" maxlength="80" placeholder="e.g. LPG / Gas, Garbage Collection"
                        value="<?= $v('custom_utility_name') ?>" <?= $values['utility_type'] === 'Other' ? 'required' : '' ?>>
                    <?= $err('custom_utility_name') ?>
                </div>
                <div class="form-field app-form__wide">
                    <label class="form-label" for="description">Bill Description *</label>
                    <input class="form-control" type="text" id="description" name="description"
                        required maxlength="120" placeholder="e.g. November electricity usage"
                        value="<?= $v('description') ?>">
                    <?= $err('description') ?>
                </div>
                <div class="form-field">
                    <label class="form-label" for="billing_month">Billing Month *</label>
                    <input class="form-control" id="billing_month" name="billing_month"
                        type="month" min="2000-01" max="2100-12" value="<?= $v('billing_month') ?>" required>
                    <?= $err('billing_month') ?>
                </div>
                <div class="form-field">
                    <label class="form-label" for="due_date">Due Date *</label>
                    <input class="form-control" id="due_date" name="due_date"
                        type="date" value="<?= $v('due_date') ?>" required>
                    <?= $err('due_date') ?>
                </div>
                <div class="form-field">
                    <label class="form-label" for="amount">Total Bill Amount (PHP) *</label>
                    <input class="form-control" id="amount" name="amount" type="number"
                        min="0.01" max="9999999999.99" step="0.01" placeholder="0.00"
                        value="<?= $v('amount') ?>" required>
                    <?= $err('amount') ?>
                </div>
            </div>
            <div class="app-form__actions">
                <button class="button button--small" type="submit"><?= $editId ? 'Save Changes' : 'Add Utility Bill' ?></button>
            </div>
        </form>
    </section>
    <section class="app-panel app-panel--table" aria-labelledby="bill-list-heading">
        <div class="app-panel__top">
            <div><h2 id="bill-list-heading">YOUR RECORDED BILLS</h2>
                <p><?= count($rows) ?> <?= count($rows) === 1 ? 'bill' : 'bills' ?> in your account</p></div>
        </div>
        <?php if (!$rows): ?>
            <div class="app-empty"><strong>No bills recorded yet.</strong>Use the form above to create your first bill.</div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead><tr><th>Utility / Description</th><th>Billing Month</th><th>Due Date</th><th>Bill</th><th>Remaining</th><th>Status</th><th>Manage</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $bill):
                        $due = bg_bill_due_label($bill['due_date'], $bill['balance_cents'], $today);
                        $stateClass = $bill['computed_status'] === 'paid' ? 'app-badge--paid'
                            : ($bill['computed_status'] === 'partial' ? 'app-badge--partial' : '');
                    ?>
                        <tr>
                            <td><strong><?= bg_safe($bill['utility_label']) ?></strong>
                                <div class="app-muted"><?= bg_safe($bill['description']) ?></div></td>
                            <td><?= bg_safe(substr($bill['billing_month'], 0, 7)) ?></td>
                            <td><?= bg_safe($bill['due_date']) ?><div class="app-muted"><?= bg_safe($due) ?></div></td>
                            <td><?= bg_safe(bg_bill_peso($bill['amount_cents'])) ?></td>
                            <td><?= bg_safe(bg_bill_peso($bill['balance_cents'])) ?></td>
                            <td><span class="app-badge <?= $stateClass ?>"><?= bg_safe(ucfirst($bill['computed_status'])) ?></span></td>
                            <td>
                                <div class="app-action-links">
                                    <?php if ($bill['balance_cents'] > 0): ?>
                                        <a href="payments.php?bill_id=<?= (int) $bill['id'] ?>">Pay</a>
                                    <?php endif; ?>
                                    <?php if ((int) $bill['payment_count'] === 0): ?>
                                        <a href="bills.php?edit=<?= (int) $bill['id'] ?>#bill-form">Edit</a>
                                        <form method="post" action="bills.php" data-confirm-delete>
                                            <?= bg_csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="bill_id" value="<?= (int) $bill['id'] ?>">
                                            <button class="app-button-danger" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php bg_app_end(); ?>
