<?php
declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
requireRole(['admin']);

$pdo = getPdo();
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$totalStmt = $pdo->query("SELECT COUNT(*) FROM activity_logs");
$totalItems = (int) $totalStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT a.*, u.full_name as user_name, u.role as user_role 
    FROM activity_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY a.created_at DESC 
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$pageTitle = 'Log Aktivitas';
require_once __DIR__ . '/includes/header.php';
?>

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-2xl font-extrabold text-slate-900">Log Aktivitas Sistem</h2>
        <p class="text-sm text-slate-500">Mencatat riwayat seluruh aktivitas yang dilakukan oleh pengguna.</p>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full table-fixed text-sm">
            <thead class="bg-slate-50 text-slate-500">
                <tr>
                    <th class="w-48 px-4 py-3 text-left font-bold uppercase">Waktu</th>
                    <th class="w-48 px-4 py-3 text-left font-bold uppercase">Pengguna</th>
                    <th class="w-32 px-4 py-3 text-left font-bold uppercase">Aksi</th>
                    <th class="w-96 px-4 py-3 text-left font-bold uppercase">Keterangan</th>
                    <th class="w-32 px-4 py-3 text-left font-bold uppercase">IP Address</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada aktivitas yang dicatat.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="px-4 py-3"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                            <td class="px-4 py-3">
                                <?php if ($log['user_name']): ?>
                                    <div class="font-semibold"><?= e($log['user_name']) ?></div>
                                    <div class="text-xs text-slate-400 uppercase"><?= e($log['user_role']) ?></div>
                                <?php else: ?>
                                    <span class="italic text-slate-400">Sistem / Terhapus</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3"><span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600"><?= e($log['action']) ?></span></td>
                            <td class="px-4 py-3"><?= e($log['description']) ?></td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400"><?= e($log['ip_address'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php renderPagination('page', $page, $totalItems, $limit); ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
