<?php
/**
 * Alat Diagnostik & Pemulihan Login Online
 * Agenda Pimpinan - SKMBBLKL
 *
 * Unggah file ini ke folder web hosting Anda (misal: public_html/agendakepala/cek_login.php)
 * Lalu buka di browser: https://skmbblkl.my.id/cek_login.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/config/database.php';

$actionMsg = '';
$actionType = '';

// Proses Aksi Pemulihan jika ada
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_lockout') {
        try {
            $pdo = getPdo();
            $pdo->exec("TRUNCATE TABLE login_attempts");
            $actionType = 'success';
            $actionMsg = 'Semua pemblokiran percobaan login (login_attempts) berhasil dihapus! Silakan coba login kembali.';
        } catch (Throwable $e) {
            $actionType = 'error';
            $actionMsg = 'Gagal menghapus login_attempts: ' . $e->getMessage();
        }
    } elseif ($action === 'reset_password') {
        $targetUser = trim($_POST['username'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        if ($targetUser !== '' && $newPass !== '') {
            try {
                $pdo = getPdo();
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE username = :u");
                $stmt->execute(['hash' => $hash, 'u' => $targetUser]);
                if ($stmt->rowCount() > 0) {
                    $actionType = 'success';
                    $actionMsg = "Password user '{$targetUser}' berhasil diubah! Silakan login sekarang.";
                } else {
                    $actionType = 'warning';
                    $actionMsg = "User '{$targetUser}' tidak ditemukan di tabel users.";
                }
            } catch (Throwable $e) {
                $actionType = 'error';
                $actionMsg = 'Gagal reset password: ' . $e->getMessage();
            }
        }
    }
}

// 1. Diagnostik Database
$dbConnected = false;
$dbError = '';
$tables = [];
$usersList = [];
$attempts = [];

try {
    $pdo = getPdo();
    $dbConnected = true;

    // Cek tabel-tabel utama
    $stmtTables = $pdo->query("SHOW TABLES");
    $allTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('users', $allTables, true)) {
        $stmtUsers = $pdo->query("SELECT id, username, full_name, role, totp_enabled FROM users ORDER BY id ASC");
        $usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    }

    if (in_array('login_attempts', $allTables, true)) {
        $stmtAtt = $pdo->query("SELECT id, username, ip_address, success, created_at FROM login_attempts ORDER BY created_at DESC LIMIT 20");
        $attempts = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$clientIp = getClientIp();
$isSessionWorking = (session_status() === PHP_SESSION_ACTIVE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostik Login Hosting - Agenda Pimpinan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-800 p-4 md:p-8">
    <div class="max-w-4xl mx-auto space-y-6">

        <!-- Header -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">Alat Diagnostik & Pemulihan Login</h1>
                <p class="text-sm text-slate-500 mt-1">Periksa status server hosting, koneksi database, dan pulihkan akun login.</p>
            </div>
            <a href="login.php" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold rounded-xl shadow-sm transition">
                Ke Halaman Login &rarr;
            </a>
        </div>

        <?php if ($actionMsg): ?>
            <div class="p-4 rounded-2xl border <?= $actionType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?>">
                <p class="font-bold"><?= htmlspecialchars($actionMsg) ?></p>
            </div>
        <?php endif; ?>

        <!-- 1. Status Koneksi Database -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2 mb-4">
                <span>1. Status Koneksi Database</span>
                <?php if ($dbConnected): ?>
                    <span class="px-3 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full">TERHUBUNG (OK)</span>
                <?php else: ?>
                    <span class="px-3 py-1 bg-rose-100 text-rose-800 text-xs font-bold rounded-full">GAGAL KONEKSI</span>
                <?php endif; ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mb-4">
                <div class="bg-slate-50 p-4 rounded-xl space-y-1">
                    <p class="text-slate-500">Host Database: <strong class="text-slate-800"><?= htmlspecialchars(db_host()) ?></strong></p>
                    <p class="text-slate-500">Port Database: <strong class="text-slate-800"><?= htmlspecialchars(db_port()) ?></strong></p>
                    <p class="text-slate-500">Nama Database: <strong class="text-slate-800"><?= htmlspecialchars(db_name()) ?></strong></p>
                </div>
                <div class="bg-slate-50 p-4 rounded-xl space-y-1">
                    <p class="text-slate-500">User Database: <strong class="text-slate-800"><?= htmlspecialchars(db_user()) ?></strong></p>
                    <p class="text-slate-500">Password Database: <strong class="text-slate-800"><?= db_pass() !== '' ? 'Terisi (Panjang: ' . strlen(db_pass()) . ' karakter)' : '<span class="text-rose-600 font-bold">KOSONG (Perlu diisi di hosting!)</span>' ?></strong></p>
                    <p class="text-slate-500">Sumber Konfigurasi: <strong><?= is_file(__DIR__ . '/config/db_credentials.php') ? 'config/db_credentials.php (Direkomendasikan)' : (is_file(__DIR__ . '/.env') ? '.env file' : 'config/database.php default') ?></strong></p>
                </div>
            </div>

            <?php if (!$dbConnected): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl text-sm space-y-2">
                    <p class="font-bold">Pesan Error dari Database:</p>
                    <pre class="bg-white p-3 rounded border border-rose-200 text-xs overflow-x-auto"><?= htmlspecialchars($dbError) ?></pre>
                    <p class="font-semibold text-xs mt-2">Solusi Perbaikan:</p>
                    <ol class="list-decimal list-inside text-xs space-y-1">
                        <li>Buat file <code class="bg-rose-100 px-1 py-0.5 rounded">config/db_credentials.php</code> di hosting Anda.</li>
                        <li>Masukkan nama database, username cPanel, dan password cPanel yang benar.</li>
                        <li>Pastikan user cPanel sudah diberi hak akses (All Privileges) ke database tersebut di menu MySQL Databases cPanel.</li>
                    </ol>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. Status Sesi & IP Klien -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-lg font-bold text-slate-900 mb-4">2. Status Sesi & Jaringan Pengunjung</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="bg-slate-50 p-4 rounded-xl space-y-1">
                    <p class="text-slate-500">IP Anda Saat Ini: <strong class="text-teal-700"><?= htmlspecialchars($clientIp) ?></strong></p>
                    <p class="text-slate-500">Status HTTPS: <strong><?= !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'Ya (Aman)' : 'Tidak / Reverse Proxy' ?></strong></p>
                </div>
                <div class="bg-slate-50 p-4 rounded-xl space-y-1">
                    <p class="text-slate-500">Status Session PHP: <strong class="text-emerald-700"><?= $isSessionWorking ? 'Aktif (Normal)' : 'Belum Aktif' ?></strong></p>
                    <p class="text-slate-500">Session Save Path: <strong class="text-xs"><?= htmlspecialchars(session_save_path() ?: 'Default Sistem') ?></strong></p>
                </div>
            </div>
        </div>

        <?php if ($dbConnected): ?>
            <!-- 3. Daftar Akun Pengguna -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">3. Daftar Akun di Database</h2>
                <?php if (empty($usersList)): ?>
                    <p class="text-sm text-slate-500">Belum ada akun di tabel users. Anda dapat mengimpor database melalui menu Backup/Restore atau phpMyAdmin.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-100 text-slate-700 text-xs font-bold uppercase">
                                <tr>
                                    <th class="p-3">ID</th>
                                    <th class="p-3">Username</th>
                                    <th class="p-3">Nama Lengkap</th>
                                    <th class="p-3">Role</th>
                                    <th class="p-3">2FA</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($usersList as $u): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3"><?= (int)$u['id'] ?></td>
                                        <td class="p-3 font-bold text-teal-700"><?= htmlspecialchars($u['username']) ?></td>
                                        <td class="p-3"><?= htmlspecialchars($u['full_name'] ?? '-') ?></td>
                                        <td class="p-3">
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $u['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : ($u['role'] === 'sekretaris' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700') ?>">
                                                <?= htmlspecialchars($u['role']) ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-xs"><?= (int)($u['totp_enabled'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Form Reset Password Darurat -->
                <div class="mt-6 pt-6 border-t border-slate-100">
                    <h3 class="text-sm font-bold text-slate-800 mb-2">Reset Password Akun (Jika Lupa Password)</h3>
                    <form method="POST" class="flex flex-wrap items-center gap-3">
                        <input type="hidden" name="action" value="reset_password">
                        <select name="username" required class="px-3 py-2 border rounded-xl text-sm bg-white focus:ring-2 focus:ring-teal-500">
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?= htmlspecialchars($u['username']) ?>"><?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="new_password" placeholder="Password baru" required minlength="6" class="px-3 py-2 border rounded-xl text-sm focus:ring-2 focus:ring-teal-500">
                        <button type="submit" onclick="return confirm('Yakin ingin mengganti password user ini?')" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-xl transition">
                            Ubah Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- 4. Riwayat Blokir Brute-Force & Tombol Buka Kunci -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-slate-900">4. Blokir Percobaan Login (login_attempts)</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="clear_lockout">
                        <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl transition">
                            Bersihkan Semua Blokir (Reset)
                        </button>
                    </form>
                </div>

                <?php if (empty($attempts)): ?>
                    <p class="text-sm text-emerald-700 font-medium">Tidak ada IP atau username yang sedang terblokir.</p>
                <?php else: ?>
                    <div class="overflow-x-auto max-h-60 overflow-y-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-100 text-slate-700 uppercase">
                                <tr>
                                    <th class="p-2">Waktu</th>
                                    <th class="p-2">Username</th>
                                    <th class="p-2">IP Address</th>
                                    <th class="p-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($attempts as $a): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-2"><?= htmlspecialchars($a['created_at']) ?></td>
                                        <td class="p-2 font-bold"><?= htmlspecialchars($a['username']) ?></td>
                                        <td class="p-2 <?= $a['ip_address'] === $clientIp ? 'text-rose-600 font-bold' : '' ?>"><?= htmlspecialchars($a['ip_address']) ?></td>
                                        <td class="p-2"><?= (int)$a['success'] === 1 ? '<span class="text-emerald-600 font-bold">Sukses</span>' : '<span class="text-rose-600 font-bold">Gagal</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Footer Warning -->
        <div class="p-4 bg-amber-50 rounded-2xl border border-amber-200 text-xs text-amber-800">
            <strong>Catatan Keamanan:</strong> Setelah login Anda normal kembali, Anda dapat menghapus file <code class="bg-amber-100 px-1 py-0.5 rounded">cek_login.php</code> ini dari hosting demi keamanan server Anda.
        </div>

    </div>
</body>
</html>
