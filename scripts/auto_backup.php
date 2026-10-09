<?php

declare(strict_types=1);

/**
 * Backup otomatis harian.
 *
 * Cara pakai (crontab server):
 *   0 2 * * * /usr/bin/php /path/to/agenda-php/scripts/auto_backup.php
 *
 * Atau via docker compose:
 *   docker compose exec app php scripts/auto_backup.php
 *
 * Output: storage/backups/backup_YYYY-MM-DD_HHMMSS.sql
 * Retention: simpan 30 hari terakhir, hapus sisanya.
 */

require_once __DIR__ . '/../app/helpers.php';

$backupDir = __DIR__ . '/../storage/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0750, true);
}

$filename = 'backup_' . date('Y-m-d_His') . '.sql';
$filepath = $backupDir . '/' . $filename;

try {
    $pdo = getPdo();

    $sql = "-- Auto Backup Agenda Pimpinan\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $row[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $r) {
                $keys = array_keys($r);
                $values = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string) $v);
                }, array_values($r));
                $sql .= "INSERT INTO `{$table}` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    file_put_contents($filepath, $sql);
    // Compress dengan gzip untuk hemat storage.
    $gzPath = $filepath . '.gz';
    $gz = gzopen($gzPath, 'wb9');
    gzwrite($gz, $sql);
    gzclose($gz);
    unlink($filepath); // hapus file .sql, simpan .sql.gz saja

    // Cleanup: hapus backup > 30 hari.
    $retentionDays = (int) env('BACKUP_RETENTION_DAYS', 30);
    foreach (glob($backupDir . '/backup_*.sql.gz') as $oldFile) {
        if (filemtime($oldFile) < time() - $retentionDays * 86400) {
            @unlink($oldFile);
        }
    }

    auditLogImmutable('auto_backup', "Backup otomatis berhasil: {$filename}.gz");
    echo "[OK] Backup created: {$filename}.gz\n";
    exit(0);
} catch (Throwable $e) {
    error_log('[auto_backup] Gagal: ' . $e->getMessage());
    echo "[FAIL] Backup failed: " . $e->getMessage() . "\n";
    exit(1);
}
