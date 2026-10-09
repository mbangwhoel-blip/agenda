<?php

declare(strict_types=1);

/**
 * CEK HASIL — tampilkan entri yang MASIH salah di index.php.
 * Buka file ini, lihat hasilnya langsung di layar. HAPUS setelah selesai.
 */

require_once __DIR__ . '/app/helpers.php';
header('Content-Type: text/html; charset=utf-8');
$pdo = getPdo();

echo '<html><head><meta charset="utf-8"><title>Cek Hasil</title><style>body{font-family:sans-serif;padding:20px;max-width:900px;margin:auto}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:6px;font-size:13px}th{background:#eee}.bad{background:#fee}.ok{background:#efe}h2{margin-top:24px}</style></head><body>';
echo '<h1>Hasil Cek Agenda</h1>';

// 1. Entri di Agenda Kegiatan (index.php) — filter persis seperti query index.php
$bulanIni = date('Y-m');
echo "<h2>1. Agenda Kegiatan — bulan {$bulanIni} (yang tampil di index.php)</h2>";
$q = $pdo->query("SELECT id, title, agenda_mode, agenda_date, ai_generated, zoom_link FROM agendas WHERE entry_type='kegiatan' AND agenda_date >= '{$bulanIni}-01' AND agenda_date < DATE_ADD('{$bulanIni}-01', INTERVAL 1 MONTH) ORDER BY agenda_date");
$rows = $q->fetchAll();
if (!$rows) { echo "<p><b>Tidak ada entri bulan ini.</b></p>"; }
else {
    echo "<table><tr><th>ID</th><th>Judul</th><th>Tanggal</th><th>Mode</th><th>AI?</th><th>Status</th></tr>";
    foreach ($rows as $r) {
        $isBad = false;
        $lower = strtolower($r['title']);
        foreach (['nota dinas','surat keputusan','permohonan bantuan','permohonan ijin','permohonan izin','sk '] as $bad) {
            if (str_contains($lower, $bad)) { $isBad = true; break; }
        }
        $cls = $isBad ? 'bad' : 'ok';
        $status = $isBad ? 'SALAH — harus dihapus' : 'OK';
        echo "<tr class='{$cls}'><td>#{$r['id']}</td><td>" . htmlspecialchars($r['title']) . "</td><td>{$r['agenda_date']}</td><td>{$r['agenda_mode']}</td><td>" . ($r['ai_generated'] ? 'AI' : 'Manual') . "</td><td><b>{$status}</b></td></tr>";
    }
    echo "</table>";
}

// 2. Entri di Agenda Zoom (index.php) — dengan filter zoom_link
echo "<h2>2. Agenda Zoom — bulan {$bulanIni} (yang tampil di index.php)</h2>";
$q2 = $pdo->query("SELECT id, title, agenda_mode, agenda_date, zoom_link FROM agendas WHERE entry_type='kegiatan' AND (agenda_mode IN ('zoom','hybrid') OR (zoom_link IS NOT NULL AND zoom_link != '')) AND zoom_link IS NOT NULL AND zoom_link != '' AND agenda_date >= '{$bulanIni}-01' AND agenda_date < DATE_ADD('{$bulanIni}-01', INTERVAL 1 MONTH) ORDER BY agenda_date");
$rows2 = $q2->fetchAll();
if (!$rows2) { echo "<p><b>Tidak ada entri zoom bulan ini.</b></p>"; }
else {
    echo "<table><tr><th>ID</th><th>Judul</th><th>Tanggal</th><th>Link</th></tr>";
    foreach ($rows2 as $r) {
        echo "<tr><td>#{$r['id']}</td><td>" . htmlspecialchars($r['title']) . "</td><td>{$r['agenda_date']}</td><td>" . htmlspecialchars($r['zoom_link'] ?: '(kosong)') . "</td></tr>";
    }
    echo "</table>";
}

// 3. Entri AI yang masih salah (tidak seharusnya ada)
echo "<h2>3. Semua AI-Generated yang Judulnya Nota Dinas / SK / Permohonan</h2>";
$q3 = $pdo->query("SELECT id, title, agenda_mode, agenda_date, zoom_link, ai_generated FROM agendas WHERE entry_type='kegiatan' AND ai_generated=1 AND (title LIKE '%Nota Dinas%' OR title LIKE '%Surat Keputusan%' OR title LIKE '%Permohonan%') ORDER BY id DESC LIMIT 50");
$rows3 = $q3->fetchAll();
if (!$rows3) { echo "<p><b>Bersih — tidak ada yang salah.</b></p>"; }
else {
    echo "<table><tr><th>ID</th><th>Judul</th><th>Tanggal</th><th>Mode</th><th>Link</th><th>Aksi</th></tr>";
    foreach ($rows3 as $r) {
        $hasLink = !empty($r['zoom_link']);
        $action = $hasLink ? 'OK (ada link)' : 'HAPUS';
        $cls = $hasLink ? 'ok' : 'bad';
        echo "<tr class='{$cls}'><td>#{$r['id']}</td><td>" . htmlspecialchars($r['title']) . "</td><td>{$r['agenda_date']}</td><td>{$r['agenda_mode']}</td><td>" . htmlspecialchars($r['zoom_link'] ?: '(kosong)') . "</td><td><b>{$action}</b></td></tr>";
    }
    echo "</table>";
}

echo "<hr><p style='color:red;font-weight:bold'>HAPUS FILE INI SETELAH SELESAI.</p></body></html>";