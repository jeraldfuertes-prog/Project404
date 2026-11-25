<?php
declare(strict_types=1);
/** Disposable CI-only MySQL tests. NEVER run on a real account database. */
if (PHP_SAPI !== 'cli' || getenv('BG_MONITOR_CI') !== 'yes') {
    fwrite(STDERR, "Restricted to disposable CI MySQL.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/monitor_rules.php';
require_once dirname(__DIR__) . '/php/admin_monitor_data.php';
date_default_timezone_set('Asia/Manila');
$checks = 0; $fails = 0;
function verify_monitor(bool $pass, string $label): void
{
    global $checks, $fails;
    $checks++;
    echo ($pass ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$pass) $fails++;
}
try {
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root', (string) getenv('BG_TEST_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $db->exec("SET time_zone = '+08:00'");
    $now = bg_now();
    $day = $now->format('Y-m-d');
    $uid = '6060-6060';
    $db->prepare("INSERT INTO users
        (id_number, first_name, family_name, home_address, email, username,
         password_hash, role, account_status, email_verified_at, consented_at)
        VALUES (:id, 'Phase', 'Six', 'CI synthetic address', 'monitor@example.test',
            'MonitorCI60', :hash, 'user', 'active', NOW(), NOW())")
       ->execute([':id' => $uid, ':hash' => password_hash('FakeCIOnlyPassword!', PASSWORD_DEFAULT)]);

    $ins = $db->prepare("INSERT INTO activity_log
        (event_type, username_typed, user_id, ip_address, user_agent, created_at)
        VALUES (:event, :name, :uid, :ip, 'CI monitor-only', :stamp)");
    $add = static function (string $type, ?string $name, ?string $id,
        string $ip, DateTimeImmutable $when) use ($ins): void {
        $ins->execute([
            ':event' => $type, ':name' => $name, ':uid' => $id, ':ip' => $ip,
            ':stamp' => $when->format('Y-m-d H:i:s'),
        ]);
    };
    $ip = '198.51.100.177';
    for ($i = 0; $i < 15; $i++) {
        $add('LOGIN_FAILED', 'MonitorCI60', $uid, $ip,
            $now->modify('-12 minutes')->modify('+' . ($i * 10) . ' seconds'));
    }
    $add('LOGIN_FAILED', 'MonitorCI60', $uid, '198.51.100.178', $now->modify('-40 minutes'));
    $add('LOGIN_FAILED', 'MonitorCI60', $uid, '198.51.100.179', $now->modify('-40 minutes'));
    for ($i = 0; $i < 5; $i++) {
        $add('OTP_FAILED', null, $uid, '198.51.100.180',
            $now->modify('-30 minutes')->modify('+' . ($i * 9) . ' seconds'));
    }
    $night = $now->setTime(2, 30);
    if ($night > $now) $night = $night->modify('-1 day');
    $add('LOGIN_SUCCESS', null, $uid, '198.51.100.180', $night);
    $add('LOGIN_FAILED', '<img src=x onerror=alert(1)>', null, '198.51.100.181',
        $now->modify('-1 minute'));

    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    $first = bg_monitor_run($db, $seed, $now);
    verify_monitor($first['scanned'] >= 23, '24-hour scanner reads synthetic authentication events');
    verify_monitor($first['inserted'] >= 4, 'Required threat patterns create at least four initial flags');
    $flagCount = $db->prepare("SELECT COUNT(*) FROM security_flags
        WHERE pattern = :pattern AND target = :target AND flagged_date = :day");
    foreach ([
        ['IP_FAILURE_BURST', $ip], ['USERNAME_MULTI_IP', 'monitorci60'],
        ['OTP_FAILURE_BURST', $uid], ['NIGHT_LOGIN', $uid],
    ] as [$pattern, $target]) {
        $flagCount->execute([':pattern' => $pattern, ':target' => $target, ':day' => $day]);
        verify_monitor((int) $flagCount->fetchColumn() === 1,
            'Expected flag exists once: ' . $pattern);
    }
    $second = bg_monitor_run($db, $seed, $now);
    verify_monitor($second['inserted'] === 0,
        'Repeated scan never duplicates already stored same-day flags');

    $safe = bg_monitor_filters([
        'date' => $day, 'event' => 'LOGIN_FAILED', 'username' => 'MonitorCI60', 'ip' => $ip,
    ]);
    $data = bg_monitor_dashboard_data($db, $safe, $now);
    verify_monitor(count($data['hourly']) === 24,
        'Dashboard returns all 24 hourly bars');
    verify_monitor(array_sum(array_column($data['hourly'], 'failures')) >= 15,
        'Hourly login failure chart is populated from database');
    verify_monitor((int) $data['summary']['failed_logins'] >= 18,
        'KPI totals cover the last 24 hours');
    verify_monitor(count($data['top_ips']) <= 5, 'Top source IP list never exceeds five');
    verify_monitor(count($data['flags']) >= 4 && count($data['flags']) <= 20,
        'Dashboard shows recent monitor flags');
    verify_monitor(count($data['events']) === 15,
        'Date, event, username and IP filters are combined with AND');
    verify_monitor(!array_key_exists('details', $data['events'][0])
        && !array_key_exists('user_id', $data['events'][0]),
        'Activity feed excludes raw log details and internal account identifiers');
    $xss = bg_monitor_dashboard_data($db,
        bg_monitor_filters(['username' => '<img src=x onerror=alert(1)>']), $now);
    verify_monitor(count($xss['events']) === 1
        && $xss['events'][0]['username_typed'] === '<img src=x onerror=alert(1)>',
        'Attack-shaped usernames stay database data for escaped/textContent rendering');

    echo "{$checks} Phase 6 MySQL checks; {$fails} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 6 CI error: ' . get_class($e)
        . ' [code ' . (string) $e->getCode() . ']' . PHP_EOL);
    $fails++;
}
exit($fails ? 1 : 0);
