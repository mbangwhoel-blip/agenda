<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
// count = jumlah duplikat mingguan. Default 1. Maks 60 (lebih dari 1 tahun).
$count = isset($_GET['count']) ? max(1, min(60, (int) $_GET['count'])) : 1;
// weeks = lompatan minggu untuk mode tunggal (backward compat). Default 1.
$weeks = isset($_GET['weeks']) ? max(1, min(60, (int) $_GET['weeks'])) : 1;
$returnTo = $_GET['return_to'] ?? 'agenda_zoom.php';
if (!in_array($returnTo, ['agenda.php', 'agenda_zoom.php', 'agenda_kegiatan.php'], true)) {
    $returnTo = 'agenda_zoom.php';
}

if (!verify_csrf_token($_GET['token'] ?? '')) {
    setFlash('error', 'Token keamanan tidak valid. Silakan ulangi dari halaman sebelumnya.');
    redirect($returnTo);
}

if ($id <= 0) {
    setFlash('error', 'ID agenda tidak valid.');
    redirect($returnTo);
}

if (!isAdmin() && !isSekretaris()) {
    setFlash('error', 'Anda tidak punya akses menduplikasi agenda.');
    redirect($returnTo);
}

$pdo = getPdo();
$stmt = $pdo->prepare("SELECT * FROM agendas WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$src = $stmt->fetch();

if (!$src) {
    setFlash('error', 'Data agenda tidak ditemukan.');
    redirect($returnTo);
}

if (($src['entry_type'] ?? '') !== 'kegiatan') {
    setFlash('error', 'Hanya agenda kegiatan yang bisa diduplikasi.');
    redirect($returnTo);
}

if (!canManageAgenda((int) ($src['user_id'] ?? 0))) {
    setFlash('error', 'Anda tidak punya akses mengelola data ini.');
    redirect($returnTo);
}

$authUser = currentUser();

$insertSql = "INSERT INTO agendas
    (user_id, entry_type, agenda_mode, source_agenda_id, ai_generated, agenda_time,
     reminder_h1_enabled, reminder_h1_at, reminder_h1_sent_at, invitation_template,
     direction, title, reference_number, letter_date, recorded_date, counterparty,
     agenda_date, agenda_date_end, status, priority, location, zoom_link,
     zoom_meeting_id, zoom_passcode, attachment, description, notes)
    VALUES
    (:user_id, :entry_type, :agenda_mode, NULL, :ai_generated, :agenda_time,
     :reminder_h1_enabled, :reminder_h1_at, NULL, :invitation_template,
     :direction, :title, :reference_number, :letter_date, :recorded_date, :counterparty,
     :agenda_date, :agenda_date_end, 'draft', :priority, :location, :zoom_link,
     :zoom_meeting_id, :zoom_passcode, :attachment, :description, :notes)";

$stmtIns = $pdo->prepare($insertSql);
$baseTimePart = trim((string) ($src['agenda_time'] ?? ''));
if ($baseTimePart === '' || $baseTimePart === '00:00:00') {
    $baseTimePart = '09:00:00';
}
$baseDate = (string) $src['agenda_date'];
$baseDateEnd = !empty($src['agenda_date_end']) ? (string) $src['agenda_date_end'] : null;
$reminderEnabled = (int) ($src['reminder_h1_enabled'] ?? 1);
$created = 0;
$firstNewDate = null;
$lastNewDate = null;

$pdo->beginTransaction();
try {
    for ($i = 1; $i <= $count; $i++) {
        // Untuk mode tunggal (count=1), pakai lompatan $weeks. Untuk bulk, tiap minggu berturut.
        $step = ($count === 1) ? $weeks : $i;
        $offsetDays = $step * 7;

        $newDate = date('Y-m-d', strtotime("{$baseDate} +{$offsetDays} days"));
        $newDateEnd = $baseDateEnd ? date('Y-m-d', strtotime("{$baseDateEnd} +{$offsetDays} days")) : null;

        $reminderH1At = null;
        if ($reminderEnabled === 1) {
            $agendaDateTime = strtotime("{$newDate} {$baseTimePart}");
            if ($agendaDateTime !== false) {
                $reminderH1At = date('Y-m-d H:i:s', $agendaDateTime - 86400);
            }
        }

        $stmtIns->execute([
            'user_id'             => (int) ($authUser['id'] ?? $src['user_id']),
            'entry_type'          => 'kegiatan',
            'agenda_mode'         => (string) $src['agenda_mode'],
            'ai_generated'        => (int) ($src['ai_generated'] ?? 0),
            'agenda_time'         => $src['agenda_time'] ?? null,
            'reminder_h1_enabled' => $reminderEnabled,
            'reminder_h1_at'      => $reminderH1At,
            'invitation_template' => $src['invitation_template'] ?? null,
            'direction'           => (string) ($src['direction'] ?? 'masuk'),
            'title'               => (string) $src['title'],
            'reference_number'    => $src['reference_number'] ?? null,
            'letter_date'         => $src['letter_date'] ?? null,
            'recorded_date'       => date('Y-m-d'),
            'counterparty'        => $src['counterparty'] ?? null,
            'agenda_date'         => $newDate,
            'agenda_date_end'     => $newDateEnd,
            'priority'            => (string) ($src['priority'] ?? 'normal'),
            'location'            => $src['location'] ?? null,
            'zoom_link'           => $src['zoom_link'] ?? null,
            'zoom_meeting_id'     => $src['zoom_meeting_id'] ?? null,
            'zoom_passcode'       => $src['zoom_passcode'] ?? null,
            'attachment'          => $src['attachment'] ?? null,
            'description'         => $src['description'] ?? null,
            'notes'               => $src['notes'] ?? null,
        ]);

        if ($i === 1) $firstNewDate = $newDate;
        $lastNewDate = $newDate;
        $created++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    setFlash('error', 'Gagal menduplikasi agenda: ' . $e->getMessage());
    redirect($returnTo);
}

if ($created === 1) {
    setFlash('success', "Agenda diduplikasi ke " . date('d M Y', strtotime($firstNewDate)) . ".");
} else {
    setFlash('success', "{$created} agenda berhasil dibuat (mingguan): " . date('d M Y', strtotime($firstNewDate)) . " s/d " . date('d M Y', strtotime($lastNewDate)) . ".");
}
logActivity('duplicate_agenda', "Duplikasi agenda ID {$id} → {$created} copy ({$firstNewDate} s/d {$lastNewDate})", 'agenda', $id);
redirect($returnTo);
