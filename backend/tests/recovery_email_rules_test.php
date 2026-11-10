<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/recovery_email.php';
require_once dirname(__DIR__) . '/php/recovery_code.php';

$passed = 0;
$failed = 0;
function check_recovery_email(bool $ok, string $label): void
{
    global $passed, $failed;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if ($ok) $passed++; else $failed++;
}
check_recovery_email(bg_recovery_email_error('person@example.test') === null,
    'Valid registered-format email accepted');
check_recovery_email(bg_recovery_email_error('') !== null,
    'Blank email rejected');
check_recovery_email(bg_recovery_email_error('not-an-email') !== null,
    'Malformed email rejected');
check_recovery_email(bg_recovery_email_error('a @example.test') !== null,
    'Spaces inside email rejected');
check_recovery_email(bg_recovery_email_error(str_repeat('x', 91) . '@a.test') !== null,
    'Email over 100 bytes rejected');
check_recovery_email(bg_recovery_normalize_email('  Person@Example.TEST ') === 'person@example.test',
    'Recovery email uses registration normalization');
check_recovery_email(str_contains(BG_RECOVERY_EMAIL_NOTICE, 'If this email'),
    'Entry confirmation does not reveal whether account exists');
check_recovery_email(!str_contains(BG_RECOVERY_EMAIL_NOTICE, 'A code was sent'),
    'Entry does not guarantee email delivery');
check_recovery_email(str_contains(BG_RECOVERY_QUESTION_NOTICE, 'No password has been changed'),
    'Combined confirmation does not imply password modification');
$flow = [
    'stage' => 'identity_verified',
    'started_at' => 1000,
    'candidate_id' => '1111-2222',
    'code_sent' => true,
    'identity_verified_at' => 1200,
];
check_recovery_email(bg_recovery_question_result_ready($flow, 1201),
    'Both proofs verified is a time-limited result state');
check_recovery_email(!bg_recovery_flow_ready($flow, 1201),
    'Both proofs verified state cannot be used as pending entry');
check_recovery_email(!bg_recovery_question_result_ready($flow, 1601),
    'Identity-verified page expires with original request');
check_recovery_email(bg_recovery_question_choices(
    bg_seed(['seed_d1' => 4, 'seed_d2' => 7])) !== [],
    'Registered questions remain available in email recovery');

echo PHP_EOL . ($passed + $failed) . " checks; {$failed} failed." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
