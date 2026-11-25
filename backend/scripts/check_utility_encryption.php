<?php
declare(strict_types=1);
/**
 * Local read-only Protection 13 preflight.
 * NEVER outputs secret keys, private account numbers or ciphertext.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/utility_account.php';
echo "BillGuard / Protection 13 — AES-256 setup check\n";
$ok = true;
try {
    $config = billguard_config();
    $keyReady = bg_utility_key_ready($config);
    echo 'Private AES-256 key: ' . ($keyReady ? 'OK' : 'MISSING OR INVALID') . PHP_EOL;
    if (!$keyReady) $ok = false;
} catch (Throwable $e) {
    echo "Private AES-256 key: CONFIGURATION NOT AVAILABLE\n";
    $ok = false;
}
try {
    $db = billguard_db();
    echo "MySQL connection: OK\n";
    $schemaReady = bg_utility_schema_ready($db);
    echo 'Encrypted users columns: ' . ($schemaReady ? 'OK' : 'MISSING') . PHP_EOL;
    if (!$schemaReady) $ok = false;
} catch (Throwable $e) {
    echo "MySQL connection / encrypted schema: NOT READY\n";
    $ok = false;
}
echo $ok ? "Protection 13 local setup: READY\n"
    : "Protection 13 local setup: NOT READY. Read docs/PROTECTION13_ENCRYPTION_TESTING.md\n";
exit($ok ? 0 : 1);
