<?php

declare(strict_types=1);

/**
 * Penyimpanan lampiran Google Drive memakai service account.
 *
 * Folder Drive harus dibagikan kepada alamat email service account sebagai Editor.
 * File tidak dibuat public; aplikasi mengambil file melalui API setelah otorisasi pengguna.
 */
final class GoogleDriveStorage
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_URL = 'https://www.googleapis.com/drive/v3';
    private static ?string $accessToken = null;
    private static int $accessTokenExpiresAt = 0;
    private static string $lastError = '';

    public static function isEnabled(): bool
    {
        return env_bool('GOOGLE_DRIVE_ENABLED', false);
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function isDriveReference(string $path): bool
    {
        return str_starts_with(trim($path), 'gdrive:');
    }

    public static function fileIdFromReference(string $path): ?string
    {
        if (!self::isDriveReference($path)) {
            return null;
        }

        $id = trim(substr(trim($path), strlen('gdrive:')));
        return preg_match('/^[A-Za-z0-9_-]{10,}$/', $id) === 1 ? $id : null;
    }

    /** @return array{reference:string,id:string,name:string,mime_type:string}|null */
    public static function upload(string $sourcePath, string $originalName, string $mimeType): ?array
    {
        self::$lastError = '';
        if (!self::isEnabled()) {
            self::$lastError = 'Google Drive belum diaktifkan.';
            return null;
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            self::$lastError = 'File upload tidak dapat dibaca.';
            return null;
        }

        $folderId = trim((string) env('GOOGLE_DRIVE_FOLDER_ID', ''));
        if ($folderId === '') {
            self::$lastError = 'GOOGLE_DRIVE_FOLDER_ID belum diisi.';
            return null;
        }

        $token = self::accessToken();
        if ($token === null) {
            return null;
        }

        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($originalName)) ?: 'surat';
        $metadata = json_encode([
            'name' => date('Ymd_His') . '_' . $safeName,
            'parents' => [$folderId],
        ], JSON_UNESCAPED_SLASHES);
        $content = file_get_contents($sourcePath);
        if ($metadata === false || $content === false) {
            self::$lastError = 'File upload tidak dapat diproses.';
            return null;
        }

        $boundary = 'agenda-drive-' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . 'Content-Type: ' . $mimeType . "\r\n"
            . "Content-Transfer-Encoding: binary\r\n\r\n"
            . $content . "\r\n--{$boundary}--\r\n";

        $response = self::request(
            'POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType',
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
            $body
        );
        if ($response === null || empty($response['json']['id'])) {
            return null;
        }

        $id = (string) $response['json']['id'];
        return [
            'reference' => 'gdrive:' . $id,
            'id' => $id,
            'name' => (string) ($response['json']['name'] ?? $safeName),
            'mime_type' => (string) ($response['json']['mimeType'] ?? $mimeType),
        ];
    }

    /** @return array{content:string,name:string,mime_type:string}|null */
    public static function download(string $reference): ?array
    {
        self::$lastError = '';
        $fileId = self::fileIdFromReference($reference);
        if ($fileId === null) {
            self::$lastError = 'Referensi file Google Drive tidak valid.';
            return null;
        }
        $token = self::accessToken();
        if ($token === null) {
            return null;
        }

        $metadata = self::request('GET', self::API_URL . '/files/' . rawurlencode($fileId) . '?fields=name,mimeType', [
            'Authorization: Bearer ' . $token,
        ]);
        $file = self::request('GET', self::API_URL . '/files/' . rawurlencode($fileId) . '?alt=media', [
            'Authorization: Bearer ' . $token,
        ], null, false);
        if ($file === null) {
            return null;
        }

        return [
            'content' => $file['body'],
            'name' => (string) ($metadata['json']['name'] ?? ('surat_' . $fileId)),
            'mime_type' => (string) ($metadata['json']['mimeType'] ?? ($file['content_type'] ?: 'application/octet-stream')),
        ];
    }

    /** @return array{content:string,name:string,mime_type:string}|null */
    public static function downloadById(string $fileId): ?array
    {
        return self::download('gdrive:' . $fileId);
    }

    /**
     * Cari file di Google Drive berdasarkan nama file (misal surat_66d5...pdf)
     * @return array{id:string,name:string,mime_type:string}|null
     */
    public static function findFileByName(string $filename): ?array
    {
        self::$lastError = '';
        if (!self::isEnabled()) {
            return null;
        }
        $token = self::accessToken();
        if ($token === null) {
            return null;
        }

        $cleanName = basename($filename);
        $folderId = trim((string) env('GOOGLE_DRIVE_FOLDER_ID', ''));

        $safeQueryName = str_replace("'", "\\'", $cleanName);
        $q = "trashed = false";
        if ($folderId !== '') {
            $q .= " and '{$folderId}' in parents";
        }
        $q .= " and name contains '{$safeQueryName}'";

        $url = self::API_URL . '/files?' . http_build_query([
            'q' => $q,
            'fields' => 'files(id, name, mimeType)',
            'pageSize' => 10,
        ]);

        $res = self::request('GET', $url, ['Authorization: Bearer ' . $token]);
        if ($res !== null && !empty($res['json']['files']) && is_array($res['json']['files'])) {
            $first = $res['json']['files'][0];
            return [
                'id' => (string) $first['id'],
                'name' => (string) ($first['name'] ?? $cleanName),
                'mime_type' => (string) ($first['mimeType'] ?? 'application/octet-stream'),
            ];
        }

        return null;
    }

    /**
     * Ambil daftar seluruh file di folder Google Drive (dengan dukungan paginasi hingga 3000+ file)
     * @return array<array{id:string,name:string,mime_type:string}>
     */
    public static function listFiles(int $limit = 5000): array
    {
        self::$lastError = '';
        if (!self::isEnabled()) {
            return [];
        }
        $token = self::accessToken();
        if ($token === null) {
            return [];
        }
        $out = [];
        $seenIds = [];
        $pageToken = null;

        // Ambil seluruh file di Google Drive (termasuk semua subfolder)
        do {
            $params = [
                'q' => "mimeType != 'application/vnd.google-apps.folder' and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType)',
                'pageSize' => 1000,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $url = self::API_URL . '/files?' . http_build_query($params);
            $res = self::request('GET', $url, ['Authorization: Bearer ' . $token]);
            if ($res === null || empty($res['json']['files']) || !is_array($res['json']['files'])) {
                break;
            }

            foreach ($res['json']['files'] as $f) {
                $fId = (string) $f['id'];
                if (!isset($seenIds[$fId])) {
                    $seenIds[$fId] = true;
                    $out[] = [
                        'id' => $fId,
                        'name' => (string) ($f['name'] ?? ''),
                        'mime_type' => (string) ($f['mimeType'] ?? 'application/octet-stream'),
                    ];
                }
                if (count($out) >= $limit) {
                    break 2;
                }
            }

            $pageToken = $res['json']['nextPageToken'] ?? null;
        } while ($pageToken !== null && count($out) < $limit);

        return $out;
    }

    /**
     * Cari file di Google Drive berdasarkan daftar kata kunci (Hash, Judul Surat, Nomor Surat)
     * @param string[] $keywords
     * @return array{id:string,name:string,mime_type:string}|null
     */
    public static function findFileByKeywords(array $keywords): ?array
    {
        self::$lastError = '';
        if (!self::isEnabled()) {
            return null;
        }
        $driveFiles = self::listFiles(3000);
        if (empty($driveFiles)) {
            return null;
        }

        $cleanKeywords = [];
        foreach ($keywords as $kw) {
            $k = trim((string)$kw);
            if ($k !== '') {
                $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $k));
                if (strlen($clean) >= 4) {
                    $cleanKeywords[] = $clean;
                }
                if (strlen($clean) > 15) {
                    $cleanKeywords[] = substr($clean, 0, 15);
                }
            }
        }

        foreach ($driveFiles as $df) {
            $dfClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$df['name']));
            foreach ($cleanKeywords as $kw) {
                if (str_contains($dfClean, $kw)) {
                    return [
                        'id' => (string)$df['id'],
                        'name' => (string)$df['name'],
                        'mime_type' => (string)($df['mime_type'] ?? 'application/octet-stream'),
                    ];
                }
            }
        }

        return null;
    }

    public static function delete(string $reference): bool
    {
        self::$lastError = '';
        $fileId = self::fileIdFromReference($reference);
        if ($fileId === null) {
            return false;
        }
        $token = self::accessToken();
        if ($token === null) {
            return false;
        }

        return self::request('DELETE', self::API_URL . '/files/' . rawurlencode($fileId), [
            'Authorization: Bearer ' . $token,
        ], null, false) !== null;
    }

    /** @return array{body:string,json:array<string,mixed>,content_type:string}|null */
    private static function request(string $method, string $url, array $headers, ?string $body = null, bool $decodeJson = true): ?array
    {
        if (!function_exists('curl_init')) {
            self::$lastError = 'Ekstensi PHP cURL diperlukan untuk Google Drive.';
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $caBundle = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (!$caBundle || !is_file($caBundle)) {
            foreach ([
                'C:/xampp/php/extras/ssl/cacert.pem',
                'C:/xampp/apache/bin/curl-ca-bundle.crt',
                'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem',
            ] as $candidate) {
                if (is_file($candidate)) {
                    $caBundle = $candidate;
                    break;
                }
            }
        }
        if ($caBundle && is_file($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            @curl_close($ch);
        }

        if (!is_string($response) || $status < 200 || $status >= 300) {
            $errDetail = '';
            if (is_string($response)) {
                $errJson = json_decode($response, true);
                if (isset($errJson['error']['message'])) {
                    $errDetail = ' (' . $errJson['error']['message'] . ')';
                }
            }
            // Google menghapus kuota storage Service Account (2022): upload via
            // service account ke folder My Drive selalu ditolak 403. Solusinya
            // adalah OAuth akun Google biasa (kuota 15GB) via oauth_setup.php.
            if ($status === 403 && stripos($errDetail, 'storage quota') !== false) {
                self::$lastError = 'Service Account Google tidak punya kuota penyimpanan (kebijakan Google). Jalankan oauth_setup.php di browser untuk menghubungkan akun Google pribadi (15GB gratis) sebagai penyimpanan Drive.';
                return null;
            }
            self::$lastError = $error !== '' ? $error : ('Google Drive menolak permintaan [Status ' . $status . ']' . $errDetail);
            return null;
        }
        $json = $decodeJson ? json_decode($response, true) : [];
        return ['body' => $response, 'json' => is_array($json) ? $json : [], 'content_type' => $contentType];
    }

    private static function accessToken(): ?string
    {
        if (self::$accessToken !== null && self::$accessTokenExpiresAt > time() + 60) {
            return self::$accessToken;
        }

        // Cek konfigurasi OAuth
        $refreshToken = trim((string) env('GOOGLE_DRIVE_REFRESH_TOKEN', ''));
        $clientId = trim((string) env('GOOGLE_DRIVE_CLIENT_ID', ''));
        $clientSecret = trim((string) env('GOOGLE_DRIVE_CLIENT_SECRET', ''));

        // Metode 1: OAuth 2.0 Refresh Token (hanya jika semua konfigurasi lengkap)
        if ($refreshToken !== '' && $clientId !== '' && $clientSecret !== '') {
            $payload = http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
            $response = self::request('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $payload);
            if ($response === null || empty($response['json']['access_token'])) {
                $errorDetail = self::$lastError ?: 'Invalid Refresh Token';
                // Cek jika error mengandung invalid_grant, berikan petunjuk lebih jelas
                if (stripos($errorDetail, 'invalid_grant') !== false || stripos($errorDetail, '400') !== false) {
                    self::$lastError = 'Refresh token Google Drive sudah tidak valid/expired. <a href="gdrive_connect.php" class="font-semibold underline">Klik di sini untuk hubungkan ulang akun Google &rarr;</a>';
                } else {
                    self::$lastError = 'Gagal memperbarui token OAuth Google Drive: ' . $errorDetail;
                }
                return null;
            }
            self::$accessToken = (string) $response['json']['access_token'];
            self::$accessTokenExpiresAt = time() + max(60, (int) ($response['json']['expires_in'] ?? 3600));
            return self::$accessToken;
        }

        // Metode 2: Service Account JWT (Untuk Google Workspace Shared Drives)
        $credentials = self::credentials();
        if ($credentials === null) {
            return null;
        }
        if (!function_exists('openssl_sign')) {
            self::$lastError = 'Ekstensi OpenSSL diperlukan untuk Google Drive.';
            return null;
        }

        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '{}');
        $claims = self::base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        $unsigned = $header . '.' . $claims;
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            self::$lastError = 'Gagal menandatangani kredensial Google Drive.';
            return null;
        }
        $assertion = $unsigned . '.' . self::base64Url($signature);
        $payload = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        $response = self::request('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $payload);
        if ($response === null || empty($response['json']['access_token'])) {
            return null;
        }
        self::$accessToken = (string) $response['json']['access_token'];
        self::$accessTokenExpiresAt = $now + max(60, (int) ($response['json']['expires_in'] ?? 3600));
        return self::$accessToken;
    }

    /** @return array{client_email:string,private_key:string}|null */
    private static function credentials(): ?array
    {
        $rawPath = trim((string) env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON_PATH', ''));
        $rawPath = str_replace(["\r", "\n", '"', "'"], '', $rawPath);

        $path = $rawPath;
        if ($path === '' || !is_file($path)) {
            // Check relative to project root
            $rootPath = dirname(__DIR__) . '/' . ltrim($rawPath, '/\\');
            if (is_file($rootPath)) {
                $path = $rootPath;
            } elseif (is_file(__DIR__ . '/' . basename($rawPath))) {
                // Check inside app/ directly
                $path = __DIR__ . '/' . basename($rawPath);
            } elseif (is_file(__DIR__ . '/google-service-account.json')) {
                $path = __DIR__ . '/google-service-account.json';
            }
        }

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            self::$lastError = 'File kunci service account tidak ditemukan atau tidak dapat dibaca di: ' . ($rawPath ?: '(kosong)');
            return null;
        }

        $content = (string) file_get_contents($path);
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            self::$lastError = 'Isi file JSON kredensial service account tidak valid atau rusak.';
            return null;
        }
        return ['client_email' => (string) $data['client_email'], 'private_key' => (string) $data['private_key']];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
