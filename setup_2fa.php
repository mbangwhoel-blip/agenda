<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
requireLogin();

// Hanya admin/sekretaris/pimpinan yang wajib/wajib bisa setup 2FA.
$user = currentUser();
if (!isAdmin() && !isSekretaris() && !isPimpinan()) {
    setFlash('error', '2FA hanya berlaku untuk akun admin, sekretaris, dan pimpinan.');
    redirect('dashboard.php');
}

$pdo = getPdo();
$flash = getFlash();
$error = '';
$step = 'setup'; // 'setup' | 'verify'

// Ambil data user dari DB.
$stmt = $pdo->prepare('SELECT id, username, full_name, totp_secret, totp_enabled, totp_backup_codes FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $user['id']]);
$userRow = $stmt->fetch();

if (!$userRow) {
    setFlash('error', 'User tidak ditemukan.');
    redirect('logout.php');
}

$is2FAEnabled = (int)($userRow['totp_enabled'] ?? 0) === 1;

// Generate secret baru kalau belum ada (untuk flow setup).
if (empty($userRow['totp_secret'])) {
    $newSecret = generateTotpSecret();
    $upd = $pdo->prepare('UPDATE users SET totp_secret = :s WHERE id = :id');
    $upd->execute(['s' => $newSecret, 'id' => $user['id']]);
    $userRow['totp_secret'] = $newSecret;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';
        $code = trim($_POST['code'] ?? '');

        if ($action === 'enable') {
            if (!verifyTotp($userRow['totp_secret'], $code)) {
                $error = 'Kode TOTP salah. Coba lagi.';
                $step = 'verify';
            } else {
                // Generate backup codes (10x 8 hex).
                $backupCodes = [];
                for ($i = 0; $i < 10; $i++) {
                    $backupCodes[] = bin2hex(random_bytes(4));
                }
                $hashed = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $backupCodes);

                $upd = $pdo->prepare('UPDATE users SET totp_enabled = 1, totp_backup_codes = :bc WHERE id = :id');
                $upd->execute(['bc' => json_encode($hashed), 'id' => $user['id']]);
                auditLogImmutable('2fa_enable', "User {$user['username']} mengaktifkan 2FA");
                setFlash('success', '2FA berhasil diaktifkan. Simpan kode cadangan di tempat aman.');
                // Tampilkan backup codes sekali.
                $_SESSION['backup_codes_display'] = $backupCodes;
                redirect('setup_2fa.php?show_backup=1');
            }
        } elseif ($action === 'disable') {
            // Disable butuh verifikasi kode TOTP.
            if (!verifyTotp($userRow['totp_secret'], $code)) {
                $error = 'Kode TOTP salah. 2FA tidak dinonaktifkan.';
                $step = 'verify_disable';
            } else {
                $upd = $pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL WHERE id = :id');
                $upd->execute(['id' => $user['id']]);
                auditLogImmutable('2fa_disable', "User {$user['username']} menonaktifkan 2FA");
                setFlash('success', '2FA berhasil dinonaktifkan.');
                redirect('setup_2fa.php');
            }
        }
    }
}

$showBackup = isset($_GET['show_backup']) && isset($_SESSION['backup_codes_display']);
$backupCodes = $showBackup ? $_SESSION['backup_codes_display'] : [];
if ($showBackup) {
    unset($_SESSION['backup_codes_display']);
}

// Generate otpauth URI untuk QR.
$qrUri = totpQrUri($userRow['totp_secret'], 'Agenda Pimpinan BBLKL', $userRow['username']);
// Pakai QR server Google Chart (offline alternative: render manual di client).
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($qrUri);

$pageTitle = 'Pengaturan 2FA';
require_once __DIR__ . '/includes/header.php';
?>
<nav aria-label="Breadcrumb" class="mb-4">
    <ol class="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
        <li><a href="dashboard.php" class="hover:text-teal-600">Dashboard</a></li>
        <li aria-hidden="true" class="text-slate-300">/</li>
        <li class="text-slate-700 dark:text-slate-200" aria-current="page">Pengaturan 2FA</li>
    </ol>
</nav>

<section class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100 mb-2">Autentikasi Dua Faktor (2FA)</h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Lindungi akun Anda dengan kode verifikasi dari aplikasi authenticator (Google Authenticator, Authy, 1Password, dll).</p>

    <?php if ($flash): ?>
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:border-emerald-800 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200 px-4 py-3 text-sm"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($showBackup): ?>
    <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 p-5">
        <h2 class="font-bold text-amber-900 dark:text-amber-200 mb-2">Kode Cadangan (Backup Codes)</h2>
        <p class="text-sm text-amber-800 dark:text-amber-300 mb-3">Simpan kode berikut di tempat aman. Tiap kode bisa dipakai sekali jika Anda kehilangan perangkat authenticator.</p>
        <div class="grid grid-cols-2 gap-2 font-mono text-sm bg-white dark:bg-slate-800 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
            <?php foreach ($backupCodes as $code): ?>
                <div class="px-2 py-1 rounded"><?= e($code) ?></div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">Kode ini hanya ditampilkan sekali. Screenshot atau catat sekarang.</p>
    </div>
    <?php endif; ?>

    <?php if ($is2FAEnabled): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800 p-5 mb-6">
            <div class="flex items-center gap-3">
                <svg class="h-8 w-8 text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div>
                    <p class="font-bold text-emerald-900 dark:text-emerald-200">2FA Aktif</p>
                    <p class="text-sm text-emerald-700 dark:text-emerald-300">Akun Anda terlindungi 2FA sejak sekarang.</p>
                </div>
            </div>
        </div>

        <form method="post" class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disable">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-3">Nonaktifkan 2FA</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Masukkan kode TOTP untuk konfirmasi.</p>
            <label for="code-disable" class="block text-sm font-semibold text-slate-600 dark:text-slate-300 mb-1">Kode 6-digit dari Authenticator</label>
            <input id="code-disable" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
                   class="w-48 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3 py-2 text-center text-2xl font-mono tracking-[0.5em] focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
            <button type="submit" class="mt-4 block w-full rounded-xl bg-rose-600 hover:bg-rose-700 text-white px-4 py-2.5 text-sm font-bold">Nonaktifkan 2FA</button>
        </form>
    <?php else: ?>
        <div class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-6 mb-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-4">Langkah 1: Pindai QR Code</h2>
            <div class="flex flex-col sm:flex-row gap-6 items-center">
                <div class="shrink-0">
                    <img src="<?= e($qrUrl) ?>" alt="QR Code 2FA" width="240" height="240" class="rounded-xl border border-slate-200 dark:border-slate-700">
                </div>
                <div class="flex-1">
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">Buka aplikasi authenticator → tambah akun baru → pindai QR di kiri. Atau input manual:</p>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Secret key (Base32)</label>
                    <code class="block break-all bg-slate-50 dark:bg-slate-700 dark:text-slate-200 rounded-lg p-3 font-mono text-xs"><?= e($userRow['totp_secret']) ?></code>
                </div>
            </div>
        </div>

        <form method="post" class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 p-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="enable">
            <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100 mb-3">Langkah 2: Verifikasi</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Masukkan kode 6-digit dari aplikasi authenticator untuk mengaktifkan 2FA.</p>
            <label for="code-enable" class="block text-sm font-semibold text-slate-600 dark:text-slate-300 mb-1">Kode 6-digit</label>
            <input id="code-enable" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
                   class="w-48 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3 py-2 text-center text-2xl font-mono tracking-[0.5em] focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
            <button type="submit" class="mt-4 block w-full rounded-xl bg-teal-600 hover:bg-teal-700 text-white px-4 py-2.5 text-sm font-bold">Aktifkan 2FA</button>
        </form>
    <?php endif; ?>

    <div class="mt-6">
        <a href="dashboard.php" class="text-sm font-semibold text-teal-600 hover:text-teal-700">← Kembali ke Dashboard</a>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
