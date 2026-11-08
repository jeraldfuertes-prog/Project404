<?php
declare(strict_types=1);
/**
 * Local read-only dashboard preflight. No passwords, account data or OTP values.
 * Run: php backend/scripts/check_dashboard.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/billing.php';
date_default_timezone_set('Asia/Manila');

echo "BillGuard Dashboard / Local Setup Check\n";
try {
    $db = billguard_db();
    echo "MySQL connection: OK\n";
    $required = ['users', 'utility_bills', 'utility_payments', 'activity_log'];
    $query = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
    $all = array_column($query->fetchAll(), 'table_name');
    $tablesReady = count(array_diff($required, $all)) === 0;
    echo 'Required dashboard tables: ' . ($tablesReady ? 'OK' : 'NOT READY') . PHP_EOL;
    if (!$tablesReady) exit(1);
    $ready = bg_bill_schema_ready($db);
    echo 'Other utility database migration: ' . ($ready ? 'OK' : 'NOT READY') . PHP_EOL;
    if (!$ready) {
        echo "Back up your database, then import backend/database/migrations/2026-11-08_add_other_utility.sql once in phpMyAdmin.\n";
        exit(1);
    }
    echo "Dashboard database is ready. Test a real bill and payment in Chrome next.\n";
} catch (Throwable $error) {
    echo "Dashboard setup: NOT READY. Verify your private database configuration and migration.\n";
    exit(1);
}
