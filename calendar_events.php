<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getPdo();

    $stmt = $pdo->query(
        "SELECT id, title, agenda_date, agenda_date_end, agenda_time, agenda_mode, location, zoom_link, status, entry_type
         FROM agendas
         WHERE entry_type IN ('kegiatan', 'agenda_zoom')
         ORDER BY agenda_date ASC, agenda_time ASC"
    );
    $rows = $stmt->fetchAll();
    foreach($rows as &$r) { $r['title'] = str_replace('Tindak Lanjut Surat: ', '', $r['title'] ?? ''); } unset($r);

    $events = [];
    foreach ($rows as $row) {
        $date = (string) ($row['agenda_date'] ?? '');
        if ($date === '') {
            continue;
        }

        // Build ISO start datetime
        $time = (string) ($row['agenda_time'] ?? '');
        $start = $date;
        if ($time !== '' && $time !== '00:00:00') {
            $start = $date . 'T' . substr($time, 0, 5);
        }

        $endDate = (string) ($row['agenda_date_end'] ?? '');
        $end = null;
        if ($endDate !== '') {
            if ($time !== '' && $time !== '00:00:00') {
                $end = $endDate . 'T23:59:00';
            } else {
                $endTs = strtotime($endDate);
                $end = date('Y-m-d', strtotime('+1 day', $endTs));
            }
        }

        // Color by mode
        $color = match ($row['agenda_mode'] ?? 'offline') {
            'zoom'   => '#4f46e5',  // indigo-600
            'hybrid' => '#d97706',  // amber-600
            default  => '#0d9488',  // teal-600
        };

        $event = [
            'id'    => (int) $row['id'],
            'title' => (string) $row['title'],
            'start' => $start,
            'color' => $color,
            'url'   => 'surat_detail_v2.php?id=' . (int) $row['id'],
            'extendedProps' => [
                'location'  => (string) ($row['location'] ?? ''),
                'mode'      => (string) ($row['agenda_mode'] ?? 'offline'),
                'time'      => ($time !== '' && $time !== '00:00:00') ? substr($time, 0, 5) : '',
                'zoom_link' => (string) ($row['zoom_link'] ?? ''),
                'status'    => (string) ($row['status'] ?? ''),
            ],
        ];
        if ($end !== null) {
            $event['end'] = $end;
        }
        $events[] = $event;
    }

    echo json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error'], JSON_THROW_ON_ERROR);
}
