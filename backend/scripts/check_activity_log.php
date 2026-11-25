<?php
declare(strict_types=1);
/**
 * Protection #9 local audit checklist.
 * CLI only: shows event counts, NEVER personal identifiers or log contents.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/security.php';

echo "BillGuard / Protection 9 — Activity Log Setup\n";
try {
    $db = billguard_db();
    $required = [
        'id', 'event_type', 'username_typed', 'user_id',
        'ip_address', 'user_agent', 'details', 'created_at',
    ];
    $q = $db->query("SELECT column_name FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'activity_log'");
    $available = $q->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($required, $available)) {
        echo "Activity log schema: MISSING REQUIRED COLUMNS\n";
        exit(1);
    }
    echo "Activity log schema: OK (append-only application INSERTs)\n";
    $q = $db->query('SELECT event_type, COUNT(*) AS events FROM activity_log
        GROUP BY event_type ORDER BY event_type ASC');
    echo "Event type counts (no usernames, IPs, secrets, or account data):\n";
    foreach ($q->fetchAll() as $row) {
        echo '  ' . (string) $row['event_type'] . ': ' . (int) $row['events'] . PHP_EOL;
    }
    echo "Activity logging setup: READY\n";
} catch (Throwable $e) {
    // Never reveal database credentials, DSNs, raw SQL errors or account data.
    fwrite(STDERR, "Activity logging setup: NOT READY. Check private DB config and schema.\n");
    exit(1);
}
