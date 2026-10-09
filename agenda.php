<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();
requireRole(['admin', 'sekretaris', 'pimpinan', 'arsiparis']);
$flash = getFlash();

$q = trim($_GET['q'] ?? '');
$tab = trim($_GET['tab'] ?? 'semua');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$m = trim($_GET['m'] ?? '');

if (!in_array($tab, ['semua', 'masuk', 'keluar'], true)) {
    $tab = 'semua';
}

$filterYear = null;
$filterMonth = null;
if ($m !== '' && preg_match('/^\d{4}-\d{2}$/', $m)) {
    [$filterYear, $filterMonth] = explode('-', $m);
    $filterYear = (int) $filterYear;
    $filterMonth = (int) $filterMonth;
}

$enableAutoAiBackground = isAdmin() || isSekretaris();
$autoAiQueueCount = 0;
if ($enableAutoAiBackground) {
    $queued = $_SESSION['ai_pending_letter_ids'] ?? [];
    if (is_array($queued)) {
        $autoAiQueueCount = count(array_filter(array_map('intval', $queued), static fn (int $id): bool => $id > 0));
    }
}

$statsStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total_surat,
        SUM(CASE WHEN direction = 'masuk' THEN 1 ELSE 0 END) AS total_masuk,
        SUM(CASE WHEN direction = 'keluar' THEN 1 ELSE 0 END) AS total_keluar,
        SUM(CASE WHEN direction = 'masuk' AND NOT EXISTS (SELECT 1 FROM agenda_disposition_items adi WHERE adi.agenda_id = agendas.id) THEN 1 ELSE 0 END) AS belum_disposisi
     FROM agendas
     WHERE entry_type = 'surat'"
);
$stats = $statsStmt->fetch() ?: ['total_surat' => 0, 'total_masuk' => 0, 'total_keluar' => 0, 'belum_disposisi' => 0];

// Kolom tanggal efektif: pakai generated column (sargable) bila tersedia,
// fallback ke ekspresi COALESCE bila hosting tidak mendukung generated column.
$hasEffectiveDate = false;
try {
    $hasEffectiveDate = (bool) $pdo->query("SHOW COLUMNS FROM agendas LIKE 'effective_date'")->fetch();
} catch (Throwable) {}
$dateCol = $hasEffectiveDate
    ? 'a.effective_date'
    : 'COALESCE(a.letter_date, a.recorded_date, a.agenda_date)';

$conditions = ["a.entry_type = 'surat'"];
$params = [];

if ($tab === 'masuk' || $tab === 'keluar') {
    $conditions[] = 'a.direction = :direction';
    $params['direction'] = $tab;
}
if ($q !== '') {
    $conditions[] = '(a.reference_number LIKE :q1 OR a.counterparty LIKE :q2 OR a.title LIKE :q3)';
    $params['q1'] = "%{$q}%";
    $params['q2'] = "%{$q}%";
    $params['q3'] = "%{$q}%";
}
if ($dateFrom !== '') {
    $conditions[] = "{$dateCol} >= :date_from";
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = "{$dateCol} <= :date_to";
    $params['date_to'] = $dateTo;
}
if ($filterYear && $filterMonth) {
    // Range bulan (sargable) pengganti YEAR()/MONTH() di atas kolom.
    $monthStart = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
    $nextY = $filterMonth === 12 ? $filterYear + 1 : $filterYear;
    $nextM = $filterMonth === 12 ? 1 : $filterMonth + 1;
    $monthEnd = sprintf('%04d-%02d-01', $nextY, $nextM);
    $conditions[] = "{$dateCol} >= :m_start AND {$dateCol} < :m_end";
    $params['m_start'] = $monthStart;
    $params['m_end'] = $monthEnd;
}

$orderBy = "{$dateCol} DESC, a.id DESC";

$p = max(1, (int) ($_GET['p'] ?? 1));
$limit = 7;
$offset = ($p - 1) * $limit;

$countSql = "SELECT COUNT(*) FROM agendas a WHERE " . implode(' AND ', $conditions);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();

$sql = "SELECT
            a.*,
            u.full_name AS created_by,
            EXISTS(
                SELECT 1
                FROM agendas c
                WHERE c.source_agenda_id = a.id
                  AND c.entry_type = 'kegiatan'
                  AND c.ai_generated = 1
            ) AS ai_processed
        FROM agendas a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY {$orderBy}
        LIMIT {$limit} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agendas = $stmt->fetchAll();

$reminderStmt = $pdo->query(
    "SELECT COUNT(*) AS total_reminder
     FROM agendas
     WHERE entry_type = 'kegiatan'
       AND reminder_h1_enabled = 1
       AND reminder_h1_sent_at IS NULL
       AND reminder_h1_at IS NOT NULL
       AND status != 'selesai'
       AND (agenda_date > CURDATE() OR (agenda_date = CURDATE() AND COALESCE(agenda_time, '23:59:59') >= CURTIME()))"
);
$reminderCount = (int) ($reminderStmt->fetchColumn() ?: 0);

$defaultDirection = in_array($tab, ['masuk', 'keluar'], true) ? $tab : 'masuk';

function agendaUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) {
            unset($query[$k]);
        }
    }
    return 'agenda.php' . ($query ? '?' . http_build_query($query) : '');
}

function suratDisposisiBadge(string $status): array
{
    if ($status === 'approved') {
        return ['label' => 'Disetujui', 'class' => 'text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #10b981, #059669);'];
    }
    if ($status === 'rejected') {
        return ['label' => 'Ditolak', 'class' => 'text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #f43f5e, #e11d48);'];
    }
    return ['label' => 'Menunggu', 'class' => 'text-white font-bold border-0 shadow-sm" style="background: linear-gradient(to right, #fbbf24, #ea580c);'];
}

$pageTitle = 'Agenda Surat';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .ag-table .ui-icon-btn{width:26px;height:26px;}
    .ag-table .ui-icon-btn svg{width:12px;height:12px;}
    .badge-masuk{background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;border:0;}
    .badge-keluar{background:linear-gradient(135deg,#38bdf8,#0284c7);color:#fff;font-weight:700;border:0;}
    .badge-disposisi-approved{background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;border:0;}
    .badge-disposisi-rejected{background:linear-gradient(135deg,#f43f5e,#e11d48);color:#fff;font-weight:700;border:0;}
    .badge-disposisi-pending{background:linear-gradient(135deg,#fbbf24,#ea580c);color:#fff;font-weight:700;border:0;}
</style>
<div x-data="{ showDeleteModal: false, deleteUrl: '' }">

<?php if ($flash): ?>
    <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 3000)" x-transition class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
        <?= e($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- ═══ HEADER: Title + Stats ═══ -->
<header class="mb-6">
    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white mb-1">Agenda Surat</h1>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500 dark:text-slate-400">Kelola arsip surat masuk & keluar</p>
        
        <?php if ($reminderCount > 0): ?>
        <div class="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-1.5 ml-auto dark:border-violet-800 dark:bg-violet-900/20">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <p class="text-[13px] font-medium text-violet-800 dark:text-violet-200">
                <strong><?= $reminderCount; ?> agenda</strong> besok akan dikirim pengingat H-1 otomatis.
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats Cards (semula) -->
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="<?= e(agendaUrl(['tab' => 'semua'])); ?>" class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-transparent dark:from-indigo-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Total Surat</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['total_surat']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 22h14a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            </div>
        </a>
        <a href="<?= e(agendaUrl(['tab' => 'masuk'])); ?>" class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Surat Masuk</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['total_masuk']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
            </div>
        </a>
        <a href="<?= e(agendaUrl(['tab' => 'keluar'])); ?>" class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-sky-50 to-transparent dark:from-sky-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Surat Keluar</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['total_keluar']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </div>
            </div>
        </a>
        <div class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-rose-50 to-transparent dark:from-rose-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Belum Disposisi</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) ($stats['belum_disposisi'] ?? 0); ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ═══ TOOLBAR: Filter + Tambah (satu baris) ═══ -->
<section class="mb-5 rounded-2xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-800">
    <!-- Search + Jenis + Aksi (sejajar) -->
    <form method="get" class="flex flex-wrap items-center gap-2">
        <div class="flex-1 min-w-[180px]">
            <input type="text" name="q" value="<?= e($q); ?>" placeholder="Cari nomor surat, pengirim, perihal…" class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2.5 px-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
        </div>
        <select name="tab" class="w-[130px] shrink-0 cursor-pointer rounded-lg border border-slate-200 bg-slate-50 py-2.5 pl-3 pr-8 text-sm text-slate-700 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200" style="appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;" title="Filter jenis (Semua/Masuk/Keluar)">
            <option value="semua" <?= $tab === 'semua' ? 'selected' : ''; ?>>Semua</option>
            <option value="masuk" <?= $tab === 'masuk' ? 'selected' : ''; ?>>Masuk</option>
            <option value="keluar" <?= $tab === 'keluar' ? 'selected' : ''; ?>>Keluar</option>
        </select>
        <button type="submit" class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-700 transition">Cari</button>
        <?php if ($q || $m || $tab !== 'semua'): ?>
        <a href="agenda.php" class="shrink-0 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-500 hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">Reset</a>
        <?php endif; ?>
        <?php if (!isPimpinan()): ?>
        <a href="agenda_form.php?direction=<?= e($defaultDirection); ?>&return_to=agenda.php" class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-teal-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Tambah Surat
        </a>
        <?php endif; ?>
    </form>
</section>

<!-- ═══ TABEL ═══ -->
<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <div class="hidden sm:block">
        <table class="w-full table-fixed text-sm ag-table">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-900/30 dark:text-slate-400">
                <tr>
                    <th class="w-10 px-3 py-3 text-center font-semibold">No</th>
                    <th class="w-44 px-3 py-3 text-center font-semibold">No. Surat</th>
                    <th class="w-24 px-3 py-3 text-center font-semibold">Tanggal</th>
                    <th class="px-3 py-3 text-center font-semibold">Perihal & Pengirim</th>
                    <th class="w-24 px-3 py-3 text-center font-semibold">Disposisi</th>
                    <th class="w-32 px-2 py-3 text-center font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700 dark:divide-slate-700 dark:text-slate-300">
                <?php if (!$agendas): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center">
                            <div class="flex flex-col items-center justify-center opacity-80">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mb-3 h-12 w-12 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <p class="text-base font-bold text-slate-500 dark:text-slate-400">Belum ada data surat</p>
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Tambahkan surat baru atau ubah filter pencarian.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($agendas as $i => $agenda): ?>
                        <?php
                            $disposisi = suratDisposisiBadge((string) ($agenda['disposition_status'] ?? 'pending'));
                            $dateLetter = date('d M Y', strtotime((string)(($agenda['letter_date'] ?: $agenda['agenda_date']) ?: date('Y-m-d'))));
                            $dateRecorded = date('d M Y', strtotime((string)(($agenda['recorded_date'] ?: $agenda['agenda_date']) ?: date('Y-m-d'))));
                            $cpName = (string)($agenda['counterparty'] ?: '—');
                        ?>
                        <tr class="align-middle transition-colors hover:bg-slate-50 dark:hover:bg-slate-700/30">
                            <td class="px-3 py-3 text-xs font-semibold text-slate-400"><?= $offset + $i + 1; ?></td>
                            <td class="px-3 py-3 min-w-0">
                                <p class="truncate text-sm font-bold text-slate-800 dark:text-slate-200" title="<?= e((string)$agenda['reference_number']); ?>"><?= e((string)($agenda['reference_number'] ?: '—')); ?></p>
                                <span class="ui-badge mt-1 inline-block text-[10px] py-0.5 px-1.5 <?= $agenda['direction'] === 'masuk' ? 'badge-masuk' : 'badge-keluar'; ?>"><?= $agenda['direction'] === 'masuk' ? 'Masuk' : 'Keluar'; ?></span>
                            </td>
                            <td class="px-3 py-3">
                                <p class="text-xs font-semibold text-slate-700 dark:text-slate-300"><?= e($dateLetter); ?></p>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5">Diterima: <?= e($dateRecorded); ?></p>
                            </td>
                            <td class="px-3 py-3 min-w-0">
                                <div class="flex items-start gap-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="break-words text-sm font-semibold text-slate-800 dark:text-slate-200"><?= e((string)$agenda['title']); ?></p>
                                        <div class="mt-1 flex items-start gap-1.5">
                                            <span class="ui-avatar inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold text-white <?= getAvatarColor($cpName) ?>"><?= e(strtoupper(substr($cpName, 0, 1))); ?></span>
                                            <span class="break-words text-xs text-slate-500 dark:text-slate-400"><?= e($cpName); ?></span>
                                        </div>
                                    </div>
                                    <?php if ((int)($agenda['ai_processed'] ?? 0) === 1): ?>
                                    <span class="shrink-0 rounded bg-violet-100 px-1 py-0.5 text-[9px] font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-400" title="Diproses AI">AI</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="ui-badge text-[10px] py-0.5 px-1.5 badge-disposisi-<?= e((string)($agenda['disposition_status'] ?? 'pending')); ?>"><?= e((string)$disposisi['label']); ?></span>
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex items-center justify-center gap-1 flex-nowrap">
                                    <?php if (!isPimpinan()): ?>
                                        <a href="surat_detail_v2.php?id=<?= (int)$agenda['id']; ?>&return_to=agenda.php" class="ui-icon-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-500/20 dark:text-indigo-400" title="Lihat detail & preview surat">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <?php if (!empty($agenda['attachment'])): ?>
                                            <a href="file_preview.php?id=<?= (int)$agenda['id']; ?>&return_to=agenda.php" target="_blank" class="ui-icon-btn bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-300" title="Buka file lampiran langsung">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (canManageLetterArchive((int)($agenda['user_id'] ?? 0))): ?>
                                            <a href="agenda_form.php?id=<?= (int)$agenda['id']; ?>" class="ui-icon-btn bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-500/20 dark:text-amber-400" title="Edit surat">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"/></svg>
                                            </a>
                                            <a href="agenda_delete.php?id=<?= (int)$agenda['id']; ?>&token=<?= e(csrf_token()); ?>" @click.prevent="deleteUrl = $el.getAttribute('href'); showDeleteModal = true" class="ui-icon-btn bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-500/20 dark:text-red-400" title="Hapus surat">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a href="disposisi_form.php?id=<?= (int)$agenda['id']; ?>" class="ui-icon-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-500/20 dark:text-indigo-400" title="Lihat detail naskah">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="divide-y divide-slate-100 dark:divide-slate-700 sm:hidden">
        <?php if (!$agendas): ?>
            <div class="px-5 py-12 text-center">
                <div class="flex flex-col items-center justify-center opacity-80">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mb-3 h-12 w-12 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <p class="text-base font-bold text-slate-500 dark:text-slate-400">Belum ada data surat</p>
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Tambahkan surat baru atau ubah filter.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($agendas as $agenda):
                $disposisi = suratDisposisiBadge((string)($agenda['disposition_status'] ?? 'pending'));
                $dateLetter = date('d M Y', strtotime((string)(($agenda['letter_date'] ?: $agenda['agenda_date']) ?: date('Y-m-d'))));
                $cpName = (string)($agenda['counterparty'] ?: '—');
            ?>
            <div class="px-4 py-3.5 space-y-2">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold text-slate-900 dark:text-white"><?= e((string)($agenda['reference_number'] ?: '—')); ?></p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"><?= e($dateLetter); ?></p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span class="ui-badge text-[10px] py-0.5 px-1.5 <?= $agenda['direction'] === 'masuk' ? 'badge-masuk' : 'badge-keluar'; ?>"><?= $agenda['direction'] === 'masuk' ? 'Masuk' : 'Keluar'; ?></span>
                        <span class="ui-badge text-[10px] py-0.5 px-1.5 badge-disposisi-<?= e((string)($agenda['disposition_status'] ?? 'pending')); ?>"><?= e((string)$disposisi['label']); ?></span>
                    </div>
                </div>
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200"><?= e((string)$agenda['title']); ?></p>
                <div class="flex items-center gap-1.5">
                    <span class="ui-avatar inline-flex h-5 w-5 items-center justify-center rounded-full text-[9px] font-bold text-white <?= getAvatarColor($cpName) ?>"><?= e(strtoupper(substr($cpName, 0, 1))); ?></span>
                    <span class="text-xs text-slate-500 dark:text-slate-400"><?= e($cpName); ?></span>
                    <?php if ((int)($agenda['ai_processed'] ?? 0) === 1): ?>
                    <span class="rounded bg-violet-100 px-1 py-0.5 text-[9px] font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-400">AI</span>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2 pt-1">
                    <a href="surat_detail_v2.php?id=<?= (int)$agenda['id']; ?>" class="rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-500/20 dark:text-indigo-400">Detail</a>
                    <?php if (canManageLetterArchive((int)($agenda['user_id'] ?? 0)) && !isPimpinan()): ?>
                        <a href="agenda_form.php?id=<?= (int)$agenda['id']; ?>" class="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100 dark:bg-amber-500/20 dark:text-amber-400">Edit</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination (dalam card) -->
    <div class="border-t border-slate-100 dark:border-slate-700">
        <?php renderPagination('p', $p, $totalItems, $limit); ?>
    </div>
</section>

<!-- Delete Modal -->
<div x-cloak x-show="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
    <div @click.outside="showDeleteModal = false" class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-800">
        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Konfirmasi Hapus</h3>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Yakin ingin menghapus data ini? Tindakan ini tidak bisa dibatalkan.</p>
        <div class="mt-5 flex items-center justify-end gap-2">
            <button type="button" @click="showDeleteModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">Batal</button>
            <a :href="deleteUrl" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Ya, Hapus</a>
        </div>
    </div>
</div>
</div>

<?php if ($enableAutoAiBackground): ?>
    <script>
        (function () {
            fetch('ai_process_background.php', { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).catch(function () {});
        })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
