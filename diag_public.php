<?php

declare(strict_types=1);

/**
 * DIAG PUBLIC — periksa kenapa tabel kegiatan publik kosong.
 * Meniru persis query + filter index.php untuk bulan berjalan,
 * lalu cetak keputusan per baris.
 * HAPUS FILE INI SETELAH SELESAI.
 */

require_once __DIR__ . '/app/helpers.php';

header('Content-Type: text/html; charset=utf-8');
$pdo = getPdo();
requireLogin();

$cm = (int) date('n');
$cy = (int) date('Y');

echo '<html><head><meta charset="utf-8"><style>body{font-family:monospace;padding:20px}table{border-collapse:collapse}td,th{border:1px solid #999;padding:4px 8px;font-size:12px}.ok{background:#dfd}.bad{background:#fdd}</style></head><body>';
echo "<h3>Diagnostik kegiatan bulan {$cy}-" . str_pad((string)$cm, 2, '0', STR_PAD_LEFT) . "</h3>";

// 1) Versi fungsi yang ter-load di hosting
echo '<p><b>isPublicMeetingLink ada:</b> ' . (function_exists('isPublicMeetingLink') ? 'YA' : 'TIDAK (helpers.php lama!)') . '</p>';

// 2) Query persis seperti index.php
$stmt = $pdo->prepare("SELECT id, title, agenda_date, agenda_date_end, agenda_mode, zoom_link, ai_generated
    FROM agendas WHERE entry_type = 'kegiatan' AND MONTH(agenda_date) = :m AND YEAR(agenda_date) = :y ORDER BY agenda_date");
$stmt->execute(['m' => $cm, 'y' => $cy]);
$rows = $stmt->fetchAll();

echo '<p><b>Baris dari query SQL:</b> ' . count($rows) . '</p>';
echo '<table><tr><th>ID</th><th>Judul</th><th>Tgl</th><th>Mode</th><th>AI?</th><th>Judul lolos?</th><th>Link meeting?</th><th>Keputusan</th></tr>';

foreach ($rows as $r) {
    $manual = (int) $r['ai_generated'] === 0;
    $titleOk = isPublicAgendaTitle((string) $r['title']);
    $ctx = (string) $r['title'];
    $linkOk = function_exists('isPublicMeetingLink') ? isPublicMeetingLink($r['zoom_link'] ?? null, $ctx) : false;
    $showKegiatan = $manual || $titleOk;
    $cls = $showKegiatan ? 'ok' : 'bad';
    echo "<tr class='{$cls}'><td>#{$r['id']}</td><td>" . htmlspecialchars(mb_substr((string)$r['title'], 0, 45)) . "</td>"
        . "<td>{$r['agenda_date']}</td><td>{$r['agenda_mode']}</td>"
        . "<td>" . ($manual ? 'manual' : 'AI') . "</td>"
        . "<td>" . ($titleOk ? 'ya' : 'TIDAK') . "</td>"
        . "<td>" . ($linkOk ? 'ya' : 'tidak') . "</td>"
        . "<td><b>" . ($showKegiatan ? 'TAMPIL di kegiatan' : 'SEMBUNYI') . "</b></td></tr>";
}
echo '</table>';

// 3) Cek kolom yang dipakai index.php benar-benar ada
echo '<h4>Kolom tabel agendas:</h4><p>';
$cols = $pdo->query("SHOW COLUMNS FROM agendas")->fetchAll(PDO::FETCH_COLUMN);
$need = ['ai_generated', 'agenda_date_end', 'zoom_link', 'source_agenda_id', 'entry_type'];
foreach ($need as $n) {
    echo $n . ': <b>' . (in_array($n, $cols, true) ? 'ada' : 'TIDAK ADA!') . '</b> &nbsp; ';
}
echo '</p>';
echo "<p style='color:red'><b>HAPUS FILE INI SETELAH SELESAI.</b></p></body></html>";
