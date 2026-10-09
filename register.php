<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

if (currentUser()) {
    redirect('dashboard.php');
}

$flash = getFlash();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $username === '' || $email === '' || $whatsapp === '' || $password === '') {
            $error = 'Semua kolom wajib diisi.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password dan konfirmasi password tidak cocok.';
        } else {
            $pwCheck = validatePassword($password);
            if (!$pwCheck['valid']) {
                $error = implode(' ', $pwCheck['errors']);
            } elseif (isPasswordBreached($password)) {
                $error = 'Password ini pernah ditemukan di database kebocoran data. Pilih password lain.';
            } else {
            $pdo = getPdo();

            // ── Auto-create tabel antrian pendaftaran (pending approval admin) ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_registrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(120) NOT NULL,
                username VARCHAR(50) NOT NULL,
                email VARCHAR(180) NULL,
                whatsapp_number VARCHAR(30) NULL,
                password_hash VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                INDEX idx_status (status),
                INDEX idx_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Cek apakah username sudah ada di tabel users (aktif)
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $stmt->execute(['username' => $username]);
            if ($stmt->fetch()) {
                $error = 'Username sudah digunakan, silakan pilih yang lain.';
            } else {
                // Cek di tabel employees juga
                $stmtEmp = $pdo->prepare('SELECT id FROM employees WHERE username = :username LIMIT 1');
                $stmtEmp->execute(['username' => $username]);
                if ($stmtEmp->fetch()) {
                    $error = 'Username sudah digunakan oleh pegawai lain.';
                } else {
                    // Cek antrian pendaftaran pending (belum ditolak)
                    $stmtPending = $pdo->prepare("SELECT id FROM user_registrations WHERE username = :username AND status = 'pending' LIMIT 1");
                    $stmtPending->execute(['username' => $username]);
                    if ($stmtPending->fetch()) {
                        $error = 'Pendaftaran dengan username ini sedang menunggu persetujuan admin.';
                    } else {
                        // Simpan ke antrian pendaftaran (status pending), belum aktif.
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmtInsert = $pdo->prepare('INSERT INTO user_registrations (full_name, username, email, whatsapp_number, password_hash, status) VALUES (:full_name, :username, :email, :whatsapp_number, :password_hash, :pending)');

                        try {
                            $stmtInsert->execute([
                                'full_name' => $fullName,
                                'username' => $username,
                                'email' => $email,
                                'whatsapp_number' => $whatsapp,
                                'password_hash' => $hash,
                                'pending' => 'pending',
                            ]);

                            setFlash('success', 'Pendaftaran terkirim. Akun akan aktif setelah disetujui administrator.');
                            logActivity('register_pending', "Pegawai {$username} mendaftar, menunggu persetujuan admin.");
                            redirect('login.php');
                        } catch (Exception $e) {
                            $error = 'Terjadi kesalahan sistem saat mendaftar.';
                        }
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
    <title>Daftar Akun Pegawai - Agenda Pimpinan</title>
    <link rel="icon" href="assets/logo.png?v=2.2.1" type="image/png" sizes="32x32">
    <link rel="stylesheet" href="assets/css/kemenkes.css?v=2.1.0">
    <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/fonts.css?v=2.2.1">
    <link rel="stylesheet" href="assets/ui.css?v=2.1.0">
    <script defer src="assets/js/sweetalert2.all.min.js"></script>
    <style>body{font-family:"Plus Jakarta Sans",ui-sans-serif,system-ui}</style>
</head>
<body class="auth-shell min-h-screen bg-slate-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="flex justify-center">
            <a href="index.php" class="group inline-block rounded-2xl bg-white p-3 sm:p-4 shadow-sm border border-slate-200 hover:-translate-y-1 hover:shadow-md transition-all duration-300">
                <img src="assets/logo.png" alt="Logo" class="h-10 sm:h-12 w-auto object-contain">
            </a>
        </div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-slate-900">Buat Akun Pegawai</h2>
        <p class="mt-2 text-center text-sm text-slate-600">
            Daftarkan diri Anda. Akun akan aktif setelah disetujui administrator.
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white py-8 px-4 shadow-xl sm:rounded-2xl sm:px-10 border border-slate-100">
            <?php
            $toastMessage = '';
            $toastIcon = '';
            if ($error !== '') {
                $toastMessage = $error;
                $toastIcon = 'error';
            }
            ?>

            <form method="post" class="space-y-5">
                <?= csrf_field(); ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700">Nama Lengkap</label>
                    <div class="mt-1">
                        <input type="text" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 transition-colors" required placeholder="John Doe">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">Username</label>
                    <div class="mt-1">
                        <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 transition-colors" required autocomplete="username" placeholder="johndoe">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">Email</label>
                    <div class="mt-1">
                        <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 transition-colors" required placeholder="nama@email.com">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">No. WhatsApp</label>
                    <div class="mt-1">
                        <input type="text" name="whatsapp" value="<?= e($_POST['whatsapp'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 transition-colors" required placeholder="08123456789">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">Password</label>
                    <div class="mt-1 relative">
                        <input id="register-password" type="password" name="password" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 pr-11 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 transition-colors" required autocomplete="new-password" placeholder="••••••••">
                        <button type="button" id="toggle-password" class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-400 hover:text-teal-600 focus:outline-none transition-colors" aria-label="Tampilkan password">
                            <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m3 3 18 18"></path>
                                <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                                <path d="M9.36 5.37A9.8 9.8 0 0 1 12 5c7 0 11 7 11 7a13.17 13.17 0 0 1-1.67 2.68"></path>
                                <path d="M6.61 6.61A13.53 13.53 0 0 0 1 12s4 7 11 7a9.74 9.74 0 0 0 5.39-1.61"></path>
                            </svg>
                        </button>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">Minimal 8 karakter, mengandung huruf dan angka.</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700">Konfirmasi Password</label>
                    <div class="mt-1 relative">
                        <input id="register-confirm-password" type="password" name="confirm_password" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 pr-11 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 transition-colors" required autocomplete="new-password" placeholder="••••••••">
                        <button type="button" id="toggle-confirm-password" class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-400 hover:text-teal-600 focus:outline-none transition-colors" aria-label="Tampilkan password">
                            <svg id="icon-confirm-eye" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="icon-confirm-eye-off" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m3 3 18 18"></path>
                                <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                                <path d="M9.36 5.37A9.8 9.8 0 0 1 12 5c7 0 11 7 11 7a13.17 13.17 0 0 1-1.67 2.68"></path>
                                <path d="M6.61 6.61A13.53 13.53 0 0 0 1 12s4 7 11 7a9.74 9.74 0 0 0 5.39-1.61"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-xl shadow-sm text-sm font-bold text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors">
                        Daftar Akun Sekarang
                    </button>
                </div>
            </form>

            <div class="mt-8">
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-2 bg-white text-slate-500">
                            Sudah punya akun?
                        </span>
                    </div>
                </div>
                
                <div class="mt-6 text-center">
                    <a href="login.php" class="font-bold text-teal-600 hover:text-teal-500 transition-colors">
                        Masuk di sini
                    </a>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            function setupToggle(inputId, toggleId, eyeId, eyeOffId) {
                var input = document.getElementById(inputId);
                var toggle = document.getElementById(toggleId);
                var eye = document.getElementById(eyeId);
                var eyeOff = document.getElementById(eyeOffId);
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
            }

            setupToggle('register-password', 'toggle-password', 'icon-eye', 'icon-eye-off');
            setupToggle('register-confirm-password', 'toggle-confirm-password', 'icon-confirm-eye', 'icon-confirm-eye-off');
            
            <?php if ($toastMessage !== ''): ?>
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
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
        })();
    </script>
</body>
</html>
