<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/google_drive_storage.php';

function normalizeUrlCandidate(string $url): string
{
    $url = trim($url);
    $url = rtrim($url, ".,;:!?)]}");

    // Perbaiki typo umum OCR pada domain Zoom (contoh: usO2web -> us02web)
    $url = preg_replace('~\bus[oO](\d)web\.zoom\.us~i', 'us0$1web.zoom.us', $url) ?? $url;
    // Hilangkan spasi yang tersisip oleh OCR sebelum parameter query pwd
    $url = preg_replace('~\?[\s]+pwd=~i', '?pwd=', $url) ?? $url;

    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function normalizeTextWhitespace(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function shellNullRedirect(): string
{
    return PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
}

function resolveBinaryCommand(string $command): ?string
{
    static $cache = [];
    $key = strtolower($command);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $envMap = [
        'tesseract' => 'AGENDA_OCR_TESSERACT',
        'pdftotext' => 'AGENDA_OCR_PDFTOTEXT',
        'pdftoppm' => 'AGENDA_OCR_PDFTOPPM',
        'magick' => 'AGENDA_OCR_MAGICK',
    ];
    $localMap = [
        'tesseract' => __DIR__ . '/../tools/ocr/tesseract/tesseract.exe',
        'pdftotext' => __DIR__ . '/../tools/ocr/poppler/pdftotext.exe',
        'pdftoppm' => __DIR__ . '/../tools/ocr/poppler/pdftoppm.exe',
        'magick' => __DIR__ . '/../tools/ocr/imagemagick/magick.exe',
    ];

    if (isset($envMap[$key])) {
        $envPath = getenv($envMap[$key]);
        if (is_string($envPath) && trim($envPath) !== '' && is_file($envPath)) {
            $cache[$key] = $envPath;
            return $cache[$key];
        }
    }

    if (isset($localMap[$key]) && is_file($localMap[$key])) {
        $cache[$key] = $localMap[$key];
        return $cache[$key];
    }

    if (!function_exists('shell_exec')) {
        $cache[$key] = null;
        return null;
    }

    $probeCmd = PHP_OS_FAMILY === 'Windows'
        ? 'where ' . escapeshellarg($command) . ' ' . shellNullRedirect()
        : 'command -v ' . escapeshellarg($command) . ' ' . shellNullRedirect();
    $output = shell_exec($probeCmd);
    if (is_string($output) && trim($output) !== '') {
        $first = trim(explode(PHP_EOL, str_replace("\r", '', $output))[0] ?? '');
        $cache[$key] = $first !== '' ? $first : $command;
        return $cache[$key];
    }

    $cache[$key] = null;
    return $cache[$key];
}

function commandExists(string $command): bool
{
    return resolveBinaryCommand($command) !== null;
}

function runCliTextCommand(string $command): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }

    $output = shell_exec($command);
    if (!is_string($output) || $output === '') {
        return '';
    }

    return normalizeTextWhitespace($output);
}

function extractTextFromPdfCli(string $absolutePath): string
{
    if (!function_exists('escapeshellarg') || !function_exists('shell_exec')) {
        return '';
    }

    $binary = resolveBinaryCommand('pdftotext');
    if ($binary === null) {
        return '';
    }

    $cmd = escapeshellarg($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($absolutePath) . ' - ' . shellNullRedirect();
    return runCliTextCommand($cmd);
}

function extractTextFromImageCli(string $absolutePath): string
{
    if (!function_exists('escapeshellarg') || !function_exists('shell_exec')) {
        return '';
    }

    $binary = resolveBinaryCommand('tesseract');
    if ($binary === null) {
        return '';
    }

    // Periksa apakah bahasa 'ind' terpasang di Tesseract agar tidak buang waktu error
    static $hasIndLang = null;
    if ($hasIndLang === null) {
        $probe = (string) @shell_exec(escapeshellarg($binary) . ' --list-langs ' . shellNullRedirect());
        $hasIndLang = str_contains($probe, 'ind');
    }

    $langParam = $hasIndLang ? '-l ind+eng' : '-l eng';
    // Gunakan psm 3 (otomatis segmentasi tata letak halaman naskah/surat) terlebih dahulu
    $cmd = escapeshellarg($binary) . ' ' . escapeshellarg($absolutePath) . " stdout {$langParam} --psm 3 " . shellNullRedirect();
    $output = runCliTextCommand($cmd);
    if ($output !== '') {
        return $output;
    }

    $cmdFallback = escapeshellarg($binary) . ' ' . escapeshellarg($absolutePath) . ' stdout -l eng --psm 6 ' . shellNullRedirect();
    return runCliTextCommand($cmdFallback);
}

function extractTextFromPdfViaTesseract(string $absolutePath): string
{
    if (!function_exists('escapeshellarg') || !function_exists('shell_exec')) {
        return '';
    }

    if (!commandExists('tesseract')) {
        return '';
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agenda_ocr_' . uniqid('', true);
    $result = '';

    try {
        $pdftoppm = resolveBinaryCommand('pdftoppm');
        $magick = resolveBinaryCommand('magick');
        if ($pdftoppm !== null) {
            // Render halaman 1
            $cmd1 = escapeshellarg($pdftoppm) . ' -f 1 -l 1 -png ' . escapeshellarg($absolutePath) . ' ' . escapeshellarg($tmpBase . '_1') . ' ' . shellNullRedirect();
            runCliTextCommand($cmd1);
            $p1 = $tmpBase . '_1-1.png';
            if (!is_file($p1)) {
                $p1 = $tmpBase . '_1.png';
            }
            if (is_file($p1)) {
                $result .= ' ' . extractTextFromImageCli($p1);
                @unlink($p1);
            }

            // Render halaman 2 jika ada (surat lampiran / rincian agenda)
            $cmd2 = escapeshellarg($pdftoppm) . ' -f 2 -l 2 -png ' . escapeshellarg($absolutePath) . ' ' . escapeshellarg($tmpBase . '_2') . ' ' . shellNullRedirect();
            runCliTextCommand($cmd2);
            $p2 = $tmpBase . '_2-2.png';
            if (!is_file($p2)) {
                $p2 = $tmpBase . '_2.png';
            }
            if (is_file($p2)) {
                $result .= ' ' . extractTextFromImageCli($p2);
                @unlink($p2);
            }
        } elseif ($magick !== null) {
            $tmpImage = $tmpBase . '.png';
            $pdfPage = $absolutePath . '[0]';
            $cmd = escapeshellarg($magick) . ' -density 220 ' . escapeshellarg($pdfPage) . ' -strip -quality 90 ' . escapeshellarg($tmpImage) . ' ' . shellNullRedirect();
            runCliTextCommand($cmd);
            if (is_file($tmpImage)) {
                $result = extractTextFromImageCli($tmpImage);
                @unlink($tmpImage);
            }
        }
    } finally {
        // Cleanup file sisa jika ada
        foreach (glob($tmpBase . '*.png') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    return trim($result);
}

function decodePdfStream(string $stream): ?string
{
    $stream = ltrim($stream, "\r\n");
    $stream = rtrim($stream, "\r\n");

    $decoded = @gzuncompress($stream);
    if ($decoded !== false) {
        return $decoded;
    }

    $decoded = @gzinflate($stream);
    if ($decoded !== false) {
        return $decoded;
    }

    if (strlen($stream) > 2) {
        $decoded = @gzinflate(substr($stream, 2));
        if ($decoded !== false) {
            return $decoded;
        }
    }

    return null;
}

function decodePdfLiteralString(string $value): string
{
    $value = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $value);
    $value = preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? $value;
    return normalizeTextWhitespace($value);
}

function extractTextFromPdf(string $absolutePath): string
{
    $raw = @file_get_contents($absolutePath);
    if ($raw === false || $raw === '') {
        return '';
    }

    $chunks = [];
    if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $raw, $streams) > 0) {
        foreach ($streams[1] as $stream) {
            $decoded = decodePdfStream((string) $stream);
            if (!$decoded) {
                continue;
            }

            if (preg_match_all('/\((.*?)\)/s', $decoded, $texts) > 0) {
                foreach ($texts[1] as $text) {
                    $clean = decodePdfLiteralString((string) $text);
                    if ($clean !== '' && preg_match('/[A-Za-z]{3,}/', $clean) === 1) {
                        $chunks[] = $clean;
                    }
                }
            }
        }
    }

    return normalizeTextWhitespace(implode(' ', $chunks));
}

function extractUrlHintsFromPdf(string $absolutePath): string
{
    $raw = @file_get_contents($absolutePath);
    if ($raw === false || $raw === '') {
        return '';
    }

    $urls = [];

    if (preg_match_all('/\/URI\s*\((.*?)\)/s', $raw, $uriMatches) > 0) {
        foreach (($uriMatches[1] ?? []) as $uriRaw) {
            $candidate = normalizeUrlCandidate((string) $uriRaw);
            if (!isInvalidShortDomain($candidate)) {
                $urls[] = $candidate;
            }
        }
    }

    if (preg_match_all('/\/URI\s*<([0-9A-Fa-f]+)>/s', $raw, $uriHexMatches) > 0) {
        foreach (($uriHexMatches[1] ?? []) as $hexRaw) {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string) $hexRaw) ?? '';
            if ($hex === '' || strlen($hex) % 2 !== 0) {
                continue;
            }
            $decoded = @hex2bin($hex);
            if (!is_string($decoded) || $decoded === '') {
                continue;
            }
            $candidate = normalizeUrlCandidate($decoded);
            if (!isInvalidShortDomain($candidate)) {
                $urls[] = $candidate;
            }
        }
    }

    $urls = array_values(array_unique(array_filter(array_map('trim', $urls), static function (string $v): bool {
        if ($v === '') {
            return false;
        }
        $host = strtolower((string) parse_url($v, PHP_URL_HOST));
        if ($host === '' || isNoiseReferenceHost($host) || isInvalidShortDomain($v)) {
            return false;
        }
        return true;
    })));
    return normalizeTextWhitespace(implode(' ', $urls));
}

function extractTextFromOcrSpaceApi(string $absolutePath): string
{
    // Gunakan free API key publik K84524410188957 (Limit 5MB)
    $apiKey = 'K84524410188957'; 
    $url = 'https://api.ocr.space/parse/image';
    
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mime = 'application/pdf';
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($ext === 'png') {
        $mime = 'image/png';
    }

    $cfile = new CURLFile($absolutePath, $mime, basename($absolutePath));
    $postData = [
        'apikey' => $apiKey,
        'language' => 'eng',
        'isOverlayRequired' => 'false',
        'scale' => 'true',
        'file' => $cfile,
        'OCREngine' => '2'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $response = curl_exec($ch);
    @curl_close($ch);

    if ($response) {
        $json = json_decode($response, true);
        if (!empty($json['ParsedResults'])) {
            $texts = array_column($json['ParsedResults'], 'ParsedText');
            return implode("\n", $texts);
        }
    }
    return '';
}

function extractAttachmentText(string $relativePath): string
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return '';
    }

    if (GoogleDriveStorage::isDriveReference($relativePath)) {
        $driveFile = GoogleDriveStorage::download($relativePath);
        if ($driveFile === null) {
            return '';
        }
        $extension = strtolower(pathinfo($driveFile['name'], PATHINFO_EXTENSION));
        $target = tempnam(sys_get_temp_dir(), 'agenda_drive_');
        if ($target === false) {
            return '';
        }
        if ($extension !== '') {
            $renamedTarget = $target . '.' . $extension;
            if (@rename($target, $renamedTarget)) {
                $target = $renamedTarget;
            }
        }
        if (file_put_contents($target, $driveFile['content']) === false) {
            @unlink($target);
            return '';
        }
        register_shutdown_function(static function () use ($target): void {
            if (is_file($target)) {
                @unlink($target);
            }
        });
    } else {
        $baseDir = realpath(__DIR__ . '/../uploads');
        $target = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
        if ($baseDir === false || $target === false) {
            return '';
        }
        if (!str_starts_with($target, $baseDir . DIRECTORY_SEPARATOR) || !is_file($target)) {
            return '';
        }
    }

    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $text = extractTextFromPdfCli($target);
        $urlHints = extractUrlHintsFromPdf($target);
        
        if ($text === '' && class_exists('\\Smalot\\PdfParser\\Parser')) {
            // Guard: smalot/pdfparser adalah parser PHP murni — di PDF scan besar
            // bisa melampaui max_execution_time (30s di shared hosting) dan itu
            // FATAL error yang tidak bisa ditangkap try/catch. Batasi hanya untuk
            // PDF <= 2 MB; yang lebih besar lanjut ke fallback OCR (tesseract/OCR.space)
            // yang punya timeout sendiri.
            $pdfSize = is_file($target) ? (int) @filesize($target) : 0;
            if ($pdfSize > 0 && $pdfSize <= 2 * 1024 * 1024) {
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($target);
                    $text = $pdf->getText();
                } catch (\Throwable $e) {
                    // Ignore errors
                }
            }
        }

        // Tesseract Local Fallback
        if ($text === '') {
            $text = extractTextFromPdfViaTesseract($target);
        }

        // OCR.Space Cloud API Fallback (Jika server Linux cPanel dan surat berupa hasil scan)
        if ($text === '') {
            $text = extractTextFromOcrSpaceApi($target);
        }

        if ($text !== '') {
            return normalizeTextWhitespace(trim($text . ' ' . $urlHints));
        }

        $text = extractTextFromPdf($target);
        return normalizeTextWhitespace(trim($text . ' ' . $urlHints));
    }

    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $text = extractTextFromImageCli($target);
        
        // Cek jika teks kosong atau jika teks kurang lengkap (khas flyer), panggil OCR.Space
        // Tesseract kurang bagus membaca layout flyer secara default
        if ($text === '' || (!hasMeetingCredentialHints($text) && !inferAgendaDate($text))) {
            $textOcr = extractTextFromOcrSpaceApi($target);
            if ($textOcr !== '') {
                $text = $textOcr; // Prioritaskan hasil OCR Space jika ada
            }
        }
        
        return normalizeTextWhitespace(trim($text));
    }

    return '';
}

function isInvalidShortDomain(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return true;
    }

    // Hindari salah baca seperti "a.iv" dari teks non-link.
    if (preg_match('/^[a-z]\.[a-z]{2,3}$/', $host) === 1) {
        return true;
    }

    return false;
}

function isPlausibleDomainForFallback(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    $trustedSingleDotHosts = ['zoom.us', 'bit.ly', 't.ly', 'rb.gy', 'tinyurl.com'];
    if (in_array($host, $trustedSingleDotHosts, true)) {
        return true;
    }

    // Untuk fallback tanpa protocol, hindari pola domain terlalu pendek
    // yang sering bentrok dengan format nomor surat.
    return substr_count($host, '.') >= 2;
}

function isNoiseReferenceHost(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return true;
    }

    $noiseHosts = [
        'www.w3.org',
        'w3.org',
        'purl.org',
        'ns.adobe.com',
        'www.aiim.org',
        'aiim.org',
        'www.tcpdf.org',
        'tcpdf.org',
        'en.wikipedia.org',
        'wikipedia.org',
    ];

    foreach ($noiseHosts as $noiseHost) {
        if ($host === $noiseHost || str_ends_with($host, '.' . $noiseHost)) {
            return true;
        }
    }

    return false;
}

function extractMeetingUrlStrict(string $text): ?string
{
    // Normalisasi spasi di sekitar query param pwd sebelum regex
    $text = preg_replace('~(https?://[^\s<>"\']+\?)\s+(pwd=[^\s<>"\']+)~i', '$1$2', $text) ?? $text;

    $patterns = [
        // Zoom meeting direct link (us02web.zoom.us, usO2web.zoom.us, us04web, dsb)
        '~\b(?:https?://)?(?:us[0-9oO]{2}web|us[0-9oO]{2}cai)\.zoom\.us/[^\s<>"\']+~i',
        // Zoom meeting link dengan ID rapat /j/ atau /my/
        '~(?:https?://)?(?:[a-z0-9-]+\.)?zoom\.us/(?:j|my)/\d+[^\s<>"\']*~i',
        // Zoom.us, meet.google.com, teams.microsoft.com, s.kemkes.go.id, s.kemenkeu.go.id — dengan atau tanpa protocol
        '~\b(?:https?://)?(?:[a-z0-9-]+\.)?(?:zoom\.us|meet\.google\.com|teams\.microsoft\.com|s\.kemkes\.go\.id|s\.kemenkeu\.go\.id)(?:/[^\s<>"\']*)?~i',
        // Bit.ly / tinyurl / t.ly / rb.gy short links (umum di flyer)
        '~\b(?:https?://)?(?:bit\.ly|tinyurl\.com|t\.ly|rb\.gy|shorturl\.at|cutt\.ly)/[a-zA-Z0-9]+~i',
        // Pola "Link: <url>" atau "Tautan: <url>" yang umum di flyer
        '~(?:link|tautan)\s*[:\-=]?\s*(https?://[^\s<>"\']+)~i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m) === 1) {
            $candidate = normalizeUrlCandidate((string) ($m[0] ?? ''));
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            if ($host !== '' && !isNoiseReferenceHost($host)) {
                return $candidate;

            }
        }
    }

    return null;
}

function extractFirstUrl(string $text): ?string
{
    $candidates = [];

    if (preg_match_all('~https?://[^\s<>"\']+~i', $text, $matches) > 0) {
        foreach (($matches[0] ?? []) as $m) {
            $candidate = normalizeUrlCandidate((string) $m);
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            if (!isInvalidShortDomain($candidate) && !isNoiseReferenceHost($host)) {
                $candidates[] = $candidate;
            }
        }
    }

    if (preg_match_all('~\b((?:[a-z0-9-]+\.)+[a-z]{2,}(?:/[^\s<>"\']*)?)\b~i', $text, $fallback) > 0) {
        foreach (($fallback[1] ?? []) as $m) {
            $candidate = normalizeUrlCandidate((string) $m);
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            if (
                !isInvalidShortDomain($candidate)
                && !isNoiseReferenceHost($host)
                && isPlausibleDomainForFallback($candidate)
                && !in_array($candidate, $candidates, true)
            ) {
                $candidates[] = $candidate;
            }
        }
    }

    if (!$candidates) {
        return null;
    }

    foreach ($candidates as $candidate) {
        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        if (
            str_contains($host, 'zoom')
            || str_contains($host, 'meet')
            || str_contains($host, 'teams')
            || $host === 's.kemkes.go.id'
            || str_ends_with($host, '.s.kemkes.go.id')
            || $host === 's.kemenkeu.go.id'
            || str_ends_with($host, '.s.kemenkeu.go.id')
        ) {
            return $candidate;
        }
    }

    return null;
}

function isLikelyMeetingShortLink(?string $url): bool
{
    if (!$url) {
        return false;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    $meetingDomains = [
        's.kemkes.go.id',
        's.kemenkeu.go.id',
        'zoom.us',
        'us02web.zoom.us',
        'bit.ly',
        'tinyurl.com',
        't.ly',
        'rb.gy',
    ];

    foreach ($meetingDomains as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    return false;
}

function isMeetingUrlCandidate(?string $url): bool
{
    if (!$url) {
        return false;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    if (
        str_contains($host, 'zoom')
        || str_contains($host, 'meet')
        || str_contains($host, 'teams')
    ) {
        return true;
    }

    // Shortlink pemerintah (s.kemkes.go.id, s.kemenkeu.go.id): bisa meeting,
    // bisa juga link upload/daftar/form. Terima sebagai kandidat, tapi
    // verifikasi konteks di isMeetingUrlWithContext().
    if (
        $host === 's.kemkes.go.id'
        || str_ends_with($host, '.s.kemkes.go.id')
        || $host === 's.kemenkeu.go.id'
        || str_ends_with($host, '.s.kemenkeu.go.id')
    ) {
        return true;
    }

    return isLikelyMeetingShortLink($url);
}

/**
 * Verifikasi konteks untuk shortlink pemerintah: s.kemkes.go.id/xxx bisa
 * berupa link meeting ATAU link upload/form/daftar. Hanya terima jika teks
 * di sekitarnya mengandung sinyal meeting (zoom/rapat/meeting/daring/online).
 */
function isMeetingUrlWithContext(?string $url, string $context): bool
{
    if (!isMeetingUrlCandidate($url)) {
        return false;
    }

    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    $isGovShortlink = str_contains($host, 's.kemkes.go.id')
        || str_contains($host, 's.kemenkeu.go.id');

    if (!$isGovShortlink) {
        return true; // zoom.us, meet.google.com, teams — langsung terima
    }

    // Shortlink pemerintah: s.kemkes.go.id/xxx bisa link meeting ATAU
    // landing page multi-link (registrasi/e-learning/upload). Evidence dari
    // API SLINK Kemkes: mayoritas shortlink adalah landing page, bukan link
    // join meeting — jadi sinyal NON-meeting dicek DULU dan default = false.
    $lowerCtx = strtolower($context);

    // Sinyal NON-meeting: upload, daftar, form, kirim, e-learning, mooc, dll.
    $nonMeetingSignals = [
        'upload', 'unggah', 'pengumpulan', 'pengiriman',
        'daftar', 'pendaftaran', 'registrasi', 'form', 'formulir',
        'kirim', 'submit', 'artikel', 'majalah',
        'e-learning', 'elearning', 'e learning', 'mooc', 'kursus online',
        'presensi', 'rekaman', 'paparan',
    ];
    foreach ($nonMeetingSignals as $sig) {
        if (str_contains($lowerCtx, $sig)) {
            return false;
        }
    }

    // Sinyal meeting eksplisit di konteks.
    // 'agenda' termasuk: nota dinas yang menyebut "agenda" + tautan
    // (s.kemkes.go.id/xxx) adalah acara daring (aturan operator).
    $meetingSignals = ['zoom', 'rapat', 'meeting', 'daring', 'online', 'webinar', 'telekonferensi', 'vidcon', 'video conference', 'agenda'];
    foreach ($meetingSignals as $sig) {
        if (str_contains($lowerCtx, $sig)) {
            return true;
        }
    }

    // Default: TOLAK. Shortlink pemerintah tanpa sinyal meeting eksplisit
    // kemungkinan besar landing page (lihat evidence SLINK API), bukan
    // link join. Lebih baik tidak menampilkan daripada menampilkan
    // link upload/registrasi sebagai "link Zoom".
    return false;
}

function extractZoomMeetingId(string $text): ?string
{
    if (preg_match('/\b(\d{3}[\s-]?\d{3,4}[\s-]?\d{4})\b/', $text, $matches) !== 1) {
        return null;
    }
    return preg_replace('/\s+/', ' ', trim((string) ($matches[1] ?? '')));
}

function extractZoomPasscode(string $text): ?string
{
    // Pattern diperluas untuk handle OCR garbled: "asscode" (P hilang), "psscode", dll
    if (preg_match_all('/(passcode|p\?asscode|asscode|kode\s*sandi|password|pwd)\s*[:\-\=]?\s*([A-Za-z0-9@#*_\-\.]+)/i', $text, $matches, PREG_SET_ORDER) > 0) {
        $bestMatch = null;
        $pwdMatch = null;
        $blacklist = ['tidak', 'akan', 'menyusul', 'ada', 'terlampir', 'sesuai', 'belakang', 'yang', 'untuk', 'dan', 'di', 'dari', 'harap', 'mohon', 'silakan', 'silahkan', 'diinfokan'];

        foreach ($matches as $match) {
            $keyword = strtolower(trim((string)($match[1] ?? '')));
            $val = rtrim(trim((string) ($match[2] ?? '')), '.');
            
            if (in_array(strtolower($val), $blacklist, true) || $val === '') {
                continue;
            }

            if ($keyword === 'pwd') {
                if ($pwdMatch === null) {
                    $pwdMatch = $val;
                }
            } else {
                if ($bestMatch === null) {
                    $bestMatch = $val;
                }
            }
        }

        if ($bestMatch !== null) {
            return $bestMatch;
        }
        
        if ($pwdMatch !== null) {
            return $pwdMatch;
        }
    }
    return null;
}

function looksLikeZoomMeeting(string $text): bool
{
    $haystack = strtolower($text);
    $keywords = [
        'zoom',
        'zoom.us',
        'zoom meeting',
        'aplikasi zoom',
        'rapat daring',
        'webinar',
        'google meet',
        'gmeet',
        'microsoft teams',
    ];

    // Dihapus keyword terlalu generik yang sering false-positive:
    // - 'secara daring' (bisa berarti pendaftaran/laporan daring, bukan rapat daring)
    // - 'daring melalui' (bisa berarti "daring melalui website", bukan zoom)
    // - 'meeting online', 'rapat online', 'online meeting', 'web meeting', 'virtual meeting'
    //   (terlalu umum, banyak surat non-zoom memuat frasa ini)

    foreach ($keywords as $keyword) {
        if (str_contains($haystack, $keyword)) {
            return true;
        }
    }

    return false;
}

function hasMeetingCredentialHints(string $text): bool
{
    $haystack = strtolower($text);
    // 'tautan' dihapus karena terlalu generik (hanya berarti "link", bisa untuk link apa saja).
    $hints = ['id rapat', 'meeting id', 'kode sandi', 'passcode', 'password'];

    foreach ($hints as $hint) {
        if (str_contains($haystack, $hint)) {
            return true;
        }
    }

    return false;
}

function isLetterEventInvitation(string $context, string $headerContext): bool
{
    $haystack = strtolower(trim($context . ' ' . $headerContext));
    if ($haystack === '') {
        return false;
    }

    // =====================================================
    // Sinyal Absolut Zoom/Meeting:
    // Jika terdapat tautan zoom, meeting ID, passcode, atau sinyal rapat online,
    // dokumen ini adalah agenda rapat virtual/online dan TIDAK BOLEH ditolak oleh judul!
    // =====================================================
    if (
        preg_match('~\b(?:https?://)?(?:[a-z0-9-]+\.)?zoom\.us/(?:j/|my/|[^\s<>"\']+)~i', $haystack) === 1
        || preg_match('~\b(?:link\s*zoom|tautan\s*zoom|tautan\s*meeting|link\s*meeting|meeting\s*id|id\s*rapat|passcode|kode\s*sandi)\b~i', $haystack) === 1
        || (preg_match('~\b(\d{3}[\s-]?\d{3,4}[\s-]?\d{4})\b~', $haystack) === 1 && str_contains($haystack, 'zoom'))
    ) {
        return true;
    }

    // =====================================================
    // Sinyal Absolut: Nota Dinas dengan tulisan "agenda" & Surat Tugas:
    // Jika surat adalah Nota Dinas dan memuat kata/tulisan "agenda" (di judul,
    // ringkasan, catatan, atau lampiran dokumen), WAJIB diklasifikasikan sebagai
    // agenda kegiatan dan TIDAK BOLEH ditolak oleh kata negatif (seperti evaluasi, monev, dll).
    // Begitu pula Surat Tugas selalu merupakan penugasan kegiatan.
    // =====================================================
    $isNotaDinas = (bool) preg_match('/\bnota\s+dinas\b/i', $haystack);
    // Nota Dinas SELALU dianggap agenda kegiatan (aturan operator, konsisten
    // dengan isPublicAgendaTitle). Jangan bergantung pada kata "agenda" saja:
    // sering kali kata itu hanya ada di lampiran yang gagal di-OCR, sehingga
    // agenda yang sudah valid malah ikut terhapus oleh proses cleanup.
    if ($isNotaDinas || preg_match('/\bsurat\s+tugas\b/i', $haystack)) {
        return true;
    }

    // =====================================================
    // 0. Sinyal NEGATIF KUAT (selalu cek): dokumen prosedural/regulasi
    // =====================================================
    $documentTypePatterns = [
        '/\bsop\b/i',
        '/\bstandar\s+operasional\s+prosedur\b/i',
        '/\bprosedur\s+operasional\s+baku\b/i',
        '/\bpobac\b/i',
        '/\bpedoman\b/i',
        '/\bpanduan\b/i',
        '/\bpetunjuk\s+praktis\b/i',
        '/\bpetunjuk\s+operasional\b/i',
        '/\bjuknis\b/i',
        '/\bjukpel\b/i',
        '/\bpanduan\s+teknis\b/i',
        '/\bpedoman\s+teknis\b/i',
        '/\btata\s+cara\b/i',
        '/\btata\s+kerja\b/i',
        '/\bregulasi\b/i',
        '/\bregulasi\s+internal\b/i',
        '/\bnorma\b/i',
        '/\bstandar\b/i',
        '/\bstandar\s+pelayanan\b/i',
        '/\bkriteria\b/i',
        '/\bpanduan\s+penyelenggaraan\b/i',
        '/\bbuku\s+saku\b/i',
        '/\bmanual\b/i',
        '/\bpanduan\s+operasional\b/i',
        '/\binstruksi\s+kerja\b/i',
        '/\bprofil\s+produk\b/i',
        '/\bkuesioner\b/i',
        '/\bformulir\b/i',
        '/\btemplate\b/i',
        '/\bformat\b/i',
        '/\bblangko\b/i',
        '/\biklan\b/i',
        '/\bevaluasi\b/i',
        '/\bmonitoring\b/i',
        '/\bverifikasi\b/i',
        '/\bvalidasi\b/i',
        '/\bpenilaian\b/i',
        '/\bpenilaian\s+kinerja\b/i',
        '/\bpenilaian\s+prestasi\b/i',
        '/\bpenilaian\s+pegawai\b/i',
        '/\bpenilaian\s+skp\b/i',
        '/\bpenilaian\s+dp3\b/i',
        '/\bpenilaian\s+jabatan\b/i',
        '/\bpenilaian\s+fungsional\b/i',
        '/\bpenilaian\s+angka\s+kredit\b/i',
        // Jenis surat/dokumen administratif:
        '/\bsurat\s+keputusan\b/i',
        '/\bsk\b/i',
        '/\bpermohonan\s+bantuan\b/i',
        '/\bpermohonan\s+ijin\b/i',
        '/\bpermohonan\s+izin\b/i',
        '/\bpermohonan\s+narasumber\b/i',
        '/\bpermohonan\s+pembicara\b/i',
        '/\bpermohonan\s+data\b/i',
        '/\bpermohonan\s+informasi\b/i',
        '/\bpermohonan\s+audiensi\b/i',
        '/\bpermohonan\s+rekomendasi\b/i',
        '/\bpermintaan\s+bantuan\b/i',
        '/\bpermintaan\s+data\b/i',
        '/\bnota\s+kesepahaman\b/i',
        '/\bmou\b/i',
        '/\bkerjasama\b/i',
        '/\bperjanjian\s+kerjasama\b/i',
        '/\bpks\b/i',
    ];

    // Cek apakah ada sinyal acara kuat (sosialisasi, rapat, apel, dll) sebelum menolak karena topik prosedural
    $hasStrongEventSignal = (bool) preg_match('/\b(undangan|rapat|meeting|sosialisasi|workshop|seminar|bimtek|bimbingan teknis|pelatihan|diklat|apel|apel\s+pagi|upacara|wawancara|pelantikan|pengambilan\s+sumpah)\b/i', $haystack);

    foreach ($documentTypePatterns as $pattern) {
        if (preg_match($pattern, $haystack) === 1) {
            if ($hasStrongEventSignal && preg_match('/\b(prosedur|sop|evaluasi|monitoring|verifikasi|validasi|pedoman|panduan|tata\s+cara)\b/i', $haystack)) {
                // Topik acara membahas SOP/evaluasi/prosedur, jangan tolak
                continue;
            }
            return false;
        }
    }

    // =====================================================
    // 1. Sinyal positif KUAT: surat ini adalah undangan/acara
    // =====================================================
    $strongInvitationKeywords = [
        'undangan',
        'mengundang',
        'kami mengundang',
        'akan dilaksanakan',
        'akan diselenggarakan',
        'akan diadakan',
        'dilaksanakan pada',
        'diselenggarakan pada',
        'diadakan pada',
        'mengharapkan kehadiran',
        'mohon kehadiran',
        'hari, tanggal',
        'hari dan tanggal',
        'hari/tanggal',
        'hari / tanggal',
        'hari/tgl',
        'hari, tgl',
        'waktu :',
        'pukul :',
        'link zoom',
        'tautan zoom',
        'link meeting',
        'tautan meeting',
        'meeting id',
        'id rapat',
        'passcode',
        'kode sandi',
        'mengikuti rapat',
        'hadir dalam rapat',
        'menghadiri rapat',
        'rapat yang dilaksanakan',
        'pertemuan yang dilaksanakan',
    ];

    $hasStrongInvitation = false;
    foreach ($strongInvitationKeywords as $keyword) {
        if (str_contains($haystack, $keyword)) {
            $hasStrongInvitation = true;
            break;
        }
    }

    // =====================================================
    // 2. Sinyal positif SEDANG: ada kata acara/kegiatan spesifik
    // =====================================================
    $eventKeywords = [
        'rapat', 'meeting', 'webinar', 'sosialisasi',
        'bimtek', 'bimbingan teknis', 'seminar', 'workshop',
        'pelatihan', 'upacara', 'apel', 'apel pagi', 'apel sore',
        'diklat', 'pembekalan', 'orientasi',
        'wawancara', 'supervisi', 'pelantikan', 'pengambilan sumpah',
        'asesmen', 'assesmen',
        'e-learning', 'elearning', 'e learning',
        'peringatan', 'festival', 'turnamen', 'senam',
        'launching', 'peluncuran', 'serah terima', 'penandatanganan',
        'peresmian', 'pembukaan', 'penutupan', 'wisuda',
        'halal bihalal', 'halal-bihalal', 'syukuran', 'tasyakuran',
        'buka puasa', 'buka bersama', 'gathering', 'temu',
        'silaturahmi', 'silaturahim', 'kunjungan', 'studi banding',
        'benchmarking', 'focus group', 'fgd', 'lokakarya',
        'sarasehan', 'simposium', 'kongres', 'konferensi',
        'rakor', 'rapim', 'rapat pimpinan', 'rapat kerja', 'raker',
        'audiensi', 'diskusi', 'pembahasan', 'koordinasi',
        'forum', 'konsultasi', 'sinkronisasi',
        'forum komunikasi', 'rapat mingguan', 'rapat rutin',
        'rapat koordinasi mingguan', 'apresiasi', 'briefing',
        'coffee morning', 'morning briefing', 'daily briefing',
        'weekly meeting', 'monthly meeting',
    ];

    $weakEventKeywords = ['kegiatan', 'acara', 'koordinasi', 'forum'];

    $hasEventKeyword = false;
    foreach ($eventKeywords as $keyword) {
        if (str_contains($haystack, $keyword)) {
            $hasEventKeyword = true;
            break;
        }
    }

    if (!$hasEventKeyword) {
        foreach ($weakEventKeywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                if ($hasStrongInvitation) {
                    $hasEventKeyword = true;
                }
                break;
            }
        }
    }

    if (!$hasStrongInvitation && !$hasEventKeyword) {
        return false;
    }

    // =====================================================
    // 3. Sinyal NEGATIF: jenis surat yang biasanya BUKAN agenda
    // =====================================================
    if (!$hasStrongInvitation) {
        $nonEventPatterns = [
            '/\bsurat\s+instruksi\b/i',
            '/\bsurat\s+edaran\b/i',
            '/\bsurat\s+permohonan\b/i',
            '/\bsurat\s+permintaan\b/i',
            '/\bsurat\s+tugas\b/i',
            '/\bsurat\s+keterangan\b/i',
            '/\bsurat\s+keputusan\b/i',
            '/\bsurat\s+usulan\b/i',
            '/\bsurat\s+pengantar\b/i',
            '/\bsurat\s+kuasa\b/i',
            '/\bsurat\s+mandat\b/i',
            '/\bsurat\s+dispensasi\b/i',
            '/\bpermohonan\b/i',
            '/\bpermintaan\b/i',
            '/\busulan\b/i',
            '/\barahan\b/i',
            '/\blaporan\b/i',
            '/\bpemberitahuan\b/i',
            '/\bpemutakhiran\b/i',
            '/\bcatatan\b/i',
            '/\bpengumuman\b/i',
            '/\biklan\b/i',
            '/\bpemberitahuan\s+umum\b/i',
            '/\bketetapan\b/i',
            '/\bkeputusan\b/i',
            '/\bpenetapan\b/i',
            '/\bpernyataan\b/i',
            '/\babsensi\b/i',
            '/\bdokumen\b/i',
            '/\bmakalah\b/i',
            '/\bmateri\b/i',
            '/\bmodul\b/i',
            '/\bkurikulum\b/i',
            '/\bsilabus\b/i',
            '/\bgambar\s+teknis\b/i',
            '/\bbeban\s+kerja\b/i',
            '/\bjadwal\s+kegiatan\b(?!.*(?:undangan|mengundang|dilaksanakan|diselenggarakan))/i',
            '/\binformasi\b/i',
            '/\bberita\b/i',
            '/\binfo\b/i',
            '/\bsiaran\s+pers\b/i',
            '/\bpress\s+release\b/i',
            '/\bnotifikasi\b/i',
            '/\bperingatan\s+dini\b/i',
            '/\bimbauan\b/i',
            '/\bhimbauan\b/i',
            '/\banjuran\b/i',
            '/\brekomendasi\b/i',
        ];

        foreach ($nonEventPatterns as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                if ($hasStrongEventSignal && preg_match('/\b(dokumen|materi|modul|kurikulum|laporan|catatan|informasi|berita|info)\b/i', $haystack)) {
                    // Topik acara mengenai dokumen/materi/laporan — jangan tolak jika ada sinyal acara kuat
                    continue;
                }
                return false;
            }
        }
    }

    return true;
}

function hasStrongZoomEvidence(
    string $context,
    ?string $zoomUrl,
    string $meetingId,
    string $passcode
): bool {

    // Sinyal KUAT: URL meeting yang valid (zoom.us, meet.google.com, teams, dll.)
    if (isMeetingUrlCandidate($zoomUrl)) {
        return true;
    }

    // Sinyal KUAT: kredensial Zoom (Meeting ID atau Passcode) + konteks zoom
    $hasCredentials = $meetingId !== '' || $passcode !== '';
    if ($hasCredentials && looksLikeZoomMeeting($context)) {
        return true;
    }

    // Catatan: kata "zoom" saja tanpa URL/ID/passcode TIDAK cukup.
    // "password" / "id rapat" / "meeting id" tanpa angka spesifik juga
    // tidak cukup — banyak dokumen administratif menyebut kata-kata ini
    // tanpa benar-benar berisi meeting Zoom.

    return false;
}

function inferPriority(string $text): string
{
    $haystack = strtolower($text);
    $urgentWords = ['segera', 'urgent', 'mendesak', 'prioritas', 'penting'];
    foreach ($urgentWords as $word) {
        if (str_contains($haystack, $word)) {
            return 'tinggi';
        }
    }
    return 'normal';
}

function inferAgendaTimeWindow(string $text): array
{
    $patterns = [
        '/\b(?:waktu|pukul|jam)\s*[:\-\x{2012}\x{2013}\x{2014}\x{2015}]?\s*([01]?\d|2[0-3])(?:[:.]([0-5]\d))?\s*(?:wib|wit|wita)?\s*(?:s\.?\s*d\.?|s\/d|sampai|hingga|[\-\x{2012}\x{2013}\x{2014}\x{2015}])\s*([01]?\d|2[0-3])(?:[:.]([0-5]\d))?\s*(?:wib|wit|wita)?\b/iu',
        '/\b([01]?\d|2[0-3])\s*[.:]\s*([0-5]\d)\s*(?:wib|wit|wita)?\s*(?:s\.?\s*d\.?|s\/d|sampai|hingga|[\-\x{2012}\x{2013}\x{2014}\x{2015}])\s*([01]?\d|2[0-3])\s*[.:]\s*([0-5]\d)\s*(?:wib|wit|wita)?\b/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m) === 1) {
            $h1 = (int) ($m[1] ?? 0);
            $m1 = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            $h2 = (int) ($m[3] ?? 0);
            $m2 = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;

            return [
                'start' => sprintf('%02d:%02d:00', $h1, $m1),
                'end' => sprintf('%02d:%02d:00', $h2, $m2),
            ];
        }
    }

    return ['start' => null, 'end' => null];
}

function inferAgendaTime(string $text): ?string
{
    $pos = stripos($text, 'berubah menjadi');
    if ($pos === false) {
        $pos = stripos($text, 'diubah menjadi');
    }
    if ($pos !== false) {
        $text = substr($text, $pos);
    }

    $window = inferAgendaTimeWindow($text);
    if (!empty($window['start'])) {
        return (string) $window['start'];
    }

    if (preg_match('/\b(?:pukul|jam)\s*([01]?\d|2[0-3])(?:[:.]([0-5]\d))?\b/i', $text, $matches) === 1) {
        $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        return sprintf('%02d:%02d:00', (int) $matches[1], $minute);
    }

    if (preg_match('/\b([01]?\d|2[0-3])[:.]([0-5]\d)\s*(?:wib|wit|wita)\b/i', $text, $matches) === 1) {
        return sprintf('%02d:%02d:00', (int) $matches[1], (int) $matches[2]);
    }

    if (preg_match('/\b([01]?\d|2[0-3])\s*(?:wib|wit|wita)\b/i', $text, $matches) === 1) {
        return sprintf('%02d:00:00', (int) $matches[1]);
    }

    if (preg_match('/\bjam\s+([01]?\d|2[0-3])\b/i', $text, $matches) === 1) {
        return sprintf('%02d:00:00', (int) $matches[1]);
    }

    return null;
}

function normalizeIndonesianMonth(string $month): ?int
{
    $key = strtolower(trim($month));
    $map = [
        'januari' => 1, 'jan' => 1,
        'februari' => 2, 'feb' => 2,
        'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5,
        'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8,
        'september' => 9, 'sep' => 9,
        'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12,
    ];
    return $map[$key] ?? null;
}

function buildYmdDate(int $day, int $month, int $year): ?string
{
    if ($year < 100) {
        $year += 2000;
    }
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}



/**
 * Deteksi agenda rutin berulang: "tiap hari Rabu", "setiap hari Rabu", "rutin tiap Rabu"
 * Kembalikan tanggal kejadian terdekat dari hari tersebut (hari ini jika cocok,
 * atau hari berikutnya dalam minggu ini).
 */
function inferRecurringDay(string $text): ?string
{
    $patterns = [
        '/\b(?:tiap|setiap|rutin(?:\s+tiap)?)\s+hari\s+(senin|selasa|rabu|kamis|jumat|sabtu|minggu)\b/iu',
        '/\b(?:tiap|setiap|rutin(?:\s+tiap)?)\s+(senin|selasa|rabu|kamis|jumat|sabtu|minggu)\b/iu',
        '/\bhari\s+(senin|selasa|rabu|kamis|jumat|sabtu|minggu)\s+(?:tiap|setiap|rutin)\b/iu',
    ];

    $dayMap = [
        'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4,
        'jumat' => 5, 'sabtu' => 6, 'minggu' => 7,
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m) === 1) {
            $dayName = strtolower($m[1]);
            $targetDow = $dayMap[$dayName] ?? null;
            if ($targetDow === null) continue;

            $today = new DateTime('today');
            $todayDow = (int) $today->format('N'); // 1=Senin, 7=Minggu

            if ($todayDow === $targetDow) {
                return $today->format('Y-m-d'); // hari ini
            }

            // Cari kejadian berikutnya dalam minggu ini (bukan minggu depan)
            $diff = $targetDow - $todayDow;
            if ($diff < 0) {
                $diff += 7; // minggu depan
            }
            $next = clone $today;
            $next->modify("+{$diff} days");
            return $next->format('Y-m-d');
        }
    }

    return null;
}

function inferAgendaDate(string $text): ?array
{
    // Jika ada revisi jadwal, fokus pada teks setelah revisi
    $pos = stripos($text, 'berubah menjadi');
    if ($pos === false) {
        $pos = stripos($text, 'diubah menjadi');
    }
    if ($pos !== false) {
        $text = substr($text, $pos);
    }

    // Agenda rutin berulang: "tiap hari Rabu", "setiap hari Rabu", "rutin tiap Rabu"
    $recurring = inferRecurringDay($text);
    if ($recurring !== null) {
        return ['start' => $recurring, 'end' => null];
    }

    $dayName    = '(?:senin|selasa|rabu|kamis|jumat|jum[\'\`\x{2018}\x{2019}]?at|sabtu|minggu)';
    $dayNameOpt = '(?:' . $dayName . '(?:\s*(?:s\.?d\.?|s\/d|[\-\x{2012}\x{2013}\x{2014}\x{2015}]|sampai|hingga)\s*' . $dayName . ')?,?\s*)?';
    $rangeSep   = '(?:\s*(?:s\.?d\.?|s\/d|[\-\x{2012}\x{2013}\x{2014}\x{2015}]|sampai|hingga)\s*)';

    // -----------------------------------------------------------------------
    // 0. POLA PALING KUAT: Format surat resmi "Hari, tanggal : Selasa, 15 September 2026"
    //    Khusus undangan resmi yang mencantumkan "hari, tanggal :" secara eksplisit.
    // -----------------------------------------------------------------------
    $patternLetterFormat = '/\b(?:hari\s*[,\/]?\s*tanggal|hari\s*dan\s*tanggal)\s*[:\-\x{2012}\x{2013}\x{2014}\x{2015}]?\s*' . $dayNameOpt . '(\d{1,2})\s+([a-z\.]+)\s+(\d{4})\b/iu';
    if (preg_match($patternLetterFormat, $text, $m) === 1) {
        $day   = (int) $m[1];
        $month = normalizeIndonesianMonth((string) $m[2]);
        $year  = (int) $m[3];
        if ($month !== null) {
            $date = buildYmdDate($day, $month, $year);
            if ($date !== null) {
                return ['start' => $date, 'end' => null];
            }
        }
    }

    // 0b. Pola "dilaksanakan pada: Selasa, 15 September 2026"
    $patternDilaksanakan = '/\b(?:dilaksanakan\s+pada|akan\s+dilaksanakan\s+pada|diselenggarakan\s+pada|diadakan\s+pada)\s*[:\-]?\s*' . $dayNameOpt . '(\d{1,2})\s+([a-z\.]+)\s+(\d{4})\b/iu';
    if (preg_match($patternDilaksanakan, $text, $m) === 1) {
        $day   = (int) $m[1];
        $month = normalizeIndonesianMonth((string) $m[2]);
        $year  = (int) $m[3];
        if ($month !== null) {
            $date = buildYmdDate($day, $month, $year);
            if ($date !== null) {
                return ['start' => $date, 'end' => null];
            }
        }
    }

    // 1. Cross-month Range with strong context: "hari/tgl: 28 Agustus s.d 2 September 2026"
    $patternCrossMonthStrong = '/\b(?:hari(?:[\/\\,]?\s*tanggal)?|jadwal|pelaksanaan|tanggal|tgl)\s*[:\-\x{2012}\x{2013}\x{2014}\x{2015}]?\s*' . $dayNameOpt . '(\d{1,2})\s+([a-z\.]+)(?:\s+(\d{4}))?' . $rangeSep . $dayNameOpt . '(\d{1,2})\s+([a-z\.]+)\s+(\d{4})\b/iu';
    if (preg_match($patternCrossMonthStrong, $text, $m) === 1) {
        $day1   = (int) $m[1];
        $month1 = normalizeIndonesianMonth((string) $m[2]);
        $year2  = (int) $m[6];
        $year1  = !empty($m[3]) ? (int) $m[3] : $year2;
        $day2   = (int) $m[4];
        $month2 = normalizeIndonesianMonth((string) $m[5]);

        if ($month1 !== null && $month2 !== null) {
            $startDate = buildYmdDate($day1, $month1, $year1);
            $endDate   = buildYmdDate($day2, $month2, $year2);
            if ($startDate !== null) {
                return ['start' => $startDate, 'end' => $endDate];
            }
        }
    }

    // 2. Same-month Range with strong context: "hari/tgl: 28 s.d 30 Agustus 2026"
    $patternsWordsStrong = [
        '/\b(?:hari(?:[\/\\,]?\s*tanggal)?|jadwal|pelaksanaan)\s*[:\-\x{2012}\x{2013}\x{2014}\x{2015}]?\s*' . $dayNameOpt . '(\d{1,2})(?:' . $rangeSep . $dayNameOpt . '(\d{1,2}))?\s+([a-z\.]+)\s+(\d{4})\b/iu',
    ];
    foreach ($patternsWordsStrong as $pattern) {
        if (preg_match($pattern, $text, $m) === 1) {
            $day    = (int) ($m[1] ?? 0);
            $endDay = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            $month  = normalizeIndonesianMonth((string) ($m[3] ?? ''));
            $year   = (int) ($m[4] ?? 0);
            if ($month !== null) {
                $date = buildYmdDate($day, $month, $year);
                if ($date !== null) {
                    $endDate = $endDay !== null ? buildYmdDate($endDay, $month, $year) : null;
                    return ['start' => $date, 'end' => $endDate];
                }
            }
        }
    }

    // 3. Cross-month Range general: "Senin, 28 Agustus s/d Rabu, 2 September 2026"
    $patternCrossMonthGeneral = '/\b' . $dayNameOpt . '(\d{1,2})\s+([a-z\.]+)(?:\s+(\d{4}))?' . $rangeSep . $dayNameOpt . '(\d{1,2})\s+([a-z\.]+)\s+(\d{4})\b/iu';
    if (preg_match_all($patternCrossMonthGeneral, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $index => $match) {
            $offset        = $match[1];
            $contextBefore = strtolower(substr($text, max(0, $offset - 30), 30));
            if (str_contains($contextBefore, 'lambat') || str_contains($contextBefore, 'batas')) {
                continue;
            }
            $day1   = (int) $matches[1][$index][0];
            $month1 = normalizeIndonesianMonth((string) $matches[2][$index][0]);
            $year2  = (int) $matches[6][$index][0];
            $year1  = !empty($matches[3][$index][0]) ? (int) $matches[3][$index][0] : $year2;
            $day2   = (int) $matches[4][$index][0];
            $month2 = normalizeIndonesianMonth((string) $matches[5][$index][0]);

            if ($month1 !== null && $month2 !== null) {
                $startDate = buildYmdDate($day1, $month1, $year1);
                $endDate   = buildYmdDate($day2, $month2, $year2);
                if ($startDate !== null) {
                    return ['start' => $startDate, 'end' => $endDate];
                }
            }
        }
    }

    // 4. Format dengan nama hari (Senin, 29 Juni 2026)
    //    Filter tambahan: lewati jika konteks sebelumnya adalah nomor/metadata header surat
    $patternsWordsMedium = [
        '/\b(?:senin|selasa|rabu|kamis|jumat|jum[\'\`\x{2018}\x{2019}]?at|sabtu|minggu)\s*,?\s*(\d{1,2})(?:' . $rangeSep . '(\d{1,2}))?\s+([a-z\.]+)\s+(\d{4})\b/iu',
    ];
    foreach ($patternsWordsMedium as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $index => $match) {
                $offset        = $match[1];
                $contextBefore = strtolower(substr($text, max(0, $offset - 60), 60));
                // Lewati jika berada di konteks header surat
                if (
                    str_contains($contextBefore, 'lambat')
                    || str_contains($contextBefore, 'batas')
                    || preg_match('/\bnomor\s*:/i', $contextBefore) === 1
                    || preg_match('/\btgl\s*\.?\s*surat\b/i', $contextBefore) === 1
                    || preg_match('/\bkp\.\d{2}\.\d{2}\/[a-z]/i', $contextBefore) === 1
                ) {
                    continue;
                }
                $day    = (int) $matches[1][$index][0];
                $endDay = isset($matches[2][$index][0]) && $matches[2][$index][0] !== '' ? (int) $matches[2][$index][0] : null;
                $month  = normalizeIndonesianMonth((string) $matches[3][$index][0]);
                $year   = (int) $matches[4][$index][0];
                if ($month !== null) {
                    $date = buildYmdDate($day, $month, $year);
                    if ($date !== null) {
                        $endDate = $endDay !== null ? buildYmdDate($endDay, $month, $year) : null;
                        return ['start' => $date, 'end' => $endDate];
                    }
                }
            }
        }
    }

    // 5. Pola umum "tanggal 9 juni 2026"
    $patternsWordsWeak = [
        '/\b(?:tanggal|tgl|waktu|pada)\s*[:\-\x{2012}\x{2013}\x{2014}\x{2015}]?\s*' . $dayNameOpt . '(\d{1,2})(?:' . $rangeSep . '(\d{1,2}))?\s+([a-z\.]+)\s+(\d{4})\b/iu',
    ];
    foreach ($patternsWordsWeak as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $index => $match) {
                $offset        = $match[1];
                $contextBefore = strtolower(substr($text, max(0, $offset - 40), 40));
                if (
                    str_contains($contextBefore, 'surat')
                    || str_contains($contextBefore, 'lambat')
                    || str_contains($contextBefore, 'terdahulu')
                    || str_contains($contextBefore, 'nomor')
                ) {
                    continue;
                }
                $day    = (int) $matches[1][$index][0];
                $endDay = isset($matches[2][$index][0]) && $matches[2][$index][0] !== '' ? (int) $matches[2][$index][0] : null;
                $month  = normalizeIndonesianMonth((string) $matches[3][$index][0]);
                $year   = (int) $matches[4][$index][0];
                if ($month !== null) {
                    $date = buildYmdDate($day, $month, $year);
                    if ($date !== null) {
                        $endDate = $endDay !== null ? buildYmdDate($endDay, $month, $year) : null;
                        return ['start' => $date, 'end' => $endDate];
                    }
                }
            }
        }
    }

    // 6. Numeric date range: "28/08/2026 s/d 02/09/2026"
    $patternNumericRange = '/\b(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2,4})' . $rangeSep . '(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2,4})\b/iu';
    if (preg_match($patternNumericRange, $text, $m) === 1) {
        $startDate = buildYmdDate((int) $m[1], (int) $m[2], (int) $m[3]);
        $endDate   = buildYmdDate((int) $m[4], (int) $m[5], (int) $m[6]);
        if ($startDate !== null) {
            return ['start' => $startDate, 'end' => $endDate];
        }
    }

    // 7. Numeric date single
    $patternsNumeric = [
        '/\b(?:hari(?:[\/\\,]?\s*tanggal)?|tanggal|tgl|jadwal|pelaksanaan|waktu|pada)\s*[:\-\x{2012}\x{2013}\x{2014}\x{2015}]?\s*' . $dayNameOpt . '(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2,4})\b/iu',
    ];
    foreach ($patternsNumeric as $pattern) {
        if (preg_match($pattern, $text, $m) === 1) {
            $day   = (int) ($m[1] ?? 0);
            $month = (int) ($m[2] ?? 0);
            $year  = (int) ($m[3] ?? 0);
            $date  = buildYmdDate($day, $month, $year);
            if ($date !== null) return ['start' => $date, 'end' => null];
        }
    }

    // 8. Fallback: ambil tanggal yang BUKAN header surat (nomor surat / metadata)
    if (preg_match_all('/\b(\d{1,2})(?:' . $rangeSep . '(\d{1,2}))?\s+([a-z\.]+)\s+(\d{4})\b/iu', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $index => $match) {
            $offset        = $match[1];
            $contextBefore = strtolower(substr($text, max(0, $offset - 80), 80));
            if ($offset <= 60) {
                continue; // terlalu dekat awal teks, kemungkinan header
            }
            // Lewati tanggal di dekat nomor surat / metadata header
            if (
                preg_match('/nomor\s*:/i', $contextBefore) === 1
                || preg_match('/\bkp\.\d{2}/i', $contextBefore) === 1
                || preg_match('/\bot\.\d{2}/i', $contextBefore) === 1
                || preg_match('/\bkr\.\d{2}/i', $contextBefore) === 1
                || str_contains($contextBefore, 'lampiran')
            ) {
                continue;
            }
            $day    = (int) $matches[1][$index][0];
            $endDay = isset($matches[2][$index][0]) && $matches[2][$index][0] !== '' ? (int) $matches[2][$index][0] : null;
            $month  = normalizeIndonesianMonth((string) $matches[3][$index][0]);
            $year   = (int) $matches[4][$index][0];
            if ($month !== null) {
                $date = buildYmdDate($day, $month, $year);
                if ($date !== null) {
                    $endDate = $endDay !== null ? buildYmdDate($endDay, $month, $year) : null;
                    return ['start' => $date, 'end' => $endDate];
                }
            }
        }
    }

    return null;
}

function buildReminderH1At(string $agendaDate, ?string $agendaTime): ?string
{
    if (!$agendaTime) {
        return null;
    }
    $ts = strtotime($agendaDate . ' ' . $agendaTime);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', strtotime('-1 day', $ts));
}

function buildInvitationTemplate(
    string $counterparty,
    string $title,
    string $agendaDate,
    ?string $agendaTime,
    ?string $agendaEndTime,
    string $agendaMode,
    string $location,
    string $zoomLink,
    string $zoomMeetingId = '',
    string $zoomPasscode = ''
): string {
    $party = trim($counterparty) !== '' ? trim($counterparty) : 'Bapak/Ibu';
    $dateLabel = date('d-m-Y', strtotime($agendaDate));
    $startLabel = $agendaTime ? substr($agendaTime, 0, 5) : 'sesuai surat';
    $endLabel = ($agendaTime && $agendaEndTime) ? substr($agendaEndTime, 0, 5) : null;
    $timeLabel = $endLabel ? ($startLabel . '-' . $endLabel) : $startLabel;

    if ($agendaMode === 'zoom') {
        $template = "Yth. {$party},\n"
            . "Dengan hormat, kami mengundang Anda untuk mengikuti {$title} pada {$dateLabel} pukul {$timeLabel} WIB melalui Zoom Meeting.\n"
            . "Link Zoom: {$zoomLink}\n";
            
        if ($zoomMeetingId !== '') {
            $template .= "Meeting ID: {$zoomMeetingId}\n";
        }
        if ($zoomPasscode !== '') {
            $template .= "Passcode: {$zoomPasscode}\n";
        }
            
        $template .= "Mohon kehadiran tepat waktu. Terima kasih.";
        return $template;
    }

    return "Yth. {$party},\n"
        . "Dengan hormat, kami mengundang Anda untuk menghadiri {$title} pada {$dateLabel} pukul {$timeLabel} WIB di {$location}.\n"
        . "Mohon konfirmasi kehadiran. Terima kasih.";
}

function autoGenerateAgendaFromLetter(PDO $pdo, int $letterId, bool $skipAttachment = false): array
{
    $stmt = $pdo->prepare("SELECT * FROM agendas WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $letterId]);
    $letter = $stmt->fetch();

    if (!$letter || ($letter['entry_type'] ?? '') !== 'surat') {
        return ['created' => false, 'updated' => false, 'mode' => null];
    }

    $headerContext = implode(' ', [
        (string) ($letter['title'] ?? ''),
        (string) ($letter['description'] ?? ''),
        (string) ($letter['notes'] ?? ''),
        (string) ($letter['counterparty'] ?? ''),
        (string) ($letter['reference_number'] ?? ''),
    ]);

    // Mode cleanup cepat: skip OCR attachment untuk klasifikasi awal.
    // OCR hanya perlu jika ingin ekstrak zoom link dari lampiran.
    if ($skipAttachment) {
        $attachmentText = '';
    } else {
        $attachmentText = extractAttachmentText((string) ($letter['attachment'] ?? ''));
    }
    $context = trim($headerContext . ' ' . (string) $attachmentText);

    $manualZoomUrl = trim((string) ($letter['zoom_link'] ?? ''));
    if ($manualZoomUrl !== '') {
        $manualZoomUrl = normalizeUrlCandidate($manualZoomUrl);
        if (!isMeetingUrlCandidate($manualZoomUrl)) {
            $manualZoomUrl = '';
        }
    }
    $zoomUrl = null;
    if ($manualZoomUrl !== '') {
        $zoomUrl = $manualZoomUrl;
    } else {
        $candidateUrl = extractMeetingUrlStrict($headerContext)
            ?? extractMeetingUrlStrict($context)
            ?? extractFirstUrl($headerContext)
            ?? extractFirstUrl($context);
        if (isMeetingUrlWithContext($candidateUrl, $context)) {
            $zoomUrl = $candidateUrl;
        } elseif ($candidateUrl !== null && (looksLikeZoomMeeting($context) || hasMeetingCredentialHints($context))) {
            $zoomUrl = $candidateUrl;
        }
    }
    $meetingId = trim((string) ($letter['zoom_meeting_id'] ?? ''));
    if ($meetingId === '') {
        $meetingId = extractZoomMeetingId($context) ?? extractZoomMeetingId((string) ($letter['zoom_meeting_id'] ?? '')) ?? '';
    }
    $passcode = trim((string) ($letter['zoom_passcode'] ?? ''));
    if ($passcode === '') {
        $passcode = extractZoomPasscode($context) ?? '';
    }
    $isZoom = hasStrongZoomEvidence($context, $zoomUrl, $meetingId, $passcode)
        || $manualZoomUrl !== ''
        || (str_contains(strtolower((string) ($letter['location'] ?? '')), 'zoom')
            && isMeetingUrlWithContext($zoomUrl, $context));

    $baseAgendaDate = '';
    $baseAgendaDateEnd = null;
    $agendaDateInferred = false; // true = tanggal kegiatan ditemukan dari isi/lampiran surat

    if (!empty($letter['agenda_date']) && $letter['agenda_date'] !== '0000-00-00' && (!empty($letter['agenda_date_end']) || $letter['agenda_date'] !== ($letter['letter_date'] ?? ''))) {
        $baseAgendaDate     = (string) $letter['agenda_date'];
        $baseAgendaDateEnd  = !empty($letter['agenda_date_end']) ? (string) $letter['agenda_date_end'] : null;
        $agendaDateInferred = true;
    } else {
        // Prioritas: teks lengkap (judul + isi lampiran) dulu, baru header.
        // Header/deskripsi sering hanya memuat tanggal surat; jika dicek lebih
        // dulu, tanggal pelaksanaan yang ada di isi surat tidak pernah terbaca.
        $inferredAgendaDateResult = inferAgendaDate($context) ?? inferAgendaDate($headerContext);
        if ($inferredAgendaDateResult !== null) {
            $baseAgendaDate     = $inferredAgendaDateResult['start'];
            $baseAgendaDateEnd  = $inferredAgendaDateResult['end'];
            $agendaDateInferred = true;
        }
    }

    if ($baseAgendaDate === '') {
        $val = (string) ($letter['agenda_date'] ?? '');
        if ($val !== '' && $val !== '0000-00-00') {
            $baseAgendaDate     = $val;
            $agendaDateInferred = true;
        }
    }

    $title = (string) $letter['title'];
    $reference = (string) ($letter['reference_number'] ?: ('AUTO-SURAT-' . $letterId));
    $description = trim(
        "Dibuat otomatis oleh AI dari surat #" . $letterId . ".\n" .
        "Judul surat: " . (string) $letter['title'] . "\n" .
        "Ringkasan: " . (string) ($letter['description'] ?? '-')
    );

    // Query existing agenda SEBELUM fallback tanggal,
    // supaya bisa pakai tanggal existing dan bisa cleanup jika surat bukan event.
    // HANYA cari yang source_agenda_id = surat ini (bukan reference_number),
    // agar agenda manual yang kebetulan punya reference_number sama tidak terhapus.
    // Pastikan koneksi PDO tetap hidup setelah proses ekstraksi lampiran (OCR/download)
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable) {
        $pdo = getPdo();
    }

    $existingStmt = $pdo->prepare(
        "SELECT id, agenda_time, agenda_mode, zoom_link, agenda_date, agenda_date_end, ai_generated
         FROM agendas
         WHERE source_agenda_id = :source_id AND entry_type = 'kegiatan'
         LIMIT 1"
    );
    $existingStmt->execute(['source_id' => $letterId]);
    $existingRow = $existingStmt->fetch();
    $existingId = $existingRow['id'] ?? null;
    $existingAiGenerated = (int) ($existingRow['ai_generated'] ?? 0);

    // Agenda anak yang sudah diedit manual oleh user (ai_generated=0) adalah
    // milik user — jangan diubah/ditimpa oleh AI (termasuk tanggal pelaksanaan).
    if ($existingId && $existingAiGenerated === 0) {
        return ['created' => false, 'updated' => false, 'mode' => (string) ($existingRow['agenda_mode'] ?? '') ?: null];
    }

    // Jangan biarkan tanggal surat menimpa tanggal pelaksanaan yang sudah benar
    // pada agenda anak. agenda_date surat yang sama dengan letter_date hanya
    // salinan default; tanggal anak yang berbeda adalah hasil ekstraksi isi
    // surat yang valid dan harus dipertahankan.
    $letterDateValue = (string) ($letter['letter_date'] ?? '');
    if (
        $existingId
        && $baseAgendaDate !== ''
        && $letterDateValue !== ''
        && $baseAgendaDate === $letterDateValue
        && !empty($existingRow['agenda_date'])
        && (string) $existingRow['agenda_date'] !== $letterDateValue
    ) {
        $baseAgendaDate    = (string) $existingRow['agenda_date'];
        $baseAgendaDateEnd = !empty($existingRow['agenda_date_end']) ? (string) $existingRow['agenda_date_end'] : null;
    }


    // =====================================================
    // CLEANUP: Jika surat BUKAN undangan/acara kegiatan,
    // hapus agenda AI-generated yang mungkin dibuat salah oleh versi lama.
    // Hanya hapus yang ai_generated=1 (dibuat AI), bukan manual user.
    // =====================================================
    $manualEventSignal = !empty($letter['agenda_date'])
        && $letter['agenda_date'] !== '0000-00-00'
        && (!empty($letter['agenda_date_end']) || $letter['agenda_date'] !== ($letter['letter_date'] ?? ''));

    $isEventInvitation = $manualEventSignal || isLetterEventInvitation($context, $headerContext);

    // =====================================================
    // Nota Dinas, Surat Tugas, & Bukti Zoom:
    // - Nota Dinas dengan tulisan "agenda" (di judul/isi/lampiran) → SELALU dibuat agenda kegiatan
    // - Surat Tugas                                                → SELALU dibuat agenda kegiatan
    // - Berisi link/tautan/passcode Zoom                          → SELALU dibuat agenda Zoom
    // Aturan ini meng-override heuristik judul agar agenda tidak lolos.
    // =====================================================
    $allContextCombined = $headerContext . ' ' . $context;
    $isNotaDinas = (bool) preg_match('/\bnota\s+dinas\b/i', $allContextCombined);
    // Nota Dinas selalu dibuat/dipertahankan sebagai agenda kegiatan meskipun
    // kata "agenda" tidak terbaca dari teks (mis. hanya ada di lampiran yang
    // gagal di-OCR). Tanpa ini, cleanup akan menghapus agenda Nota Dinas yang
    // sebenarnya valid.
    $isNotaDinasWithAgenda = $isNotaDinas;
    $isSuratTugas = (bool) preg_match('/\bsurat\s+tugas\b/i', $allContextCombined);
    $hasZoomLink = $zoomUrl !== null && isMeetingUrlWithContext($zoomUrl, $context);

    if ($isNotaDinasWithAgenda || $isSuratTugas || $isZoom || $hasZoomLink) {
        $isEventInvitation = true;
        $agendaDateInferred = true; // biarkan fallback tanggal surat jika tanggal spesifik belum terurai
    }

    if (!$isEventInvitation) {
        // Mode cleanup cepat (skipAttachment=true) mengklasifikasi hanya dari
        // judul/deskripsi. Bila surat punya lampiran, bukti acara bisa berada di
        // lampiran yang belum di-OCR — JANGAN hapus agenda yang sudah ada.
        $hasAttachment = trim((string) ($letter['attachment'] ?? '')) !== '';
        if ($skipAttachment && $hasAttachment) {
            return ['created' => false, 'updated' => false, 'mode' => null];
        }
        if ($existingId && $existingAiGenerated === 1) {
            $deleteStmt = $pdo->prepare("DELETE FROM agendas WHERE id = :id");
            $deleteStmt->execute(['id' => (int) $existingId]);
            return ['created' => false, 'updated' => false, 'mode' => null, 'cleaned' => true];
        }
        return ['created' => false, 'updated' => false, 'mode' => null];
    }

    // Guard: jangan buat agenda BARU jika surat tidak memiliki tanggal kegiatan.
    // (Tapi surat undangan / zoom tetap diproses walau tanggal belum ketemu,
    //  akan pakai fallback tanggal surat.)
    if (!$agendaDateInferred && !$existingId && !$hasZoomLink && !$isZoom) {
        return ['created' => false, 'updated' => false, 'mode' => null];
    }

    // Fallback tanggal: jika tanggal kegiatan tidak ditemukan dari isi surat,
    // gunakan prioritas: tanggal existing (jika BUKAN hari ini dari fallback lama)
    // → tanggal surat → hari ini (hanya untuk agenda baru).
    if ($baseAgendaDate === '') {
        $today = date('Y-m-d');
        $letterDate = (string) ($letter['letter_date'] ?? '');
        $recordedDate = (string) ($letter['recorded_date'] ?? '');

        if ($existingId && !empty($existingRow['agenda_date'])) {
            $existingDate = (string) $existingRow['agenda_date'];
            // Jika tanggal existing = hari ini, kemungkinan besar dari fallback kode lama.
            // Ganti dengan tanggal surat jika ada dan berbeda.
            if ($existingDate === $today && $letterDate !== '' && $letterDate !== $today) {
                $baseAgendaDate = $letterDate;
            } elseif ($existingDate === $today && $recordedDate !== '' && $recordedDate !== $today) {
                $baseAgendaDate = $recordedDate;
            } else {
                // Tanggal existing valid (bukan hari ini dari fallback), pertahankan.
                $baseAgendaDate    = $existingDate;
                $baseAgendaDateEnd = !empty($existingRow['agenda_date_end']) ? (string) $existingRow['agenda_date_end'] : null;
            }
        } else {
            // Agenda baru: pakai tanggal surat, atau hari ini sebagai fallback terakhir.
            $baseAgendaDate = $letterDate !== ''
                ? $letterDate
                : ($recordedDate !== '' ? $recordedDate : $today);
        }
    }

    $existingTime = null;
    $existingMode = '';
    $existingZoomLink = '';
    if ($existingId) {
        $existingTime = (string) ($existingRow['agenda_time'] ?? '');
        $existingMode = strtolower(trim((string) ($existingRow['agenda_mode'] ?? '')));
        $existingZoomLink = trim((string) ($existingRow['zoom_link'] ?? ''));
    }
    // Jangan pertahankan mode zoom lama jika surat tidak punya bukti zoom yang kuat.
    // (Hanya pertahankan jika masih ada zoom_link valid di data existing.)
    if (!$isZoom && $existingMode === 'zoom' && $existingZoomLink !== '' && isMeetingUrlWithContext($existingZoomLink, $context) && hasMeetingCredentialHints($context)) {
        $isZoom = true;
    }
    if ($zoomUrl === null && $isZoom && $existingZoomLink !== '' && isMeetingUrlWithContext($existingZoomLink, $context)) {
        $zoomUrl = $existingZoomLink;
    }
    $agendaMode = $isZoom ? 'zoom' : 'offline';
    $rawLocation = trim((string) ($letter['location'] ?? ''));
    if ($isZoom) {
        $location = ($rawLocation === '' || strcasecmp($rawLocation, 'sesuai surat') === 0)
            ? 'Online (Zoom)'
            : $rawLocation;
    } else {
        $location = $rawLocation !== '' ? $rawLocation : 'Sesuai surat';
    }
    $manualAgendaTime = null;
    if (!empty($letter['agenda_time'])) {
        $manualAgendaTime = substr((string) $letter['agenda_time'], 0, 8);
        if ($manualAgendaTime === '00:00:00') {
            $manualAgendaTime = null;
        }
    }
    $parsedAgendaTime = inferAgendaTime($headerContext) ?? inferAgendaTime($context);
    $timeWindow = inferAgendaTimeWindow($headerContext);
    if (empty($timeWindow['end']) && empty($timeWindow['start'])) {
        $timeWindow = inferAgendaTimeWindow($context);
    }
    $parsedAgendaEndTime = !empty($timeWindow['end']) ? (string) $timeWindow['end'] : null;
    $agendaTime = $manualAgendaTime ?? $parsedAgendaTime;
    if ($agendaTime === null && $existingTime !== null) {
        $existingNormalized = substr((string) $existingTime, 0, 8);
        $agendaTime = $existingNormalized !== '' ? $existingNormalized : null;
    }
    if ($agendaTime === null) {
        $agendaTime = '09:00:00';
    }
    $reminderH1At = buildReminderH1At($baseAgendaDate, $agendaTime);
    $invitationTemplate = buildInvitationTemplate(
        (string) ($letter['counterparty'] ?? ''),
        $title,
        $baseAgendaDate,
        $agendaTime,
        $parsedAgendaEndTime,
        $agendaMode,
        $location,
        (string) ($zoomUrl ?? ''),
        (string) ($meetingId ?? ''),
        (string) ($passcode ?? '')
    );

    $payload = [
        'user_id' => (int) ($letter['user_id'] ?? 0) > 0 ? (int) $letter['user_id'] : null,
        'entry_type' => 'kegiatan',
        'agenda_mode' => $agendaMode,
        'source_agenda_id' => $letterId,
        'ai_generated' => 1,
        'agenda_time' => $agendaTime,
        'reminder_h1_enabled' => isset($letter['reminder_h1_enabled']) ? (int) $letter['reminder_h1_enabled'] : 1,
        'reminder_h1_at' => $reminderH1At,
        'reminder_h1_sent_at' => null,
        'invitation_template' => $invitationTemplate,
        'direction' => (string) $letter['direction'],
        'title' => $title,
        'reference_number' => $reference,
        'letter_date' => null,
        'recorded_date' => null,
        'counterparty' => (string) ($letter['counterparty'] ?? ''),
        'agenda_date' => $baseAgendaDate,
        'agenda_date_end' => $baseAgendaDateEnd,
        'status' => 'draft',
        'priority' => inferPriority($context),
        'location' => $location,
        'zoom_link' => $isZoom ? (string) ($zoomUrl ?? '') : '',
        'zoom_meeting_id' => $isZoom ? (string) ($meetingId ?? '') : '',
        'zoom_passcode' => $isZoom ? (string) ($passcode ?? '') : '',
        'attachment' => (string) ($letter['attachment'] ?? ''),
        'description' => $description,
        'notes' => 'AI Assistant: agenda ini dibuat otomatis dari surat masuk/keluar.',
    ];

    if ($existingId) {
        $payload['id'] = (int) $existingId;
        $sql = "UPDATE agendas SET
                    user_id = :user_id,
                    entry_type = :entry_type,
                    agenda_mode = :agenda_mode,
                    source_agenda_id = :source_agenda_id,
                    ai_generated = :ai_generated,
                    agenda_time = :agenda_time,
                    reminder_h1_enabled = :reminder_h1_enabled,
                    reminder_h1_at = :reminder_h1_at,
                    reminder_h1_sent_at = :reminder_h1_sent_at,
                    invitation_template = :invitation_template,
                    direction = :direction,
                    title = :title,
                    reference_number = :reference_number,
                    letter_date = :letter_date,
                    recorded_date = :recorded_date,
                    counterparty = :counterparty,
                    agenda_date = :agenda_date,
                    agenda_date_end = :agenda_date_end,
                    status = :status,
                    priority = :priority,
                    location = :location,
                    zoom_link = :zoom_link,
                    zoom_meeting_id = :zoom_meeting_id,
                    zoom_passcode = :zoom_passcode,
                    attachment = :attachment,
                    description = :description,
                    notes = :notes
                WHERE id = :id";
        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($payload);

        return ['created' => false, 'updated' => true, 'mode' => $agendaMode];
    }

    $sql = "INSERT INTO agendas
            (user_id, entry_type, agenda_mode, source_agenda_id, ai_generated, agenda_time, reminder_h1_enabled, reminder_h1_at, reminder_h1_sent_at, invitation_template, direction, title, reference_number, letter_date, recorded_date, counterparty, agenda_date, agenda_date_end, status, priority, location, zoom_link, zoom_meeting_id, zoom_passcode, attachment, description, notes)
            VALUES
            (:user_id, :entry_type, :agenda_mode, :source_agenda_id, :ai_generated, :agenda_time, :reminder_h1_enabled, :reminder_h1_at, :reminder_h1_sent_at, :invitation_template, :direction, :title, :reference_number, :letter_date, :recorded_date, :counterparty, :agenda_date, :agenda_date_end, :status, :priority, :location, :zoom_link, :zoom_meeting_id, :zoom_passcode, :attachment, :description, :notes)";
    $insertStmt = $pdo->prepare($sql);
    $insertStmt->execute($payload);

    return ['created' => true, 'updated' => false, 'mode' => $agendaMode];
}

/**
 * Ekstrak info Zoom (link, meeting ID, passcode) dari attachment kegiatan/flyer.
 * Berbeda dengan autoGenerateAgendaFromLetter yang membuat agenda baru dari surat,
 * fungsi ini hanya meng-update field zoom_* pada entri kegiatan yang sudah ada.
 *
 * Dipakai untuk:
 * - Kegiatan manual (entry_type='kegiatan', ai_generated=0) yang punya flyer attachment
 * - Kegiatan AI-generated yang zoom_link-nya masih kosong (mis: OCR pertama gagal)
 *
 * @return array{updated: bool, mode: ?string, zoom_link: ?string}
 */
function autoExtractZoomFromKegiatan(PDO $pdo, int $kegiatanId): array
{
    $stmt = $pdo->prepare("SELECT * FROM agendas WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $kegiatanId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['updated' => false, 'mode' => null, 'zoom_link' => null];
    }

    $entryType = (string) ($row['entry_type'] ?? '');
    // Hanya proses kegiatan (bukan surat — surat punya pipeline sendiri)
    if ($entryType !== 'kegiatan') {
        return ['updated' => false, 'mode' => null, 'zoom_link' => null];
    }

    $attachmentPath = trim((string) ($row['attachment'] ?? ''));
    if ($attachmentPath === '') {
        return ['updated' => false, 'mode' => null, 'zoom_link' => null];
    }

    // Jika zoom_link sudah ada dan valid, skip
    $existingZoom = trim((string) ($row['zoom_link'] ?? ''));
    if ($existingZoom !== '' && isMeetingUrlCandidate($existingZoom)) {
        return ['updated' => false, 'mode' => (string) ($row['agenda_mode'] ?? ''), 'zoom_link' => $existingZoom];
    }

    // Ekstrak teks dari attachment (flyer PDF/image)
    $attachmentText = extractAttachmentText($attachmentPath);
    if ($attachmentText === '') {
        return ['updated' => false, 'mode' => (string) ($row['agenda_mode'] ?? ''), 'zoom_link' => null];
    }

    // Header context (title, description, notes, location, counterparty)
    $headerContext = implode(' ', [
        (string) ($row['title'] ?? ''),
        (string) ($row['description'] ?? ''),
        (string) ($row['notes'] ?? ''),
        (string) ($row['location'] ?? ''),
        (string) ($row['counterparty'] ?? ''),
    ]);
    $context = trim($headerContext . ' ' . $attachmentText);

    // Ekstrak zoom link
    $zoomUrl = extractMeetingUrlStrict($context)
        ?? extractMeetingUrlStrict($attachmentText)
        ?? extractFirstUrl($context)
        ?? extractFirstUrl($attachmentText);

    if ($zoomUrl !== null && !isMeetingUrlCandidate($zoomUrl)) {
        // Jika bukan meeting URL tapi konteks menunjukkan zoom meeting, tetap pakai
        if (!(looksLikeZoomMeeting($context) || hasMeetingCredentialHints($context))) {
            $zoomUrl = null;
        }
    }

    // Ekstrak meeting ID & passcode
    $meetingId = trim((string) ($row['zoom_meeting_id'] ?? ''));
    if ($meetingId === '') {
        $meetingId = extractZoomMeetingId($context) ?? extractZoomMeetingId($attachmentText) ?? '';
    }
    $passcode = trim((string) ($row['zoom_passcode'] ?? ''));
    if ($passcode === '') {
        $passcode = extractZoomPasscode($context) ?? extractZoomPasscode($attachmentText) ?? '';
    }

    // Deteksi mode zoom — gunakan kriteria yang lebih ketat:
    // hanya tandai zoom jika ada URL meeting valid, kredensial zoom,
    // atau hint kredensial meeting. Kata "zoom" saja di judul tidak cukup.
    $titleLower = strtolower((string) ($row['title'] ?? ''));
    $locationLower = strtolower((string) ($row['location'] ?? ''));

    $isZoom = hasStrongZoomEvidence($context, $zoomUrl, $meetingId, $passcode)
        || (str_contains($locationLower, 'zoom') && isMeetingUrlCandidate($zoomUrl))
        || (str_contains($titleLower, 'zoom') && isMeetingUrlCandidate($zoomUrl))
        || (str_contains($titleLower, 'webinar') && isMeetingUrlCandidate($zoomUrl));

    $agendaMode = $isZoom ? 'zoom' : (string) ($row['agenda_mode'] ?? 'offline');

    // Hanya update jika ada yang berubah
    $newZoomLink = $isZoom ? ($zoomUrl ?? '') : '';
    $newMeetingId = $isZoom ? $meetingId : '';
    $newPasscode = $isZoom ? $passcode : '';

    if ($newZoomLink === '' && $newMeetingId === '' && $newPasscode === '' && !$isZoom) {
        return ['updated' => false, 'mode' => $agendaMode, 'zoom_link' => null];
    }

    $updateStmt = $pdo->prepare(
        "UPDATE agendas
         SET agenda_mode = :agenda_mode,
             zoom_link = :zoom_link,
             zoom_meeting_id = :zoom_meeting_id,
             zoom_passcode = :zoom_passcode
         WHERE id = :id"
    );
    $updateStmt->execute([
        'agenda_mode' => $agendaMode,
        'zoom_link' => $newZoomLink,
        'zoom_meeting_id' => $newMeetingId,
        'zoom_passcode' => $newPasscode,
        'id' => $kegiatanId,
    ]);

    return ['updated' => true, 'mode' => $agendaMode, 'zoom_link' => $newZoomLink !== '' ? $newZoomLink : null];
}
