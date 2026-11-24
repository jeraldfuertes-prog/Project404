<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/recovery_reset.php';

$checks = 0;
$errors = 0;
function reset_rule(bool $pass, string $name): void
{
    global $checks, $errors;
    $checks++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) $errors++;
}

$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
if ($seed === null) exit(1);
$flow = [
    'stage' => 'identity_verified',
    'started_at' => 1000,
    'identity_verified_at' => 1100,
    'candidate_id' => '1234-5678',
    'code_sent' => true,
    'reset_otp_id' => 23,
    'password_fingerprint' => hash('sha256', 'synthetic-hash'),
];
reset_rule(bg_reset_flow_ready($flow, 1101), 'Two proofs with bound OTP and password version permit reset');
reset_rule(bg_reset_flow_ready($flow, 1600), 'Recovery form valid at ten-minute boundary');
reset_rule(!bg_reset_flow_ready($flow, 1601), 'Recovery form expires after ten minutes');
reset_rule(!bg_reset_flow_ready(['stage' => 'identity_submitted'] + $flow, 1101),
    'Incorrect email/question proof cannot reset password');
reset_rule(!bg_reset_flow_ready(['candidate_id' => null] + $flow, 1101),
    'Unknown email has no password-reset permission');
reset_rule(!bg_reset_flow_ready(['code_sent' => false] + $flow, 1101),
    'Unsent email code cannot grant reset');
reset_rule(!bg_reset_flow_ready(['reset_otp_id' => null] + $flow, 1101),
    'OTP record must be bound to verified recovery session');
reset_rule(!bg_reset_flow_ready(['reset_otp_id' => -1] + $flow, 1101),
    'Invalid OTP ID rejected');
reset_rule(!bg_reset_flow_ready(['password_fingerprint' => 'invalid'] + $flow, 1101),
    'Password version must have valid format');
reset_rule(!bg_reset_flow_ready(['identity_verified_at' => 999] + $flow, 1101),
    'Proof before entry denied');
reset_rule(!bg_reset_flow_ready(['identity_verified_at' => 1500] + $flow, 1101),
    'Future-dated proof denied');

$min = $seed['password_min'];
reset_rule($min === 11, 'Reset uses approved seeded password minimum, not hardcoded 12');
reset_rule(bg_reset_password_error('LongAndStrong1!', $seed) === null,
    'Strong password accepted under registration rules');
reset_rule(bg_reset_password_error('abC2!short', $seed) !== null,
    'Too-short password rejected');
reset_rule(bg_reset_password_error(str_repeat('A', 65), $seed) !== null,
    'Password over 64 bytes rejected');
reset_rule(bg_reset_password_error('alllowercaseletters', $seed) !== null,
    'Single-character-class password rejected');
reset_rule(bg_reset_password_error("Strongpass9!\n", $seed) !== null,
    'Password with control characters rejected');
reset_rule(bg_reset_password_error('Upperlower123', $seed) === null,
    'Registration permits three classes without requiring symbol');
reset_rule(bg_reset_password_error('Password1!', ['password_min' => 0]) !== null,
    'Missing seeded policy fails closed');

$current = password_hash('CurrentPassword9!', PASSWORD_DEFAULT);
$old1 = password_hash('PreviousPassword8!', PASSWORD_DEFAULT);
$old2 = password_hash('PreviousPassword7!', PASSWORD_DEFAULT);
$old3 = password_hash('PreviousPassword6!', PASSWORD_DEFAULT);
$old4 = password_hash('PreviousPassword5!', PASSWORD_DEFAULT);
reset_rule(bg_reset_password_reused('CurrentPassword9!', $current, [$old1, $old2, $old3]),
    'Current password reuse rejected');
reset_rule(bg_reset_password_reused('PreviousPassword8!', $current, [$old1, $old2, $old3]),
    'Most recent old password reuse rejected');
reset_rule(bg_reset_password_reused('PreviousPassword6!', $current, [$old1, $old2, $old3]),
    'Third previous password reuse rejected');
reset_rule(!bg_reset_password_reused('PreviousPassword5!', $current, [$old1, $old2, $old3, $old4]),
    'Only last three earlier hashes considered');
reset_rule(!bg_reset_password_reused('BrandNewPassword4!', $current, [$old1, $old2, $old3]),
    'Brand-new password accepted');
reset_rule(bg_auth_password_fingerprint($current) !== bg_auth_password_fingerprint($old1),
    'Change to stored password hash revokes prior fingerprint');
reset_rule(hash_equals(bg_auth_password_fingerprint($current), bg_auth_password_fingerprint($current)),
    'Unchanged stored password hash preserves valid session');
echo PHP_EOL . "{$checks} checks; {$errors} failed." . PHP_EOL;
exit($errors ? 1 : 0);
