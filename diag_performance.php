<?php

declare(strict_types=1);

/**
 * DIAGNOSTIK PERFORMA — upload ke hosting, buka di browser, lalu HAPUS.
 * Tidak menampilkan password/kredensial apa pun.
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/app/helpers.php';

echo "=== DIAGNOSTIK PERFORMA AGENDA ===\n";
echo "Waktu server : " . date('Y-m-d H:i:s') . "\n";
echo "PHP          : " . PHP_VERSION . "\n";
echo "OPcache      : " . (function_exists('opcache_get_status') && opcache_get_status() ? 'AKTIF' : 'NONAKTIF (!)') . "\n";
echo "\n";

// 1. Koneksi + migrasi (gate)
$t0 = microtime(true);
try {
    $pdo = getPdo();
} catch (Throwable $e) {
    echo "FATAL: koneksi DB gagal - " . $e->getMessage() . "\n";
    exit;
}
$t1 = microtime(true);
printf("[1] getPdo() + gate check : %d ms\n", (int) (($t1 - $t0) * 1000));

// 2. Versi MySQL
$ver = $pdo->query("SELECT VERSION()")->fetchColumn();
echo "[2] MySQL/MariaDB         : {$ver}\n";
echo "    (generated column butuh MySQL >= 5.7 / MariaDB >= 10.2)\n";

// 3. Status gate
$v = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'")->fetchColumn();
echo "[3] schema_version        : " . ($v !== false ? $v : 'BELUM TERSIMPAT (!) — migrasi berulang tiap request') . "\n";

// 4. Kolom/index hasil migrasi
$c1 = (bool) $pdo->query("SHOW COLUMNS FROM agendas LIKE 'effective_date'")->fetch();
$c2 = (bool) $pdo->query("SHOW INDEX FROM agendas WHERE Key_name = 'idx_agendas_entry_disp'")->fetch();
$c3 = (bool) $pdo->query("SHOW INDEX FROM agendas WHERE Key_name = 'idx_agendas_effective_date'")->fetch();
$c4 = (bool) $pdo->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notif_user_created'")->fetch();
echo "[4] effective_date        : " . ($c1 ? 'ADA' : 'TIDAK ADA (fallback COALESCE aktif — OK)') . "\n";
echo "    idx_entry_disp        : " . ($c2 ? 'ADA' : 'TIDAK ADA') . "\n";
echo "    idx_effective_date    : " . ($c3 ? 'ADA' : 'TIDAK ADA') . "\n";
echo "    idx_notif_user_created: " . ($c4 ? 'ADA' : 'TIDAK ADA') . "\n";

// 5. Ukuran tabel
echo "[5] Ukuran tabel:\n";
foreach ($pdo->query("SELECT table_name, table_rows, ROUND(data_length/1024/1024, 2) AS mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND table_name IN ('agendas', 'notifications', 'activity_logs', 'agenda_disposition_items', 'login_attempts', 'rate_limits') ORDER BY data_length DESC") as $r) {
    echo sprintf("    %-28s %8s baris  %8s MB\n", $r['table_name'], (string) $r['table_rows'], (string) $r['mb']);
}

// 6. Query hot-path (dashboard/agenda)
$q = [
    'statistik agenda (index.php)' => "SELECT COUNT(*) FROM agendas",
    'badge disposisi' => "SELECT COUNT(*) FROM agendas WHERE entry_type='surat' AND disposition_status='pending'",
    'chart tahunan' => "SELECT COUNT(*) FROM agendas WHERE entry_type IN ('kegiatan','agenda_zoom') AND agenda_date >= '2026-01-01' AND agenda_date < '2027-01-01'",
    'notifikasi (header)' => "SELECT * FROM notifications ORDER BY created_at DESC LIMIT 10",
    'log aktivitas (COUNT)' => "SELECT COUNT(*) FROM activity_logs",
];
echo "[6] Waktu query hot-path:\n";
foreach ($q as $label => $sql) {
    $t = microtime(true);
    try {
        $pdo->query($sql)->fetchAll();
        printf("    %-30s %d ms\n", $label, (int) ((microtime(true) - $t) * 1000));
    } catch (Throwable $e) {
        echo "    {$label}: ERROR - " . $e->getMessage() . "\n";
    }
}

// 7. Log migrasi terakhir (jejak kegagalan)
echo "[7] 5 baris terakhir error_log PHP (jika ada):\n";
$err = ini_get('error_log');
if ($err && is_file($err)) {
    $lines = array_slice(file($err) ?: [], -5);
    foreach ($lines as $l) { echo "    " . trim($l) . "\n"; }
} else {
    echo "    (tidak ada file error_log di path ini: " . ($err ?: 'tidak diset') . ")\n";
}

echo "\n=== SELESAI — HAPUS FILE INI DARI HOSTING ===\n";
