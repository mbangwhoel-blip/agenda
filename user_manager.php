<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
$pdo = getPdo();
requireRole(['admin']);
$flash = getFlash();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token keamanan tidak valid.');
        redirect('user_manager.php');
    }

    $action = trim($_POST['action'] ?? '');

    // ── ADD USER ──────────────────────────────────────────────────────────────
    if ($action === 'add_user') {
        $full_name   = trim($_POST['full_name'] ?? '');
        $username    = trim($_POST['username'] ?? '');
        $password    = $_POST['password'] ?? '';
        $role        = trim($_POST['role'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
        $employee_id = (int) ($_POST['employee_id'] ?? 0);

        if ($full_name === '') {
            $errors[] = 'Nama lengkap wajib diisi.';
        }
        if ($username === '') {
            $errors[] = 'Username wajib diisi.';
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
            $chk->execute(['u' => $username]);
            if ((int) $chk->fetchColumn() > 0) {
                $errors[] = 'Username sudah digunakan.';
            }
        }
        $pwCheck = validatePassword($password);
        if (!$pwCheck['valid']) {
            $errors = array_merge($errors, $pwCheck['errors']);
        } elseif (isPasswordBreached($password)) {
            $errors[] = 'Password pernah ditemukan di database kebocoran data.';
        }
        if (!in_array($role, ['admin', 'sekretaris', 'pimpinan', 'pegawai', 'arsiparis'], true)) {
            $errors[] = 'Role tidak valid.';
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $emp  = $employee_id > 0 ? $employee_id : null;
            $ins  = $pdo->prepare(
                "INSERT INTO users (full_name, username, password_hash, role, employee_id, email, whatsapp_number, created_at)
                 VALUES (:fn, :un, :ph, :role, :emp, :em, :wa, NOW())"
            );
            $ins->execute([
                'fn'   => $full_name,
                'un'   => $username,
                'ph'   => $hash,
                'role' => $role,
                'emp'  => $emp,
                'em'   => $email,
                'wa'   => $whatsapp_number,
            ]);
            logActivity('user_add', "Tambah user: {$username} (role: {$role})", 'users', (int) $pdo->lastInsertId());
            setFlash('success', "User \"{$username}\" berhasil ditambahkan.");
            redirect('user_manager.php');
        }
    }

    // ── EDIT USER ─────────────────────────────────────────────────────────────
    elseif ($action === 'edit_user') {
        $id        = (int) ($_POST['id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = trim($_POST['role'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');

        if ($full_name === '') {
            $errors[] = 'Nama lengkap wajib diisi.';
        }
        if (!in_array($role, ['admin', 'sekretaris', 'pimpinan', 'pegawai', 'arsiparis'], true)) {
            $errors[] = 'Role tidak valid.';
        }

        if (empty($errors)) {
            $upd = $pdo->prepare("UPDATE users SET full_name = :fn, role = :role, email = :em, whatsapp_number = :wa WHERE id = :id");
            $upd->execute(['fn' => $full_name, 'role' => $role, 'em' => $email, 'wa' => $whatsapp_number, 'id' => $id]);
            logActivity('user_edit', "Edit user id={$id} (role: {$role})", 'users', $id);
            setFlash('success', 'Data pengguna berhasil diperbarui.');
            redirect('user_manager.php');
        }
    }

    // ── RESET PASSWORD ────────────────────────────────────────────────────────
    elseif ($action === 'reset_password') {
        $id           = (int) ($_POST['id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if ($new_password !== '') {
            $pwCheck = validatePassword($new_password);
            if (!$pwCheck['valid']) {
                $errors = array_merge($errors, $pwCheck['errors']);
            } elseif (isPasswordBreached($new_password)) {
                $errors[] = 'Password baru pernah ditemukan di database kebocoran data.';
            }
        }

        if (empty($errors) && $new_password !== '') {
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
            $upd  = $pdo->prepare("UPDATE users SET password_hash = :ph WHERE id = :id");
            $upd->execute(['ph' => $hash, 'id' => $id]);
            logActivity('user_reset_pw', "Reset password user id={$id}", 'users', $id);
            setFlash('success', 'Password berhasil direset.');
            redirect('user_manager.php');
        } elseif (empty($errors)) {
            // field kosong — tidak ada perubahan
            setFlash('error', 'Isi field password baru terlebih dahulu.');
            redirect('user_manager.php');
        }
    }

    // ── DELETE USER ───────────────────────────────────────────────────────────
    elseif ($action === 'delete_user') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id === 1) {
            setFlash('error', 'User utama tidak dapat dihapus.');
            redirect('user_manager.php');
        }

        $del = $pdo->prepare("DELETE FROM users WHERE id = :id AND id != 1");
        $del->execute(['id' => $id]);
        logActivity('user_delete', "Hapus user id={$id}", 'users', $id);
        setFlash('success', 'Pengguna berhasil dihapus.');
        redirect('user_manager.php');
    }

    // ── APPROVE REGISTRATION ─────────────────────────────────────────────────
    elseif ($action === 'approve_registration') {
        $regId = (int) ($_POST['id'] ?? 0);

        $stmtReg = $pdo->prepare("SELECT * FROM user_registrations WHERE id = :id AND status = 'pending' LIMIT 1");
        $stmtReg->execute(['id' => $regId]);
        $reg = $stmtReg->fetch();

        if (!$reg) {
            setFlash('error', 'Permintaan pendaftaran tidak ditemukan atau sudah diproses.');
            redirect('user_manager.php');
        } else {
            // Cek ulang bentrok username (race-condition guard)
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
            $chk->execute(['u' => $reg['username']]);
            if ($chk->fetch()) {
                setFlash('error', "Username \"{$reg['username']}\" sudah dipakai user aktif. Tolak permintaan ini.");
                redirect('user_manager.php');
            }

            $ins = $pdo->prepare(
                "INSERT INTO users (full_name, username, email, whatsapp_number, password_hash, role, created_at)
                 VALUES (:fn, :un, :em, :wa, :ph, 'pegawai', NOW())"
            );
            $ins->execute([
                'fn' => $reg['full_name'],
                'un' => $reg['username'],
                'em' => $reg['email'],
                'wa' => $reg['whatsapp_number'],
                'ph' => $reg['password_hash'],
            ]);
            $newUserId = (int) $pdo->lastInsertId();

            $upd = $pdo->prepare("UPDATE user_registrations SET status = 'approved', reviewed_at = NOW(), reviewed_by = :rb WHERE id = :id");
            $upd->execute(['rb' => currentUser()['id'] ?? null, 'id' => $regId]);

            logActivity('registration_approve', "Setujui pendaftaran: {$reg['username']} -> user_id={$newUserId}", 'users', $newUserId);
            setFlash('success', "Pendaftaran \"{$reg['username']}\" disetujui. Akun pegawai aktif.");
            redirect('user_manager.php');
        }
    }

    // ── REJECT REGISTRATION ──────────────────────────────────────────────────
    elseif ($action === 'reject_registration') {
        $regId = (int) ($_POST['id'] ?? 0);

        $stmtReg = $pdo->prepare("SELECT username FROM user_registrations WHERE id = :id AND status = 'pending' LIMIT 1");
        $stmtReg->execute(['id' => $regId]);
        $reg = $stmtReg->fetch();

        if (!$reg) {
            setFlash('error', 'Permintaan pendaftaran tidak ditemukan atau sudah diproses.');
            redirect('user_manager.php');
        }

        $upd = $pdo->prepare("UPDATE user_registrations SET status = 'rejected', reviewed_at = NOW(), reviewed_by = :rb WHERE id = :id");
        $upd->execute(['rb' => currentUser()['id'] ?? null, 'id' => $regId]);

        logActivity('registration_reject', "Tolak pendaftaran: {$reg['username']}");
        setFlash('success', "Pendaftaran \"{$reg['username']}\" ditolak.");
        redirect('user_manager.php');
    }
}

// ── Fetch all users ────────────────────────────────────────────────────────────
$users = $pdo->query(
    "SELECT id, full_name, username, role, created_at FROM users ORDER BY id ASC"
)->fetchAll();

// ── Fetch pending registrations ────────────────────────────────────────────────
$pendingRegs = [];
try {
    $pendingRegs = $pdo->query(
        "SELECT id, full_name, username, email, whatsapp_number, created_at
         FROM user_registrations
         WHERE status = 'pending'
         ORDER BY created_at ASC"
    )->fetchAll();
} catch (Throwable $e) {
    // Tabel belum ada — tidak ada pendaftaran pending.
    $pendingRegs = [];
}

// ── Stats ──────────────────────────────────────────────────────────────────────
$stats = ['total' => count($users), 'admin' => 0, 'sekretaris' => 0, 'pimpinan' => 0, 'pegawai' => 0];
foreach ($users as $u) {
    $stats[$u['role']] = ($stats[$u['role']] ?? 0) + 1;
}

// ── Employee dropdown (for employee_id on add) ─────────────────────────────────
$employees = $pdo->query(
    "SELECT id, full_name FROM employees WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

$pageTitle = 'Kelola Pengguna';
require_once __DIR__ . '/includes/header.php';
?>

<?php
// Inline errors from a failed POST (no redirect was done, so re-display)
$addOpen  = !empty($errors) && ($_POST['action'] ?? '') === 'add_user';
$editOpen = !empty($errors) && ($_POST['action'] ?? '') === 'edit_user';
?>
<div x-data="{
    showAdd: <?= $addOpen ? 'true' : 'false' ?>,
    showEdit: false,
    showDelete: false,
    showNewPassword: false,
    showAddPassword: false,
    editId: 0, editFullName: '', editRole: '', editEmail: '', editWhatsapp: '',
    deleteId: 0, deleteUsername: '',
    openEdit(id, fullName, role, email, whatsapp) {
        this.editId = id;
        this.editFullName = fullName;
        this.editRole = role;
        this.editEmail = email;
        this.editWhatsapp = whatsapp;
        this.showEdit = true;
    },
    openDelete(id, username) {
        this.deleteId = id;
        this.deleteUsername = username;
        this.showDelete = true;
    }
}">

    <?php if ($flash): ?>
        <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 3000)"
             x-transition
             class="mb-6 rounded-xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
            <?= e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- ── Stats cards ──────────────────────────────────────────────────── -->
    <section class="mb-6">
        <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-transparent dark:from-indigo-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Total Users</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['total']; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                </div>
            </article>
            <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-rose-50 to-transparent dark:from-rose-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Admin</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['admin']; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                </div>
            </article>
            <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-sky-50 to-transparent dark:from-sky-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Sekretaris</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['sekretaris']; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                    </div>
                </div>
            </article>
            <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
                <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="relative z-10 flex justify-between items-start">
                    <div>
                        <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Pimpinan</p>
                        <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['pimpinan']; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                    </div>
                </div>
            </article>
        </div>
    </section>

    <!-- ── Header row ────────────────────────────────────────────────────── -->
    <section class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-3xl font-extrabold text-slate-900 dark:text-slate-100">Kelola Pengguna</h2>
        <button type="button" @click="showAdd = true"
                class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700">
            + Tambah Pengguna
        </button>
    </section>

    <!-- ── Pending registrations table ───────────────────────────────────── -->
    <?php if (!empty($pendingRegs)): ?>
    <section class="mb-6 overflow-hidden rounded-3xl border border-amber-200 bg-amber-50/40 shadow-sm">
        <div class="flex items-center gap-3 border-b border-amber-200 bg-amber-100/60 px-5 py-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022 23.848 23.848 0 0 0 5.454 1.31m6.722 0a24.255 24.255 0 0 1-6.722 0m6.722 0a3 3 0 1 1-6.722 0"/>
            </svg>
            <h3 class="text-base font-extrabold text-amber-900">
                Pendaftaran Menunggu Persetujuan
                <span class="ml-2 inline-flex items-center rounded-full bg-amber-600 px-2 py-0.5 text-xs font-bold text-white"><?= count($pendingRegs); ?></span>
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm leading-6">
                <thead class="bg-amber-100/40 border-b border-amber-200 text-xs uppercase tracking-wider text-amber-800 font-semibold">
                    <tr>
                        <th class="px-4 py-3 text-left whitespace-nowrap">No</th>
                        <th class="px-4 py-3 text-left min-w-[160px]">Nama Lengkap</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">Username</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">Email</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">WhatsApp</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">Daftar Pada</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-amber-100 text-slate-700">
                    <?php foreach ($pendingRegs as $i => $r): ?>
                        <tr class="align-middle hover:bg-amber-50 transition-colors">
                            <td class="px-4 py-3 font-semibold text-amber-700"><?= $i + 1; ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= e($r['full_name']); ?></td>
                            <td class="px-4 py-3 text-slate-600 font-medium"><?= e($r['username']); ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= e($r['email'] ?: '-'); ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= e($r['whatsapp_number'] ?: '-'); ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <form method="post" action="user_manager.php" class="inline" onsubmit="return confirm('Setujui pendaftaran <?= e($r['username']); ?>? Akun pegawai akan dibuat.');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="approve_registration">
                                        <input type="hidden" name="id" value="<?= (int) $r['id']; ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700 transition" title="Setujui">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            Setujui
                                        </button>
                                    </form>
                                    <form method="post" action="user_manager.php" class="inline" onsubmit="return confirm('Tolak pendaftaran <?= e($r['username']); ?>?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="reject_registration">
                                        <input type="hidden" name="id" value="<?= (int) $r['id']; ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700 transition" title="Tolak">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            Tolak
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── Users table ───────────────────────────────────────────────────── -->
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm leading-6">
                <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-wider text-slate-500 font-semibold">
                    <tr>
                        <th class="px-4 py-4 text-left whitespace-nowrap">No</th>
                        <th class="px-4 py-4 text-left min-w-[180px]">Nama Lengkap</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Username</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Role</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Tanggal Dibuat</th>
                        <th class="px-4 py-4 text-left whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700 dark:divide-slate-700 dark:text-slate-300">
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <div class="flex flex-col items-center justify-center opacity-80">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-16 w-16 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <h5 class="text-xl font-bold text-slate-500">Belum Ada Data Pengguna</h5>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $i => $u): ?>
                            <?php
                            $badgeClass = match ($u['role']) {
                                'admin'      => 'bg-rose-100 text-rose-700',
                                'sekretaris' => 'bg-sky-100 text-sky-700',
                                'pimpinan'   => 'bg-indigo-100 text-indigo-700',
                                'arsiparis'  => 'bg-violet-100 text-violet-700',
                                default      => 'bg-emerald-100 text-emerald-700',
                            };
                            ?>
                            <tr class="align-middle hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="px-4 py-4 font-semibold text-indigo-600"><?= $i + 1; ?></td>
                                <td class="px-4 py-4 font-semibold text-slate-800 dark:text-slate-200"><?= e($u['full_name']); ?></td>
                                <td class="px-4 py-4 text-slate-600 dark:text-slate-400 font-medium"><?= e($u['username']); ?></td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold capitalize <?= $badgeClass; ?>">
                                        <?= e($u['role']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-slate-500 dark:text-slate-400">
                                    <?= e(date('d/m/Y', strtotime($u['created_at']))); ?>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <button type="button"
                                                @click="openEdit(<?= (int) $u['id']; ?>, '<?= e(addslashes($u['full_name'])); ?>', '<?= e($u['role']); ?>', '<?= e(addslashes($u['email'] ?? '')); ?>', '<?= e(addslashes($u['whatsapp_number'] ?? '')); ?>')"
                                                class="inline-flex items-center justify-center h-8 w-8 rounded-xl bg-amber-100 text-amber-700 hover:bg-amber-200 transition" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"/></svg>
                                        </button>
                                        <?php if ((int) $u['id'] !== 1): ?>
                                            <button type="button"
                                                    @click="openDelete(<?= (int) $u['id']; ?>, '<?= e(addslashes($u['username'])); ?>')"
                                                    class="inline-flex items-center justify-center h-8 w-8 rounded-xl bg-red-100 text-red-700 hover:bg-red-200 transition" title="Hapus">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <!-- ADD USER MODAL                                                       -->
    <!-- ════════════════════════════════════════════════════════════════════ -->
    <div x-show="showAdd" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-2 sm:p-4"
         @keydown.escape.window="showAdd = false">
        <div class="w-full max-w-md flex flex-col min-h-0 max-h-[90vh] overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-slate-800"
             @click.outside="showAdd = false">
            <!-- Header -->
            <div class="flex-none flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-700">
                <h3 class="text-base font-extrabold text-slate-900 dark:text-slate-100">Tambah Pengguna Baru</h3>
                <button type="button" @click="showAdd = false"
                        class="rounded-xl p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            
            <!-- Body (Scrollable) -->
            <div class="flex-1 overflow-y-auto p-0 min-h-0">
                <form id="form-add-user" method="post" action="user_manager.php" class="px-5 py-4 space-y-2.5">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="add_user">

                <?php if (!empty($errors) && ($addOpen)): ?>
                    <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        <ul class="list-disc list-inside space-y-1">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Nama Lengkap <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="full_name"
                           value="<?= $addOpen ? e($_POST['full_name'] ?? '') : ''; ?>"
                           required
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Username <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="username"
                           value="<?= $addOpen ? e($_POST['username'] ?? '') : ''; ?>"
                           required autocomplete="off"
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Email
                    </label>
                    <input type="email" name="email"
                           value="<?= $addOpen ? e($_POST['email'] ?? '') : ''; ?>"
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        No WhatsApp
                    </label>
                    <input type="text" name="whatsapp_number"
                           value="<?= $addOpen ? e($_POST['whatsapp_number'] ?? '') : ''; ?>"
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Password <span class="text-red-500">*</span>
                        <span class="font-normal text-slate-400">(min. 8 karakter)</span>
                    </label>
                    <div class="relative">
                        <input :type="showAddPassword ? 'text' : 'password'" name="password" required autocomplete="new-password"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 pr-10 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                        <button type="button" @click="showAddPassword = !showAddPassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            <svg x-show="!showAddPassword" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg x-show="showAddPassword" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" /></svg>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Role <span class="text-red-500">*</span>
                    </label>
                    <select name="role" required
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                        <option value="">-- Pilih Role --</option>
                        <?php foreach (['admin', 'sekretaris', 'pimpinan', 'pegawai', 'arsiparis'] as $r): ?>
                            <option value="<?= $r; ?>" <?= $addOpen && ($_POST['role'] ?? '') === $r ? 'selected' : ''; ?>>
                                <?= ucfirst($r); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                </form>
            </div><!-- end scrollable body -->

            <!-- Footer -->
            <div class="flex-none border-t border-slate-200 px-5 py-3 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 rounded-b-xl">
                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="showAdd = false"
                            class="rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-white dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                        Batal
                    </button>
                    <button type="submit" form="form-add-user"
                            class="rounded-xl bg-indigo-600 px-3 py-1.5 text-sm font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700">
                        Simpan User
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <!-- EDIT / RESET PASSWORD MODAL                                          -->
    <!-- ════════════════════════════════════════════════════════════════════ -->
    <div x-show="showEdit" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-2 sm:p-4"
         @keydown.escape.window="showEdit = false">
        <div class="w-full max-w-md flex flex-col min-h-0 max-h-[90vh] overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-slate-800"
             @click.outside="showEdit = false">
            <!-- Header -->
            <div class="flex-none flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-700">
                <h3 class="text-base font-extrabold text-slate-900 dark:text-slate-100">Edit Pengguna</h3>
                <button type="button" @click="showEdit = false"
                        class="rounded-xl p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <!-- Body (Scrollable) -->
            <div class="flex-1 overflow-y-auto p-0 min-h-0">
                <!-- Edit form -->
                <form id="form-edit-user" method="post" action="user_manager.php" class="px-5 py-4 space-y-2.5">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="id" :value="editId">

                <?php if (!empty($errors) && $editOpen): ?>
                    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <ul class="list-disc list-inside space-y-1">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Nama Lengkap <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="full_name" x-model="editFullName" required
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Email
                    </label>
                    <input type="email" name="email" x-model="editEmail"
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        No WhatsApp
                    </label>
                    <input type="text" name="whatsapp_number" x-model="editWhatsapp"
                           class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Role <span class="text-red-500">*</span>
                    </label>
                    <select name="role" x-model="editRole" required
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                        <option value="">-- Pilih Role --</option>
                        <option value="admin">Admin</option>
                        <option value="sekretaris">Sekretaris</option>
                        <option value="pimpinan">Pimpinan</option>
                        <option value="pegawai">Pegawai</option>
                        <option value="arsiparis">Arsiparis</option>
                    </select>
                </div>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showEdit = false"
                            class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                        Batal
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700">
                        Simpan Perubahan
                    </button>
                </div>
            </form>

            <!-- Reset password section -->
            <form id="form-reset-password" method="post" action="user_manager.php" class="px-5 py-4 space-y-2.5 border-t border-slate-100 dark:border-slate-700">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" :value="editId">

                <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Reset Password</p>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Password Baru
                        <span class="font-normal text-slate-400">(min. 8 karakter, kosongkan jika tidak ingin ubah)</span>
                    </label>
                    <div class="relative">
                        <input :type="showNewPassword ? 'text' : 'password'" name="new_password" autocomplete="new-password"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 pr-10 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                        <button type="button" @click="showNewPassword = !showNewPassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            <svg x-show="!showNewPassword" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg x-show="showNewPassword" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" /></svg>
                        </button>
                    </div>
                </div>
            </form>
            </div><!-- end scrollable body -->
            
            <!-- Footer -->
            <div class="flex-none border-t border-slate-200 px-5 py-3 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 rounded-b-xl">
                <div class="flex justify-between items-center">
                    <button type="submit" form="form-reset-password"
                            class="rounded-xl bg-amber-500 px-3 py-1.5 text-sm font-bold text-white hover:bg-amber-600">
                        Reset Password
                    </button>
                    <div class="flex gap-2">
                        <button type="button" @click="showEdit = false"
                                class="rounded-xl border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-white dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                            Batal
                        </button>
                        <button type="submit" form="form-edit-user"
                                class="rounded-xl bg-indigo-600 px-3 py-1.5 text-sm font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700">
                            Simpan User
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <!-- DELETE CONFIRMATION MODAL                                            -->
    <!-- ════════════════════════════════════════════════════════════════════ -->
    <div x-show="showDelete" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
         @keydown.escape.window="showDelete = false">
        <div class="w-full max-w-sm rounded-3xl bg-white shadow-2xl dark:bg-slate-800"
             @click.outside="showDelete = false">
            <div class="flex flex-col items-center px-8 py-8 text-center">
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <h3 class="text-xl font-extrabold text-slate-900 dark:text-slate-100">Hapus Pengguna?</h3>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Anda akan menghapus akun <strong x-text="deleteUsername"></strong>.
                    Tindakan ini tidak dapat dibatalkan.
                </p>
            </div>
            <form method="post" action="user_manager.php" class="flex items-center justify-center gap-3 px-6 pb-8">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="id" :value="deleteId">
                <button type="button" @click="showDelete = false"
                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                    Batal
                </button>
                <button type="submit"
                        class="rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">
                    Ya, Hapus
                </button>
            </form>
        </div>
    </div>

</div><!-- end x-data wrapper -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

