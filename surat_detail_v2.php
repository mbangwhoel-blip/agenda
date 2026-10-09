<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$returnTo = $_GET['return_to'] ?? 'agenda.php';
if (!in_array($returnTo, ['dashboard.php', 'agenda.php', 'agenda_kegiatan.php', 'agenda_zoom.php', 'disposisi.php', 'disposisi_saya.php'], true)) {
    $returnTo = 'agenda.php';
}

if ($id <= 0) {
    http_response_code(400);
    setFlash('error', 'ID surat tidak valid.');
    redirect($returnTo);
}

$pdo = getPdo();

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS created_by_name
     FROM agendas a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE a.id = :id
     LIMIT 1"
);
$stmt->execute(['id' => $id]);
$surat = $stmt->fetch();

if (!$surat) {
    http_response_code(404);
    setFlash('error', 'Data surat tidak ditemukan.');
    redirect($returnTo);
}

// Access control
$hasAccess = false;
$user = currentUser();
if (isArsiparis() && ($surat['entry_type'] ?? '') !== 'surat') {
    $hasAccess = false;
} elseif (isAdmin() || isSekretaris() || isArsiparis()) {
    $hasAccess = true;
} elseif (isPimpinan()) {
    $hasAccess = true;
} elseif (isPegawai()) {
    $empId = (int) ($user['employee_id'] ?? 0);
    if ($empId > 0) {
        $chk = $pdo->prepare("SELECT 1 FROM agenda_disposition_items WHERE agenda_id = :aid AND employee_id = :eid LIMIT 1");
        $chk->execute(['aid' => $id, 'eid' => $empId]);
        if ($chk->fetchColumn()) {
            $hasAccess = true;
        }
    }
}

if (!$hasAccess) {
    http_response_code(403);
    $pageTitle = 'Akses Ditolak';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="rounded-3xl border border-red-200 bg-red-50 p-10 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto mb-4 h-16 w-16 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <h2 class="text-2xl font-extrabold text-red-700">Akses Ditolak</h2>
        <p class="mt-2 text-sm text-red-600">Anda tidak memiliki izin untuk melihat data ini.</p>
        <a href="' . e($returnTo) . '" class="mt-6 inline-flex rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-red-700">Kembali</a>
    </div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Agenda kegiatan AI yang terkait
$kegiatanStmt = $pdo->prepare(
    "SELECT * FROM agendas
     WHERE source_agenda_id = :sid AND entry_type = 'kegiatan' AND ai_generated = 1
     LIMIT 1"
);
$kegiatanStmt->execute(['sid' => $id]);
$kegiatan = $kegiatanStmt->fetch() ?: null;

// Timeline disposisi
$dispStmt = $pdo->prepare(
    "SELECT d.*, e.full_name AS employee_name_resolved
     FROM agenda_disposition_items d
     LEFT JOIN employees e ON e.id = d.employee_id
     WHERE d.agenda_id = :aid
     ORDER BY d.disposition_at ASC"
);
$dispStmt->execute(['aid' => $id]);
$dispositions = $dispStmt->fetchAll();

$isSurat = ($surat['entry_type'] ?? '') === 'surat';

$pageTitle = 'Detail ' . ($isSurat ? 'Surat' : 'Agenda') . ' — ' . e((string) $surat['title']);
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <nav class="flex text-sm font-medium text-slate-500 dark:text-slate-400 mb-2" aria-label="Breadcrumb">
            <a href="<?= e($returnTo); ?>" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Kembali</a>
            <span class="mx-2 text-slate-400">/</span>
            <span class="text-slate-700 dark:text-slate-300" aria-current="page">Detail <?= $isSurat ? 'Surat' : 'Agenda'; ?></span>
        </nav>
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">Detail <?= $isSurat ? 'Surat' : 'Agenda'; ?></h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Informasi lengkap dan riwayat disposisi</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if (isAdmin() || isSekretaris() || isArsiparis()): ?>
            <a href="agenda_form.php?id=<?= (int) $id; ?>&return_to=<?= urlencode($returnTo); ?>" class="inline-flex items-center gap-2 rounded-2xl bg-amber-500 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-amber-600 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"/></svg>
                Edit
            </a>
        <?php endif; ?>
        <?php if (isPimpinan() || isAdmin()): ?>
            <a href="disposisi_form.php?id=<?= (int) $id; ?>" class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5"/><path d="M20 20a7 7 0 0 0-7-7H4"/></svg>
                Beri Disposisi
            </a>
        <?php endif; ?>
        <a href="<?= e($returnTo); ?>" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 dark:border-slate-600 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            Kembali
        </a>
    </div>
</div>

<!-- ── Info Surat ───────────────────────────────────────────────────────────── -->
<section class="mb-5 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-6 shadow-sm">
    <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Informasi Surat</h2>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Nomor Referensi</p>
            <p class="mt-1 font-bold text-slate-800 dark:text-slate-200"><?= e((string) ($surat['reference_number'] ?: '-')); ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Jenis Surat</p>
            <p class="mt-1">
                <?php if (($surat['direction'] ?? '') === 'masuk'): ?>
                    <span class="ui-badge bg-emerald-100 text-emerald-700">Surat Masuk</span>
                <?php else: ?>
                    <span class="ui-badge bg-sky-100 text-sky-700">Surat Keluar</span>
                <?php endif; ?>
            </p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Status Disposisi</p>
            <p class="mt-1">
                <?php
                $ds = $surat['disposition_status'] ?? 'pending';
                $dsBadge = match ($ds) {
                    'approved' => ['bg-emerald-100 text-emerald-700', 'Disetujui'],
                    'rejected' => ['bg-rose-100 text-rose-700', 'Ditolak'],
                    default    => ['bg-amber-100 text-amber-700', 'Menunggu'],
                };
                ?>
                <span class="ui-badge <?= $dsBadge[0]; ?>"><?= $dsBadge[1]; ?></span>
            </p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Tanggal Surat</p>
            <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200"><?= e($surat['letter_date'] ? date('d F Y', strtotime($surat['letter_date'])) : '-'); ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Tanggal Diterima/Dicatat</p>
            <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200"><?= e($surat['recorded_date'] ? date('d F Y', strtotime($surat['recorded_date'])) : '-'); ?></p>
        </div>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Prioritas</p>
            <p class="mt-1">
                <?php
                $prio = $surat['priority'] ?? 'normal';
                $prioBadge = match ($prio) {
                    'tinggi' => ['bg-rose-100 text-rose-700', 'Tinggi'],
                    'rendah' => ['bg-slate-100 text-slate-600', 'Rendah'],
                    default  => ['bg-sky-100 text-sky-700', 'Normal'],
                };
                ?>
                <span class="ui-badge <?= $prioBadge[0]; ?>"><?= $prioBadge[1]; ?></span>
            </p>
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Pengirim / Penerima</p>
            <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200"><?= e((string) ($surat['counterparty'] ?: '-')); ?></p>
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Perihal</p>
            <p class="mt-1 text-base font-bold text-slate-900 dark:text-slate-100"><?= e((string) $surat['title']); ?></p>
        </div>
        <?php if (!empty($surat['agenda_date']) && (!empty($surat['agenda_date_end']) || $surat['agenda_date'] !== ($surat['letter_date'] ?? ''))): ?>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Tanggal Pelaksanaan Kegiatan</p>
            <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                <?= e(date('d F Y', strtotime((string)$surat['agenda_date']))); ?>
                <?php if (!empty($surat['agenda_date_end']) && $surat['agenda_date_end'] !== $surat['agenda_date']): ?>
                    <span class="text-slate-400 dark:text-slate-500 font-normal">s.d.</span> <?= e(date('d F Y', strtotime((string)$surat['agenda_date_end']))); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
        <?php if (!empty($surat['location'])): ?>
        <div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Lokasi</p>
            <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200"><?= e((string) $surat['location']); ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($surat['notes'])): ?>
        <div class="sm:col-span-2 lg:col-span-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Keterangan</p>
            <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-300"><?= e((string) $surat['notes']); ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($surat['description'])): ?>
        <div class="sm:col-span-2 lg:col-span-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Deskripsi</p>
            <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-300"><?= e((string) $surat['description']); ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($surat['attachment'])): ?>
        <div class="sm:col-span-2 lg:col-span-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Lampiran</p>
            <a href="file_preview.php?id=<?= (int) $surat['id']; ?>&return_to=surat_detail_v2.php&embed=0" target="_blank"
               class="mt-1 inline-flex items-center gap-2 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 px-3 py-2 text-sm font-semibold text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Lihat Lampiran · <?= e(attachmentStorageLabel((string) $surat['attachment'])); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($surat['attachment'])): ?>
<!-- ── Preview Lampiran ─────────────────────────────────────────────────────── -->
<section class="mb-5 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-6 shadow-sm">
    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Preview Surat</h2>
        <a href="file_preview.php?id=<?= (int) $surat['id']; ?>&return_to=surat_detail_v2.php&embed=0" target="_blank"
           class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700">Buka di Tab Baru</a>
    </div>
    <div class="overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-700">
        <iframe src="file_preview.php?id=<?= (int) $surat['id']; ?>&return_to=surat_detail_v2.php&embed=1"
                title="Preview Surat" class="h-[70vh] w-full"></iframe>
    </div>
</section>
<?php endif; ?>

<?php if ($kegiatan): ?>
<!-- ── Ringkasan Agenda Kegiatan AI ─────────────────────────────────────────── -->
<section class="mb-5 rounded-3xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-950/40 p-6 shadow-sm">
    <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-indigo-500 dark:text-indigo-400">Agenda Kegiatan (Dibuat AI)</h2>
    <div class="grid gap-3 sm:grid-cols-3">
        <div>
            <p class="text-xs text-indigo-500 dark:text-indigo-400">Judul</p>
            <p class="mt-1 font-semibold text-indigo-900 dark:text-indigo-100"><?= e((string) $kegiatan['title']); ?></p>
        </div>
        <div>
            <p class="text-xs text-indigo-500 dark:text-indigo-400">Tanggal</p>
            <p class="mt-1 font-semibold text-indigo-900 dark:text-indigo-100">
                <?php
                if ($kegiatan['agenda_date']) {
                    $d1k = date('d F Y', strtotime($kegiatan['agenda_date']));
                    $d2k = !empty($kegiatan['agenda_date_end']) ? date('d F Y', strtotime($kegiatan['agenda_date_end'])) : $d1k;
                    echo e($d1k === $d2k ? $d1k : "$d1k - $d2k");
                } else {
                    echo '-';
                }
                ?>
            </p>
        </div>
        <div>
            <p class="text-xs text-indigo-500 dark:text-indigo-400">Waktu</p>
            <p class="mt-1 font-semibold text-indigo-900 dark:text-indigo-100"><?= e($kegiatan['agenda_time'] ? substr($kegiatan['agenda_time'], 0, 5) . ' WIB' : '-'); ?></p>
        </div>
        <div>
            <p class="text-xs text-indigo-500 dark:text-indigo-400">Mode</p>
            <p class="mt-1">
                <?php $modeLabel = match ($kegiatan['agenda_mode'] ?? 'offline') { 'zoom' => 'Online (Zoom)', 'hybrid' => 'Hybrid', default => 'Offline' }; ?>
                <span class="font-semibold text-indigo-900 dark:text-indigo-100"><?= $modeLabel; ?></span>
            </p>
        </div>
        <div>
            <p class="text-xs text-indigo-500 dark:text-indigo-400">Lokasi</p>
            <p class="mt-1 font-semibold text-indigo-900 dark:text-indigo-100"><?= e((string) ($kegiatan['location'] ?: '-')); ?></p>
        </div>
        <?php if (!empty($kegiatan['zoom_link'])): ?>
        <div>
            <p class="text-xs text-indigo-500 dark:text-indigo-400">Link Zoom</p>
            <a href="<?= e((string) $kegiatan['zoom_link']); ?>" target="_blank" rel="noopener noreferrer"
               class="mt-1 block truncate text-sm font-semibold text-indigo-700 dark:text-indigo-300 hover:underline">
                <?= e((string) $kegiatan['zoom_link']); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Timeline Disposisi ──────────────────────────────────────────────────── -->
<section class="rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-6 shadow-sm">
    <h2 class="mb-5 text-sm font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">
        Riwayat Disposisi
        <?php if (count($dispositions) > 0): ?>
            <span class="ml-2 rounded-full bg-indigo-100 dark:bg-indigo-900/50 px-2 py-0.5 text-xs font-bold text-indigo-600 dark:text-indigo-400"><?= count($dispositions); ?></span>
        <?php endif; ?>
    </h2>

    <?php if (!$dispositions): ?>
        <div class="flex flex-col items-center justify-center py-12 opacity-70">
            <svg xmlns="http://www.w3.org/2000/svg" class="mb-3 h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
            </svg>
            <p class="text-sm text-slate-400 dark:text-slate-500">Belum ada riwayat disposisi untuk surat ini.</p>
        </div>
    <?php else: ?>
        <div class="relative">
            <!-- Timeline line -->
            <div class="absolute left-4 top-2 bottom-2 w-0.5 bg-slate-200 dark:bg-slate-700"></div>
            <ol class="space-y-5">
                <?php foreach ($dispositions as $disp): ?>
                    <li class="relative flex gap-4">
                        <!-- Dot -->
                        <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-white dark:border-slate-800 bg-indigo-600 shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </div>
                        <!-- Content -->
                        <div class="flex-1 rounded-2xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50 p-4 pb-3">
                            <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
                                <div>
                                    <span class="font-bold text-slate-800 dark:text-slate-200"><?= e((string) ($disp['employee_name'] ?: ($disp['employee_name_resolved'] ?? '-'))); ?></span>
                                    <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase
                                        <?= match ($disp['item_type']) { 'koordinasi' => 'bg-sky-100 text-sky-700', 'arahan' => 'bg-amber-100 text-amber-700', default => 'bg-indigo-100 text-indigo-700' }; ?>">
                                        <?= e(ucfirst((string) $disp['item_type'])); ?>
                                    </span>
                                </div>
                                <time class="text-[11px] text-slate-400 dark:text-slate-500 whitespace-nowrap">
                                    <?= e(date('d M Y, H:i', strtotime((string) $disp['disposition_at']))); ?>
                                </time>
                            </div>
                            <?php if (!empty($disp['message_text'])): ?>
                                <p class="text-sm text-slate-700 dark:text-slate-300 mb-1">
                                    <span class="font-semibold">Pesan:</span> <?= e((string) $disp['message_text']); ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($disp['instruction_text'])): ?>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">
                                    <span class="font-semibold">Instruksi:</span> <?= e((string) $disp['instruction_text']); ?>
                                </p>
                            <?php endif; ?>
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                                <?php if (!empty($disp['due_at'])): ?>
                                    <span>⏰ Tenggat: <?= e(date('d M Y H:i', strtotime((string) $disp['due_at']))); ?> WIB</span>
                                <?php endif; ?>
                                <?php if (!empty($disp['completed_at'])): ?>
                                    <span class="text-emerald-600 dark:text-emerald-400 font-semibold">✓ Selesai: <?= e(date('d M Y H:i', strtotime((string) $disp['completed_at']))); ?></span>
                                <?php elseif ($disp['read_status'] === 'sudah'): ?>
                                    <span class="text-teal-600 dark:text-teal-400">✓ Sudah dibaca</span>
                                <?php else: ?>
                                    <span class="text-amber-600 dark:text-amber-400">● Belum dibaca</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
