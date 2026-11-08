<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/billing.php';

$passed = 0;
$failed = 0;
function assert_bill(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if ($condition) $passed++; else $failed++;
}

assert_bill(bg_bill_cents('1250.45') === 125045, 'PHP decimal amount converted to exact integer centavos');
assert_bill(bg_bill_cents('2.5') === 250, 'One decimal place interpreted correctly');
assert_bill(bg_bill_cents('0.01') === 1, 'One-centavo bill accepted');
assert_bill(bg_bill_cents('0') === null, 'Zero bill/payment rejected');
assert_bill(bg_bill_cents('0.00', true) === 0, 'Zero previously-paid amount allowed for summaries');
assert_bill(bg_bill_cents('-2.00') === null, 'Negative money rejected');
assert_bill(bg_bill_cents('4e6') === null, 'Scientific notation rejected');
assert_bill(bg_bill_cents('1.999') === null, 'More than two decimal places rejected');
assert_bill(bg_bill_cents('10000000000.00') === null, 'Money above DECIMAL 12,2 max rejected');
assert_bill(bg_bill_decimal(125045) === '1250.45', 'Centavos converted to DB decimal without float arithmetic');
assert_bill(bg_bill_peso(125045) === '₱1,250.45', 'Centavos shown as Philippine peso currency');
assert_bill(bg_bill_outstanding(250000, 100000) === 150000, 'Partial payment reduces remaining balance');
assert_bill(bg_bill_outstanding(250000, 250000) === 0, 'Fully paid balance is zero');
assert_bill(bg_bill_status(250000, 0) === 'unpaid', 'No recorded payments means unpaid');
assert_bill(bg_bill_status(250000, 100000) === 'partial', 'Partial payment yields partial status');
assert_bill(bg_bill_status(250000, 250000) === 'paid', 'Full payment yields paid status');

$today = '2026-11-08';
assert_bill(bg_bill_due_label('2026-11-07', 100, $today) === 'Overdue', 'Unpaid past-due bill overdue');
assert_bill(bg_bill_due_label('2026-11-08', 100, $today) === 'Due Today', 'Unpaid bill due today');
assert_bill(bg_bill_due_label('2026-11-15', 100, $today) === 'Due Soon', 'Unpaid bill within seven days');
assert_bill(bg_bill_due_label('2026-11-16', 100, $today) === 'Scheduled', 'Later unpaid bill scheduled');
assert_bill(bg_bill_due_label('2026-11-07', 0, $today) === 'Paid', 'Fully paid bill never triggers overdue reminder');

$valid = [
    'utility_type' => 'Other',
    'custom_utility_name' => 'LPG / Gas',
    'description' => 'Kitchen gas cylinder refill',
    'billing_month' => '2026-11',
    'due_date' => '2026-11-22',
    'amount' => '1450.50',
];
[$errors, $clean] = bg_bill_validate($valid);
assert_bill($errors === [], 'Other utility with custom name is accepted');
assert_bill($clean['billing_month'] === '2026-11-01', 'Billing month normalized to first day');
assert_bill($clean['amount'] === '1450.50', 'Amount normalized without losing centavos');
$badOther = $valid;
$badOther['custom_utility_name'] = '';
assert_bill(isset(bg_bill_validate($badOther)[0]['custom_utility_name']), 'Other requires custom utility name');
$badOther['utility_type'] = 'Electricity';
assert_bill(bg_bill_validate($badOther)[0] === [], 'Built-in utility does not require custom name');
$badOther['utility_type'] = 'OtherExpense';
assert_bill(isset(bg_bill_validate($badOther)[0]['utility_type']), 'Unsupported utility type is rejected');
$badOther = $valid;
$badOther['due_date'] = '2026-10-01';
assert_bill(isset(bg_bill_validate($badOther)[0]['due_date']), 'Due date before billing month rejected');
$badOther = $valid;
$badOther['amount'] = '200.123';
assert_bill(isset(bg_bill_validate($badOther)[0]['amount']), 'Invalid money amount rejected in bill form');

[$paymentErrors] = bg_payment_validate([
    'amount' => '500.00', 'paid_on' => bg_now()->format('Y-m-d'), 'note' => 'Paid via provider',
]);
assert_bill($paymentErrors === [], 'Valid payment record fields accepted');
$tomorrow = bg_now()->modify('+1 day')->format('Y-m-d');
[$paymentErrors] = bg_payment_validate(['amount' => '100', 'paid_on' => $tomorrow]);
assert_bill(isset($paymentErrors['paid_on']), 'Future payment date rejected');

assert_bill(bg_name_error("O'CONNOR", 'Last Name', 27) === null, 'Profile keeps allowed special characters and capitals');
assert_bill(bg_home_address_error('Block #2-A, Davao') === null, 'Profile address supports punctuation');

echo PHP_EOL . ($passed + $failed) . " checks; {$failed} failed." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
