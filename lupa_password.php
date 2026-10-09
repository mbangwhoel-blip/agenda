<?php
declare(strict_types=1);
require_once __DIR__ . '/app/helpers.php';

/**
 * Reset password berbasis token.
 *
 * Flow:
 *  1. User input username/email → sistem generate token, simpan ke DB (expiry 30 menit),
 *     kirim email berisi link ?token=XXX.
 *  2. User klik link → input password baru → verifikasi token → update password.
 *
 * Tidak ada lagi reset password tanpa verifikasi identitas (bug lama).
 */

$flash = getFlash();
$error = '';
$step = 'request'; // 'request' atau 'reset'
$tokenFromUrl = trim($_GET['token'] ?? '');

// Auto-create table password_resets jika belum ada.
try {
    $pdo = getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_table VARCHAR(20) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        username VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token_hash),
        INDEX idx_user (user_table, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('[lupa_password.php] Gagal memastikan tabel password_resets: ' . $e->getMessage());
}

// Validasi token dari URL → tampilkan form reset.
if ($tokenFromUrl !== '') {
    try {
        $tokenHash = hash('sha256', $tokenFromUrl);
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = :th AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
        $stmt->execute(['th' => $tokenHash]);
        $resetRow = $stmt->fetch();
        if ($resetRow) {
            $step = 'reset';
        } else {
            $error = 'Token tidak valid atau sudah kedaluwarsa. Silakan minta token baru.';
            $step = 'request';
        }
    } catch (Throwable $e) {
        $error = 'Terjadi kesalahan sistem. Coba lagi nanti.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } elseif (($action = $_POST['action'] ?? '') === 'request') {
        // STEP 1: minta token baru.
        $identifier = trim($_POST['identifier'] ?? ''); // username atau email
        if ($identifier === '') {
            $error = 'Masukkan username atau email Anda.';
        } else {
            try {
                // Cari di tabel users lalu employees.
                $stmtU = $pdo->prepare("SELECT id, username, email FROM users WHERE username = :id OR email = :id LIMIT 1");
                $stmtU->execute(['id' => $identifier]);
                $userRow = $stmtU->fetch();
                $table = 'users';
                if (!$userRow) {
                    $stmtE = $pdo->prepare("SELECT id, username, email FROM employees WHERE username = :id OR email = :id LIMIT 1");
                    $stmtE->execute(['id' => $identifier]);
                    $userRow = $stmtE->fetch();
                    $table = 'employees';
                }
                if ($userRow && !empty($userRow['email'])) {
                    // Generate token 32 byte random → 64 hex chars.
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $expiresAt = date('Y-m-d H:i:s', time() + 30 * 60); // 30 menit

                    // Hapus token lama untuk user ini (single-active-token).
                    $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_table = :t AND user_id = :uid");
                    $delStmt->execute(['t' => $table, 'uid' => $userRow['id']]);

                    $insStmt = $pdo->prepare("INSERT INTO password_resets (user_table, user_id, username, email, token_hash, expires_at) VALUES (:t, :uid, :u, :e, :th, :ea)");
                    $insStmt->execute([
                        't' => $table, 'uid' => $userRow['id'],
                        'u' => $userRow['username'], 'e' => $userRow['email'],
                        'th' => $tokenHash, 'ea' => $expiresAt,
                    ]);

                    // Kirim email berisi link reset.
                    $resetLink = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' ? 'https' : 'http')
                        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/lupa_password.php?token=' . $token;
                    $subject = 'Reset Password - Agenda Pimpinan BBLKL';
                    $html = '<div style="font-family:Plus Jakarta Sans,Arial,sans-serif;max-width:560px;margin:auto;padding:24px">'
                        . '<h2 style="color:#0f766e">Reset Password</h2>'
                        . '<p>Halo <strong>' . e($userRow['username']) . '</strong>,</p>'
                        . '<p>Kami menerima permintaan reset password akun Anda. Klik tombol di bawah untuk mengatur password baru. Token berlaku 30 menit.</p>'
                        . '<p style="margin:24px 0"><a href="' . e($resetLink) . '" style="background:#0f766e;color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:700">Reset Password</a></p>'
                        . '<p style="font-size:12px;color:#64748b">Jika Anda tidak meminta reset password, abaikan email ini. Jangan bagikan link ini ke siapa pun.</p>'
                        . '<hr style="border:0;border-top:1px solid #e2e8f0;margin:24px 0">'
                        . '<p style="font-size:11px;color:#94a3b8">Email ini dikirim otomatis oleh sistem Agenda Pimpinan BBLKL.</p>'
                        . '</div>';
                    smtpSendMail($userRow['email'], $subject, $html);

                    // Anti-enumeration: selalu tampilkan pesan sukses yang sama meskipun email tidak ditemukan.
                    setFlash('success', 'Jika akun terdaftar dengan email valid, instruksi reset sudah dikirim. Periksa email Anda (termasuk folder spam).');
                    redirect('lupa_password.php');
                } else {
                    // Anti-enumeration: tetap tampilkan sukses (jangan bocorkan apakah username/email ada).
                    setFlash('success', 'Jika akun terdaftar dengan email valid, instruksi reset sudah dikirim. Periksa email Anda (termasuk folder spam).');
                    redirect('lupa_password.php');
                }
            } catch (Throwable $e) {
                error_log('[lupa_password.php] Request gagal: ' . $e->getMessage());
                $error = 'Terjadi kesalahan sistem. Coba lagi nanti.';
            }
        }
    } elseif ($action === 'reset') {
        // STEP 2: reset password dengan token.
        $token = trim($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($token === '' || $password === '' || $confirm === '') {
            $error = 'Semua field wajib diisi.';
        } elseif ($password !== $confirm) {
            $error = 'Password baru dan konfirmasi tidak cocok.';
        } else {
            $pwCheck = validatePassword($password);
            if (!$pwCheck['valid']) {
                $error = implode(' ', $pwCheck['errors']);
            } elseif (isPasswordBreached($password)) {
                $error = 'Password ini pernah ditemukan di database kebocoran data. Pilih password lain.';
            } else {
            try {
                $tokenHash = hash('sha256', $token);
                $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = :th AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
                $stmt->execute(['th' => $tokenHash]);
                $resetRow = $stmt->fetch();
                if (!$resetRow) {
                    $error = 'Token tidak valid atau sudah kedaluwarsa.';
                    $step = 'request';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($resetRow['user_table'] === 'users') {
                        $upd = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                    } else {
                        $upd = $pdo->prepare('UPDATE employees SET password_hash = :hash WHERE id = :id');
                    }
                    $upd->execute(['hash' => $hash, 'id' => $resetRow['user_id']]);

                    // Tandai token sebagai sudah dipakai (single-use).
                    $markStmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id");
                    $markStmt->execute(['id' => $resetRow['id']]);

                    logActivity('reset_password', "User {$resetRow['username']} reset password via token email.");
                    setFlash('success', 'Password berhasil diubah. Silakan login dengan password baru.');
                    redirect('login.php');
                }
            } catch (Throwable $e) {
                error_log('[lupa_password.php] Reset gagal: ' . $e->getMessage());
                $error = 'Terjadi kesalahan sistem. Coba lagi nanti.';
            }
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - Agenda Pimpinan</title>
    <link rel="icon" href="assets/logo.png?v=2.2.1" type="image/png" sizes="32x32">
    <link rel="stylesheet" href="assets/css/kemenkes.css?v=1">
    <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/fonts.css?v=2.2.1">
    <link rel="stylesheet" href="assets/ui.css?v=1">
    <script defer src="assets/js/sweetalert2.all.min.js"></script>
    <style>body{font-family:"Plus Jakarta Sans",ui-sans-serif,system-ui}</style>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-3xl shadow-xl overflow-hidden p-8">
        <?php if ($step === 'reset' && isset($resetRow)): ?>
        <h1 class="text-2xl font-bold text-slate-900 text-center">Atur Password Baru</h1>
        <p class="mt-2 text-sm text-slate-500 text-center">Masukkan password baru untuk akun <strong><?= e($resetRow['username']) ?></strong>.</p>

        <form method="post" class="mt-6 space-y-4" autocomplete="on">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= e($tokenFromUrl) ?>">
            <div>
                <label for="reset-password" class="mb-1 block text-sm font-semibold text-slate-600">Password Baru</label>
                <input id="reset-password" type="password" name="password" autocomplete="new-password" minlength="8" required
                       class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                <p class="mt-1 text-xs text-slate-400">Minimal 8 karakter.</p>
            </div>
            <div>
                <label for="reset-confirm" class="mb-1 block text-sm font-semibold text-slate-600">Konfirmasi Password Baru</label>
                <input id="reset-confirm" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required
                       class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
            </div>
            <button type="submit" class="w-full rounded-xl bg-teal-600 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-700 mt-2">Simpan Password Baru</button>
        </form>
        <?php else: ?>
        <h1 class="text-2xl font-bold text-slate-900 text-center">Lupa Password</h1>
        <p class="mt-2 text-sm text-slate-500 text-center">Masukkan username atau email Anda. Instruksi reset akan dikirim ke email terdaftar.</p>

        <form method="post" class="mt-6 space-y-4" autocomplete="on">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="request">
            <div>
                <label for="req-identifier" class="mb-1 block text-sm font-semibold text-slate-600">Username atau Email</label>
                <input id="req-identifier" type="text" name="identifier" autocomplete="username" required
                       class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
            </div>
            <button type="submit" class="w-full rounded-xl bg-teal-600 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-700 mt-2">Kirim Instruksi Reset</button>
        </form>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <a href="login.php" class="text-xs font-semibold text-teal-600 hover:text-teal-700">Kembali ke halaman login</a>
        </div>
    </div>
    <?php
    $toastMessage = '';
    $toastIcon = '';
    if ($flash) {
        $toastMessage = $flash['message'];
        $toastIcon = $flash['type'] === 'success' ? 'success' : 'error';
    } elseif ($error !== '') {
        $toastMessage = $error;
        $toastIcon = 'error';
    }
    ?>
    <?php if ($toastMessage !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 4000, timerProgressBar: true,
                    didOpen: (toast) => { toast.onmouseenter = Swal.stopTimer; toast.onmouseleave = Swal.resumeTimer; }
                });
                Toast.fire({ icon: '<?= $toastIcon ?>', title: '<?= e($toastMessage) ?>' });
            });
        </script>
    <?php endif; ?>
</body>
</html>
