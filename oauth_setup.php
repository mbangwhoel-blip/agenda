<?php
declare(strict_types=1);

// ============================================================
// oauth_setup.php — Hubungkan Google Drive via OAuth 2.0
//
// Penguatan alur OAuth:
// 1. State (client_id/secret/folder) disimpan DI SESSION + FILE
//    (storage/.oauth_setup_state.json — file dot, diblokir .htaccess).
//    Jika session hosting hilang saat kembali dari Google, callback
//    tetap bisa menyelesaikan pertukaran token dari file.
// 2. Callback error dari Google (?error=access_denied dkk) ditangani
//    dan dijelaskan penyebab + solusinya.
// 3. Panel status: tampilkan apakah refresh token sudah tersimpan di
//    .env, apakah .env bisa ditulis, plus tombol "Tes Koneksi".
// 4. Semua output dibuffer — header redirect tidak mungkin gagal.
// ============================================================

while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/google_drive_storage.php';

// Guard: hanya admin/sekretaris (file ini menulis .env).
if (!isLoggedIn()) {
    requireLogin();
}
if (!isAdmin() && !isSekretaris()) {
    http_response_code(403);
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo '<!doctype html><html lang="id"><body style="font-family:sans-serif;padding:40px;text-align:center"><h2>403 — Akses Ditolak</h2><p>Halaman setup Google Drive hanya untuk administrator/sekretaris.</p><p><a href="dashboard.php">Kembali ke dashboard</a></p></body></html>';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Deteksi protokol andal — mendukung hosting di belakang Cloudflare/proxy.
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
    || str_contains(strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? '')), '"scheme":"https"')
    || strtolower((string)($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on'
);
$protocol = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
$redirectUri = $protocol . '://' . $host . $uri;

$envPath = defined('ENV_FILE_PATH') && constant('ENV_FILE_PATH') ? constant('ENV_FILE_PATH') : __DIR__ . '/.env';

/** Redirect yang tidak mungkin gagal: header + meta-refresh + JS + link manual. */
function oauthRedirect(string $url): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . $url);
    echo '<!doctype html><html lang="id"><head><meta charset="UTF-8">'
        . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">'
        . '<title>Menghubungkan ke Google…</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:60px;background:#f8fafc">'
        . '<p style="font-size:16px">Mengalihkan ke Google…</p>'
        . '<script>try{location.replace(' . json_encode($url) . ');}catch(e){}</script>'
        . '<p style="margin-top:20px">Bila tidak otomatis berpindah, <a href="' . htmlspecialchars($url) . '" style="font-weight:bold">klik di sini</a>.</p>'
        . '</body></html>';
    exit;
}

/** POST ke endpoint token Google dengan CA-bundle fallback. */
function oauthTokenRequest(string $url, array $postFields): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $caBundle = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
    if (!$caBundle || !is_file($caBundle)) {
        foreach ([
            __DIR__ . '/app/cacert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            'C:/xampp/php/extras/ssl/cacert.pem',
        ] as $candidate) {
            if (is_file($candidate)) {
                $caBundle = $candidate;
                break;
            }
        }
    }
    if ($caBundle && is_file($caBundle)) {
        $opts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $opts);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = (string) curl_error($ch);
    curl_close($ch);
    return [$body, $status, $err];
}

/**
 * State OAuth disimpan ke file (backup session). File dot di storage/
 * otomatis diblokir akses web oleh aturan <FilesMatch "^\."> di .htaccess.
 * @return array{client_id:string,client_secret:string,folder_id:string,redirect_uri:string,ts:int}|null
 */
function oauthStateLoad(): ?array
{
    $f = __DIR__ . '/storage/.oauth_setup_state.json';
    if (!is_file($f)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($f), true);
    if (!is_array($data) || empty($data['client_id']) || empty($data['client_secret'])) {
        @unlink($f);
        return null;
    }
    if ((time() - (int) ($data['ts'] ?? 0)) > 900) {
        @unlink($f);
        return null;
    }
    return $data;
}

$clientId = trim((string)($_POST['client_id'] ?? env('GOOGLE_DRIVE_CLIENT_ID', '')));
$clientSecret = trim((string)($_POST['client_secret'] ?? env('GOOGLE_DRIVE_CLIENT_SECRET', '')));
$folderId = trim((string)($_POST['folder_id'] ?? env('GOOGLE_DRIVE_FOLDER_ID', '')));

$step = 'init';
$error = '';
$success = false;
$refreshToken = '';
$testResult = null;
$testError = '';
$envWritten = null;
$callbackSource = '';
$connectionTest = null;
$preparedAuthUrl = '';

// ── Callback error dari Google (mis. access_denied) ─────────────────────────
if (isset($_GET['error'])) {
    $step = 'error';
    $gerr = (string)$_GET['error'];
    $gdesc = (string)($_GET['error_description'] ?? '');
    if ($gerr === 'access_denied') {
        $error = 'Akses ditolak Google (access_denied). Penyebab paling umum: '
            . '(1) Anda menekan "Batal" di layar izin Google, atau '
            . '(2) OAuth consent screen masih berstatus <b>Testing</b> dan email Google Anda belum ditambahkan ke <b>Test users</b>. '
            . 'Solusi: buka <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank">OAuth consent screen</a> → '
            . 'bagian <b>Test users</b> → <b>+ Add users</b> → masukkan email Gmail Anda → ulangi koneksi dari awal.';
    } else {
        $error = 'Google mengirim error: ' . htmlspecialchars($gerr) . ($gdesc !== '' ? ' — ' . htmlspecialchars($gdesc) : '');
    }

// ── OAuth callback sukses (kode otorisasi) ──────────────────────────────────
} elseif (isset($_GET['code'])) {
    $code = trim((string)$_GET['code']);

    // State: session dulu, fallback file (session hosting bisa hilang).
    $state = null;
    $savedClientId = (string)($_SESSION['oauth_client_id'] ?? '');
    $savedClientSecret = (string)($_SESSION['oauth_client_secret'] ?? '');
    if ($savedClientId !== '' && $savedClientSecret !== '') {
        $state = [
            'client_id'     => $savedClientId,
            'client_secret' => $savedClientSecret,
            'folder_id'     => (string)($_SESSION['oauth_folder_id'] ?? ''),
            'redirect_uri'  => $redirectUri,
        ];
        $callbackSource = 'session';
    } else {
        $state = oauthStateLoad();
        $callbackSource = $state !== null ? 'file' : '';
    }

    if ($state === null) {
        $step = 'error';
        $error = 'Data setup (Client ID/Secret) tidak ditemukan saat kembali dari Google — sesi browser dan berkas state keduanya hilang/kedaluwarsa. '
            . 'Solusi: isi ulang form di bawah lalu klik "Hubungkan dengan Akun Google" lagi.';
    } else {
        $clientId = (string)$state['client_id'];
        $clientSecret = (string)$state['client_secret'];
        $folderId = (string)($state['folder_id'] ?? '');
        // redirect_uri HARUS sama persis dengan yang dipakai saat meminta izin.
        $callbackRedirectUri = (string)($state['redirect_uri'] ?? $redirectUri);

        [$body, $status, $curlErr] = oauthTokenRequest('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $callbackRedirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $json = json_decode($body, true);
        if ($status === 200 && is_array($json) && !empty($json['refresh_token'])) {
            $refreshToken = (string)$json['refresh_token'];
            $step = 'success';

            // Tulis .env bila bisa — dan catat hasilnya untuk ditampilkan.
            $envWritten = false;
            if (is_file($envPath) && is_writable($envPath)) {
                $content = (string)(file_get_contents($envPath) ?: '');
                $vars = [
                    'GOOGLE_DRIVE_ENABLED'       => 'true',
                    'GOOGLE_DRIVE_FOLDER_ID'     => $folderId,
                    'GOOGLE_DRIVE_CLIENT_ID'     => $clientId,
                    'GOOGLE_DRIVE_CLIENT_SECRET' => $clientSecret,
                    'GOOGLE_DRIVE_REFRESH_TOKEN' => $refreshToken,
                ];
                foreach ($vars as $k => $v) {
                    if (preg_match('/^' . preg_quote($k, '/') . '=.*$/m', $content)) {
                        $content = (string)preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', $k . '=' . $v, $content);
                    } else {
                        $content .= "\n" . $k . '=' . $v;
                    }
                }
                $envWritten = file_put_contents($envPath, $content) !== false;
            }

            // Terapkan untuk request ini lalu uji upload.
            $_ENV['GOOGLE_DRIVE_ENABLED'] = 'true';
            $_ENV['GOOGLE_DRIVE_FOLDER_ID'] = $folderId;
            $_ENV['GOOGLE_DRIVE_CLIENT_ID'] = $clientId;
            $_ENV['GOOGLE_DRIVE_CLIENT_SECRET'] = $clientSecret;
            $_ENV['GOOGLE_DRIVE_REFRESH_TOKEN'] = $refreshToken;
            putenv('GOOGLE_DRIVE_ENABLED=true');
            putenv('GOOGLE_DRIVE_FOLDER_ID=' . $folderId);
            putenv('GOOGLE_DRIVE_CLIENT_ID=' . $clientId);
            putenv('GOOGLE_DRIVE_CLIENT_SECRET=' . $clientSecret);
            putenv('GOOGLE_DRIVE_REFRESH_TOKEN=' . $refreshToken);

            $tmp = (string)tempnam(sys_get_temp_dir(), 'oauth_test_');
            file_put_contents($tmp, 'Test upload OAuth Google Drive pada ' . date('Y-m-d H:i:s'));
            $testResult = GoogleDriveStorage::upload($tmp, 'test_koneksi_oauth_' . date('Ymd_His') . '.txt', 'text/plain');
            if ($testResult === null) {
                $testError = GoogleDriveStorage::lastError();
            }
            @unlink($tmp);
        } else {
            $step = 'error';
            $gErrCode = (string)($json['error'] ?? '');
            if ($curlErr !== '') {
                $error = 'Gagal menghubungi server Google: ' . htmlspecialchars($curlErr);
            } elseif ($gErrCode === 'redirect_uri_mismatch') {
                $error = 'Redirect URI belum terdaftar di Google Cloud Console. Buka https://console.cloud.google.com/apis/credentials → OAuth client Anda → tambahkan persis: ' . htmlspecialchars($callbackRedirectUri);
            } elseif ($gErrCode === 'invalid_grant') {
                $error = 'Kode otorisasi sudah terpakai/kedaluwarsa. Silakan ulangi dari awal (isi form lalu klik Hubungkan).';
            } elseif ($gErrCode === 'invalid_client') {
                $error = 'Client ID atau Client Secret salah (secret mungkin sudah di-reset di Google Console). Salin ulang dari Credentials lalu coba lagi.';
            } elseif (is_array($json) && isset($json['error'])) {
                $error = 'Google menolak: ' . htmlspecialchars((string)($json['error_description'] ?? $gErrCode));
            } else {
                $error = 'Gagal mendapatkan refresh token dari Google [HTTP ' . $status . '].';
            }
        }

        // Bersihkan state (session + file) — kode otorisasi sekali pakai.
        unset($_SESSION['oauth_client_id'], $_SESSION['oauth_client_secret'], $_SESSION['oauth_folder_id']);
        @unlink(__DIR__ . '/storage/.oauth_setup_state.json');
    }

// ── Metode alternatif (GET ?prepare=1): simpan state dari .env lalu
//    TAMPILKAN link izin Google sebagai <a href> biasa. Tanpa form-POST,
//    tanpa header redirect — kebal terhadap mod_security / cache proxy
//    yang memblokir POST atau Location header.
} elseif (isset($_GET['prepare']) && $_GET['prepare'] === '1') {
    if ($clientId === '' || $clientSecret === '') {
        $error = 'Client ID / Client Secret belum ada di .env — isi form di bawah, simpan dulu ke .env, lalu buka metode ini lagi.';
    } elseif ($folderId === '') {
        $error = 'Folder ID belum ada di .env — isi di form bawah lalu ulangi.';
    } else {
        $_SESSION['oauth_client_id'] = $clientId;
        $_SESSION['oauth_client_secret'] = $clientSecret;
        $_SESSION['oauth_folder_id'] = $folderId;

        $stateDir = __DIR__ . '/storage';
        if (!is_dir($stateDir)) { @mkdir($stateDir, 0775, true); }
        @file_put_contents($stateDir . '/.oauth_setup_state.json', json_encode([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'folder_id'     => $folderId,
            'redirect_uri'  => $redirectUri,
            'ts'            => time(),
        ]));

        $preparedAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);
        $step = 'prepared';
    }

// ── Mulai OAuth: simpan state (session + file) lalu redirect ke Google ─────
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['start_oauth'])) {
    if ($clientId === '' || $clientSecret === '') {
        $error = 'Client ID dan Client Secret wajib diisi.';
    } elseif ($folderId === '') {
        $error = 'Google Drive Folder ID wajib diisi.';
    } else {
        $_SESSION['oauth_client_id'] = $clientId;
        $_SESSION['oauth_client_secret'] = $clientSecret;
        $_SESSION['oauth_folder_id'] = $folderId;

        // Backup state ke file — bertahan walau session hosting hilang.
        $stateDir = __DIR__ . '/storage';
        if (!is_dir($stateDir)) { @mkdir($stateDir, 0775, true); }
        @file_put_contents($stateDir . '/.oauth_setup_state.json', json_encode([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'folder_id'     => $folderId,
            'redirect_uri'  => $redirectUri,
            'ts'            => time(),
        ]));

        session_write_close();
        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);
        oauthRedirect($authUrl);
    }

// ── Tes koneksi kredensial yang SUDAH tersimpan di .env ────────────────────
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['test_connection'])) {
    $savedRefresh = trim((string)env('GOOGLE_DRIVE_REFRESH_TOKEN', ''));
    if ($savedRefresh === '') {
        $connectionTest = ['ok' => false, 'msg' => 'Belum ada refresh token di .env — hubungkan akun Google dulu (langkah 1–3 di bawah).'];
    } elseif (!GoogleDriveStorage::isEnabled()) {
        $connectionTest = ['ok' => false, 'msg' => 'GOOGLE_DRIVE_ENABLED tidak aktif di .env.'];
    } else {
        $tmp = (string)tempnam(sys_get_temp_dir(), 'conn_test_');
        file_put_contents($tmp, 'Tes koneksi Google Drive ' . date('Y-m-d H:i:s'));
        $r = GoogleDriveStorage::upload($tmp, 'tes_koneksi_' . date('Ymd_His') . '.txt', 'text/plain');
        @unlink($tmp);
        if ($r !== null) {
            $connectionTest = ['ok' => true, 'msg' => 'Terhubung! Upload uji berhasil ke Google Drive (file: ' . htmlspecialchars($r['name']) . ').'];
        } else {
            $connectionTest = ['ok' => false, 'msg' => 'Upload uji gagal: ' . htmlspecialchars(GoogleDriveStorage::lastError())
                . ' — bila error menyebut 404: Folder ID salah atau foldernya milik akun Google LAIN (hubungkan akun pemilik folder).'];
        }
    }
}

// Status koneksi saat ini (untuk panel).
$hasRefreshToken = trim((string)env('GOOGLE_DRIVE_REFRESH_TOKEN', '')) !== '';
$envWritableNow  = is_file($envPath) && is_writable($envPath);
$maskedToken = $hasRefreshToken ? substr(trim((string)env('GOOGLE_DRIVE_REFRESH_TOKEN', '')), 0, 8) . '…' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Drive OAuth 2.0 Setup (v3)</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 25px; background: #f8fafc; color: #1e293b; line-height: 1.6; max-width: 780px; margin: 0 auto; }
        .card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 20px; border: 1px solid #e2e8f0; }
        h1, h2, h3 { margin-top: 0; color: #0f172a; }
        .code-box { background: #0f172a; color: #f8fafc; padding: 14px; border-radius: 10px; font-family: monospace; font-size: 13px; overflow-x: auto; word-break: break-all; position: relative; }
        .copy-btn { position: absolute; top: 8px; right: 8px; background: #334155; color: #e2e8f0; border: 1px solid #475569; border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer; font-weight: 600; }
        .copy-btn:hover { background: #475569; }
        .input-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155; }
        input[type="text"] { width: 100%; box-sizing: border-box; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #0284c7; color: #fff; padding: 12px 20px; border-radius: 10px; font-weight: bold; font-size: 14px; text-decoration: none; border: none; cursor: pointer; }
        .btn:hover { background: #0369a1; }
        .btn-green { background: #059669; }
        .btn-green:hover { background: #047857; }
        .btn-sm { padding: 8px 14px; font-size: 13px; background: #475569; }
        .btn-sm:hover { background: #334155; }
        .step-num { display: inline-block; width: 24px; height: 24px; background: #e0f2fe; color: #0369a1; border-radius: 50%; text-align: center; font-weight: bold; line-height: 24px; font-size: 13px; margin-right: 6px; }
        .alert-err { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px; border-radius: 10px; margin-bottom: 16px; }
        .alert-ok { background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 14px; border-radius: 10px; margin-bottom: 16px; }
        .alert-warn { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 14px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        table.status { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.status td { padding: 6px 4px; border-bottom: 1px solid #f1f5f9; }
        table.status td:first-child { width: 220px; font-weight: 600; color: #475569; }
        .ok-text { color: #059669; font-weight: 700; }
        .bad-text { color: #dc2626; font-weight: 700; }
        ol, ul { font-size: 14px; padding-left: 20px; }
    </style>
</head>
<body>

<div class="card">
    <h1>🚀 Hubungkan Google Drive (OAuth 2.0)</h1>
    <p style="color: #64748b;">Gunakan akun Google pribadi Anda (<strong>15 GB Gratis</strong>) tanpa batasan kuota Service Account.
       <span style="font-size:12px;color:#94a3b8;">[versi halaman: v3 — bila tidak terlihat v3 di judul tab, file lama masih berjalan/browser cache; tekan Ctrl+F5]</span></p>

    <?php if ($step === 'success'): ?>
        <div class="alert-ok">
            <h3 style="margin-top:0;">🎉 Berhasil Menghubungkan Google Drive!</h3>
            <p>Refresh token diterima dari Google<?= $callbackSource === 'file' ? ' (state dipulihkan dari berkas cadangan — sesi browser sempat hilang)' : ''; ?>.</p>
            <?php if ($testResult !== null): ?>
                <p>✅ <strong>Uji Coba Upload Berhasil:</strong> File <code><?= htmlspecialchars($testResult['name']) ?></code> (ID: <code><?= htmlspecialchars($testResult['id']) ?></code>) tersimpan di Google Drive Anda!</p>
            <?php else: ?>
                <p style="color:#b45309;">⚠️ Token berhasil didapat, TETAPI uji upload gagal: <?= htmlspecialchars($testError) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($envWritten): ?>
            <div class="alert-ok">✅ Konfigurasi <strong>otomatis tersimpan</strong> ke file .env (<?= htmlspecialchars($envPath) ?>). Upload surat dengan lampiran sekarang langsung masuk ke Google Drive.</div>
        <?php else: ?>
            <div class="alert-err">
                <strong>⚠️ PENTING — .env belum diperbarui!</strong><br>
                File <code><?= htmlspecialchars($envPath) ?></code> tidak dapat ditulis otomatis (izin file / lokasi). Anda WAJIB menyalin konfigurasi di bawah ini <strong>manual</strong> ke file .env lewat File Manager hosting, kalau tidak koneksi akan hilang saat halaman ini ditutup.
            </div>
        <?php endif; ?>

        <h3>Konfigurasi .env:</h3>
        <div class="code-box">
            <button type="button" class="copy-btn" onclick="copyBox(this)">Copy</button>
            <span class="copy-target">GOOGLE_DRIVE_ENABLED=true
GOOGLE_DRIVE_FOLDER_ID=<?= htmlspecialchars($folderId) ?>
GOOGLE_DRIVE_CLIENT_ID=<?= htmlspecialchars($clientId) ?>
GOOGLE_DRIVE_CLIENT_SECRET=<?= htmlspecialchars($clientSecret) ?>
GOOGLE_DRIVE_REFRESH_TOKEN=<?= htmlspecialchars($refreshToken) ?></span>
        </div>

        <div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="gdrive_connect.php" class="btn btn-green">Halaman Uji Coba &rarr;</a>
            <form method="POST" action="oauth_setup.php" style="margin:0;">
                <button type="submit" name="test_connection" value="1" class="btn btn-sm">Tes Koneksi Sekarang</button>
            </form>
        </div>

    <?php else: ?>

        <?php if ($step === 'prepared'): ?>
            <div class="alert-ok">
                <h3 style="margin-top:0;">🔗 Link izin Google siap — klik di bawah ini</h3>
                <p>Metode alternatif aktif (tanpa tombol form). Klik link besar ini untuk membuka halaman izin Google, pilih email Anda, lalu tekan <strong>Allow</strong>:</p>
                <p style="margin:14px 0;">
                    <a href="<?= htmlspecialchars($preparedAuthUrl) ?>" style="display:inline-block;background:#059669;color:#fff;padding:14px 24px;border-radius:10px;font-weight:bold;font-size:15px;text-decoration:none;">Buka Halaman Izin Google &rarr;</a>
                </p>
                <p style="font-size:13px;color:#64748b;">Atau salin URL ini ke tab baru bila tombol atas tidak bisa diklik:</p>
                <div class="code-box" style="font-size:11px;">
                    <button type="button" class="copy-btn" onclick="copyBox(this)">Copy</button>
                    <span class="copy-target"><?= htmlspecialchars($preparedAuthUrl) ?></span>
                </div>
                <p style="font-size:13px;color:#64748b;margin-top:10px;">Setelah menekan <b>Allow</b>, Google akan mengembalikan Anda ke halaman ini dan token tersimpan otomatis.</p>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert-err"><strong>Gagal:</strong> <?= $error ?></div>
        <?php endif; ?>

        <?php if ($connectionTest !== null): ?>
            <div class="<?= $connectionTest['ok'] ? 'alert-ok' : 'alert-err' ?>">
                <?= $connectionTest['ok'] ? '✅' : '❌' ?> <strong>Hasil tes koneksi:</strong> <?= $connectionTest['msg'] ?>
            </div>
        <?php endif; ?>

        <h2 style="font-size:18px;">📋 Status Koneksi Saat Ini</h2>
        <table class="status">
            <tr><td>Akun Google terhubung?</td>
                <td><?= $hasRefreshToken ? '<span class="ok-text">SUDAH</span> <span style="color:#64748b">(token ' . htmlspecialchars($maskedToken) . ')</span>' : '<span class="bad-text">BELUM</span>' ?></td></tr>
            <tr><td>Client ID</td><td><?= htmlspecialchars($clientId !== '' ? $clientId : '— belum diisi —') ?></td></tr>
            <tr><td>Folder ID</td><td><?= htmlspecialchars($folderId !== '' ? $folderId : '— belum diisi —') ?></td></tr>
            <tr><td>File .env</td><td><?= is_file($envPath) ? htmlspecialchars($envPath) . ' — ' . ($envWritableNow ? '<span class="ok-text">dapat ditulis otomatis</span>' : '<span class="bad-text">TIDAK dapat ditulis (nanti disalin manual)</span>') : '<span class="bad-text">tidak ditemukan</span>' ?></td></tr>
        </table>
        <form method="POST" action="oauth_setup.php" style="margin: 10px 0 0;">
            <button type="submit" name="test_connection" value="1" class="btn btn-sm">Tes Koneksi (upload uji ke Drive)</button>
        </form>

        <hr style="border:0;border-top:1px solid #e2e8f0;margin:22px 0;">

        <h3><span class="step-num">1</span> Daftarkan Redirect URI di Google Cloud Console</h3>
        <ol>
            <li>Buka <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color: #0284c7; font-weight: bold;">Google Cloud Console &rarr; Credentials</a> <strong>dengan akun Google yang sama</strong>.</li>
            <li>Klik OAuth client ID Anda → bagian <strong>Authorized redirect URIs</strong> → <em>+ ADD URI</em> → paste URL ini <strong>persis sama</strong>:
                <div class="code-box" style="margin: 8px 0;">
                    <button type="button" class="copy-btn" onclick="copyBox(this)">Copy</button>
                    <span class="copy-target"><?= htmlspecialchars($redirectUri) ?></span>
                </div>
            </li>
        </ol>

        <div class="alert-warn">
            <strong>Wajib cek juga:</strong> buka <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank">OAuth consent screen</a> —
            bila <em>Publishing status</em> masih <strong>Testing</strong>, tambahkan email Google Anda ke <strong>Test users</strong>.
            Tanpa itu Google menolak dengan "access_denied" dan koneksi gagal diam-diam.
        </div>

        <h3><span class="step-num">2</span> Masukkan Client ID &amp; Secret di Bawah:</h3>
        <form method="POST" action="oauth_setup.php" onsubmit="var b=document.getElementById('btn-oauth'); if(b){b.textContent='Menghubungkan ke Google…';}">
            <div class="input-group">
                <label>Google Drive Folder ID:</label>
                <input type="text" name="folder_id" value="<?= htmlspecialchars($folderId) ?>" placeholder="ID folder Drive tujuan arsip" required>
            </div>
            <div class="input-group">
                <label>OAuth Client ID:</label>
                <input type="text" name="client_id" value="<?= htmlspecialchars($clientId) ?>" placeholder="Contoh: 1234567890-abcdef.apps.googleusercontent.com" required>
            </div>
            <div class="input-group">
                <label>OAuth Client Secret:</label>
                <input type="text" name="client_secret" value="<?= htmlspecialchars($clientSecret) ?>" placeholder="Contoh: GOCSPX-xxxxxxxxxxxxxxxxx" required>
            </div>
            <button type="submit" name="start_oauth" value="1" id="btn-oauth" class="btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/></svg>
                Hubungkan dengan Akun Google
            </button>
        </form>

        <div class="alert-warn" style="margin-top:18px;">
            <strong>Tombol di atas tidak merespons / "diam saja"?</strong>
            Gunakan metode alternatif tanpa tombol (bekerja walau POST diblokir mod_security hosting):
            <a href="oauth_setup.php?prepare=1" style="font-weight:bold;color:#0284c7;"><strong>&rarr; Buka metode alternatif (link izin langsung)</strong></a>
        </div>

    <?php endif; ?>
</div>

<script>
function copyBox(btn) {
    var el = btn.parentElement.querySelector('.copy-target');
    var text = el ? el.textContent.trim() : '';
    function done() { btn.textContent = 'Tersalin!'; setTimeout(function(){ btn.textContent = 'Copy'; }, 1500); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
    }
}
</script>

</body>
</html>
