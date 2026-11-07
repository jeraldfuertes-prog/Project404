<?php
declare(strict_types=1);

/**
 * Internal PDO connection factory for BillGuard.
 * Never include this from the browser as a directly accessible endpoint.
 */

function billguard_config(): array
{
    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException(
            'Local configuration is missing. Copy backend/config.sample.php to backend/config.php.'
        );
    }

    $settings = require $path;
    if (!is_array($settings)) {
        throw new RuntimeException('The local configuration must return an array.');
    }

    return $settings;
}

function billguard_db(): PDO
{
    $config = billguard_config();

    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $required) {
        if (!array_key_exists($required, $config) || !is_string($config[$required])) {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }
    if (str_contains($config['db_user'], 'REPLACE_')
        || str_contains($config['db_password'], 'REPLACE_')) {
        throw new RuntimeException('Replace the sample database credentials in backend/config.php.');
    }

    $dsn = 'mysql:host=' . $config['db_host']
        . ';dbname=' . $config['db_name']
        . ';charset=utf8mb4';

    // Keep all application queries prepared, with no emulated prepare mode.
    $pdo = new PDO(
        $dsn,
        $config['db_user'],
        $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Set timestamps consistently to Philippine time for class security logs.
    $pdo->exec("SET time_zone = '+08:00'");

    return $pdo;
}
