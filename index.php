<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/ai_agenda.php';

$rawMonth = trim((string)($_GET['m'] ?? ''));
$isAllMonths = ($rawMonth === 'all');
if (preg_match('/^(\d{4})-(\d{2})$/', $rawMonth, $mMatches)) {
    $currentYear = (int)$mMatches[1];
    $currentMonth = (int)$mMatches[2];
} elseif (is_numeric($rawMonth) && (int)$rawMonth >= 1 && (int)$rawMonth <= 12) {
    $currentMonth = (int)$rawMonth;
    $currentYear = isset($_GET['y']) && is_numeric($_GET['y']) && $_GET['y'] >= 2000 && $_GET['y'] <= 2100 ? (int)$_GET['y'] : (int)date('Y');
} else {
    $currentMonth = (int)date('n');
    $currentYear = isset($_GET['y']) && is_numeric($_GET['y']) && $_GET['y'] >= 2000 && $_GET['y'] <= 2100 ? (int)$_GET['y'] : (int)date('Y');
}

$dbError = false;
$stats = null;
try {
    $pdo = getPdo();

    // Sinkronisasi otomatis surat yang belum diproses oleh AI jika ada antrian atau surat baru
    if (function_exists('autoGenerateAgendaFromLetter')) {
        $aiAttemptDir = __DIR__ . '/uploads/.ai_attempts_v3';
        if (!is_dir($aiAttemptDir)) { @mkdir($aiAttemptDir, 0775, true); }
        $forceSync = isset($_GET['force_sync']) || isset($_GET['sync_ai']);

        $pendingSyncIds = [];
        if (!empty($_SESSION['ai_pending_letter_ids']) && is_array($_SESSION['ai_pending_letter_ids'])) {
            $pendingSyncIds = array_slice(array_map('intval', $_SESSION['ai_pending_letter_ids']), 0, 5);
        }
        // Throttle deteksi global maksimal 1x per 5 menit — query deteksi berat
        // (NOT IN + NOT EXISTS) dan autoGenerateAgendaFromLetter memanggil API
        // AI eksternal; tanpa ini SETIAP pengunjung publik menanggung biayanya.
        // Jalur session (ai_pending_letter_ids) tidak dibatasi — itu intent eksplisit
        // user yang baru mengunggah surat.
        $aiSyncLock = $aiAttemptDir . '/.sync_lock';
        $aiSyncLocked = !$forceSync && is_file($aiSyncLock) && (time() - (int)@file_get_contents($aiSyncLock)) < 300;
        if (empty($pendingSyncIds) && !$aiSyncLocked) {
            @file_put_contents($aiSyncLock, (string)time());
            try {
                // Ambil surat yang belum punya child kegiatan, ATAU yang child kegiatannya dibuat AI tapi belum jadi Zoom
                $unprocessedStmt = $pdo->query("
                    SELECT id FROM agendas 
                    WHERE entry_type = 'surat' 
                      AND (
                          id NOT IN (SELECT source_agenda_id FROM agendas WHERE source_agenda_id IS NOT NULL AND entry_type = 'kegiatan')
                          OR id IN (
                              SELECT source_agenda_id FROM agendas 
                              WHERE source_agenda_id IS NOT NULL 
                                AND entry_type = 'kegiatan' 
                                AND ai_generated = 1 
                                AND agenda_mode != 'zoom'
                                AND (zoom_link IS NULL OR zoom_link = '')
                                AND (zoom_meeting_id IS NULL OR zoom_meeting_id = '')
                          )
                      )
                      AND (
                          (attachment IS NOT NULL AND attachment != '')
                          OR (zoom_link IS NOT NULL AND zoom_link != '')
                          OR (zoom_meeting_id IS NOT NULL AND zoom_meeting_id != '')
                      )
                    ORDER BY id DESC LIMIT 5
                ");
                $unprocessedIds = $unprocessedStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($unprocessedIds as $uid) {
                    $cntFile = $aiAttemptDir . '/' . $uid . '.cnt';
                    $cnt = is_file($cntFile) ? (int)@file_get_contents($cntFile) : 0;
                    if ($forceSync || $cnt < 3) {
                        $pendingSyncIds[] = (int)$uid;
                    }
                }
            } catch (Exception $e) {}
        }
        foreach ($pendingSyncIds as $pid) {
            try {
                $cntFile = $aiAttemptDir . '/' . $pid . '.cnt';
                @file_put_contents($cntFile, (string)((is_file($cntFile) ? (int)@file_get_contents($cntFile) : 0) + 1));
                $res = autoGenerateAgendaFromLetter($pdo, (int)$pid);
                if (!empty($res['created']) || !empty($res['updated']) || !empty($res['cleaned'])) {
                    @unlink($cntFile);
                }
                if (!empty($_SESSION['ai_pending_letter_ids']) && is_array($_SESSION['ai_pending_letter_ids'])) {
                    $_SESSION['ai_pending_letter_ids'] = array_values(array_diff($_SESSION['ai_pending_letter_ids'], [$pid]));
                }
            } catch (Exception $e) {
                error_log('[index.php] sync letter ' . $pid . ' error: ' . $e->getMessage());
            }
        }
    }

    // Rentang bulan berbasis range (sargable) — bukan MONTH()/YEAR() di atas kolom
    // agar index idx_agenda_date / idx_agendas_entry_direction dapat dipakai.
    $monthStart = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $nextYear = $currentMonth === 12 ? $currentYear + 1 : $currentYear;
    $nextMonth = $currentMonth === 12 ? 1 : $currentMonth + 1;
    $monthEnd = sprintf('%04d-%02d-01', $nextYear, $nextMonth);

    // Cache ringan statistik landing (TTL 60 detik, per bulan) — query ini
    // full-scan agendas + correlated NOT EXISTS; untuk pengunjung publik
    // beruntun hasilnya identik. Staleness 1 menit tidak terasa.
    $statsCacheDir  = __DIR__ . '/storage/cache';
    $statsCacheFile = $statsCacheDir . '/index_stats_' . $currentYear . '_' . $currentMonth . '.json';
    if ($stats === null && is_file($statsCacheFile) && (time() - (int)@filemtime($statsCacheFile)) < 60) {
        $statsCached = @json_decode((string)@file_get_contents($statsCacheFile), true);
        if (is_array($statsCached)) {
            $stats = $statsCached;
        }
    }
    if ($stats === null) {
        $statsStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN direction = 'masuk' THEN 1 ELSE 0 END) as total_masuk,
                SUM(CASE WHEN direction = 'keluar' THEN 1 ELSE 0 END) as total_keluar,
                SUM(CASE WHEN attachment IS NOT NULL AND attachment != '' THEN 1 ELSE 0 END) as total_dokumen,
                COUNT(*) as total_agenda,
                SUM(CASE WHEN direction = 'masuk' AND recorded_date >= :ms1 AND recorded_date < :me1 THEN 1 ELSE 0 END) as masuk_bulan_ini,
                SUM(CASE WHEN direction = 'keluar' AND recorded_date >= :ms2 AND recorded_date < :me2 THEN 1 ELSE 0 END) as keluar_bulan_ini,
                SUM(CASE WHEN entry_type = 'kegiatan' AND agenda_date >= :ms3 AND agenda_date < :me3 THEN 1 ELSE 0 END) as kegiatan_bulan_ini,
                SUM(CASE WHEN (
                    (entry_type = 'kegiatan' OR (entry_type = 'surat' AND NOT EXISTS (SELECT 1 FROM agendas c WHERE c.source_agenda_id = agendas.id AND c.entry_type = 'kegiatan')))
                    AND (agenda_mode IN ('zoom','hybrid') OR (zoom_link IS NOT NULL AND zoom_link != '') OR (zoom_meeting_id IS NOT NULL AND zoom_meeting_id != ''))
                    AND agenda_date >= :ms4 AND agenda_date < :me4
                ) THEN 1 ELSE 0 END) as zoom_bulan_ini
            FROM agendas
        ");
        $statsStmt->execute([
            'ms1' => $monthStart, 'me1' => $monthEnd,
            'ms2' => $monthStart, 'me2' => $monthEnd,
            'ms3' => $monthStart, 'me3' => $monthEnd,
            'ms4' => $monthStart, 'me4' => $monthEnd,
        ]);
        $stats = $statsStmt->fetch();
        if (is_array($stats)) {
            if (!is_dir($statsCacheDir)) { @mkdir($statsCacheDir, 0775, true); }
            @file_put_contents($statsCacheFile, json_encode($stats));
        }
    }
} catch (Exception $e) {
    $dbError = true;
}

$totalMasuk        = $dbError ? null : (int)($stats['total_masuk'] ?? 0);
$totalKeluar       = $dbError ? null : (int)($stats['total_keluar'] ?? 0);
$totalDokumen      = $dbError ? null : (int)($stats['total_dokumen'] ?? 0);
$totalAgenda       = $dbError ? null : (int)($stats['total_agenda'] ?? 0);
$masukBulanIni     = $dbError ? null : (int)($stats['masuk_bulan_ini'] ?? 0);
$keluarBulanIni    = $dbError ? null : (int)($stats['keluar_bulan_ini'] ?? 0);
$kegiatanBulanIni  = $dbError ? null : (int)($stats['kegiatan_bulan_ini'] ?? 0);
$zoomBulanIni      = $dbError ? null : (int)($stats['zoom_bulan_ini'] ?? 0);

// Label bulan Indonesia (lengkap & ringkas)
$namaBulan = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$namaBulanRingkas = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
$bulanIni  = $namaBulan[$currentMonth];
$tahunIni  = $currentYear;

// Daftar pilihan bulan ringkas untuk filter Agenda Kegiatan & Agenda Zoom
$monthList = [];
if (!$dbError && isset($pdo)) {
    try {
        $monthList = $pdo->query("
            SELECT DISTINCT SUBSTRING(agenda_date, 1, 7) AS ym 
            FROM agendas 
            WHERE agenda_date IS NOT NULL AND agenda_date > '2000-01-01' 
            ORDER BY ym ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // Abaikan jika query gagal
    }
}

$currYmStr = sprintf('%04d-%02d', (int)date('Y'), (int)date('n'));
if (!in_array($currYmStr, $monthList, true)) {
    $monthList[] = $currYmStr;
}

$selectedMonthStr = sprintf('%04d-%02d', $currentYear, $currentMonth);
if (!$isAllMonths && !in_array($selectedMonthStr, $monthList, true)) {
    $monthList[] = $selectedMonthStr;
}

sort($monthList);

$monthOptionsRingkas = [];
foreach ($monthList as $ymVal) {
    if (preg_match('/^(\d{4})-(\d{2})$/', (string)$ymVal, $mParts)) {
        $mNum = (int)$mParts[2];
        $yNum = (int)$mParts[1];
        $monthOptionsRingkas[(string)$ymVal] = ($namaBulanRingkas[$mNum] ?? '') . ' ' . $yNum;
    }
}

$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}
$tabDefault = ($isAllMonths || !isset($_GET['m'])) ? 'semua' : 'bulan';

$allZero = !$dbError && ($totalMasuk === 0 && $totalKeluar === 0 && $totalDokumen === 0 && $totalAgenda === 0);

// Inisialisasi awal agar tersedia untuk lazy load SweetAlert2 di <head>
$eventDetails = [];

// --- Agenda Publik: Kegiatan & Zoom Mendatang ---
$publicAgendaError = false;
$kegiatanRows = [];
$zoomRows = [];
$kegiatanTotal = 0;
$zoomTotal = 0;
$kegiatanPage = max(1, (int)($_GET['kp'] ?? 1));
$zoomPage     = max(1, (int)($_GET['zp'] ?? 1));
$publicLimit  = 7;
$kegiatanLimit = (int)($_GET['kl'] ?? 5);
$kegiatanLimit = in_array($kegiatanLimit, [5, 10, 20], true) ? $kegiatanLimit : 5;
$zoomLimit     = (int)($_GET['zl'] ?? 5);
$zoomLimit     = in_array($zoomLimit, [5, 10, 20], true) ? $zoomLimit : 5;

// Validasi & sanitasi keyword pencarian (maks 100 karakter)
$kegiatanQ = mb_substr(trim((string)($_GET['kq'] ?? '')), 0, 100);
$kegiatanMode = in_array($_GET['km'] ?? '', ['zoom', 'offline', 'hybrid']) ? $_GET['km'] : '';
$zoomQ     = mb_substr(trim((string)($_GET['zq'] ?? '')), 0, 100);
$qTooLong  = (isset($_GET['kq']) && mb_strlen(trim((string)$_GET['kq'])) > 100)
           || (isset($_GET['zq']) && mb_strlen(trim((string)$_GET['zq'])) > 100);
// Simpan anchor untuk auto-scroll setelah redirect
$scrollAnchor = in_array($_GET['_anchor'] ?? '', ['agenda-kegiatan', 'agenda-zoom']) ? (string)$_GET['_anchor'] : '';

$isCurrentMonth = !$isAllMonths && ($currentYear === (int)date('Y') && $currentMonth === (int)date('n'));

// Base query params agar filter limit/paging tidak menghilangkan pencarian dan bulan
$monthParam = $isAllMonths ? 'all' : sprintf('%04d-%02d', $currentYear, $currentMonth);
$kBaseParams = array_filter([
    'm'  => $isAllMonths ? 'all' : (!$isCurrentMonth ? $monthParam : null),
    'kq' => $kegiatanQ !== '' ? $kegiatanQ : null,
    'km' => $kegiatanMode !== '' ? $kegiatanMode : null,
    'zq' => $zoomQ !== '' ? $zoomQ : null,
    'zp' => $zoomPage > 1 ? $zoomPage : null,
]);
$zBaseParams = array_filter([
    'm'  => $isAllMonths ? 'all' : (!$isCurrentMonth ? $monthParam : null),
    'kq' => $kegiatanQ !== '' ? $kegiatanQ : null,
    'kp' => $kegiatanPage > 1 ? $kegiatanPage : null,
    'zq' => $zoomQ !== '' ? $zoomQ : null,
]);

if (!$dbError && !$qTooLong) {
    try {
        $dateFilter = "agenda_date >= :qms AND agenda_date < :qme";
        
        // --- 1. Query Agenda Kegiatan ---
        $kParams = [];
        $kBase = "FROM agendas WHERE entry_type = 'kegiatan'";
        
        if ($kegiatanQ !== '') {
            // Ketika mencari kata kunci, cari di semua data lintas bulan agar surat/kegiatan pasti ketemu
            $kWords = array_values(array_filter(preg_split('/\s+/', $kegiatanQ)));
            $kConds = [];
            foreach ($kWords as $idx => $word) {
                $p1 = "kq_{$idx}_1";
                $p2 = "kq_{$idx}_2";
                $p3 = "kq_{$idx}_3";
                $p4 = "kq_{$idx}_4";
                $p5 = "kq_{$idx}_5";
                $p6 = "kq_{$idx}_6";
                $p7 = "kq_{$idx}_7";
                $p8 = "kq_{$idx}_8";
                $p9 = "kq_{$idx}_9";
                $p10 = "kq_{$idx}_10";
                $p11 = "kq_{$idx}_11";

                $kw = '%' . $word . '%';
                $kParams[$p1] = $kw;
                $kParams[$p2] = $kw;
                $kParams[$p3] = $kw;
                $kParams[$p4] = $kw;
                $kParams[$p5] = $kw;
                $kParams[$p6] = $kw;
                $kParams[$p7] = $kw;
                $kParams[$p8] = $kw;
                $kParams[$p9] = $kw;
                $kParams[$p10] = $kw;
                $kParams[$p11] = $kw;

                $kConds[] = "(
                    agendas.title LIKE :{$p1}
                    OR agendas.reference_number LIKE :{$p2}
                    OR agendas.location LIKE :{$p3}
                    OR agendas.notes LIKE :{$p4}
                    OR agendas.description LIKE :{$p5}
                    OR agendas.counterparty LIKE :{$p6}
                    OR EXISTS (
                        SELECT 1 FROM agendas p 
                        WHERE p.id = agendas.source_agenda_id 
                          AND (
                              p.reference_number LIKE :{$p7} 
                              OR p.title LIKE :{$p8} 
                              OR p.description LIKE :{$p9} 
                              OR p.notes LIKE :{$p10} 
                              OR p.counterparty LIKE :{$p11}
                          )
                    )
                )";
            }
            if (!empty($kConds)) {
                $kBase .= " AND (" . implode(' AND ', $kConds) . ")";
            }
        } else {
            // Filter bulan & tahun jika tidak ada kata kunci pencarian dan bukan "Semua Bulan"
            if (!$isAllMonths) {
                $kBase .= " AND " . $dateFilter;
                $kParams['qms'] = $monthStart;
                $kParams['qme'] = $monthEnd;
            } else {
                $kBase .= " AND agenda_date IS NOT NULL AND agenda_date > '2000-01-01'";
            }
        }

        if ($kegiatanMode !== '') {
            $kBase .= " AND agenda_mode = :km";
            $kParams['km'] = $kegiatanMode;
        }

        // Ambil SEMUA baris bulan ini (tanpa LIMIT) — filter display harus
        // berjalan SEBELUM pagination. Kalau filter jalan setelah LIMIT,
        // baris junk memakan slot halaman dan halaman bisa kosong walau
        // data bersihnya ada (bug: "tabel kosong, kalender penuh").
        $kStmt = $pdo->prepare(
            "SELECT id, title, description, agenda_date, agenda_date_end, agenda_time, agenda_mode, status, location, source_agenda_id, zoom_link, zoom_meeting_id, zoom_passcode, ai_generated, " .
            "COALESCE(NULLIF(agendas.reference_number, ''), (SELECT reference_number FROM agendas p WHERE p.id = agendas.source_agenda_id LIMIT 1)) AS reference_number, " .
            "(SELECT direction FROM agendas p WHERE p.id = agendas.source_agenda_id LIMIT 1) AS parent_direction, " .
            "agendas.attachment AS attachment, (SELECT p2.attachment FROM agendas p2 WHERE p2.id = agendas.source_agenda_id LIMIT 1) AS source_attachment " .
            $kBase . " ORDER BY
            CASE
                WHEN CURDATE() BETWEEN agenda_date AND COALESCE(agenda_date_end, agenda_date) THEN 1
                WHEN agenda_date >= CURDATE() THEN 2
                ELSE 3
            END ASC,
            CASE
                WHEN CURDATE() BETWEEN agenda_date AND COALESCE(agenda_date_end, agenda_date)
                    THEN CURDATE()
                WHEN agenda_date >= CURDATE()
                    THEN agenda_date
                ELSE COALESCE(agenda_date_end, agenda_date)
            END " . ($kegiatanQ !== '' ? 'DESC' : 'ASC') . ",
            agenda_time ASC,
            id DESC"
        );
        foreach ($kParams as $k => $v) { $kStmt->bindValue(':' . $k, $v); }
        $kStmt->execute();
        $kegiatanRows = $kStmt->fetchAll();
        $seenKRefs = [];
        $uniqueKRows = [];
        foreach ($kegiatanRows as $r) {
            $r['title'] = str_replace('Tindak Lanjut Surat: ', '', $r['title'] ?? '');
            $refKey = trim((string)($r['reference_number'] ?? ''));
            $titleDateKey = trim((string)($r['title'] ?? '')) . '|' . trim((string)($r['agenda_date'] ?? ''));
            if ($refKey !== '' && !str_starts_with($refKey, 'AUTO-')) {
                if (isset($seenKRefs[$refKey])) {
                    continue;
                }
                $seenKRefs[$refKey] = true;
            } elseif ($titleDateKey !== '|') {
                if (isset($seenKRefs[$titleDateKey])) {
                    continue;
                }
                $seenKRefs[$titleDateKey] = true;
            }
            $uniqueKRows[] = $r;
        }
        $kegiatanRows = $uniqueKRows;

        // Filter display-level: entri yang jelas bukan acara (Nota Dinas, SK,
        // Permohonan, Pemberitahuan, Pemutakhiran, dll) tidak ditampilkan di
        // halaman publik meskipun ada di DB.
        // PENGECUALIAN: entri manual (ai_generated = 0) selalu tampil — user
        // sengaja mengisi tanggal pelaksanaannya sendiri lewat form (mis.
        // e-learning berhari-hari via online). Itu penanda manusia yang lebih
        // kuat dari filter judul.
        $kegiatanRows = array_values(array_filter($kegiatanRows, static function (array $r): bool {
            if ((int) ($r['ai_generated'] ?? 1) === 0) {
                return true; // entri manual — keputusan user, selalu tampil
            }
            $cleanMeetingId = preg_replace('/\D/', '', (string)($r['zoom_meeting_id'] ?? ''));
            $hasZoom = !empty(trim((string)($r['zoom_link'] ?? '')))
                || strlen($cleanMeetingId) >= 9
                || in_array(strtolower((string)($r['agenda_mode'] ?? '')), ['zoom', 'hybrid'], true);
            if ($hasZoom) {
                return true;
            }
            $ctx = (string) ($r['title'] ?? '') . ' ' . (string) ($r['description'] ?? '');
            return isPublicAgendaTitle((string) ($r['title'] ?? ''), $ctx);
        }));

        // Pagination SETELAH filter: total & clamp dihitung dari data bersih
        $kegiatanTotal = count($kegiatanRows);
        $kTotalPages = max(1, (int)ceil($kegiatanTotal / $kegiatanLimit));
        if ($kegiatanPage > $kTotalPages) {
            $kegiatanPage = $kTotalPages;
        }
        $kegiatanRows = array_slice($kegiatanRows, ($kegiatanPage - 1) * $kegiatanLimit, $kegiatanLimit);

        // --- 2. Query Agenda Zoom ---
        $zParams = [];
        // Entri masuk kandidat agenda zoom jika:
        // (1) entry_type = 'kegiatan' ATAU (2) surat yang belum ada child kegiatannya
        // DAN punya link/tautan, meeting ID, passcode, atau mode zoom/hybrid.
        $zBase = "FROM agendas WHERE (
            (entry_type = 'kegiatan')
            OR (entry_type = 'surat' AND NOT EXISTS (
                SELECT 1 FROM agendas c WHERE c.source_agenda_id = agendas.id AND c.entry_type = 'kegiatan'
            ))
        ) AND (
            (zoom_link IS NOT NULL AND zoom_link != '')
            OR (zoom_meeting_id IS NOT NULL AND zoom_meeting_id != '')
            OR (zoom_passcode IS NOT NULL AND zoom_passcode != '')
            OR agenda_mode IN ('zoom','hybrid')
        )";
        
        if ($zoomQ !== '') {
            // Ketika mencari Zoom, cari di semua data lintas bulan
            $zWords = array_values(array_filter(preg_split('/\s+/', $zoomQ)));
            $zConds = [];
            foreach ($zWords as $idx => $word) {
                $zp1 = "zq_{$idx}_1";
                $zp2 = "zq_{$idx}_2";
                $zp3 = "zq_{$idx}_3";
                $zp4 = "zq_{$idx}_4";
                $zp5 = "zq_{$idx}_5";
                $zp6 = "zq_{$idx}_6";
                $zp7 = "zq_{$idx}_7";
                $zp8 = "zq_{$idx}_8";
                $zp9 = "zq_{$idx}_9";
                $zp10 = "zq_{$idx}_10";
                $zp11 = "zq_{$idx}_11";
                $zp12 = "zq_{$idx}_12";
                $zp13 = "zq_{$idx}_13";
                $zp14 = "zq_{$idx}_14";

                $zw = '%' . $word . '%';
                $zParams[$zp1] = $zw;
                $zParams[$zp2] = $zw;
                $zParams[$zp3] = $zw;
                $zParams[$zp4] = $zw;
                $zParams[$zp5] = $zw;
                $zParams[$zp6] = $zw;
                $zParams[$zp7] = $zw;
                $zParams[$zp8] = $zw;
                $zParams[$zp9] = $zw;
                $zParams[$zp10] = $zw;
                $zParams[$zp11] = $zw;
                $zParams[$zp12] = $zw;
                $zParams[$zp13] = $zw;
                $zParams[$zp14] = $zw;

                $zConds[] = "(
                    agendas.title LIKE :{$zp1}
                    OR agendas.reference_number LIKE :{$zp2}
                    OR agendas.location LIKE :{$zp3}
                    OR agendas.notes LIKE :{$zp4}
                    OR agendas.description LIKE :{$zp5}
                    OR agendas.counterparty LIKE :{$zp6}
                    OR agendas.zoom_meeting_id LIKE :{$zp7}
                    OR agendas.zoom_passcode LIKE :{$zp8}
                    OR agendas.zoom_link LIKE :{$zp9}
                    OR EXISTS (
                        SELECT 1 FROM agendas p 
                        WHERE p.id = agendas.source_agenda_id 
                          AND (
                              p.reference_number LIKE :{$zp10} 
                              OR p.title LIKE :{$zp11} 
                              OR p.description LIKE :{$zp12} 
                              OR p.notes LIKE :{$zp13} 
                              OR p.counterparty LIKE :{$zp14}
                          )
                    )
                )";
            }
            if (!empty($zConds)) {
                $zBase .= " AND (" . implode(' AND ', $zConds) . ")";
            }
        } else {
            // Filter bulan & tahun jika tidak ada kata kunci pencarian dan bukan "Semua Bulan"
            if (!$isAllMonths) {
                $zBase .= " AND " . $dateFilter;
                $zParams['qms'] = $monthStart;
                $zParams['qme'] = $monthEnd;
            } else {
                $zBase .= " AND agenda_date IS NOT NULL AND agenda_date > '2000-01-01'";
            }
        }

        // Ambil SEMUA baris (tanpa LIMIT) — filter link meeting jalan
        // SEBELUM pagination (alasan sama seperti kegiatan di atas).
        $zStmt = $pdo->prepare(
            "SELECT id, title, agenda_date, agenda_date_end, agenda_time, agenda_mode, status, zoom_link, source_agenda_id, zoom_meeting_id, zoom_passcode, description, ai_generated, " .
            "COALESCE(NULLIF(agendas.reference_number, ''), (SELECT reference_number FROM agendas p WHERE p.id = agendas.source_agenda_id LIMIT 1)) AS reference_number, " .
            "(SELECT direction FROM agendas p WHERE p.id = agendas.source_agenda_id LIMIT 1) AS parent_direction, " .
            "agendas.attachment AS attachment, (SELECT p2.attachment FROM agendas p2 WHERE p2.id = agendas.source_agenda_id LIMIT 1) AS source_attachment " .
            $zBase . " ORDER BY
            CASE
                WHEN CURDATE() BETWEEN agenda_date AND COALESCE(agenda_date_end, agenda_date) THEN 1
                WHEN agenda_date >= CURDATE() THEN 2
                WHEN COALESCE(agenda_date_end, agenda_date) < CURDATE() THEN 3
                ELSE 4
            END ASC,
            CASE
                WHEN CURDATE() BETWEEN agenda_date AND COALESCE(agenda_date_end, agenda_date)
                    THEN CURDATE()
                WHEN agenda_date >= CURDATE()
                    THEN agenda_date
                ELSE COALESCE(agenda_date_end, agenda_date)
            END " . ($zoomQ !== '' ? 'DESC' : 'ASC') . ",
            agenda_time ASC,
            id DESC"
        );
        foreach ($zParams as $k => $v) { $zStmt->bindValue(':' . $k, $v); }
        $zStmt->execute();
        $zoomRows = $zStmt->fetchAll();
        $seenZRefs = [];
        $uniqueZRows = [];
        foreach ($zoomRows as $r) {
            $r['title'] = str_replace('Tindak Lanjut Surat: ', '', $r['title'] ?? '');
            $refKey = trim((string)($r['reference_number'] ?? ''));
            $titleDateKey = trim((string)($r['title'] ?? '')) . '|' . trim((string)($r['agenda_date'] ?? ''));
            if ($refKey !== '' && !str_starts_with($refKey, 'AUTO-')) {
                if (isset($seenZRefs[$refKey])) {
                    continue;
                }
                $seenZRefs[$refKey] = true;
            } elseif ($titleDateKey !== '|') {
                if (isset($seenZRefs[$titleDateKey])) {
                    continue;
                }
                $seenZRefs[$titleDateKey] = true;
            }
            $uniqueZRows[] = $r;
        }
        $zoomRows = $uniqueZRows;

        // Filter display-level: hanya link meeting ASLI yang tampil di agenda
        // Zoom publik. Shortlink pemerintah yang ternyata landing page
        // (registrasi/e-learning/upload) disaring — nota dinas dengan link
        // meeting asli tetap tampil.
        // PENGECUALIAN: entri manual (ai_generated = 0) TIDAK PERNAH diblokir
        // — punya link/tautan, meeting ID, passcode, atau mode zoom/hybrid
        // saja pun tetap masuk agenda zoom (aturan operator). Tombol Gabung
        // otomatis dibangun dari meeting ID jika link kosong.
        $zoomRows = array_values(array_filter($zoomRows, static function (array $r): bool {
            $zl = trim((string) ($r['zoom_link'] ?? ''));
            $zm = trim((string) ($r['zoom_meeting_id'] ?? ''));
            $zp = trim((string) ($r['zoom_passcode'] ?? ''));
            $mode = strtolower(trim((string) ($r['agenda_mode'] ?? '')));
            $hasOnlineMode = ($mode === 'zoom' || $mode === 'hybrid');

            if ((int) ($r['ai_generated'] ?? 1) === 0) {
                return $zl !== '' || $zm !== '' || $zp !== '' || $hasOnlineMode;
            }

            // Jika AI menetapkan mode zoom/hybrid dan terdapat tautan kegiatan, meeting ID, atau passcode
            if ($hasOnlineMode && ($zl !== '' || $zm !== '' || $zp !== '')) {
                return true;
            }

            $cleanMeetingId = preg_replace('/\D/', '', $zm);
            if (strlen($cleanMeetingId) >= 9) {
                return true;
            }
            $ctx = (string) ($r['title'] ?? '') . ' ' . (string) ($r['description'] ?? '');
            return isPublicMeetingLink($r['zoom_link'] ?? null, $ctx);
        }));

        // Pagination SETELAH filter: total & clamp dihitung dari data bersih
        $zoomTotal = count($zoomRows);
        $zTotalPages = max(1, (int)ceil($zoomTotal / $zoomLimit));
        if ($zoomPage > $zTotalPages) {
            $zoomPage = $zTotalPages;
        }
        $zoomRows = array_slice($zoomRows, ($zoomPage - 1) * $zoomLimit, $zoomLimit);

    } catch (Exception $e) {
        error_log('[index.php] Gagal mengambil agenda publik: ' . $e->getMessage());
        $publicAgendaError = true;
    }
}

// Helper: apakah surat asal/agenda ini punya file lampiran yang bisa dibuka.
// Dipakai agar tombol "Lihat Surat Masuk/Keluar" tidak tampil (dan tidak
// memunculkan halaman "Lampiran Tidak Tersedia") bila lampirannya kosong.
function publicHasLetterAttachment(array $r): bool {
    return trim((string)($r['source_attachment'] ?? '')) !== ''
        || trim((string)($r['attachment'] ?? '')) !== '';
}

// Helper: badge status jadwal Zoom
function publicZoomBadge(string $date, ?string $endDate = null): array {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $effectiveEnd = !empty($endDate) && $endDate !== '0000-00-00' ? $endDate : $date;
    if ($effectiveEnd < $date) {
        $effectiveEnd = $date;
    }

    if (empty($date) || $date === '0000-00-00') {
        return ['label' => 'Direncanakan', 'class' => 'text-white font-bold border-0 shadow-sm badge-direncanakan'];
    }

    // Selesai jika tanggal akhir sudah lewat sebelum hari ini
    if ($effectiveEnd < $today) {
        return ['label' => 'Selesai', 'class' => 'text-white font-bold border-0 shadow-sm badge-selesai'];
    }

    // Rentang multi-hari yang sedang berjalan (mulai di masa lalu, berakhir setelah hari ini)
    if ($date < $today && $effectiveEnd > $today) {
        return ['label' => 'Berlangsung', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-berlangsung'];
    }

    // Hari ini jika tepat hari ini (single day) atau hari terakhir rentang kegiatan
    if ($date === $today || $effectiveEnd === $today) {
        return ['label' => 'Hari Ini', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-hari-ini'];
    }

    if ($date === $tomorrow) {
        return ['label' => 'Besok', 'class' => 'text-white font-bold border-0 shadow-sm badge-besok'];
    }

    if ($date > $tomorrow) {
        return ['label' => 'Mendatang', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-mendatang'];
    }

    return ['label' => 'Selesai', 'class' => 'text-white font-bold border-0 shadow-sm badge-selesai'];
}

// Helper: badge status jadwal Kegiatan
function publicKegiatanStatusBadge(string $startDate, ?string $endDate = null): ?array {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $effectiveEnd = !empty($endDate) && $endDate !== '0000-00-00' ? $endDate : $startDate;
    if ($effectiveEnd < $startDate) {
        $effectiveEnd = $startDate;
    }

    if (empty($startDate) || $startDate === '0000-00-00') {
        return null;
    }

    // Selesai jika tanggal akhir sudah lewat sebelum hari ini
    if ($effectiveEnd < $today) {
        return ['label' => 'Selesai', 'class' => 'text-white font-bold border-0 shadow-sm badge-selesai'];
    }

    // Rentang multi-hari yang sedang berjalan (mulai di masa lalu, berakhir setelah hari ini)
    if ($startDate < $today && $effectiveEnd > $today) {
        return ['label' => 'Berlangsung', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-berlangsung'];
    }

    // Hari ini jika tepat hari ini (single day) atau hari terakhir rentang kegiatan
    if ($startDate === $today || $effectiveEnd === $today) {
        return ['label' => 'Hari Ini', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-hari-ini'];
    }

    if ($startDate === $tomorrow) {
        return ['label' => 'Besok', 'class' => 'text-white font-bold border-0 shadow-sm badge-besok'];
    }

    if ($startDate > $tomorrow) {
        return ['label' => 'Mendatang', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-mendatang'];
    }

    return ['label' => 'Selesai', 'class' => 'text-white font-bold border-0 shadow-sm badge-selesai'];
}

// Helper: format waktu HH:MM
function publicTimeLabel(?string $time): string {
    $t = trim((string)$time);
    if ($t === '' || $t === '00:00:00') return '09:00';
    return substr($t, 0, 5);
}

// Helper: nama hari Indonesia
function publicHariIndo(string $date): string {
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $hari[(int)date('w', strtotime($date))];
}

// Helper: badge mode kegiatan
function publicModeBadge(string $mode): array {
    return match(strtolower($mode)) {
        'zoom'   => ['label' => 'Zoom',   'class' => 'bg-rose-100 text-rose-700 border border-rose-300 dark:bg-rose-600 dark:text-rose-100 dark:border-rose-500'],
        'hybrid' => ['label' => 'Hybrid', 'class' => 'bg-klime-100 text-klime-700 border border-klime-300 dark:bg-klime-500 dark:text-klime-100 dark:border-klime-400'],
        default  => ['label' => 'Offline','class' => 'bg-brand-100 text-brand-700 border border-brand-300 dark:bg-[#00b294] dark:text-brand-100 dark:border-brand-500'],
    };
}

// Helper: validasi URL zoom
function publicValidUrl(?string $url): ?string {
    $v = trim((string)$url);
    if ($v === '') return null;
    $v = preg_replace('/us[oO](\d)web\.zoom\.us/i', 'us0$1web.zoom.us', $v);
    $v = preg_replace('/\s+/', '', $v);
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    // Validasi longgar: pastikan ada scheme + host. Tidak pakai filter_var
    // karena menolak karakter legitimate di path (kurung, dll).
    $parts = parse_url($v);
    if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    if (!isset($parts['host']) || $parts['host'] === '') {
        return null;
    }
    return $v;
}

/**
 * Build URL Zoom dari meeting ID (format "123 456 7890" / "123-456-7890")
 * + passcode opsional. Format: https://zoom.us/j/1234567890?pwd=PASSCODE
 */
function publicBuildZoomUrlFromId(string $meetingId, string $passcode = ''): ?string {
    $digits = preg_replace('/\D/', '', $meetingId);
    if ($digits === '' || strlen($digits) < 9) return null;
    $url = 'https://zoom.us/j/' . $digits;
    if ($passcode !== '') {
        $url .= '?pwd=' . urlencode($passcode);
    }
    return $url;
}
?>
<!doctype html>
<html lang="id">
<head>
    <!-- build:2026-09-10-r6 (manual: kegiatan selalu tampil; zoom bila ada link/ID/passcode; filter sebelum pagination) -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beranda &mdash; Agenda Pimpinan BBLKL</title>
    <meta name="description" content="Sistem arsip digital agenda surat dan kegiatan pimpinan Balai Besar Laboratorium Kesehatan Lingkungan.">
    <meta property="og:title" content="Agenda Pimpinan BBLKL">
    <meta property="og:description" content="Sistem arsip digital agenda surat dan kegiatan pimpinan BBLKL.">
    <meta property="og:type" content="website">
    <link rel="icon" href="assets/logo.png?v=2.2.1" type="image/png" sizes="32x32">
    <link rel="stylesheet" href="assets/css/kemenkes.css?v=1782826600">
    <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/fonts.css?v=2.2.1">

    <link rel="stylesheet" href="assets/ui.css?v=1.0.3">
    <style>
        /* Custom Tailwind Colors for Badges */
        .bg-emerald-600 { background-color: #059669 !important; }
        .hover\:bg-emerald-700:hover { background-color: #047857 !important; }
        .text-emerald-100 { color: #d1fae5 !important; }

        .bg-amber-600 { background-color: #d97706 !important; }
        .hover\:bg-amber-700:hover { background-color: #b45309 !important; }
        .text-amber-100 { color: #fef3c7 !important; }

        /* Badge Agenda Hari Ini di Header Kalender Mini */
        .badge-header-hari-ini {
            background-color: #e0e7ff !important;
            color: #3730a3 !important;
            border-color: #a5b4fc !important;
        }
        .dark .badge-header-hari-ini {
            background-color: rgba(49, 46, 129, 0.45) !important;
            color: #e0e7ff !important;
            border-color: rgba(129, 140, 248, 0.35) !important;
        }
        .dark .badge-header-hari-ini strong {
            color: #ffffff !important;
        }
        .badge-header-hari-ini .badge-dot-ping {
            background-color: #818cf8 !important;
        }
        .badge-header-hari-ini .badge-dot-core {
            background-color: #4f46e5 !important;
        }
        .dark .badge-header-hari-ini .badge-dot-core {
            background-color: #818cf8 !important;
        }

        /* Badges Statis Agenda */
        .badge-direncanakan,
        .badge-selesai {
            background: linear-gradient(to right, #94a3b8, #6b7280) !important;
        }
        .badge-besok {
            background: linear-gradient(to right, #22d3ee, #3b82f6) !important;
        }

        /* Badges Bergerak & Dinamis (Berlangsung, Hari Ini, Mendatang) */
        .badge-moving {
            position: relative !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            background-size: 250% 250% !important;
            will-change: transform, background-position, box-shadow;
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            letter-spacing: 0.02em;
        }
        .badge-moving:hover {
            transform: translateY(-4px) scale(1.08) !important;
        }

        /* 1. Berlangsung (Emerald-Teal) */
        .badge-moving-berlangsung {
            background: linear-gradient(135deg, #059669, #10b981, #0d9488, #34d399, #059669) !important;
            animation: badgeFloatBerlangsung 2.4s ease-in-out infinite, badgeGradientShift 4s ease infinite, badgeGlowBerlangsung 2.4s ease-in-out infinite !important;
        }

        /* 2. Hari Ini (Vibrant Sunset Orange) */
        .badge-moving-hari-ini {
            background: linear-gradient(135deg, #ea580c, #f97316, #fb923c, #f59e0b, #ea580c) !important;
            animation: badgeFloatHariIni 1.9s ease-in-out infinite, badgeGradientShift 3.2s ease infinite, badgeGlowHariIni 1.9s ease-in-out infinite !important;
        }

        /* 3. Mendatang (Purple-Fuchsia) */
        .badge-moving-mendatang {
            background: linear-gradient(135deg, #7c3aed, #a855f7, #d946ef, #ec4899, #7c3aed) !important;
            animation: badgeFloatMendatang 2.8s ease-in-out infinite, badgeGradientShift 4.5s ease infinite, badgeGlowMendatang 2.8s ease-in-out infinite !important;
        }

        /* Efek Kilau Cahaya Bergerak (Shimmer Light Sweep) */
        .badge-moving::after {
            content: '';
            position: absolute;
            top: 0;
            left: -120%;
            width: 70%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent);
            transform: skewX(-20deg);
            animation: badgeShimmerSweep 3.2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
            pointer-events: none;
        }

        /* Keyframes Gerak Naik-Turun (Floating Motion) */
        @keyframes badgeFloatBerlangsung {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-2.5px);
            }
        }
        @keyframes badgeFloatHariIni {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-3px);
            }
        }
        @keyframes badgeFloatMendatang {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-2px);
            }
        }

        /* Keyframes Pergerakan Aliran Gradien Warna */
        @keyframes badgeGradientShift {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        /* Keyframes Pendaran Cahaya (Glow Pulse) */
        @keyframes badgeGlowBerlangsung {
            0%, 100% {
                box-shadow: 0 1px 4px rgba(16, 185, 129, 0.35);
            }
            50% {
                box-shadow: 0 4px 14px rgba(16, 185, 129, 0.65), 0 0 0 2px rgba(52, 211, 153, 0.3);
            }
        }
        @keyframes badgeGlowHariIni {
            0%, 100% {
                box-shadow: 0 1px 4px rgba(234, 88, 12, 0.35);
            }
            50% {
                box-shadow: 0 4px 14px rgba(249, 115, 22, 0.75), 0 0 0 2px rgba(251, 146, 60, 0.4);
            }
        }
        @keyframes badgeGlowMendatang {
            0%, 100% {
                box-shadow: 0 1px 4px rgba(217, 70, 239, 0.35);
            }
            50% {
                box-shadow: 0 4px 14px rgba(217, 70, 239, 0.65), 0 0 0 2px rgba(168, 85, 247, 0.3);
            }
        }

        /* Keyframes Shimmer Sweep */
        @keyframes badgeShimmerSweep {
            0%, 20% {
                left: -120%;
            }
            60%, 100% {
                left: 200%;
            }
        }

        /* Tanggal Hari Ini di Kalender Mini Bergerak & Menarik Perhatian */
        .calendar-today-cell {
            position: relative !important;
            background: linear-gradient(135deg, #4f46e5, #6366f1, #7c3aed, #8b5cf6, #4f46e5) !important;
            background-size: 250% 250% !important;
            overflow: hidden !important;
            z-index: 10 !important;
            will-change: transform, box-shadow, background-position;
            animation: calendarTodayFloat 2.2s ease-in-out infinite, calendarTodayGradient 4s ease infinite, calendarTodayGlow 2.2s ease-in-out infinite !important;
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
        }

        .calendar-today-cell:hover {
            transform: translateY(-4px) scale(1.08) !important;
            opacity: 1 !important;
        }

        /* Shimmer beam sweep melintasi tanggal hari ini */
        .calendar-today-cell::after {
            content: '';
            position: absolute;
            top: 0;
            left: -120%;
            width: 70%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent);
            transform: skewX(-20deg);
            animation: calendarTodayShimmer 3s cubic-bezier(0.4, 0, 0.2, 1) infinite;
            pointer-events: none;
        }

        /* Keyframes Gerak Melayang Naik-Turun (Floating) */
        @keyframes calendarTodayFloat {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-3px);
            }
        }

        /* Keyframes Pergerakan Aliran Gradien */
        @keyframes calendarTodayGradient {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        /* Keyframes Pendaran Cahaya & Denyut Halo (Pulsing Glow & Radar Ripple) */
        @keyframes calendarTodayGlow {
            0%, 100% {
                box-shadow: 0 2px 8px rgba(79, 70, 229, 0.4), 0 0 0 0 rgba(99, 102, 241, 0.4);
            }
            50% {
                box-shadow: 0 6px 18px rgba(79, 70, 229, 0.75), 0 0 0 4px rgba(129, 140, 248, 0.35);
            }
        }

        /* Keyframes Shimmer Sweep */
        @keyframes calendarTodayShimmer {
            0%, 20% {
                left: -120%;
            }
            60%, 100% {
                left: 200%;
            }
        }

        /* Custom responsive classes (karena Tailwind CSS statis) */
        .grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        .grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
        @media (min-width: 1024px) {
            .custom-lg-block { display: block !important; }
            .custom-lg-hidden { display: none !important; }
            .custom-lg-items-start { align-items: start !important; }
            .custom-lg-self-start { align-self: start !important; }
            .custom-lg-sticky { position: sticky !important; }
        }
        @media (max-width: 1023px) {
            .custom-mobile-hidden { display: none !important; }
            .custom-mobile-block { display: block !important; }
            .custom-mobile-reverse { display: flex !important; flex-direction: column-reverse !important; gap: 2rem; }
        }

        /* Skip link */
        .skip-link {
            position: absolute;
            top: -100%;
            left: 1rem;
            z-index: 9999;
            padding: 0.5rem 1rem;
            background: #0ea5e9;
            color: #fff;
            border-radius: 0 0 0.5rem 0.5rem;
            font-weight: 700;
            font-size: 0.875rem;
            transition: top 0.2s;
            text-decoration: none;
        }
        .skip-link:focus {
            top: 0;
        }

        /* Entrance animation */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out both;
        }

        /* Feature card staggered animation */
        .feature-card {
            opacity: 0;
            transform: translateY(16px);
            transition: opacity 0.4s ease-out, transform 0.4s ease-out, border-color 0.2s ease-out;
        }
        .feature-card.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .feature-card:hover {
            transform: translateY(-4px);
            border-color: var(--fc-hover-color, rgba(255,255,255,0.2));
        }

        /* Skeleton shimmer */
        .skeleton-pulse {
            background: linear-gradient(90deg, #1e293b 25%, #334155 50%, #1e293b 75%);
            background-size: 200% 100%;
            animation: skeleton-shimmer 1.5s infinite;
            border-radius: 0.5rem;
        }
        @keyframes skeleton-shimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            .animate-fade-in-up,
            .feature-card,
            .feature-card.visible,
            .badge-moving,
            .badge-moving::after,
            .calendar-today-cell,
            .calendar-today-cell::after {
                animation: none !important;
                transition: none !important;
                opacity: 1 !important;
                transform: none !important;
            }
            .skeleton-pulse {
                animation: none !important;
            }
        }

        /* Fix 7: Smooth scroll */
        html {
            scroll-behavior: smooth;
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
        }

        /* Fix 8: Hero bottom wave divider */
        .hero-wave {
            display: block;
            width: 100%;
            overflow: hidden;
            line-height: 0;
        }

        /* Fix 12: Back to top button */
        #back-to-top {
            position: fixed;
            bottom: 5rem;
            right: 1.5rem;
            z-index: 40;
            opacity: 0;
            transform: translateY(10px);
            pointer-events: none;
            transition: opacity 0.3s, transform 0.3s;
        }
        #back-to-top.visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        /* Fix #7: feedback klik tanggal kalender */
        [x-data] [class*="cursor-pointer"]:active {
            transform: scale(0.88);
            transition: transform 0.1s ease;
        }

        /* SweetAlert2 & Mini Calendar Modal Dark Mode */
        .dark .swal2-popup {
            background-color: #1e293b !important;
            color: #f8fafc !important;
            border: 1px solid #334155 !important;
        }
        .dark .swal2-title {
            color: #f8fafc !important;
        }
        .dark .swal2-html-container {
            color: #cbd5e1 !important;
        }
        .dark .calendar-event-card {
            background-color: #0f172a !important;
            border-color: #334155 !important;
        }
        .dark .calendar-event-card:hover {
            border-color: #475569 !important;
            background-color: #1e293b !important;
        }
        .dark .calendar-event-title {
            color: #f8fafc !important;
        }
        .dark .calendar-event-meta {
            color: #94a3b8 !important;
        }
        .dark .calendar-event-divider {
            border-color: #334155 !important;
        }
        .dark .calendar-event-link {
            color: #38bdf8 !important;
        }
        .dark .calendar-empty-text {
            color: #cbd5e1 !important;
        }
        .dark .calendar-empty-subtext {
            color: #64748b !important;
        }
        /* Month & Date picker calendar icon styling */
        html.dark input[type="month"],
        html.dark input[type="date"],
        .dark input[type="month"],
        .dark input[type="date"] {
            color-scheme: dark !important;
        }

        html.dark input[type="month"]::-webkit-calendar-picker-indicator,
        html.dark input[type="date"]::-webkit-calendar-picker-indicator,
        .dark input[type="month"]::-webkit-calendar-picker-indicator,
        .dark input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer !important;
            opacity: 0.95 !important;
        }

        html.dark input[type="month"]::-webkit-calendar-picker-indicator:hover,
        html.dark input[type="date"]::-webkit-calendar-picker-indicator:hover,
        .dark input[type="month"]::-webkit-calendar-picker-indicator:hover,
        .dark input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1 !important;
        }

        html:not(.dark) input[type="month"],
        html:not(.dark) input[type="date"] {
            color-scheme: light !important;
        }

        /* Section header icon boxes */
        .bg-cyan-100 { background-color: #cffafe !important; }
        .text-cyan-600 { color: #0891b2 !important; }
        html.dark .bg-cyan-100 { background-color: rgba(6, 182, 212, 0.2) !important; }
        html.dark .text-cyan-600,
        html.dark .text-cyan-400 { color: #22d3ee !important; }

        .bg-rose-100 { background-color: #ffe4e6 !important; }
        .text-rose-600 { color: #e11d48 !important; }
        html.dark .bg-rose-100 { background-color: rgba(244, 63, 94, 0.2) !important; }
        html.dark .text-rose-600,
        html.dark .text-rose-400 { color: #fb7185 !important; }
    </style>
    <script>
        if (localStorage.getItem('darkMode') === 'true' || (!('darkMode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
        function agendaMonthPicker(config) {
            return {
                open: false,
                selectedVal: config.initialVal || '',
                pickerYear: parseInt(config.initialYear, 10) || (new Date()).getFullYear(),
                currentYear: (new Date()).getFullYear(),
                currentMonth: (new Date()).getMonth() + 1,
                theme: config.theme || 'cyan',
                monthsList: ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'],
                fullMonths: ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
                get displayText() {
                    if (this.selectedVal === 'all') {
                        return 'Semua Bulan';
                    }
                    var parts = (this.selectedVal || '').split('-');
                    if (parts.length === 2) {
                        var y = parseInt(parts[0], 10);
                        var m = parseInt(parts[1], 10);
                        if (m >= 1 && m <= 12) {
                            return this.fullMonths[m] + ' ' + y;
                        }
                    }
                    return this.fullMonths[this.currentMonth] + ' ' + this.currentYear;
                },
                isSelected(mNum) {
                    var mStr = String(mNum).padStart(2, '0');
                    return this.selectedVal === (this.pickerYear + '-' + mStr);
                },
                isCurrent(mNum) {
                    return this.pickerYear === this.currentYear && mNum === this.currentMonth;
                },
                selectMonth(mNum) {
                    var mStr = String(mNum).padStart(2, '0');
                    this.selectedVal = this.pickerYear + '-' + mStr;
                    this.open = false;
                    this.$nextTick(() => {
                        var form = this.$el.closest('form');
                        if (form) {
                            if (form.__x && form.__x.$data) form.__x.$data.isSearching = true;
                            form.submit();
                        }
                    });
                },
                selectAll() {
                    this.selectedVal = 'all';
                    this.open = false;
                    this.$nextTick(() => {
                        var form = this.$el.closest('form');
                        if (form) {
                            if (form.__x && form.__x.$data) form.__x.$data.isSearching = true;
                            form.submit();
                        }
                    });
                }
            };
        }
    </script>
    <script defer src="assets/js/alpine.min.js"></script>
    <script defer src="assets/js/sweetalert2.all.min.js"></script>
</head>
<body class="landing-shell bg-slate-50 dark:bg-slate-950 font-sans text-slate-900 dark:text-slate-100">

    <!-- Skip Link -->
    <a href="#main-content" class="skip-link">Lewati navigasi</a>

    <!-- Header -->
    <header role="banner" class="sticky top-0 z-50 backdrop-blur-md bg-white/90 dark:bg-slate-950/90 border-b border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">

            <!-- Logo + Nama Instansi -->
            <a href="index.php" aria-label="Beranda Agenda Pimpinan BBLKL"
               class="flex items-center gap-3 group">
                <!-- Logo box -->
                <div class="flex-shrink-0 flex items-center justify-center h-16 sm:h-20 w-auto px-2 sm:px-3 rounded-2xl bg-white border border-slate-200 dark:border-white/10 shadow-md group-hover:shadow-xl group-hover:-translate-y-1 transition-all duration-300 overflow-hidden">
                    <img src="assets/logo.png"
                         alt="Logo Balai Besar Laboratorium Kesehatan Lingkungan"
                         class="h-12 sm:h-16 w-auto object-contain scale-110 group-hover:scale-[1.15] transition-transform duration-300">
                </div>
                <!-- Teks -->
                <div class="flex flex-col min-w-0">
                    <span class="text-xl sm:text-3xl font-black tracking-tight text-slate-900 dark:text-white leading-tight">
                        Agenda Pimpinan
                    </span>
                    <span class="text-[10px] sm:text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest leading-tight hidden sm:block mt-0.5">
                        Balai Besar Laboratorium Kesehatan Lingkungan
                    </span>
                    <!-- Badge instansi untuk mobile -->
                    <span class="text-[9px] font-bold text-kcyan-600 dark:text-kcyan-400 uppercase tracking-wider sm:hidden">
                        BBLKL
                    </span>
                </div>
            </a>

            <!-- Kanan: datetime + nav + toggle + login -->
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <!-- Datetime: hanya lg ke atas -->
                <div class="hidden lg:flex flex-col items-end leading-tight mr-2 border-r border-slate-200 dark:border-white/10 pr-4"
                     aria-live="polite" aria-label="Tanggal dan waktu saat ini">
                    <span id="hdr-datetime" class="text-sm font-bold text-slate-800 dark:text-slate-100 tabular-nums"></span>
                    <span id="hdr-date" class="text-[11px] text-slate-500 dark:text-slate-400"></span>
                </div>
                <!-- Link Jadwal -->
                <a href="#agenda-kegiatan"
                   class="hidden sm:inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 transition-colors duration-200 min-h-[44px]">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/>
                    </svg>
                    Jadwal
                </a>
                <!-- Dark mode toggle -->
                <button type="button"
                        id="dark-toggle"
                        onclick="const isDark=document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',isDark);this.setAttribute('aria-label',isDark?'Aktifkan mode terang':'Aktifkan mode gelap');"
                        aria-label="Aktifkan mode gelap"
                        title="Ganti tema tampilan"
                        class="hidden sm:inline-flex items-center justify-center w-10 h-10 rounded-xl text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm transition-all duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] hidden dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                </button>
                <!-- Tombol Login &mdash; lebih menonjol -->
                <a href="login.php"
                   aria-label="Masuk ke sistem"
                   class="hidden sm:inline-flex items-center gap-2 rounded-xl bg-kcyan-500 hover:bg-kcyan-400 text-slate-950 font-bold px-5 py-2.5 text-sm min-h-[44px] transition-colors duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 shadow-md shadow-cyan-500/20">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
                    Login
                </a>
            </div>
        </div>
    </header>

    <main>
        <!-- Hero Section -->
        <div class="relative overflow-hidden">
            <div class="absolute inset-0
                bg-[radial-gradient(circle_at_20%_20%,rgba(14,165,233,0.18),transparent_50%),radial-gradient(circle_at_80%_0%,rgba(11,87,208,0.22),transparent_45%),linear-gradient(160deg,#dbeafe,#e0f2fe_40%,#f0f9ff)]
                dark:bg-[radial-gradient(circle_at_20%_20%,rgba(14,165,233,0.35),transparent_45%),radial-gradient(circle_at_80%_0%,rgba(11,87,208,0.4),transparent_35%),linear-gradient(160deg,#020617,#0f172a_50%,#082f49)]">
            </div>

            <section id="main-content" class="relative z-10 mx-auto grid max-w-7xl gap-8 px-4 pb-8 pt-8 sm:px-6 lg:grid-cols-2 lg:px-8 lg:pb-12 lg:pt-16">
                <div>
                    <p class="animate-fade-in-up inline-flex rounded-full border border-kcyan-400 dark:border-kcyan-300/30 bg-kcyan-600/10 dark:bg-kcyan-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-kcyan-700 dark:text-kcyan-200" style="animation-delay: 0ms;">
                        Sistem Arsip Digital Sekretaris
                    </p>
                    <h1 class="animate-fade-in-up mt-5 text-4xl lg:text-5xl font-extrabold leading-tight text-slate-900 dark:text-slate-100" style="animation-delay: 100ms;">
                        Kelola Agenda Surat dan Kegiatan Pimpinan Lebih Cepat
                    </h1>
                    <p class="animate-fade-in-up mt-5 max-w-xl text-slate-700 dark:text-slate-300" style="animation-delay: 200ms;">
                        Satu dashboard untuk mencatat surat masuk/keluar, jadwal kegiatan, lampiran dokumen, dan laporan ekspor dengan tampilan modern yang nyaman dipakai dari laptop maupun HP.
                    </p>
                    <div class="animate-fade-in-up mt-8 flex flex-wrap gap-3" style="animation-delay: 300ms;">
                        <a href="login.php"
                           class="bg-kcyan-500 hover:bg-kcyan-400 text-slate-950 font-bold rounded-xl px-5 py-3 text-sm min-h-[44px] transition-colors duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 flex items-center">
                            Masuk ke Sistem
                        </a>
                        <a href="#agenda-kegiatan" class="flex items-center rounded-xl border border-slate-300 dark:border-white/30 px-5 py-3 text-sm font-semibold hover:bg-slate-100 dark:hover:bg-white/10 min-h-[44px] transition-colors duration-200">Lihat Jadwal</a>
                        <a href="#fitur" class="hidden sm:flex items-center rounded-xl border border-slate-300 dark:border-white/30 px-5 py-3 text-sm font-semibold hover:bg-slate-100 dark:hover:bg-white/10 min-h-[44px] transition-colors duration-200">Lihat Fitur</a>
                    </div>
                </div>
                <!-- Preview Stats: dengan tab Bulan Ini / Semua Waktu -->
                <?php ob_start(); ?>
                <div id="stats-section" class="flex flex-col rounded-3xl overflow-hidden border border-slate-200 dark:border-white/10 shadow-lg dark:shadow-none bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900"
                     x-data="{ tab: '<?= $tabDefault ?>' }">
                    <!-- Header bar -->
                    <div class="flex items-center justify-between px-5 pt-4 pb-2">
                        <div class="flex items-center gap-2">
                            <span class="flex h-2 w-2 rounded-full bg-brand-400 animate-pulse"></span>
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Ringkasan Statistik</p>
                        </div>
                    </div>
                    <!-- Tab toggle -->
                    <div class="px-5 pb-3 flex gap-1">
                        <button @click="tab='semua'"
                                :class="tab==='semua' ? 'bg-slate-500/20 text-slate-200 border-slate-500/40' : 'text-slate-500 border-transparent hover:text-slate-300'"
                                class="flex-1 rounded-lg border px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide transition-all duration-200">
                            Semua Bulan
                        </button>
                        
                        <div :class="tab==='bulan' ? 'bg-kcyan-500/20 text-kcyan-300 border-kcyan-500/40' : 'text-slate-500 border-transparent hover:text-slate-300'"
                             class="flex-1 flex items-center justify-between rounded-lg border px-1 py-1 transition-all duration-200">
                             
                            <a href="?m=<?= $prevMonth ?>&y=<?= $prevYear ?>#stats-section" class="p-1 hover:text-white transition-colors" title="Bulan Sebelumnya">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                            </a>
                            
                            <button @click="tab='bulan'" class="flex-1 text-[11px] font-bold uppercase tracking-wide text-center h-full">
                                <?= $bulanIni . ' ' . $tahunIni ?>
                            </button>
                            
                            <a href="?m=<?= $nextMonth ?>&y=<?= $nextYear ?>#stats-section" class="p-1 hover:text-white transition-colors" title="Bulan Selanjutnya">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                            </a>
                        </div>
                    </div>
                    <!-- Skeleton loader -->
                    <div class="stats-skeleton px-5 pb-4 grid gap-2 grid-cols-2" style="display:none;" aria-hidden="true">
                        <div class="skeleton-pulse rounded-2xl h-20"></div>
                        <div class="skeleton-pulse rounded-2xl h-20"></div>
                        <div class="skeleton-pulse rounded-2xl h-20"></div>
                        <div class="skeleton-pulse rounded-2xl h-20"></div>
                    </div>

                    <div class="stats-content flex-1 flex flex-col">
                    <!-- Tab Bulan Ini -->
                    <div x-show="tab==='bulan'" class="px-5 pb-4 grid gap-2 grid-cols-2 flex-1">
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-kcyan-500/20 to-kcyan-600/10 border border-kcyan-500/20 p-4 cursor-default flex flex-col justify-between hover:from-kcyan-500/30 hover:border-kcyan-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-cyan-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-kcyan-400/10"></div>
                            <svg class="mb-2 h-5 w-5 text-kcyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 01-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 001.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 00-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V8.844a2.25 2.25 0 011.183-1.98l7.5-4.04a2.25 2.25 0 012.134 0l7.5 4.04a2.25 2.25 0 011.183 1.98V19.5z"/></svg>
                            <p class="text-[11px] font-semibold text-kcyan-300/70 uppercase tracking-wide leading-tight">Surat Masuk</p>
                            <p class="mt-1 text-3xl font-black text-kcyan-300 tabular-nums leading-tight"><?= $dbError ? '&mdash;' : $masukBulanIni ?></p>
                        </div>
                        <div class="group relative overflow-hidden rounded-2xl <?= (!$dbError && $keluarBulanIni === 0) ? 'bg-gradient-to-br from-slate-600/20 to-slate-700/10 border-slate-600/20' : 'bg-gradient-to-br from-kcyan-500/20 to-kcyan-600/10 border-kcyan-500/20 hover:from-kcyan-500/30 hover:border-kcyan-400/40' ?> border p-4 cursor-default flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-cyan-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-kcyan-400/10"></div>
                            <svg class="mb-2 h-5 w-5 <?= (!$dbError && $keluarBulanIni === 0) ? 'text-slate-500' : 'text-kcyan-400' ?>" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                            <p class="text-[11px] font-semibold <?= (!$dbError && $keluarBulanIni === 0) ? 'text-slate-500' : 'text-kcyan-300/70' ?> uppercase tracking-wide leading-tight">Surat Keluar</p>
                            <p class="mt-1 text-3xl font-black <?= (!$dbError && $keluarBulanIni === 0) ? 'text-slate-500' : 'text-kcyan-300' ?> tabular-nums leading-tight"><?= $dbError ? '&mdash;' : $keluarBulanIni ?></p>
                        </div>
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-500/20 to-brand-600/10 border border-brand-500/20 p-4 cursor-default flex flex-col justify-between hover:from-brand-500/30 hover:border-brand-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-teal-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-brand-400/10"></div>
                            <svg class="mb-2 h-5 w-5 text-brand-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/></svg>
                            <p class="text-[11px] font-semibold text-brand-300/70 uppercase tracking-wide leading-tight">Kegiatan</p>
                            <p class="mt-1 text-3xl font-black text-brand-300 tabular-nums leading-tight"><?= $dbError ? '&mdash;' : $kegiatanBulanIni ?></p>
                        </div>
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-rose-500/20 to-rose-600/10 border border-rose-500/20 p-4 cursor-default flex flex-col justify-between hover:from-rose-500/30 hover:border-rose-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-rose-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-rose-500/10"></div>
                            <svg class="mb-2 h-5 w-5 text-rose-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                            <p class="text-[11px] font-semibold text-rose-300/70 uppercase tracking-wide leading-tight">Zoom</p>
                            <p class="mt-1 text-3xl font-black text-rose-300 tabular-nums leading-tight"><?= $dbError ? '&mdash;' : $zoomBulanIni ?></p>
                        </div>
                    </div>

                    <!-- Tab Semua Bulan -->
                    <div x-show="tab==='semua'" x-cloak class="px-5 pb-4 grid gap-2 grid-cols-2 flex-1">
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-kcyan-500/20 to-kcyan-600/10 border border-kcyan-500/20 p-4 cursor-default flex flex-col justify-between hover:from-kcyan-500/30 hover:border-kcyan-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-cyan-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-kcyan-400/10"></div>
                            <svg class="mb-2 h-5 w-5 text-kcyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 01-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 001.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 00-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V8.844a2.25 2.25 0 011.183-1.98l7.5-4.04a2.25 2.25 0 012.134 0l7.5 4.04a2.25 2.25 0 011.183 1.98V19.5z"/></svg>
                            <p class="text-[11px] font-semibold text-kcyan-300/70 uppercase tracking-wide leading-tight">Agenda Masuk</p>
                            <p class="mt-1 text-3xl font-black text-kcyan-300 tabular-nums leading-tight" aria-live="polite"><?= $dbError ? '&mdash;' : $totalMasuk ?></p>
                        </div>
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-kcyan-500/20 to-kcyan-600/10 border border-kcyan-500/20 p-4 cursor-default flex flex-col justify-between hover:from-kcyan-500/30 hover:border-kcyan-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-cyan-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-kcyan-400/10"></div>
                            <svg class="mb-2 h-5 w-5 text-kcyan-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                            <p class="text-[11px] font-semibold text-kcyan-300/70 uppercase tracking-wide leading-tight">Agenda Keluar</p>
                            <p class="mt-1 text-3xl font-black text-kcyan-300 tabular-nums leading-tight" aria-live="polite"><?= $dbError ? '&mdash;' : $totalKeluar ?></p>
                        </div>
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-500/20 to-brand-600/10 border border-brand-500/20 p-4 cursor-default flex flex-col justify-between hover:from-brand-500/30 hover:border-brand-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-teal-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-brand-400/10"></div>
                            <svg class="mb-2 h-5 w-5 text-brand-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            <p class="text-[11px] font-semibold text-brand-300/70 uppercase tracking-wide leading-tight">Dokumen Arsip</p>
                            <p class="mt-1 text-3xl font-black text-brand-300 tabular-nums leading-tight" aria-live="polite"><?= $dbError ? '&mdash;' : $totalDokumen ?></p>
                        </div>
                        <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-klime-500/20 to-klime-600/10 border border-klime-500/20 p-4 cursor-default flex flex-col justify-between hover:from-klime-500/30 hover:border-klime-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-lime-500/20">
                            <div class="absolute -right-3 -top-3 h-12 w-12 rounded-full bg-klime-400/10"></div>
                            <svg class="mb-2 h-5 w-5 text-klime-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122"/></svg>
                            <p class="text-[11px] font-semibold text-klime-300/70 uppercase tracking-wide leading-tight">Total Agenda</p>
                            <p class="mt-1 text-3xl font-black text-klime-300 tabular-nums leading-tight" aria-live="polite"><?= $dbError ? '&mdash;' : $totalAgenda ?></p>
                        </div>
                    </div>
                    </div>
                    <?php if ($allZero): ?>
                    <p class="px-5 pb-3 text-xs text-slate-500 text-center">Belum ada data agenda tercatat</p>
                    <?php endif; ?>
                    <!-- Footer hint -->
                    <div class="mt-auto border-t border-white/5 px-5 py-3 flex items-center justify-between">
                        <p class="text-[10px] text-slate-600">Data diperbarui setiap halaman dimuat</p>
                        <a href="login.php" class="text-[11px] font-semibold text-kcyan-400 hover:text-kcyan-300 transition-colors">Kelola →</a>
                    </div>
                </div>
                <?php $statsHtml = ob_get_clean(); ?>
                
                <div class="custom-mobile-hidden custom-lg-block">
                    <?= $statsHtml ?>
                </div>
            </section>
        </div>
        <!-- Fix 8: Wave divider antara Hero dan konten di bawahnya -->
        <div class="hero-wave -mt-1">
            <svg viewBox="0 0 1440 40" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" class="w-full h-8 fill-slate-50 dark:fill-slate-950">
                <path d="M0,20 C360,40 1080,0 1440,20 L1440,40 L0,40 Z"/>
            </svg>
        </div>

        <!-- ===== SECTION: Agenda Publik ===== -->
        <?php if ($qTooLong): ?>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pb-4">
            <p class="rounded-xl bg-red-500/10 border border-red-500/30 text-red-700 dark:text-red-300 text-sm px-4 py-3">
                Kata kunci pencarian terlalu panjang (maks. 100 karakter). Silakan coba lagi.
            </p>
        </div>
        <?php endif; ?>

        <?php if ($publicAgendaError): ?>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pb-4">
            <p class="rounded-xl bg-klime-500/10 border border-klime-500/30 text-klime-700 dark:text-klime-300 text-sm px-4 py-3">
                Informasi jadwal sementara tidak tersedia. Silakan coba beberapa saat lagi.
            </p>
        </div>
        <?php else: ?>

        <!-- Pembatas visual sebelum section agenda publik -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <hr class="border-slate-200 dark:border-white/10 mb-0">
        </div>

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 custom-mobile-reverse">
                <!-- Bagian Kiri: Tabel -->
                <div class="lg:col-span-2 space-y-6 sm:space-y-8">
        <!-- Section Agenda Kegiatan Mendatang -->
        <section id="agenda-kegiatan" aria-labelledby="heading-kegiatan" class="pb-4">
            <div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-100 dark:bg-cyan-900/30 text-cyan-600 dark:text-cyan-400 shrink-0">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/>
                            </svg>
                        </div>
                        <h2 id="heading-kegiatan" class="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Agenda Kegiatan <?= $isAllMonths ? '<span class="text-xs font-semibold text-slate-400 dark:text-slate-500">(Semua)</span>' : ($isCurrentMonth ? '' : '<span class="text-xs font-semibold text-slate-400 dark:text-slate-500">(' . e($namaBulanRingkas[$currentMonth] . ' ' . $tahunIni) . ')</span>') ?>
                        </h2>
                    </div>
                </div>
                <!-- Form pencarian kegiatan -->
                <form x-data="{ isSearching: false }" @submit="isSearching = true" method="GET" action="index.php" onsubmit="setTimeout(()=>{const el=document.getElementById('agenda-kegiatan');if(el)el.scrollIntoView({behavior:'smooth'});},100);" class="flex flex-wrap sm:flex-nowrap items-center gap-2 w-full sm:w-auto">
                    <input type="hidden" name="_anchor" value="agenda-kegiatan">
                    <?php if ($zoomQ !== ''): ?><input type="hidden" name="zq" value="<?= e($zoomQ) ?>"><?php endif; ?>
                    <?php if ($zoomPage > 1): ?><input type="hidden" name="zp" value="<?= $zoomPage ?>"><?php endif; ?>
                    <?php if ($zoomLimit !== 5): ?><input type="hidden" name="zl" value="<?= $zoomLimit ?>"><?php endif; ?>
                    
                    <div class="relative shrink-0" x-data="agendaMonthPicker({
                        initialVal: '<?= $isAllMonths ? 'all' : sprintf('%04d-%02d', $currentYear, $currentMonth) ?>',
                        initialYear: <?= $currentYear ?>,
                        initialMonth: <?= $currentMonth ?>,
                        theme: 'cyan'
                    })" @click.outside="open = false">
                        <button type="button" @click="open = !open"
                                title="Pilih Bulan & Tahun Rekap"
                                aria-label="Pilih Bulan & Tahun Rekap"
                                class="month-picker-input flex items-center justify-between gap-2 rounded-xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:border-kcyan-400 focus:outline-none focus:border-kcyan-400 focus:ring-1 focus:ring-cyan-400 min-h-[44px] cursor-pointer min-w-[145px] transition-colors">
                            <span class="font-medium text-slate-800 dark:text-slate-200 whitespace-nowrap" x-text="displayText"><?= $isAllMonths ? 'Semua Bulan' : e($bulanIni . ' ' . $tahunIni) ?></span>
                            <svg class="h-4 w-4 text-cyan-600 dark:text-cyan-400 shrink-0 ml-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/>
                            </svg>
                        </button>
                        <input type="hidden" name="m" :value="selectedVal" value="<?= $isAllMonths ? 'all' : sprintf('%04d-%02d', $currentYear, $currentMonth) ?>">

                        <!-- Popover Date Picker -->
                        <div x-show="open" x-transition.origin.top.duration.150ms x-cloak
                             class="absolute left-0 top-full mt-1.5 z-50 w-64 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-xl p-3 text-slate-800 dark:text-slate-200">
                            
                            <!-- Opsi Semua Bulan -->
                            <button type="button" @click="selectAll()"
                                    :class="selectedVal === 'all' ? 'bg-cyan-500 text-slate-950 font-bold shadow-sm' : 'bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/15 text-slate-700 dark:text-slate-200 font-semibold'"
                                    class="w-full mb-2.5 flex items-center justify-center gap-2 py-2 px-3 rounded-xl text-xs transition-colors">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <span>Semua Bulan</span>
                            </button>

                            <div class="border-t border-slate-100 dark:border-slate-700/80 pt-2">
                                <!-- Navigasi Tahun -->
                                <div class="flex items-center justify-between px-1 mb-2">
                                    <button type="button" @click.stop="pickerYear--" class="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-white/10 text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white transition-colors" title="Tahun sebelumnya">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <span class="text-sm font-bold tracking-tight text-slate-900 dark:text-white" x-text="pickerYear"></span>
                                    <button type="button" @click.stop="pickerYear++" class="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-white/10 text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white transition-colors" title="Tahun berikutnya">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>

                                <!-- Grid 12 Bulan (4x3) persis format Date Picker -->
                                <div class="grid grid-cols-4 gap-1.5 text-center">
                                    <template x-for="(mName, mIdx) in monthsList" :key="mIdx">
                                        <button type="button" @click="selectMonth(mIdx + 1)"
                                                :class="isSelected(mIdx + 1) ? 'bg-cyan-500 text-slate-950 font-bold shadow-sm' : (isCurrent(mIdx + 1) ? 'border border-cyan-500/50 text-cyan-600 dark:text-cyan-400 font-semibold hover:bg-slate-100 dark:hover:bg-white/10' : 'hover:bg-slate-100 dark:hover:bg-white/10 text-slate-700 dark:text-slate-300 font-medium')"
                                                class="py-2 text-xs rounded-xl transition-colors"
                                                x-text="mName">
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative flex-1 sm:w-64">
                        <input type="text" name="kq" value="<?= e($kegiatanQ) ?>"
                               placeholder="Cari judul kegiatan atau no. surat..."
                               maxlength="100"
                               aria-label="Cari agenda kegiatan"
                               class="w-full rounded-xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none pl-11 pr-3 py-2 text-sm text-slate-900 dark:text-slate-800 dark:text-slate-200 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-kcyan-400 focus:ring-1 focus:ring-cyan-400 min-h-[44px]">
                        <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                        </svg>
                    </div>
                    <button type="submit" class="rounded-xl bg-kcyan-500 hover:bg-kcyan-400 text-slate-950 font-bold px-4 py-2 text-sm min-h-[44px] transition-colors duration-200">
                        <span x-show="!isSearching">Cari</span>
                        <svg x-show="isSearching" style="display: none;" class="animate-spin h-5 w-5 text-slate-950" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    </button>
                    <select name="kl" @change="isSearching = true; $el.form.submit()" class="rounded-xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none pl-3 pr-8 py-2 text-sm text-slate-700 dark:text-slate-300 focus:outline-none focus:border-kcyan-400 focus:ring-1 focus:ring-cyan-400 min-h-[44px]" title="Tampilkan data per halaman" aria-label="Tampilkan data per halaman">
                        <option value="5" <?= $kegiatanLimit === 5 ? 'selected' : '' ?>>5 data</option>
                        <option value="10" <?= $kegiatanLimit === 10 ? 'selected' : '' ?>>10 data</option>
                        <option value="20" <?= $kegiatanLimit === 20 ? 'selected' : '' ?>>20 data</option>
                    </select>
                    <?php if ($kegiatanQ !== '' || !$isCurrentMonth || $isAllMonths): ?>
                    <a href="?<?= http_build_query(array_filter(['zq' => $zoomQ ?: null, 'zp' => $zoomPage > 1 ? $zoomPage : null, 'zl' => $zoomLimit !== 5 ? $zoomLimit : null])) ?>#agenda-kegiatan"
                       class="inline-flex items-center gap-1 rounded-xl bg-red-100 dark:bg-red-600/20 border border-red-300 dark:border-red-500/40 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-600/30 px-3 py-2 text-xs font-bold min-h-[44px] transition-colors duration-200"
                       title="Reset filter & kembali ke bulan ini">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        Reset
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($kegiatanQ !== ''): ?>
            <p class="mb-4 text-xs text-slate-400">Menampilkan hasil untuk: <strong class="text-slate-800 dark:text-slate-200">"<?= e($kegiatanQ) ?>"</strong></p>
            <?php endif; ?>

            <div x-show="isSearching" style="display: none;" class="py-16 flex justify-center">
                <svg class="animate-spin h-10 w-10 text-kcyan-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            </div>

            <div x-show="!isSearching">
                <?php if (empty($kegiatanRows)): ?>
                <!-- Empty State Kegiatan -->
                <div class="flex flex-col items-center justify-center py-16 rounded-3xl border border-slate-200 dark:border-white/10 shadow-sm bg-gradient-to-b from-slate-50 to-white dark:from-slate-800/40 dark:to-slate-800/10">
                    <div class="flex items-center justify-center h-20 w-20 rounded-full bg-slate-100 dark:bg-slate-800 mb-5 shadow-inner">
                        <svg class="h-10 w-10 text-slate-400 dark:text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/>
                        </svg>
                    </div>
                    <p class="text-slate-500 dark:text-slate-400 font-semibold">Belum ada agenda kegiatan mendatang</p>
                    <p class="text-slate-500 text-sm mt-1">Jadwal kegiatan akan muncul di sini saat tersedia.</p>
                </div>
                <?php else: ?>            <!-- Tabel Desktop -->
            <div class="hidden sm:block overflow-x-auto rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none">
                <table class="w-full text-sm leading-6">
                    <caption class="sr-only">Daftar agenda kegiatan mendatang BBLKL</caption>
                    <thead class="bg-white dark:bg-white/5 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-bold">No</th>
                            <th scope="col" class="px-4 py-3 text-left font-bold min-w-[140px]">Judul Kegiatan</th>
                            <th scope="col" class="px-4 py-3 text-left font-bold whitespace-nowrap">Jadwal</th>
                            <th scope="col" class="px-4 py-3 text-left font-bold whitespace-nowrap">Lokasi</th>
                            <th scope="col" class="px-4 py-3 text-left font-bold whitespace-nowrap">
                                Surat
                                <span class="block text-[10px] font-normal normal-case text-slate-400 dark:text-slate-500">Masuk / Keluar</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5 text-slate-800 dark:text-slate-200">
                        <?php foreach ($kegiatanRows as $i => $row):
                            $modeBadge  = publicModeBadge((string)($row['agenda_mode'] ?? 'offline'));
                            $isToday    = (string)$row['agenda_date'] === date('Y-m-d') || (!empty($row['agenda_date_end']) && date('Y-m-d') >= $row['agenda_date'] && date('Y-m-d') <= $row['agenda_date_end']);
                            $kegiatanBadge = publicKegiatanStatusBadge((string)$row['agenda_date'], $row['agenda_date_end'] ?? null);
                            $kSourceId  = (int)($row['source_agenda_id'] ?? 0);
                            $rowNum     = ($kegiatanPage - 1) * $kegiatanLimit + $i + 1;
                            $refNum     = trim((string)($row['reference_number'] ?? ''));
                            $hasRef     = $refNum !== '' && !str_starts_with($refNum, 'AUTO-');
                        ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors <?= $isToday ? 'bg-brand-50/50 dark:bg-brand-900/10' : '' ?>">
                            <td class="align-top px-4 py-3 text-slate-500 font-medium"><?= $rowNum ?></td>
                            <td class="align-top px-4 py-3 font-semibold">
                                <div>
                                    <span><?= e((string)$row['title']) ?></span>
                                    <?php if ($kegiatanBadge): ?>
                                    <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold <?= $kegiatanBadge['class'] ?>"><?= $kegiatanBadge['label'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($hasRef): ?>
                                <div class="mt-1 flex items-center gap-1.5 text-[11px] font-normal text-slate-500 dark:text-slate-400">
                                    <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    <span>No. Surat: <span class="font-mono font-medium text-slate-600 dark:text-slate-300"><?= e($refNum) ?></span></span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="align-top px-4 py-3">
                                <div class="flex items-start gap-1.5">
                                    <svg class="w-4 h-4 text-slate-400 shrink-0 mt-[-1px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    <p class="text-slate-700 dark:text-slate-200 font-medium text-xs leading-relaxed whitespace-nowrap">
                                        <?= e(publicHariIndo((string)$row['agenda_date'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date']))) ?>
                                        <?php if (!empty($row['agenda_date_end']) && $row['agenda_date_end'] !== $row['agenda_date']): ?>
                                            <br>- <?= e(publicHariIndo((string)$row['agenda_date_end'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date_end']))) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if (!empty($row['agenda_time'])): ?>
                                <div class="flex items-center gap-1.5 mt-1.5">
                                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <p class="text-slate-500 dark:text-slate-400 text-xs whitespace-nowrap"><?= e(publicTimeLabel((string)$row['agenda_time'])) ?> WIB</p>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="align-top px-4 py-3">
                                <div class="space-y-1.5">
                                    <div>
                                        <a href="javascript:void(0)" onclick="document.getElementById(window.innerWidth >= 640 ? 'zoom-row-desk-<?= $row['id'] ?>' : 'zoom-row-mob-<?= $row['id'] ?>')?.scrollIntoView({behavior: 'smooth', block: 'center'})" class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold hover:opacity-80 transition <?= $modeBadge['class'] ?>" title="Scroll ke agenda Zoom (jika ada)">
                                            <?= $modeBadge['label'] ?>
                                        </a>
                                    </div>
                                    <?php if (!empty($row['location'])):
                                        $locRaw = (string)$row['location'];
                                        $locDisplay = preg_replace('/Balai\s+Besar\s+Laboratorium\s+Kesehatan\s+Lingkungan/iu', 'BBLKL', $locRaw);
                                        $locDisplay = preg_replace('/Balai\s+Besar\s+Labkesmas/iu', 'BBLKL', $locDisplay);
                                    ?>
                                        <div class="text-slate-600 dark:text-slate-400 text-xs whitespace-nowrap flex items-center">
                                            <svg class="w-3.5 h-3.5 mr-1 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                            <span class="truncate inline-block max-w-[12rem]" style="max-width: 12rem;" title="<?= e($locRaw) ?>"><?= e($locDisplay) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="align-top px-4 py-3">
                                <?php if ($kSourceId > 0 && publicHasLetterAttachment($row)):
                                    $kDirection = $row['parent_direction'] ?? '';
                                    $kSuratLabel = $kDirection === 'masuk' ? 'Surat Masuk' : ($kDirection === 'keluar' ? 'Surat Keluar' : 'Surat');
                                    $kSuratClass = $kDirection === 'masuk' ? 'bg-emerald-600 hover:bg-emerald-700 text-emerald-100' : ($kDirection === 'keluar' ? 'bg-amber-600 hover:bg-amber-700 text-amber-100' : 'bg-indigo-600 hover:bg-indigo-700 text-indigo-100');
                                ?>
                                <a href="file_preview.php?id=<?= (int)$row['id'] ?>&source=1&return_to=index.php"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="<?= e($kSuratLabel . ': ' . (string)$row['title']) ?>"
                                   class="inline-flex items-center gap-1 rounded-lg <?= $kSuratClass ?> px-3 py-1 text-xs font-semibold min-h-[36px] transition-colors">
                                    <?php if ($kDirection === 'masuk'): ?>
                                    <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5h18M12 3v13.5m0-13.5L8 8m4-4 4 4"/></svg>
                                    <?php elseif ($kDirection === 'keluar'): ?>
                                    <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5h18M12 21V7.5m0 13.5L8 17m4 4 4-4"/></svg>
                                    <?php else: ?>
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/></svg>
                                    <?php endif; ?>
                                    <?= e($kSuratLabel) ?>
                                </a>
                                <?php else: ?>
                                <span class="text-slate-400 text-xs">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Card View Mobile -->
            <div class="sm:hidden space-y-3">
                <?php foreach ($kegiatanRows as $i => $row):
                    $modeBadge  = publicModeBadge((string)($row['agenda_mode'] ?? 'offline'));
                    $isToday    = (string)$row['agenda_date'] === date('Y-m-d') || (!empty($row['agenda_date_end']) && date('Y-m-d') >= $row['agenda_date'] && date('Y-m-d') <= $row['agenda_date_end']);
                    $kegiatanBadge = publicKegiatanStatusBadge((string)$row['agenda_date'], $row['agenda_date_end'] ?? null);
                    $kSrcIdMob  = (int)($row['source_agenda_id'] ?? 0);
                    $refNumMob  = trim((string)($row['reference_number'] ?? ''));
                    $hasRefMob  = $refNumMob !== '' && !str_starts_with($refNumMob, 'AUTO-');
                ?>
                <article class="rounded-2xl border <?= $isToday ? 'border-brand-400/40 bg-brand-50/50 dark:bg-brand-900/10' : 'border-slate-200 dark:border-white/10 bg-white dark:bg-white/5' ?> shadow-sm dark:shadow-none p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-semibold text-slate-900 dark:text-slate-100 leading-snug text-sm"><?= e((string)$row['title']) ?></p>
                            <?php if ($kegiatanBadge): ?>
                            <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold <?= $kegiatanBadge['class'] ?>"><?= $kegiatanBadge['label'] ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="javascript:void(0)" onclick="document.getElementById(window.innerWidth >= 640 ? 'zoom-row-desk-<?= $row['id'] ?>' : 'zoom-row-mob-<?= $row['id'] ?>')?.scrollIntoView({behavior: 'smooth', block: 'center'})" class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold shrink-0 hover:opacity-80 transition <?= $modeBadge['class'] ?>" title="Scroll ke agenda Zoom (jika ada)"><?= $modeBadge['label'] ?></a>
                    </div>
                    <?php if ($hasRefMob): ?>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <p class="text-xs text-slate-600 dark:text-slate-400"><span class="font-semibold text-slate-700 dark:text-slate-300">No. Surat:</span> <?= e($refNumMob) ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="mt-2 space-y-1.5">
                        <div class="flex items-start gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-[-1px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                <?= e(publicHariIndo((string)$row['agenda_date'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date']))) ?>
                                <?php if (!empty($row['agenda_date_end']) && $row['agenda_date_end'] !== $row['agenda_date']): ?>
                                    <br>- <?= e(publicHariIndo((string)$row['agenda_date_end'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date_end']))) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if (!empty($row['agenda_time'])): ?>
                        <div class="flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <p class="text-xs text-slate-500 dark:text-slate-400"><?= e(publicTimeLabel((string)$row['agenda_time'])) ?> WIB</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($row['location'])):
                        $locMobRaw = (string)$row['location'];
                        $locMobDisplay = preg_replace('/Balai\s+Besar\s+Laboratorium\s+Kesehatan\s+Lingkungan/iu', 'BBLKL', $locMobRaw);
                        $locMobDisplay = preg_replace('/Balai\s+Besar\s+Labkesmas/iu', 'BBLKL', $locMobDisplay);
                    ?>
                    <div class="mt-1 flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <p class="text-xs text-slate-500 truncate max-w-[15rem]" title="<?= e($locMobRaw) ?>"><?= e($locMobDisplay) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($kSrcIdMob > 0 && publicHasLetterAttachment($row)):
                        $kDirectionMob = $row['parent_direction'] ?? '';
                        $kSuratLabelMob = $kDirectionMob === 'masuk' ? 'Lihat Surat Masuk' : ($kDirectionMob === 'keluar' ? 'Lihat Surat Keluar' : 'Lihat Surat Asal');
                        $kSuratClassMob = $kDirectionMob === 'masuk' ? 'bg-emerald-600 hover:bg-emerald-700 text-emerald-100' : ($kDirectionMob === 'keluar' ? 'bg-amber-600 hover:bg-amber-700 text-amber-100' : 'bg-indigo-600 hover:bg-indigo-700 text-indigo-100');
                    ?>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="file_preview.php?id=<?= (int)$row['id'] ?>&source=1&return_to=index.php"
                           target="_blank" rel="noopener noreferrer"
                           aria-label="<?= e($kSuratLabelMob . ': ' . (string)$row['title']) ?>"
                           class="inline-flex items-center gap-1.5 rounded-xl <?= $kSuratClassMob ?> px-3 py-2 text-xs font-semibold min-h-[44px] transition-colors">
                            <?php if ($kDirectionMob === 'masuk'): ?>
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5h18M12 3v13.5m0-13.5L8 8m4-4 4 4"/></svg>
                            <?php elseif ($kDirectionMob === 'keluar'): ?>
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5h18M12 21V7.5m0 13.5L8 17m4 4 4-4"/></svg>
                            <?php else: ?>
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/></svg>
                            <?php endif; ?>
                            <?= e($kSuratLabelMob) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Kegiatan -->
            <?php if ($kegiatanTotal > 0):
                $kTotalPages = (int)ceil($kegiatanTotal / $kegiatanLimit);
                $kPrevPage = $kegiatanPage > 1 ? $kegiatanPage - 1 : null;
                $kNextPage = $kegiatanPage < $kTotalPages ? $kegiatanPage + 1 : null;
                $kBase = array_filter($kBaseParams + ['kl' => $kegiatanLimit !== 5 ? $kegiatanLimit : null]);
                $kFirstRow = ($kegiatanPage - 1) * $kegiatanLimit + 1;
                $kLastRow = min($kegiatanPage * $kegiatanLimit, $kegiatanTotal);
            ?>
            <div class="mt-4 flex items-center justify-between gap-3 text-sm">
                <p class="text-slate-500 text-xs whitespace-nowrap">Menampilkan <span class="font-medium text-slate-700 dark:text-slate-200"><?= $kFirstRow ?>-<?= $kLastRow ?></span> dari <span class="font-medium text-slate-700 dark:text-slate-200"><?= $kegiatanTotal ?></span> kegiatan</p>
                <?php if ($kTotalPages > 1): ?>
                <div class="flex items-center gap-1.5 whitespace-nowrap">
                    <?php if ($kegiatanPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($kBase, ['kp' => 1])) ?>#agenda-kegiatan"
                       title="Halaman awal"
                       aria-label="Halaman awal"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m11 17-5-5 5-5m7 10-5-5 5-5"/></svg>
                    </a>
                    <a href="?<?= http_build_query(array_merge($kBase, ['kp' => $kPrevPage])) ?>#agenda-kegiatan"
                       title="Halaman sebelumnya"
                       aria-label="Halaman sebelumnya"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                    </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $kegiatanPage - 2); $i <= min($kTotalPages, $kegiatanPage + 2); $i++): ?>
                    <a href="?<?= http_build_query(array_merge($kBase, ['kp' => $i])) ?>#agenda-kegiatan"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border <?= $i === $kegiatanPage ? 'border-teal-500 bg-teal-50 text-teal-700 font-bold dark:bg-teal-500/20 dark:text-teal-300' : 'border-slate-300 bg-white dark:bg-slate-800 dark:border-white/20 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10' ?> transition-colors text-sm">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($kegiatanPage < $kTotalPages): ?>
                    <a href="?<?= http_build_query(array_merge($kBase, ['kp' => $kNextPage])) ?>#agenda-kegiatan"
                       title="Halaman selanjutnya"
                       aria-label="Halaman selanjutnya"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                    <a href="?<?= http_build_query(array_merge($kBase, ['kp' => $kTotalPages])) ?>#agenda-kegiatan"
                       title="Halaman akhir"
                       aria-label="Halaman akhir"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m13 17 5-5-5-5M6 17l5-5-5-5"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- Bagian Kiri (Lanjutan): Agenda Zoom -->
        <section id="agenda-zoom" aria-labelledby="heading-zoom" class="pt-8 border-t border-slate-200 dark:border-slate-800">
            <div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 shrink-0">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/>
                            </svg>
                        </div>
                        <h2 id="heading-zoom" class="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Agenda Zoom <?= $isAllMonths ? '<span class="text-xs font-semibold text-slate-400 dark:text-slate-500">(Semua)</span>' : ($isCurrentMonth ? '' : '<span class="text-xs font-semibold text-slate-400 dark:text-slate-500">(' . e($namaBulanRingkas[$currentMonth] . ' ' . $tahunIni) . ')</span>') ?>
                        </h2>
                    </div>
                </div>
                <form x-data="{ isSearching: false }" @submit="isSearching = true" action="index.php" method="GET" onsubmit="setTimeout(()=>{const el=document.getElementById('agenda-zoom');if(el)el.scrollIntoView({behavior:'smooth'});},100);" class="flex flex-wrap sm:flex-nowrap items-center gap-2 w-full sm:w-auto">
                    <input type="hidden" name="_anchor" value="agenda-zoom">
                    <?php if ($kegiatanQ !== ''): ?>
                    <input type="hidden" name="kq" value="<?= e($kegiatanQ) ?>">
                    <?php endif; ?>
                    <?php if ($kegiatanPage > 1): ?>
                    <input type="hidden" name="kp" value="<?= $kegiatanPage ?>">
                    <?php endif; ?>
                    <?php if ($kegiatanLimit !== 5): ?>
                    <input type="hidden" name="kl" value="<?= $kegiatanLimit ?>">
                    <?php endif; ?>

                    <div class="relative shrink-0" x-data="agendaMonthPicker({
                        initialVal: '<?= $isAllMonths ? 'all' : sprintf('%04d-%02d', $currentYear, $currentMonth) ?>',
                        initialYear: <?= $currentYear ?>,
                        initialMonth: <?= $currentMonth ?>,
                        theme: 'rose'
                    })" @click.outside="open = false">
                        <button type="button" @click="open = !open"
                                title="Pilih Bulan & Tahun Rekap"
                                aria-label="Pilih Bulan & Tahun Rekap"
                                class="month-picker-input flex items-center justify-between gap-2 rounded-xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:border-rose-400 focus:outline-none focus:border-rose-400 focus:ring-1 focus:ring-rose-400 min-h-[44px] cursor-pointer min-w-[145px] transition-colors">
                            <span class="font-medium text-slate-800 dark:text-slate-200 whitespace-nowrap" x-text="displayText"><?= $isAllMonths ? 'Semua Bulan' : e($bulanIni . ' ' . $tahunIni) ?></span>
                            <svg class="h-4 w-4 text-rose-500 dark:text-rose-400 shrink-0 ml-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/>
                            </svg>
                        </button>
                        <input type="hidden" name="m" :value="selectedVal" value="<?= $isAllMonths ? 'all' : sprintf('%04d-%02d', $currentYear, $currentMonth) ?>">

                        <!-- Popover Date Picker -->
                        <div x-show="open" x-transition.origin.top.duration.150ms x-cloak
                             class="absolute left-0 top-full mt-1.5 z-50 w-64 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-xl p-3 text-slate-800 dark:text-slate-200">
                            
                            <!-- Opsi Semua Bulan -->
                            <button type="button" @click="selectAll()"
                                    :class="selectedVal === 'all' ? 'bg-rose-500 text-white font-bold shadow-sm' : 'bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/15 text-slate-700 dark:text-slate-200 font-semibold'"
                                    class="w-full mb-2.5 flex items-center justify-center gap-2 py-2 px-3 rounded-xl text-xs transition-colors">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                <span>Semua Bulan</span>
                            </button>

                            <div class="border-t border-slate-100 dark:border-slate-700/80 pt-2">
                                <!-- Navigasi Tahun -->
                                <div class="flex items-center justify-between px-1 mb-2">
                                    <button type="button" @click.stop="pickerYear--" class="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-white/10 text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white transition-colors" title="Tahun sebelumnya">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <span class="text-sm font-bold tracking-tight text-slate-900 dark:text-white" x-text="pickerYear"></span>
                                    <button type="button" @click.stop="pickerYear++" class="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-white/10 text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white transition-colors" title="Tahun berikutnya">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>

                                <!-- Grid 12 Bulan (4x3) persis format Date Picker -->
                                <div class="grid grid-cols-4 gap-1.5 text-center">
                                    <template x-for="(mName, mIdx) in monthsList" :key="mIdx">
                                        <button type="button" @click="selectMonth(mIdx + 1)"
                                                :class="isSelected(mIdx + 1) ? 'bg-rose-500 text-white font-bold shadow-sm' : (isCurrent(mIdx + 1) ? 'border border-rose-500/50 text-rose-600 dark:text-rose-400 font-semibold hover:bg-slate-100 dark:hover:bg-white/10' : 'hover:bg-slate-100 dark:hover:bg-white/10 text-slate-700 dark:text-slate-300 font-medium')"
                                                class="py-2 text-xs rounded-xl transition-colors"
                                                x-text="mName">
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative flex-1 sm:w-64">
                        <input type="text" name="zq" value="<?= e($zoomQ) ?>" placeholder="Cari judul meeting atau no. surat..."
                               aria-label="Cari agenda Zoom"
                               class="w-full rounded-xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none pl-11 pr-3 py-2 text-sm text-slate-900 dark:text-slate-800 dark:text-slate-200 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-rose-400 focus:ring-1 focus:ring-rose-400 min-h-[44px]">
                        <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                        </svg>
                    </div>
                    <button type="submit" class="rounded-xl bg-rose-500 hover:bg-rose-600 text-slate-900 dark:text-white font-bold px-4 py-2 text-sm min-h-[44px] transition-colors duration-200">
                        <span x-show="!isSearching">Cari</span>
                        <svg x-show="isSearching" style="display: none;" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    </button>
                    <select name="zl" @change="isSearching = true; $el.form.submit()" class="rounded-xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none pl-3 pr-8 py-2 text-sm text-slate-700 dark:text-slate-300 focus:outline-none focus:border-rose-400 focus:ring-1 focus:ring-rose-400 min-h-[44px]" title="Tampilkan data per halaman" aria-label="Tampilkan data per halaman">
                        <option value="5" <?= $zoomLimit === 5 ? 'selected' : '' ?>>5 data</option>
                        <option value="10" <?= $zoomLimit === 10 ? 'selected' : '' ?>>10 data</option>
                        <option value="20" <?= $zoomLimit === 20 ? 'selected' : '' ?>>20 data</option>
                    </select>
                    <?php if ($zoomQ !== '' || !$isCurrentMonth || $isAllMonths): ?>
                    <a href="?<?= http_build_query(array_filter(['kq' => $kegiatanQ ?: null, 'kp' => $kegiatanPage > 1 ? $kegiatanPage : null, 'kl' => $kegiatanLimit !== 5 ? $kegiatanLimit : null])) ?>#agenda-zoom"
                       class="inline-flex items-center gap-1 rounded-xl bg-red-100 dark:bg-red-600/20 border border-red-300 dark:border-red-500/40 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-600/30 px-3 py-2 text-xs font-bold min-h-[44px] transition-colors duration-200"
                       title="Reset filter & kembali ke bulan ini">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        Reset
                    </a>
                    <?php endif; ?>
                </form>
        </div>
            <?php if ($zoomQ !== ''): ?>
            <p class="mb-4 text-xs text-slate-400">Menampilkan hasil untuk: <strong class="text-slate-800 dark:text-slate-200">"<?= e($zoomQ) ?>"</strong></p>
            <?php endif; ?>

            <?php if (empty($zoomRows)): ?>
            <!-- Empty State Zoom -->
            <div class="flex flex-col items-center justify-center py-14 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none bg-white dark:bg-white/5">
                <svg class="h-12 w-12 text-slate-600 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/>
                </svg>
                <p class="text-slate-500 dark:text-slate-400 font-semibold">Belum ada agenda Zoom mendatang</p>
                <p class="text-slate-500 text-sm mt-1">Meeting Zoom akan muncul di sini saat tersedia.</p>
            </div>
            <?php else: ?>

            <!-- Tabel Desktop Zoom -->
            <div class="hidden sm:block overflow-x-auto rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none">
                <table class="w-full text-sm leading-6">
                    <caption class="sr-only">Daftar agenda Zoom mendatang BBLKL</caption>
                    <thead class="bg-white dark:bg-white/5 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-bold">No</th>
                            <th scope="col" class="px-4 py-3 text-left font-bold min-w-[140px]">Judul Meeting</th>
                            <th scope="col" class="px-4 py-3 text-left font-bold whitespace-nowrap">Jadwal</th>
                            <th scope="col" class="px-4 py-3 text-center font-bold whitespace-nowrap">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5 text-slate-800 dark:text-slate-200">
                        <?php foreach ($zoomRows as $i => $row):
                            $badge     = publicZoomBadge((string)$row['agenda_date'], (string)($row['agenda_date_end'] ?? $row['agenda_date']));
                            $joinUrl   = publicValidUrl((string)($row['zoom_link'] ?? ''));
                            $sourceId  = (int)($row['source_agenda_id'] ?? 0);
                            $rowNum    = ($zoomPage - 1) * $zoomLimit + $i + 1;
                            $isToday   = (string)$row['agenda_date'] === date('Y-m-d') || (!empty($row['agenda_date_end']) && date('Y-m-d') >= $row['agenda_date'] && date('Y-m-d') <= $row['agenda_date_end']);
                            $zRefNum   = trim((string)($row['reference_number'] ?? ''));
                            $zHasRef   = $zRefNum !== '' && !str_starts_with($zRefNum, 'AUTO-');
                            // Fallback: jika zoom_link kosong tapi ada meeting ID, construct URL Zoom
                            if (!$joinUrl && !empty($row['zoom_meeting_id'])) {
                                $joinUrl = publicBuildZoomUrlFromId((string)$row['zoom_meeting_id'], (string)($row['zoom_passcode'] ?? ''));
                            }
                        ?>
                        <tr id="zoom-row-desk-<?= $row['id'] ?>" class="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors <?= $isToday ? 'bg-brand-50/50 dark:bg-brand-900/10' : '' ?>">
                            <td class="align-top px-4 py-3 text-slate-500 font-medium"><?= $rowNum ?></td>
                            <td class="align-top px-4 py-3 font-semibold">
                                <div>
                                    <span><?= e((string)$row['title']) ?></span>
                                    <?php if ($badge): ?>
                                    <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($zHasRef): ?>
                                <div class="mt-1 flex items-center gap-1.5 text-[11px] font-normal text-slate-500 dark:text-slate-400">
                                    <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    <span>No. Surat: <span class="font-mono font-medium text-slate-600 dark:text-slate-300"><?= e($zRefNum) ?></span></span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="align-top px-4 py-3">
                                <div class="flex items-start gap-1.5">
                                    <svg class="w-4 h-4 text-slate-400 shrink-0 mt-[-1px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    <p class="text-slate-700 dark:text-slate-200 font-medium text-xs leading-relaxed whitespace-nowrap">
                                        <?= e(publicHariIndo((string)$row['agenda_date'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date']))) ?>
                                        <?php if (!empty($row['agenda_date_end']) && $row['agenda_date_end'] !== $row['agenda_date']): ?>
                                            <br>- <?= e(publicHariIndo((string)$row['agenda_date_end'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date_end']))) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if (!empty($row['agenda_time'])): ?>
                                <div class="flex items-center gap-1.5 mt-1.5">
                                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <p class="text-slate-500 dark:text-slate-400 text-xs whitespace-nowrap"><?= e(publicTimeLabel((string)$row['agenda_time'])) ?> WIB</p>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="align-top px-4 py-3 text-center">
                                <div class="flex flex-col items-stretch justify-center gap-2 w-full">
                                    <div class="flex flex-nowrap items-center justify-center gap-1.5 w-full">
                                        <a href="<?= e($joinUrl ?: 'https://zoom.us/join') ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           aria-label="<?= e('Bergabung ke meeting: ' . (string)$row['title']) ?>"
                                           class="inline-flex items-center gap-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-rose-100 px-3 py-1 text-xs font-semibold min-h-[36px] whitespace-nowrap transition-colors">
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                            </svg>
                                            Gabung
                                        </a>
                                        <?php if ($sourceId > 0 && publicHasLetterAttachment($row)): ?>
                                        <a href="file_preview.php?id=<?= (int)$row['id'] ?>&source=1&return_to=index.php"
                                           target="_blank" rel="noopener noreferrer"
                                           aria-label="<?= e('Lihat surat asal: ' . (string)$row['title']) ?>"
                                           class="inline-flex items-center gap-1 rounded-lg <?= ($row['parent_direction'] ?? '') === 'masuk' ? 'bg-emerald-600 hover:bg-emerald-700 text-emerald-100' : (($row['parent_direction'] ?? '') === 'keluar' ? 'bg-amber-600 hover:bg-amber-700 text-amber-100' : 'bg-indigo-600 hover:bg-indigo-700 text-indigo-100') ?> px-3 py-1 text-xs font-semibold min-h-[36px] whitespace-nowrap transition-colors">
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/>
                                            </svg>
                                    <?= ($row['parent_direction'] ?? '') === 'masuk' ? 'Surat Masuk' : (($row['parent_direction'] ?? '') === 'keluar' ? 'Surat Keluar' : 'Surat') ?>
                                </a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($row['zoom_meeting_id']) || !empty($row['zoom_passcode'])): ?>
                                    <div class="text-xs text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2 py-1.5 rounded-md border border-slate-200 dark:border-white/10 w-full text-left break-all">
                                        <?php if (!empty($row['zoom_meeting_id'])): ?><div class="mb-0.5"><strong class="text-slate-900 dark:text-white font-bold">ID:</strong> <?= e((string)$row['zoom_meeting_id']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['zoom_passcode'])): ?><div><strong class="text-slate-900 dark:text-white font-bold">Passcode:</strong> <?= e((string)$row['zoom_passcode']) ?></div><?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Card View Mobile Zoom -->
            <div class="sm:hidden space-y-3">
                <?php foreach ($zoomRows as $i => $row):
                    $badge    = publicZoomBadge((string)$row['agenda_date'], (string)($row['agenda_date_end'] ?? $row['agenda_date']));
                    $joinUrl  = publicValidUrl((string)($row['zoom_link'] ?? ''));
                    $sourceId = (int)($row['source_agenda_id'] ?? 0);
                    $isToday  = (string)$row['agenda_date'] === date('Y-m-d') || (!empty($row['agenda_date_end']) && date('Y-m-d') >= $row['agenda_date'] && date('Y-m-d') <= $row['agenda_date_end']);
                    $zRefNumMob = trim((string)($row['reference_number'] ?? ''));
                    $zHasRefMob = $zRefNumMob !== '' && !str_starts_with($zRefNumMob, 'AUTO-');
                    // Fallback: jika zoom_link kosong tapi ada meeting ID, construct URL Zoom
                    if (!$joinUrl && !empty($row['zoom_meeting_id'])) {
                        $joinUrl = publicBuildZoomUrlFromId((string)$row['zoom_meeting_id'], (string)($row['zoom_passcode'] ?? ''));
                    }
                ?>
                <article id="zoom-row-mob-<?= $row['id'] ?>" class="rounded-2xl border <?= $isToday ? 'border-brand-400/40 bg-brand-50/50 dark:bg-brand-900/10' : 'border-slate-200 dark:border-white/10 bg-white dark:bg-white/5' ?> shadow-sm dark:shadow-none p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-semibold text-slate-900 dark:text-slate-100 leading-snug text-sm"><?= e((string)$row['title']) ?></p>
                        <span class="inline-flex items-center justify-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold shrink-0 <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                    </div>
                    <?php if ($zHasRefMob): ?>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <p class="text-xs text-slate-600 dark:text-slate-400"><span class="font-semibold text-slate-700 dark:text-slate-300">No. Surat:</span> <?= e($zRefNumMob) ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="mt-2 space-y-1.5">
                        <div class="flex items-start gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-[-1px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                <?= e(publicHariIndo((string)$row['agenda_date'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date']))) ?>
                                <?php if (!empty($row['agenda_date_end']) && $row['agenda_date_end'] !== $row['agenda_date']): ?>
                                    <br>- <?= e(publicHariIndo((string)$row['agenda_date_end'])) ?>, <?= e(date('d/m/Y', strtotime((string)$row['agenda_date_end']))) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if (!empty($row['agenda_time'])): ?>
                        <div class="flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <p class="text-xs text-slate-500 dark:text-slate-400"><?= e(publicTimeLabel((string)$row['agenda_time'])) ?> WIB</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($row['zoom_meeting_id']) || !empty($row['zoom_passcode'])): ?>
                    <p class="mt-1 text-[11px] text-slate-700 dark:text-slate-200">
                        <?php if (!empty($row['zoom_meeting_id'])): ?><strong class="text-slate-900 dark:text-white font-bold">ID:</strong> <?= e((string)$row['zoom_meeting_id']) ?> &nbsp;<?php endif; ?>
                        <?php if (!empty($row['zoom_passcode'])): ?><strong class="text-slate-900 dark:text-white font-bold">Passcode:</strong> <?= e((string)$row['zoom_passcode']) ?><?php endif; ?>
                    </p>
                    <?php endif; ?>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="<?= e($joinUrl ?: 'https://zoom.us/join') ?>"
                           target="_blank" rel="noopener noreferrer"
                           aria-label="<?= e('Bergabung ke meeting: ' . (string)$row['title']) ?>"
                           class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-rose-100 px-3 py-2 text-xs font-semibold min-h-[44px] transition-colors">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                            </svg>
                            Gabung
                        </a>
                        <?php if ($sourceId > 0 && publicHasLetterAttachment($row)): ?>
                        <a href="file_preview.php?id=<?= (int)$row['id'] ?>&source=1&return_to=index.php"
                           target="_blank" rel="noopener noreferrer"
                           aria-label="<?= e('Lihat surat asal: ' . (string)$row['title']) ?>"
                           class="inline-flex items-center gap-1.5 rounded-xl <?= ($row['parent_direction'] ?? '') === 'masuk' ? 'bg-emerald-600 hover:bg-emerald-700 text-emerald-100' : (($row['parent_direction'] ?? '') === 'keluar' ? 'bg-amber-600 hover:bg-amber-700 text-amber-100' : 'bg-indigo-600 hover:bg-indigo-700 text-indigo-100') ?> px-3 py-2 text-xs font-semibold min-h-[44px] transition-colors">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/>
                            </svg>
                            Lihat <?= ($row['parent_direction'] ?? '') === 'masuk' ? 'Surat Masuk' : (($row['parent_direction'] ?? '') === 'keluar' ? 'Surat Keluar' : 'Surat') ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Zoom -->
            <?php if ($zoomTotal > 0):
                $zTotalPages = (int)ceil($zoomTotal / $zoomLimit);
                $zPrevPage = $zoomPage > 1 ? $zoomPage - 1 : null;
                $zNextPage = $zoomPage < $zTotalPages ? $zoomPage + 1 : null;
                $zBase = array_filter($zBaseParams + ['zl' => $zoomLimit !== 5 ? $zoomLimit : null]);
                $zFirstRow = ($zoomPage - 1) * $zoomLimit + 1;
                $zLastRow = min($zoomPage * $zoomLimit, $zoomTotal);
            ?>
            <div class="mt-4 flex items-center justify-between gap-3 text-sm">
                <p class="text-slate-500 text-xs whitespace-nowrap">Menampilkan <span class="font-medium text-slate-700 dark:text-slate-200"><?= $zFirstRow ?>-<?= $zLastRow ?></span> dari <span class="font-medium text-slate-700 dark:text-slate-200"><?= $zoomTotal ?></span> meeting</p>
                <?php if ($zTotalPages > 1): ?>
                <div class="flex items-center gap-1.5 whitespace-nowrap">
                    <?php if ($zoomPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($zBase, ['zp' => 1])) ?>#agenda-zoom"
                       title="Halaman awal"
                       aria-label="Halaman awal"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m11 17-5-5 5-5m7 10-5-5 5-5"/></svg>
                    </a>
                    <a href="?<?= http_build_query(array_merge($zBase, ['zp' => $zPrevPage])) ?>#agenda-zoom"
                       title="Halaman sebelumnya"
                       aria-label="Halaman sebelumnya"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                    </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $zoomPage - 2); $i <= min($zTotalPages, $zoomPage + 2); $i++): ?>
                    <a href="?<?= http_build_query(array_merge($zBase, ['zp' => $i])) ?>#agenda-zoom"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border <?= $i === $zoomPage ? 'border-rose-500 bg-rose-50 text-rose-700 font-bold dark:bg-rose-500/20 dark:text-rose-300' : 'border-slate-300 bg-white dark:bg-slate-800 dark:border-white/20 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10' ?> transition-colors text-sm">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($zoomPage < $zTotalPages): ?>
                    <a href="?<?= http_build_query(array_merge($zBase, ['zp' => $zNextPage])) ?>#agenda-zoom"
                       title="Halaman selanjutnya"
                       aria-label="Halaman selanjutnya"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                    <a href="?<?= http_build_query(array_merge($zBase, ['zp' => $zTotalPages])) ?>#agenda-zoom"
                       title="Halaman akhir"
                       aria-label="Halaman akhir"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m13 17 5-5-5-5M6 17l5-5-5-5"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; // end zoomRows ?>
        </section>
                </div> <!-- End Bagian Kiri -->
                
                <!-- Bagian Kanan: Widget Kalender -->
                <div class="lg:col-span-1 mb-4 lg:mb-0">
                    <div class="custom-lg-sticky" style="top: 6rem;">
<!-- Mini Calendar Widget -->
        <div class="rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 shadow-sm">
            <?php
            $today = date('Y-m-d');
            $firstDayOfMonth = (int)date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

            $firstDayDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
            $lastDayDate = date('Y-m-t', strtotime($firstDayDate));

            $eventDays = [];
            $eventDetails = [];
            $totalKegiatanBulanIni = 0;
            $totalZoomBulanIni = 0;
            $totalSuratMasukBulanIni = 0;
            $totalSuratKeluarBulanIni = 0;
            $monthName = $namaBulan[$currentMonth];

            if (!$dbError) {
            $miniCalStmt = $pdo->prepare("SELECT id, title, agenda_date, agenda_time, agenda_date_end, agenda_mode, entry_type, zoom_link, zoom_meeting_id, zoom_passcode, description, ai_generated FROM agendas WHERE (entry_type = 'kegiatan' OR entry_type = 'agenda_zoom' OR (entry_type = 'surat' AND (zoom_link IS NOT NULL AND zoom_link != '' OR zoom_meeting_id IS NOT NULL AND zoom_meeting_id != ''))) AND (
                (agenda_date_end IS NULL AND agenda_date >= ? AND agenda_date <= ?)
                OR
                (agenda_date_end IS NOT NULL AND agenda_date <= ? AND agenda_date_end >= ?)
            )");
            $miniCalStmt->execute([$firstDayDate, $lastDayDate, $lastDayDate, $firstDayDate]);
            $miniCalEvents = $miniCalStmt->fetchAll();
            foreach($miniCalEvents as &$r) { $r['title'] = str_replace('Tindak Lanjut Surat: ', '', $r['title'] ?? ''); } unset($r);
            $eventDays = [];
            $eventDetails = [];
            $totalKegiatanBulanIni = 0;
            $totalZoomBulanIni = 0;
            foreach ($miniCalEvents as $row) {
                // Filter publik (sama seperti tabel kegiatan & zoom):
                // surat non-acara (pemberitahuan, permohonan, keputusan,
                // pemutakhiran, nota dinas, dll) tidak tampil di kalender,
                // KECUALI: (1) entri manual — user sengaja setting tanggal
                // pelaksanaan sendiri (mis. e-learning berhari-hari online),
                // atau (2) punya link meeting asli (tampil sebagai agenda Zoom).
                $calCtx = (string) ($row['title'] ?? '') . ' ' . (string) ($row['description'] ?? '');
                $cleanMeetingId = preg_replace('/\D/', '', (string)($row['zoom_meeting_id'] ?? ''));
                $isRealMeeting = isPublicMeetingLink($row['zoom_link'] ?? null, $calCtx) || strlen($cleanMeetingId) >= 9;
                $isManualEntry = (int) ($row['ai_generated'] ?? 1) === 0;
                if (!$isManualEntry && !isPublicAgendaTitle((string) ($row['title'] ?? ''), $calCtx) && !$isRealMeeting) {
                    continue;
                }

                $isZoom = in_array(strtolower((string)($row['agenda_mode'] ?? '')), ['zoom', 'hybrid']) || $isRealMeeting;
                
                if ($isZoom) {
                    $totalZoomBulanIni++;
                } else if (($row['entry_type'] ?? '') === 'kegiatan' || ($row['entry_type'] ?? '') === 'agenda_zoom') {
                    $totalKegiatanBulanIni++;
                }

                if ($row['agenda_date']) {
                                        $startDate = $row['agenda_date'];
                    $endDate = !empty($row['agenda_date_end']) ? $row['agenda_date_end'] : $startDate;
                    
                    $startTs = strtotime($startDate);
                    $endTs = strtotime($endDate);
                    
                    $monthStartTs = strtotime($firstDayDate);
                    $monthEndTs = strtotime($lastDayDate);
                    
                    $loopStartTs = max($startTs, $monthStartTs);
                    $loopEndTs = min($endTs, $monthEndTs);
                    
                    for ($ts = $loopStartTs; $ts <= $loopEndTs; $ts = strtotime('+1 day', $ts)) {
                        $day = (int)date('j', $ts);
                        $mode = strtolower((string) ($row['agenda_mode'] ?? 'offline'));
                        if (!isset($eventDays[$day]) || $mode === 'zoom' || $mode === 'hybrid') {
                            $eventDays[$day] = $mode;
                        }
                        $timeStr = !empty($row['agenda_time']) ? date('H:i', strtotime($row['agenda_time'])) . ' WIB' : '';
                        
                        $isDuplicate = false;
                        if (!empty($eventDetails[$day])) {
                            foreach ($eventDetails[$day] as $existing) {
                                if ($existing['id'] === $row['id'] || ($existing['title'] === ($row['title'] ?? 'Agenda') && $existing['time'] === $timeStr)) {
                                    $isDuplicate = true;
                                    break;
                                }
                            }
                        }
                        
                        if (!$isDuplicate) {
                            $eventDetails[$day][] = ['id' => $row['id'], 'title' => $row['title'] ?? 'Agenda', 'mode' => $mode, 'time' => $timeStr, 'end' => $endDate];
                        }
                    }
                }
            }

            $statsBulanIniStmt = $pdo->prepare("SELECT SUM(CASE WHEN direction = 'masuk' THEN 1 ELSE 0 END) as total_masuk, SUM(CASE WHEN direction = 'keluar' THEN 1 ELSE 0 END) as total_keluar FROM agendas WHERE entry_type = 'surat' AND agenda_date >= ? AND agenda_date < ?");
            $statsBulanIniStmt->execute([$monthStart, $monthEnd]);
            $suratStats = $statsBulanIniStmt->fetch();
            $totalSuratMasukBulanIni = (int)($suratStats['total_masuk'] ?? 0);
            $totalSuratKeluarBulanIni = (int)($suratStats['total_keluar'] ?? 0);
            } // end if (!$dbError)

            $monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $monthName = $monthNames[$currentMonth - 1];
            
            $prevMonth = $currentMonth - 1;
            $prevYear = $currentYear;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
            }
            
            $nextMonth = $currentMonth + 1;
            $nextYear = $currentYear;
            if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear++;
            }
            ?>
            <?php
            // Hitung agenda hari ini untuk ditampilkan di header kalender
            $todayInt = (int)date('j');
            $agendaHariIni = isset($eventDetails[$todayInt]) ? count($eventDetails[$todayInt]) : 0;
            ?>
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <a href="?m=<?= $prevMonth ?>&y=<?= $prevYear ?>" class="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition" title="Bulan Sebelumnya">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <h2 class="text-base font-bold text-slate-900 dark:text-slate-100"><?= $monthName . ' ' . $currentYear ?></h2>
                    <a href="?m=<?= $nextMonth ?>&y=<?= $nextYear ?>" class="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition" title="Bulan Berikutnya">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($agendaHariIni > 0): ?>
                    <span class="badge-header-hari-ini inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] sm:text-[11px] font-bold border shadow-sm transition-colors">
                        <span class="relative flex h-2 w-2">
                            <span class="badge-dot-ping animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                            <span class="badge-dot-core relative inline-flex rounded-full h-2 w-2"></span>
                        </span>
                        <span>Hari ini: <strong><?= $agendaHariIni ?></strong> agenda</span>
                    </span>
                    <?php else: ?>
                    <span class="text-[10px] sm:text-[11px] font-medium text-slate-400 dark:text-slate-400">Tidak ada agenda hari ini</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold tracking-wide text-slate-400 uppercase mb-2">
                <div>Mg</div><div>Sn</div><div>Sl</div><div>Rb</div><div>Km</div><div>Jm</div><div>Sb</div>
            </div>
            <div class="grid grid-cols-7 gap-1 text-center text-sm" x-data="{ 
                events: <?= htmlspecialchars(json_encode($eventDetails), ENT_QUOTES, 'UTF-8') ?>,
                currentMonth: <?= $currentMonth ?>,
                currentYear: <?= $currentYear ?>,
                monthName: '<?= $monthName ?>',
                showAgenda(day) { 
                    let mStr = String(this.currentMonth).padStart(2, '0');
                    let dStr = String(day).padStart(2, '0');
                    let dateStr = `${this.currentYear}-${mStr}-${dStr}`;
                    let addUrl = `agenda_form.php?entry_type=kegiatan&agenda_date=${dateStr}&return_to=index.php`;
                    let items = this.events[day] || [];
                    
                    let html = '';
                    if (items.length === 0) {
                        html = `
                            <div class='text-center py-4 text-slate-500 dark:text-slate-400'>
                                <svg class='w-10 h-10 mx-auto text-slate-300 dark:text-slate-600 mb-2' fill='none' stroke='currentColor' viewBox='0 0 24 24'><rect x='3' y='4' width='18' height='18' rx='2' ry='2'></rect><line x1='16' y1='2' x2='16' y2='6'></line><line x1='8' y1='2' x2='8' y2='6'></line><line x1='3' y1='10' x2='21' y2='10'></line></svg>
                                <p class='calendar-empty-text text-xs font-semibold text-slate-700 dark:text-slate-300'>Belum ada kegiatan pada tanggal ini.</p>
                                <p class='calendar-empty-subtext text-[11px] text-slate-400 dark:text-slate-500 mt-0.5'>Klik tombol di bawah untuk menambah kegiatan manual.</p>
                            </div>
                            <div class='mt-3'>
                                <a href='${addUrl}' class='flex items-center justify-center gap-1.5 w-full py-2.5 px-4 bg-teal-600 hover:bg-teal-700 text-white rounded-xl font-bold text-xs transition shadow-sm'>
                                    <svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24' stroke-width='2.5'><line x1='12' y1='5' x2='12' y2='19'></line><line x1='5' y1='12' x2='19' y2='12'></line></svg>
                                    Tambah Kegiatan Manual
                                </a>
                            </div>
                        `;
                    } else {
                        html = `
                            <div class='space-y-2 mt-2 mb-3 text-left max-h-60 overflow-y-auto pr-1'>
                                ${items.map(e => {
                                    let mode = (e.mode || 'offline').toLowerCase();
                                    let dotClass = mode === 'zoom' ? 'bg-rose-500' : (mode === 'hybrid' ? 'bg-amber-500' : 'bg-teal-500');
                                    let modeLabel = mode === 'zoom' ? 'Zoom' : (mode === 'hybrid' ? 'Hybrid' : 'Offline');
                                    return `
                                    <div class='calendar-event-card p-2.5 bg-slate-50 dark:bg-slate-900/80 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 transition'>
                                        <div class='flex items-center gap-1.5 mb-1'>
                                            <span class='w-2 h-2 rounded-full ${dotClass}'></span>
                                            <span class='calendar-event-meta text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400'>${modeLabel}</span>
                                            ${e.time ? `<span class='text-slate-300 dark:text-slate-600'>•</span><span class='calendar-event-meta text-xs font-semibold text-slate-500 dark:text-slate-400'>${e.time}</span>` : ''}
                                        </div>
                                        <p class='calendar-event-title text-xs font-bold text-slate-800 dark:text-white leading-snug'>${e.title}</p>
                                        <div class='calendar-event-divider mt-2 flex items-center gap-2 pt-1 border-t border-slate-100 dark:border-slate-700/50'>
                                            <a href='surat_detail_v2.php?id=${e.id}' class='calendar-event-link text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline'>Detail</a>
                                        </div>
                                    </div>
                                    `;
                                }).join('')}
                            </div>
                            <div class='pt-2 border-t border-slate-100 dark:border-slate-700'>
                                <a href='${addUrl}' class='flex items-center justify-center gap-1.5 w-full py-2 px-3 bg-teal-600 hover:bg-teal-700 text-white rounded-xl font-bold text-xs transition shadow-sm'>
                                    <svg class='w-3.5 h-3.5' fill='none' stroke='currentColor' viewBox='0 0 24 24' stroke-width='2.5'><line x1='12' y1='5' x2='12' y2='19'></line><line x1='5' y1='12' x2='19' y2='12'></line></svg>
                                    Tambah Kegiatan di Tanggal Ini
                                </a>
                            </div>
                        `;
                    }
                    Swal.fire({
                        title: `<div class='flex items-center gap-2.5'><div class='p-1.5 bg-teal-50 dark:bg-teal-500/10 rounded-lg'><svg class='w-5 h-5 text-teal-600 dark:text-teal-400' fill='none' stroke='currentColor' viewBox='0 0 24 24'><rect x='3' y='4' width='18' height='18' rx='2' ry='2'></rect><line x1='16' y1='2' x2='16' y2='6'></line><line x1='8' y1='2' x2='8' y2='6'></line><line x1='3' y1='10' x2='21' y2='10'></line></svg></div><span class='text-base font-bold text-slate-800 dark:text-white'>Agenda ${day} ${this.monthName}</span></div>`,
                        html: html,
                        showConfirmButton: true,
                        showCloseButton: true,
                        confirmButtonText: 'Tutup',
                        buttonsStyling: false,
                        customClass: {
                            popup: 'rounded-3xl p-5 bg-white dark:bg-slate-800 shadow-2xl border border-slate-100 dark:border-slate-700 max-w-sm max-h-[85vh] overflow-y-auto',
                            title: 'text-left pb-3 border-b border-slate-100 dark:border-slate-700/50 mb-2',
                            htmlContainer: 'text-left m-0',
                            closeButton: 'text-slate-400 hover:text-slate-700 dark:text-slate-500 dark:hover:text-slate-300',
                            confirmButton: 'w-full mt-3 py-2 px-4 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 rounded-xl font-semibold text-xs transition outline-none'
                        },
                        heightAuto: false,
                        backdrop: `rgba(15, 23, 42, 0.5) backdrop-blur-sm`
                    });
                } 
            }">
                <?php for ($i = 0; $i < $firstDayOfMonth; $i++): ?>
                    <div class="py-2"></div>
                <?php endfor; ?>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                    $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $d);
                    $isToday = $dateStr === $today;
                    $isPast = $dateStr < $today;
                    $hasEvent = isset($eventDays[$d]);
                    $eventMode = $hasEvent ? $eventDays[$d] : 'offline';
                    
                    $bgClass = '';
                    $textClass = 'text-slate-700 dark:text-slate-300';
                    
                    if ($isPast) {
                        $textClass = 'text-slate-400 dark:text-slate-500';
                        if ($hasEvent) {
                            $bgClass = 'bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700';
                        }
                    } elseif ($isToday) {
                        $bgClass = 'calendar-today-cell';
                        $textClass = 'text-white font-bold';
                    } elseif ($hasEvent) {
                        $bgClass = 'bg-indigo-100 dark:bg-indigo-500/20 border border-indigo-300 dark:border-indigo-700';
                        $textClass = 'text-indigo-700 dark:text-indigo-300 font-bold';
                    }
                    
                    // Tentukan dot yang muncul: merah (zoom) & hijau (offline) — bisa keduanya
                    $hasZoom = false;
                    $hasOffline = false;
                    if ($hasEvent && !empty($eventDetails[$d])) {
                        foreach ($eventDetails[$d] as $ev) {
                            $m = strtolower((string)($ev['mode'] ?? 'offline'));
                            if ($m === 'zoom' || $m === 'hybrid') {
                                $hasZoom = true;
                            } else {
                                $hasOffline = true;
                            }
                        }
                    }
                    // Tentukan apakah agenda sudah selesai berdasarkan tanggal akhir agenda
                    $hasFinishedEvent = false;
                    if ($hasEvent && !empty($eventDetails[$d])) {
                        foreach ($eventDetails[$d] as $ev) {
                            $evEnd = $ev['end'] ?? '';
                            if ($evEnd === '') {
                                $evEnd = $dateStr;
                            }
                            if ($evEnd < $today) {
                                $hasFinishedEvent = true;
                            }
                        }
                    }
                    // Event di tanggal lewat = selesai
                    $isPastEvent = $isPast || $hasFinishedEvent;
                ?>
                    <div @click="showAgenda(<?= $d ?>)" class="py-2.5 sm:py-3 rounded-xl <?= $bgClass ?> <?= $textClass ?> cursor-pointer hover:opacity-80 transition-all relative flex items-center justify-center" title="Klik tanggal <?= $d ?> untuk melihat/tambah agenda">
                        <span><?= $d ?></span>
                        <?php if ($hasEvent): ?>
                            <?php if ($isToday): ?>
                                <div class="absolute bottom-1 flex items-center gap-0.5">
                                    <?php if ($hasZoom): ?><span class="w-2 h-2 rounded-full bg-rose-500 shadow-sm border border-indigo-600"></span><?php endif; ?>
                                    <?php if ($hasOffline): ?><span class="w-2 h-2 rounded-full bg-teal-500 shadow-sm border border-indigo-600"></span><?php endif; ?>
                                </div>
                            <?php elseif ($isPastEvent): ?>
                                <div class="absolute bottom-1 flex items-center gap-0.5">
                                    <?php if ($hasZoom): ?><span class="w-1.5 h-1.5 rounded-full bg-rose-500" style="opacity: 0.35;"></span><?php endif; ?>
                                    <?php if ($hasOffline): ?><span class="w-1.5 h-1.5 rounded-full bg-teal-500" style="opacity: 0.35;"></span><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="absolute bottom-1 flex items-center gap-0.5">
                                    <?php if ($hasZoom): ?><span class="w-2 h-2 rounded-full bg-rose-500"></span><?php endif; ?>
                                    <?php if ($hasOffline): ?><span class="w-2 h-2 rounded-full bg-teal-500"></span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            
            <div class="mt-4 flex flex-wrap items-center justify-center gap-3 border-t border-slate-100 dark:border-slate-700 pt-4">
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Hari Ini</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Zoom</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-teal-500"></span>
                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Offline</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-slate-400 dark:bg-slate-500"></span>
                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Selesai</span>
                </div>
            </div>

            <!-- Shortcut Tambah Kegiatan & Buka Kalender -->
            <div class="mt-3 flex items-center justify-between border-t border-slate-100 dark:border-slate-700 pt-3">
                <a href="agenda_form.php?entry_type=kegiatan&return_to=index.php" class="inline-flex items-center gap-1.5 rounded-xl bg-teal-50 dark:bg-teal-950/30 border border-teal-200 dark:border-teal-800/50 px-2.5 py-1.5 text-xs font-bold text-teal-700 dark:text-teal-300 hover:bg-teal-100 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Kegiatan Manual
                </a>
                <a href="calendar.php" class="text-xs font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                    Buka Kalender &rarr;
                </a>
            </div>
        </div>                </div> <!-- End sticky wrapper -->
                </div> <!-- End Bagian Kanan -->
            </div>
        </div>

        <?php endif; // end !publicAgendaError ?>
        <!-- ===== END: Agenda Publik ===== -->

        <!-- Mobile Stats Accordion (Hidden on Desktop) -->
        <div class="custom-lg-hidden mx-auto max-w-7xl px-4 sm:px-6 pb-8" x-data="{ showMobileStats: window.location.hash.includes('stats') }">
            <button @click="showMobileStats = !showMobileStats" class="w-full rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-5 py-4 text-sm font-bold shadow-sm flex items-center justify-between text-slate-700 dark:text-slate-200 transition-colors hover:bg-slate-50 dark:hover:bg-slate-700/50">
                <span>Lihat Ringkasan Statistik</span>
                <svg :class="showMobileStats ? 'rotate-180' : ''" class="w-5 h-5 transition-transform duration-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div x-show="showMobileStats" x-transition.opacity.duration.300ms class="mt-4">
                <?= $statsHtml ?>
            </div>
        </div>

        <!-- Feature Cards Section &mdash; dipindah ke sini agar konten jadwal tampil lebih dulu -->
        <section id="fitur" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="mb-8">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-kcyan-600 dark:text-kcyan-300">Fitur Utama</p>
                <h2 class="mt-2 text-3xl font-extrabold">Semua kebutuhan agenda dalam satu platform</h2>
            </div>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <article role="article" class="feature-card transition-all duration-200 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none bg-white dark:bg-white/5 p-5" style="--fc-hover-color: rgba(96,165,250,0.5);">
                    <svg class="mb-3 h-7 w-7 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>
                    <h3 class="font-bold">Manajemen Agenda</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Input, edit, hapus, dan filter surat/kegiatan masuk-keluar secara terstruktur.</p>
                </article>
                <article role="article" class="feature-card transition-all duration-200 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none bg-white dark:bg-white/5 p-5" style="--fc-hover-color: rgba(52,211,153,0.5);">
                    <svg class="mb-3 h-7 w-7 text-brand-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" /></svg>
                    <h3 class="font-bold">Upload Lampiran</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Simpan file surat atau dokumentasi kegiatan agar mudah dilacak kembali.</p>
                </article>
                <article role="article" class="feature-card transition-all duration-200 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none bg-white dark:bg-white/5 p-5" style="--fc-hover-color: rgba(167,139,250,0.5);">
                    <svg class="mb-3 h-7 w-7 text-violet-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <h3 class="font-bold">Multi User Login</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Hak akses admin dan sekretaris dipisah untuk menjaga keamanan data.</p>
                </article>
                <article role="article" class="feature-card transition-all duration-200 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm dark:shadow-none bg-white dark:bg-white/5 p-5" style="--fc-hover-color: rgba(251,191,36,0.5);">
                    <svg class="mb-3 h-7 w-7 text-klime-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    <h3 class="font-bold">Export Laporan</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Cetak laporan agenda ke Excel atau PDF kapan saja saat diperlukan.</p>
                </article>
            </div>
        </section>


    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-200 dark:border-white/10 bg-slate-100 dark:bg-slate-950">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
                <p class="text-sm text-slate-600 dark:text-slate-400">&copy; <?= date('Y') ?> Balai Besar Laboratorium Kesehatan Lingkungan.</p>
                <nav aria-label="Tautan footer" class="flex items-center gap-2">
                    <a href="index.php" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors duration-200">Beranda</a>
                    <span class="text-slate-400 dark:text-slate-600" aria-hidden="true">&bull;</span>
                    <a href="#agenda-kegiatan" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors duration-200">Jadwal</a>
                    <span class="text-slate-400 dark:text-slate-600" aria-hidden="true">&bull;</span>
                    <a href="login.php" class="text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors duration-200">Login</a>
                </nav>
            </div>
        </div>
    </footer>

    <!-- Fix 12: Back to Top Button -->
    <button id="back-to-top"
            onclick="window.scrollTo({top:0,behavior:'smooth'})"
            aria-label="Kembali ke atas"
            title="Kembali ke atas"
            class="flex items-center justify-center w-11 h-11 rounded-full bg-kcyan-600 hover:bg-kcyan-500 text-white shadow-lg shadow-cyan-600/30 transition-all duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>
        </svg>
    </button>

    <!-- Fix #9: Bottom Nav Mobile (sm ke bawah saja) -->
    <nav aria-label="Navigasi cepat mobile"
         class="sm:hidden fixed bottom-0 left-0 right-0 z-30 bg-white/95 dark:bg-slate-900/95 backdrop-blur-md border-t border-slate-200 dark:border-white/10 flex items-center justify-around px-2 py-2 shadow-xl">
        <a href="#main-content" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;"
           class="flex flex-col items-center gap-0.5 text-slate-500 dark:text-slate-400 hover:text-kcyan-600 dark:hover:text-kcyan-400 transition-colors min-w-[56px]">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
            <span class="text-[11px] font-semibold">Beranda</span>
        </a>
        <a href="#agenda-kegiatan"
           class="flex flex-col items-center gap-0.5 text-slate-500 dark:text-slate-400 hover:text-[#00b294] dark:hover:text-brand-400 transition-colors min-w-[56px]">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5"/></svg>
            <span class="text-[11px] font-semibold">Jadwal</span>
        </a>
        <a href="#agenda-zoom"
           class="flex flex-col items-center gap-0.5 text-slate-500 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 transition-colors min-w-[56px]">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
            <span class="text-[11px] font-semibold">Zoom</span>
        </a>
        <!-- Dark mode toggle -->
        <button type="button"
                onclick="const isDark=document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',isDark);"
                class="flex flex-col items-center gap-0.5 text-slate-500 dark:text-slate-400 hover:text-yellow-500 dark:hover:text-yellow-400 transition-colors min-w-[56px]">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
            <span class="text-[11px] font-semibold">Tema</span>
        </button>
        <a href="login.php"
           class="flex flex-col items-center gap-0.5 text-kcyan-600 dark:text-kcyan-400 min-w-[56px]">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
            <span class="text-[11px] font-semibold">Login</span>
        </a>
    </nav>
    <!-- Spacer agar konten tidak tertutup bottom nav di mobile -->
    <div class="sm:hidden h-16"></div>

    <script>
        // Skeleton loader: show skeleton briefly, then reveal real content
        (function () {
            var contents = document.querySelectorAll('.stats-content');
            var skeletons = document.querySelectorAll('.stats-skeleton');
            for(var i=0; i<contents.length; i++) {
                if (contents[i] && skeletons[i]) {
                    contents[i].style.display = 'none';
                    skeletons[i].style.display = 'grid';
                    (function(content, skeleton) {
                        setTimeout(function () {
                            skeleton.style.display = 'none';
                            content.style.display = 'block';
                        }, 400);
                    })(contents[i], skeletons[i]);
                }
            }
        })();

        // IntersectionObserver for staggered feature card animation
        (function () {
            var cards = document.querySelectorAll('.feature-card');
            if (!cards.length) return;

            // Respect reduced motion
            var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (prefersReduced) {
                cards.forEach(function (el) { el.classList.add('visible'); });
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry, i) {
                    if (entry.isIntersecting) {
                        var idx = Array.prototype.indexOf.call(cards, entry.target);
                        setTimeout(function () {
                            entry.target.classList.add('visible');
                        }, idx * 100);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            cards.forEach(function (el) { observer.observe(el); });
        })();

        // Fix 5: Auto-scroll ke anchor setelah form search submit
        <?php if ($scrollAnchor): ?>
        (function() {
            var el = document.getElementById('<?= e($scrollAnchor) ?>');
            if (el) { setTimeout(function(){ el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150); }
        })();
        <?php endif; ?>

        // Fix 12: Back to top button visibility
        (function() {
            var btn = document.getElementById('back-to-top');
            if (!btn) return;
            window.addEventListener('scroll', function() {
                if (window.scrollY > 400) btn.classList.add('visible');
                else btn.classList.remove('visible');
            }, { passive: true });
        })();

        // Datetime live clock di header
        (function() {
            var hariNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
            var bulanNames = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
            var elTime = document.getElementById('hdr-datetime');
            var elDate = document.getElementById('hdr-date');
            if (!elTime || !elDate) return;
            function update() {
                var now = new Date();
                var h = String(now.getHours()).padStart(2,'0');
                var m = String(now.getMinutes()).padStart(2,'0');
                var s = String(now.getSeconds()).padStart(2,'0');
                elTime.textContent = h + ':' + m + ':' + s + ' WIB';
                var hari = hariNames[now.getDay()];
                var tgl  = now.getDate();
                var bln  = bulanNames[now.getMonth()];
                var thn  = now.getFullYear();
                elDate.textContent = hari + ', ' + tgl + ' ' + bln + ' ' + thn;
            }
            update();
            setInterval(update, 1000);
        })();
    </script>
</body>
</html>

