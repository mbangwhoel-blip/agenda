<?php
@ini_set("display_errors", "1");
@ini_set("display_startup_errors", "1");
@ini_set("max_execution_time", "120");
@set_time_limit(120);
error_reporting(E_ALL);

require_once __DIR__ . "/app/helpers.php";
require_once __DIR__ . "/app/google_drive_storage.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi: hanya admin yang boleh akses halaman ini
requireLogin();
if (!isAdmin()) {
    setFlash('error', 'Halaman ini hanya dapat diakses oleh Admin.');
    redirect('dashboard.php');
}

$protocol = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || ($_SERVER["SERVER_PORT"] ?? "") == 443 ? "https" : "http";
$host = $_SERVER["HTTP_HOST"] ?? "localhost";
$uri = strtok($_SERVER["REQUEST_URI"] ?? "", "?");
$currentUrl = $protocol . "://" . $host . $uri;

$oauthSuccessMsg = "";
$oauthErrMsg = "";
$uploadMsg = "";
$uploadSuccess = false;
$syncMsg = "";
$syncDetails = [];
$driveFileList = [];
$autoContinue = isset($_POST["auto_continue"]) && $_POST["auto_continue"] === "1";

// Helper ekstraksi kata kunci signifikan (termasuk singkatan 3 huruf seperti PIB, UAT, SKI, UPT)
function extractKeywords(string $str): array {
    $clean = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $str));
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    $stopWords = ['dan', 'yang', 'untuk', 'pada', 'tentang', 'perihal', 'nomor', 'tahun', 'tgl', 'kepada', 'dari', 'oleh'];
    foreach ($words as $w) {
        if (strlen($w) >= 3 && !in_array($w, $stopWords, true)) {
            $out[] = $w;
        }
    }
    return array_unique($out);
}

// Hitung status file arsip di database & lokal
$pdo = getPdo();

if (isset($_GET['api']) && $_GET['api'] === 'file_status') {
    header('Content-Type: application/json; charset=utf-8');
    $allAtt = $pdo->query("SELECT id, title, reference_number, attachment FROM agendas WHERE attachment IS NOT NULL AND attachment != ''")->fetchAll(PDO::FETCH_ASSOC);
    $driveFiles = GoogleDriveStorage::isEnabled() ? GoogleDriveStorage::listFiles(5000) : [];
    echo json_encode([
        'agendas' => $allAtt,
        'drive_files' => $driveFiles,
    ]);
    exit;
}
$totalAttStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE attachment IS NOT NULL AND attachment != ''");
$totalAttCount = (int)$totalAttStmt->fetchColumn();

$gdriveAttStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE attachment LIKE 'gdrive:%'");
$gdriveAttCount = (int)$gdriveAttStmt->fetchColumn();

$localPendingStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE attachment IS NOT NULL AND attachment != '' AND attachment NOT LIKE 'gdrive:%'");
$localPendingCount = (int)$localPendingStmt->fetchColumn();

// Hitung file fisik yang ada di server hosting folder uploads/
$uploadsDir = __DIR__ . '/uploads';
$serverFilesCount = 0;
if (is_dir($uploadsDir)) {
    $scanned = scandir($uploadsDir);
    if (is_array($scanned)) {
        foreach ($scanned as $f) {
            if ($f !== '.' && $f !== '..' && is_file($uploadsDir . '/' . $f)) {
                $serverFilesCount++;
            }
        }
    }
}

// 1. Handle Uji Coba Upload Manual
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["run_upload_test"]) && GoogleDriveStorage::isEnabled()) {
    $tmpTestFile = tempnam(sys_get_temp_dir(), "gdrive_test_");
    file_put_contents($tmpTestFile, "Uji coba upload Google Drive Agenda pada " . date("Y-m-d H:i:s"));

    $result = GoogleDriveStorage::upload($tmpTestFile, "test_koneksi_" . date("Ymd_His") . ".txt", "text/plain");
    @unlink($tmpTestFile);

    if ($result !== null) {
        $uploadSuccess = true;
        $uploadMsg = "🎉 <strong>SUKSES!</strong> File test berhasil diunggah ke Google Drive.<br>"
            . "Nama File: <code>" . htmlspecialchars($result["name"]) . "</code><br>"
            . "File ID: <code>" . htmlspecialchars($result["id"]) . "</code>";
    } else {
        $uploadMsg = "❌ <strong>GAGAL:</strong> " . htmlspecialchars(GoogleDriveStorage::lastError());
    }
}

// 2. Handle Upload File ZIP / Komputer Langsung ke Drive
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["upload_batch_computer"]) && GoogleDriveStorage::isEnabled()) {
    $uploadedCount = 0;
    if (!empty($_FILES['manual_files']['name'][0])) {
        $fileCount = count($_FILES['manual_files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $tmpName = $_FILES['manual_files']['tmp_name'][$i];
            $origName = $_FILES['manual_files']['name'][$i];
            $error = $_FILES['manual_files']['error'][$i];

            if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($ext === 'zip' && class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($tmpName) === true) {
                        for ($z = 0; $z < $zip->numFiles; $z++) {
                            $stat = $zip->statIndex($z);
                            $entryName = basename($stat['name']);
                            if ($entryName === '' || str_starts_with($entryName, '.')) continue;

                            $stream = $zip->getStream($stat['name']);
                            if ($stream) {
                                $tmpPdf = tempnam(sys_get_temp_dir(), 'zip_pdf_');
                                file_put_contents($tmpPdf, stream_get_contents($stream));
                                fclose($stream);

                                $mime = mime_content_type($tmpPdf) ?: 'application/octet-stream';
                                $upRes = GoogleDriveStorage::upload($tmpPdf, $entryName, $mime);
                                @unlink($tmpPdf);
                                if ($upRes !== null) {
                                    $uploadedCount++;
                                }
                            }
                        }
                        $zip->close();
                    }
                } else {
                    $mime = mime_content_type($tmpName) ?: 'application/octet-stream';
                    $upRes = GoogleDriveStorage::upload($tmpName, $origName, $mime);
                    if ($upRes !== null) {
                        $uploadedCount++;
                    }
                }
            }
        }
        $uploadMsg = "🎉 <strong>Berhasil mengunggah {$uploadedCount} file dari komputer ke Google Drive!</strong> Sekarang klik tombol 'Cocokkan File yang Ada di Google Drive'.";
        $uploadSuccess = true;
    }
}

$unlinkedList = [];

// 3. Handle Lihat Daftar File di Drive
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["view_drive_files"]) && GoogleDriveStorage::isEnabled()) {
    $driveFileList = GoogleDriveStorage::listFiles(5000);
}

// 4. Handle Lihat Daftar Surat yang Belum Tersambung
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["view_unlinked"])) {
    $unlinkedStmt = $pdo->query("SELECT id, entry_type, direction, title, reference_number, letter_date, counterparty, attachment FROM agendas WHERE attachment IS NOT NULL AND attachment != '' AND attachment NOT LIKE 'gdrive:%' ORDER BY id DESC LIMIT 500");
    $unlinkedList = $unlinkedStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. Handle Sinkronisasi Arsip (Pencocokan Cerdas & Upload Batch)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["start_sync"]) && GoogleDriveStorage::isEnabled()) {
    try {
        $stmt = $pdo->query("SELECT id, title, reference_number, counterparty, attachment FROM agendas WHERE attachment IS NOT NULL AND attachment != '' AND attachment NOT LIKE 'gdrive:%'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil seluruh daftar file di Drive dalam 1 request dengan paginasi penuh hingga 5000 file
        $driveFiles = GoogleDriveStorage::listFiles(5000);

        $syncedCount = 0;
        $uploadedCount = 0;
        $failedCount = 0;
        $batchLimit = isset($_POST["upload_local"]) ? 20 : 0;

        foreach ($rows as $r) {
            $oldAtt = (string)$r['attachment'];
            $cleanName = basename($oldAtt);

            // A. Cek di daftar Google Drive dengan Multi-Kriteria Cerdas (Hash, No Surat, Digit Nomor, Kata Kunci Judul & Pengirim)
            $matched = null;
            $matchReason = '';

            // 1) Cek nama hash persis
            $cleanHash = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $cleanName));
            foreach ($driveFiles as $df) {
                $dfClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$df['name']));
                if ($cleanHash !== '' && str_contains($dfClean, $cleanHash)) {
                    $matched = $df;
                    $matchReason = 'Nama file lampiran cocok';
                    break;
                }
            }

            // 2) Cek nomor surat lengkap jika ada
            if ($matched === null && !empty($r['reference_number'])) {
                $cleanRef = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$r['reference_number']));
                if (strlen($cleanRef) >= 4) {
                    foreach ($driveFiles as $df) {
                        $dfClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$df['name']));
                        if (str_contains($dfClean, $cleanRef)) {
                            $matched = $df;
                            $matchReason = 'Nomor surat cocok: ' . $r['reference_number'];
                            break;
                        }
                    }
                }
            }

            // 3) Cek digit angka unik nomor surat (contoh: 2330, 3074, 3420)
            if ($matched === null && !empty($r['reference_number'])) {
                preg_match_all('/\d{3,6}/', (string)$r['reference_number'], $matches);
                if (!empty($matches[0])) {
                    foreach ($matches[0] as $digitBlock) {
                        // Abaikan tahun jika berdiri sendiri tanpa digit lain
                        if (in_array($digitBlock, ['2023', '2024', '2025', '2026', '2027'], true)) continue;
                        foreach ($driveFiles as $df) {
                            $dfName = (string)$df['name'];
                            if (str_contains($dfName, $digitBlock)) {
                                $matched = $df;
                                $matchReason = "Nomor urut surat (#{$digitBlock}) cocok pada file Drive: " . $df['name'];
                                break 2;
                            }
                        }
                    }
                }
            }

            // 4) Cek kata kunci perihal/judul surat + pengirim/penerima
            if ($matched === null && (!empty($r['title']) || !empty($r['counterparty']))) {
                $comboText = (string)$r['title'] . ' ' . (string)($r['counterparty'] ?? '');
                $keywords = extractKeywords($comboText);
                if (!empty($keywords)) {
                    foreach ($driveFiles as $df) {
                        $dfClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$df['name']));
                        $hitCount = 0;
                        foreach ($keywords as $kw) {
                            if (str_contains($dfClean, $kw)) {
                                $hitCount++;
                            }
                        }
                        // Jika cocok minimal 2 kata kunci unik atau 1 kata panjang (>= 7 huruf)
                        if ($hitCount >= 2 || ($hitCount >= 1 && count($keywords) === 1)) {
                            $matched = $df;
                            $matchReason = 'Perihal surat/pengirim cocok (' . $hitCount . ' kata kunci)';
                            break;
                        }
                    }
                }
            }

            if ($matched !== null) {
                $fileId = (string)$matched['id'];
                $up = $pdo->prepare("UPDATE agendas SET attachment = :gref WHERE id = :id");
                $up->execute(['gref' => 'gdrive:' . $fileId, 'id' => (int)$r['id']]);
                $syncedCount++;
                $syncDetails[] = "✅ ID #{$r['id']} (" . htmlspecialchars((string)$r['title']) . ") &rarr; Cocok: <code>" . htmlspecialchars($matched['name']) . "</code> ({$matchReason})";
                continue;
            }

            // B. Jika file ada di server lokal uploads/ dan upload diaktifkan
            $localPath = __DIR__ . '/' . ltrim($oldAtt, '/\\');
            if (is_file($localPath) && is_readable($localPath) && $uploadedCount < $batchLimit) {
                $mime = mime_content_type($localPath) ?: 'application/octet-stream';
                $uploaded = GoogleDriveStorage::upload($localPath, $cleanName, $mime);
                if ($uploaded !== null) {
                    $up = $pdo->prepare("UPDATE agendas SET attachment = :gref WHERE id = :id");
                    $up->execute(['gref' => 'gdrive:' . $uploaded['id'], 'id' => (int)$r['id']]);
                    $syncedCount++;
                    $uploadedCount++;
                    $syncDetails[] = "🚀 ID #{$r['id']} (" . htmlspecialchars((string)$r['title']) . ") &rarr; Berhasil diunggah dari server ke Google Drive (ID: {$uploaded['id']})";
                    continue;
                }
            }

            $failedCount++;
            if (!is_file($localPath)) {
                $syncDetails[] = "⚠️ ID #{$r['id']} (" . htmlspecialchars((string)$r['title']) . ") &rarr; Belum ditemukan di Google Drive maupun di folder uploads/ server.";
            } else {
                $syncDetails[] = "⏳ ID #{$r['id']} (" . htmlspecialchars((string)$r['title']) . ") &rarr; Ada di server, siap diunggah pada batch berikutnya.";
            }
        }

        // Propagasi Lintas Relasi & Nomor Surat Identik
        try {
            // Relasi Surat Induk <-> Turunan
            $pdo->exec("UPDATE agendas c JOIN agendas p ON c.source_agenda_id = p.id SET c.attachment = p.attachment WHERE p.attachment LIKE 'gdrive:%' AND (c.attachment NOT LIKE 'gdrive:%' OR c.attachment IS NULL)");
            $pdo->exec("UPDATE agendas p JOIN agendas c ON c.source_agenda_id = p.id SET p.attachment = c.attachment WHERE c.attachment LIKE 'gdrive:%' AND (p.attachment NOT LIKE 'gdrive:%' OR p.attachment IS NULL)");
            
            // Relasi Nomor Surat yang Sama
            $pdo->exec("UPDATE agendas a1 JOIN agendas a2 ON a1.reference_number = a2.reference_number SET a1.attachment = a2.attachment WHERE a1.reference_number IS NOT NULL AND a1.reference_number != '' AND a2.attachment LIKE 'gdrive:%' AND (a1.attachment NOT LIKE 'gdrive:%' OR a1.attachment IS NULL)");

            // Relasi Judul & Tanggal yang Sama
            $pdo->exec("UPDATE agendas a1 JOIN agendas a2 ON (a1.title = a2.title AND a1.agenda_date = a2.agenda_date) SET a1.attachment = a2.attachment WHERE a1.title IS NOT NULL AND a1.title != '' AND a2.attachment LIKE 'gdrive:%' AND (a1.attachment NOT LIKE 'gdrive:%' OR a1.attachment IS NULL)");
        } catch (\Throwable $e) {}

        // Refresh counter
        $localPendingStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE attachment IS NOT NULL AND attachment != '' AND attachment NOT LIKE 'gdrive:%'");
        $localPendingCount = (int)$localPendingStmt->fetchColumn();

        $gdriveAttStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE attachment LIKE 'gdrive:%'");
        $gdriveAttCount = (int)$gdriveAttStmt->fetchColumn();

        $syncMsg = "Proses selesai: <strong>{$syncedCount} file berhasil dihubungkan ke Google Drive</strong>."
            . ($localPendingCount > 0 ? " (Tersisa {$localPendingCount} file yang belum terhubung)" : " 🎉 Seluruh file telah terhubung ke Google Drive!");
    } catch (\Throwable $e) {
        $syncMsg = "Gagal sinkronisasi: " . htmlspecialchars($e->getMessage());
    }
}

// 4. Handle Exchange Code untuk Otorisasi OAuth
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["exchange_code"])) {
    $code = trim($_POST["code"] ?? "");
    $clientId = trim($_POST["client_id"] ?? "");
    $clientSecret = trim($_POST["client_secret"] ?? "");
    $folderId = trim($_POST["folder_id"] ?? "");
    if ($folderId === "") $folderId = "192rCF12w084haz0-Um_ugsidvSkKOov2";

    if ($code !== "" && $clientId !== "" && $clientSecret !== "") {
        $ch = curl_init("https://oauth2.googleapis.com/token");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                "code" => $code,
                "client_id" => $clientId,
                "client_secret" => $clientSecret,
                "redirect_uri" => $currentUrl,
                "grant_type" => "authorization_code",
            ]),
            CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
            CURLOPT_TIMEOUT => 20,
        ]);
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) { @curl_close($ch); }

        $json = json_decode((string)$res, true);
        if ($status === 200 && is_array($json) && !empty($json["refresh_token"])) {
            $refreshToken = (string)$json["refresh_token"];

            $envPath = defined("ENV_FILE_PATH") && constant("ENV_FILE_PATH") ? constant("ENV_FILE_PATH") : __DIR__ . "/.env";
            if (is_file($envPath) && is_writable($envPath)) {
                $content = file_get_contents($envPath) ?: "";
                $vars = [
                    "GOOGLE_DRIVE_ENABLED" => "true",
                    "GOOGLE_DRIVE_FOLDER_ID" => $folderId,
                    "GOOGLE_DRIVE_CLIENT_ID" => $clientId,
                    "GOOGLE_DRIVE_CLIENT_SECRET" => $clientSecret,
                    "GOOGLE_DRIVE_REFRESH_TOKEN" => $refreshToken,
                ];
                foreach ($vars as $k => $v) {
                    if (preg_match("/^" . preg_quote($k, "/") . "=.*$/m", $content)) {
                        $content = preg_replace("/^" . preg_quote($k, "/") . "=.*$/m", $k . "=" . $v, $content);
                    } else {
                        $content .= "\n" . $k . "=" . $v;
                    }
                }
                file_put_contents($envPath, $content);
            }

            putenv("GOOGLE_DRIVE_ENABLED=true");
            putenv("GOOGLE_DRIVE_FOLDER_ID=" . $folderId);
            putenv("GOOGLE_DRIVE_CLIENT_ID=" . $clientId);
            putenv("GOOGLE_DRIVE_CLIENT_SECRET=" . $clientSecret);
            putenv("GOOGLE_DRIVE_REFRESH_TOKEN=" . $refreshToken);
            $_ENV["GOOGLE_DRIVE_ENABLED"] = "true";
            $_ENV["GOOGLE_DRIVE_FOLDER_ID"] = $folderId;
            $_ENV["GOOGLE_DRIVE_CLIENT_ID"] = $clientId;
            $_ENV["GOOGLE_DRIVE_CLIENT_SECRET"] = $clientSecret;
            $_ENV["GOOGLE_DRIVE_REFRESH_TOKEN"] = $refreshToken;

            $oauthSuccessMsg = "Refresh Token berhasil diperbarui dan otomatis tersimpan ke file .env!";
        } else {
            $oauthErrMsg = $json["error_description"] ?? ($json["error"] ?? "Gagal mendapatkan Refresh Token dari Google.");
        }
    }
}

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Diagnostik & Setup Google Drive</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 25px; background: #f8fafc; color: #1e293b; line-height: 1.6; max-width: 900px; margin: 0 auto; }
        .card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 20px; border: 1px solid #e2e8f0; }
        h2, h3, h4 { margin-top: 0; color: #0f172a; }
        .badge-ok { background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 13px; }
        .badge-err { background: #fee2e2; color: #991b1b; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 13px; }
        .code { font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 13px; word-break: break-all; }
        .code-box { background: #0f172a; color: #f8fafc; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 13px; word-break: break-all; margin: 8px 0; }
        .success-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 16px; color: #15803d; margin-top: 15px; }
        .error-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 16px; color: #b91c1c; margin-top: 15px; }
        .oauth-box { background: #f8fafc; border: 2px dashed #0284c7; border-radius: 12px; padding: 20px; margin-top: 20px; }
        .sync-box { background: #f0fdf4; border: 2px solid #86efac; border-radius: 12px; padding: 20px; margin-top: 20px; }
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 15px 0; }
        .stat-card { background: #f1f5f9; padding: 12px; border-radius: 8px; text-align: center; }
        .stat-card strong { display: block; font-size: 20px; color: #0f172a; }
        .stat-card span { font-size: 11px; color: #64748b; }
        .input-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 5px; color: #334155; }
        input[type="text"] { width: 100%; box-sizing: border-box; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #0284c7; color: #fff; padding: 10px 18px; border-radius: 8px; font-weight: bold; font-size: 13px; text-decoration: none; border: none; cursor: pointer; }
        .btn:hover { background: #0369a1; }
        .btn-green { background: #059669; }
        .btn-green:hover { background: #047857; }
        .btn-indigo { background: #4f46e5; }
        .btn-indigo:hover { background: #4338ca; }
        .btn-amber { background: #d97706; }
        .btn-amber:hover { background: #b45309; }
        .btn-slate { background: #475569; }
        .btn-slate:hover { background: #334155; }
        .loading-box { display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 16px; color: #1e40af; margin-top: 15px; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 12px; text-align: left; }
        th { background: #f1f5f9; }
        .auto-loop-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px; margin-top: 15px; }
    </style>
</head>
<body>

<div class="card">
    <h2>🔍 Diagnostik & Setup Google Drive</h2>

    <div id="exchangeLoading" class="loading-box">
        <p><strong>⏳ Sedang memproses otorisasi Google dan menyimpan token... Mohon tunggu sebentar...</strong></p>
    </div>

    <?php if ($oauthSuccessMsg !== ""): ?>
        <div class="success-box">
            <strong>🎉 Sukses:</strong> <?= htmlspecialchars($oauthSuccessMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($oauthErrMsg !== ""): ?>
        <div class="error-box">
            <strong>❌ Gagal:</strong> <?= htmlspecialchars($oauthErrMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($uploadMsg !== ""): ?>
        <div class="<?= $uploadSuccess ? 'success-box' : 'error-box' ?>">
            <?= $uploadMsg ?>
        </div>
    <?php endif; ?>

    <?php if ($syncMsg !== ""): ?>
        <div class="success-box">
            <p><?= $syncMsg ?></p>
            <?php if (!empty($syncDetails)): ?>
                <ul style="font-size: 12px; max-height: 220px; overflow-y: auto; background: #fff; padding: 10px 25px; border-radius: 6px; border: 1px solid #bbf7d0;">
                    <?php foreach ($syncDetails as $dtl): ?>
                        <li><?= $dtl ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($autoContinue && $localPendingCount > 0 && isset($_POST["upload_local"])): ?>
        <div class="auto-loop-box">
            <p><strong>⚡ Otomatis Melanjutkan Batch Berikutnya... (Tersisa <?= $localPendingCount ?> file)</strong></p>
            <p style="font-size: 12px; color: #78350f;">Browser akan otomatis mengirim batch berikutnya dalam 1.5 detik. Anda bisa membatalkan kapan saja dengan menutup tab.</p>
            <form id="autoLoopForm" method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
                <input type="hidden" name="start_sync" value="1">
                <input type="hidden" name="upload_local" value="1">
                <input type="hidden" name="auto_continue" value="1">
                <button type="button" onclick="stopAutoLoop()" class="btn btn-slate" style="padding: 6px 12px; font-size: 12px;">⛔ Hentikan Auto-Upload</button>
            </form>
            <script>
                window.autoLoopTimer = setTimeout(function() {
                    document.getElementById('autoLoopForm').submit();
                }, 1500);
                function stopAutoLoop() {
                    clearTimeout(window.autoLoopTimer);
                    alert("Auto-Upload dihentikan.");
                }
            </script>
        </div>
    <?php endif; ?>

    <h3>1. Status Arsip Lampiran Surat:</h3>
    <div class="stat-grid">
        <div class="stat-card">
            <strong><?= $totalAttCount ?></strong>
            <span>Total Surat Bernaskah</span>
        </div>
        <div class="stat-card">
            <strong style="color: #059669;"><?= $gdriveAttCount ?></strong>
            <span>Tersambung di Google Drive</span>
        </div>
        <div class="stat-card">
            <strong style="color: #0284c7;"><?= $serverFilesCount ?></strong>
            <span>File di uploads/ Hosting</span>
        </div>
        <div class="stat-card">
            <strong style="color: #dc2626;"><?= $localPendingCount ?></strong>
            <span>Belum Tersambung</span>
        </div>
    </div>

    <h3>2. Nilai Konfigurasi .env:</h3>
    <?php
    $enabled = GoogleDriveStorage::isEnabled();
    echo "<p><strong>GOOGLE_DRIVE_ENABLED:</strong> " . ($enabled ? "<span class=\"badge-ok\">true (Aktif)</span>" : "<span class=\"badge-err\">false (Nonaktif)</span>") . "</p>";

    $currentFolderId = trim((string) env("GOOGLE_DRIVE_FOLDER_ID", ""));
    echo "<p><strong>GOOGLE_DRIVE_FOLDER_ID:</strong> " . ($currentFolderId !== "" ? "<span class=\"code\">" . htmlspecialchars($currentFolderId) . "</span>" : "<span class=\"badge-err\">KOSONG</span>") . "</p>";

    $curRefreshToken = trim((string) env("GOOGLE_DRIVE_REFRESH_TOKEN", ""));
    $curClientId = trim((string) env("GOOGLE_DRIVE_CLIENT_ID", ""));
    $usingOAuth = ($curRefreshToken !== "" && $curClientId !== "");

    if ($usingOAuth) {
        echo "<p><strong>Metode Otentikasi:</strong> <span class=\"badge-ok\">OAuth 2.0 (Akun Google Pribadi)</span></p>";
    } else {
        echo "<p><strong>Metode Otentikasi:</strong> Service Account</p>";
    }
    ?>

    <h3>3. Uji Coba Upload & Sinkronisasi:</h3>
    <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
            <button type="submit" name="run_upload_test" value="1" class="btn">
                🧪 Uji Coba Upload File Percobaan
            </button>
        </form>

        <?php if ($enabled): ?>
        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
            <input type="hidden" name="upload_local" value="1">
            <input type="hidden" name="auto_continue" value="1">
            <button type="submit" name="start_sync" value="1" class="btn btn-amber">
                ⚡ Upload Otomatis Semua File Server ke Drive (Auto-Loop)
            </button>
        </form>

        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
            <input type="hidden" name="upload_local" value="1">
            <button type="submit" name="start_sync" value="1" class="btn btn-green">
                🚀 Upload dari Folder Server (Batch 20 File)
            </button>
        </form>

        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
            <button type="submit" name="start_sync" value="1" class="btn btn-indigo">
                🔄 Cocokkan File yang Ada di Google Drive
            </button>
        </form>

        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
            <button type="submit" name="view_drive_files" value="1" class="btn btn-slate">
                📋 Lihat Daftar File di Google Drive
            </button>
        </form>

        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>">
            <button type="submit" name="view_unlinked" value="1" class="btn" style="background: #e11d48;">
                🔍 Lihat Daftar Surat yang Belum Tersambung
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($unlinkedList)): ?>
    <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 10px; padding: 15px; margin-bottom: 20px;">
        <h4 style="margin-top:0; color: #9f1239;">Daftar Surat / Agenda yang Belum Tersambung ke Google Drive (Total: <?= count($unlinkedList) ?> data):</h4>
        <div style="max-height: 300px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Jenis</th>
                        <th>No. Surat</th>
                        <th>Perihal / Judul</th>
                        <th>Pengirim / Penerima</th>
                        <th>Nama File Asal di Uploads</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unlinkedList as $u): ?>
                    <tr>
                        <td><strong>#<?= (int)$u['id'] ?></strong></td>
                        <td><span style="font-size:11px; padding: 2px 6px; border-radius: 4px; background: <?= $u['direction'] === 'keluar' ? '#fef3c7; color: #92400e;' : '#e0e7ff; color: #3730a3;' ?>"><?= htmlspecialchars(ucfirst((string)$u['direction'])) ?></span></td>
                        <td><code><?= htmlspecialchars((string)$u['reference_number']) ?></code></td>
                        <td><?= htmlspecialchars((string)$u['title']) ?></td>
                        <td><?= htmlspecialchars((string)($u['counterparty'] ?? '-')) ?></td>
                        <td><small><?= htmlspecialchars((string)$u['attachment']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($driveFileList)): ?>
    <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px; margin-bottom: 20px;">
        <h4 style="margin-top:0;">Daftar File yang Ditemukan di Google Drive (Total: <?= count($driveFileList) ?> file):</h4>
        <div style="max-height: 250px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama File di Google Drive</th>
                        <th>ID File Drive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($driveFileList as $idx => $f): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= htmlspecialchars($f['name']) ?></code></td>
                        <td><small><?= htmlspecialchars($f['id']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Kotak Upload ZIP / Multiple PDF dari Komputer -->
    <?php if ($enabled): ?>
    <div style="background: #faf5ff; border: 2px dashed #a855f7; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
        <h3 style="color: #7e22ce; margin-top: 0;">📦 Upload File ZIP / Banyak PDF Sekaligus dari Komputer</h3>
        <p style="font-size: 13px; color: #475569;">
            Jika Anda memiliki arsip file surat PDF lama di komputer/laptop Anda (baik berupa <strong>file .ZIP</strong> yang berisi kumpulan PDF atau <strong>banyak file .PDF sekaligus</strong>), Anda bisa langsung mengunggahnya di sini. Sistem akan otomatis memasukkannya ke Google Drive dan menghubungkannya:
        </p>
        <form method="POST" action="<?= htmlspecialchars($currentUrl) ?>" enctype="multipart/form-data">
            <div style="margin-bottom: 14px;">
                <input type="file" name="manual_files[]" multiple accept=".pdf,.zip,.jpg,.png,.doc,.docx" required style="width:100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;">
            </div>
            <button type="submit" name="upload_batch_computer" value="1" class="btn" style="background: #9333ea;">
                🚀 Upload File dari Komputer ke Google Drive & Sambungkan
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Kotak Hubungkan Ulang OAuth -->
    <div class="oauth-box">
        <h3 style="color: #0369a1;">🔑 Hubungkan Akun Google (Akses Lengkap Google Drive)</h3>
        <p style="font-size: 13px; color: #475569;">
            Pastikan Anda sudah mengklik tombol di bawah ini minimal 1 kali agar Google memberikan izin pembacaan file-file di Google Drive:
        </p>

        <p style="font-size: 13px;"><strong>Redirect URI yang terdaftar:</strong></p>
        <div class="code-box"><?= htmlspecialchars($currentUrl) ?></div>

        <form id="oauthForm" onsubmit="handleStartAuth(event)">
            <div class="input-group">
                <label>Folder ID Google Drive:</label>
                <input type="text" id="form_folder_id" value="<?= htmlspecialchars($currentFolderId !== "" ? $currentFolderId : "192rCF12w084haz0-Um_ugsidvSkKOov2") ?>" required>
            </div>

            <div class="input-group">
                <label>OAuth Client ID:</label>
                <input type="text" id="form_client_id" value="<?= htmlspecialchars($curClientId) ?>" placeholder="Contoh: 123456789-abcdef.apps.googleusercontent.com" required>
            </div>

            <div class="input-group">
                <label>OAuth Client Secret:</label>
                <input type="text" id="form_client_secret" placeholder="Contoh: GOCSPX-xxxxxxxxxxxx" required>
            </div>

            <button type="submit" class="btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/></svg>
                Hubungkan dengan Akun Google
            </button>
        </form>

        <form id="exchangeForm" method="POST" action="<?= htmlspecialchars($currentUrl) ?>" style="display:none;">
            <input type="hidden" name="exchange_code" value="1">
            <input type="hidden" name="code" id="ex_code">
            <input type="hidden" name="client_id" id="ex_client_id">
            <input type="hidden" name="client_secret" id="ex_client_secret">
            <input type="hidden" name="folder_id" id="ex_folder_id">
        </form>
    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const savedClientId = localStorage.getItem("gdrive_client_id");
    const savedClientSecret = localStorage.getItem("gdrive_client_secret");
    const savedFolderId = localStorage.getItem("gdrive_folder_id");

    const clientIdInput = document.getElementById("form_client_id");
    const clientSecretInput = document.getElementById("form_client_secret");
    const folderIdInput = document.getElementById("form_folder_id");

    if (clientIdInput && !clientIdInput.value && savedClientId) clientIdInput.value = savedClientId;
    if (clientSecretInput && !clientSecretInput.value && savedClientSecret) clientSecretInput.value = savedClientSecret;
    if (folderIdInput && !folderIdInput.value && savedFolderId) folderIdInput.value = savedFolderId;

    const urlParams = new URLSearchParams(window.location.search);
    const code = urlParams.get("code");
    if (code) {
        document.getElementById("exchangeLoading").style.display = "block";
        const cId = savedClientId || (clientIdInput ? clientIdInput.value : "");
        const cSecret = savedClientSecret || (clientSecretInput ? clientSecretInput.value : "");
        const fId = savedFolderId || (folderIdInput ? folderIdInput.value : "");

        if (cId && cSecret) {
            document.getElementById("ex_code").value = code;
            document.getElementById("ex_client_id").value = cId;
            document.getElementById("ex_client_secret").value = cSecret;
            document.getElementById("ex_folder_id").value = fId;
            document.getElementById("exchangeForm").submit();
        }
    }
});

function handleStartAuth(e) {
    e.preventDefault();
    const clientId = document.getElementById("form_client_id").value.trim();
    const clientSecret = document.getElementById("form_client_secret").value.trim();
    const folderId = document.getElementById("form_folder_id").value.trim();

    if (!clientId || !clientSecret) {
        alert("Mohon masukkan Client ID dan Client Secret terlebih dahulu.");
        return;
    }

    localStorage.setItem("gdrive_client_id", clientId);
    localStorage.setItem("gdrive_client_secret", clientSecret);
    localStorage.setItem("gdrive_folder_id", folderId);

    const redirectUri = window.location.origin + window.location.pathname;
    const authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" + new URLSearchParams({
        client_id: clientId,
        redirect_uri: redirectUri,
        response_type: "code",
        scope: "https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/drive.file",
        access_type: "offline",
        prompt: "consent"
    }).toString();

    window.location.href = authUrl;
}
</script>

</body>
</html>
