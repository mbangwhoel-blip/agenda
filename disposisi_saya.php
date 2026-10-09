<?php
declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
$pdo = getPdo();
requireRole(['pegawai', 'admin', 'sekretaris', 'pimpinan']);

$user = currentUser();
$employeeId = (int) ($user['employee_id'] ?? 0);

// FIX: Sinkronisasi ID Pegawai jika login menggunakan akun dari tabel users
if ($employeeId === 0 && isPegawai()) {
    $stmtEmp = $pdo->prepare('SELECT id FROM employees WHERE username = :username LIMIT 1');
    $stmtEmp->execute(['username' => $user['username'] ?? '']);
    $empMatch = $stmtEmp->fetch();
    if ($empMatch) {
        $employeeId = (int) $empMatch['id'];
        $_SESSION['user']['employee_id'] = $employeeId;
    } else {
        // Fallback pencarian berdasarkan nama lengkap
        $stmtEmpName = $pdo->prepare('SELECT id FROM employees WHERE full_name = :full_name LIMIT 1');
        $stmtEmpName->execute(['full_name' => $user['full_name'] ?? '']);
        $empNameMatch = $stmtEmpName->fetch();
        if ($empNameMatch) {
            $employeeId = (int) $empNameMatch['id'];
            $_SESSION['user']['employee_id'] = $employeeId;
        }
    }
}

if (isset($_GET['mark_read']) && (int) $_GET['mark_read'] > 0) {
    $readId = (int) $_GET['mark_read'];
    $upd = $pdo->prepare("UPDATE agenda_disposition_items SET read_status = 'sudah' WHERE id = :id AND employee_id = :emp_id");
    $upd->execute(['id' => $readId, 'emp_id' => $employeeId]);
    redirect('disposisi_saya.php');
}

// Untuk pegawai: filter by employee_id; admin/sekretaris: lihat semua
if (isPegawai()) {
    $stmt = $pdo->prepare("
        SELECT d.*, a.title, a.reference_number, a.letter_date, a.attachment
        FROM agenda_disposition_items d
        JOIN agendas a ON d.agenda_id = a.id
        WHERE d.employee_id = :emp_id
        ORDER BY d.disposition_at DESC
    ");
    $stmt->execute(['emp_id' => $employeeId]);
} else {
    $stmt = $pdo->query("
        SELECT d.*, a.title, a.reference_number, a.letter_date, a.attachment
        FROM agenda_disposition_items d
        JOIN agendas a ON d.agenda_id = a.id
        ORDER BY d.disposition_at DESC
    ");
}
$allDispositions = $stmt->fetchAll();

$total   = count($allDispositions);
$baru    = 0;
$selesai = 0;
$done    = 0;
foreach ($allDispositions as $d) {
    if ($d['read_status'] === 'belum') {
        $baru++;
    } else {
        $selesai++;
    }
    if (!empty($d['completed_at'])) {
        $done++;
    }
}

$p = max(1, (int)($_GET['p'] ?? 1));
$limit = 5;
$offset = ($p - 1) * $limit;

$dispositions = array_slice($allDispositions, $offset, $limit);

$pageTitle = 'Disposisi Saya';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-extrabold text-slate-900">Disposisi & Instruksi Saya</h2>
        <p class="mt-1 text-sm text-slate-500">Daftar tugas, disposisi, dan arahan yang ditugaskan kepada Anda.</p>
    </div>
</div>

<section class="mb-8">
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
        <!-- Card 1: Total Disposisi -->
        <article class="relative overflow-hidden flex flex-col rounded-3xl bg-gradient-to-br from-indigo-400 to-purple-500 p-6 text-white shadow-xl shadow-purple-500/20 transition-transform hover:-translate-y-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="absolute -right-4 -top-4 h-32 w-32 text-white/20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22h14a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"/><polyline points="14 2 14 8 20 8"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/></svg>
            <div class="relative z-10">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20 text-white backdrop-blur-md shadow-inner">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 22h14a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v4"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <p class="text-sm font-bold tracking-wide text-white/90">Total Disposisi</p>
                </div>
                <p class="mt-4 text-4xl font-black text-white"><?= $total; ?></p>
            </div>
        </article>

        <!-- Card 2: Belum Dibaca -->
        <article class="relative overflow-hidden flex flex-col rounded-3xl bg-gradient-to-br from-rose-400 to-red-500 p-6 text-white shadow-xl shadow-red-500/20 transition-transform hover:-translate-y-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="absolute -right-4 -top-4 h-32 w-32 text-white/20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div class="relative z-10">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20 text-white backdrop-blur-md shadow-inner">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <p class="text-sm font-bold tracking-wide text-white/90">Disposisi Baru (Belum Dibaca)</p>
                </div>
                <p class="mt-4 text-4xl font-black text-white"><?= $baru; ?></p>
            </div>
        </article>

        <!-- Card 3: Sudah Dibaca / Selesai -->
        <article class="relative overflow-hidden flex flex-col rounded-3xl bg-gradient-to-br from-emerald-400 to-teal-500 p-6 text-white shadow-xl shadow-emerald-500/20 transition-transform hover:-translate-y-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="absolute -right-4 -top-4 h-32 w-32 text-white/20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div class="relative z-10">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20 text-white backdrop-blur-md shadow-inner">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <p class="text-sm font-bold tracking-wide text-white/90">Sudah Dibaca / Selesai</p>
                </div>
                <p class="mt-4 text-4xl font-black text-white"><?= $selesai; ?></p>
            </div>
        </article>
    </div>
</section>

<div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-xs sm:text-sm leading-5 sm:leading-6 table-fixed">
        <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-[10px] sm:text-xs uppercase tracking-wider text-slate-500 font-semibold">
            <tr>
                <th class="px-1 sm:px-2 py-3 text-center w-8 sm:w-10">No</th>
                <th class="px-1 sm:px-2 py-3 text-left w-14 sm:w-20">Status</th>
                <th class="px-1 sm:px-2 py-3 text-left">Perihal</th>
                <th class="px-1 sm:px-2 py-3 text-left w-20 sm:w-32">Tipe/Ref</th>
                <th class="px-1 sm:px-2 py-3 text-left">Pesan/Instruksi</th>
                <th class="px-1 sm:px-2 py-3 text-left w-16 sm:w-24">Batas</th>
                <th class="px-1 sm:px-2 py-3 text-right w-20 sm:w-32">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
            <?php if (!$dispositions): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                        Belum ada disposisi atau instruksi untuk Anda.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = $offset + 1; ?>
                <?php foreach ($dispositions as $row): ?>
                    <tr class="align-top transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50 <?= $row['read_status'] === 'belum' ? 'bg-red-50/20' : ''; ?>">
                        <td class="px-1 sm:px-2 py-3 text-center font-semibold text-slate-500 border-l-4 <?= $row['read_status'] === 'belum' ? 'border-red-500' : 'border-transparent' ?>"><?= $no++; ?></td>
                        <td class="px-1 sm:px-2 py-3">
                            <?php if ($row['read_status'] === 'belum'): ?>
                                <span class="inline-flex rounded-full bg-red-100 px-2 sm:px-3 py-1 text-[10px] sm:text-xs font-bold text-red-700">Baru</span>
                            <?php else: ?>
                                <span class="inline-flex rounded-full bg-slate-100 px-2 sm:px-3 py-1 text-[10px] sm:text-xs font-bold text-slate-600">Dibaca</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-1 sm:px-2 py-3">
                            <div class="font-bold text-slate-900 break-words"><?= e($row['title']); ?></div>
                            <div class="text-[10px] sm:text-xs text-slate-500 mt-1">Tgl: <?= e($row['letter_date'] ?? '-'); ?></div>
                        </td>
                        <td class="px-1 sm:px-2 py-3">
                            <span class="inline-flex rounded-md bg-indigo-50 px-1.5 sm:px-2 py-0.5 sm:py-1 text-[10px] sm:text-xs font-semibold text-indigo-700 uppercase tracking-wide">
                                <?= e($row['item_type']); ?>
                            </span>
                            <div class="text-[10px] sm:text-xs text-slate-500 mt-1 break-all"><?= e($row['reference_number'] ?: '-'); ?></div>
                        </td>
                        <td class="px-1 sm:px-2 py-3">
                            <div class="text-slate-700 font-medium break-words"><?= nl2br(e($row['message_text'])); ?></div>
                            <?php if (!empty($row['instruction_text'])): ?>
                                <div class="mt-2 rounded-lg bg-orange-50 p-2 text-[10px] sm:text-xs text-orange-800 border border-orange-100 break-words">
                                    <strong>Instruksi:</strong><br>
                                    <?= nl2br(e($row['instruction_text'])); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-1 sm:px-2 py-3 text-[10px] sm:text-xs">
                            <?= $row['due_at'] ? date('d-m-Y H:i', strtotime($row['due_at'])) : '-'; ?>
                        </td>
                        <td class="px-1 sm:px-2 py-3 text-right">
                            <div class="flex justify-end gap-2 flex-wrap">
                                <a href="surat_detail_v2.php?id=<?= (int)$row['agenda_id']; ?>&return_to=disposisi_saya.php"
                                   class="inline-flex items-center justify-center h-8 w-8 rounded-xl bg-indigo-50 text-indigo-700 transition hover:bg-indigo-100" title="Lihat Detail">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </a>
                                <?php if ($row['read_status'] === 'belum'): ?>
                                    <a href="?mark_read=<?= (int)$row['id']; ?>"
                                       class="rounded-xl bg-sky-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-600">
                                        Tandai Dibaca
                                    </a>
                                <?php endif; ?>
                                <?php if (empty($row['completed_at'])): ?>
                                    <form method="post" action="mark_done.php" class="inline">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="item_id" value="<?= (int) $row['id']; ?>">
                                        <input type="hidden" name="return_to" value="disposisi_saya.php">
                                        <button type="submit"
                                                class="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                            Tandai Selesai
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="rounded-xl bg-emerald-50 border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                                        ✓ Selesai
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php renderPagination('p', $p, $total, $limit); ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
