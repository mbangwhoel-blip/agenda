<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();

if (!canApproveDisposisi()) {
    setFlash('error', 'Menu disposisi hanya untuk pimpinan.');
    redirect('dashboard.php');
}

$flash = getFlash();
$q = trim((string) ($_GET['q'] ?? ''));
$direction = trim((string) ($_GET['direction'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

if (!in_array($direction, ['', 'masuk', 'keluar'], true)) {
    $direction = '';
}
if (!in_array($status, ['', 'pending', 'approved', 'rejected'], true)) {
    $status = '';
}

$conditions = ["a.entry_type = 'surat'"];
$params = [];

if ($q !== '') {
    $conditions[] = '(a.reference_number LIKE :q1 OR a.title LIKE :q2 OR a.counterparty LIKE :q3)';
    $params['q1'] = "%{$q}%";
    $params['q2'] = "%{$q}%";
    $params['q3'] = "%{$q}%";
}
if ($direction !== '') {
    $conditions[] = 'a.direction = :direction';
    $params['direction'] = $direction;
}
if ($status !== '') {
    $conditions[] = 'a.disposition_status = :disposition_status';
    $params['disposition_status'] = $status;
}

$p = max(1, (int) ($_GET['p'] ?? 1));
$limit = 5;
$offset = ($p - 1) * $limit;

$countSql = "SELECT COUNT(*) FROM agendas a WHERE " . implode(' AND ', $conditions);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalItems / $limit));

$sql = "SELECT a.*, u.full_name AS disposition_by_name
        FROM agendas a
        LEFT JOIN users u ON u.id = a.disposition_by
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY COALESCE(a.letter_date, a.recorded_date, a.agenda_date) DESC, a.id DESC
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statsStmt = $pdo->query(
    "SELECT
        SUM(CASE WHEN disposition_status = 'pending' OR disposition_status IS NULL OR disposition_status = '' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN disposition_status = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN disposition_status = 'rejected' THEN 1 ELSE 0 END) AS rejected
     FROM agendas
     WHERE entry_type = 'surat'"
);
$stats = $statsStmt->fetch() ?: ['pending' => 0, 'approved' => 0, 'rejected' => 0];

function disposisiStatusBadge(string $status): array
{
    if ($status === 'approved') {
        return ['label' => 'Disetujui', 'class' => 'bg-emerald-100 text-emerald-700'];
    }
    if ($status === 'rejected') {
        return ['label' => 'Ditolak', 'class' => 'bg-rose-100 text-rose-700'];
    }
    return ['label' => 'Menunggu', 'class' => 'bg-amber-100 text-amber-700'];
}

$pageTitle = 'Disposisi Surat';
require_once __DIR__ . '/includes/header.php';
?>
<div x-data="{ showFilter: false }">
<?php if ($flash): ?>
    <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 2600)" x-transition class="mb-6 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
        <?= e($flash['message']); ?>
    </div>
<?php endif; ?>

<section class="mb-6">
    <div class="grid gap-6 sm:grid-cols-3">
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-amber-50 to-transparent dark:from-amber-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Menunggu Disposisi</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['pending']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Sudah Disetujui</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['approved']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-rose-50 to-transparent dark:from-rose-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Ditolak</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['rejected']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
            </div>
        </article>
    </div>
</section>

<section class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-3xl font-extrabold text-slate-900">Disposisi Surat Masuk/Keluar</h2>

</section>

<section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5">
    <form method="get" class="grid gap-3 lg:grid-cols-10">
        <div class="lg:col-span-5">
            <input type="text" name="q" value="<?= e($q); ?>" placeholder="Cari nomor surat, pengirim/penerima, perihal..." class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100">
        </div>
        <div class="lg:col-span-2">
            <select name="direction" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100">
                <option value="" <?= $direction === '' ? 'selected' : ''; ?>>Semua Jenis</option>
                <option value="masuk" <?= $direction === 'masuk' ? 'selected' : ''; ?>>Surat Masuk</option>
                <option value="keluar" <?= $direction === 'keluar' ? 'selected' : ''; ?>>Surat Keluar</option>
            </select>
        </div>
        <div class="lg:col-span-2">
            <select name="status" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100">
                <option value="" <?= $status === '' ? 'selected' : ''; ?>>Semua Status</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                <option value="approved" <?= $status === 'approved' ? 'selected' : ''; ?>>Disetujui</option>
                <option value="rejected" <?= $status === 'rejected' ? 'selected' : ''; ?>>Ditolak</option>
            </select>
        </div>
        <div class="lg:col-span-1">
            <button type="submit" class="w-full rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white hover:bg-indigo-700">Cari</button>
        </div>
    </form>
</section>

<section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
    <!-- Desktop table -->
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full min-w-[1100px] table-fixed text-sm leading-6">
            <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-wider text-slate-500 font-semibold">
                <tr>
                    <th class="w-12 px-4 py-4 text-left">No</th>
                    <th class="w-40 px-4 py-4 text-left">Nomor Surat</th>
                    <th class="w-32 px-4 py-4 text-left">Tanggal Surat</th>
                    <th class="w-40 px-4 py-4 text-left">Pengirim/Penerima</th>
                    <th class="px-4 py-4 text-left">Perihal & Arahan</th>
                    <th class="w-32 px-4 py-4 text-left">Aksi</th>
                    <th class="w-36 px-4 py-4 text-left">Status Disposisi</th>
                    <th class="w-32 px-4 py-4 text-left">Jenis</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="py-16 text-center">
                            <div class="flex flex-col items-center justify-center opacity-80">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-16 w-16 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                                <h5 class="text-xl font-bold text-slate-500">Belum Ada Disposisi</h5>
                                <p class="mt-2 text-slate-400">Data surat yang memerlukan disposisi pimpinan akan tampil di sini.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <?php $badge = disposisiStatusBadge((string) ($row['disposition_status'] ?? 'pending')); ?>
                        <tr class="align-middle hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors <?= ($row['disposition_status'] ?? 'pending') === 'pending' ? 'bg-rose-50/30' : ''; ?>">
                            <td class="px-4 py-4 font-semibold text-indigo-600"><?= $offset + $i + 1; ?></td>
                            <td class="break-words px-4 py-4 font-semibold text-slate-800"><?= e((string) ($row['reference_number'] ?: '-')); ?></td>
                            <td class="px-4 py-4 font-semibold text-slate-700"><?= e(date('d-m-Y', strtotime((string) (($row['letter_date'] ?: $row['agenda_date']) ?: date('Y-m-d'))))); ?></td>
                            <td class="break-words px-4 py-4">
                                <?php $cpName = (string) ($row['counterparty'] ?: 'Unknown'); ?>
                                <div class="flex items-center gap-2">
                                    <div class="ui-avatar h-6 w-6 text-[10px] <?= getAvatarColor($cpName) ?>">
                                        <?= e(strtoupper(substr($cpName, 0, 1))); ?>
                                    </div>
                                    <span class="text-xs font-medium text-slate-600"><?= e($cpName); ?></span>
                                </div>
                            </td>
                            <td class="break-words px-4 py-4">
                                <p class="font-semibold text-slate-800"><?= e((string) $row['title']); ?></p>
                                <?php if (!empty($row['disposition_note'])): ?>
                                    <p class="mt-1 text-xs text-slate-500">Arahan: <?= e((string) $row['disposition_note']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4">
                                <a href="disposisi_form.php?id=<?= (int) $row['id']; ?>" class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 hover:shadow">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M5 12h14"></path>
                                        <path d="m12 5 7 7-7 7"></path>
                                    </svg>
                                    Atur Dispo
                                </a>
                            </td>
                            <td class="px-4 py-4">
                                <span class="ui-badge <?= e((string) $badge['class']); ?>"><?= e((string) $badge['label']); ?></span>
                                <?php if (!empty($row['disposition_by_name'])): ?>
                                    <p class="mt-1 text-xs text-slate-500"><?= e((string) $row['disposition_by_name']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4">
                                <?php if (($row['direction'] ?? '') === 'masuk'): ?>
                                    <span class="ui-badge bg-emerald-100 text-emerald-700">Surat Masuk</span>
                                <?php else: ?>
                                    <span class="ui-badge bg-sky-100 text-sky-700">Surat Keluar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile card view -->
    <div class="sm:hidden divide-y divide-slate-100">
        <?php if (!$rows): ?>
            <div class="px-5 py-16 text-center">
                <div class="flex flex-col items-center justify-center opacity-80">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-14 w-14 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <h5 class="text-lg font-bold text-slate-500">Belum Ada Disposisi</h5>
                    <p class="mt-1 text-sm text-slate-400">Belum ada data disposisi yang tersedia.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($rows as $i => $row):
                $badge = disposisiStatusBadge((string) ($row['disposition_status'] ?? 'pending'));
            ?>
            <div class="px-5 py-4 space-y-2 <?= ($row['disposition_status'] ?? 'pending') === 'pending' ? 'bg-rose-50/10' : ''; ?>">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-bold text-slate-900 text-sm"><?= e((string) ($row['reference_number'] ?: '-')); ?></p>
                        <p class="text-xs text-slate-500"><?= e(date('d-m-Y', strtotime((string) (($row['letter_date'] ?: $row['agenda_date']) ?: date('Y-m-d'))))); ?></p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-1">
                        <?php if (($row['direction'] ?? '') === 'masuk'): ?>
                            <span class="ui-badge bg-emerald-100 text-emerald-700">Masuk</span>
                        <?php else: ?>
                            <span class="ui-badge bg-sky-100 text-sky-700">Keluar</span>
                        <?php endif; ?>
                        <span class="ui-badge <?= e((string) $badge['class']); ?>"><?= e((string) $badge['label']); ?></span>
                    </div>
                </div>
                <p class="text-sm text-slate-800 font-semibold"><?= e((string) $row['title']); ?></p>
                <p class="text-xs text-slate-500"><?= e((string) ($row['counterparty'] ?: '-')); ?></p>
                <a href="disposisi_form.php?id=<?= (int) $row['id']; ?>"
                   class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                    Atur Disposisi →
                </a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php renderPagination('p', $p, $totalItems, $limit); ?>
</section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
