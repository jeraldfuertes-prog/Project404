<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/php/recovery.php';

$failed = 0;
$total = 0;
function bg_test_recovery(bool $result, string $description): void
{
    global $failed, $total;
    $total++;
    echo ($result ? '[OK]   ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$result) $failed++;
}

$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
if ($seed === null) exit(1);
bg_test_recovery(bg_recovery_username_error('ValidUser9', $seed) === null,
    'Valid existing-style username accepted');
bg_test_recovery(bg_recovery_username_error('short', $seed) !== null,
    'Username shorter than seeded minimum rejected');
bg_test_recovery(bg_recovery_username_error('Starts With Space', $seed) !== null,
    'Username with spaces rejected');
bg_test_recovery(bg_recovery_username_error('9StartsWrong', $seed) !== null,
    'Username must start with a letter');
bg_test_recovery(bg_recovery_username_error('Alpha_One', $seed) !== null,
    'Unsupported username characters rejected');
bg_test_recovery(bg_recovery_username_error(str_repeat('A', $seed['username_max'] + 1), $seed) !== null,
    'Overlong username rejected');
bg_test_recovery(str_contains(BG_RECOVERY_ENTRY_NOTICE, 'No email was sent'),
    'Recovery entry does not claim email OTP or password reset is complete');

$_SESSION = ['captcha_answer' => 'A9B2C3', 'captcha_created' => 1000];
bg_test_recovery(bg_recovery_check_captcha('a9b2c3', 1001),
    'Fresh CAPTCHA accepts case-insensitive correct answer');
bg_test_recovery(!bg_recovery_check_captcha('A9B2C3', 1002),
    'CAPTCHA is single use');
$_SESSION = ['captcha_answer' => 'A9B2C3', 'captcha_created' => 1000];
bg_test_recovery(!bg_recovery_check_captcha('BAD123', 1001),
    'Incorrect CAPTCHA denied');
bg_test_recovery(!isset($_SESSION['captcha_answer']),
    'Incorrect attempt also consumes CAPTCHA');
$_SESSION = ['captcha_answer' => 'A9B2C3', 'captcha_created' => 1000];
bg_test_recovery(!bg_recovery_check_captcha('A9B2C3', 1301),
    'CAPTCHA older than five minutes denied');
$_SESSION = ['captcha_answer' => 'A9B2C3', 'captcha_created' => 1100];
bg_test_recovery(!bg_recovery_check_captcha('A9B2C3', 1000),
    'CAPTCHA from future is denied');

$flow = ['stage' => 'question_pending', 'started_at' => 1000, 'candidate_id' => '1234-5678'];
bg_test_recovery(bg_recovery_flow_ready($flow, 1600),
    'Valid recovery candidate remains pending for ten minutes');
bg_test_recovery(!bg_recovery_flow_ready($flow, 1601),
    'Pending recovery entry expires after ten minutes');
bg_test_recovery(!bg_recovery_flow_ready($flow, 900),
    'Future-dated recovery stage is rejected');
bg_test_recovery(bg_recovery_flow_ready([
    'stage' => 'question_pending', 'started_at' => 1000, 'candidate_id' => null,
], 1100), 'Unknown accounts use same pending session structure');
bg_test_recovery(!bg_recovery_flow_ready([
    'stage' => 'password_allowed', 'started_at' => 1000, 'candidate_id' => '1234-5678',
], 1100), 'Entry cannot grant direct password-change permission');
bg_test_recovery(!bg_recovery_flow_ready([
    'stage' => 'question_pending', 'started_at' => 1000, 'candidate_id' => 'not-an-id',
], 1100), 'Forged candidate IDs rejected');
bg_test_recovery(!bg_recovery_flow_ready([], 1100),
    'Missing recovery stage denied');

echo PHP_EOL . "{$total} checks; {$failed} failed." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
