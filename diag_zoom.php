<?php

declare(strict_types=1);

/**
 * DIAGNOSTIK ZOOM — cek entri yang muncul di Agenda Zoom index.php
 * beserta status link-nya. JALANKAN SEKALI, LALU HAPUS.
 */

require_once __DIR__ . '/app/helpers.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = getPdo();

echo "=== ENTRI YANG MUNCUL DI AGENDA ZOOM (query persis index.php) ===\n\n";
$q = $pdo->query("SELECT id, title, agenda_mode, zoom_link, zoom_meeting_id, agenda_date, ai_generated FROM agendas WHERE entry_type = 'kegiatan' AND (agenda_mode IN ('zoom','hybrid') OR (zoom_link IS NOT NULL AND zoom_link != '')) AND zoom_link IS NOT NULL AND zoom_link != '' ORDER BY agenda_date DESC LIMIT 30");

$count = 0;
$bad = 0;
while ($r = $q->fetch()) {
    $count++;
    $linkOk = !empty($r['zoom_link']);
    $badge = $linkOk ? 'OK' : 'TANPA-LINK!';
    if (!$linkOk) $bad++;
    printf("[%s] #%d [%s] ai=%d | %s\n", $badge, $r['id'], $r['agenda_mode'], $r['ai_generated'], mb_substr($r['title'],0,60));
    printf("     date=%s link=%s id=%s\n\n", $r['agenda_date'], $r['zoom_link'] ?: '(kosong)', $r['zoom_meeting_id'] ?: '(kosong)');
}
echo "Total: {$count} entri, {$bad} tanpa link\n\n";

echo "=== NOTA DINAS di ZOOM SECTION (yang bermasalah) ===\n\n";
$q2 = $pdo->query("SELECT id, title, agenda_mode, zoom_link, zoom_meeting_id FROM agendas WHERE entry_type = 'kegiatan' AND (agenda_mode IN ('zoom','hybrid') OR (zoom_link IS NOT NULL AND zoom_link != '')) AND zoom_link IS NOT NULL AND zoom_link != '' AND title LIKE '%Nota Dinas%' LIMIT 20");
while ($r = $q2->fetch()) {
    printf("#%d [%s] link=[%s] id=[%s] | %s\n", $r['id'], $r['agenda_mode'], $r['zoom_link'] ?: 'KOSONG', $r['zoom_meeting_id'] ?: 'KOSONG', $r['title']);
}

echo "\n=== SEMUA ZOOM tanpa link (seharusnya TIDAK muncul) ===\n\n";
$q3 = $pdo->query("SELECT id, title, agenda_mode, zoom_link, zoom_meeting_id FROM agendas WHERE entry_type = 'kegiatan' AND (agenda_mode IN ('zoom','hybrid') OR (zoom_link IS NOT NULL AND zoom_link != '')) AND (zoom_link IS NULL OR zoom_link = '')");
$noLink = $q3->fetchAll();
echo "Total tanpa link: " . count($noLink) . "\n";
foreach ($noLink as $r) {
    printf("#%d [%s] link=[%s] id=[%s] | %s\n", $r['id'], $r['agenda_mode'], $r['zoom_link'] ?: 'KOSONG', $r['zoom_meeting_id'] ?: 'KOSONG', $r['title']);
}