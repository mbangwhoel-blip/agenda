<?php

declare(strict_types=1);

/**
 * API publik REST sederhana untuk Agenda Pimpinan.
 *
 * Endpoint:
 *   GET  /api.php?action=agendas&type=surat&limit=20&offset=0
 *   GET  /api.php?action=agendas&type=kegiatan&limit=20&offset=0
 *   GET  /api.php?action=agenda&id=123
 *   GET  /api.php?action=stats
 *
 * Autentikasi: Authorization: Bearer <api_key>
 *   API key dibuat admin via settings (app_settings table, key='api_key').
 *
 * Rate limit: 100 req/menit per API key.
 */

require_once __DIR__ . '/app/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Autentikasi via Bearer token ──
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'database_unavailable']);
    exit;
}

$validKey = (string) getAppSetting('api_key', '');
if ($validKey === '' || !hash_equals($validKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'message' => 'API key tidak valid.']);
    exit;
}

// ── Rate limit ──
if (!rateLimit('api_public', 'key:' . substr(md5($apiKey), 0, 16), 100, 60)) {
    respondRateLimited(60);
}

// ── Routing ──
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'agendas':
            $type = $_GET['type'] ?? 'surat';
            if (!in_array($type, ['surat', 'kegiatan', 'zoom'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_type']);
                exit;
            }
            $limit = min(max((int)($_GET['limit'] ?? 20), 1), 100);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            $sql = "SELECT id, title, reference_number, direction, agenda_date, agenda_time, agenda_mode, location, status, zoom_link
                    FROM agendas WHERE entry_type = :type";
            if ($type === 'zoom') {
                $sql .= " AND (agenda_mode IN ('zoom','hybrid') OR zoom_link IS NOT NULL AND zoom_link != '')";
            }
            $sql .= " ORDER BY agenda_date DESC, id DESC LIMIT :lim OFFSET :off";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':type', $type === 'zoom' ? 'kegiatan' : $type);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            echo json_encode(['data' => $rows, 'count' => count($rows), 'limit' => $limit, 'offset' => $offset]);
            break;

        case 'agenda':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_id']);
                exit;
            }
            $stmt = $pdo->prepare('SELECT * FROM agendas WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'not_found']);
                exit;
            }
            echo json_encode(['data' => $row]);
            break;

        case 'stats':
            $stats = $pdo->query("
                SELECT
                    SUM(CASE WHEN direction = 'masuk' THEN 1 ELSE 0 END) as total_masuk,
                    SUM(CASE WHEN direction = 'keluar' THEN 1 ELSE 0 END) as total_keluar,
                    SUM(CASE WHEN entry_type = 'kegiatan' THEN 1 ELSE 0 END) as total_kegiatan,
                    COUNT(*) as total
                FROM agendas
            ")->fetch();
            echo json_encode(['data' => $stats]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'unknown_action', 'available' => ['agendas', 'agenda', 'stats']]);
    }
} catch (Throwable $e) {
    error_log('[api.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal_error']);
}
