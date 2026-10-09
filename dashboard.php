<?php
declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();
if (isArsiparis()) {
    redirect('agenda.php');
}
$flash = getFlash();
$suratQ = trim((string) ($_GET['surat_q'] ?? ''));
$kegiatanQ = trim((string) ($_GET['kegiatan_q'] ?? ''));
$zoomQ = trim((string) ($_GET['zoom_q'] ?? ''));
$filterMonth = trim((string) ($_GET['filter_month'] ?? ''));
if ($filterMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = '';
}
$suratP = max(1, (int) ($_GET['surat_p'] ?? 1));
$kegiatanP = max(1, (int) ($_GET['kegiatan_p'] ?? 1));
$zoomP = max(1, (int) ($_GET['zoom_p'] ?? 1));
$adminDispP = max(1, (int) ($_GET['admin_disp_p'] ?? 1));
$pegawaiDispP = max(1, (int) ($_GET['pegawai_disp_p'] ?? 1));
$rawLimit = (int) ($_GET['limit'] ?? ($_GET['disp_limit'] ?? 5));
$limit = in_array($rawLimit, [5, 10, 20, 50], true) ? $rawLimit : 5;

$suratOffset = ($suratP - 1) * $limit;
$kegiatanOffset = ($kegiatanP - 1) * $limit;
$zoomOffset = ($zoomP - 1) * $limit;
$adminDispOffset = ($adminDispP - 1) * $limit;
$pegawaiDispOffset = ($pegawaiDispP - 1) * $limit;

// Approximation of totals (ignoring search queries for simplicity of pagination on dashboard)
$suratTotalSql = "SELECT COUNT(*) FROM agendas WHERE entry_type = 'surat'";
if ($filterMonth !== '') $suratTotalSql .= " AND DATE_FORMAT(COALESCE(letter_date, recorded_date, agenda_date), '%Y-%m') = " . $pdo->quote($filterMonth);
$suratTotal = (int)$pdo->query($suratTotalSql)->fetchColumn();

$kegiatanTotalSql = "SELECT COUNT(*) FROM agendas WHERE entry_type IN ('kegiatan', 'agenda_zoom')";
if ($filterMonth !== '') $kegiatanTotalSql .= " AND DATE_FORMAT(agenda_date, '%Y-%m') = " . $pdo->quote($filterMonth);
$kegiatanTotal = (int)$pdo->query($kegiatanTotalSql)->fetchColumn();

$zoomTotalSql = "SELECT COUNT(*) FROM agendas WHERE entry_type IN ('kegiatan', 'agenda_zoom') AND (agenda_mode IN ('zoom', 'hybrid') OR (zoom_link IS NOT NULL AND zoom_link <> ''))";
if ($filterMonth !== '') $zoomTotalSql .= " AND DATE_FORMAT(agenda_date, '%Y-%m') = " . $pdo->quote($filterMonth);
$zoomTotal = (int)$pdo->query($zoomTotalSql)->fetchColumn();
$adminDispTotal = !isPegawai() ? (int)$pdo->query("SELECT COUNT(*) FROM agenda_disposition_items")->fetchColumn() : 0;



$cardStatsStmt = $pdo->query(
    "SELECT
        SUM(CASE WHEN entry_type = 'surat' AND direction = 'masuk' AND MONTH(recorded_date) = MONTH(CURDATE()) AND YEAR(recorded_date) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS surat_masuk_bulan_ini,
        SUM(CASE WHEN entry_type = 'surat' AND direction = 'keluar' AND MONTH(recorded_date) = MONTH(CURDATE()) AND YEAR(recorded_date) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS surat_keluar_bulan_ini,
        SUM(CASE WHEN entry_type = 'surat' AND direction = 'masuk' AND NOT EXISTS (SELECT 1 FROM agenda_disposition_items adi WHERE adi.agenda_id = agendas.id) THEN 1 ELSE 0 END) AS belum_disposisi,
        SUM(CASE WHEN entry_type = 'kegiatan' AND agenda_date = CURDATE() THEN 1 ELSE 0 END) AS agenda_hari_ini
     FROM agendas"
);
$cardStats = $cardStatsStmt->fetch() ?: [
    'surat_masuk' => 0,
    'surat_keluar' => 0,
    'kegiatan_hari_ini' => 0,
    'zoom_hari_ini' => 0,
    'zoom_mendatang' => 0,
];

$suratSql = "SELECT a.id, a.title, a.reference_number, a.direction, a.letter_date, a.recorded_date, a.counterparty, u.full_name AS created_by
             FROM agendas a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entry_type = 'surat'";
$suratParams = [];
if ($suratQ !== '') {
    $suratSql .= " AND (a.reference_number LIKE :q_s1 OR a.title LIKE :q_s2 OR a.counterparty LIKE :q_s3)";
    $suratParams['q_s1'] = '%' . $suratQ . '%';
    $suratParams['q_s2'] = '%' . $suratQ . '%';
    $suratParams['q_s3'] = '%' . $suratQ . '%';
}
if ($filterMonth !== '') {
    $suratSql .= " AND DATE_FORMAT(COALESCE(a.letter_date, a.recorded_date, a.agenda_date), '%Y-%m') = :fm";
    $suratParams['fm'] = $filterMonth;
}
$suratSql .= " ORDER BY COALESCE(a.letter_date, a.recorded_date, a.agenda_date) DESC, a.id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$suratOffset;
$suratStmt = $pdo->prepare($suratSql);
$suratStmt->execute($suratParams);
$suratRows = $suratStmt->fetchAll();

$kegiatanSql = "SELECT a.id, a.title, a.agenda_date, a.agenda_time, a.agenda_mode, a.location, a.status, a.counterparty, u.full_name AS created_by
                FROM agendas a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE a.entry_type = 'kegiatan'";
$kegiatanParams = [];
if ($kegiatanQ !== '') {
    $kegiatanSql .= " AND (a.title LIKE :q_k1 OR a.counterparty LIKE :q_k2 OR a.location LIKE :q_k3)";
    $kegiatanParams['q_k1'] = '%' . $kegiatanQ . '%';
    $kegiatanParams['q_k2'] = '%' . $kegiatanQ . '%';
    $kegiatanParams['q_k3'] = '%' . $kegiatanQ . '%';
}
if ($filterMonth !== '') {
    $kegiatanSql .= " AND DATE_FORMAT(a.agenda_date, '%Y-%m') = :fm";
    $kegiatanParams['fm'] = $filterMonth;
}
$kegiatanSql .= " ORDER BY a.agenda_date DESC, a.id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$kegiatanOffset;
$kegiatanStmt = $pdo->prepare($kegiatanSql);
$kegiatanStmt->execute($kegiatanParams);
$kegiatanRows = $kegiatanStmt->fetchAll();

$zoomSql = "SELECT a.id, a.user_id, a.title, a.agenda_date, a.agenda_time, a.zoom_link, a.zoom_meeting_id, a.source_agenda_id, s.attachment AS source_attachment
            FROM agendas a
            LEFT JOIN agendas s ON s.id = a.source_agenda_id
            WHERE a.entry_type = 'kegiatan'
              AND (a.agenda_mode IN ('zoom', 'hybrid') OR (a.zoom_link IS NOT NULL AND a.zoom_link <> ''))";
$zoomParams = [];
if ($zoomQ !== '') {
    $zoomSql .= " AND (a.title LIKE :q_z1 OR a.zoom_meeting_id LIKE :q_z2 OR a.zoom_link LIKE :q_z3)";
    $zoomParams['q_z1'] = '%' . $zoomQ . '%';
    $zoomParams['q_z2'] = '%' . $zoomQ . '%';
    $zoomParams['q_z3'] = '%' . $zoomQ . '%';
}
if ($filterMonth !== '') {
    $zoomSql .= " AND DATE_FORMAT(a.agenda_date, '%Y-%m') = :fm";
    $zoomParams['fm'] = $filterMonth;
}
$zoomSql .= " ORDER BY a.agenda_date DESC, a.id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$zoomOffset;
$zoomStmt = $pdo->prepare($zoomSql);
$zoomStmt->execute($zoomParams);
$zoomAgendas = $zoomStmt->fetchAll();

$pegawaiDispositions = [];
$adminDispositions = [];
$pegawaiStats = ['total' => 0, 'baru' => 0, 'selesai' => 0];
if (!isPegawai()) {
    $adminDispStmt = $pdo->query("
        SELECT d.*, a.title, a.reference_number
        FROM agenda_disposition_items d
        JOIN agendas a ON d.agenda_id = a.id
        ORDER BY d.disposition_at DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$adminDispOffset . "
    ");
    $adminDispositions = $adminDispStmt->fetchAll();
}
$pegawaiStats = ['total' => 0, 'baru' => 0, 'selesai' => 0];
if (isPegawai()) {
    $empId = (int) ($_SESSION['user']['employee_id'] ?? 0);
    $dispStmt = $pdo->prepare("
        SELECT d.*, a.title, a.reference_number, a.letter_date
        FROM agenda_disposition_items d
        JOIN agendas a ON d.agenda_id = a.id
        WHERE d.employee_id = :emp_id
        ORDER BY d.disposition_at DESC
    ");
    $dispStmt->execute(['emp_id' => $empId]);
    $allDisp = $dispStmt->fetchAll();
    
    $pegawaiStats['total'] = count($allDisp);
    foreach ($allDisp as $d) {
        if ($d['read_status'] === 'belum') {
            $pegawaiStats['baru']++;
        } else {
            $pegawaiStats['selesai']++;
        }
    }
    
    $pegawaiDispositions = array_slice($allDisp, $pegawaiDispOffset, $limit);
}

function dashboardKegiatanStatusBadge(array $row): array
{
    $startDate = $row['agenda_date'] ?? null;
    $endDate = $row['agenda_date_end'] ?? null;
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    if (!$startDate || $startDate === '0000-00-00') {
        return ['label' => 'Direncanakan', 'class' => 'text-white font-bold border-0 shadow-sm badge-direncanakan'];
    }

    $effectiveEnd = !empty($endDate) && $endDate !== '0000-00-00' ? $endDate : $startDate;
    if ($effectiveEnd < $startDate) {
        $effectiveEnd = $startDate;
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

function dashboardKegiatanModeBadge(string $mode): array
{
    $mode = strtolower(trim($mode));
    if ($mode === 'zoom') return ['label' => 'Zoom', 'class' => 'text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #f43f5e, #e11d48);'];
    if ($mode === 'hybrid') return ['label' => 'Hybrid', 'class' => 'text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #fbbf24, #ea580c);'];
    return ['label' => 'Offline', 'class' => 'text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #10b981, #0d9488);'];
}

function dashboardZoomTimeLabel(?string $time): string
{
    $value = trim((string) $time);
    if ($value === '' || $value === '00:00:00') {
        $value = '09:00:00';
    }
    return substr($value, 0, 5);
}

function dashboardZoomScheduleBadge(string $agendaDate, ?string $endDate = null): array
{
    $date = trim($agendaDate);
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $effectiveEnd = !empty($endDate) && $endDate !== '0000-00-00' ? trim((string)$endDate) : $date;
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

    // Rentang multi-hari yang sedang berjalan
    if ($date < $today && $effectiveEnd > $today) {
        return ['label' => 'Berlangsung', 'class' => 'text-white font-bold border-0 shadow-sm badge-moving badge-moving-berlangsung'];
    }

    // Hari ini jika tepat hari ini (single day) atau hari terakhir kegiatan
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

function dashboardNormalizeMeetingUrl(?string $url): ?string
{
    $value = trim((string) $url);
    if ($value === '') {
        return null;
    }
    $value = rtrim($value, ".,;:!?)]}");
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
}

// Chart Data: Tren Kegiatan per Bulan (Offline vs Zoom vs Hybrid)
$currentYear = date('Y');
$yearStart = $currentYear . '-01-01';
$yearEnd = ((int)$currentYear + 1) . '-01-01';
$chartDates = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
$kegiatanChartStmt = $pdo->query("
    SELECT MONTH(agenda_date) as bulan,
           SUM(CASE WHEN agenda_mode = 'offline' THEN 1 ELSE 0 END) as offline,
           SUM(CASE WHEN agenda_mode = 'zoom' THEN 1 ELSE 0 END) as zoom,
           SUM(CASE WHEN agenda_mode = 'hybrid' THEN 1 ELSE 0 END) as hybrid
    FROM agendas
    WHERE entry_type IN ('kegiatan', 'agenda_zoom') AND agenda_date >= '{$yearStart}' AND agenda_date < '{$yearEnd}'
    GROUP BY MONTH(agenda_date)
    ORDER BY bulan ASC
");
$rawKegiatanChart = $kegiatanChartStmt->fetchAll();
$chartOffline = array_fill(0, 12, 0);
$chartZoom = array_fill(0, 12, 0);
$chartHybrid = array_fill(0, 12, 0);
foreach ($rawKegiatanChart as $row) {
    $idx = (int)$row['bulan'] - 1;
    if ($idx >= 0 && $idx <= 11) {
        $chartOffline[$idx] = (int)$row['offline'];
        $chartZoom[$idx] = (int)$row['zoom'];
        $chartHybrid[$idx] = (int)$row['hybrid'];
    }
}

// Chart Data 3: Komposisi Surat (Donut)
$suratChartStmt = $pdo->query("
    SELECT direction, COUNT(*) as total
    FROM agendas
    WHERE entry_type = 'surat'
    GROUP BY direction
");
$suratData = ['masuk' => 0, 'keluar' => 0];
while ($r = $suratChartStmt->fetch()) {
    $dir = strtolower((string)$r['direction']);
    if (isset($suratData[$dir])) {
        $suratData[$dir] = (int)$r['total'];
    }
}

$pageTitle = 'Dashboard Agenda';
require_once __DIR__ . '/includes/header.php';
?>
<div x-data="{ showFilter: false }">

    <!-- Mobile Quick Menu (Hanya tampil di layar kecil/mobile) -->
    <section class="mb-8 xl:hidden">
        <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200 mb-4 px-1">Menu Cepat</h2>
        <div class="grid grid-cols-4 gap-3 sm:gap-4">
            <a href="calendar.php" class="flex flex-col items-center gap-2 group">
                <div class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl bg-white shadow-sm border border-slate-200 text-teal-600 transition-all group-hover:bg-teal-50 group-hover:scale-105 group-hover:shadow-md dark:bg-slate-800 dark:border-slate-700 dark:text-teal-400 dark:group-hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="14" x2="8" y2="14"></line><line x1="12" y1="14" x2="12" y2="14"></line><line x1="16" y1="14" x2="16" y2="14"></line></svg>
                </div>
                <span class="text-[10px] sm:text-xs font-semibold text-slate-600 text-center leading-tight dark:text-slate-300 group-hover:text-teal-700 dark:group-hover:text-teal-400">Kalender</span>
            </a>
            
            <?php if (!isPegawai()): ?>
            <a href="agenda.php" class="flex flex-col items-center gap-2 group">
                <div class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl bg-white shadow-sm border border-slate-200 text-blue-600 transition-all group-hover:bg-blue-50 group-hover:scale-105 group-hover:shadow-md dark:bg-slate-800 dark:border-slate-700 dark:text-blue-400 dark:group-hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                </div>
                <span class="text-[10px] sm:text-xs font-semibold text-slate-600 text-center leading-tight dark:text-slate-300 group-hover:text-blue-700 dark:group-hover:text-blue-400">Surat</span>
            </a>
            <a href="agenda_kegiatan.php" class="flex flex-col items-center gap-2 group">
                <div class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl bg-white shadow-sm border border-slate-200 text-amber-600 transition-all group-hover:bg-amber-50 group-hover:scale-105 group-hover:shadow-md dark:bg-slate-800 dark:border-slate-700 dark:text-amber-400 dark:group-hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </div>
                <span class="text-[10px] sm:text-xs font-semibold text-slate-600 text-center leading-tight dark:text-slate-300 group-hover:text-amber-700 dark:group-hover:text-amber-400">Kegiatan</span>
            </a>
            <a href="agenda_zoom.php" class="flex flex-col items-center gap-2 group">
                <div class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl bg-white shadow-sm border border-slate-200 text-purple-600 transition-all group-hover:bg-purple-50 group-hover:scale-105 group-hover:shadow-md dark:bg-slate-800 dark:border-slate-700 dark:text-purple-400 dark:group-hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                </div>
                <span class="text-[10px] sm:text-xs font-semibold text-slate-600 text-center leading-tight dark:text-slate-300 group-hover:text-purple-700 dark:group-hover:text-purple-400">Zoom</span>
            </a>
            <?php endif; ?>
            
            <?php if (isAdmin() || isPimpinan()): ?>
            <a href="disposisi.php" class="flex flex-col items-center gap-2 group">
                <div class="relative flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl bg-white shadow-sm border border-slate-200 text-rose-600 transition-all group-hover:bg-rose-50 group-hover:scale-105 group-hover:shadow-md dark:bg-slate-800 dark:border-slate-700 dark:text-rose-400 dark:group-hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <?php if (isset($disposisiBadge) && $disposisiBadge > 0): ?>
                        <span class="absolute -top-1.5 -right-1.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full border-2 border-white bg-rose-500 px-1 text-[9px] font-bold text-white dark:border-slate-800"><?= $disposisiBadge > 99 ? '99+' : $disposisiBadge; ?></span>
                    <?php endif; ?>
                </div>
                <span class="text-[10px] sm:text-xs font-semibold text-slate-600 text-center leading-tight dark:text-slate-300 group-hover:text-rose-700 dark:group-hover:text-rose-400">Disposisi</span>
            </a>
            <?php endif; ?>
            
            <?php if (isPegawai()): ?>
            <a href="disposisi_saya.php" class="flex flex-col items-center gap-2 group">
                <div class="relative flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl bg-white shadow-sm border border-slate-200 text-emerald-600 transition-all group-hover:bg-emerald-50 group-hover:scale-105 group-hover:shadow-md dark:bg-slate-800 dark:border-slate-700 dark:text-emerald-400 dark:group-hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-7 sm:w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <?php if (isset($disposisiBadge) && $disposisiBadge > 0): ?>
                        <span class="absolute -top-1.5 -right-1.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full border-2 border-white bg-rose-500 px-1 text-[9px] font-bold text-white dark:border-slate-800"><?= $disposisiBadge > 99 ? '99+' : $disposisiBadge; ?></span>
                    <?php endif; ?>
                </div>
                <span class="text-[10px] sm:text-xs font-semibold text-slate-600 text-center leading-tight dark:text-slate-300 group-hover:text-emerald-700 dark:group-hover:text-emerald-400">Disposisi</span>
            </a>
            <?php endif; ?>
        </div>
    </section>

<?php if (!isPegawai()): ?>
<style>
/* Fallback agar kolom tabel bisa sembunyi di HP tanpa harus re-upload CSS Tailwind */
@media (max-width: 639px) {
    .hide-on-mobile { display: none !important; }
}
</style>
<section class="mb-8">
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-rose-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-rose-50 to-transparent dark:from-rose-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Belum Didisposisi</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) ($cardStats['belum_disposisi'] ?? 0); ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-emerald-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Surat Masuk <span class="lowercase text-xs font-medium text-slate-400">(Bulan Ini)</span></p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) ($cardStats['surat_masuk_bulan_ini'] ?? 0); ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-amber-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-amber-50 to-transparent dark:from-amber-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Agenda Hari Ini</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) ($cardStats['agenda_hari_ini'] ?? 0); ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-sky-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-sky-50 to-transparent dark:from-sky-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Surat Keluar <span class="lowercase text-xs font-medium text-slate-400">(Bulan Ini)</span></p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) ($cardStats['surat_keluar_bulan_ini'] ?? 0); ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </div>
            </div>
        </article>
    </div>
</section>
<?php else: ?>
<section class="mb-8">
    <div class="grid gap-6 sm:grid-cols-3 xl:grid-cols-3">
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-orange-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-orange-50 to-transparent dark:from-orange-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Total Disposisi</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= $pegawaiStats['total']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-100 dark:bg-orange-500/20 text-orange-600 dark:text-orange-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-emerald-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Disposisi Baru</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= $pegawaiStats['baru']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-white/10 transition-all duration-300 hover:shadow-xl hover:shadow-sky-500/20 hover:-translate-y-1.5 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-sky-50 to-transparent dark:from-sky-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-widest text-slate-500 dark:text-slate-400 uppercase">Selesai Dibaca</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= $pegawaiStats['selesai']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
        </article>
    </div>
</section>
<?php endif; ?>

<?php if (isPegawai()): ?>
<section class="mb-8 flex justify-center">
    <a href="disposisi_saya.php" class="group flex w-full items-center justify-between rounded-3xl bg-gradient-to-r from-brand-500 via-indigo-500 to-purple-500 p-2 pl-6 shadow-2xl transition-all hover:scale-[1.01] hover:shadow-indigo-500/50">
        <div class="flex items-center gap-5 py-4">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20 text-white backdrop-blur-md">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <div class="text-left text-white">
                <h2 class="text-2xl font-black tracking-wide">Buka Disposisi Saya</h2>
                <p class="mt-1 text-sm font-medium text-white/90">Lihat semua tugas dan arahan pimpinan yang masuk untuk Anda</p>
            </div>
        </div>
        <div class="mr-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-indigo-600 shadow-md transition-transform duration-300 group-hover:translate-x-1 group-hover:scale-110">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <path d="M5 12h14"></path>
                <path d="m12 5 7 7-7 7"></path>
            </svg>
        </div>
    </a>
</section>
<?php endif; ?>

<!-- Chart Section: Tren Kegiatan + Komposisi Mode -->
<section class="mb-8 grid gap-6 lg:grid-cols-3 lg:items-start">
    <!-- Line Chart: Tren Kegiatan per Bulan -->
    <div class="lg:col-span-2 rounded-3xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-6 shadow-sm flex flex-col transition-all duration-300 hover:shadow-lg">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100">Tren Kegiatan per Bulan (<?= $currentYear; ?>)</h2>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Garis tren menunjukkan jumlah kegiatan Offline, Zoom, dan Hybrid per bulan.</p>
        <div class="w-full flex-1 min-h-[300px] relative">
            <canvas id="kegiatanTrendChart"></canvas>
        </div>
    </div>

    <div class="flex flex-col gap-6">
        <!-- Donut: Perbandingan Surat -->
        <div class="rounded-3xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-6 shadow-sm flex flex-col transition-all duration-300 hover:shadow-lg">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-xl font-bold text-slate-900 dark:text-slate-100">Perbandingan Surat</h2>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Proporsi Surat Masuk vs Surat Keluar.</p>
            <div class="w-full flex-1 min-h-[200px] flex items-center justify-center relative">
                <canvas id="suratDonutChart"></canvas>
            </div>
        </div>
        
        <!-- Mini Calendar Widget -->
        <div class="rounded-3xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-5 shadow-sm transition-all duration-300 hover:shadow-lg">
            <?php
            $today = date('Y-m-d');
            $currentMonth = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');
            $currentYear = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
            if ($currentMonth < 1 || $currentMonth > 12) $currentMonth = (int)date('n');
            if ($currentYear < 2000 || $currentYear > 2100) $currentYear = (int)date('Y');
            $firstDayOfMonth = (int)date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

            $firstDayDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
            $lastDayDate = date('Y-m-t', strtotime($firstDayDate));

            $miniCalStmt = $pdo->prepare("SELECT id, title, agenda_date, agenda_time, agenda_date_end, agenda_mode FROM agendas WHERE entry_type IN ('kegiatan', 'agenda_zoom') AND (
                (agenda_date_end IS NULL AND agenda_date >= ? AND agenda_date <= ?)
                OR
                (agenda_date_end IS NOT NULL AND agenda_date <= ? AND agenda_date_end >= ?)
            )");
            $miniCalStmt->execute([$firstDayDate, $lastDayDate, $lastDayDate, $firstDayDate]);
            $miniCalEvents = $miniCalStmt->fetchAll();
            $eventDays = [];
            $eventDetails = [];
            foreach ($miniCalEvents as $row) {
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
                        // Simpan mode, jika ada zoom di hari yang sama, prioritaskan zoom untuk warna titiknya
                        $mode = strtolower((string) ($row['agenda_mode'] ?? 'offline'));
                        if (!isset($eventDays[$day])) {
                            $eventDays[$day] = [];
                        }
                        if ($mode === 'zoom' || $mode === 'hybrid') {
                            $eventDays[$day]['zoom'] = true;
                        } else {
                            $eventDays[$day]['offline'] = true;
                        }
                        $timeStr = !empty($row['agenda_time']) ? date('H:i', strtotime($row['agenda_time'])) . ' WIB' : '';
                            $eventDetails[$day][] = ['id' => $row['id'], 'title' => $row['title'] ?? 'Agenda', 'mode' => $mode, 'time' => $timeStr, 'end' => $endDate];
                    }
                }
            }

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
                <?php if ($agendaHariIni > 0): ?>
                    <span class="badge-header-hari-ini inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] sm:text-[11px] font-bold border shadow-sm transition-colors">
                        <span class="relative flex h-2 w-2">
                            <span class="badge-dot-ping animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                            <span class="badge-dot-core relative inline-flex rounded-full h-2 w-2"></span>
                        </span>
                        <span>Hari ini: <strong><?= $agendaHariIni ?></strong> agenda</span>
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-50 dark:bg-slate-800 px-2 py-1 text-[10px] font-bold text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">
                        Kosong
                    </span>
                <?php endif; ?>
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
                    let addUrl = `agenda_form.php?entry_type=kegiatan&agenda_date=${dateStr}&return_to=dashboard.php`;
                    let items = this.events[day] || [];
                    
                    let html = '';
                    if (items.length === 0) {
                        html = `
                            <div class='text-center py-4 text-slate-500 dark:text-slate-400'>
                                <svg class='w-10 h-10 mx-auto text-slate-300 dark:text-slate-600 mb-2' fill='none' stroke='currentColor' viewBox='0 0 24 24'><rect x='3' y='4' width='18' height='18' rx='2' ry='2'></rect><line x1='16' y1='2' x2='16' y2='6'></line><line x1='8' y1='2' x2='8' y2='6'></line><line x1='3' y1='10' x2='21' y2='10'></line></svg>
                                <p class='calendar-empty-text text-xs font-semibold text-slate-700 dark:text-slate-300'>Belum ada agenda pada tanggal ini.</p>
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
                                            <a href='surat_detail_v2.php?id=${e.id}' class='calendar-event-link text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline'>Lihat Detail</a>
                                            <span class='text-slate-300 dark:text-slate-600'>•</span>
                                            <a href='agenda_form.php?id=${e.id}&return_to=dashboard.php' class='text-[11px] font-bold text-amber-600 dark:text-amber-400 hover:underline'>Edit</a>
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
                    $hasZoom = !empty($eventDays[$d]['zoom']);
                    $hasOffline = !empty($eventDays[$d]['offline']);
                    $hasAnyIndicator = $hasZoom || $hasOffline;

                    // fallback jika data lama masih berbentuk string
                    if ($hasEvent && !$hasAnyIndicator && !empty($eventDays[$d]) && is_string($eventDays[$d])) {
                        $oldMode = strtolower($eventDays[$d]);
                        $hasZoom = ($oldMode === 'zoom' || $oldMode === 'hybrid');
                        $hasOffline = !$hasZoom;
                    }

                    $bgClass = '';
                    $textClass = 'text-slate-700 dark:text-slate-300';

                    if ($isPast) {
                        $textClass = 'text-slate-400 dark:text-slate-500';
                        if ($hasEvent) {
                            $bgClass = 'bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700';
                        }
                    } elseif ($isToday) {
                        $bgClass = 'bg-indigo-600 shadow-md shadow-indigo-500/20';
                        $textClass = 'text-white font-bold';
                    } elseif ($hasEvent) {
                        $bgClass = 'bg-indigo-100 dark:bg-indigo-500/20 border border-indigo-300 dark:border-indigo-700';
                        $textClass = 'text-indigo-700 dark:text-indigo-300 font-bold';
                    }

                    // Tentukan apakah agenda sudah selesai berdasarkan tanggal akhir
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
                    <div @click="showAgenda(<?= $d ?>)" class="py-1.5 sm:py-2 rounded-xl <?= $bgClass ?> <?= $textClass ?> cursor-pointer hover:opacity-80 transition-all relative flex items-center justify-center" title="Klik tanggal <?= $d ?> untuk melihat/tambah agenda">
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
            
            <div class="mt-4 flex flex-wrap items-center justify-center gap-3 border-t border-slate-100 dark:border-slate-700 pt-3">
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-indigo-600"></span>
                    <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Hari Ini</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                    <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Zoom</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-teal-500"></span>
                    <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Offline</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-slate-400 dark:bg-slate-500"></span>
                    <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase">Selesai</span>
                </div>
            </div>

            <!-- Shortcut Buka Kalender -->
            <div class="mt-3 flex items-center justify-end border-t border-slate-100 dark:border-slate-700 pt-3">
                <a href="calendar.php" class="text-xs font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                    Buka Kalender &rarr;
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Filter Bulan -->
<div class="mt-8 flex items-center justify-end">
    <form method="GET" action="dashboard.php" class="flex items-center gap-3 bg-white dark:bg-slate-800 px-4 py-3 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <label for="filter_month" class="text-sm font-semibold text-slate-700 dark:text-slate-300">Filter Bulan:</label>
        <input type="month" id="filter_month" name="filter_month" value="<?= e($filterMonth) ?>" class="rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition-colors">
        <button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 text-sm font-bold transition-colors">Terapkan</button>
        <?php if ($filterMonth !== ''): ?>
        <a href="dashboard.php" class="text-sm font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tab Switcher Dashboard -->
<section class="mt-4 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm"
         x-data="{ 
             activeTab: new URLSearchParams(window.location.search).get('tab') || localStorage.getItem('dashTab') || 'surat',
             init() {
                 this.$watch('activeTab', v => {
                     localStorage.setItem('dashTab', v);
                     const url = new URL(window.location);
                     url.searchParams.set('tab', v);
                     window.history.replaceState(null, '', url);
                 });
             }
         }">

    <!-- Tab Headers -->
    <div class="flex flex-wrap items-center justify-between border-b border-slate-200 dark:border-slate-700 px-6 pt-5 gap-3">
        <div class="flex gap-4 sm:gap-8 overflow-x-auto no-scrollbar items-center">
            <button type="button"
                    @click="activeTab = 'surat'"
                    :class="activeTab === 'surat' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition-colors whitespace-nowrap">
                Surat Terbaru
            </button>
            <button type="button"
                    @click="activeTab = 'kegiatan'"
                    :class="activeTab === 'kegiatan' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition-colors whitespace-nowrap">
                Kegiatan Mendatang
            </button>
            <button type="button"
                    @click="activeTab = 'zoom'"
                    :class="activeTab === 'zoom' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition-colors whitespace-nowrap">
                Zoom Terbaru
            </button>
            <?php if (!isPegawai()): ?>
            <button type="button"
                    @click="activeTab = 'disposisi'"
                    :class="activeTab === 'disposisi' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition-colors whitespace-nowrap">
                Disposisi Terbaru
            </button>
            <?php endif; ?>
        </div>

        <!-- Tampil Data Selector di Header Tab -->
        <form method="GET" action="dashboard.php" class="flex items-center gap-2 mb-2">
            <input type="hidden" name="tab" :value="activeTab">
            <?php if ($filterMonth !== ''): ?>
            <input type="hidden" name="filter_month" value="<?= e($filterMonth) ?>">
            <?php endif; ?>
            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 hidden sm:inline">Tampil Data:</label>
            <select name="limit" onchange="this.form.submit()" 
                    class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-1.5 text-xs font-bold text-slate-700 dark:text-slate-300 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                    title="Tampilkan data per halaman">
                <option value="5" <?= $limit === 5 ? 'selected' : ''; ?>>5 Data</option>
                <option value="10" <?= $limit === 10 ? 'selected' : ''; ?>>10 Data</option>
                <option value="20" <?= $limit === 20 ? 'selected' : ''; ?>>20 Data</option>
                <option value="50" <?= $limit === 50 ? 'selected' : ''; ?>>50 Data</option>
            </select>
        </form>
    </div>

    <!-- Tab: Surat Terbaru -->
    <div x-show="activeTab === 'surat'" x-cloak class="p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Surat Terbaru</h2>
            <a href="agenda.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Lihat Semua →</a>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full table-auto text-sm leading-6">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-widest text-slate-500 font-semibold">
                    <tr>
                        <th class="px-3 py-3 text-left w-12">No</th>
                        <th class="px-3 py-3 text-left">No. Surat</th>
                        <th class="px-3 py-3 text-left">Tanggal</th>
                        <th class="px-3 py-3 text-left">Jenis</th>
                        <th class="px-3 py-3 text-left">Perihal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                    <?php if (!$suratRows): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-14 text-center">
                                <div class="flex flex-col items-center justify-center space-y-3">
                                    <div class="rounded-full bg-slate-100 p-4 dark:bg-slate-800">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 19v-8.93a2 2 0 01.89-1.664l7-4.666a2 2 0 012.22 0l7 4.666A2 2 0 0121 10.07V19M3 19a2 2 0 002 2h14a2 2 0 002-2M3 19l6.75-4.5M21 19l-6.75-4.5M3 10l6.75 4.5M21 10l-6.75 4.5m0 0l-1.14.76a2 2 0 01-2.22 0l-1.14-.76" /></svg>
                                    </div>
                                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Belum ada data surat terbaru saat ini.</p>
                                    <?php if (!isPegawai()): ?>
                                    <a href="agenda_form.php" class="mt-2 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                        Tambah Surat
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suratRows as $i => $surat): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/50 transition-colors">
                            <td class="px-3 py-3 font-semibold text-indigo-600"><?= $suratOffset + $i + 1; ?></td>
                            <td class="px-3 py-3 font-semibold text-slate-800 dark:text-slate-200">
                                <?= e((string) ($surat['reference_number'] ?: '-')); ?>
                            </td>
                            <td class="px-3 py-3 text-slate-600 dark:text-slate-400"><?= e(date('d-m-Y', strtotime((string) (($surat['letter_date'] ?: $surat['recorded_date']) ?: date('Y-m-d'))))); ?></td>
                            <td class="px-3 py-3">
                                <?php if ($surat['direction'] === 'masuk'): ?>
                                    <span class="ui-badge text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #10b981, #059669);">Masuk</span>
                                <?php else: ?>
                                    <span class="ui-badge text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #38bdf8, #0284c7);">Keluar</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3">
                                <a href="surat_detail_v2.php?id=<?= (int) $surat['id']; ?>" class="font-semibold text-slate-800 dark:text-slate-200 hover:text-indigo-600"><?= e((string) $surat['title']); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View -->
        <div class="md:hidden flex flex-col gap-4 mt-2">
            <?php if (!$suratRows): ?>
                <div class="flex flex-col items-center justify-center p-8 border border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400 text-center">Belum ada data surat terbaru.</p>
                </div>
            <?php else: ?>
                <?php foreach ($suratRows as $i => $surat): ?>
                <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 shadow-sm relative transition-all hover:shadow-md">
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400"><?= e((string) ($surat['reference_number'] ?: '-')); ?></span>
                        <?php if ($surat['direction'] === 'masuk'): ?>
                            <span class="ui-badge text-white font-bold border-0 shadow-sm text-[10px]" style="background: linear-gradient(to right, #10b981, #059669);">Masuk</span>
                        <?php else: ?>
                            <span class="ui-badge text-white font-bold border-0 shadow-sm text-[10px]" style="background: linear-gradient(to right, #38bdf8, #0284c7);">Keluar</span>
                        <?php endif; ?>
                    </div>
                    <a href="surat_detail_v2.php?id=<?= (int) $surat['id']; ?>" class="text-sm font-bold text-slate-800 dark:text-slate-200 block leading-snug mb-3 hover:text-indigo-600 dark:hover:text-indigo-400">
                        <?= e((string) $surat['title']); ?>
                    </a>
                    <div class="flex items-center text-xs text-slate-500 dark:text-slate-400 font-medium">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <?= e(date('d-m-Y', strtotime((string) (($surat['letter_date'] ?: $surat['recorded_date']) ?: date('Y-m-d'))))); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php renderPagination('surat_p', $suratP, $suratTotal, $limit); ?>
    </div>

    <!-- Tab: Kegiatan Mendatang -->
    <div x-show="activeTab === 'kegiatan'" x-cloak class="p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Kegiatan Mendatang</h2>
            <a href="agenda_kegiatan.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Lihat Semua →</a>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[600px] table-fixed text-sm leading-6">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-widest text-slate-500 font-semibold">
                    <tr>
                        <th class="w-12 px-3 py-3 text-left">No</th>
                        <th class="w-32 px-3 py-3 text-left">Tanggal</th>
                        <th class="w-20 px-3 py-3 text-left">Waktu</th>
                        <th class="px-3 py-3 text-left">Judul</th>
                        <th class="w-24 px-3 py-3 text-left">Mode</th>
                        <th class="w-24 px-3 py-3 text-left">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                    <?php if (!$kegiatanRows): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-14 text-center">
                                <div class="flex flex-col items-center justify-center space-y-3">
                                    <div class="rounded-full bg-slate-100 p-4 dark:bg-slate-800">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                    </div>
                                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Belum ada agenda kegiatan mendatang.</p>
                                    <?php if (!isPegawai()): ?>
                                    <a href="agenda_form.php?type=kegiatan" class="mt-2 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                        Tambah Kegiatan
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($kegiatanRows as $i => $agenda): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/50 transition-colors">
                            <td class="px-3 py-3 font-semibold text-indigo-600"><?= $kegiatanOffset + $i + 1; ?></td>
                            <td class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">
                                <?= e(date('d-m-Y', strtotime((string) $agenda['agenda_date']))); ?>
                            </td>
                            <td class="px-3 py-3"><?= e(dashboardZoomTimeLabel((string) ($agenda['agenda_time'] ?? ''))); ?></td>
                            <td class="break-words px-3 py-3">
                                <a href="surat_detail_v2.php?id=<?= (int) $agenda['id']; ?>" class="font-semibold text-slate-800 dark:text-slate-200 hover:text-indigo-600"><?= e($agenda['title']); ?></a>
                            </td>
                            <td class="px-3 py-3">
                                <?php $modeBadge = dashboardKegiatanModeBadge((string)($agenda['agenda_mode'] ?? 'offline')); ?>
                                <span class="ui-badge <?= $modeBadge['class']; ?>"><?= $modeBadge['label']; ?></span>
                            </td>
                            <td class="px-3 py-3">
                                <?php $statusBadge = dashboardKegiatanStatusBadge($agenda); ?>
                                <span class="ui-badge <?= $statusBadge['class']; ?>"><?= $statusBadge['label']; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View -->
        <div class="md:hidden flex flex-col gap-4 mt-2">
            <?php if (!$kegiatanRows): ?>
                <div class="flex flex-col items-center justify-center p-8 border border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400 text-center">Belum ada agenda kegiatan mendatang.</p>
                </div>
            <?php else: ?>
                <?php foreach ($kegiatanRows as $i => $agenda): ?>
                <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 shadow-sm relative transition-all hover:shadow-md">
                    <div class="flex justify-between items-start mb-2">
                        <?php $statusBadge = dashboardKegiatanStatusBadge($agenda); ?>
                        <span class="ui-badge <?= $statusBadge['class']; ?> text-[10px]"><?= $statusBadge['label']; ?></span>
                        <?php $modeBadge = dashboardKegiatanModeBadge((string)($agenda['agenda_mode'] ?? 'offline')); ?>
                        <span class="ui-badge <?= $modeBadge['class']; ?> text-[10px]"><?= $modeBadge['label']; ?></span>
                    </div>
                    <a href="surat_detail_v2.php?id=<?= (int) $agenda['id']; ?>" class="text-sm font-bold text-slate-800 dark:text-slate-200 block leading-snug mb-3 hover:text-indigo-600 dark:hover:text-indigo-400">
                        <?= e($agenda['title']); ?>
                    </a>
                    <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500 dark:text-slate-400 font-medium">
                        <div class="flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <?= e(date('d-m-Y', strtotime((string) $agenda['agenda_date']))); ?>
                        </div>
                        <div class="flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <?= e(dashboardZoomTimeLabel((string) ($agenda['agenda_time'] ?? ''))); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php renderPagination('kegiatan_p', $kegiatanP, $kegiatanTotal, $limit); ?>
    </div>

    <!-- Tab: Zoom Terbaru -->
    <div x-show="activeTab === 'zoom'" x-cloak class="p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Agenda Zoom Terbaru</h2>
            <a href="agenda_zoom.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Lihat Semua →</a>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[600px] table-fixed text-sm leading-6">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-widest text-slate-500 font-semibold">
                    <tr>
                        <th class="w-12 px-3 py-3 text-left">No</th>
                        <th class="px-3 py-3 text-left">Judul Meeting</th>
                        <th class="w-28 px-3 py-3 text-left">Tanggal</th>
                        <th class="w-20 px-3 py-3 text-left">Waktu</th>
                        <th class="w-32 px-3 py-3 text-left">Status</th>
                        <th class="w-20 px-3 py-3 text-left">Join</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                    <?php if (!$zoomAgendas): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-14 text-center">
                                <div class="flex flex-col items-center justify-center space-y-3">
                                    <div class="rounded-full bg-slate-100 p-4 dark:bg-slate-800">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                    </div>
                                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Belum ada agenda Zoom mendatang.</p>
                                    <?php if (!isPegawai()): ?>
                                    <a href="agenda_form.php?type=agenda_zoom" class="mt-2 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                        Tambah Zoom
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($zoomAgendas as $i => $zoom):
                            $badge   = dashboardZoomScheduleBadge((string) $zoom['agenda_date'], !empty($zoom['agenda_date_end']) ? (string)$zoom['agenda_date_end'] : null);
                            $joinUrl = dashboardNormalizeMeetingUrl((string) ($zoom['zoom_link'] ?? ''));
                        ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/50 transition-colors">
                            <td class="px-3 py-3 font-semibold text-indigo-600"><?= $zoomOffset + $i + 1; ?></td>
                            <td class="break-words px-3 py-3">
                                <p class="font-semibold text-slate-800 dark:text-slate-200"><?= e((string) $zoom['title']); ?></p>
                                <p class="text-[11px] text-slate-400">ID: <?= e((string) ($zoom['zoom_meeting_id'] ?: '-')); ?></p>
                            </td>
                            <td class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">
                                <?= e(date('d-m-Y', strtotime((string) $zoom['agenda_date']))); ?>
                            </td>
                            <td class="px-3 py-3"><?= e(dashboardZoomTimeLabel((string) ($zoom['agenda_time'] ?? ''))); ?></td>
                            <td class="px-3 py-3"><span class="ui-badge <?= (string)$badge['class'] ?>"><?= e($badge['label']); ?></span></td>
                            <td class="px-3 py-3">
                                <?php if ($joinUrl): ?>
                                    <a href="<?= e($joinUrl); ?>" target="_blank" rel="noopener" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Gabung</a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View -->
        <div class="md:hidden flex flex-col gap-4 mt-2">
            <?php if (!$zoomAgendas): ?>
                <div class="flex flex-col items-center justify-center p-8 border border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400 text-center">Belum ada agenda Zoom mendatang.</p>
                </div>
            <?php else: ?>
                <?php foreach ($zoomAgendas as $i => $zoom):
                    $badge   = dashboardZoomScheduleBadge((string) $zoom['agenda_date']);
                    $joinUrl = dashboardNormalizeMeetingUrl((string) ($zoom['zoom_link'] ?? ''));
                ?>
                <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 shadow-sm relative transition-all hover:shadow-md">
                    <div class="flex justify-between items-start mb-2">
                        <span class="ui-badge <?= (string)$badge['class'] ?> text-[10px]"><?= e($badge['label']); ?></span>
                    </div>
                    <p class="font-bold text-slate-800 dark:text-slate-200 block leading-snug mb-1">
                        <?= e((string) $zoom['title']); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mb-3">ID: <?= e((string) ($zoom['zoom_meeting_id'] ?: '-')); ?></p>
                    
                    <div class="flex flex-wrap justify-between items-end">
                        <div class="flex flex-col gap-1.5 text-xs text-slate-500 dark:text-slate-400 font-medium">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                <?= e(date('d-m-Y', strtotime((string) $zoom['agenda_date']))); ?>
                            </div>
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                <?= e(dashboardZoomTimeLabel((string) ($zoom['agenda_time'] ?? ''))); ?>
                            </div>
                        </div>
                        <?php if ($joinUrl): ?>
                            <a href="<?= e($joinUrl); ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-xl bg-indigo-50 dark:bg-indigo-500/10 px-3 py-1.5 text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 transition-colors">
                                Gabung
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php renderPagination('zoom_p', $zoomP, $zoomTotal, $limit); ?>
    </div>

    <!-- Tab: Disposisi Terbaru (Admin/Pimpinan) -->
    <?php if (!isPegawai()): ?>
    <div x-show="activeTab === 'disposisi'" x-cloak class="p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-base font-bold text-slate-900 dark:text-slate-100">Disposisi Terbaru</h2>
            <a href="disposisi.php" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">Lihat Semua →</a>
        </div>
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full min-w-[600px] table-fixed text-sm leading-6">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-widest text-slate-500 font-semibold">
                    <tr>
                        <th class="w-12 px-3 py-3 text-left">No</th>
                        <th class="px-3 py-3 text-left">Perihal Surat</th>
                        <th class="w-32 sm:w-48 px-3 py-3 text-left">Diteruskan Kepada</th>
                        <th class="w-24 sm:w-32 px-3 py-3 text-center">Status Baca</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                    <?php if (!$adminDispositions): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-14 text-center">
                                <div class="flex flex-col items-center justify-center space-y-3">
                                    <div class="rounded-full bg-slate-100 p-4 dark:bg-slate-800">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" /></svg>
                                    </div>
                                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Belum ada disposisi saat ini.</p>
                                    <a href="disposisi.php" class="mt-2 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 transition-colors">
                                        Cek Daftar Surat
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($adminDispositions as $i => $disp): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-700/50 transition-colors <?= $disp['read_status'] === 'belum' ? 'border-l-4 border-red-400' : ''; ?>">
                            <td class="px-3 py-3 font-semibold text-indigo-600"><?= $adminDispOffset + $i + 1; ?></td>
                            <td class="break-words px-3 py-3">
                                <p class="font-semibold text-slate-800 dark:text-slate-200"><?= e($disp['title']); ?></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400"><?= e($disp['reference_number'] ?: '-'); ?></p>
                            </td>
                            <td class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300"><?= e((string) ($disp['employee_name'] ?? '-')); ?></td>
                            <td class="px-3 py-3 text-center">
                                <?php if ($disp['read_status'] === 'belum'): ?>
                                    <span class="ui-badge text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #f43f5e, #e11d48);">Belum Dibaca</span>
                                <?php else: ?>
                                    <span class="ui-badge text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #10b981, #059669);">Sudah Dibaca</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View -->
        <div class="md:hidden flex flex-col gap-4 mt-2">
            <?php if (!$adminDispositions): ?>
                <div class="flex flex-col items-center justify-center p-8 border border-dashed border-slate-200 dark:border-slate-700 rounded-2xl bg-slate-50/50 dark:bg-slate-800/50">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400 text-center">Belum ada disposisi saat ini.</p>
                </div>
            <?php else: ?>
                <?php foreach ($adminDispositions as $i => $disp): ?>
                <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 shadow-sm relative transition-all hover:shadow-md <?= $disp['read_status'] === 'belum' ? 'border-l-4 border-l-red-400' : ''; ?>">
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400"><?= e($disp['reference_number'] ?: '-'); ?></span>
                        <?php if ($disp['read_status'] === 'belum'): ?>
                            <span class="ui-badge text-white font-bold border-0 shadow-sm text-[10px]" style="background: linear-gradient(to right, #f43f5e, #e11d48);">Belum Dibaca</span>
                        <?php else: ?>
                            <span class="ui-badge text-white font-bold border-0 shadow-sm text-[10px]" style="background: linear-gradient(to right, #10b981, #059669);">Sudah Dibaca</span>
                        <?php endif; ?>
                    </div>
                    <p class="font-bold text-slate-800 dark:text-slate-200 block leading-snug mb-3">
                        <?= e($disp['title']); ?>
                    </p>
                    <div class="flex items-center text-xs text-slate-500 dark:text-slate-400 font-medium">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                        Ke: <?= e((string) ($disp['employee_name'] ?? '-')); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php renderPagination('admin_disp_p', $adminDispP, $adminDispTotal, $limit); ?>
    </div>
    <?php endif; ?>

</section>

</div>
<script defer src="assets/js/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? '#334155' : '#f1f5f9';
        var textColor = isDark ? '#94a3b8' : '#64748b';
        var tooltipBg = isDark ? '#1e293b' : '#ffffff';
        var tooltipTitle = isDark ? '#f8fafc' : '#0f172a';
        var tooltipBody = isDark ? '#e2e8f0' : '#475569';
        var tooltipBorder = isDark ? '#475569' : '#e2e8f0';
        var donutBorder = isDark ? '#1e293b' : '#ffffff';

        // ── Chart 1: Tren Kegiatan per Bulan (Line Chart) ──
        var canvasTrend = document.getElementById('kegiatanTrendChart');
        var trendChart;
        if (canvasTrend) {
            var ctxTrend = canvasTrend.getContext('2d');
            trendChart = new Chart(ctxTrend, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($chartDates) ?>,
                        datasets: [
                            {
                                label: 'Offline',
                                data: <?= json_encode($chartOffline) ?>,
                                borderColor: '#14b8a6',
                                backgroundColor: 'rgba(20, 184, 166, 0.1)',
                                fill: true,
                                tension: 0.35,
                                borderWidth: 2.5,
                                pointBackgroundColor: '#14b8a6',
                                pointRadius: 4,
                                pointHoverRadius: 6
                            },
                            {
                                label: 'Zoom',
                                data: <?= json_encode($chartZoom) ?>,
                                borderColor: '#f43f5e',
                                backgroundColor: 'rgba(244, 63, 94, 0.1)',
                                fill: true,
                                tension: 0.35,
                                borderWidth: 2.5,
                                pointBackgroundColor: '#f43f5e',
                                pointRadius: 4,
                                pointHoverRadius: 6
                            },
                            {
                                label: 'Hybrid',
                                data: <?= json_encode($chartHybrid) ?>,
                                borderColor: '#d97706',
                                backgroundColor: 'rgba(217, 119, 6, 0.1)',
                                fill: true,
                                tension: 0.35,
                                borderWidth: 2.5,
                                pointBackgroundColor: '#d97706',
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: textColor,
                                    font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' },
                                    padding: 16,
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: tooltipBg,
                                titleColor: tooltipTitle,
                                bodyColor: tooltipBody,
                                borderColor: tooltipBorder,
                                borderWidth: 1,
                                padding: 12,
                                usePointStyle: true,
                                titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: 'bold' },
                                bodyFont: { family: 'Plus Jakarta Sans', size: 12 }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11 } }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: gridColor, drawBorder: false },
                                ticks: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11 }, precision: 0 }
                            }
                        },
                        animation: { duration: 800, easing: 'easeOutQuart' }
                    }
                });
            }

            // ── Chart 4: Komposisi Surat (Donut) ──
            var canvasSurat = document.getElementById('suratDonutChart');
            var suratDonutChart;
            if (canvasSurat) {
                var ctxSurat = canvasSurat.getContext('2d');
                suratDonutChart = new Chart(ctxSurat, {
                    type: 'doughnut',
                    data: {
                        labels: ['Surat Masuk: <?= $suratData['masuk'] ?>', 'Surat Keluar: <?= $suratData['keluar'] ?>'],
                        datasets: [{
                            data: [<?= $suratData['masuk'] ?>, <?= $suratData['keluar'] ?>],
                            backgroundColor: ['#10b981', '#38bdf8'],
                            borderWidth: 3,
                            borderColor: donutBorder,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: { padding: { bottom: 10 } },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: textColor,
                                    font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' },
                                    padding: 16,
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: tooltipBg,
                                titleColor: tooltipTitle,
                                bodyColor: tooltipBody,
                                borderColor: tooltipBorder,
                                borderWidth: 1,
                                padding: 12,
                                boxPadding: 6,
                                usePointStyle: true,
                                titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: 'bold' },
                                bodyFont: { family: 'Plus Jakarta Sans', size: 12 }
                            }
                        },
                        cutout: '68%'
                    }
                });
            }

            // Watch for dark mode changes
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === "class") {
                        var dark = document.documentElement.classList.contains('dark');
                        var newGrid = dark ? '#334155' : '#f1f5f9';
                        var newText = dark ? '#94a3b8' : '#64748b';
                        var newBg = dark ? '#1e293b' : '#ffffff';
                        var newTitle = dark ? '#f8fafc' : '#0f172a';
                        var newBody = dark ? '#cbd5e1' : '#475569';
                        var newBorder = dark ? '#334155' : '#e2e8f0';
                        var newDonutBorder = dark ? '#1e293b' : '#ffffff';

                        if (trendChart) {
                            trendChart.options.scales.x.ticks.color = newText;
                            trendChart.options.scales.y.ticks.color = newText;
                            trendChart.options.scales.y.grid.color = newGrid;
                            trendChart.options.plugins.legend.labels.color = newText;
                            trendChart.options.plugins.tooltip.backgroundColor = newBg;
                            trendChart.options.plugins.tooltip.titleColor = newTitle;
                            trendChart.options.plugins.tooltip.bodyColor = newBody;
                            trendChart.options.plugins.tooltip.borderColor = newBorder;
                            trendChart.update();
                        }
                        if (suratDonutChart) {
                            suratDonutChart.options.plugins.legend.labels.color = newText;
                            suratDonutChart.options.plugins.tooltip.backgroundColor = newBg;
                            suratDonutChart.options.plugins.tooltip.titleColor = newTitle;
                            suratDonutChart.options.plugins.tooltip.bodyColor = newBody;
                            suratDonutChart.options.plugins.tooltip.borderColor = newBorder;
                            suratDonutChart.data.datasets[0].borderColor = newDonutBorder;
                            suratDonutChart.update();
                        }
                    }
                });
            });
            observer.observe(document.documentElement, { attributes: true });
    });
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
