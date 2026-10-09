<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$returnTo = $_GET['return_to'] ?? 'agenda.php';
if (!in_array($returnTo, ['agenda.php', 'agenda_zoom.php', 'agenda_kegiatan.php'], true)) {
    $returnTo = 'agenda.php';
}

if (!verify_csrf_token($_GET['token'] ?? '')) {
    setFlash('error', 'Token keamanan tidak valid. Silakan ulangi dari halaman sebelumnya.');
    redirect($returnTo);
}

if ($id <= 0) {
    setFlash('error', 'ID agenda tidak valid.');
    redirect($returnTo);
}

$pdo = getPdo();
$checkStmt = $pdo->prepare("SELECT user_id, entry_type, attachment FROM agendas WHERE id = :id");
$checkStmt->execute(['id' => $id]);
$agenda = $checkStmt->fetch();

if (!$agenda) {
    setFlash('error', 'Data agenda tidak ditemukan.');
    redirect($returnTo);
}

if (($agenda['entry_type'] ?? '') !== 'surat' || !canManageLetterArchive((int) ($agenda['user_id'] ?? 0))) {
    setFlash('error', 'Anda tidak punya akses menghapus data ini.');
    redirect($returnTo);
}

if (($agenda['entry_type'] ?? '') === 'surat') {
    $cleanupStmt = $pdo->prepare("DELETE FROM agendas WHERE source_agenda_id = :source_id AND ai_generated = 1");
    $cleanupStmt->execute(['source_id' => $id]);
}

$stmt = $pdo->prepare("DELETE FROM agendas WHERE id = :id");
$stmt->execute(['id' => $id]);

if (!empty($agenda['attachment'])) {
    deleteAttachmentIfUnused($pdo, (string) $agenda['attachment'], [$id]);
}

setFlash('success', 'Agenda berhasil dihapus.');
logActivity('delete_agenda', "Menghapus agenda ID {$id}", 'agenda', $id);
redirect($returnTo);
