<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/monitor_rules.php';
require_once dirname(__DIR__) . '/php/admin_monitor_data.php';
date_default_timezone_set('Asia/Manila');

$checks = 0; $failed = 0;
function monitor_rule(bool $pass, string $label): void
{
    global $checks, $failed;
    $checks++;
    echo ($pass ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$pass) $failed++;
}
$points = [];
for ($i = 0; $i < 15; $i++) {
    $points[] = ['target' => '198.51.100.10', 'time' => 100000 + $i * 20];
}
monitor_rule((bg_monitor_bursts($points, 15)['198.51.100.10'] ?? 0) === 15,
    'Seeded 15-failure burst in ten minutes is detected');
monitor_rule(bg_monitor_bursts($points, 16) === [],
    'Just below threshold produces no flag');
$wide = [];
for ($i = 0; $i < 15; $i++) $wide[] = ['target' => '198.51.100.10', 'time' => $i * 600];
monitor_rule(bg_monitor_bursts($wide, 2) === [],
    'A failure at exactly ten minutes is outside the rolling interval');

$now = new DateTimeImmutable('2026-11-25 15:00:00', new DateTimeZone('Asia/Manila'));
$event = static function (string $type, string $when, ?string $name, ?string $uid, string $ip): array {
    return ['event_type' => $type, 'created_at' => $when, 'username_typed' => $name,
        'user_id' => $uid, 'ip_address' => $ip];
};
$log = [];
for ($i = 0; $i < 15; $i++) {
    $log[] = $event('LOGIN_FAILED', $now->modify('-15 minutes')->modify('+' . ($i * 10) . ' seconds')
        ->format('Y-m-d H:i:s'), 'SyntheticUser', null, '198.51.100.10');
}
$log[] = $event('LOGIN_FAILED', '2026-11-25 14:00:00', 'SyntheticUser', null, '198.51.100.11');
$log[] = $event('LOGIN_FAILED', '2026-11-25 13:00:00', 'SyntheticUser', null, '198.51.100.12');
for ($i = 0; $i < 5; $i++) {
    $log[] = $event('OTP_FAILED', '2026-11-25 13:30:' . sprintf('%02d', $i * 6),
        null, '6767-6777', '198.51.100.21');
}
$log[] = $event('LOGIN_SUCCESS', '2026-11-25 02:30:00',
    null, '6767-6777', '198.51.100.21');
$log[] = $event('LOGIN_SUCCESS', '2026-11-25 11:30:00',
    null, '6767-6888', '198.51.100.21');
$log[] = $event('LOGIN_FAILED', '2026-11-23 02:30:00',
    'StaleUsername', null, '198.51.100.99');
$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
$flags = bg_monitor_detect($log, $seed, $now);
$byPattern = [];
foreach ($flags as $flag) $byPattern[$flag['pattern']] = $flag;
monitor_rule(count($flags) === 4, 'Four required threat patterns detected once per target');
monitor_rule(($byPattern['IP_FAILURE_BURST']['target'] ?? '') === '198.51.100.10',
    'Rolling IP burst identifies correct source');
monitor_rule(($byPattern['USERNAME_MULTI_IP']['target'] ?? '') === 'syntheticuser',
    'Username targeted by three distinct IP addresses detected');
monitor_rule(($byPattern['OTP_FAILURE_BURST']['target'] ?? '') === '6767-6777',
    'Five OTP failures against one user in ten minutes detected');
monitor_rule(($byPattern['NIGHT_LOGIN']['target'] ?? '') === '6767-6777',
    'Successful 2:30 AM login detected in Philippine time');
monitor_rule(!str_contains(json_encode($flags), 'StaleUsername'),
    'Events older than 24 hours ignored');

$filters = bg_monitor_filters(['date' => '2026-11-25',
    'event' => 'login_failed', 'username' => '<script>', 'ip' => '198.51']);
monitor_rule($filters['event'] === 'LOGIN_FAILED' && $filters['username'] === '<script>',
    'Safe filters normalize event names and leave typed search text as data');
foreach ([
    ['date' => '2026-02-30'],
    ['date' => 'yesterday'],
    ['event' => 'LOGIN_FAILED;DROP'],
    ['username' => str_repeat('u', 81)],
    ['ip' => "bad\naddress"],
] as $bad) {
    $threw = false;
    try { bg_monitor_filters($bad); } catch (InvalidArgumentException $e) { $threw = true; }
    monitor_rule($threw, 'Invalid dashboard filter rejected');
}
echo "{$checks} monitoring rules; {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
