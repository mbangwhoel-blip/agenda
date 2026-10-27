<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$redirectTarget = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
if ($redirectTarget !== '') {
    $parsed = parse_url($redirectTarget);
    if (!empty($parsed['host']) || !empty($parsed['scheme']) || str_starts_with($redirectTarget, '//') || str_starts_with($redirectTarget, '\\')) {
        $redirectTarget = '';
    }
}

if (currentUser()) {
    if ($redirectTarget !== '') {
        redirect($redirectTarget);
    }
    redirect('dashboard.php');
}

$flash = getFlash();
$error = '';
$require2FA = false;
$pendingUsername = '';
$pendingPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } else {
            $pdo = null;
            try {
                $pdo = getPdo();
            } catch (Throwable $e) {
                error_log('[login.php] Gagal koneksi database: ' . $e->getMessage());
                $error = 'Gagal terhubung ke database. Mohon pastikan file config/db_credentials.php atau config/database.php sudah disesuaikan dengan database cPanel hosting Anda.';
            }

            if ($pdo) {
                // ============================================
                // BRUTE-FORCE THROTTLE
                // ============================================
                $ipAddr = getClientIp();
                $throttleWindow = 15 * 60; // 15 menit
                $maxPerUsername = 5;
                $maxPerIp = 10;

                try {
                    // Pastikan tabel login_attempts ada (auto-create).
                    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        username VARCHAR(100) NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        success TINYINT(1) NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_username_time (username, created_at),
                        INDEX idx_ip_time (ip_address, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    // Bersihkan record lama (> 24 jam)
                    $pdo->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 24 HOUR)");

                    $stmtU = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = :u AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
                    $stmtU->execute(['u' => $username]);
                    $failUser = (int)$stmtU->fetchColumn();

                    $stmtI = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
                    $stmtI->execute(['ip' => $ipAddr]);
                    $failIp = (int)$stmtI->fetchColumn();

                    if ($failUser >= $maxPerUsername || $failIp >= $maxPerIp) {
                        $error = 'Terlalu banyak percobaan login gagal. Silakan tunggu 15 menit sebelum mencoba kembali.';
                    }
                } catch (Throwable $e) {
                    error_log('[login.php] Throttle check gagal: ' . $e->getMessage());
                }

                if ($error === '') {
                    $stmt = $pdo->prepare('SELECT id, full_name, username, password_hash, role, totp_secret, totp_enabled FROM users WHERE username = :username LIMIT 1');
                    $stmt->execute(['username' => $username]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Bersihkan percobaan gagal untuk username & IP ini setelah password benar.
                        try {
                            $cleanStmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = :u OR ip_address = :ip");
                            $cleanStmt->execute(['u' => $username, 'ip' => $ipAddr]);
                        } catch (Throwable $e) {}

                        // ── 2FA GATE ── Jika totp_enabled, minta kode TOTP sebelum set session.
                        if ((int)($user['totp_enabled'] ?? 0) === 1 && !empty($user['totp_secret'])) {
                            $totpCode = trim($_POST['totp_code'] ?? '');
                            $totpValid = false;
                            if ($totpCode !== '') {
                                $totpValid = verifyTotp($user['totp_secret'], $totpCode);
                                // Cek backup codes juga.
                                if (!$totpValid) {
                                    $backupJson = $user['totp_backup_codes'] ?? '[]';
                                    $backupHashes = json_decode($backupJson, true) ?: [];
                                    foreach ($backupHashes as $i => $hash) {
                                        if (password_verify($totpCode, $hash)) {
                                            $totpValid = true;
                                            unset($backupHashes[$i]);
                                            $upd = $pdo->prepare('UPDATE users SET totp_backup_codes = :bc WHERE id = :id');
                                            $upd->execute(['bc' => json_encode(array_values($backupHashes)), 'id' => $user['id']]);
                                            auditLogImmutable('2fa_backup_used', "User {$user['username']} memakai backup code untuk login");
                                            break;
                                        }
                                    }
                                }
                            }
                            if (!$totpValid) {
                                $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                                $error = 'Masukkan kode 2FA dari aplikasi authenticator Anda.';
                                $require2FA = true;
                                $pendingUsername = $username;
                                $pendingPassword = $password;
                            } else {
                                auditLogImmutable('2fa_login_success', "User {$user['username']} login dengan 2FA");
                            }
                        }

                        if (!$require2FA) {
                            $sessionData = [
                                'id' => (int) $user['id'],
                                'full_name' => $user['full_name'],
                                'username' => $user['username'],
                                'role' => $user['role'],
                            ];

                            // Sinkronisasi ID pegawai jika login via tabel users sebagai pegawai
                            if ($user['role'] === 'pegawai') {
                                $stmtEmpSync = $pdo->prepare('SELECT id FROM employees WHERE username = :username LIMIT 1');
                                $stmtEmpSync->execute(['username' => $user['username']]);
                                $empSync = $stmtEmpSync->fetch();
                                if ($empSync) {
                                    $sessionData['employee_id'] = (int) $empSync['id'];
                                }
                            }

                            session_regenerate_id(true);
                            $_SESSION['user'] = $sessionData;
                            logActivity('login', "Pengguna {$user['username']} berhasil login.");
                            if ($redirectTarget !== '') {
                                redirect($redirectTarget);
                            }
                            if (($user['role'] ?? '') === 'pimpinan') {
                                redirect('disposisi.php');
                            }
                            redirect('dashboard.php');
                        }
                    } else {
                        $stmtEmp = $pdo->prepare('SELECT id, full_name, username, password_hash, is_active FROM employees WHERE username = :username LIMIT 1');
                        $stmtEmp->execute(['username' => $username]);
                        $emp = $stmtEmp->fetch();

                        if ($emp && password_verify($password, $emp['password_hash'])) {
                            if ((int)$emp['is_active'] !== 1) {
                                $error = 'Akun pegawai Anda tidak aktif.';
                            } else {
                                try {
                                    $cleanStmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = :u OR ip_address = :ip");
                                    $cleanStmt->execute(['u' => $username, 'ip' => $ipAddr]);
                                } catch (Throwable $e) {}

                                session_regenerate_id(true);
                                $_SESSION['user'] = [
                                    'id' => (int) $emp['id'],
                                    'full_name' => $emp['full_name'],
                                    'username' => $emp['username'],
                                    'role' => 'pegawai',
                                    'employee_id' => (int) $emp['id']
                                ];
                                logActivity('login', "Pegawai {$emp['username']} berhasil login.");
                                if ($redirectTarget !== '') {
                                    redirect($redirectTarget);
                                }
                                redirect('dashboard.php');
                            }
                        } else {
                            try {
                                $logStmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, 0)");
                                $logStmt->execute(['u' => $username, 'ip' => $ipAddr]);
                            } catch (Throwable $e) {}
                            $error = 'Username atau password salah.';
                        }
                    }
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
    <title>Login - Agenda Pimpinan</title>
    <link rel="icon" href="assets/logo.png?v=2.2.1" type="image/png" sizes="32x32">
    <link rel="stylesheet" href="assets/css/kemenkes.css?v=2.1.0">
    <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/fonts.css?v=2.2.1">
    <link rel="stylesheet" href="assets/ui.css?v=2.1.0">
    <script defer src="assets/js/alpine.min.js"></script>
    <script defer src="assets/js/sweetalert2.all.min.js"></script>
    <style>body{font-family:"Plus Jakarta Sans",ui-sans-serif,system-ui}</style>
</head>
<body class="auth-shell min-h-screen bg-slate-100">
    <a href="#login-form" class="skip-link">Lewati ke form login</a>
    <div class="mx-auto flex min-h-screen max-w-5xl items-center px-4 py-10 sm:px-6">
        <div class="grid w-full overflow-hidden rounded-3xl bg-white shadow-2xl lg:grid-cols-2">
            <div class="bg-gradient-to-br from-teal-600 to-cyan-500 p-8 text-white flex flex-col items-center justify-center text-center">
                <div class="flex justify-center w-full mb-8">
                    <a href="index.php" aria-label="Kembali ke Beranda" class="group inline-block rounded-2xl bg-white/10 p-2 sm:p-2.5 backdrop-blur-sm border border-white/20 hover:bg-white/20 hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                        <div class="flex items-center justify-center bg-white rounded-xl p-2 sm:p-3 shadow-sm overflow-hidden">
                            <img src="assets/logo.png" alt="Logo BBLKL" width="48" height="48" class="h-12 sm:h-16 w-auto object-contain scale-110 group-hover:scale-[1.15] transition-transform duration-300">
                        </div>
                    </a>
                </div>
                <p class="text-[11px] font-bold uppercase tracking-[0.15em] text-cyan-100/90 mb-5">Balai Besar Laboratorium Kesehatan Lingkungan</p>
                <h1 class="text-2xl min-[400px]:text-3xl lg:text-[34px] xl:text-4xl font-extrabold leading-tight tracking-tighter">Sistem Agenda Pimpinan</h1>
                <p class="mt-3 text-sm text-cyan-50">Kelola agenda masuk dan keluar secara rapi, aman, dan siap laporan.</p>
            </div>
            <div class="p-8">
                <h2 class="text-2xl font-bold text-slate-900">Masuk ke Sistem</h2>
                <p class="mt-1 text-sm text-slate-500">Silakan login dengan akun Anda.</p>

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

                <form id="login-form" method="post" class="mt-6 space-y-4" autocomplete="on" x-data="{ loading: false }" @submit="loading = true">
                    <?= csrf_field(); ?>
                    <?php if ($redirectTarget !== ''): ?>
                        <input type="hidden" name="redirect" value="<?= e($redirectTarget); ?>">
                    <?php endif; ?>
                    <div>
                        <label for="login-username" class="mb-1 block text-sm font-semibold text-slate-600">Username</label>
                        <input id="login-username" type="text" name="username" autocomplete="username" required
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </div>
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label for="login-password" class="block text-sm font-semibold text-slate-600">Password</label>
                            <a href="lupa_password.php" class="text-xs font-semibold text-teal-600 hover:text-teal-700">Lupa Password?</a>
                        </div>
                        <div class="relative">
                            <input id="login-password" type="password" name="password" autocomplete="current-password" required
                                   class="w-full rounded-xl border border-slate-200 px-3 py-2 pr-11 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            <button type="button" id="toggle-password" class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-slate-500 hover:text-slate-700" aria-label="Tampilkan password">
                                <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="m3 3 18 18"></path>
                                    <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                                    <path d="M9.36 5.37A9.8 9.8 0 0 1 12 5c7 0 11 7 11 7a13.17 13.17 0 0 1-1.67 2.68"></path>
                                    <path d="M6.61 6.61A13.53 13.53 0 0 0 1 12s4 7 11 7a9.74 9.74 0 0 0 5.39-1.61"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <?php if ($require2FA): ?>
                    <div class="rounded-xl border border-teal-200 bg-teal-50 dark:bg-teal-900/20 dark:border-teal-800 p-4">
                        <label for="login-totp" class="mb-1 block text-sm font-semibold text-teal-700 dark:text-teal-300">Kode 2FA (6-digit)</label>
                        <p class="text-xs text-teal-600 dark:text-teal-400 mb-2">Buka aplikasi authenticator Anda dan masukkan kode 6-digit, atau gunakan kode cadangan.</p>
                        <input id="login-totp" type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6,8}" maxlength="8" required autofocus
                               class="w-full rounded-xl border border-teal-300 dark:border-teal-700 dark:bg-slate-800 dark:text-slate-200 px-3 py-2 text-center text-2xl font-mono tracking-[0.4em] focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </div>
                    <?php endif; ?>
                    <button type="submit" :disabled="loading" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-teal-600 text-white px-3 py-2 text-sm font-semibold hover:bg-teal-700 disabled:opacity-60 disabled:cursor-not-allowed transition">
                        <svg x-show="loading" x-cloak class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="loading ? 'Memproses...' : 'Login'">Login</span>
                    </button>
                </form>
                <a href="index.php" class="mt-4 flex items-center justify-center w-full gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700 bg-slate-50 hover:bg-slate-100 px-3 py-2 rounded-xl border border-slate-200 transition-all duration-200 shadow-sm">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Kembali ke Beranda
                </a>
                
                <div class="mt-6 text-center border-t border-slate-200 pt-4">
                    <p class="text-sm text-slate-600">Belum punya akun?
                        <button type="button" id="btn-contact-admin" class="font-semibold text-teal-600 hover:text-teal-700">Hubungi Admin</button>
                    </p>
                    <p class="mt-1 text-xs text-slate-400">Pembuatan akun dilakukan oleh administrator sistem.</p>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var input = document.getElementById('login-password');
            var toggle = document.getElementById('toggle-password');
            var eye = document.getElementById('icon-eye');
            var eyeOff = document.getElementById('icon-eye-off');
            if (!input || !toggle || !eye || !eyeOff) return;

            toggle.addEventListener('click', function () {
                var isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                if (isHidden) {
                    eye.classList.add('hidden');
                    eyeOff.classList.remove('hidden');
                } else {
                    eye.classList.remove('hidden');
                    eyeOff.classList.add('hidden');
                }
            });

            var btnAdmin = document.getElementById('btn-contact-admin');
            if (btnAdmin) {
                btnAdmin.addEventListener('click', function () {
                    Swal.fire({
                        icon: 'info',
                        title: 'Hubungi Administrator',
                        html: '<p style="font-size:14px;line-height:1.5;color:#475569;">Untuk pembuatan akun baru, silakan menghubungi administrator sistem:</p>' +
                              '<div style="margin-top:14px;padding:12px;border-radius:10px;background:#f0fdfa;border:1px solid #99f6e4;text-align:left;">' +
                              '<p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#0f766e;">Administrator Sistem Agenda Pimpinan</p>' +
                              '<p style="margin:0;font-size:13px;color:#0f766e;">Email: bblkl.salatiga@gmail.com</p>' +
                              '</div>' +
                              '<p style="margin-top:12px;font-size:12px;color:#94a3b8;">Permintaan akan diproses setelah verifikasi oleh admin.</p>',
                        confirmButtonText: 'Tutup',
                        confirmButtonColor: '#0d9488'
                    });
                });
            }
        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($toastMessage !== ''): ?>
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 4000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.onmouseenter = Swal.stopTimer;
                        toast.onmouseleave = Swal.resumeTimer;
                    }
                });
                Toast.fire({
                    icon: '<?= $toastIcon ?>',
                    title: '<?= e($toastMessage) ?>'
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>
