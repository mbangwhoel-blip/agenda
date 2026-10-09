<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/schema_updates.php';
require_once __DIR__ . '/env.php';

// Prioritas 1: File konfigurasi kredensial hosting terpisah (config/db_credentials.php)
// File ini tidak akan tertimpa saat mengupload pembaruan script ke hosting.
$customCreds = __DIR__ . '/db_credentials.php';
if (is_file($customCreds)) {
    require_once $customCreds;
}

// Prioritas 2: Environment variables (.env file)
// Prioritas 3: Konstanta hardcoded dev lokal (fallback)
const DB_HOST_DEFAULT = '127.0.0.1';
const DB_PORT_DEFAULT = '3306';
const DB_NAME_DEFAULT = 'agenda_pimpinan';
const DB_USER_DEFAULT = 'root';
const DB_PASS_DEFAULT = '';

function db_host(): string {
    if (defined('DB_HOST')) return (string) constant('DB_HOST');
    return (string) (env('DB_HOST', DB_HOST_DEFAULT));
}
function db_port(): string {
    if (defined('DB_PORT')) return (string) constant('DB_PORT');
    return (string) (env('DB_PORT', DB_PORT_DEFAULT));
}
function db_name(): string {
    if (defined('DB_NAME')) return (string) constant('DB_NAME');
    return (string) (env('DB_NAME', DB_NAME_DEFAULT));
}
function db_user(): string {
    if (defined('DB_USER')) return (string) constant('DB_USER');
    return (string) (env('DB_USER', DB_USER_DEFAULT));
}
function db_pass(): string {
    if (defined('DB_PASS')) return (string) constant('DB_PASS');
    return (string) (env('DB_PASS', DB_PASS_DEFAULT));
}

function getPdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (Throwable) {
            $pdo = null;
        }
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', db_host(), db_port(), db_name());
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, db_user(), db_pass(), $options);
    try {
        applySchemaUpdates($pdo);
    } catch (Throwable $exception) {
        error_log('Schema update warning: ' . $exception->getMessage());
    }
    return $pdo;
}
