<?php
declare(strict_types=1);

/**
 * Safe, read-only CLI preflight for Phase 1.
 *
 * php backend/scripts/check_setup.php --php-only
 * php backend/scripts/check_setup.php --db
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$extensions = ['PDO', 'pdo_mysql', 'openssl', 'mbstring'];
fwrite(STDOUT, "BillGuard / Phase 1 environment check\n");
fwrite(STDOUT, "PHP version: " . PHP_VERSION . ($phpOk ? " [OK]\n" : " [PHP 8.1+ required]\n"));
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    fwrite(STDOUT, $ext . ': ' . ($loaded ? 'OK' : 'MISSING') . "\n");
    if (!$loaded) { $phpOk = false; }
}

$mode = $argv[1] ?? '--php-only';
if ($mode === '--php-only') {
    exit($phpOk ? 0 : 1);
}
if ($mode !== '--db') {
    fwrite(STDERR, "Use --php-only or --db\n");
    exit(2);
}
if (!$phpOk) { exit(1); }

require_once dirname(__DIR__) . '/php/db.php';
try {
    $db = billguard_db();
    $db->query('SELECT 1')->fetchColumn();
    $tableCount = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
    fwrite(STDOUT, "MySQL/PDO connection: OK\n");
    fwrite(STDOUT, "Database tables: {$tableCount}\n");
    if ($tableCount < 9) {
        fwrite(STDOUT, "Import backend/database/schema.sql to create all nine tables.\n");
        exit(1);
    }
    fwrite(STDOUT, "Schema: initial expected table count satisfied\n");
} catch (Throwable $exception) {
    // Do not print SQL errors, database credentials, or full DSNs.
    fwrite(STDERR, "Database setup is incomplete. Verify MySQL is running, config.php has real local values, and schema.sql was imported.\n");
    exit(1);
}
