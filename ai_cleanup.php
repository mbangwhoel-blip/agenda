<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/ai_agenda.php';

// Naikkan limit eksekusi per batch (bukan global)
@set_time_limit(60);
@ini_set('memory_limit', '256M');

$pdo = getPdo();
requireLogin();

if (!isAdmin() && !isSekretaris()) {
    setFlash('error', 'Hanya admin/sekretaris yang dapat menjalankan cleanup AI.');
    redirect('agenda.php');
}

$returnTo = trim((string) ($_GET['return_to'] ?? 'agenda.php'));
if (!in_array($returnTo, ['agenda.php', 'agenda_kegiatan.php', 'agenda_zoom.php', 'dashboard.php', 'index.php'], true)) {
    $returnTo = 'agenda.php';
}

// Batch kecil: 5 surat per request agar tidak 503
$batchSize = 5;
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$round = max(0, (int) ($_GET['round'] ?? 0));

// Ambil batch surat
$sql = "SELECT id FROM agendas WHERE entry_type = 'surat' ORDER BY id DESC LIMIT {$batchSize} OFFSET {$offset}";
$stmt = $pdo->query($sql);
$letterIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Hitung total surat
$totalStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE entry_type = 'surat'");
$totalSurat = (int) $totalStmt->fetchColumn();

if (!$letterIds) {
    // Selesai — jalankan tahap 2: ekstrak zoom dari kegiatan
    $kegZoomUpdated = 0;
    try {
        $kegStmt = $pdo->query(
            "SELECT id FROM agendas
             WHERE entry_type = 'kegiatan'
               AND attachment IS NOT NULL AND attachment != ''
               AND (zoom_link IS NULL OR zoom_link = '')
             ORDER BY id DESC LIMIT 20"
        );
        $kegIds = array_map('intval', $kegStmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($kegIds as $kegId) {
            try {
                $kegResult = autoExtractZoomFromKegiatan($pdo, $kegId);
                if (($kegResult['updated'] ?? false) === true) {
                    $kegZoomUpdated++;
                }
            } catch (Throwable $e) {
                // Fail-safe
            }
        }
    } catch (Throwable $e) {
        // Fail-safe
    }

    $msg = "Cleanup AI selesai. Semua {$totalSurat} surat telah diproses.";
    if ($kegZoomUpdated > 0) {
        $msg .= " Ekstrak zoom dari {$kegZoomUpdated} kegiatan.";
    }
    setFlash('success', $msg);
    redirect($returnTo);
}

// Proses batch ini — mode skipAttachment=true untuk kecepatan
$created = 0;
$updated = 0;
$zoom = 0;
$offline = 0;
$cleaned = 0;
$failed = 0;

foreach ($letterIds as $letterIdRaw) {
    $letterId = (int) $letterIdRaw;
    if ($letterId <= 0) {
        continue;
    }
    try {
        // skipAttachment=true = tidak OCR, hanya klasifikasi dari judul/deskripsi
        $result = autoGenerateAgendaFromLetter($pdo, $letterId, true);
        if (($result['created'] ?? false) === true) {
            $created++;
        }
        if (($result['updated'] ?? false) === true) {
            $updated++;
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
        file_put_contents(__DIR__ . '/ai_error_log.txt', date('Y-m-d H:i:s') . " - Cleanup error surat ID {$letterId}: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

$processed = $offset + count($letterIds);
$progress = $totalSurat > 0 ? round(($processed / $totalSurat) * 100) : 100;

// Lanjut ke batch berikutnya
$nextOffset = $offset + $batchSize;
$nextRound = $round + 1;

// Tampilkan halaman progress
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup AI - <?= $processed ?>/<?= $totalSurat ?></title>
    <meta http-equiv="refresh" content="2;url=ai_cleanup.php?offset=<?= $nextOffset ?>&round=<?= $nextRound ?>&return_to=<?= urlencode($returnTo) ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { max-width: 480px; width: 90%; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .title { font-size: 18px; font-weight: 800; margin-bottom: 6px; display: flex; align-items: center; }
        .subtitle { font-size: 13px; color: #94a3b8; margin-bottom: 20px; }
        .progress-bar { width: 100%; height: 10px; background: #334155; border-radius: 999px; overflow: hidden; margin-bottom: 8px; }
        .progress-fill { height: 100%; background: linear-gradient(to right, #f43f5e, #fbbf24); border-radius: 999px; }
        .progress-text { font-size: 12px; color: #cbd5e1; text-align: center; margin-bottom: 20px; }
        .stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
        .stat { background: #0f172a; padding: 8px 10px; border-radius: 8px; border: 1px solid #334155; text-align: center; }
        .stat-label { color: #64748b; font-size: 10px; text-transform: uppercase; font-weight: 600; }
        .stat-value { font-size: 16px; font-weight: 800; }
        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #475569; border-top-color: #f43f5e; border-radius: 50%; animation: spin 0.8s linear infinite; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .link { margin-top: 16px; font-size: 11px; color: #64748b; text-align: center; }
        .link a { color: #38bdf8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="title"><span class="spinner"></span>Cleanup AI berjalan...</div>
            <div class="subtitle">Hapus agenda salah klasifikasi & perbaiki tanggal</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $progress ?>%;"></div>
            </div>
            <div class="progress-text"><?= $processed ?> / <?= $totalSurat ?> surat (<?= $progress ?>%)</div>
            <div class="stats">
                <div class="stat"><div class="stat-label">Update</div><div class="stat-value" style="color:#38bdf8"><?= $updated ?></div></div>
                <div class="stat"><div class="stat-label">Hapus</div><div class="stat-value" style="color:#fbbf24"><?= $cleaned ?></div></div>
                <div class="stat"><div class="stat-label">Gagal</div><div class="stat-value" style="color:#64748b"><?= $failed ?></div></div>
            </div>
            <div class="link">Lanjut otomatis 2 detik... <a href="ai_cleanup.php?offset=<?= $nextOffset ?>&round=<?= $nextRound ?>&return_to=<?= urlencode($returnTo) ?>">lanjut</a></div>
        </div>
    </div>
</body>
</html>
