<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();
if (!canManageEmployees()) {
    setFlash('error', 'Akses ditolak.');
    redirect('dashboard.php');
}

if (!verify_csrf_token($_GET['token'] ?? '')) {
    setFlash('error', 'Token keamanan tidak valid. Silakan ulangi dari halaman master pegawai.');
    redirect('master_pegawai.php');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    setFlash('error', 'ID pegawai tidak valid.');
    redirect('master_pegawai.php');
}

$pdo = getPdo();
$check = $pdo->prepare("SELECT id FROM employees WHERE id = :id LIMIT 1");
$check->execute(['id' => $id]);
if (!$check->fetch()) {
    setFlash('error', 'Data pegawai tidak ditemukan.');
    redirect('master_pegawai.php');
}

$inUse = $pdo->prepare("SELECT COUNT(*) FROM agenda_disposition_items WHERE employee_id = :id");
$inUse->execute(['id' => $id]);
if ((int) $inUse->fetchColumn() > 0) {
    setFlash('error', 'Pegawai sudah dipakai pada disposisi. Nonaktifkan saja jika tidak ingin dipakai.');
    redirect('master_pegawai.php');
}

$del = $pdo->prepare("DELETE FROM employees WHERE id = :id");
$del->execute(['id' => $id]);

setFlash('success', 'Pegawai berhasil dihapus.');
logActivity('delete_employee', "Menghapus pegawai ID {$id}", 'employee', $id);
redirect('master_pegawai.php');
