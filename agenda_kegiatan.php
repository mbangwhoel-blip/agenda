<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();
requireRole(['admin', 'sekretaris', 'pimpinan']);
$flash = getFlash();

$m = trim($_GET['m'] ?? '');
$filterYear = null;
$filterMonth = null;
if ($m !== '' && preg_match('/^\d{4}-\d{2}$/', $m)) {
    [$filterYear, $filterMonth] = explode('-', $m);
    $filterYear = (int) $filterYear;
    $filterMonth = (int) $filterMonth;
}
$orderBy = "CASE WHEN a.agenda_date = CURDATE() THEN 1 WHEN a.agenda_date > CURDATE() THEN 2 ELSE 3 END ASC, ABS(DATEDIFF(a.agenda_date, CURDATE())) ASC, a.id DESC";

$p = max(1, (int) ($_GET['p'] ?? 1));
$limit = 7;
$offset = ($p - 1) * $limit;

$q = trim($_GET['q'] ?? '');

$conditions = ["a.entry_type = 'kegiatan'"];
$params = [];

if ($q !== '') {
    $conditions[] = "(a.title LIKE :q1 OR a.counterparty LIKE :q2 OR a.location LIKE :q3)";
    $params['q1'] = "%{$q}%";
    $params['q2'] = "%{$q}%";
    $params['q3'] = "%{$q}%";
}
if ($filterYear && $filterMonth) {
    // Range bulan (sargable, pakai idx_agenda_date) pengganti YEAR()/MONTH().
    $mStart = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
    $mEndY = $filterMonth === 12 ? $filterYear + 1 : $filterYear;
    $mEndM = $filterMonth === 12 ? 1 : $filterMonth + 1;
    $mEnd = sprintf('%04d-%02d-01', $mEndY, $mEndM);
    $conditions[] = 'a.agenda_date >= :m_start AND a.agenda_date < :m_end';
    $params['m_start'] = $mStart;
    $params['m_end'] = $mEnd;
}

$countSql = "SELECT COUNT(*) FROM agendas a WHERE " . implode(' AND ', $conditions);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();

$sql = "SELECT a.*, s.attachment AS source_attachment, s.letter_date AS source_letter_date, s.agenda_time AS source_agenda_time
        FROM agendas a
        LEFT JOIN agendas s ON s.id = a.source_agenda_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY {$orderBy}
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$seenRefs = [];
$uniqueRows = [];
foreach ($rows as $r) {
    $r['title'] = str_replace('Tindak Lanjut Surat: ', '', $r['title'] ?? '');
    $refKey = trim((string)($r['reference_number'] ?? ''));
    $titleDateKey = trim((string)($r['title'] ?? '')) . '|' . trim((string)($r['agenda_date'] ?? ''));
    if ($refKey !== '' && !str_starts_with($refKey, 'AUTO-')) {
        if (isset($seenRefs[$refKey])) {
            continue;
        }
        $seenRefs[$refKey] = true;
    } elseif ($titleDateKey !== '|') {
        if (isset($seenRefs[$titleDateKey])) {
            continue;
        }
        $seenRefs[$titleDateKey] = true;
    }
    $uniqueRows[] = $r;
}
$rows = $uniqueRows;

$statsSql = "SELECT 
    COUNT(id) as total,
    SUM(CASE WHEN COALESCE(agenda_date_end, agenda_date) < CURDATE() THEN 1 ELSE 0 END) as selesai,
    SUM(CASE WHEN COALESCE(agenda_date_end, agenda_date) >= CURDATE() THEN 1 ELSE 0 END) as berjalan
    FROM agendas 
    WHERE entry_type = 'kegiatan'";
$stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'selesai' => 0, 'berjalan' => 0];

function oneHourAfter(?string $time): string
{
    $value = $time ?: '09:00:00';
    $ts = strtotime($value);
    if ($ts === false) {
        return '10:00';
    }
    return date('H:i', strtotime('+1 hour', $ts));
}

function waktuKegiatanLabel(array $row): string
{
    $time = trim((string) ($row['agenda_time'] ?? ''));
    if ($time === '' || $time === '00:00:00') {
        $time = trim((string) ($row['source_agenda_time'] ?? ''));
    }
    if ($time === '' || $time === '00:00:00') {
        $time = '09:00:00';
    }

    return substr($time, 0, 5);
}

function getStatusData(array $row): array
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

$pageTitle = 'Agenda Kegiatan';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .kegiatan-table .ui-icon-btn {
        width: 30px;
        height: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
    }
    .kegiatan-table .ui-icon-btn svg {
        width: 15px;
        height: 15px;
    }
</style>
<div x-data="{ showFilter: false }">

<?php if ($flash): ?>
    <div class="mb-6 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
        <?= e($flash['message']); ?>
    </div>
<?php endif; ?>

<header class="mb-6">
    <div class="mb-4">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">Agenda Kegiatan</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Jadwal kegiatan (hasil ekstraksi AI dari surat maupun input manual)</p>
    </div>
</header>

<section class="mb-6">
    <div class="grid gap-6 sm:grid-cols-3">
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-transparent dark:from-indigo-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Total Kegiatan</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int)$stats['total']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-sky-50 to-transparent dark:from-sky-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Mendatang / Hari Ini</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int)$stats['berjalan']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Selesai</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int)$stats['selesai']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
            </div>
        </article>
    </div>
</section>

<!-- Filter & Table Card -->
<section class="rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 sm:p-6 shadow-sm">
    <!-- Toolbar -->
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <!-- Search bar -->
        <form method="get" class="flex flex-1 items-center gap-2">
            <?php if ($m !== ''): ?>
                <input type="hidden" name="m" value="<?= e($m); ?>">
            <?php endif; ?>
            <div class="relative flex-1">
                <input type="search" name="q" value="<?= e($q); ?>"
                       placeholder="Cari nama kegiatan, pengirim, atau lokasi..."
                       class="w-full rounded-2xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700/50 dark:text-white px-4 py-2.5 text-sm placeholder-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
            </div>
            <button type="submit"
                    class="rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                Cari
            </button>
            <?php if ($q !== '' || $m !== ''): ?>
                <a href="agenda_kegiatan.php"
                   class="rounded-2xl border border-slate-200 dark:border-slate-600 px-3 py-2.5 text-sm text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-700">Reset</a>
            <?php endif; ?>
        </form>

        <div class="flex items-center gap-2 flex-wrap">
            <!-- Filter toggle -->
            <button type="button" @click="showFilter = !showFilter"
                    class="flex items-center gap-2 rounded-2xl border border-slate-200 dark:border-slate-600 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Filter
                <?php if ($filterYear && $filterMonth): ?>
                    <span class="rounded-full bg-indigo-600 px-1.5 text-xs text-white">1</span>
                <?php endif; ?>
            </button>

            <?php if (!isPimpinan()): ?>
                <a href="agenda_form.php?entry_type=kegiatan&return_to=agenda_kegiatan.php"
                   class="inline-flex items-center gap-2 rounded-2xl bg-teal-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-teal-700 transition shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Kegiatan Manual
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter panel -->
    <div x-show="showFilter" x-cloak x-transition
         class="mb-6 rounded-2xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/30 p-4">
        <form method="get" class="flex flex-wrap items-center gap-4">
            <?php if ($q !== ''): ?>
                <input type="hidden" name="q" value="<?= e($q); ?>">
            <?php endif; ?>
            <div>
                <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-400">Bulan &amp; Tahun</label>
                <input type="month" name="m" value="<?= e($m); ?>"
                       class="rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none">
            </div>
            <div class="flex items-end gap-2 pt-4">
                <button type="submit"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Terapkan</button>
                <a href="agenda_kegiatan.php<?= $q !== '' ? '?q=' . urlencode($q) : ''; ?>"
                   class="rounded-xl border border-slate-200 dark:border-slate-600 px-3 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700">Hapus Filter</a>
            </div>
        </form>
    </div>

    <!-- Desktop table -->
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm leading-6 kegiatan-table">
            <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-wider text-slate-500 font-semibold">
                <tr>
                    <th class="w-12 px-3 py-3 text-center">No</th>
                    <th class="px-4 py-3 text-left">Nama Kegiatan</th>
                    <th class="w-52 px-4 py-3 text-left">Tanggal &amp; Waktu</th>
                    <th class="w-28 px-3 py-3 text-center">Status</th>
                    <th class="w-44 px-3 py-3 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5" class="py-16 text-center">
                            <div class="flex flex-col items-center justify-center opacity-80">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-16 w-16 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <h5 class="text-xl font-bold text-slate-500">Belum Ada Agenda Kegiatan</h5>
                                <p class="mt-2 text-slate-400">Jadwal kegiatan akan muncul di sini.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr class="align-middle hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-3 py-3 text-center font-bold text-indigo-600 dark:text-indigo-400"><?= $offset + $i + 1; ?></td>
                            <td class="px-4 py-3 min-w-[200px]">
                                <p class="font-bold text-slate-800 dark:text-slate-100 leading-snug"><?= e((string) $row['title']); ?></p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <?php if ((int) ($row['ai_generated'] ?? 0) === 1): ?>
                                        <span class="inline-flex items-center rounded-md bg-violet-100 dark:bg-violet-900/40 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 dark:text-violet-300">AI</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center rounded-md bg-teal-100 dark:bg-teal-900/40 px-1.5 py-0.5 text-[10px] font-bold text-teal-700 dark:text-teal-300">Manual</span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['counterparty'])): ?>
                                        <span class="text-xs text-slate-500 dark:text-slate-400">🏢 <?= e((string) $row['counterparty']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['location'])): ?>
                                        <span class="text-xs text-slate-500 dark:text-slate-400">📍 <?= e((string) $row['location']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php
                                $hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                                $hariStr = $hariIndo[(int)date('w', strtotime((string) ($row['agenda_date'] ?: date('Y-m-d'))))];
                                $d1 = date('d-m-Y', strtotime((string) ($row['agenda_date'] ?: date('Y-m-d'))));
                                $d2 = !empty($row['agenda_date_end']) ? date('d-m-Y', strtotime((string) $row['agenda_date_end'])) : $d1;
                                $dateStr = ($d1 === $d2 ? $d1 : "$d1 s/d $d2");
                                ?>
                                <div class="flex flex-col gap-0.5">
                                    <span class="font-bold text-xs text-slate-800 dark:text-slate-200"><?= e($hariStr . ', ' . $dateStr) ?></span>
                                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">⏰ <?= e(waktuKegiatanLabel($row)); ?> WIB</span>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-center whitespace-nowrap">
                                <?php $sData = getStatusData($row); ?>
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold <?= $sData['class'] ?>">
                                    <?= e($sData['label']); ?>
                                </span>
                            </td>

                             <td class="px-3 py-3 text-center">
                                 <div class="flex flex-nowrap items-center justify-center gap-1.5">
                                     <?php if (!isPimpinan()): ?>
                                         <?php if (!empty($row['source_attachment']) || !empty($row['attachment'])): ?>
                                             <a href="file_preview.php?id=<?= (int) $row['id']; ?><?= !empty($row['source_attachment']) ? '&source=1' : ''; ?>&return_to=agenda_kegiatan.php" target="_blank" class="ui-icon-btn bg-indigo-50 text-indigo-600 transition hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400" title="Lihat lampiran">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                                     <circle cx="12" cy="12" r="3"></circle>
                                                 </svg>
                                             </a>
                                         <?php elseif (!empty($row['source_agenda_id'])): ?>
                                             <a href="disposisi_form.php?id=<?= (int) $row['source_agenda_id']; ?>" class="ui-icon-btn bg-indigo-50 text-indigo-600 transition hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400" title="Lihat detail naskah asal">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                                     <circle cx="12" cy="12" r="3"></circle>
                                                 </svg>
                                             </a>
                                         <?php endif; ?>

                                         <?php if (canManageAgenda((int) ($row['user_id'] ?? 0))): ?>
                                             <a href="agenda_duplicate.php?id=<?= (int) $row['id']; ?>&count=52&return_to=agenda_kegiatan.php&token=<?= e(csrf_token()); ?>" class="ui-icon-btn bg-violet-50 text-violet-600 transition hover:bg-violet-100 dark:bg-violet-900/30 dark:text-violet-400" title="Duplikat 52 minggu (1 tahun)" onclick="return confirm('Buat 52 duplikat mingguan untuk 1 tahun ke depan?');">
                                                 <span class="text-[10px] font-black leading-none tracking-tighter">52x</span>
                                             </a>

                                             <a href="agenda_duplicate.php?id=<?= (int) $row['id']; ?>&return_to=agenda_kegiatan.php&token=<?= e(csrf_token()); ?>" class="ui-icon-btn bg-emerald-50 text-emerald-600 transition hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400" title="Duplikat (+7 hari)" onclick="return confirm('Duplikat agenda ini ke minggu depan (tanggal +7 hari)?');">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                     <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                                 </svg>
                                             </a>

                                             <a href="agenda_form.php?id=<?= (int) $row['id']; ?>&return_to=agenda_kegiatan.php" class="ui-icon-btn bg-amber-50 text-amber-600 transition hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400" title="Edit">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                     <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                 </svg>
                                             </a>

                                             <a href="agenda_delete.php?id=<?= (int) $row['id']; ?>&return_to=agenda_kegiatan.php&token=<?= e(csrf_token()); ?>" class="ui-icon-btn bg-rose-50 text-rose-600 transition hover:bg-rose-100 dark:bg-rose-900/30 dark:text-rose-400" title="Hapus" onclick="return confirm('Yakin ingin menghapus agenda kegiatan ini?');">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <path d="M3 6h18"></path>
                                                     <path d="M8 6V4h8v2"></path>
                                                     <path d="M19 6l-1 14H6L5 6"></path>
                                                     <path d="M10 11v6M14 11v6"></path>
                                                 </svg>
                                             </a>
                                         <?php endif; ?>
                                     <?php else: ?>
                                         <?php if (!empty($row['source_attachment']) || !empty($row['attachment'])): ?>
                                             <a href="file_preview.php?id=<?= (int) $row['id']; ?><?= !empty($row['source_attachment']) ? '&source=1' : ''; ?>&return_to=agenda_kegiatan.php" target="_blank" class="ui-icon-btn bg-indigo-50 text-indigo-600 transition hover:bg-indigo-100" title="Lihat lampiran">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                                     <circle cx="12" cy="12" r="3"></circle>
                                                 </svg>
                                             </a>
                                         <?php elseif (!empty($row['source_agenda_id'])): ?>
                                             <a href="disposisi_form.php?id=<?= (int) $row['source_agenda_id']; ?>" class="ui-icon-btn bg-indigo-50 text-indigo-600 transition hover:bg-indigo-100" title="Lihat detail naskah asal">
                                                 <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                     <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                                     <circle cx="12" cy="12" r="3"></circle>
                                                 </svg>
                                             </a>
                                         <?php else: ?>
                                             <span class="text-slate-400">-</span>
                                         <?php endif; ?>
                                     <?php endif; ?>
                                 </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile card view -->
    <div class="sm:hidden divide-y divide-slate-100 dark:divide-slate-700">
        <?php if (!$rows): ?>
            <div class="px-5 py-16 text-center">
                <div class="flex flex-col items-center justify-center opacity-80">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-14 w-14 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <h5 class="text-lg font-bold text-slate-500 dark:text-slate-400">Belum Ada Agenda Kegiatan</h5>
                    <p class="mt-1 text-sm text-slate-400 dark:text-slate-500">Belum ada agenda kegiatan terjadwal.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($rows as $i => $row): ?>
            <div class="px-4 py-4 space-y-2">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-slate-900 dark:text-white leading-snug"><?= e((string) $row['title']); ?></p>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <?php if ((int) ($row['ai_generated'] ?? 0) === 1): ?>
                                <span class="inline-flex items-center rounded-md bg-violet-100 dark:bg-violet-900/40 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 dark:text-violet-300">AI</span>
                            <?php else: ?>
                                <span class="inline-flex items-center rounded-md bg-teal-100 dark:bg-teal-900/40 px-1.5 py-0.5 text-[10px] font-bold text-teal-700 dark:text-teal-300">Manual</span>
                            <?php endif; ?>
                            <?php if (!empty($row['counterparty'])): ?>
                                <span class="text-xs text-slate-500 dark:text-slate-400">🏢 <?= e((string) $row['counterparty']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $sData = getStatusData($row); ?>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold shrink-0 <?= $sData['class'] ?>">
                        <?= e($sData['label']); ?>
                    </span>
                </div>
                <div class="flex flex-col gap-1 text-xs text-slate-600 dark:text-slate-300">
                    <p>
                        📅 
                        <?php
                        $hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                        $hariM = $hariIndo[(int)date('w', strtotime((string) ($row['agenda_date'] ?: date('Y-m-d'))))];
                        $d1m = date('d-m-Y', strtotime((string) ($row['agenda_date'] ?: date('Y-m-d'))));
                        $d2m = !empty($row['agenda_date_end']) ? date('d-m-Y', strtotime((string) $row['agenda_date_end'])) : $d1m;
                        echo e($hariM . ', ' . ($d1m === $d2m ? $d1m : "$d1m s/d $d2m"));
                        ?>
                        &nbsp;• ⏰ <?= e(waktuKegiatanLabel($row)); ?> WIB
                    </p>
                    <?php if (!empty($row['location'])): ?>
                        <p>📍 <?= e((string) $row['location']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <a href="surat_detail_v2.php?id=<?= (int) $row['id']; ?>"
                       class="rounded-xl bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400">Detail</a>
                    <?php if (canManageAgenda((int) ($row['user_id'] ?? 0)) && !isPimpinan()): ?>
                        <a href="agenda_duplicate.php?id=<?= (int) $row['id']; ?>&count=52&return_to=agenda_kegiatan.php&token=<?= e(csrf_token()); ?>" class="rounded-xl bg-violet-50 px-3 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-100 dark:bg-violet-900/30 dark:text-violet-400" onclick="return confirm('Buat 52 duplikat mingguan untuk 1 tahun ke depan?');">52x</a>
                        <a href="agenda_duplicate.php?id=<?= (int) $row['id']; ?>&return_to=agenda_kegiatan.php&token=<?= e(csrf_token()); ?>" class="rounded-xl bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400" onclick="return confirm('Duplikat agenda ini ke minggu depan (tanggal +7 hari)?');">Duplikat</a>
                        <a href="agenda_form.php?id=<?= (int) $row['id']; ?>&return_to=agenda_kegiatan.php"
                           class="rounded-xl bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400">Edit</a>
                        <a href="agenda_delete.php?id=<?= (int) $row['id']; ?>&return_to=agenda_kegiatan.php&token=<?= e(csrf_token()); ?>" class="rounded-xl bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:bg-rose-900/30 dark:text-rose-400" onclick="return confirm('Yakin ingin menghapus agenda kegiatan ini?');">Hapus</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php renderPagination('p', $p, $totalItems, $limit); ?>
</section>

 </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>