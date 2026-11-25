<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/security.php';

$checks = 0;
$failed = 0;
function assert_audit_rule(bool $passed, string $name): void
{
    global $checks, $failed;
    $checks++;
    echo ($passed ? '[OK]   ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}

assert_audit_rule(bg_activity_details_safe([]), 'Empty audit metadata is allowed');
assert_audit_rule(bg_activity_details_safe([
    'purpose' => 'reset', 'attempt' => 3, 'rate_limited' => false,
    'page' => 'profile', 'reason' => 'idle_timeout',
]), 'Non-secret metadata supports typical authentication events');

foreach ([
    ['password' => 'test-password'],
    ['otp' => '123456'],
    ['reset_token' => 'secret-token'],
    ['security_answer' => 'secret-answer'],
    ['utility_account' => '12345678'],
    ['encryption_key' => 'fake-key'],
    ['cookie' => 'test-cookie'],
    ['ciphertext' => 'fake-bytes'],
] as $sensitive) {
    assert_audit_rule(!bg_activity_details_safe($sensitive),
        'Known credential-bearing audit field is rejected');
}
assert_audit_rule(!bg_activity_details_safe(['extra' => ['nested' => 'metadata']]),
    'Nested values are not allowed in the activity log');
assert_audit_rule(!bg_activity_details_safe(['reason' => str_repeat('a', 129)]),
    'Oversized string metadata is rejected');
assert_audit_rule(!bg_activity_details_safe(['reason' => "line\nbreak"]),
    'Control characters in metadata are rejected');
assert_audit_rule(!bg_activity_details_safe([0 => 'invalid']),
    'Numeric metadata keys are rejected');

echo PHP_EOL . "{$checks} audit safety checks; {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
