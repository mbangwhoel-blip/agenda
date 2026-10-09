<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();

if (!canManageEmployees()) {
    setFlash('error', 'Akses master pegawai hanya untuk admin/pimpinan.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_api') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token keamanan tidak valid.');
        redirect('master_pegawai.php');
    }
    $url = getAppSetting('simpeg_api_url');
    $key = getAppSetting('simpeg_api_key');
    if (empty($url)) {
        setFlash('error', 'URL API SIMPEG belum diatur. Silakan atur di menu Pengaturan terlebih dahulu.');
        redirect('master_pegawai.php');
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($key)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-Key: $key"]);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log('[master_pegawai.php] SIMPEG API failed: HTTP ' . $httpCode . ' - ' . $curlErr);
        setFlash('error', 'Gagal menghubungi API SIMPEG. Silakan coba lagi nanti.');
        redirect('master_pegawai.php');
    }

    $json = json_decode($response, true);
    $data = $json['data'] ?? (isset($json['nip']) ? [$json] : []); // support array data or single row
    if (!is_array($data) || empty($data)) {
        setFlash('error', 'Format data API tidak sesuai atau data kosong.');
        redirect('master_pegawai.php');
    }

    $inserted = 0;
    $updated = 0;
    $pdo->beginTransaction();
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE nip = ? LIMIT 1");
        $insertStmt = $pdo->prepare("INSERT INTO employees (nip, full_name, position_title, email, is_active, unit_name) VALUES (?, ?, ?, ?, ?, ?)");
        $updateStmt = $pdo->prepare("UPDATE employees SET full_name = ?, position_title = ?, email = ?, is_active = ?, unit_name = ? WHERE id = ?");
        foreach ($data as $emp) {
            $nip = $emp['nip'] ?? '';
            if (empty($nip)) continue;
            
            $nama = $emp['nama'] ?? '';
            $jabatan = $emp['nama_jabatan'] ?? '';
            $email = $emp['email'] ?? '';
            $status = ($emp['status'] ?? 'Aktif') === 'Aktif' ? 1 : 0;
            $unit = $emp['unit_kerja'] ?? '';
            
            $checkStmt->execute([$nip]);
            $exists = $checkStmt->fetchColumn();
            if ($exists) {
                $updateStmt->execute([$nama, $jabatan, $email, $status, $unit, $exists]);
                $updated++;
            } else {
                $insertStmt->execute([$nip, $nama, $jabatan, $email, $status, $unit]);
                $inserted++;
            }
        }
        $pdo->commit();
        setFlash('success', "Sinkronisasi berhasil! $inserted data baru, $updated data diperbarui.");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[master_pegawai.php] Sync error: ' . $e->getMessage());
        setFlash('error', 'Gagal menyimpan data sinkronisasi. Silakan coba lagi atau hubungi administrator.');
    }
    redirect('master_pegawai.php');
}

$flash = getFlash();
$q = trim((string) ($_GET['q'] ?? ''));
$active = trim((string) ($_GET['active'] ?? ''));
if (!in_array($active, ['', '1', '0'], true)) {
    $active = '';
}

$conditions = ['1=1'];
$params = [];
if ($q !== '') {
    $conditions[] = '(full_name LIKE :q1 OR nip LIKE :q2 OR position_title LIKE :q3 OR unit_name LIKE :q4 OR email LIKE :q5)';
    $params['q1'] = "%{$q}%";
    $params['q2'] = "%{$q}%";
    $params['q3'] = "%{$q}%";
    $params['q4'] = "%{$q}%";
    $params['q5'] = "%{$q}%";
}
if ($active !== '') {
    $conditions[] = 'is_active = :is_active';
    $params['is_active'] = (int) $active;
}

$sql = "SELECT * FROM employees WHERE " . implode(' AND ', $conditions) . " ORDER BY full_name ASC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statsStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS aktif,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS nonaktif
     FROM employees"
);
$stats = $statsStmt->fetch() ?: ['total' => 0, 'aktif' => 0, 'nonaktif' => 0];

$pageTitle = 'Master Pegawai';
require_once __DIR__ . '/includes/header.php';
?>
<div x-data="{ showFilter: false }">
    <?php if ($flash): ?>
        <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 2800)" x-transition class="mb-6 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
            <?= e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <section class="mb-6">
        <div class="grid gap-6 sm:grid-cols-3">
            <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-transparent dark:from-indigo-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Total Pegawai</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['total']; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                </div>
            </article>
            
            <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Pegawai Aktif</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['aktif']; ?></p>
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
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Pegawai Nonaktif</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['nonaktif']; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                </div>
            </article>
        </div>
    </section>

    <section class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-3xl font-extrabold text-slate-900">Master Pegawai</h2>
        <div class="flex items-center gap-2">

            <form method="post" class="inline-block" onsubmit="return confirm('Proses ini akan menarik data terbaru dari SIMPEG dan mungkin memerlukan waktu. Lanjutkan?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_api">
                <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-teal-500 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-teal-200 hover:bg-teal-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 2v6h-6"></path>
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"></path>
                        <path d="M3 22v-6h6"></path>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"></path>
                    </svg>
                    Tarik SIMPEG
                </button>
            </form>
            <a href="master_pegawai_form.php" class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700">+ Tambah</a>
        </div>
    </section>

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-5">
        <form method="get" class="grid gap-3 lg:grid-cols-10">
            <div class="lg:col-span-7">
                <input type="text" name="q" value="<?= e($q); ?>" placeholder="Cari nama, NIP, jabatan, unit..." class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">
            </div>
            <div class="lg:col-span-2">
                <select name="active" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                    <option value="" <?= $active === '' ? 'selected' : ''; ?>>Semua Status</option>
                    <option value="1" <?= $active === '1' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="0" <?= $active === '0' ? 'selected' : ''; ?>>Nonaktif</option>
                </select>
            </div>
            <div class="lg:col-span-1">
                <button type="submit" class="w-full rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white hover:bg-indigo-700">Cari</button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm leading-6">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-wider text-slate-500 font-semibold">
                    <tr>
                        <th class="px-4 py-4 text-left whitespace-nowrap">No</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">NIP</th>
                        <th class="px-4 py-4 text-left min-w-[200px]">Nama Pegawai</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Email</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">No. WhatsApp</th>
                        <th class="px-4 py-4 text-left min-w-[150px]">Jabatan</th>
                        <th class="px-4 py-4 text-left min-w-[150px]">Unit</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Status</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="py-16 text-center">
                                <div class="flex flex-col items-center justify-center opacity-80">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-16 w-16 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    <h5 class="text-xl font-bold text-slate-500">Belum Ada Data Pegawai</h5>
                                    <p class="mt-2 text-slate-400">Daftar pengguna dan pegawai sistem akan dikelola di sini.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $i => $emp): ?>
                            <tr class="align-middle hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="px-4 py-4 font-semibold text-indigo-600"><?= $i + 1; ?></td>
                                <td class="px-4 py-4 font-semibold text-slate-700"><?= e((string) ($emp['nip'] ?: '-')); ?></td>
                                <td class="px-4 py-4 font-semibold text-slate-800"><?= e((string) $emp['full_name']); ?></td>
                                <td class="px-4 py-4 text-slate-600 font-medium"><?= e((string) ($emp['email'] ?: '-')); ?></td>
                                <td class="px-4 py-4 text-slate-600 font-medium"><?= e((string) ($emp['whatsapp_number'] ?: '-')); ?></td>
                                <td class="px-4 py-4"><?= e((string) ($emp['position_title'] ?: '-')); ?></td>
                                <td class="px-4 py-4"><?= e((string) ($emp['unit_name'] ?: '-')); ?></td>
                                <td class="px-4 py-4">
                                    <span class="ui-badge <?= (int) $emp['is_active'] === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'; ?>">
                                        <?= (int) $emp['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <a href="master_pegawai_form.php?id=<?= (int) $emp['id']; ?>" class="ui-icon-btn bg-amber-100 text-amber-700 transition hover:bg-amber-200" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"></path></svg>
                                        </a>
                                        <a href="master_pegawai_delete.php?id=<?= (int) $emp['id']; ?>&token=<?= e(csrf_token()); ?>" data-confirm-delete class="ui-icon-btn bg-red-100 text-red-700 transition hover:bg-red-200" title="Hapus">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6M14 11v6"></path></svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
