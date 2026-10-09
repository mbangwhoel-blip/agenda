<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    setFlash('error', 'ID reminder tidak valid.');
    redirect('dashboard.php');
}

$pdo = getPdo();
$stmt = $pdo->prepare("SELECT id, user_id, entry_type, reminder_h1_enabled FROM agendas WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$agenda = $stmt->fetch();

if (!$agenda) {
    setFlash('error', 'Data agenda tidak ditemukan.');
    redirect('dashboard.php');
}

if (($agenda['entry_type'] ?? '') !== 'kegiatan' || (int) ($agenda['reminder_h1_enabled'] ?? 0) !== 1) {
    setFlash('error', 'Data ini tidak memiliki pengingat H-1 aktif.');
    redirect('dashboard.php');
}

if (!canManageAgenda((int) ($agenda['user_id'] ?? 0))) {
    setFlash('error', 'Anda tidak punya akses mengubah reminder ini.');
    redirect('dashboard.php');
}

$update = $pdo->prepare("UPDATE agendas SET reminder_h1_sent_at = NOW() WHERE id = :id");
$update->execute(['id' => $id]);

setFlash('success', 'Pengingat H-1 ditandai sudah diingatkan.');
redirect('dashboard.php');
