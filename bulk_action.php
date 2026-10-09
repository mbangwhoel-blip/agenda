<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireRole(['admin', 'sekretaris', 'pimpinan']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('agenda.php');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Token keamanan tidak valid.');
    redirect('agenda.php');
}

$action  = trim((string) ($_POST['action']  ?? ''));
$returnTo = trim((string) ($_POST['return_to'] ?? 'agenda.php'));
if (!in_array($returnTo, ['agenda.php', 'agenda_kegiatan.php'], true)) {
    $returnTo = 'agenda.php';
}

// Parse IDs
$rawIds = (array) ($_POST['ids'] ?? []);
$ids    = [];
foreach ($rawIds as $rawId) {
    $clean = (int) $rawId;
    if ($clean > 0) {
        $ids[] = $clean;
    }
}
$ids = array_unique($ids);

if ($ids === []) {
    setFlash('error', 'Tidak ada item yang dipilih.');
    redirect($returnTo);
}

$pdo = getPdo();

if ($action === 'delete') {
    // Fetch attachment paths first for cleanup
    $holders = implode(',', array_fill(0, count($ids), '?'));
    $attachStmt = $pdo->prepare("SELECT id, attachment FROM agendas WHERE id IN ({$holders})");
    $attachStmt->execute($ids);
    $toDelete = $attachStmt->fetchAll();

    // Delete any child AI generated agendas linked to these deleted agendas first
    $cleanupStmt = $pdo->prepare("DELETE FROM agendas WHERE source_agenda_id IN ({$holders}) AND ai_generated = 1");
    $cleanupStmt->execute($ids);

    // Delete records
    $delStmt = $pdo->prepare("DELETE FROM agendas WHERE id IN ({$holders})");
    $delStmt->execute($ids);

    // Cleanup orphaned attachments
    foreach ($toDelete as $row) {
        if (!empty($row['attachment'])) {
            deleteAttachmentIfUnused($pdo, (string) $row['attachment']);
        }
    }

    $count = count($ids);
    logActivity('bulk_delete', "Hapus massal {$count} agenda: " . implode(', ', $ids), 'agenda', null);
    setFlash('success', "{$count} data berhasil dihapus.");
    redirect($returnTo);
}

if ($action === 'export_excel') {
    // Redirect to export with selected IDs
    $idParam = implode(',', $ids);
    redirect('export.php?type=excel&ids=' . urlencode($idParam));
}

setFlash('error', 'Aksi tidak dikenal.');
redirect($returnTo);
