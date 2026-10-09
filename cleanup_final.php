<?php

declare(strict_types=1);

/**
 * CLEANUP FINAL — perbaikan data kegiatan berbasis evidence.
 *
 * Dua pekerjaan:
 * 1. Resolve shortlink pemerintah (s.kemkes.go.id / s.kemenkeu.go.id) di
 *    zoom_link via API SLINK. Link meeting asli → simpan URL tujuan asli.
 *    Landing page (multi-link / form / drive) → kosongkan zoom_link.
 * 2. Hapus kegiatan AI-generated yang judulnya terblokir filter publik
 *    (pemberitahuan/permohonan/keputusan/pemutakhiran/dll) DAN tidak punya
 *    link meeting asli. Entri manual (ai_generated=0) tidak dihapus —
 *    hanya dibersihkan link-nya; penyembunyian dari publik ditangani
 *    filter display di index.php.
 *
 * Jalankan via browser (login admin) atau CLI: php cleanup_final.php
 * HAPUS FILE INI SETELAH SELESAI.
 */

require_once __DIR__ . '/app/helpers.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

$pdo = getPdo();

if (!$isCli) {
    requireLogin();
    if (!isAdmin() && !isSekretaris()) {
        die('Akses ditolak.');
    }
}

function out(string $html, string $plain = ''): void
{
    if (PHP_SAPI === 'cli') {
        echo ($plain !== '' ? $plain : strip_tags($html)) . PHP_EOL;
    } else {
        echo $html;
    }
}

/**
 * Resolve shortlink SLINK Kemkes/Kemenkeu via API publiknya.
 * Return: ['type' => 'single'|'multi'|'unknown', 'url' => ?string]
 */
function resolveGovShortlink(string $url): array
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    if ($path === '' || str_contains($path, '/')) {
        return ['type' => 'unknown', 'url' => null];
    }

    $apiBase = null;
    if ($host === 's.kemkes.go.id' || str_ends_with($host, '.s.kemkes.go.id')) {
        $apiBase = 'https://s.kemkes.go.id';
    } elseif ($host === 's.kemenkeu.go.id' || str_ends_with($host, '.s.kemenkeu.go.id')) {
        $apiBase = 'https://s.kemenkeu.go.id';
    }
    if ($apiBase === null) {
        return ['type' => 'unknown', 'url' => null];
    }

    $api = $apiBase . '/slink/public/links/' . rawurlencode($path) . '/';
    $ctx = stream_context_create(['http' => ['timeout' => 15], 'ssl' => ['verify_peer' => true]]);
    $raw = @file_get_contents($api, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return ['type' => 'unknown', 'url' => null];
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['type'])) {
        return ['type' => 'unknown', 'url' => null];
    }

    if ($json['type'] === 'single' && !empty($json['original_url'])) {
        return ['type' => 'single', 'url' => (string) $json['original_url']];
    }

    // multi: landing page — bukan link join. Cek apakah ADA item zoom aktif
    // (hanya sebagai info; link induk tetap bukan link meeting).
    $hasActiveZoom = false;
    foreach ((array) ($json['items'] ?? []) as $item) {
        $itemUrl = strtolower((string) ($item['original_url'] ?? ''));
        if (!empty($item['is_active']) && (str_contains($itemUrl, 'zoom.us/j/') || str_contains($itemUrl, 'meet.google.com'))) {
            $hasActiveZoom = true;
            break;
        }
    }
    return ['type' => 'multi', 'url' => null, 'has_active_zoom' => $hasActiveZoom, 'title' => (string) ($json['title'] ?? '')];
}

if (!$isCli) {
    echo '<html><head><meta charset="utf-8"><style>body{font-family:sans-serif;padding:20px;max-width:1000px;margin:auto}td,th{border:1px solid #ccc;padding:6px;font-size:13px}table{border-collapse:collapse;width:100%}th{background:#eee}.bad{background:#fee}.ok{background:#efe}.warn{background:#ffc}</style></head><body>';
}

out('<h2>Cleanup Final — Evidence-Based</h2>', '=== CLEANUP FINAL ===');

// ---------------------------------------------------------
// BAGIAN 1: Resolve shortlink pemerintah di zoom_link
// ---------------------------------------------------------
out('<h3>Bagian 1: Verifikasi shortlink pemerintah di zoom_link</h3>', '--- Bagian 1: Shortlink pemerintah ---');

// PENTING: hanya entri buatan AI (ai_generated=1) yang diverifikasi.
// Entri manual (ai_generated=0) tidak disentuh — link yang dipasang user
// lewat form adalah keputusan user, cleanup tidak berhak mengosongkannya.
$stmt = $pdo->query(
    "SELECT id, title, agenda_mode, zoom_link, zoom_meeting_id, zoom_passcode, ai_generated
     FROM agendas
     WHERE entry_type = 'kegiatan'
       AND ai_generated = 1
       AND zoom_link IS NOT NULL AND zoom_link != ''
       AND (zoom_link LIKE '%s.kemkes.go.id%' OR zoom_link LIKE '%s.kemenkeu.go.id%')"
);
$rows = $stmt->fetchAll();

if (!$rows) {
    out('<p>Tidak ada shortlink pemerintah di zoom_link.</p>', 'Tidak ada shortlink pemerintah.');
}

$fixed = 0;
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $link = trim((string) $row['zoom_link']);
    $res = resolveGovShortlink($link);
    $label = mb_substr((string) $row['title'], 0, 60);

    if ($res['type'] === 'single' && $res['url'] !== null) {
        $target = $res['url'];
        $isMeeting = isPublicMeetingLink($target, (string) $row['title']);
        if ($isMeeting) {
            // Simpan URL asli (transparan untuk publik)
            $pdo->prepare("UPDATE agendas SET zoom_link = ? WHERE id = ?")->execute([$target, $id]);
            $fixed++;
            out("<p class='ok'>✅ #{$id} {$label} → link asli meeting: " . htmlspecialchars($target) . "</p>",
                "OK   #{$id} {$label} => {$target}");
        } else {
            // Single tapi bukan meeting (form/drive) → kosongkan
            $pdo->prepare("UPDATE agendas SET zoom_link = '', zoom_meeting_id = '', zoom_passcode = '', agenda_mode = IF(agenda_mode = 'zoom', 'offline', agenda_mode) WHERE id = ?")
                ->execute([$id]);
            $fixed++;
            out("<p class='warn'>⚠️ #{$id} {$label} → single tapi BUKAN meeting ({$target}) — zoom_link dikosongkan</p>",
                "WARN #{$id} {$label} => bukan meeting ({$target}), link dikosongkan");
        }
    } elseif ($res['type'] === 'multi') {
        $info = ($res['has_active_zoom'] ?? false) ? ' (ada item zoom aktif di dalamnya)' : '';
        $landingTitle = (string) ($res['title'] ?? '');
        $pdo->prepare("UPDATE agendas SET zoom_link = '', zoom_meeting_id = '', zoom_passcode = '', agenda_mode = IF(agenda_mode = 'zoom', 'offline', agenda_mode) WHERE id = ?")
            ->execute([$id]);
        $fixed++;
        out("<p class='bad'>❌ #{$id} {$label} → landing page multi-link \"{$landingTitle}\"{$info} — zoom_link dikosongkan</p>",
            "HAPUS #{$id} {$label} => landing multi-link \"{$landingTitle}\"{$info}");
    } else {
        out("<p>ℹ️ #{$id} {$label} → tidak bisa di-resolve, link dibiarkan: " . htmlspecialchars($link) . "</p>",
            "SKIP #{$id} {$label} => unresolved");
    }
}
out("<p><b>{$fixed} shortlink diverifikasi/diperbaiki.</b></p>", "Fixed: {$fixed}");

// ---------------------------------------------------------
// BAGIAN 2: Hapus kegiatan AI yang judulnya terblokir & tanpa meeting link
// ---------------------------------------------------------
out('<h3>Bagian 2: Hapus kegiatan AI terblokir tanpa link meeting</h3>', '--- Bagian 2: AI junk terblokir ---');

$stmt2 = $pdo->query(
    "SELECT id, title, zoom_link, description, ai_generated
     FROM agendas
     WHERE entry_type = 'kegiatan'"
);

$deleted = 0;
$keptManual = 0;
foreach ($stmt2->fetchAll() as $row) {
    $title = (string) $row['title'];
    if (isPublicAgendaTitle($title)) {
        continue; // judul boleh tampil — bukan junk
    }

    $ctx = $title . ' ' . (string) ($row['description'] ?? '');
    if (isPublicMeetingLink($row['zoom_link'] ?? null, $ctx)) {
        continue; // punya link meeting asli — tetap tampil di agenda Zoom
    }

    if ((int) $row['ai_generated'] === 1) {
        $pdo->prepare("DELETE FROM agendas WHERE id = ? AND ai_generated = 1")->execute([(int) $row['id']]);
        $deleted++;
        out("<p class='bad'>❌ DIHAPUS (AI): #{$row['id']} — " . htmlspecialchars(mb_substr($title, 0, 70)) . "</p>",
            "HAPUS #{$row['id']} {$title}");
    } else {
        $keptManual++;
        out("<p class='warn'>⚠️ MANUAL disembunyikan dari publik (tidak dihapus): #{$row['id']} — " . htmlspecialchars(mb_substr($title, 0, 70)) . "</p>",
            "MANUAL #{$row['id']} {$title} (hidden dari publik, tidak dihapus)");
    }
}

out('<hr><h3>Ringkasan</h3>', '--- Ringkasan ---');
out("<p>Shortlink diperbaiki/dikosongkan: <b>{$fixed}</b></p>", "Shortlink fixed: {$fixed}");
out("<p>Entri AI dihapus: <b>{$deleted}</b></p>", "AI dihapus: {$deleted}");
out("<p>Entri manual disembunyikan dari publik: <b>{$keptManual}</b> (data tetap ada)</p>", "Manual hidden: {$keptManual}");
out("<p>Refresh index.php untuk melihat hasilnya.</p>", 'Refresh index.php untuk hasil.');
out("<p style='color:red;font-weight:bold'>HAPUS FILE INI SETELAH SELESAI.</p>", 'HAPUS FILE INI SETELAH SELESAI.');

if (!$isCli) {
    echo '</body></html>';
}
