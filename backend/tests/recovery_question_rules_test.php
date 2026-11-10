<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/recovery_questions.php';

$pass = 0;
$fail = 0;
function question_check(bool $ok, string $label): void
{
    global $pass, $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if ($ok) $pass++; else $fail++;
}
$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
if ($seed === null) exit(1);
$questions = bg_recovery_question_choices($seed);
question_check(count($questions) === 3, 'Exactly three seeded questions shown');
question_check(array_keys($questions) === $seed['question_ids'],
    'Question choices match original enrollment IDs and order');
question_check(!isset($questions[1]), 'Non-enrolled question excluded');
question_check(bg_recovery_question_choices(['question_ids' => [1, 1, 2]]) === [],
    'Malformed or duplicate seeded questions fail closed');
question_check(bg_recovery_answers_match('Blue Sky', 'blue sky'),
    'Answers are case-insensitive');
question_check(bg_recovery_answers_match('Silver River', 'silver river'),
    'Registered multiword answer matches case-insensitively');
question_check(!bg_recovery_answers_match('Blue Sky', 'Blue Sun'),
    'Different confirmation rejected');
question_check(!bg_recovery_answers_match('', ''),
    'Blank answer rejected');
question_check(!bg_recovery_answers_match('two  spaces', 'two  spaces'),
    'Double spaces rejected');
question_check(!bg_recovery_answers_match('1234', '1234'),
    'Numeric-only answer rejected');
question_check(!bg_recovery_answers_match(str_repeat('x', 51), str_repeat('x', 51)),
    'Overlong answer rejected');
question_check(str_contains(BG_RECOVERY_QUESTION_NOTICE, 'No reset email was sent'),
    'Question page does not claim reset OTP delivered');
question_check(str_contains(BG_RECOVERY_QUESTION_NOTICE, 'no password was changed'),
    'Question page never claims password changed');

$flow = ['stage' => 'question_pending', 'candidate_id' => '4444-5555',
    'started_at' => 1000];
question_check(!bg_recovery_question_result_ready($flow, 1100),
    'Unsubmitted question cannot show completion page');
$flow['stage'] = 'question_verified';
question_check(bg_recovery_question_result_ready($flow, 1100),
    'Verified result can show non-identifying completion page');
question_check(!bg_recovery_question_result_ready($flow, 1601),
    'Verification result expires after ten minutes');
$flow['stage'] = 'question_submitted';
question_check(bg_recovery_question_result_ready($flow, 1100),
    'Failed result shows same completion page');
$flow['candidate_id'] = null;
question_check(bg_recovery_question_result_ready($flow, 1100),
    'Unknown username gets indistinguishable completion state');
$flow['stage'] = 'reset_allowed';
question_check(!bg_recovery_question_result_ready($flow, 1100),
    'Question step cannot elevate to password reset permission');

echo PHP_EOL . ($pass + $fail) . " tests; {$fail} failed." . PHP_EOL;
exit($fail > 0 ? 1 : 0);
