<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/registration.php';

/**
 * BillGuard household billing rules. Money is converted from DECIMAL strings
 * to INTEGER centavos for exact calculations; never calculate balances as float.
 * All writes are restricted to the authenticated owner's id_number.
 */
function bg_bill_schema_ready(PDO $db): bool
{
    $q = $db->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'utility_bills'
            AND column_name = 'custom_utility_name'");
    return (int) $q->fetchColumn() === 1;
}

function bg_app_context(): array
{
    bg_secure_start();
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Approved seed not configured.');
    $db = billguard_db();
    $user = bg_auth_user($db, $seed);
    if ($user === null) {
        header('Location: login.php', true, 303);
        exit;
    }
    return [$db, $user, $seed];
}

/** Decimal money string => exact integer centavos, or null for invalid input. */
function bg_bill_cents(string $input, bool $allowZero = false): ?int
{
    $text = trim($input);
    if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $text, $part)) {
        return null;
    }
    $cents = (int) $part[1] * 100 + (int) str_pad($part[2] ?? '', 2, '0');
    if (!$allowZero && $cents <= 0) return null;
    return $cents;
}

/** Exact integer centavos => DB-ready DECIMAL(12,2) string. */
function bg_bill_decimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function bg_bill_peso(int $cents): string
{
    return '₱' . number_format(intdiv($cents, 100), 0) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function bg_bill_outstanding(int $billCents, int $paidCents): int
{
    return max(0, $billCents - $paidCents);
}

function bg_bill_status(int $billCents, int $paidCents): string
{
    if ($paidCents >= $billCents) return 'paid';
    return $paidCents > 0 ? 'partial' : 'unpaid';
}

function bg_bill_due_label(string $date, int $balance, string $today): string
{
    if ($balance === 0) return 'Paid';
    if ($date < $today) return 'Overdue';
    if ($date === $today) return 'Due Today';
    if ($date <= (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))
        ->modify('+7 days')->format('Y-m-d')) return 'Due Soon';
    return 'Scheduled';
}

function bg_bill_type_label(array $bill): string
{
    return $bill['utility_type'] === 'Other'
        ? (string) ($bill['custom_utility_name'] ?: 'Other')
        : (string) $bill['utility_type'];
}

function bg_bill_safe_text(string $text, int $min, int $max): bool
{
    return mb_check_encoding($text, 'UTF-8')
        && preg_match('/\p{C}/u', $text) !== 1
        && mb_strlen($text, 'UTF-8') >= $min
        && mb_strlen($text, 'UTF-8') <= $max;
}

function bg_bill_month(string $value): ?string
{
    if (!preg_match('/^([0-9]{4})-([0-9]{2})$/D', $value, $m)) return null;
    $year = (int) $m[1];
    $month = (int) $m[2];
    return ($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12)
        ? "{$value}-01" : null;
}

/** @return array{0:array<string,string>,1:array<string,string>} */
function bg_bill_validate(array $input): array
{
    $fields = ['utility_type', 'custom_utility_name', 'description', 'billing_month', 'due_date', 'amount'];
    $value = [];
    foreach ($fields as $key) $value[$key] = bg_form_input($input, $key);
    $errors = [];

    if (!in_array($value['utility_type'], ['Electricity', 'Water', 'Internet', 'Other'], true)) {
        $errors['utility_type'] = 'Choose Electricity, Water, Internet or Other.';
    }
    if ($value['utility_type'] === 'Other') {
        if (!bg_bill_safe_text($value['custom_utility_name'], 2, 80)) {
            $errors['custom_utility_name'] = 'Specify a utility name containing 2–80 printable characters.';
        }
    } else {
        $value['custom_utility_name'] = '';
    }
    if (!bg_bill_safe_text($value['description'], 3, 120)) {
        $errors['description'] = 'Description must contain 3–120 printable characters.';
    }
    $month = bg_bill_month($value['billing_month']);
    if ($month === null) $errors['billing_month'] = 'Choose a valid billing month.';
    $due = bg_date($value['due_date']);
    if ($due === null) {
        $errors['due_date'] = 'Choose a valid due date.';
    } elseif ($month !== null && $value['due_date'] < $month) {
        $errors['due_date'] = 'Due date cannot be before the billing month.';
    }
    $cents = bg_bill_cents($value['amount']);
    if ($cents === null) $errors['amount'] = 'Enter a positive bill amount (maximum 10 digits and 2 decimals).';
    else $value['amount'] = bg_bill_decimal($cents);
    $value['billing_month'] = $month ?? $value['billing_month'];
    return [$errors, $value];
}

function bg_user_bills(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT b.id, b.user_id, b.utility_type, b.custom_utility_name,
            b.description, b.billing_month, b.due_date, b.amount, b.status,
            COALESCE(pay.total_paid, 0) AS paid_amount,
            COALESCE(pay.payment_count, 0) AS payment_count
        FROM utility_bills b
        LEFT JOIN (SELECT bill_id, SUM(amount) AS total_paid, COUNT(*) AS payment_count
                   FROM utility_payments GROUP BY bill_id) pay ON pay.bill_id = b.id
        WHERE b.user_id = :uid ORDER BY b.due_date ASC, b.id DESC');
    $q->execute([':uid' => $uid]);
    $rows = $q->fetchAll();
    foreach ($rows as &$row) {
        $row['amount_cents'] = bg_bill_cents((string) $row['amount'], true) ?? 0;
        $row['paid_cents'] = bg_bill_cents((string) $row['paid_amount'], true) ?? 0;
        $row['balance_cents'] = bg_bill_outstanding($row['amount_cents'], $row['paid_cents']);
        $row['computed_status'] = bg_bill_status($row['amount_cents'], $row['paid_cents']);
        $row['utility_label'] = bg_bill_type_label($row);
    }
    unset($row);
    return $rows;
}

function bg_user_payments(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT p.id, p.bill_id, p.amount, p.paid_on, p.note,
            b.utility_type, b.custom_utility_name, b.description
        FROM utility_payments p
        INNER JOIN utility_bills b ON b.id = p.bill_id
        WHERE b.user_id = :uid ORDER BY p.paid_on DESC, p.id DESC LIMIT 100');
    $q->execute([':uid' => $uid]);
    return $q->fetchAll();
}

/**
 * Create/edit a bill. Edits are forbidden after any recorded payment,
 * preserving the integrity of the original amount and history.
 */
function bg_save_bill(PDO $db, string $uid, array $input, int $editId = 0): array
{
    [$errors, $v] = bg_bill_validate($input);
    if ($errors) return ['ok' => false, 'errors' => $errors];
    try {
        $db->beginTransaction();
        if ($editId > 0) {
            $lock = $db->prepare('SELECT id FROM utility_bills WHERE id = :id AND user_id = :uid FOR UPDATE');
            $lock->execute([':id' => $editId, ':uid' => $uid]);
            if (!$lock->fetchColumn()) {
                $db->rollBack();
                return ['ok' => false, 'message' => 'Bill not found in your account.'];
            }
            $check = $db->prepare('SELECT COUNT(*) FROM utility_payments WHERE bill_id = :id');
            $check->execute([':id' => $editId]);
            if ((int) $check->fetchColumn() > 0) {
                $db->rollBack();
                return ['ok' => false, 'message' => 'This bill has payments and cannot be edited.'];
            }
            $q = $db->prepare("UPDATE utility_bills
                SET utility_type = :type, custom_utility_name = :custom, description = :description,
                    billing_month = :month, due_date = :due, amount = :amount, status = 'unpaid'
                WHERE id = :id AND user_id = :uid");
            $params = [':id' => $editId, ':uid' => $uid];
            $event = 'BILL_UPDATED';
        } else {
            $q = $db->prepare("INSERT INTO utility_bills
                (user_id, utility_type, custom_utility_name, description, billing_month, due_date, amount, status)
                VALUES (:uid, :type, :custom, :description, :month, :due, :amount, 'unpaid')");
            $params = [':uid' => $uid];
            $event = 'BILL_CREATED';
        }
        $q->execute($params + [
            ':type' => $v['utility_type'],
            ':custom' => $v['custom_utility_name'] === '' ? null : $v['custom_utility_name'],
            ':description' => $v['description'],
            ':month' => $v['billing_month'],
            ':due' => $v['due_date'],
            ':amount' => $v['amount'],
        ]);
        $id = $editId ?: (int) $db->lastInsertId();
        bg_log($db, $event, null, $uid, ['bill_id' => $id]);
        $db->commit();
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: bill save failed.');
        return ['ok' => false, 'message' => 'Could not save the bill. Please try again.'];
    }
}

function bg_delete_bill(PDO $db, string $uid, int $id): array
{
    if ($id < 1) return ['ok' => false, 'message' => 'Invalid bill.'];
    try {
        $db->beginTransaction();
        $lock = $db->prepare('SELECT id FROM utility_bills WHERE id = :id AND user_id = :uid FOR UPDATE');
        $lock->execute([':id' => $id, ':uid' => $uid]);
        if (!$lock->fetchColumn()) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Bill not found in your account.'];
        }
        $count = $db->prepare('SELECT COUNT(*) FROM utility_payments WHERE bill_id = :id');
        $count->execute([':id' => $id]);
        if ((int) $count->fetchColumn() !== 0) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Bills with recorded payments cannot be deleted.'];
        }
        $q = $db->prepare('DELETE FROM utility_bills WHERE id = :id AND user_id = :uid');
        $q->execute([':id' => $id, ':uid' => $uid]);
        bg_log($db, 'BILL_DELETED', null, $uid, ['bill_id' => $id]);
        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: bill delete failed.');
        return ['ok' => false, 'message' => 'Could not delete the bill.'];
    }
}

function bg_payment_validate(array $input): array
{
    $errors = [];
    $amount = bg_bill_cents(bg_form_input($input, 'amount'));
    if ($amount === null) $errors['amount'] = 'Enter a positive payment amount with at most two decimal places.';
    $date = bg_form_input($input, 'paid_on');
    if (bg_date($date) === null || $date > bg_now()->format('Y-m-d')) {
        $errors['paid_on'] = 'Choose a valid payment date that is not in the future.';
    }
    $note = bg_form_input($input, 'note');
    if ($note !== '' && !bg_bill_safe_text($note, 1, 255)) {
        $errors['note'] = 'Note must be at most 255 printable characters.';
    }
    return [$errors, ['amount_cents' => $amount, 'paid_on' => $date, 'note' => $note]];
}

function bg_record_payment(PDO $db, string $uid, int $billId, array $input): array
{
    [$errors, $v] = bg_payment_validate($input);
    if ($billId < 1) $errors['bill_id'] = 'Select a bill to pay.';
    if ($errors) return ['ok' => false, 'errors' => $errors];
    try {
        $db->beginTransaction();
        // Lock owner-scoped bill row to serialize concurrent payments.
        $lock = $db->prepare('SELECT id, amount FROM utility_bills
            WHERE id = :bid AND user_id = :uid FOR UPDATE');
        $lock->execute([':bid' => $billId, ':uid' => $uid]);
        $bill = $lock->fetch();
        if (!$bill) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Bill not found in your account.'];
        }
        $paidQ = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM utility_payments WHERE bill_id = :bid');
        $paidQ->execute([':bid' => $billId]);
        $already = bg_bill_cents((string) $paidQ->fetchColumn(), true) ?? 0;
        $amount = bg_bill_cents((string) $bill['amount'], true) ?? 0;
        $balance = bg_bill_outstanding($amount, $already);
        if ($balance === 0) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'This bill is already fully paid.'];
        }
        if ($v['amount_cents'] > $balance) {
            $db->rollBack();
            return ['ok' => false, 'errors' => ['amount' => 'Payment exceeds the remaining balance of ' . bg_bill_peso($balance) . '.']];
        }
        $q = $db->prepare('INSERT INTO utility_payments (bill_id, amount, paid_on, note)
            VALUES (:bid, :amount, :paid, :note)');
        $q->execute([
            ':bid' => $billId, ':amount' => bg_bill_decimal($v['amount_cents']),
            ':paid' => $v['paid_on'], ':note' => $v['note'] ?: null,
        ]);
        $newStatus = bg_bill_status($amount, $already + $v['amount_cents']);
        $update = $db->prepare('UPDATE utility_bills SET status = :status WHERE id = :bid AND user_id = :uid');
        $update->execute([':status' => $newStatus, ':bid' => $billId, ':uid' => $uid]);
        bg_log($db, 'PAYMENT_RECORDED', null, $uid, [
            'bill_id' => $billId,
            'payment_id' => (int) $db->lastInsertId(),
        ]);
        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: payment record failed.');
        return ['ok' => false, 'message' => 'Could not record payment. Please try again.'];
    }
}
