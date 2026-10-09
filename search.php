<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

// Rate limit: 30 pencarian per menit per user.
$identity = 'user:' . (currentUser()['id'] ?? 0);
if (!rateLimit('api_search', $identity, 30, 60)) {
    respondRateLimited(60);
}

$q = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($q) < 3) {
    echo json_encode(['surat' => [], 'pegawai' => []], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo    = getPdo();
    $param  = '%' . $q . '%';

    // Search agendas
    $suratStmt = $pdo->prepare(
        "SELECT id, title, reference_number, direction, entry_type,
                COALESCE(letter_date, recorded_date, agenda_date) AS tanggal,
                counterparty
         FROM agendas
         WHERE title LIKE :q1 OR reference_number LIKE :q2 OR counterparty LIKE :q3
         ORDER BY COALESCE(letter_date, recorded_date, agenda_date) DESC
         LIMIT 10"
    );
    $suratStmt->execute(['q1' => $param, 'q2' => $param, 'q3' => $param]);
    $suratRows = $suratStmt->fetchAll();

    $suratResult = [];
    foreach ($suratRows as $row) {
        $suratResult[] = [
            'id'               => (int) $row['id'],
            'title'            => (string) $row['title'],
            'reference_number' => (string) ($row['reference_number'] ?? ''),
            'direction'        => (string) ($row['direction'] ?? ''),
            'entry_type'       => (string) ($row['entry_type'] ?? ''),
            'tanggal'          => $row['tanggal'] ? date('d/m/Y', strtotime((string) $row['tanggal'])) : '',
            'counterparty'     => (string) ($row['counterparty'] ?? ''),
            'url'              => 'surat_detail_v2.php?id=' . (int) $row['id'],
        ];
    }

    // Search employees
    $pegawaiStmt = $pdo->prepare(
        "SELECT id, full_name, nip, position_title, unit_name
         FROM employees
         WHERE full_name LIKE :q1 OR nip LIKE :q2 OR position_title LIKE :q3
         ORDER BY full_name ASC
         LIMIT 10"
    );
    $pegawaiStmt->execute(['q1' => $param, 'q2' => $param, 'q3' => $param]);
    $pegawaiRows = $pegawaiStmt->fetchAll();

    $pegawaiResult = [];
    foreach ($pegawaiRows as $row) {
        $pegawaiResult[] = [
            'id'             => (int) $row['id'],
            'full_name'      => (string) $row['full_name'],
            'nip'            => (string) ($row['nip'] ?? ''),
            'position_title' => (string) ($row['position_title'] ?? ''),
            'unit_name'      => (string) ($row['unit_name'] ?? ''),
            'url'            => 'master_pegawai_form.php?id=' . (int) $row['id'],
        ];
    }

    echo json_encode(
        ['surat' => $suratResult, 'pegawai' => $pegawaiResult],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error'], JSON_THROW_ON_ERROR);
}
