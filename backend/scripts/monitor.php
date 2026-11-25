<?php
declare(strict_types=1);
/** Phase 6 security monitor. CLI only; never install inside the frontend web root. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}
require_once dirname(__DIR__) . '/php/monitor_rules.php';

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed not configured.');
    $db = billguard_db();
    $result = bg_monitor_run($db, $seed);

    echo "BILLGUARD // SECURITY MONITOR\n";
    echo "As of: " . $result['as_of'] . PHP_EOL;
    echo "Security events checked (24h): " . $result['scanned'] . PHP_EOL;
    echo "Threat patterns detected: " . $result['detected'] . PHP_EOL;
    echo "New flags: " . $result['inserted'] . PHP_EOL;
    echo "Already flagged today: " . $result['existing'] . PHP_EOL;
    foreach ($result['by_pattern'] as $pattern => $count) {
        echo '  ' . $pattern . ': ' . $count . PHP_EOL;
    }
    echo "DONE — no usernames, passwords, codes or IPs printed.\n";
} catch (Throwable $e) {
    // Private application error log; never print connection strings/secrets.
    error_log('BillGuard Phase 6 CLI monitor failed.');
    fwrite(STDERR, "Security monitor unavailable. Check database setup and private logs.\n");
    exit(1);
}
