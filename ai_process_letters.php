<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/ai_agenda.php';

$pdo = getPdo();
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('agenda.php');
}

if (!isAdmin() && !isSekretaris()) {
    setFlash('error', 'Hanya admin/sekretaris yang dapat menjalankan baca otomatis AI.');
    redirect('agenda.php');
}

$returnTo = trim((string) ($_POST['return_to'] ?? 'agenda.php'));
if (!in_array($returnTo, ['agenda.php', 'agenda_kegiatan.php', 'agenda_zoom.php', 'dashboard.php'], true)) {
    $returnTo = 'agenda.php';
}

$direction = trim((string) ($_POST['direction'] ?? ''));
$directionSql = '';
$params = [];
if (in_array($direction, ['masuk', 'keluar'], true)) {
    $directionSql = ' AND direction = :direction';
    $params['direction'] = $direction;
}

$sql = "SELECT id FROM agendas WHERE entry_type = 'surat'{$directionSql} ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$letterIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Lepas kunci session sebelum loop AI � proses bisa berjalan puluhan detik
// dan PHP mengunci file session selama itu, memblokir klik halaman lain user.
session_write_close();

if (!$letterIds) {
    if (session_start()) {
        setFlash('error', 'Tidak ada data surat untuk diproses oleh AI.');
        session_write_close();
    }
    redirect($returnTo);
}

$created = 0;
$updated = 0;
$zoom = 0;
$offline = 0;
$failed = 0;
$cleaned = 0;

foreach ($letterIds as $letterIdRaw) {
    $letterId = (int) $letterIdRaw;
    if ($letterId <= 0) {
        continue;
    }

    try {
        $result = autoGenerateAgendaFromLetter($pdo, $letterId);
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
    }
}

$total = count($letterIds);
$message = "AI selesai membaca {$total} surat. ";
$message .= "Buat agenda baru: {$created}, update agenda: {$updated}. ";
$message .= "Terklasifikasi Zoom: {$zoom}, non-Zoom: {$offline}.";
if ($cleaned > 0) {
    $message .= " Dihapus agenda salah klasifikasi: {$cleaned}.";
}
if ($failed > 0) {
    $message .= " {$failed} surat belum bisa diproses penuh (cek isi/lampiran).";
}

// =====================================================
// TAHAP 2: Ekstrak zoom link dari kegiatan/flyer yang belum punya zoom_link
// =====================================================
$kegZoomUpdated = 0;
try {
    $kegStmt = $pdo->query(
        "SELECT id FROM agendas
         WHERE entry_type = 'kegiatan'
           AND attachment IS NOT NULL AND attachment != ''
           AND (zoom_link IS NULL OR zoom_link = '')
         ORDER BY id DESC"
    );
    $kegIds = array_map('intval', $kegStmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($kegIds as $kegId) {
        try {
            $kegResult = autoExtractZoomFromKegiatan($pdo, $kegId);
            if (($kegResult['updated'] ?? false) === true) {
                $kegZoomUpdated++;
            }
        } catch (Throwable $e) {
            // Fail-safe: skip individual error
        }
    }
} catch (Throwable $e) {
    // Fail-safe
}

if ($kegZoomUpdated > 0) {
    $message .= " Ekstrak zoom link dari {$kegZoomUpdated} flyer/kegiatan.";
}

// Buka session lagi sebentar hanya untuk menulis flash message.
if (session_start()) {
    setFlash('success', $message);
    session_write_close();
}
redirect($returnTo);

