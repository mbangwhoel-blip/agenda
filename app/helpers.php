<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$secretsFile = __DIR__ . '/../config/secrets.php';
// File secrets tidak ikut tersimpan di Git/deployment. Aplikasi tetap dapat
// berjalan dengan nilai fallback/env bila file ini belum tersedia di hosting.
if (is_file($secretsFile)) {
    require_once $secretsFile;
}
require_once __DIR__ . '/../config/env.php';

// ==========================================
// SESSION HARDENING & INITIALIZATION
// ==========================================
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    if (PHP_SAPI !== 'cli') {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off')
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'))
        );

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_strict_mode', '1');
    }

    session_start();
}
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/google_drive_storage.php';

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

// ==========================================
// SMTP MAIL SERVER CONFIGURATION
// ==========================================
// Secret dimuat dari config/secrets.php (lihat file tersebut).
// Jangan hardcode secret di sini.
if (!defined('SMTP_HOST'))       define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT'))       define('SMTP_PORT', 587);
if (!defined('SMTP_SECURE'))     define('SMTP_SECURE', 'tls');
if (!defined('SMTP_USER'))       define('SMTP_USER', '');
if (!defined('SMTP_PASS'))       define('SMTP_PASS', '');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'no-reply@localhost');
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME', 'Agenda Pimpinan');
if (!defined('RESEND_API_KEY'))  define('RESEND_API_KEY', '');

/**
 * Pengirim Email SMTP Socket Murni (Tanpa Library Vendor/Composer)
 * Sangat handal, mandiri, dan otomatis menulis arsip lokal ke uploads/debug_emails/ untuk kemudahan pengujian offline.
 */
function smtpSendMail(string $to, string $subject, string $htmlMessage): bool
{
    // 1. Selalu tulis salinan email ke folder lokal uploads/debug_emails/ untuk kebutuhan pengujian/debug offline
    try {
        $debugDir = __DIR__ . '/../uploads/debug_emails';
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0777, true);
        }
        $filename = $debugDir . '/email_' . time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $to) . '.html';
        file_put_contents($filename, $htmlMessage);
    } catch (Throwable $e) {
        // Abaikan jika folder write terhambat izin
    }

    // 2. Jika menggunakan Resend API
    $resendApiKey = getAppSetting('resend_api_key') ?: (defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
    $fromEmail = getAppSetting('smtp_user')      ?: (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '');
    $fromName  = getAppSetting('smtp_from_name') ?: (defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME : 'Agenda Pimpinan');

    if (!empty($resendApiKey)) {
        // Menggunakan email resmi yang disetel di konfigurasi atas karena DNS sudah Verified
        $resendFromEmail = $fromEmail; 
        
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'from' => $fromName . ' <' . $resendFromEmail . '>',
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlMessage
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $resendApiKey,
            'Content-Type: application/json'
        ]);
        // Verifikasi SSL wajib aktif untuk mencegah MITM yang bisa membocorkan API key.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Sediakan CA bundle default sistem; di Windows/XAMPP biasanya sudah dikonfigurasi di php.ini (curl.cainfo).
        $caBundle = ini_get('curl.cainfo');
        if ($caBundle && is_file($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }
        $errorMessage = "Resend API Error ({$httpCode}): ";
        $errorMessage .= $response ? $response : ($curlError ?: 'Unknown Error');
        $_SESSION['smtp_error'] = $errorMessage;
        return false;
    }

    $host      = getAppSetting('smtp_host')      ?: (defined('SMTP_HOST')       ? SMTP_HOST      : 'smtp.gmail.com');
    $port      = (int)(getAppSetting('smtp_port') ?: (defined('SMTP_PORT')       ? SMTP_PORT      : 587));
    $user      = getAppSetting('smtp_user')      ?: (defined('SMTP_USER')       ? SMTP_USER      : '');
    $pass      = getAppSetting('smtp_password')  ?: (defined('SMTP_PASS')       ? SMTP_PASS      : '');
    $secure    = getAppSetting('smtp_secure')    ?: (defined('SMTP_SECURE')     ? SMTP_SECURE    : 'tls');

    // 2. Jika konfigurasi SMTP kosong, otomatis fallback ke fungsi mail() bawaan PHP
    if (empty($user) || empty($pass)) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>'
        ];
        if (function_exists('mail')) {
            return @mail($to, $subject, $htmlMessage, implode("\r\n", $headers));
        }
        return false;
    }

    // 3. Pengiriman SMTP Direct via fsockopen
    try {
        $socketHost = ($secure === 'ssl') ? 'ssl://' . $host : $host;
        $socket = @fsockopen($socketHost, $port, $errno, $errstr, 12);
        if (!$socket) {
            throw new Exception("Koneksi socket gagal: " . $errstr);
        }

        $readResponse = static function ($socket) {
            $response = '';
            while ($str = fgets($socket, 515)) {
                $response .= $str;
                if (substr($str, 3, 1) === ' ') {
                    break;
                }
            }
            return $response;
        };

        $readResponse($socket); // read greeting

        fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
        $readResponse($socket);

        if ($secure === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $res = $readResponse($socket);
            if (str_starts_with($res, '220')) {
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("Handshake enkripsi TLS gagal");
                }
                fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
                $readResponse($socket);
            }
        }

        fwrite($socket, "AUTH LOGIN\r\n");
        $readResponse($socket);

        fwrite($socket, base64_encode($user) . "\r\n");
        $readResponse($socket);

        fwrite($socket, base64_encode($pass) . "\r\n");
        $authRes = $readResponse($socket);
        if (!str_starts_with($authRes, '235')) {
            throw new Exception("Autentikasi SMTP Gagal: " . trim($authRes));
        }

        fwrite($socket, "MAIL FROM: <{$fromEmail}>\r\n");
        $readResponse($socket);

        fwrite($socket, "RCPT TO: <{$to}>\r\n");
        $readResponse($socket);

        fwrite($socket, "DATA\r\n");
        $readResponse($socket);

        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "From: =?utf-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
            "To: <{$to}>",
            "Subject: =?utf-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "Content-Transfer-Encoding: 8bit",
            "",
            $htmlMessage,
            "."
        ];

        fwrite($socket, implode("\r\n", $headers) . "\r\n");
        $res = $readResponse($socket);

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return str_starts_with($res, '250');
    } catch (Throwable $e) {
        error_log("Gagal SMTP: " . $e->getMessage());
        return false;
    }
}

/**
 * Mendapatkan IP klien sebenarnya, mendukung Cloudflare, reverse proxy, dan load balancer
 */
function getClientIp(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function isPublicAgendaTitle(string $title, string $context = ''): bool
{
    $lower = mb_strtolower($title);
    $ctxLower = mb_strtolower($context);
    $combined = $lower . ' ' . $ctxLower;

    // Jika mengandung sinyal Zoom atau kredensial meeting, selalu tampil
    if (
        str_contains($combined, 'zoom')
        || str_contains($combined, 'meeting id')
        || str_contains($combined, 'passcode')
        || preg_match('/\b(us[0-9oO]{2}web\.zoom\.us|zoom\.us\/j\/)/i', $combined)
    ) {
        return true;
    }

    // Nota Dinas & Surat Tugas: selalu tampil di agenda publik (aturan
    // operator) — override kata lain di judul yang biasanya memblokir.
    // Jika judul atau konteks memuat Nota Dinas dengan tulisan "agenda", selalu tampil.
    $hasNotaDinas = str_contains($lower, 'nota dinas') || str_contains($ctxLower, 'nota dinas');
    $hasSuratTugas = str_contains($lower, 'surat tugas') || str_contains($ctxLower, 'surat tugas');
    if ($hasSuratTugas || str_contains($lower, 'nota dinas') || ($hasNotaDinas && str_contains($combined, 'agenda'))) {
        return true;
    }

    // Sinyal kuat acara/pertemuan: jika judul mengandung jenis acara,
    // jangan diblokir hanya karena topik pembahasannya administratif
    $isEventTitle = (bool) preg_match('/\b(undangan|rapat|webinar|sosialisasi|workshop|seminar|sidang|briefing|pertemuan|koordinasi|diskusi|bimtek|pelatihan|audiensi|forum|konsultasi|evaluasi)\b/i', $lower);

    // Pola yang TIDAK PERNAH tampil jika surat murni administratif
    $blocked = [
        'pemberitahuan',
        'permohonan',
        'permintaan',
        'keputusan', 'ketetapan', 'penetapan',
        'pemutakhiran',
        'surat edaran', 'surat instruksi', 'surat keterangan',
        'surat pengantar', 'surat kuasa', 'surat mandat', 'surat dispensasi',
        'nota kesepahaman', 'perjanjian kerjasama', 'mou', 'pks',
        'kartu ucapan', 'ucapan selamat',
        'sop ', 'pedoman', 'panduan', 'juknis', 'jukpel', 'petunjuk teknis',
        'standar pelayanan', 'standar operasional', 'prosedur',
        'formulir', 'template', 'blangko', 'kuesioner',
        'laporan', 'rekapitulasi', 'absensi',
        'pengumuman',
        'surat usulan', 'usulan', 'arahan',
    ];

    foreach ($blocked as $pattern) {
        if (str_contains($lower, $pattern)) {
            if ($isEventTitle && (str_contains($lower, 'undangan') || str_contains($lower, 'rapat') || str_contains($lower, 'webinar') || str_contains($lower, 'sosialisasi'))) {
                continue;
            }
            return false;
        }
    }

    // "SK" sebagai kata terpisah
    if (preg_match('/\bsk\b/i', $title) === 1 && !$isEventTitle) {
        return false;
    }

    return true;
}

/**
 * Apakah URL ini layak tampil sebagai link meeting di agenda Zoom publik?
 */
function isPublicMeetingLink(?string $url, string $context = ''): bool
{
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }

    // Normalisasi typo OCR pada Zoom URL
    $url = preg_replace('/us[oO](\d)web\.zoom\.us/i', 'us0$1web.zoom.us', $url);
    $url = preg_replace('/\s+/', '', $url);

    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    // Domain meeting langsung — selalu OK
    foreach (['zoom.us', 'meet.google.com', 'teams.microsoft.com', 'teams.live.com', 'webex.com', 'gotomeet.me', 'gotomeeting.com'] as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return true;
        }
    }

    // Shortlink umum yang lazim dipakai untuk meeting di flyer
    foreach (['bit.ly', 'tinyurl.com', 't.ly', 'rb.gy', 'shorturl.at', 'cutt.ly', 's.id', 's.shopee.co.id'] as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return true;
        }
    }

    // Shortlink pemerintah: butuh bukti konteks
    $isGovShortlink = $host === 's.kemkes.go.id' || str_ends_with($host, '.s.kemkes.go.id')
        || $host === 's.kemenkeu.go.id' || str_ends_with($host, '.s.kemenkeu.go.id');
    if (!$isGovShortlink) {
        return false;
    }

    $ctx = mb_strtolower($context);

    // Sinyal NON-meeting: landing page registrasi/e-learning/upload/dokumen.
    // Dicek lebih dulu — kalau ada, link pasti bukan untuk join meeting.
    $nonMeetingSignals = [
        'upload', 'unggah', 'pengumpulan', 'pengiriman berkas', 'pengiriman dokumen', 'pengiriman laporan',
        'daftar', 'pendaftaran', 'registrasi', 'form', 'formulir',
        'kirim', 'submit', 'artikel', 'majalah',
        'pemutakhiran', 'presensi', 'rekaman', 'paparan', 'panduan',
    ];
    foreach ($nonMeetingSignals as $sig) {
        if (str_contains($ctx, $sig)) {
            return false;
        }
    }

    // Sinyal meeting eksplisit di konteks.
    // 'agenda' termasuk: nota dinas yang menyebut "agenda" + tautan
    // pemerintah dianggap acara daring (aturan operator) — konsisten
    // dengan sinyal di pipeline AI (isMeetingUrlWithContext).
    $meetingSignals = [
        'zoom', 'rapat', 'meeting', 'daring', 'online', 'webinar',
        'telekonferensi', 'vidcon', 'video conference', 'teleconference',
        'sosialisasi', 'pertemuan', 'koordinasi', 'diskusi', 'briefing',
        'agenda', 'pelatihan', 'workshop', 'mooc', 'e-learning', 'elearning', 'kursus', 'bimtek', 'bimbingan teknis',
    ];
    foreach ($meetingSignals as $sig) {
        if (str_contains($ctx, $sig)) {
            return true;
        }
    }

    // Default: shortlink pemerintah tanpa sinyal apa pun → anggap bukan meeting
    return false;
}

function redirect(string $url): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header("Location: {$url}");
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function agendaStats(PDO $pdo): array
{
    $stats = [];
    $queries = [
        'total' => 'SELECT COUNT(*) FROM agendas',
        'masuk' => "SELECT COUNT(*) FROM agendas WHERE direction = 'masuk'",
        'keluar' => "SELECT COUNT(*) FROM agendas WHERE direction = 'keluar'",
        'surat' => "SELECT COUNT(*) FROM agendas WHERE entry_type = 'surat'",
        'kegiatan' => "SELECT COUNT(*) FROM agendas WHERE entry_type = 'kegiatan'",
        'bulan_ini' => "SELECT COUNT(*) FROM agendas WHERE agenda_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND agenda_date < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH",
    ];

    foreach ($queries as $key => $sql) {
        $stats[$key] = (int) $pdo->query($sql)->fetchColumn();
    }

    return $stats;
}

function currentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (is_array($user) && !empty($user['id'])) {
        $user['full_name'] = $user['full_name'] ?? 'Pegawai';
        $user['role'] = $user['role'] ?? 'pegawai';
        return $user;
    }
    return null;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function requireLogin(?string $customMessage = null): void
{
    if (!currentUser()) {
        setFlash('error', $customMessage ?? 'Silakan login terlebih dahulu.');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $redirectParam = '';
        if ($uri !== '' && !str_contains($uri, 'login.php') && !str_contains($uri, 'logout.php')) {
            $redirectParam = '?redirect=' . urlencode($uri);
        }
        redirect('login.php' . $redirectParam);
    }
}

function isAdmin(): bool
{
    $user = currentUser();
    return (bool) ($user && ($user['role'] ?? '') === 'admin');
}

function isSekretaris(): bool
{
    $user = currentUser();
    return (bool) ($user && ($user['role'] ?? '') === 'sekretaris');
}

function isPimpinan(): bool
{
    $user = currentUser();
    return (bool) ($user && ($user['role'] ?? '') === 'pimpinan');
}

function canApproveDisposisi(): bool
{
    return isAdmin() || isPimpinan();
}

function canManageEmployees(): bool
{
    return isAdmin();
}

function isPegawai(): bool
{
    $user = currentUser();
    return (bool) ($user && ($user['role'] ?? '') === 'pegawai');
}

function isArsiparis(): bool
{
    $user = currentUser();
    return (bool) ($user && ($user['role'] ?? '') === 'arsiparis');
}

function canManageAgenda(?int $ownerId = null): bool
{
    if (isAdmin() || isSekretaris()) {
        return true;
    }

    $user = currentUser();
    if (!$user || $ownerId === null) {
        return false;
    }

    return (int) $user['id'] === $ownerId;
}

function canManageLetterArchive(?int $ownerId = null): bool
{
    return isAdmin() || isSekretaris() || isArsiparis() || canManageAgenda($ownerId);
}

function safelyDeleteUploadFile(string $relativePath): void
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return;
    }

    if (GoogleDriveStorage::isDriveReference($relativePath)) {
        GoogleDriveStorage::delete($relativePath);
        return;
    }

    $baseDir = realpath(__DIR__ . '/../uploads');
    $target = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));

    if ($baseDir === false || $target === false) {
        return;
    }

    if (!str_starts_with($target, $baseDir . DIRECTORY_SEPARATOR)) {
        return;
    }

    if (is_file($target)) {
        @unlink($target);
    }
}

function isGoogleDriveAttachment(string $attachment): bool
{
    return GoogleDriveStorage::isDriveReference($attachment);
}

function attachmentStorageLabel(string $attachment): string
{
    return isGoogleDriveAttachment($attachment) ? 'Google Drive' : 'Penyimpanan lokal';
}

function deleteAttachmentIfUnused(PDO $pdo, string $relativePath, array $excludeIds = []): void
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return;
    }

    $params = ['attachment' => $relativePath];
    $sql = "SELECT COUNT(*) FROM agendas WHERE attachment = :attachment";

    if ($excludeIds) {
        $holders = [];
        foreach ($excludeIds as $idx => $id) {
            $key = 'exclude_' . $idx;
            $holders[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        $sql .= " AND id NOT IN (" . implode(', ', $holders) . ")";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usedCount = (int) $stmt->fetchColumn();

    if ($usedCount === 0) {
        safelyDeleteUploadFile($relativePath);
    }
}

function sendDisposisiEmail(PDO $pdo, int $employeeId, array $agenda, string $itemType, string $messageText, ?string $instructionText, ?string $dueAt): bool
{
    $stmt = $pdo->prepare("SELECT full_name, email FROM employees WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $employeeId]);
    $emp = $stmt->fetch();
    if (!$emp || empty($emp['email'])) {
        return false;
    }

    $to = $emp['email'];
    $subject = "Tindak Lanjut Disposisi: " . ($agenda['title'] ?? 'Surat Baru');

    $dueText = $dueAt ? date('d F Y H:i', strtotime($dueAt)) . ' WIB' : '-';
    $instructText = $instructionText ? nl2br(e($instructionText)) : 'Tidak ada instruksi tambahan.';

    if (($agenda['entry_type'] ?? '') === 'surat' && !empty($agenda['id'])) {
        $checkZoom = $pdo->prepare("SELECT zoom_link, zoom_meeting_id, zoom_passcode FROM agendas WHERE source_agenda_id = :id AND entry_type = 'kegiatan' LIMIT 1");
        $checkZoom->execute(['id' => $agenda['id']]);
        $kegiatan = $checkZoom->fetch();
        if ($kegiatan) {
            $agenda['zoom_link'] = !empty($agenda['zoom_link']) ? $agenda['zoom_link'] : $kegiatan['zoom_link'];
            $agenda['zoom_meeting_id'] = !empty($agenda['zoom_meeting_id']) ? $agenda['zoom_meeting_id'] : $kegiatan['zoom_meeting_id'];
            $agenda['zoom_passcode'] = !empty($agenda['zoom_passcode']) ? $agenda['zoom_passcode'] : $kegiatan['zoom_passcode'];
        }
    }

    $zoomHtml = '';
    if (!empty($agenda['zoom_link'])) {
        $zoomHtml .= "
                <tr>
                    <td class='label'>Link Zoom</td>
                    <td class='value'><a href='" . htmlspecialchars($agenda['zoom_link']) . "' style='color: #4338ca; text-decoration: underline;' target='_blank'>Buka Meeting</a></td>
                </tr>";
    }
    
    if (!empty($agenda['zoom_meeting_id'])) {
        $zoomHtml .= "
                <tr>
                    <td class='label'>Meeting ID</td>
                    <td class='value'>" . e($agenda['zoom_meeting_id']) . "</td>
                </tr>";
    }
    
    if (!empty($agenda['zoom_passcode'])) {
        $zoomHtml .= "
                <tr>
                    <td class='label'>Passcode</td>
                    <td class='value'>" . e($agenda['zoom_passcode']) . "</td>
                </tr>";
    }

    $fileHtml = '';
    if (!empty($agenda['attachment'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
        $baseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir === '/') $scriptDir = '';
        $fileUrl = $baseUrl . $scriptDir . '/' . ltrim($agenda['attachment'], '/');
        
        $fileHtml = "
                <tr>
                    <td class='label'>Lampiran Fisik</td>
                    <td class='value'><a href='" . htmlspecialchars($fileUrl) . "' style='display: inline-block; padding: 6px 12px; background-color: #f1f5f9; color: #334155; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: bold; border: 1px solid #cbd5e1;' target='_blank'>ðŸ“¥ Lihat / Unduh Surat</a></td>
                </tr>";
    }

    $message = "
    <html>
    <head>
        <title>" . e($subject) . "</title>
        <style>
            body { font-family: 'Inter', Arial, sans-serif; line-height: 1.6; color: #334155; margin: 0; padding: 20px; background-color: #f8fafc; }
            .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
            .header { border-bottom: 2px solid #6366f1; padding-bottom: 15px; margin-bottom: 25px; }
            .header h2 { margin: 0; color: #1e1b4b; font-size: 20px; font-weight: 800; }
            .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
            .meta-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
            .meta-table td.label { font-weight: bold; color: #475569; width: 35%; }
            .meta-table td.value { color: #1e293b; }
            .badge { display: inline-block; padding: 4px 10px; font-size: 12px; font-weight: bold; border-radius: 9999px; background: #e0e7ff; color: #4338ca; text-transform: uppercase; }
            .content-box { background: #f8fafc; padding: 20px; border-radius: 12px; border-left: 4px solid #10b981; margin-bottom: 20px; }
            .content-box h4 { margin: 0 0 10px 0; color: #065f46; font-size: 14px; font-weight: bold; text-transform: uppercase; }
            .content-box p { margin: 0; font-size: 14px; color: #0f172a; font-weight: 500; }
            .footer { margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 12px; color: #64748b; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Notifikasi Tindak Lanjut Naskah</h2>
            </div>
            <p>Yth. <strong>" . e($emp['full_name']) . "</strong>,</p>
            <p>Terdapat disposisi/tindak lanjut baru untuk Anda dari Pimpinan. Berikut adalah detail informasinya:</p>
            
            <table class='meta-table'>
                <tr>
                    <td class='label'>Nomor Referensi</td>
                    <td class='value'>" . e($agenda['reference_number'] ?: 'TIDAK ADA') . "</td>
                </tr>
                <tr>
                    <td class='label'>Hal / Perihal</td>
                    <td class='value'><strong>" . e($agenda['title']) . "</strong></td>
                </tr>
                <tr>
                    <td class='label'>Jenis Tindak Lanjut</td>
                    <td class='value'><span class='badge'>" . e($itemType) . "</span></td>
                </tr>
                <tr>
                    <td class='label'>Batas Waktu</td>
                    <td class='value'>" . e($dueText) . "</td>
                </tr>
                " . $fileHtml . "
                " . $zoomHtml . "
            </table>


            <div class='content-box'>
                <h4>Pesan Tindak Lanjut</h4>
                <p>" . nl2br(e($messageText)) . "</p>
            </div>

            <div class='content-box' style='border-left-color: #6366f1;'>
                <h4>Instruksi / Pesan Tambahan</h4>
                <p>" . $instructText . "</p>
            </div>

            <p style='font-size: 13px; color: #475569;'>Mohon untuk segera menindaklanjuti arahan di atas dan melaporkan perkembangannya melalui aplikasi Agenda Pimpinan.</p>
            
            <div class='footer'>
                <p>Email ini dikirim otomatis oleh Aplikasi Agenda Pimpinan BBLKL Salatiga.</p>
            </div>
        </div>
    </body>
    </html>";

    return smtpSendMail($to, $subject, $message);
}

// ==========================================
// WHATSAPP API CONFIGURATION (DEFAULT: FONNTE)
// ==========================================
if (!defined('FONNTE_TOKEN')) {
    define('FONNTE_TOKEN', 'TOKEN_FONNTE_ANDA_DISINI');
}

function sendWhatsApp(string $target, string $message): bool
{
    $target = preg_replace('/[^0-9]/', '', $target);
    if (empty($target)) {
        return false;
    }

    // Debug file
    try {
        $debugDir = __DIR__ . '/../uploads/debug_emails';
        if (!is_dir($debugDir)) {
            @mkdir($debugDir, 0777, true);
        }
        $filename = $debugDir . '/wa_' . time() . '_' . $target . '.txt';
        @file_put_contents($filename, $message);
    } catch (Throwable $e) {}

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.fonnte.com/send',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => array(
        'target' => $target,
        'message' => $message,
      ),
      CURLOPT_HTTPHEADER => array(
        'Authorization: ' . (getAppSetting('fonnte_token') ?: (defined('FONNTE_TOKEN') ? FONNTE_TOKEN : ''))
      ),
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        error_log("Fonnte cURL Error: " . $err);
        return false;
    }
    
    return $httpCode === 200;
}

function sendDisposisiWhatsApp(PDO $pdo, int $employeeId, array $agenda, string $itemType, string $messageText, ?string $instructionText, ?string $dueAt): bool
{
    $stmt = $pdo->prepare("SELECT full_name, whatsapp_number FROM employees WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $employeeId]);
    $emp = $stmt->fetch();
    
    if (!$emp || empty($emp['whatsapp_number'])) {
        return false;
    }

    $dueText = $dueAt ? date('d F Y H:i', strtotime($dueAt)) . ' WIB' : '-';
    $instructText = $instructionText ? trim($instructionText) : 'Tidak ada instruksi tambahan.';
    
    if (($agenda['entry_type'] ?? '') === 'surat' && !empty($agenda['id'])) {
        $checkZoom = $pdo->prepare("SELECT zoom_link, zoom_meeting_id, zoom_passcode FROM agendas WHERE source_agenda_id = :id AND entry_type = 'kegiatan' LIMIT 1");
        $checkZoom->execute(['id' => $agenda['id']]);
        $kegiatan = $checkZoom->fetch();
        if ($kegiatan) {
            $agenda['zoom_link'] = !empty($agenda['zoom_link']) ? $agenda['zoom_link'] : $kegiatan['zoom_link'];
            $agenda['zoom_meeting_id'] = !empty($agenda['zoom_meeting_id']) ? $agenda['zoom_meeting_id'] : $kegiatan['zoom_meeting_id'];
            $agenda['zoom_passcode'] = !empty($agenda['zoom_passcode']) ? $agenda['zoom_passcode'] : $kegiatan['zoom_passcode'];
        }
    }

    $message = "Halo *" . trim((string) $emp['full_name']) . "*,\n\n";
    $message .= "Terdapat disposisi/tindak lanjut baru untuk Anda. Berikut detailnya:\n\n";
    $message .= "*Hal:* " . trim((string) ($agenda['title'] ?? '-')) . "\n";
    $message .= "*No Ref:* " . ($agenda['reference_number'] ?: '-') . "\n";
    $message .= "*Jenis:* " . strtoupper($itemType) . "\n";
    $message .= "*Batas Waktu:* " . $dueText . "\n";
    
    if (!empty($agenda['zoom_link'])) {
        $message .= "*Link Zoom:* " . trim($agenda['zoom_link']) . "\n";
        if (!empty($agenda['zoom_meeting_id'])) {
            $message .= "*Meeting ID:* " . trim($agenda['zoom_meeting_id']) . "\n";
        }
        if (!empty($agenda['zoom_passcode'])) {
            $message .= "*Passcode:* " . trim($agenda['zoom_passcode']) . "\n";
        }
    }
    $message .= "\n";

    $message .= "*Pesan:*\n" . trim($messageText) . "\n\n";
    $message .= "*Instruksi Tambahan:*\n" . $instructText . "\n\n";
    $message .= "Mohon segera ditindaklanjuti. Terima kasih.";

    return sendWhatsApp((string) $emp['whatsapp_number'], $message);
}

function renderPagination(string $param, int $currentPage, int $totalItems, int $limit = 10): void
{
    $totalPages = (int) ceil($totalItems / $limit);
    if ($totalPages <= 1) {
        return;
    }

    $queryParams = $_GET;
    $buildUrl = static function (int $page) use ($queryParams, $param): string {
        $queryParams[$param] = $page;
        return '?' . http_build_query($queryParams);
    };

    // Build page window
    $pages = [];
    if ($totalPages <= 7) {
        for ($i = 1; $i <= $totalPages; $i++) {
            $pages[] = $i;
        }
    } else {
        $pages[] = 1;
        $pages[] = 2;
        $windowStart = max(3, $currentPage - 1);
        $windowEnd   = min($totalPages - 2, $currentPage + 1);
        if ($windowStart > 3) {
            $pages[] = '...';
        }
        for ($i = $windowStart; $i <= $windowEnd; $i++) {
            if (!in_array($i, $pages, true)) {
                $pages[] = $i;
            }
        }
        if ($windowEnd < $totalPages - 2) {
            $pages[] = '...';
        }
        if (!in_array($totalPages - 1, $pages, true)) {
            $pages[] = $totalPages - 1;
        }
        if (!in_array($totalPages, $pages, true)) {
            $pages[] = $totalPages;
        }
    }

    echo '<div class="flex items-center justify-between border-t border-slate-100 dark:border-slate-700 px-4 py-3">';
    echo '<p class="text-sm text-slate-500 dark:text-slate-400">Halaman <span class="font-bold text-slate-700 dark:text-slate-300">' . $currentPage . '</span> dari <span class="font-bold text-slate-700 dark:text-slate-300">' . $totalPages . '</span></p>';
    echo '<nav class="flex items-center gap-1" aria-label="Pagination">';

    // Prev
    if ($currentPage > 1) {
        echo '<a href="' . e($buildUrl($currentPage - 1)) . '" class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors" aria-label="Halaman sebelumnya">'
            . '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>'
            . '</a>';
    } else {
        echo '<span class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 text-sm font-semibold text-slate-300 dark:text-slate-600 cursor-not-allowed" aria-disabled="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>'
            . '</span>';
    }

    // Page numbers
    foreach ($pages as $page) {
        if ($page === '...') {
            echo '<span class="flex h-8 w-8 items-center justify-center text-sm text-slate-400 dark:text-slate-500 select-none">&hellip;</span>';
        } elseif ((int) $page === $currentPage) {
            echo '<span class="flex h-8 w-8 items-center justify-center rounded-lg border border-indigo-500 bg-indigo-50 dark:bg-indigo-500/20 text-sm font-bold text-indigo-700 dark:text-indigo-300 shadow-sm" aria-current="page">' . $page . '</span>';
        } else {
            echo '<a href="' . e($buildUrl((int) $page)) . '" class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">' . $page . '</a>';
        }
    }

    // Next
    if ($currentPage < $totalPages) {
        echo '<a href="' . e($buildUrl($currentPage + 1)) . '" class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors" aria-label="Halaman berikutnya">'
            . '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>'
            . '</a>';
    } else {
        echo '<span class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 text-sm font-semibold text-slate-300 dark:text-slate-600 cursor-not-allowed" aria-disabled="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>'
            . '</span>';
    }

    echo '</nav>';
    echo '</div>';
}

function getAvatarColor(string $name): string
{
    $colors = ['bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-emerald-500', 'bg-cyan-500', 'bg-blue-500', 'bg-indigo-500', 'bg-violet-500', 'bg-fuchsia-500', 'bg-rose-500'];
    $hash = crc32($name);
    return $colors[$hash % count($colors)];
}

function logActivity(string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
{
    $user = currentUser();
    $userId = $user ? (int) $user['id'] : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        $pdo = getPdo();
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, ip_address) VALUES (:user_id, :action, :description, :entity_type, :entity_id, :ip)");
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => $ip,
        ]);
    } catch (Throwable $e) {
        error_log("Gagal logActivity: " . $e->getMessage());
    }
}

function createNotification(int $userId, string $title, string $message, ?string $link = null): void
{
    try {
        $pdo = getPdo();
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (:user_id, :title, :message, :link)");
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ]);
    } catch (Throwable $e) {
        error_log("Gagal createNotification: " . $e->getMessage());
    }
}

/**
 * Cache in-memory nilai app_settings per-request.
 */
final class AppSettingsCache
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$values);
    }

    public static function get(string $key): string
    {
        return self::$values[$key];
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}

/**
 * Read a single value from the app_settings table.
 * Falls back to $default if the key does not exist or the table is not yet created.
 * Nilai di-cache per-request — beberapa pemanggil sekaligus cukup satu query per key.
 */
function getAppSetting(string $key, string $default = ''): string
{
    if (AppSettingsCache::has($key)) {
        return AppSettingsCache::get($key);
    }

    try {
        $pdo = getPdo();
        $stmt = $pdo->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1"
        );
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $value = $row === false ? $default : ($row['setting_value'] ?? $default);
    } catch (Throwable) {
        $value = $default;
    }

    AppSettingsCache::set($key, $value);
    return $value;
}

/**
 * Persist (upsert) a key-value pair in the app_settings table.
 */
function setAppSetting(string $key, string $value): void
{
    try {
        $pdo = getPdo();
        $stmt = $pdo->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
        AppSettingsCache::set($key, $value);
    } catch (Throwable $e) {
        error_log("Gagal setAppSetting({$key}): " . $e->getMessage());
    }
}

/**
 * Require the current user to be logged in AND belong to one of the allowed roles.
 * If the role check fails, sets a flash error and redirects to dashboard.php.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();

    $user = currentUser();
    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        setFlash('error', 'Akses tidak diizinkan.');
        redirect('dashboard.php');
    }
}


/**
 * Generates a Google Calendar link for an event.
 */
function generateGoogleCalendarLink(string $title, string $dateStart, ?string $dateEnd = null, ?string $timeStr = null, string $location = ''): string
{
    $text = urlencode($title);
    $loc = urlencode($location);

    // Default to all-day event format Ymd
    $format = 'Ymd';
    $isAllDay = true;

    if ($timeStr && trim($timeStr) !== '') {
        if (preg_match('/(\d{2}):(\d{2})/', $timeStr, $m)) {
            $format = 'Ymd\THis';
            $isAllDay = false;
        }
    }

    $start = new DateTime($dateStart);
    
    if (!$isAllDay && isset($m)) {
        $start->setTime((int)$m[1], (int)$m[2]);
    }

    if ($dateEnd) {
        $end = new DateTime($dateEnd);
        if ($isAllDay) {
            // Google calendar all-day events need the end date to be exclusive (the next day)
            $end->modify('+1 day');
        } else {
            if (preg_match('/-\s*(\d{2}):(\d{2})/', $timeStr, $m2)) {
                $end->setTime((int)$m2[1], (int)$m2[2]);
            } else {
                $end = clone $start;
                $end->modify('+1 hour');
            }
        }
    } else {
        $end = clone $start;
        if ($isAllDay) {
            $end->modify('+1 day');
        } else {
            $end->modify('+1 hour');
        }
    }

    $dates = $start->format($format) . '/' . $end->format($format);
    $tzParam = $isAllDay ? '' : '&ctz=Asia/Jakarta';

    return "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$text}&dates={$dates}&location={$loc}{$tzParam}";
}

// ==========================================
// PASSWORD POLICY
// ==========================================

/**
 * Validasi password sesuai policy SaaS.
 * Return array [valid => bool, errors => string[]]
 */
function validatePassword(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'Password harus mengandung huruf.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password harus mengandung angka.';
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Cek apakah password pernah bocor via haveibeenpwned (k-anonymity API).
 * Return true jika aman (tidak ditemukan), false jika ditemukan di breach database.
 */
function isPasswordBreached(string $password): bool
{
    if (env_bool('PWNED_CHECK_DISABLE', true)) {
        // Default: skip di dev lokal (tidak ada internet/curl). Aktifkan di produksi.
        return false;
    }
    $hash = strtoupper(sha1($password));
    $prefix = substr($hash, 0, 5);
    $suffix = substr($hash, 5);

    $ch = curl_init("https://api.pwnedpasswords.com/range/{$prefix}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AgendaPimpinan-BBKL');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) return false; // fail-open
    $lines = explode("\n", $response);
    foreach ($lines as $line) {
        [$sfx, $count] = explode(':', trim($line), 2);
        if ($sfx === $suffix) return true;
    }
    return false;
}

// ==========================================
// RATE LIMITER (sliding window via DB)
// ==========================================

/**
 * Cek & catat rate limit. Return true jika request BOLEH lanjut, false jika exceeded.
 *
 * @param string $bucket Kunci bucket (mis. 'api_search')
 * @param string $identity Identitas (IP atau user_id)
 * @param int $maxRequests Maks request dalam window
 * @param int $windowSec Window dalam detik
 */
function rateLimit(string $bucket, string $identity, int $maxRequests, int $windowSec): bool
{
    try {
        $pdo = getPdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bucket VARCHAR(64) NOT NULL,
            identity VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bucket_id_time (bucket, identity, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Hapus record lama di luar window untuk identity+bucket ini.
        $del = $pdo->prepare("DELETE FROM rate_limits WHERE bucket = :b AND identity = :i AND created_at < (NOW() - INTERVAL :w SECOND)");
        $del->bindValue(':b', $bucket);
        $del->bindValue(':i', $identity);
        $del->bindValue(':w', $windowSec, PDO::PARAM_INT);
        $del->execute();

        // Hitung request saat ini dalam window.
        $count = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND identity = :i AND created_at >= (NOW() - INTERVAL :w SECOND)");
        $count->bindValue(':b', $bucket);
        $count->bindValue(':i', $identity);
        $count->bindValue(':w', $windowSec, PDO::PARAM_INT);
        $count->execute();
        $current = (int)$count->fetchColumn();

        if ($current >= $maxRequests) {
            return false; // exceeded
        }

        // Catat request ini.
        $ins = $pdo->prepare("INSERT INTO rate_limits (bucket, identity) VALUES (:b, :i)");
        $ins->bindValue(':b', $bucket);
        $ins->bindValue(':i', $identity);
        $ins->execute();
        return true;
    } catch (Throwable $e) {
        error_log('[rateLimit] Gagal: ' . $e->getMessage());
        return true; // fail-open untuk availability
    }
}

/**
 * Kirim response 429 Too Many Requests + exit.
 */
function respondRateLimited(int $retryAfter = 60): void
{
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'too_many_requests', 'message' => 'Terlalu banyak permintaan. Coba lagi nanti.']);
    exit;
}

// ==========================================
// AUDIT LOG IMMUTABLE (append-only file + DB)
// ==========================================

/**
 * Tulis audit log ke file append-only (immutable dari sisi aplikasi).
 * Dipakai sebagai backup integrity jika DB log di-tamper.
 */
function auditLogImmutable(string $action, string $description, array $context = []): void
{
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    $logFile = $logDir . '/audit-' . date('Y-m-d') . '.log';
    $user = currentUser();
    $entry = json_encode([
        'ts'         => date('Y-m-d H:i:s'),
        'user_id'    => $user['id'] ?? null,
        'username'   => $user['username'] ?? null,
        'action'     => $action,
        'description'=> $description,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'context'    => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Append-only. chmod 0640 + chown www-data ideal di production.
    @file_put_contents($logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
}

// ==========================================
// 2FA TOTP (RFC 6238) — tanpa vendor library
// ==========================================

/**
 * Generate TOTP secret baru (Base32, 20 byte).
 */
function generateTotpSecret(): string
{
    $bytes = random_bytes(20);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $b = ord($bytes[$i]);
        // 8 bits → 5 base32 chars (perlukan 5 byte → 8 char)
    }
    // Pakai base32 encode manual sederhana
    $bits = '';
    foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $five) {
        if (strlen($five) < 5) $five = str_pad($five, 5, '0');
        $out .= $alphabet[bindec($five)];
    }
    return $out;
}

/**
 * Hitung TOTP code (RFC 6238, 30 detik window, 6 digit).
 */
function totpCode(string $secret, ?int $timestamp = null, int $window = 30): string
{
    $timestamp = $timestamp ?? time();
    $counter = intdiv($timestamp, $window);
    $binaryCounter = pack('N*', 0) . pack('N*', $counter);

    // Decode base32 secret → binary
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($secret)) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $eight) {
        if (strlen($eight) === 8) $key .= chr(bindec($eight));
    }

    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Verifikasi TOTP code dengan toleransi ±1 window (untuk clock drift).
 */
function verifyTotp(string $secret, string $code, int $window = 30): bool
{
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totpCode($secret, $now + $i * $window, $window), $code)) return true;
    }
    return false;
}
/**
 * Generate otpauth URI untuk QR code.
 */
function totpQrUri(string $secret, string $issuer, string $account): string
{
    $label = rawurlencode($issuer . ':' . $account);
    return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer) . "&algorithm=SHA1&digits=6&period=30";
}

/**
 * Kompresi gambar JPG/PNG menggunakan GD.
 */
function compressImageUpload(string $sourcePath, string $targetPath, int $quality = 70): bool
{
    if (!function_exists('getimagesize')) {
        return copy($sourcePath, $targetPath);
    }

    $info = @getimagesize($sourcePath);
    if ($info === false) {
        return false;
    }

    if ($info['mime'] == 'image/jpeg') {
        $image = @imagecreatefromjpeg($sourcePath);
        if ($image !== false) {
            $result = imagejpeg($image, $targetPath, $quality);
            imagedestroy($image);
            return $result;
        }
    } elseif ($info['mime'] == 'image/png') {
        $image = @imagecreatefrompng($sourcePath);
        if ($image !== false) {
            // Ubah transparansi agar aman, dan kompres 8 dari 9
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $result = imagepng($image, $targetPath, 8); 
            imagedestroy($image);
            return $result;
        }
    }

    return false;
}

/**
 * Membersihkan data duplikat agenda secara aman.
 * Mencari duplikasi pada agendas (berdasarkan reference_number yang sama atau title + agenda_date yang sama pada entry_type yang sama),
 * mempertahankan 1 record utama, memindahkan relasi anak (disposisi), dan menghapus record duplikat yang redundan.
 */
function cleanupDuplicateAgendas(?PDO $pdo = null): int
{
    if ($pdo === null) {
        $pdo = getPdo();
    }
    
    $deletedCount = 0;

    // 1. Duplikasi berdasarkan reference_number non-kosong pada entry_type yang sama
    $dupes = $pdo->query("
        SELECT entry_type, reference_number, GROUP_CONCAT(id ORDER BY (source_agenda_id IS NOT NULL) DESC, id ASC) as id_list, COUNT(*) as cnt
        FROM agendas
        WHERE reference_number IS NOT NULL AND TRIM(reference_number) != '' AND reference_number NOT LIKE 'AUTO-%'
        GROUP BY entry_type, reference_number
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dupes as $group) {
        $ids = array_filter(array_map('intval', explode(',', (string) $group['id_list'])));
        if (count($ids) <= 1) {
            continue;
        }
        $primaryId = array_shift($ids);
        foreach ($ids as $dupeId) {
            $pdo->prepare("UPDATE agenda_disposition_items SET agenda_id = :primary WHERE agenda_id = :dupe")
                ->execute(['primary' => $primaryId, 'dupe' => $dupeId]);
            $pdo->prepare("UPDATE agendas SET source_agenda_id = :primary WHERE source_agenda_id = :dupe")
                ->execute(['primary' => $primaryId, 'dupe' => $dupeId]);
            $pdo->prepare("DELETE FROM agendas WHERE id = :dupe")->execute(['dupe' => $dupeId]);
            $deletedCount++;
        }
    }

    // 2. Duplikasi kegiatan berdasarkan (title, agenda_date)
    $titleDupes = $pdo->query("
        SELECT title, agenda_date, GROUP_CONCAT(id ORDER BY (source_agenda_id IS NOT NULL) DESC, (zoom_link != '') DESC, id ASC) as id_list, COUNT(*) as cnt
        FROM agendas
        WHERE entry_type = 'kegiatan' AND title IS NOT NULL AND TRIM(title) != ''
        GROUP BY title, agenda_date
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($titleDupes as $group) {
        $ids = array_filter(array_map('intval', explode(',', (string) $group['id_list'])));
        if (count($ids) <= 1) {
            continue;
        }
        $primaryId = array_shift($ids);
        foreach ($ids as $dupeId) {
            $pdo->prepare("UPDATE agenda_disposition_items SET agenda_id = :primary WHERE agenda_id = :dupe")
                ->execute(['primary' => $primaryId, 'dupe' => $dupeId]);
            $pdo->prepare("UPDATE agendas SET source_agenda_id = :primary WHERE source_agenda_id = :dupe")
                ->execute(['primary' => $primaryId, 'dupe' => $dupeId]);
            $pdo->prepare("DELETE FROM agendas WHERE id = :dupe")->execute(['dupe' => $dupeId]);
            $deletedCount++;
        }
    }

    return $deletedCount;
}
