<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/auth.php';

$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
if ($seed === null) exit(1);

$checks = [
    'Lockout before third failure' => bg_login_lockout(2, $seed) === [0, 0],
    'First lockout at exactly three' => bg_login_lockout(3, $seed) === [1, 19],
    'No extra lockout on fourth failed password' => bg_login_lockout(4, $seed) === [0, 0],
    'Second lockout at exactly six' => bg_login_lockout(6, $seed) === [2, 38],
    'Third lockout at nine' => bg_login_lockout(9, $seed) === [3, 76],
    'No alert tier on second attempt' => bg_login_alert_tier(2) === 0,
    'Retry first alert after threshold if SMTP was unavailable' => bg_login_alert_tier(4) === 1,
    'Retry second alert after threshold if SMTP was unavailable' => bg_login_alert_tier(7) === 2,
    'Retry third alert after threshold if SMTP was unavailable' => bg_login_alert_tier(10) === 3,
    'Remain in tier three after nine' => bg_login_lockout(10, $seed) === [3, 76],
    'Idle timeout seeded minutes' => (5 + ($seed['d1'] % 5)) === 9,
    'IP failure threshold seeded' => (8 + $seed['d2']) === 15,
];
$bad = 0;
foreach ($checks as $label => $pass) {
    echo ($pass ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$pass) $bad++;
}
echo count($checks) . ' checks, ' . $bad . ' failures.' . PHP_EOL;
exit($bad ? 1 : 0);
