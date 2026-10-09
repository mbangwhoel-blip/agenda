<?php
/**
 * Test koneksi Google Drive - untuk debugging
 * Akses: http://localhost/agenda/test_gdrive_simple.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/google_drive_storage.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Google Drive</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; border-radius: 8px; margin: 10px 0; border: 1px solid #ddd; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f0f0f0; padding: 10px; overflow: auto; }
    </style>
</head>
<body>
    <h1>Test Koneksi Google Drive</h1>
    
    <div class="box">
        <h2>1. Status Konfigurasi</h2>
        <?php
        $enabled = GoogleDriveStorage::isEnabled();
        echo "<p>GOOGLE_DRIVE_ENABLED: " . ($enabled ? '<span class="success">true</span>' : '<span class="error">false</span>') . "</p>";
        
        $folderId = trim((string) env('GOOGLE_DRIVE_FOLDER_ID', ''));
        echo "<p>GOOGLE_DRIVE_FOLDER_ID: " . ($folderId !== '' ? '<span class="info">' . htmlspecialchars($folderId) . '</span>' : '<span class="error">KOSONG</span>') . "</p>";
        
        $refreshToken = trim((string) env('GOOGLE_DRIVE_REFRESH_TOKEN', ''));
        $clientId = trim((string) env('GOOGLE_DRIVE_CLIENT_ID', ''));
        $clientSecret = trim((string) env('GOOGLE_DRIVE_CLIENT_SECRET', ''));
        
        echo "<p>OAuth Refresh Token: " . ($refreshToken !== '' ? '<span class="info">ADA (' . strlen($refreshToken) . ' karakter)</span>' : '<span class="error">KOSONG</span>') . "</p>";
        echo "<p>OAuth Client ID: " . ($clientId !== '' ? '<span class="info">ADA</span>' : '<span class="error">KOSONG</span>') . "</p>";
        echo "<p>OAuth Client Secret: " . ($clientSecret !== '' ? '<span class="info">ADA</span>' : '<span class="error">KOSONG</span>') . "</p>";
        
        $serviceAccountPath = trim((string) env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON_PATH', ''));
        echo "<p>Service Account Path: " . ($serviceAccountPath !== '' ? '<span class="info">' . htmlspecialchars($serviceAccountPath) . '</span>' : '<span class="error">KOSONG</span>') . "</p>";
        
        $fullPath = __DIR__ . '/' . $serviceAccountPath;
        $pathExists = is_file($fullPath);
        echo "<p>File Service Account: " . ($pathExists ? '<span class="success">DITEMUKAN</span>' : '<span class="error">TIDAK DITEMUKAN di ' . htmlspecialchars($fullPath) . '</span>') . "</p>";
        ?>
    </div>
    
    <div class="box">
        <h2>2. Test Autentikasi</h2>
        <?php
        if (!$enabled) {
            echo '<p class="error">Google Drive tidak diaktifkan. Set GOOGLE_DRIVE_ENABLED=true di .env</p>';
        } else {
            // Coba dapatkan access token
            $reflection = new ReflectionClass('GoogleDriveStorage');
            $method = $reflection->getMethod('accessToken');
            $method->setAccessible(true);
            
            $token = $method->invoke(null);
            
            if ($token !== null) {
                echo '<p class="success">✓ Access Token berhasil didapatkan (' . strlen($token) . ' karakter)</p>';
            } else {
                echo '<p class="error">✗ Gagal mendapatkan Access Token</p>';
                echo '<p class="error">Error: ' . htmlspecialchars(GoogleDriveStorage::lastError()) . '</p>';
            }
        }
        ?>
    </div>
    
    <div class="box">
        <h2>3. Test Upload File</h2>
        <?php
        if ($enabled && isset($token) && $token !== null) {
            // Buat file test
            $testFile = tempnam(sys_get_temp_dir(), 'gdrive_test_');
            file_put_contents($testFile, "Test upload dari agenda pada " . date('Y-m-d H:i:s'));
            
            $result = GoogleDriveStorage::upload($testFile, 'test_upload_' . date('Ymd_His') . '.txt', 'text/plain');
            @unlink($testFile);
            
            if ($result !== null) {
                echo '<p class="success">✓ Upload berhasil!</p>';
                echo '<pre>' . print_r($result, true) . '</pre>';
            } else {
                echo '<p class="error">✗ Upload gagal</p>';
                echo '<p class="error">Error: ' . htmlspecialchars(GoogleDriveStorage::lastError()) . '</p>';
            }
        } else {
            echo '<p class="info">Lewati test upload karena autentikasi gagal.</p>';
        }
        ?>
    </div>
    
    <div class="box">
        <h2>4. Solusi</h2>
        <?php if (empty($refreshToken) || empty($clientId) || empty($clientSecret)): ?>
            <p><strong>Masalah:</strong> Konfigurasi OAuth tidak lengkap.</p>
            <p><strong>Solusi:</strong></p>
            <ol>
                <li>Buka <a href="test_gdrive.php" target="_blank">test_gdrive.php</a></li>
                <li>Masukkan OAuth Client ID dan Client Secret dari Google Cloud Console</li>
                <li>Klik "Hubungkan dengan Akun Google"</li>
                <li>Login dengan akun Google yang memiliki akses ke folder Drive</li>
            </ol>
        <?php elseif (!$pathExists): ?>
            <p><strong>Masalah:</strong> File Service Account JSON tidak ditemukan.</p>
            <p><strong>Solusi:</strong></p>
            <ol>
                <li>Pastikan file <code>google-service-account.json</code> ada di folder <code>app/</code></li>
                <li>Atau perbarui path di .env: <code>GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON_PATH=app/google-service-account.json</code></li>
            </ol>
        <?php else: ?>
            <p class="success">Konfigurasi terlihat OK. Jika masih gagal, coba hubungkan ulang OAuth di <a href="test_gdrive.php" target="_blank">test_gdrive.php</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
