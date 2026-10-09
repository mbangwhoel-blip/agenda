<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireRole(['pegawai', 'admin', 'sekretaris', 'pimpinan']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('disposisi_saya.php');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Token keamanan tidak valid.');
    redirect('disposisi_saya.php');
}

$itemId    = (int) ($_POST['item_id'] ?? 0);
$returnTo  = $_POST['return_to'] ?? 'disposisi_saya.php';
if (!in_array($returnTo, ['disposisi_saya.php', 'surat_detail_v2.php', 'dashboard.php'], true)) {
    $returnTo = 'disposisi_saya.php';
}

if ($itemId <= 0) {
    setFlash('error', 'Item tidak valid.');
    redirect($returnTo);
}

$user      = currentUser();
$pdo       = getPdo();
$employeeId = (int) ($user['employee_id'] ?? 0);

// Validasi kepemilikan — pegawai hanya bisa selesaikan miliknya sendiri
if (isPegawai()) {
    if ($employeeId <= 0) {
        setFlash('error', 'Akun pegawai tidak terhubung ke data pegawai.');
        redirect($returnTo);
    }
    $check = $pdo->prepare(
        "SELECT id, completed_at FROM agenda_disposition_items WHERE id = :id AND employee_id = :emp_id LIMIT 1"
    );
    $check->execute(['id' => $itemId, 'emp_id' => $employeeId]);
} else {
    // Admin/sekretaris bisa menandai semua
    $check = $pdo->prepare(
        "SELECT id, completed_at FROM agenda_disposition_items WHERE id = :id LIMIT 1"
    );
    $check->execute(['id' => $itemId]);
}

$item = $check->fetch();

if (!$item) {
    setFlash('error', 'Data disposisi tidak ditemukan atau Anda tidak berwenang.');
    redirect($returnTo);
}

// Idempotent — jangan update jika sudah selesai
if (!empty($item['completed_at'])) {
    setFlash('error', 'Disposisi ini sudah ditandai selesai sebelumnya.');
    redirect($returnTo);
}

$upd = $pdo->prepare(
    "UPDATE agenda_disposition_items
     SET read_status = 'sudah', completed_at = NOW()
     WHERE id = :id"
);
$upd->execute(['id' => $itemId]);

logActivity('mark_done', "Menandai disposisi ID {$itemId} sebagai selesai", 'disposition', $itemId);
setFlash('success', 'Disposisi berhasil ditandai selesai.');
redirect($returnTo);
