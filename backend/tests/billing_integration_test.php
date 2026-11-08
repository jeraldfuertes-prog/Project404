<?php
declare(strict_types=1);
/**
 * GitHub Actions only: short-lived MySQL 8 service, fictitious test accounts.
 * Never connects to the user's local database, uses no private config.php,
 * and never stores a real password, OTP or utility account number.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/billing.php';

$failed = 0;
$checks = 0;
function verify_db(bool $ok, string $label): void
{
    global $failed, $checks;
    $checks++;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}

try {
    $host = (string) (getenv('BG_TEST_DB_HOST') ?: '127.0.0.1');
    $pass = (string) (getenv('BG_TEST_DB_PASS') ?: '');
    if ($pass === '') throw new RuntimeException('Test-only DB environment not configured.');
    $db = new PDO(
        "mysql:host={$host};port=3306;charset=utf8mb4",
        'root', $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db->exec("SET time_zone = '+08:00'");
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) throw new RuntimeException('Cannot read development schema.');
    // All schema statements are plain CREATE DATABASE/TABLE; none use semicolon
    // inside a stored procedure or text literal.
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $db->exec($statement);
    }
    verify_db(bg_bill_schema_ready($db), 'Fresh-install schema supports Other utilities');
    $db->exec("INSERT INTO users (id_number, first_name, family_name, home_address,
        email, username, password_hash, account_status, email_verified_at, consented_at)
        VALUES
        ('1111-1111','Alice','Test','Demo Address One','alice@example.test','alice_test',
         'fake-hash','active',NOW(),NOW()),
        ('2222-2222','Bob','Test','Demo Address Two','bob@example.test','bob_test',
         'fake-hash','active',NOW(),NOW())");

    $input = [
        'utility_type' => 'Other', 'custom_utility_name' => 'LPG / Gas',
        'description' => 'Cooking fuel refill',
        'billing_month' => bg_now()->format('Y-m'),
        'due_date' => bg_now()->modify('+10 days')->format('Y-m-d'),
        'amount' => '2500.00',
    ];
    $alice = bg_save_bill($db, '1111-1111', $input);
    verify_db($alice['ok'] ?? false, 'Alice can add Other utility bill');
    if (!($alice['ok'] ?? false)) throw new RuntimeException('Cannot continue integration scenario.');
    $aliceId = (int) $alice['id'];
    $input['utility_type'] = 'Water';
    $input['custom_utility_name'] = '';
    $input['description'] = 'Monthly water';
    $bob = bg_save_bill($db, '2222-2222', $input);
    verify_db($bob['ok'] ?? false, 'Bob can add his own Water bill');
    if (!($bob['ok'] ?? false)) throw new RuntimeException('Cannot continue integration scenario.');

    $aliceBills = bg_user_bills($db, '1111-1111');
    $bobBills = bg_user_bills($db, '2222-2222');
    verify_db(count($aliceBills) === 1 && $aliceBills[0]['utility_label'] === 'LPG / Gas',
        'Alice sees only her custom Other utility');
    verify_db(count($bobBills) === 1 && $bobBills[0]['utility_label'] === 'Water',
        'Bob sees only his own water utility bill');
    $foreignEdit = bg_save_bill($db, '2222-2222', $input, $aliceId);
    verify_db(!($foreignEdit['ok'] ?? false), 'Cross-owner bill edit is denied');
    $foreignDelete = bg_delete_bill($db, '2222-2222', $aliceId);
    verify_db(!($foreignDelete['ok'] ?? false), 'Cross-owner bill delete is denied');

    $payment = [
        'amount' => '800.25',
        'paid_on' => bg_now()->format('Y-m-d'),
        'note' => 'Paid using provider app',
    ];
    $foreignPay = bg_record_payment($db, '2222-2222', $aliceId, $payment);
    verify_db(!($foreignPay['ok'] ?? false), 'Cross-owner payment is denied');
    $first = bg_record_payment($db, '1111-1111', $aliceId, $payment);
    verify_db($first['ok'] ?? false, 'Partial payment recorded for correct owner');
    $aliceBills = bg_user_bills($db, '1111-1111');
    verify_db($aliceBills[0]['balance_cents'] === 169975, 'Remaining balance is exactly ₱1,699.75');
    verify_db($aliceBills[0]['computed_status'] === 'partial', 'Partial status shown from payment records');

    $payment['amount'] = '1700.00';
    $excess = bg_record_payment($db, '1111-1111', $aliceId, $payment);
    verify_db(!($excess['ok'] ?? false), 'Overpayment rejected');
    $payment['amount'] = '1699.75';
    $second = bg_record_payment($db, '1111-1111', $aliceId, $payment);
    verify_db($second['ok'] ?? false, 'Exact final payment accepted');
    $aliceBills = bg_user_bills($db, '1111-1111');
    verify_db($aliceBills[0]['balance_cents'] === 0 && $aliceBills[0]['computed_status'] === 'paid',
        'Full payment clears bill balance');
    $storedStatus = $db->query("SELECT status FROM utility_bills WHERE id = {$aliceId}")->fetchColumn();
    verify_db($storedStatus === 'paid', 'Database payment status updated atomically');

    $editWithPayment = bg_save_bill($db, '1111-1111', $input, $aliceId);
    verify_db(!($editWithPayment['ok'] ?? false), 'Paid bill cannot be edited');
    $deleteWithPayment = bg_delete_bill($db, '1111-1111', $aliceId);
    verify_db(!($deleteWithPayment['ok'] ?? false), 'Paid bill cannot be deleted');
    verify_db(count(bg_user_payments($db, '1111-1111')) === 2,
        'Owner sees their two payment records');
    verify_db(count(bg_user_payments($db, '2222-2222')) === 0,
        'Other user cannot view payment records');

    echo PHP_EOL . "{$checks} checks; {$failed} failed.\n";
} catch (Throwable $error) {
    // Safe CI diagnostics: test-only schema/user names, no private passwords.
    fwrite(STDERR, "Billing integration test encountered an error: "
        . get_class($error) . ' [code ' . (string) $error->getCode() . "]\n");
    $failed++;
}
exit($failed ? 1 : 0);
