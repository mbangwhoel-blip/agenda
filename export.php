<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();
requireRole(['admin', 'sekretaris', 'pimpinan']);

$pdo  = getPdo();
$type = $_GET['type'] ?? 'excel';

// ── Filter params ─────────────────────────────────────────────────────────────
$dateFrom   = trim((string) ($_GET['date_from']   ?? ''));
$dateTo     = trim((string) ($_GET['date_to']     ?? ''));
$jenis      = trim((string) ($_GET['jenis']       ?? 'semua'));
$arah       = trim((string) ($_GET['arah']        ?? 'semua'));
$dispStatus = trim((string) ($_GET['disp_status'] ?? 'semua'));

if (!in_array($jenis,      ['semua', 'surat', 'kegiatan'],              true)) { $jenis      = 'semua'; }
if (!in_array($arah,       ['semua', 'masuk', 'keluar'],                true)) { $arah       = 'semua'; }
if (!in_array($dispStatus, ['semua', 'pending', 'approved', 'rejected'], true)) { $dispStatus = 'semua'; }

// Validate date range
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $dateFrom = '';
    $dateTo   = '';
}

// Bulk IDs (from bulk_action.php via export selected)
$bulkIds = [];
if (!empty($_GET['ids'])) {
    foreach (explode(',', (string) $_GET['ids']) as $rawId) {
        $clean = (int) trim($rawId);
        if ($clean > 0) {
            $bulkIds[] = $clean;
        }
    }
}

// ── Build query ────────────────────────────────────────────────────────────────
$conditions = ['1=1'];
$params     = [];

if ($bulkIds !== []) {
    $holders = implode(',', array_fill(0, count($bulkIds), '?'));
    $conditions[] = "a.id IN ({$holders})";
    $params = array_merge($params, $bulkIds);
} else {
    if ($jenis !== 'semua') {
        $conditions[] = 'a.entry_type = ?';
        $params[]     = $jenis;
    }
    if ($arah !== 'semua') {
        $conditions[] = 'a.direction = ?';
        $params[]     = $arah;
    }
    if ($dispStatus !== 'semua') {
        $conditions[] = 'a.disposition_status = ?';
        $params[]     = $dispStatus;
    }
    if ($dateFrom !== '') {
        $conditions[] = 'COALESCE(a.letter_date, a.recorded_date, a.agenda_date) >= ?';
        $params[]     = $dateFrom;
    }
    if ($dateTo !== '') {
        $conditions[] = 'COALESCE(a.letter_date, a.recorded_date, a.agenda_date) <= ?';
        $params[]     = $dateTo;
    }
}

$sql = "SELECT a.agenda_date, a.agenda_date_end, a.title, a.entry_type, a.direction, a.reference_number,
               a.counterparty, a.status, a.priority, a.location, a.disposition_status,
               u.full_name AS created_by
        FROM agendas a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY COALESCE(a.letter_date, a.recorded_date, a.agenda_date) DESC, a.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = ['Tanggal', 'Judul/Perihal', 'Jenis', 'Arah', 'No Referensi', 'Pengirim/Penerima', 'Status', 'Prioritas', 'Lokasi', 'Status Disposisi', 'Dibuat Oleh'];

$labelMap = [
    'entry_type'         => ['surat' => 'Surat', 'kegiatan' => 'Kegiatan'],
    'direction'          => ['masuk' => 'Masuk', 'keluar' => 'Keluar'],
    'status'             => ['draft' => 'Draft', 'proses' => 'Proses', 'selesai' => 'Selesai'],
    'priority'           => ['rendah' => 'Rendah', 'normal' => 'Normal', 'tinggi' => 'Tinggi'],
    'disposition_status' => ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'],
];

$instansi = function_exists('getAppSetting')
    ? getAppSetting('instansi_nama', 'BBLKL Salatiga')
    : 'BBLKL Salatiga';

// ── Excel (.xls HTML table) ────────────────────────────────────────────────────
if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_agenda_' . date('Ymd_His') . '.xls');
    header('Cache-Control: max-age=0');

    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<h2>Laporan Agenda Pimpinan</h2>";
    echo "<p>Instansi: " . e($instansi) . " | Tanggal cetak: " . date('d-m-Y H:i') . "</p>";
    echo "<table border='1' style='border-collapse:collapse;font-size:12px'>";
    echo "<tr style='background:#f3f4f6'>";
    foreach ($headers as $h) {
        echo "<th style='padding:6px 8px'>" . e($h) . "</th>";
    }
    echo "</tr>";

    if ($rows === []) {
        echo "<tr><td colspan='" . count($headers) . "' style='text-align:center;padding:12px'>Tidak ada data yang sesuai filter.</td></tr>";
    } else {
        foreach ($rows as $row) {
              $d1 = e((string) ($row['agenda_date'] ?? ''));
              $d2 = !empty($row['agenda_date_end']) ? e((string) $row['agenda_date_end']) : $d1;
              $displayDate = $d1 === $d2 ? $d1 : "$d1 s/d $d2";
              
              echo "<tr>";
              echo "<td style='padding:4px 8px'>" . $displayDate . "</td>";
            echo "<td style='padding:4px 8px'>" . e((string) $row['title']) . "</td>";
            echo "<td style='padding:4px 8px'>" . e($labelMap['entry_type'][$row['entry_type']] ?? (string) $row['entry_type']) . "</td>";
            echo "<td style='padding:4px 8px'>" . e($labelMap['direction'][$row['direction']] ?? (string) $row['direction']) . "</td>";
            echo "<td style='padding:4px 8px'>" . e((string) ($row['reference_number'] ?? '')) . "</td>";
            echo "<td style='padding:4px 8px'>" . e((string) ($row['counterparty'] ?? '')) . "</td>";
            echo "<td style='padding:4px 8px'>" . e($labelMap['status'][$row['status']] ?? (string) $row['status']) . "</td>";
            echo "<td style='padding:4px 8px'>" . e($labelMap['priority'][$row['priority']] ?? (string) $row['priority']) . "</td>";
            echo "<td style='padding:4px 8px'>" . e((string) ($row['location'] ?? '')) . "</td>";
            echo "<td style='padding:4px 8px'>" . e($labelMap['disposition_status'][$row['disposition_status']] ?? (string) ($row['disposition_status'] ?? '')) . "</td>";
            echo "<td style='padding:4px 8px'>" . e((string) ($row['created_by'] ?? '')) . "</td>";
            echo "</tr>";
        }
    }
    echo "</table></body></html>";
    exit;
}

// ── PDF (HTML print) ──────────────────────────────────────────────────────────
if ($type === 'pdf') {
    ?><!doctype html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Laporan Agenda Pimpinan</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 16px; }
            h1 { font-size: 16px; margin: 0 0 2px; }
            .meta { color: #6b7280; font-size: 11px; margin: 0 0 14px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: left; vertical-align: top; }
            th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; }
            tr:nth-child(even) { background: #f9fafb; }
            .noprint { margin-bottom: 12px; }
            @media print { .noprint { display: none; } body { margin: 0; } }
        </style>
    </head>
    <body>
        <div class="noprint">
            <button onclick="window.print()" style="padding:8px 20px;background:#4f46e5;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:bold">
                🖨 Cetak / Simpan PDF
            </button>
            <a href="export_laporan.php" style="margin-left:12px;font-size:13px;color:#6b7280">← Kembali</a>
        </div>
        <h1>Laporan Agenda Pimpinan</h1>
        <p class="meta">
            <?= e($instansi); ?> &nbsp;|&nbsp;
            Dicetak: <?= e(date('d-m-Y H:i')); ?>&nbsp;
            <?php if ($dateFrom || $dateTo): ?>
                | Filter: <?= e($dateFrom ?: '∞'); ?> s/d <?= e($dateTo ?: '∞'); ?>
            <?php endif; ?>
        </p>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?>
                        <th><?= e($h); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= count($headers); ?>" style="text-align:center;padding:16px;color:#9ca3af">Tidak ada data yang sesuai filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                          <?php
                          $d1e = e((string) ($row['agenda_date'] ?? ''));
                          $d2e = !empty($row['agenda_date_end']) ? e((string) $row['agenda_date_end']) : $d1e;
                          $displayDateHtml = $d1e === $d2e ? $d1e : "$d1e s/d $d2e";
                          ?>
                        <tr>
                            <td><?= $displayDateHtml; ?></td>
                            <td><?= e((string) $row['title']); ?></td>
                            <td><?= e($labelMap['entry_type'][$row['entry_type']] ?? (string) $row['entry_type']); ?></td>
                            <td><?= e($labelMap['direction'][$row['direction']] ?? (string) $row['direction']); ?></td>
                            <td><?= e((string) ($row['reference_number'] ?? '')); ?></td>
                            <td><?= e((string) ($row['counterparty'] ?? '')); ?></td>
                            <td><?= e($labelMap['status'][$row['status']] ?? (string) $row['status']); ?></td>
                            <td><?= e($labelMap['priority'][$row['priority']] ?? (string) $row['priority']); ?></td>
                            <td><?= e((string) ($row['location'] ?? '')); ?></td>
                            <td><?= e($labelMap['disposition_status'][$row['disposition_status']] ?? (string) ($row['disposition_status'] ?? '')); ?></td>
                            <td><?= e((string) ($row['created_by'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

redirect('export_laporan.php');
