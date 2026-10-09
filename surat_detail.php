<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$returnTo = $_GET['return_to'] ?? 'dashboard.php';
if (!in_array($returnTo, ['dashboard.php', 'agenda.php', 'agenda_kegiatan.php', 'agenda_zoom.php', 'disposisi.php', 'disposisi_saya.php'], true)) {
    $returnTo = 'dashboard.php';
}

if ($id <= 0) {
    setFlash('error', 'ID surat tidak valid.');
    redirect($returnTo);
}

$pdo = getPdo();
$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS created_by
     FROM agendas a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE a.id = :id AND a.entry_type = 'surat'
     LIMIT 1"
);
$stmt->execute(['id' => $id]);
$surat = $stmt->fetch();

if (!$surat) {
    setFlash('error', 'Data surat tidak ditemukan.');
    redirect($returnTo);
}

$hasAccess = false;
if (canManageLetterArchive((int) ($surat['user_id'] ?? 0))) {
    $hasAccess = true;
} elseif (isPimpinan()) {
    $hasAccess = true;
} elseif (isPegawai()) {
    $chk = $pdo->prepare("SELECT 1 FROM agenda_disposition_items WHERE agenda_id = :aid AND employee_id = :eid LIMIT 1");
    $chk->execute(['aid' => $id, 'eid' => $_SESSION['user']['employee_id'] ?? 0]);
    if ($chk->fetchColumn()) {
        $hasAccess = true;
    }
}

if (!$hasAccess) {
    setFlash('error', 'Anda tidak punya akses melihat surat ini.');
    redirect($returnTo);
}

$pageTitle = 'Detail Surat';
require_once __DIR__ . '/includes/header.php';
?>

<section class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-xl font-extrabold text-slate-900">Detail Surat</h2>
        <a href="<?= e($returnTo); ?>" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Kembali</a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Nomor Surat</p><p class="font-semibold"><?= e((string) ($surat['reference_number'] ?: '-')); ?></p></div>
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Jenis Surat</p><p class="font-semibold"><?= e(($surat['direction'] ?? 'masuk') === 'masuk' ? 'Surat Masuk' : 'Surat Keluar'); ?></p></div>
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Tanggal Surat</p><p class="font-semibold"><?= e((string) ($surat['letter_date'] ?: $surat['agenda_date'])); ?></p></div>
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Tanggal Terima/Pencatatan</p><p class="font-semibold"><?= e((string) ($surat['recorded_date'] ?: $surat['agenda_date'])); ?></p></div>
        <div class="rounded-xl bg-slate-50 p-3 sm:col-span-2"><p class="text-xs text-slate-500">Pengirim/Penerima</p><p class="font-semibold"><?= e((string) ($surat['counterparty'] ?: '-')); ?></p></div>
        <div class="rounded-xl bg-slate-50 p-3 sm:col-span-2"><p class="text-xs text-slate-500">Perihal</p><p class="font-semibold"><?= e((string) $surat['title']); ?></p></div>
        <div class="rounded-xl bg-slate-50 p-3 sm:col-span-2"><p class="text-xs text-slate-500">Keterangan</p><p class="font-semibold"><?= e((string) ($surat['notes'] ?: '-')); ?></p></div>
    </div>
</section>

<?php if (!empty($surat['attachment'])): ?>
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="font-bold text-slate-900">Preview Surat</h3>
            <a href="file_preview.php?id=<?= (int) $surat['id']; ?>&return_to=surat_detail.php&embed=0" target="_blank" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">Buka di Tab Baru</a>
        </div>
        <div class="overflow-hidden rounded-xl border border-slate-200">
            <iframe src="file_preview.php?id=<?= (int) $surat['id']; ?>&return_to=surat_detail.php&embed=1" title="Preview Surat" class="h-[75vh] w-full"></iframe>
        </div>
    </section>
<?php else: ?>
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
        Surat ini belum memiliki lampiran file.
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
