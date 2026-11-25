<?php
declare(strict_types=1);
require_once __DIR__ . '/admin_audit.php';

/** Strictly bounded, read-only dashboard filters; no SQL fragments from HTTP. */
function bg_monitor_filters(array $input): array
{
    // Reject nested GET parameters rather than coercing arrays into strings.
    foreach (['date', 'event', 'username', 'ip'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {
            throw new InvalidArgumentException('Invalid activity filter.');
        }
    }
    $date = trim((string) ($input['date'] ?? ''));
    $event = strtoupper(trim((string) ($input['event'] ?? '')));
    $username = trim((string) ($input['username'] ?? ''));
    $ip = trim((string) ($input['ip'] ?? ''));

    $validDate = $date === '' || (
        preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
        && ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date)) !== false
        && $parsed->format('Y-m-d') === $date
    );
    if (!$validDate || strlen($event) > 64 || strlen($username) > 80 || strlen($ip) > 45
        || ($event !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/D', $event) !== 1)
        || preg_match('/[\x00-\x1F\x7F]/', $username . $ip)) {
        throw new InvalidArgumentException('Invalid activity filter.');
    }
    return ['date' => $date, 'event' => $event, 'username' => $username, 'ip' => $ip];
}

/** Read-only 24-hour overview; the optional filters affect only the latest-events table. */
function bg_monitor_dashboard_data(PDO $db, array $filters, ?DateTimeImmutable $clock = null): array
{
    $now = ($clock ?? bg_now())->setTimezone(new DateTimeZone('Asia/Manila'));
    $since = $now->modify('-24 hours')->format('Y-m-d H:i:s');

    $summary = [
        'failed_logins' => 0, 'successful_logins' => 0, 'otp_failures' => 0,
        'events' => 0, 'flags' => 0,
    ];
    $counts = $db->prepare("SELECT event_type, COUNT(*) AS total FROM activity_log
        WHERE created_at >= :since GROUP BY event_type");
    $counts->execute([':since' => $since]);
    foreach ($counts->fetchAll() as $row) {
        $number = (int) $row['total'];
        $summary['events'] += $number;
        if ($row['event_type'] === 'LOGIN_FAILED') $summary['failed_logins'] = $number;
        if ($row['event_type'] === 'LOGIN_SUCCESS') $summary['successful_logins'] = $number;
        if ($row['event_type'] === 'OTP_FAILED') $summary['otp_failures'] = $number;
    }

    $flagCount = $db->prepare('SELECT COUNT(*) FROM security_flags WHERE created_at >= :since');
    $flagCount->execute([':since' => $since]);
    $summary['flags'] = (int) $flagCount->fetchColumn();

    // 24 consecutive hourly buckets in PHT, including zero-count hours.
    $firstHour = $now->setTime((int) $now->format('H'), 0)->modify('-23 hours');
    $hourlyQuery = $db->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS bucket,
        COUNT(*) AS failures FROM activity_log
        WHERE event_type = 'LOGIN_FAILED' AND created_at >= :first
          AND created_at <= :now
        GROUP BY bucket ORDER BY bucket");
    $hourlyQuery->execute([
        ':first' => $firstHour->format('Y-m-d H:i:s'),
        ':now' => $now->format('Y-m-d H:i:s'),
    ]);
    $bucketCounts = [];
    foreach ($hourlyQuery->fetchAll() as $row) {
        $bucketCounts[$row['bucket']] = (int) $row['failures'];
    }
    $hourly = [];
    for ($i = 0; $i < 24; $i++) {
        $hour = $firstHour->modify('+' . $i . ' hours');
        $key = $hour->format('Y-m-d H:00');
        $hourly[] = [
            'hour' => $hour->format('m/d H:00'),
            'failures' => $bucketCounts[$key] ?? 0,
        ];
    }

    $topQuery = $db->prepare("SELECT ip_address, COUNT(*) AS failures FROM activity_log
        WHERE event_type = 'LOGIN_FAILED' AND created_at >= :since
        GROUP BY ip_address ORDER BY failures DESC, ip_address ASC LIMIT 5");
    $topQuery->execute([':since' => $since]);
    $topIps = array_map(static fn(array $row): array => [
        'ip_address' => (string) $row['ip_address'],
        'failures' => (int) $row['failures'],
    ], $topQuery->fetchAll());

    // Never expose security_flags.details or raw event metadata.
    $flags = $db->query('SELECT pattern, target, severity, created_at
        FROM security_flags ORDER BY id DESC LIMIT 20')->fetchAll();

    $where = [];
    $values = [];
    if ($filters['date'] !== '') {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['date']);
        $where[] = 'created_at >= :start_date AND created_at < :end_date';
        $values[':start_date'] = $day->format('Y-m-d');
        $values[':end_date'] = $day->modify('+1 day')->format('Y-m-d');
    }
    if ($filters['event'] !== '') {
        $where[] = 'event_type = :event';
        $values[':event'] = $filters['event'];
    }
    if ($filters['username'] !== '') {
        $where[] = 'LOCATE(:username, COALESCE(username_typed, \'\')) > 0';
        $values[':username'] = $filters['username'];
    }
    if ($filters['ip'] !== '') {
        $where[] = 'LOCATE(:ip, ip_address) > 0';
        $values[':ip'] = $filters['ip'];
    }
    $sql = 'SELECT event_type, username_typed, ip_address, created_at FROM activity_log'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY id DESC LIMIT 50';
    $latest = $db->prepare($sql);
    $latest->execute($values);

    return [
        'summary' => $summary,
        'hourly' => $hourly,
        'top_ips' => $topIps,
        'flags' => $flags,
        'events' => $latest->fetchAll(),
        'updated_at' => $now->format('Y-m-d H:i:s') . ' PHT',
    ];
}
