<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

/**
 * Sliding inclusive ten-minute thresholds. Input rows are [target, epoch].
 * Returns one alert per target, counting its largest rolling burst.
 */
function bg_monitor_bursts(array $points, int $threshold, int $windowSeconds = 600): array
{
    if ($threshold < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Invalid monitor threshold.');
    }
    $byTarget = [];
    foreach ($points as $point) {
        $target = $point['target'] ?? null;
        $time = $point['time'] ?? null;
        if (!is_string($target) || $target === '' || !is_int($time)) continue;
        $byTarget[$target][] = $time;
    }

    $alerts = [];
    foreach ($byTarget as $target => $times) {
        sort($times, SORT_NUMERIC);
        $left = 0;
        $maximum = 0;
        foreach ($times as $right => $current) {
            // Exactly 10 minutes apart is outside the same rolling interval.
            while ($left <= $right && $current - $times[$left] >= $windowSeconds) {
                $left++;
            }
            $maximum = max($maximum, $right - $left + 1);
        }
        if ($maximum >= $threshold) $alerts[$target] = $maximum;
    }
    ksort($alerts);
    return $alerts;
}

/** Detect four handout patterns within 24 hours of PHT activity. */
function bg_monitor_detect(array $events, array $seed, DateTimeImmutable $now): array
{
    $ph = new DateTimeZone('Asia/Manila');
    $now = $now->setTimezone($ph);
    $start = $now->modify('-24 hours')->getTimestamp();
    $ipPoints = [];
    $otpPoints = [];
    $userIps = [];
    $nightLogins = [];

    foreach ($events as $event) {
        if (!is_array($event) || !is_string($event['created_at'] ?? null)) continue;
        $time = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', $event['created_at'], $ph
        );
        if ($time === false) continue;
        $epoch = $time->getTimestamp();
        if ($epoch < $start || $epoch > $now->getTimestamp()) continue;
        $type = (string) ($event['event_type'] ?? '');
        $ip = (string) ($event['ip_address'] ?? '');
        if ($type === 'LOGIN_FAILED') {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ipPoints[] = ['target' => $ip, 'time' => $epoch];
            }
            $name = trim((string) ($event['username_typed'] ?? ''));
            if ($name !== '' && strlen($name) <= 80 && filter_var($ip, FILTER_VALIDATE_IP)) {
                $userIps[strtolower($name)][$ip] = true;
            }
        } elseif ($type === 'OTP_FAILED') {
            $uid = (string) ($event['user_id'] ?? '');
            if (preg_match('/^\d{4}-\d{4}$/D', $uid)) {
                $otpPoints[] = ['target' => $uid, 'time' => $epoch];
            }
        } elseif ($type === 'LOGIN_SUCCESS') {
            $hour = (int) $time->format('G');
            $uid = (string) ($event['user_id'] ?? '');
            if ($hour >= 0 && $hour < 5 && preg_match('/^\d{4}-\d{4}$/D', $uid)) {
                $nightLogins[$uid] = true;
            }
        }
    }

    $ipThreshold = 8 + (int) $seed['d2'];
    $flags = [];
    foreach (bg_monitor_bursts($ipPoints, $ipThreshold) as $ip => $count) {
        $flags[] = ['pattern' => 'IP_FAILURE_BURST', 'target' => $ip,
            'severity' => 'HIGH', 'details' => ['failures' => $count,
                'threshold' => $ipThreshold, 'window_minutes' => 10]];
    }
    foreach ($userIps as $name => $ips) {
        if (count($ips) >= 3) {
            $flags[] = ['pattern' => 'USERNAME_MULTI_IP', 'target' => $name,
                'severity' => 'HIGH', 'details' => ['distinct_ips' => count($ips),
                    'window_hours' => 24]];
        }
    }
    foreach (bg_monitor_bursts($otpPoints, 5) as $uid => $count) {
        $flags[] = ['pattern' => 'OTP_FAILURE_BURST', 'target' => $uid,
            'severity' => 'HIGH', 'details' => ['failures' => $count,
                'threshold' => 5, 'window_minutes' => 10]];
    }
    foreach (array_keys($nightLogins) as $uid) {
        $flags[] = ['pattern' => 'NIGHT_LOGIN', 'target' => $uid,
            'severity' => 'MEDIUM', 'details' => ['window' => '00:00-04:59 PHT']];
    }
    return $flags;
}

/**
 * Executed only by the guarded CLI entrypoint.
 * INSERT IGNORE enforces one flag per pattern/target/PHT calendar day.
 */
function bg_monitor_run(PDO $db, array $seed, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? bg_now())->setTimezone(new DateTimeZone('Asia/Manila'));
    $since = $now->modify('-24 hours')->format('Y-m-d H:i:s');
    $q = $db->prepare("SELECT event_type, username_typed, user_id, ip_address, created_at
        FROM activity_log WHERE created_at >= :since
          AND event_type IN ('LOGIN_FAILED','OTP_FAILED','LOGIN_SUCCESS')
        ORDER BY created_at ASC, id ASC");
    $q->execute([':since' => $since]);
    $events = $q->fetchAll();
    $flags = bg_monitor_detect($events, $seed, $now);
    $insert = $db->prepare('INSERT IGNORE INTO security_flags
        (pattern, target, severity, details, flagged_date)
        VALUES (:pattern, :target, :severity, :details, :day)');
    $created = 0;
    $byPattern = [];
    foreach ($flags as $flag) {
        $insert->execute([
            ':pattern' => $flag['pattern'],
            ':target' => $flag['target'],
            ':severity' => $flag['severity'],
            ':details' => json_encode($flag['details'], JSON_THROW_ON_ERROR),
            ':day' => $now->format('Y-m-d'),
        ]);
        $created += $insert->rowCount();
        $byPattern[$flag['pattern']] = ($byPattern[$flag['pattern']] ?? 0) + 1;
    }
    return ['scanned' => count($events), 'detected' => count($flags),
        'inserted' => $created, 'existing' => count($flags) - $created,
        'by_pattern' => $byPattern, 'as_of' => $now->format('Y-m-d H:i:s') . ' PHT'];
}
