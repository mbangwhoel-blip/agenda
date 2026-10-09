<?php

declare(strict_types=1);

/**
 * Onboarding flow — tampilkan tour singkat untuk user yang baru pertama login.
 * Diset flag `onboarding_done` di session setelah selesai.
 *
 * Tampilkan modal welcome sekali per user (cek user meta).
 */

require_once __DIR__ . '/app/helpers.php';
requireLogin();

$user = currentUser();
$pdo = getPdo();

// Auto-create user_meta table.
$pdo->exec("CREATE TABLE IF NOT EXISTS user_meta (
    user_id INT UNSIGNED PRIMARY KEY,
    onboarding_done TINYINT(1) NOT NULL DEFAULT 0,
    onboarding_done_at DATETIME NULL,
    preferences JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Cek apakah user sudah selesai onboarding.
$stmt = $pdo->prepare('SELECT onboarding_done FROM user_meta WHERE user_id = :uid');
$stmt->execute(['uid' => $user['id']]);
$done = (int)($stmt->fetchColumn() ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit;
    }
    // Tandai selesai.
    $pdo->prepare('INSERT INTO user_meta (user_id, onboarding_done, onboarding_done_at) VALUES (:uid, 1, NOW()) ON DUPLICATE KEY UPDATE onboarding_done = 1, onboarding_done_at = NOW()')
        ->execute(['uid' => $user['id']]);
    auditLogImmutable('onboarding_complete', "User {$user['username']} menyelesaikan onboarding");
    echo json_encode(['ok' => true]);
    exit;
}

// Jika sudah selesai, jangan tampilkan onboarding lagi.
if ($done === 1 && !isset($_GET['force'])) {
    redirect('dashboard.php');
}

$pageTitle = 'Selamat Datang';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-3xl mx-auto py-8" x-data="{ step: 1, total: 5 }">
    <h1 class="text-3xl font-extrabold text-slate-900 dark:text-slate-100 mb-2">Selamat Datang, <?= e($user['full_name']) ?>!</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-8">Mari berkenalan dengan sistem Agenda Pimpinan dalam <?= $total ?? 5 ?> langkah singkat.</p>

    <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-6 shadow-sm">
        <!-- Progress bar -->
        <div class="mb-6">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500 mb-2">
                <span>Langkah <span x-text="step"></span> dari <span x-text="total"></span></span>
                <span><span x-text="Math.round(step/total*100)"></span>%</span>
            </div>
            <div class="h-2 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full bg-teal-500 transition-all duration-300" :style="`width: ${(step/total)*100}%`"></div>
            </div>
        </div>

        <!-- Step 1: Dashboard -->
        <div x-show="step === 1" class="space-y-3">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-teal-50 dark:bg-teal-900/40 text-teal-600 dark:text-teal-400 shrink-0">
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Dashboard</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Pusat kendali Anda. Lihat statistik surat masuk/keluar, kegiatan mendatang, dan agenda Zoom dalam satu layar. Grafik batang menunjukkan volume surat per bulan.</p>
                </div>
            </div>
        </div>

        <!-- Step 2: Agenda Surat -->
        <div x-show="step === 2" x-cloak class="space-y-3">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 shrink-0">
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Input Surat Masuk & Keluar</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Catat semua surat masuk dan keluar. Sistem AI akan otomatis membaca lampiran PDF dan mengekstrak jadwal kegiatan, link Zoom, dan passcode.</p>
                </div>
            </div>
        </div>

        <!-- Step 3: Disposisi -->
        <div x-show="step === 3" x-cloak class="space-y-3">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 shrink-0">
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Disposisi Surat</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Pimpinan menyerahkan disposisi surat ke pegawai. Pegawai menerima notifikasi WhatsApp + email + in-app. Status pelacakan real-time.</p>
                </div>
            </div>
        </div>

        <!-- Step 4: Zoom -->
        <div x-show="step === 4" x-cloak class="space-y-3">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 shrink-0">
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Agenda Zoom</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Semua meeting Zoom di satu tempat. Tombol "Gabung" langsung membuka Zoom. Meeting ID & passcode otomatis tampil. Pengingat H-1 via WhatsApp.</p>
                </div>
            </div>
        </div>

        <!-- Step 5: Security -->
        <div x-show="step === 5" x-cloak class="space-y-3">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 shrink-0">
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Keamanan Akun</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Aktifkan 2FA di menu "Autentikasi 2FA" untuk melindungi akun dari pengambilalihan. Sesi otomatis logout setelah 30 menit tidak aktif.</p>
                    <?php if (isAdmin() || isSekretaris() || isPimpinan()): ?>
                    <a href="setup_2fa.php" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 text-sm font-bold">Aktifkan 2FA Sekarang</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="mt-6 flex items-center justify-between">
            <button type="button" @click="step = Math.max(1, step - 1)" :disabled="step === 1"
                    class="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed">
                ← Sebelumnya
            </button>
            <button type="button" @click="step < total ? step++ : completeOnboarding()"
                    class="rounded-xl bg-teal-600 hover:bg-teal-700 text-white px-5 py-2 text-sm font-bold">
                <span x-text="step < total ? 'Lanjut →' : 'Selesai'"></span>
            </button>
        </div>
    </div>
</div>

<script>
function completeOnboarding() {
    fetch('onboarding.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=<?= e(csrf_token()) ?>'
    }).then(r => r.json()).then(() => {
        window.location.href = 'dashboard.php';
    }).catch(() => {
        window.location.href = 'dashboard.php';
    });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
