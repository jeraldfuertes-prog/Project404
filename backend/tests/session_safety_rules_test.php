<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/auth.php';

$checks = 0;
$failed = 0;
function session_rule(bool $pass, string $description): void
{
    global $checks, $failed;
    $checks++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$pass) $failed++;
}

$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
if ($seed === null) exit(1);
session_rule(bg_idle_timeout_seconds($seed) === 540,
    'Seed d1=4 gives 9-minute inactivity timeout');
session_rule(bg_idle_timeout_seconds(['d1' => 0]) === 300,
    'Smallest seeded inactivity timeout is 5 minutes');
session_rule(bg_idle_timeout_seconds(['d1' => 8]) === 480,
    'Seeded timeout correctly wraps d1 modulo five');
session_rule(bg_session_warning_lead_for_idle_seconds(30) === 15,
    '30-second total timeout warns after 15 seconds instead of immediately');
session_rule(bg_session_warning_lead_for_idle_seconds(90) === 60,
    '90-second total timeout warns after 30 seconds of inactivity');
session_rule(bg_session_warning_lead_for_idle_seconds(300) === 60,
    'Normal seeded timeout retains one-minute warning');
session_rule(bg_session_warning_lead_for_idle_seconds(10) === 0,
    'Too-short sessions do not show an immediate warning');
session_rule(bg_session_warning_lead_seconds($seed) === 60,
    'Current seeded session displays a one-minute warning');
session_rule(bg_session_remaining_seconds(1000, $seed, 1000) === 540,
    'New authenticated activity gives full idle window');
session_rule(bg_session_remaining_seconds(1000, $seed, 1479) === 61,
    'Warning must remain hidden when more than 60 seconds remain');
session_rule(bg_session_remaining_seconds(1000, $seed, 1480) === 60,
    'One-minute warning starts at 60 seconds remaining');
session_rule(bg_session_remaining_seconds(1000, $seed, 1539) === 1,
    'Still authenticated until one second before deadline');
session_rule(bg_session_remaining_seconds(1000, $seed, 1540) === 0,
    'Server rejects session exactly at expiry');
session_rule(bg_session_remaining_seconds(0, $seed, 1000) === 0,
    'Missing login activity denies session');
session_rule(bg_session_remaining_seconds(1001, $seed, 1000) === 0,
    'Future-dated activity timestamp fails closed');
session_rule(bg_session_remaining_seconds(1000, $seed, 1550) === 0,
    'Session cannot be renewed after expiry');

$_SESSION = [];
$token = bg_csrf_token();
session_rule(strlen($token) === 64, 'CSRF token uses 32 random bytes');
session_rule(bg_check_csrf($token), 'Matching CSRF token accepted');
session_rule(!bg_check_csrf(null), 'Absent CSRF token rejected');
session_rule(!bg_check_csrf('incorrect'), 'Incorrect CSRF token rejected');
session_rule(hash_equals(bg_csrf_token(), $token),
    'Repeated token lookup does not rotate legitimate form tokens');

echo PHP_EOL . "{$checks} checks; {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
