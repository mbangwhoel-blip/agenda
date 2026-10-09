<?php

declare(strict_types=1);

/**
 * RESTORE — buat ulang kegiatan untuk surat yang punya tanggal pelaksanaan
 * manual (dari form), tapi kegiatannya terhapus oleh cleanup sebelumnya.
 * JALANKAN SEKALI, LALU HAPUS FILE INI.
 */

require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/ai_agenda.php';

header('Content-Type: text/html; charset=utf-8');
$pdo = getPdo();
requireLogin();

if (!isAdmin() && !isSekretaris()) {
    die('Akses ditolak.');
}

echo '<html><head><meta charset="utf-8"><style>body{font-family:sans-serif;padding:20px;max-width:800px;margin:auto}p{padding:4px 8px;margin:2px 0;border-radius:4px}.ok{background:#efe}.bad{background:#fee}</style></head><body>';
echo '<h2>Restore — Buat Ulang Kegiatan dari Surat dengan Tanggal Manual</h2>';

// Cari surat yang punya tanggal pelaksanaan manual tapi TIDAK punya kegiatan AI
$stmt = $pdo->query(
    "SELECT s.id, s.title, s.agenda_date, s.agenda_date_end
     FROM agendas s
     WHERE s.entry_type = 'surat'
       AND s.agenda_date IS NOT NULL
       AND s.agenda_date != '0000-00-00'
       AND NOT EXISTS (
           SELECT 1 FROM agendas k
           WHERE k.source_agenda_id = s.id AND k.entry_type = 'kegiatan' AND k.ai_generated = 1
       )
     ORDER BY s.id DESC"
);

$restored = 0;
$failed = 0;

foreach ($stmt->fetchAll() as $s) {
    $sid = (int) $s['id'];
    try {
        $result = autoGenerateAgendaFromLetter($pdo, $sid);
        if (($result['created'] ?? false) === true) {
            $restored++;
            echo "<p class='ok'>✅ DIPULIHKAN: #{$sid} — " . htmlspecialchars($s['title']) . " <small>({$s['agenda_date']} s.d. " . ($s['agenda_date_end'] ?: '-') . ")</small></p>";
        } else {
            $failed++;
            echo "<p class='bad'>⚠️ GAGAL: #{$sid} — " . htmlspecialchars($s['title']) . " <small>(hasil: " . json_encode($result) . ")</small></p>";
        }
    } catch (Throwable $e) {
        $failed++;
        echo "<p class='bad'>❌ ERROR: #{$sid} — " . htmlspecialchars($s['title']) . " <small>(" . htmlspecialchars($e->getMessage()) . ")</small></p>";
    }
}

if ($restored === 0 && $failed === 0) {
    echo "<p>Tidak ada surat yang perlu dipulihkan (semua sudah punya kegiatan).</p>";
}

echo "<hr><p><b>Dipulihkan:</b> {$restored} kegiatan</p>";
echo "<p><b>Gagal:</b> {$failed}</p>";
echo "<p>Refresh index.php dan kalender mini untuk melihat hasilnya.</p>";
echo "<p style='color:red;font-weight:bold'>HAPUS FILE INI SETELAH SELESAI.</p></body></html>";