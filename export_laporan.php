<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
requireLogin();
requireRole(['admin', 'sekretaris', 'pimpinan']);

// Restore last filter from session
$savedFilter = $_SESSION['export_filter'] ?? [];

$dateFrom   = trim((string) ($_GET['date_from']   ?? $savedFilter['date_from']   ?? ''));
$dateTo     = trim((string) ($_GET['date_to']     ?? $savedFilter['date_to']     ?? ''));
$jenis      = trim((string) ($_GET['jenis']       ?? $savedFilter['jenis']       ?? 'semua'));
$arah       = trim((string) ($_GET['arah']        ?? $savedFilter['arah']        ?? 'semua'));
$dispStatus = trim((string) ($_GET['disp_status'] ?? $savedFilter['disp_status'] ?? 'semua'));

// Validate enums
if (!in_array($jenis, ['semua', 'surat', 'kegiatan'], true)) { $jenis = 'semua'; }
if (!in_array($arah, ['semua', 'masuk', 'keluar'], true)) { $arah = 'semua'; }
if (!in_array($dispStatus, ['semua', 'pending', 'approved', 'rejected'], true)) { $dispStatus = 'semua'; }

$errors = [];
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'Tanggal mulai tidak boleh lebih besar dari tanggal selesai.';
}

// Save filter to session
$_SESSION['export_filter'] = [
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'jenis'       => $jenis,
    'arah'        => $arah,
    'disp_status' => $dispStatus,
];

$pageTitle = 'Export Laporan';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">Export Laporan</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Filter data terlebih dahulu, lalu unduh dalam format Excel atau PDF.</p>
</div>

<?php if ($errors): ?>
    <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">
        <?php foreach ($errors as $err): ?>
            <p>— <?= e($err); ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Filter Form -->
<section class="mb-6 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-6 shadow-sm">
    <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Filter Laporan</h2>
    <form method="get" action="export_laporan.php" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300">Tanggal Mulai</label>
            <input type="date" name="date_from" value="<?= e($dateFrom); ?>"
                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 dark:text-slate-200 px-4 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300">Tanggal Selesai</label>
            <input type="date" name="date_to" value="<?= e($dateTo); ?>"
                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 dark:text-slate-200 px-4 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300">Jenis Data</label>
            <select name="jenis" class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 dark:text-slate-200 px-4 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                <option value="semua" <?= $jenis === 'semua' ? 'selected' : ''; ?>>Semua</option>
                <option value="surat" <?= $jenis === 'surat' ? 'selected' : ''; ?>>Surat</option>
                <option value="kegiatan" <?= $jenis === 'kegiatan' ? 'selected' : ''; ?>>Kegiatan</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300">Arah</label>
            <select name="arah" class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 dark:text-slate-200 px-4 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                <option value="semua" <?= $arah === 'semua' ? 'selected' : ''; ?>>Semua Arah</option>
                <option value="masuk" <?= $arah === 'masuk' ? 'selected' : ''; ?>>Masuk</option>
                <option value="keluar" <?= $arah === 'keluar' ? 'selected' : ''; ?>>Keluar</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300">Status Disposisi</label>
            <select name="disp_status" class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 dark:text-slate-200 px-4 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                <option value="semua"    <?= $dispStatus === 'semua'    ? 'selected' : ''; ?>>Semua Status</option>
                <option value="pending"  <?= $dispStatus === 'pending'  ? 'selected' : ''; ?>>Menunggu</option>
                <option value="approved" <?= $dispStatus === 'approved' ? 'selected' : ''; ?>>Disetujui</option>
                <option value="rejected" <?= $dispStatus === 'rejected' ? 'selected' : ''; ?>>Ditolak</option>
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-700 transition">
                Terapkan Filter
            </button>
        </div>
    </form>
</section>

<?php if (empty($errors)): ?>
<!-- Export Buttons -->
<?php
$params = http_build_query([
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'jenis'       => $jenis,
    'arah'        => $arah,
    'disp_status' => $dispStatus,
]);
?>
<section class="rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-6 shadow-sm">
    <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Unduh Laporan</h2>
    <div class="grid gap-5 sm:grid-cols-2 max-w-2xl">
        <article class="flex flex-col items-center rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/30 p-6 text-center">
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
            </div>
            <h3 class="text-base font-bold text-slate-900 dark:text-slate-100">Laporan Excel</h3>
            <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Format spreadsheet (.xls) sesuai filter yang dipilih.</p>
            <a href="export.php?type=excel&<?= $params; ?>" target="_blank"
               class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export Excel
            </a>
        </article>

        <article class="flex flex-col items-center rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/30 p-6 text-center">
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M10 13l2 2 4-4"/></svg>
            </div>
            <h3 class="text-base font-bold text-slate-900 dark:text-slate-100">Laporan PDF</h3>
            <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Cetak/simpan PDF dari browser sesuai filter.</p>
            <a href="export.php?type=pdf&<?= $params; ?>" target="_blank"
               class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-rose-700 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>
                Cetak PDF
            </a>
        </article>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
