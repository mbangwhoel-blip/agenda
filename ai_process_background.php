<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/ai_agenda.php';

$pdo = getPdo();
requireLogin();

header('Content-Type: application/json; charset=UTF-8');

if (!isAdmin() && !isSekretaris()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

// Rate limit: 5 trigger per menit per user.
$identity = 'user:' . (currentUser()['id'] ?? 0);
if (!rateLimit('api_ai_process', $identity, 5, 60)) {
    respondRateLimited(60);
}

$limit = 5;
$processed = 0;
$created = 0;
$updated = 0;
$zoom = 0;
$offline = 0;
$failed = 0;
$cleaned = 0;

// Budget waktu: berhenti memproses setelah 20 detik agar tidak menembus
// max_execution_time 30s hosting (fatal error = proses mati tanpa status tersimpan,
// lalu PDF gagal yang sama diproses ulang terus-menerus tiap trigger).
$aiDeadline = microtime(true) + 20.0;

// Penanda percobaan per-surat (ditulis SEBELUM proses agar selamat dari fatal error).
// Surat yang gagal 3x berturut-turut dilewati � dievaluasi ulang manual.
// Folder diberi versi (_v3): penanda lama di kode sebelumnya tidak berlaku lagi,
// sehingga surat yang dulu gagal karena bug (bukan karena PDF) ikut diproses ulang.
$aiAttemptDir = __DIR__ . '/uploads/.ai_attempts_v3';
if (!is_dir($aiAttemptDir)) { @mkdir($aiAttemptDir, 0775, true); }
// Blokir akses web langsung ke folder penanda.
if (!is_file($aiAttemptDir . '/.htaccess')) {
    @file_put_contents($aiAttemptDir . '/.htaccess', "Require all denied\n");
}

$aiAttemptCount = static function (int $letterId) use ($aiAttemptDir): int {
    $f = $aiAttemptDir . '/' . $letterId . '.cnt';
    return is_file($f) ? (int) @file_get_contents($f) : 0;
};
$aiAttemptBump = static function (int $letterId) use ($aiAttemptDir, $aiAttemptCount): void {
    @file_put_contents($aiAttemptDir . '/' . $letterId . '.cnt', (string) ($aiAttemptCount($letterId) + 1));
};
$aiAttemptReset = static function (int $letterId) use ($aiAttemptDir): void {
    @unlink($aiAttemptDir . '/' . $letterId . '.cnt');
};

if (!isset($_SESSION['ai_pending_letter_ids']) || !is_array($_SESSION['ai_pending_letter_ids'])) {
    $_SESSION['ai_pending_letter_ids'] = [];
}

$queue = array_values(array_filter(array_map('intval', $_SESSION['ai_pending_letter_ids']), static fn (int $id): bool => $id > 0));
$queue = array_values(array_unique($queue));

// PENTING: lepas kunci session SEBELUM proses berat dimulai.
// PHP mengunci file session selama request berjalan � tanpa ini, klik menu lain
// dari browser yang sama ikut mengantre sampai proses AI selesai (20+ detik),
// yang terasa sebagai "aplikasi lambat saat pindah halaman".
// Session dibuka lagi singkat di akhir untuk menulis sisa antrian.
session_write_close();

if (!$queue) {
    // Proses SEMUA surat, termasuk yang sudah punya agenda AI-generated.
    // Ini penting agar cleanup (hapus agenda salah) dan fix tanggal bisa jalan.
    // Urut: surat yang belum punya agenda dulu, lalu yang sudah punya agenda.
    $stmt = $pdo->query(
        "SELECT a.id
         FROM agendas a
         WHERE a.entry_type = 'surat'
         ORDER BY
             (CASE WHEN EXISTS (
                 SELECT 1 FROM agendas c
                 WHERE c.source_agenda_id = a.id AND c.entry_type = 'kegiatan' AND c.ai_generated = 1
             ) THEN 1 ELSE 0 END) ASC,
             a.updated_at DESC, a.id DESC
         LIMIT {$limit}"
    );
    $queue = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$toProcess = array_slice($queue, 0, $limit);

foreach ($toProcess as $letterId) {
    if (microtime(true) >= $aiDeadline) { break; } // budget waktu habis � sisanya trigger berikutnya

    // Lewati surat yang sudah gagal 3x (poison letter) agar tidak loop 30-detik selamanya.
    if ($aiAttemptCount((int) $letterId) >= 3) { continue; }

    try {
        $aiAttemptBump((int) $letterId); // catat SEBELUM proses � selamat dari fatal error
        $result = autoGenerateAgendaFromLetter($pdo, (int) $letterId);
        $processed++;
        if (($result['created'] ?? false) === true) {
            $created++;
        }
        if (($result['updated'] ?? false) === true) {
            $updated++;
        }
        if (($result['created'] ?? false) === true || ($result['updated'] ?? false) === true || ($result['cleaned'] ?? false) === true) {
            $aiAttemptReset((int) $letterId);
        }
        if (($result['mode'] ?? '') === 'zoom') {
            $zoom++;
        }
        if (($result['mode'] ?? '') === 'offline') {
            $offline++;
        }
        if (($result['cleaned'] ?? false) === true) {
            $cleaned++;
        }
        if (($result['created'] ?? false) === false && ($result['updated'] ?? false) === false && ($result['cleaned'] ?? false) === false) {
            $failed++;
        }
    } catch (Throwable $e) {
        $failed++;
        // Log error ke file txt agar bisa dibaca dari browser
        file_put_contents(__DIR__ . '/ai_error_log.txt', date('Y-m-d H:i:s') . " - Error pada surat ID " . $letterId . ": " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// =====================================================
// TAHAP 2: Proses kegiatan dengan attachment tapi belum punya zoom_link
// (flyer yang di-upload langsung sebagai kegiatan, atau AI-generated yang
// zoom_link-nya masih kosong karena OCR pertama gagal)
// =====================================================
$kegZoomProcessed = 0;
$kegZoomUpdated = 0;
try {
    $kegStmt = $pdo->query(
        "SELECT id FROM agendas
         WHERE entry_type = 'kegiatan'
           AND attachment IS NOT NULL AND attachment != ''
           AND (zoom_link IS NULL OR zoom_link = '')
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $kegIds = array_map('intval', $kegStmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($kegIds as $kegId) {
        if (microtime(true) >= $aiDeadline) { break; }
        if ($aiAttemptCount((int) $kegId) >= 3) { continue; }
        try {
            $aiAttemptBump((int) $kegId);
            $kegResult = autoExtractZoomFromKegiatan($pdo, $kegId);
            $kegZoomProcessed++;
            if (($kegResult['updated'] ?? false) === true) {
                $kegZoomUpdated++;
                $zoom++;
                $aiAttemptReset((int) $kegId);
            }
        } catch (Throwable $e) {
            file_put_contents(__DIR__ . '/ai_error_log.txt', date('Y-m-d H:i:s') . " - Error ekstrak zoom dari kegiatan ID " . $kegId . ": " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
} catch (Throwable $e) {
    // Fail-safe: lanjut tanpa error fatal
}

// Buka kembali session sebentar HANYA untuk memperbarui sisa antrian,
// lalu langsung tutup lagi � kunci tidak dipegang selama proses berat.
$pendingCount = 0;
if (session_start()) {
    if (!empty($_SESSION['ai_pending_letter_ids']) && is_array($_SESSION['ai_pending_letter_ids'])) {
        $processedSet = array_flip($toProcess);
        $_SESSION['ai_pending_letter_ids'] = array_values(array_filter(
            array_map('intval', $_SESSION['ai_pending_letter_ids']),
            static fn (int $id): bool => $id > 0 && !isset($processedSet[$id])
        ));
        $pendingCount = count($_SESSION['ai_pending_letter_ids']);
    }
    session_write_close();
}

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'created' => $created,
    'updated' => $updated,
    'zoom' => $zoom,
    'offline' => $offline,
    'cleaned' => $cleaned,
    'failed' => $failed,
    'pending_queue' => $pendingCount,
    'keg_zoom_processed' => $kegZoomProcessed,
    'keg_zoom_updated' => $kegZoomUpdated,
]);

