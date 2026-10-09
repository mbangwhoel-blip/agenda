<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// ── Secret key auth (prefer Authorization: Bearer header, fallback ke GET untuk compat lama) ──
$provided = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $provided = trim($m[1]);
}
if ($provided === '') {
    // Fallback ke query string (DEPRECATED — akan dihapus di versi mendatang).
    $provided = trim((string) ($_GET['secret_key'] ?? ''));
}

try {
    $pdo        = getPdo();
    $storedKey  = getAppSetting('reminder_secret_key');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

if ($storedKey === '' || !hash_equals($storedKey, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — invalid secret_key']);
    exit;
}

// ── Find agendas due for H-1 reminder ─────────────────────────────────────────
try {
    $stmt = $pdo->query(
        "SELECT id, title, agenda_date, agenda_time, agenda_mode, location, zoom_link, counterparty
         FROM agendas
         WHERE entry_type = 'kegiatan'
           AND reminder_h1_enabled = 1
           AND reminder_h1_sent_at IS NULL
           AND reminder_h1_at IS NOT NULL
           AND reminder_h1_at <= NOW()
           AND status != 'selesai'
           AND (agenda_date > CURDATE() OR (agenda_date = CURDATE() AND COALESCE(agenda_time, '23:59:59') >= CURTIME()))"
    );
    $agendas = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

$sent  = 0;
$skipped = 0;

foreach ($agendas as $agenda) {
    $agendaId = (int) $agenda['id'];

    try {
        // 1. In-app notifications: all sekretaris and pimpinan
        $usersStmt = $pdo->query(
            "SELECT id FROM users WHERE role IN ('sekretaris', 'pimpinan')"
        );
        $targetUsers = $usersStmt->fetchAll();

        $dateLabel = date('d M Y', strtotime((string) $agenda['agenda_date']));
        $timeLabel = (string) ($agenda['agenda_time'] ?? '');
        $timeLabel = ($timeLabel !== '' && $timeLabel !== '00:00:00') ? ' pukul ' . substr($timeLabel, 0, 5) . ' WIB' : '';

        $notifMessage = "Pengingat H-1: {$agenda['title']} — {$dateLabel}{$timeLabel}";

        foreach ($targetUsers as $u) {
            createNotification((int) $u['id'], 'Pengingat Kegiatan H-1', $notifMessage, 'agenda_kegiatan.php');
        }

        // 2. WhatsApp for Zoom/hybrid agendas — send to all active employees with WA numbers
        $isZoom = in_array($agenda['agenda_mode'] ?? 'offline', ['zoom', 'hybrid'], true);
        if ($isZoom) {
            $empStmt = $pdo->query(
                "SELECT id, full_name, whatsapp_number FROM employees WHERE is_active = 1 AND whatsapp_number IS NOT NULL AND whatsapp_number != ''"
            );
            $employees = $empStmt->fetchAll();

            foreach ($employees as $emp) {
                $waMessage  = "🔔 *Pengingat Kegiatan Besok*\n\n";
                $waMessage .= "*{$agenda['title']}*\n";
                $waMessage .= "📅 {$dateLabel}{$timeLabel}\n";
                if (!empty($agenda['location'])) {
                    $waMessage .= "📍 {$agenda['location']}\n";
                }
                if (!empty($agenda['zoom_link'])) {
                    $waMessage .= "🔗 {$agenda['zoom_link']}\n";
                }
                $waMessage .= "\nMohon siapkan diri Anda. Terima kasih.";

                sendWhatsApp((string) $emp['whatsapp_number'], $waMessage);
            }
        }

        // 3. Mark as sent
        $upd = $pdo->prepare(
            "UPDATE agendas SET reminder_h1_sent_at = NOW() WHERE id = :id"
        );
        $upd->execute(['id' => $agendaId]);

        // 4. Log activity
        logActivity('reminder_h1_sent', "Reminder H-1 terkirim untuk agenda ID {$agendaId}: {$agenda['title']}", 'agenda', $agendaId);

        $sent++;
    } catch (Throwable $e) {
        error_log("Reminder H-1 gagal untuk agenda {$agendaId}: " . $e->getMessage());
        $skipped++;
    }
}

echo json_encode([
    'status'  => 'ok',
    'sent'    => $sent,
    'skipped' => $skipped,
    'checked' => count($agendas),
    'ts'      => date('Y-m-d H:i:s'),
]);
