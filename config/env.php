<?php

declare(strict_types=1);

/**
 * Environment variable loader.
 *
 * Membaca dari getenv() / $_ENV. Fallback ke konstanta hardcoded hanya untuk dev lokal.
 * Di produksi, set via .env file (lihat .env.example) atau environment variable server.
 *
 * Prioritas:
 *   1. getenv() (cli/server env)
 *   2. $_ENV (PHP superglobal)
 *   3. .env file (jika ada, di-load sekali)
 *   4. default dev lokal
 */

// Load .env file sekali (jangan pakai vendor, parser minimal).
if (!defined('ENV_LOADED')) {
    $candidatePaths = array_unique([
        __DIR__ . '/../.env',
        dirname(__DIR__, 2) . '/.env',
        isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/.env' : '',
        isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/agendakepala/.env' : '',
    ]);

    $envFile = null;
    foreach ($candidatePaths as $p) {
        if ($p !== '' && is_file($p) && is_readable($p)) {
            $envFile = $p;
            break;
        }
    }

    if ($envFile !== null) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (!str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                // Trim quotes
                if ((strlen($v) >= 2) && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                    $v = substr($v, 1, -1);
                }
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
    }
    define('ENV_FILE_PATH', $envFile);
    define('ENV_LOADED', true);
}

/**
 * Ambil env var dengan default.
 */
function env(string $key, $default = null)
{
    $val = $_ENV[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') return $default;
    return $val;
}

/**
 * Ambil env var sebagai boolean (true: "1","true","yes","on").
 */
function env_bool(string $key, bool $default = false): bool
{
    $val = strtolower((string) env($key, $default ? '1' : '0'));
    return in_array($val, ['1', 'true', 'yes', 'on'], true);
}
