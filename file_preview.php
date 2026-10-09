<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$returnTo = $_GET['return_to'] ?? 'agenda.php';
if (!in_array($returnTo, ['agenda.php', 'agenda_kegiatan.php', 'agenda_zoom.php', 'dashboard.php', 'surat_detail.php', 'surat_detail_v2.php', 'disposisi_form.php', 'index.php'], true)) {
    $returnTo = 'agenda.php';
}

if (!isLoggedIn()) {
    if ($returnTo !== 'index.php') {
        requireLogin();
    }
}

// Rate limit: 60 preview per menit per user.
$currUser = currentUser();
$identity = 'user:' . ($currUser ? (int)($currUser['id'] ?? 0) : (string)($_SERVER['REMOTE_ADDR'] ?? 'guest'));
if (!rateLimit('api_file_preview', $identity, 60, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo 'Terlalu banyak permintaan. Coba lagi nanti.';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$source = isset($_GET['source']) && $_GET['source'] === '1';
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

if ($id <= 0) {
    http_response_code(400);
    echo 'ID file tidak valid.';
    exit;
}

try {
    $pdo = getPdo();
    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, a.title, a.reference_number, a.entry_type, a.agenda_mode, a.source_agenda_id, a.attachment,
                s.title AS source_title, s.reference_number AS source_reference_number, s.attachment AS source_attachment
         FROM agendas a
         LEFT JOIN agendas s ON s.id = a.source_agenda_id
         WHERE a.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo 'Data tidak ditemukan.';
        exit;
    }

    $hasAccess = false;
    if (!isLoggedIn()) {
        if ($returnTo === 'index.php') {
            $hasAccess = true;
        }
    } elseif (isArsiparis() && ($row['entry_type'] ?? '') !== 'surat') {
        $hasAccess = false;
    } elseif (canManageLetterArchive((int) ($row['user_id'] ?? 0)) || isPimpinan()) {
        $hasAccess = true;
    } elseif (isPegawai()) {
        if (($row['entry_type'] ?? '') === 'kegiatan') {
            $hasAccess = true;
        } else {
            $chk = $pdo->prepare("SELECT 1 FROM agenda_disposition_items WHERE agenda_id IN (:aid, :source_aid) AND employee_id = :eid LIMIT 1");
            $chk->execute([
                'aid' => $id,
                'source_aid' => $row['source_agenda_id'] ?: 0,
                'eid' => $_SESSION['user']['employee_id'] ?? 0
            ]);
            if ($chk->fetchColumn()) {
                $hasAccess = true;
            }
        }
    }

    if (!$hasAccess) {
        http_response_code(403);
        echo 'Anda tidak memiliki akses ke dokumen ini.';
        exit;
    }

    $relativePath = $source ? (string) ($row['source_attachment'] ?? '') : (string) ($row['attachment'] ?? '');
    if ($relativePath === '') {
        // Fallback: jika target awal kosong, coba ambil lampiran alternatif (induk/turunan)
        $relativePath = (string) ($row['attachment'] ?? '') ?: (string) ($row['source_attachment'] ?? '');
    }
    if ($relativePath === '') {
        http_response_code(404);
        echo '<!doctype html><html lang="id"><head><meta charset="UTF-8"><title>Lampiran Tidak Tersedia</title><style>body{font-family:sans-serif;padding:40px;background:#0f172a;color:#f8fafc;text-align:center;}a{color:#38bdf8;text-decoration:none;font-weight:bold;}</style></head><body><h2>Lampiran Tidak Tersedia</h2><p>Surat atau agenda ini belum memiliki file lampiran naskah.</p><p style="color:#94a3b8;font-size:14px;">Jika seharusnya ada file, unggah lewat menu Edit pada surat/agenda terkait.</p><p><a href="' . e($returnTo) . '">&larr; Kembali</a></p></body></html>';
        exit;
    }

    $filename = '';
    $content = false;
    $mime = 'application/octet-stream';

    if (isGoogleDriveAttachment($relativePath)) {
        $driveFile = GoogleDriveStorage::download($relativePath);
        if ($driveFile !== null) {
            $mime = $driveFile['mime_type'] ?: 'application/octet-stream';
            $content = $driveFile['content'];
            $filename = $driveFile['name'];
        } else {
            http_response_code(502);
            echo '<!doctype html><html lang="id"><head><meta charset="UTF-8"><title>Gagal Membuka File</title><style>body{font-family:sans-serif;padding:40px;background:#0f172a;color:#f8fafc;text-align:center;}a{color:#38bdf8;text-decoration:none;font-weight:bold;}</style></head><body><h2>Gagal Mengambil File dari Google Drive</h2><p>' . e(GoogleDriveStorage::lastError()) . '</p><p><a href="' . e($returnTo) . '">&larr; Kembali</a></p></body></html>';
            exit;
        }
    } else {
        // 1. Cek file di disk lokal
        $baseDir = realpath(__DIR__ . '/uploads');
        $target = realpath(__DIR__ . '/' . ltrim($relativePath, '/\\'));
        if ($baseDir !== false && $target !== false && str_starts_with($target, $baseDir . DIRECTORY_SEPARATOR) && is_file($target)) {
            $mime = mime_content_type($target) ?: 'application/octet-stream';
            $content = file_get_contents($target);
            $filename = basename($target);
        } elseif (GoogleDriveStorage::isEnabled()) {
            // 2. Jika di lokal tidak ada, cari otomatis di Google Drive dengan multi-kriteria (Hash, Perihal, No Surat)
            $searchName = basename($relativePath);
            $keywords = [
                $searchName,
                (string)($row['title'] ?? ''),
                (string)($row['reference_number'] ?? ''),
                (string)($row['source_title'] ?? ''),
                (string)($row['source_reference_number'] ?? '')
            ];
            $driveSearch = GoogleDriveStorage::findFileByKeywords($keywords);
            if ($driveSearch !== null && !empty($driveSearch['id'])) {
                $driveFile = GoogleDriveStorage::downloadById($driveSearch['id']);
                if ($driveFile !== null) {
                    $mime = $driveFile['mime_type'] ?: 'application/octet-stream';
                    $content = $driveFile['content'];
                    $filename = $driveFile['name'];

                    // Update database otomatis
                    try {
                        $upStmt = $pdo->prepare("UPDATE agendas SET attachment = :gref WHERE attachment = :oldref");
                        $upStmt->execute(['gref' => 'gdrive:' . $driveSearch['id'], 'oldref' => $relativePath]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
    }

    if ($content === false) {
        http_response_code(404);
        echo '<!doctype html><html lang="id"><head><meta charset="UTF-8"><title>File Belum Tersedia</title><style>body{font-family:sans-serif;padding:40px;background:#0f172a;color:#f8fafc;text-align:center;}a{color:#38bdf8;text-decoration:none;font-weight:bold;}code{background:#1e293b;padding:3px 8px;border-radius:4px;color:#f59e0b;}</style></head><body><h2>File Lampiran Belum Ditemukan</h2><p>File <code>' . e(basename($relativePath)) . '</code> belum ditemukan di folder Google Drive Anda.</p><p><a href="' . e($returnTo) . '">&larr; Kembali</a></p></body></html>';
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><html lang="id"><head><meta charset="UTF-8"><title>Terjadi Kendala</title><style>body{font-family:sans-serif;padding:40px;background:#0f172a;color:#f8fafc;text-align:center;}a{color:#38bdf8;text-decoration:none;font-weight:bold;}</style></head><body><h2>Terjadi Kesalahan Saat Membuka Dokumen</h2><p>' . e($e->getMessage()) . '</p><p><a href="' . e($returnTo) . '">&larr; Kembali</a></p></body></html>';
    exit;
}

// 1. Jika mode raw streaming diminta (untuk embedding iframe / direct view)
if (isset($_GET['raw']) && $_GET['raw'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($mime ?: 'application/pdf'));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
    header('Content-Length: ' . strlen((string)$content));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

$rawUrl = 'file_preview.php?id=' . $id . ($source ? '&source=1' : '') . '&return_to=' . urlencode($returnTo) . '&raw=1';
$isPdf = ($mime === 'application/pdf') || str_ends_with(strtolower($filename), '.pdf');
$isImage = str_starts_with($mime, 'image/') || preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Lampiran - <?= e($filename); ?></title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e2e8f0; }
        .topbar { padding: 12px 16px; border-bottom: 1px solid #334155; display: flex; gap: 12px; align-items: center; justify-content: space-between; }
        .topbar-left { display: flex; gap: 12px; align-items: center; min-width: 0; }
        .name { font-size: 14px; opacity: .9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .back { background: #1e293b; color: #e2e8f0; border: 1px solid #475569; border-radius: 8px; padding: 8px 14px; cursor: pointer; font-size: 13px; text-decoration: none; font-weight: 600; white-space: nowrap; }
        .back:hover { background: #334155; }
        .btn-open { background: #0284c7; color: #fff; border: 1px solid #0369a1; }
        .btn-open:hover { background: #0369a1; }
        .wrap { height: <?= $embed ? '100vh' : 'calc(100vh - 58px)'; ?>; width: 100%; position: relative; }
        iframe, object, embed, img { width: 100%; height: 100%; border: none; display: block; object-fit: contain; background: #0b1220; }
        .unsupported { padding: 40px; text-align: center; }
        .btn { display: inline-block; margin-top: 12px; background: #4f46e5; color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: bold; }
        .btn:hover { background: #4338ca; }
    </style>
</head>
<body>
    <?php if (!$embed): ?>
        <div class="topbar">
            <div class="topbar-left">
                <a href="<?= e($returnTo); ?>" class="back" onclick="if (window.history.length > 1) { event.preventDefault(); window.history.back(); }">&larr; Kembali</a>
                <div class="name" title="<?= e($filename); ?>">📄 <?= e($filename); ?></div>
            </div>
            <div>
                <a href="<?= e($rawUrl); ?>" target="_blank" rel="noopener" class="back btn-open">Buka di Tab Baru ↗</a>
            </div>
        </div>
    <?php endif; ?>
    <div class="wrap">
        <?php if ($isPdf): ?>
            <object data="<?= e($rawUrl); ?>" type="application/pdf" width="100%" height="100%">
                <iframe src="<?= e($rawUrl); ?>" title="PDF Preview" width="100%" height="100%">
                    <div class="unsupported">
                        <h3>Pratinjau PDF</h3>
                        <p>Browser Anda tidak dapat menampilkan file PDF secara inline.</p>
                        <a class="btn" href="<?= e($rawUrl); ?>" target="_blank" rel="noopener">Klik di Sini untuk Membuka / Mengunduh Dokumen</a>
                    </div>
                </iframe>
            </object>
        <?php elseif ($isImage): ?>
            <img src="<?= e($rawUrl); ?>" alt="Preview Lampiran">
        <?php else: ?>
            <div class="unsupported">
                <h3>Format file tidak didukung untuk pratinjau inline.</h3>
                <p>Nama File: <?= e($filename); ?> (<?= e($mime); ?>)</p>
                <a class="btn" href="<?= e($rawUrl); ?>" target="_blank" rel="noopener">Unduh / Buka Dokumen</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
