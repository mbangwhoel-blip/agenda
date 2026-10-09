<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();

if (!canManageEmployees()) {
    setFlash('error', 'Akses master pegawai hanya untuk admin/pimpinan.');
    redirect('dashboard.php');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];

$formData = [
    'nip' => '',
    'full_name' => '',
    'email' => '',
    'whatsapp_number' => '',
    'username' => '',
    'position_title' => '',
    'unit_name' => '',
    'is_active' => 1,
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        setFlash('error', 'Data pegawai tidak ditemukan.');
        redirect('master_pegawai.php');
    }
    $formData = [
        'nip' => (string) ($row['nip'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'whatsapp_number' => (string) ($row['whatsapp_number'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'position_title' => (string) ($row['position_title'] ?? ''),
        'unit_name' => (string) ($row['unit_name'] ?? ''),
        'is_active' => (int) ($row['is_active'] ?? 1),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $formData['nip'] = trim((string) ($_POST['nip'] ?? ''));
    $formData['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $formData['email'] = trim((string) ($_POST['email'] ?? ''));
    $formData['whatsapp_number'] = trim((string) ($_POST['whatsapp_number'] ?? ''));
    $formData['username'] = trim((string) ($_POST['username'] ?? ''));
    $formData['position_title'] = trim((string) ($_POST['position_title'] ?? ''));
    $formData['unit_name'] = trim((string) ($_POST['unit_name'] ?? ''));
    $formData['is_active'] = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
    
    $password = $_POST['password'] ?? '';

    if ($formData['full_name'] === '') {
        $errors[] = 'Nama pegawai wajib diisi.';
    }
    if ($formData['email'] === '') {
        $errors[] = 'Email wajib diisi.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if ($formData['whatsapp_number'] === '') {
        $errors[] = 'No. WhatsApp wajib diisi.';
    } elseif (!preg_match('/^(\+62|62|0)8[1-9][0-9]{6,11}$/', preg_replace('/[\s\-]/', '', $formData['whatsapp_number']))) {
        $errors[] = 'Format No. WhatsApp tidak valid. Contoh: 081234567890 atau +6281234567890.';
    }
    // Password policy: required saat create baru, optional saat edit (jika kosong = tidak ubah)
    if (!$isEdit && $password === '') {
        $errors[] = 'Password wajib diisi untuk pegawai baru.';
    }
    if ($password !== '') {
        $pwCheck = validatePassword($password);
        if (!$pwCheck['valid']) {
            foreach ($pwCheck['errors'] as $pwErr) {
                $errors[] = $pwErr;
            }
        }
    }
    if ($formData['nip'] !== '') {
        $checkSql = "SELECT COUNT(*) FROM employees WHERE nip = :nip";
        $params = ['nip' => $formData['nip']];
        if ($isEdit) {
            $checkSql .= " AND id <> :id";
            $params['id'] = $id;
        }
        $check = $pdo->prepare($checkSql);
        $check->execute($params);
        if ((int) $check->fetchColumn() > 0) {
            $errors[] = 'NIP sudah digunakan pegawai lain.';
        }
    }
    if ($formData['username'] !== '') {
        $checkSql = "SELECT COUNT(*) FROM employees WHERE username = :username";
        $params = ['username' => $formData['username']];
        if ($isEdit) {
            $checkSql .= " AND id <> :id";
            $params['id'] = $id;
        }
        $check = $pdo->prepare($checkSql);
        $check->execute($params);
        if ((int) $check->fetchColumn() > 0) {
            $errors[] = 'Username sudah digunakan pegawai lain.';
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $sql = "UPDATE employees SET nip = :nip, full_name = :full_name, email = :email, whatsapp_number = :whatsapp_number, username = :username, position_title = :position_title, unit_name = :unit_name, is_active = :is_active";
            $params = [
                'nip' => $formData['nip'] !== '' ? $formData['nip'] : null,
                'full_name' => $formData['full_name'],
                'email' => $formData['email'] !== '' ? $formData['email'] : null,
                'whatsapp_number' => $formData['whatsapp_number'] !== '' ? $formData['whatsapp_number'] : null,
                'username' => $formData['username'] !== '' ? $formData['username'] : null,
                'position_title' => $formData['position_title'] !== '' ? $formData['position_title'] : null,
                'unit_name' => $formData['unit_name'] !== '' ? $formData['unit_name'] : null,
                'is_active' => $formData['is_active'],
                'id' => $id,
            ];

            if ($password !== '') {
                $sql .= ", password_hash = :password_hash";
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= " WHERE id = :id";
            
            $upd = $pdo->prepare($sql);
            $upd->execute($params);
            setFlash('success', 'Data pegawai berhasil diperbarui.');
            logActivity('update_employee', "Memperbarui data pegawai {$formData['full_name']}", 'employee', $id);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO employees (nip, full_name, email, whatsapp_number, username, password_hash, position_title, unit_name, is_active)
                 VALUES (:nip, :full_name, :email, :whatsapp_number, :username, :password_hash, :position_title, :unit_name, :is_active)"
            );
            $ins->execute([
                'nip' => $formData['nip'] !== '' ? $formData['nip'] : null,
                'full_name' => $formData['full_name'],
                'email' => $formData['email'] !== '' ? $formData['email'] : null,
                'whatsapp_number' => $formData['whatsapp_number'] !== '' ? $formData['whatsapp_number'] : null,
                'username' => $formData['username'] !== '' ? $formData['username'] : null,
                'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
                'position_title' => $formData['position_title'] !== '' ? $formData['position_title'] : null,
                'unit_name' => $formData['unit_name'] !== '' ? $formData['unit_name'] : null,
                'is_active' => $formData['is_active'],
            ]);
            setFlash('success', 'Pegawai berhasil ditambahkan.');
            $empId = (int) $pdo->lastInsertId();
            logActivity('create_employee', "Menambahkan pegawai {$formData['full_name']}", 'employee', $empId);
        }
        }
    }
}

$pageTitle = $isEdit ? 'Edit Pegawai' : 'Tambah Pegawai';
require_once __DIR__ . '/includes/header.php';
?>
<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-slate-500">
            <li><a href="dashboard.php" class="hover:text-teal-600">Dashboard</a></li>
            <li aria-hidden="true" class="text-slate-300">/</li>
            <li><a href="master_pegawai.php" class="hover:text-teal-600">Master Pegawai</a></li>
            <li aria-hidden="true" class="text-slate-300">/</li>
            <li class="text-slate-700" aria-current="page"><?= e($pageTitle); ?></li>
        </ol>
    </nav>
    <h1 class="text-2xl font-extrabold text-slate-900"><?= e($pageTitle); ?></h1>
    <p class="mt-1 text-sm text-slate-500">Data ini dipakai sebagai tujuan disposisi surat.</p>

    <?php if ($errors): ?>
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <?php foreach ($errors as $error): ?>
                <p>- <?= e($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="mt-6 grid gap-4 sm:grid-cols-2" x-data="{ isSubmitting: false }" @submit="isSubmitting = true">
        <?= csrf_field(); ?>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-600">NIP</label>
            <input type="text" name="nip" value="<?= e($formData['nip']); ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-600">Status</label>
            <select name="is_active" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <option value="1" <?= (int) $formData['is_active'] === 1 ? 'selected' : ''; ?>>Aktif</option>
                <option value="0" <?= (int) $formData['is_active'] === 0 ? 'selected' : ''; ?>>Nonaktif</option>
            </select>
        </div>
        <div class="sm:col-span-2">
            <label class="mb-1 block text-sm font-semibold text-slate-600">Nama Pegawai</label>
            <input type="text" name="full_name" value="<?= e($formData['full_name']); ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" required>
        </div>
        <div class="sm:col-span-1">
            <label class="mb-1 block text-sm font-semibold text-slate-600">Email</label>
            <input type="email" name="email" value="<?= e($formData['email']); ?>" placeholder="nama@domain.com" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" required>
        </div>
        <div class="sm:col-span-1">
            <label class="mb-1 block text-sm font-semibold text-slate-600">No. WhatsApp</label>
            <input type="text" name="whatsapp_number" value="<?= e($formData['whatsapp_number']); ?>" placeholder="081234567890" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" required>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-600">Username (Untuk Login)</label>
            <input type="text" name="username" value="<?= e($formData['username']); ?>" pattern="[a-zA-Z0-9]+" title="Hanya boleh menggunakan huruf dan angka (tanpa spasi)" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100" required>
            <p class="mt-1 text-[11px] text-slate-500">Bisa menggunakan huruf, angka (seperti NIP), atau kombinasinya tanpa spasi.</p>
        </div>
        <div x-data="{ showPass: false }">
            <label class="mb-1 block text-sm font-semibold text-slate-600">Password <?= $isEdit ? '(Isi jika ingin diubah)' : ''; ?></label>
            <div class="relative">
                <input :type="showPass ? 'text' : 'password'" name="password" class="w-full rounded-xl border border-slate-200 px-3 py-2 pr-11 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <button type="button" @click="showPass = !showPass" class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-slate-500 hover:text-slate-700">
                    <svg x-show="!showPass" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <svg x-show="showPass" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m3 3 18 18"></path>
                        <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                        <path d="M9.36 5.37A9.8 9.8 0 0 1 12 5c7 0 11 7 11 7a13.17 13.17 0 0 1-1.67 2.68"></path>
                        <path d="M6.61 6.61A13.53 13.53 0 0 0 1 12s4 7 11 7a9.74 9.74 0 0 0 5.39-1.61"></path>
                    </svg>
                </button>
            </div>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-600">Jabatan</label>
            <input type="text" name="position_title" value="<?= e($formData['position_title']); ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold text-slate-600">Unit Kerja</label>
            <input type="text" name="unit_name" value="<?= e($formData['unit_name']); ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-2 flex flex-wrap gap-2 pt-2">
            <button type="submit" :disabled="isSubmitting" :class="isSubmitting ? 'opacity-70 cursor-not-allowed' : ''" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                <span x-show="!isSubmitting"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Pegawai'; ?></span>
                <span x-show="isSubmitting" x-cloak>Menyimpan...</span>
            </button>
            <a href="master_pegawai.php" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Kembali</a>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

