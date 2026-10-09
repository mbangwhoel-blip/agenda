<?php
$folder = "C:\\Users\\DELL\\Downloads\\Compressed\\drive-download-20260902T052908Z-1-001";
if (!is_dir($folder)) {
    echo "Folder tidak ditemukan: $folder\n";
    exit(1);
}

echo "Menghubungi server untuk mengambil daftar file Google Drive...\n";
$json = @file_get_contents("https://skmbblkl.my.id/agendakepala/test_gdrive.php?api=file_status");

if (!$json) {
    echo "Gagal menghubungi server. Pastikan file test_gdrive.php sudah diupload ke cPanel.\n";
    exit(1);
}

$data = json_decode($json, true);
if (!isset($data['drive_files']) || empty($data['drive_files'])) {
    echo "Daftar file di server belum terbaca. Mohon pastikan file test_gdrive.php terbaru sudah diupload ke cPanel.\n";
    exit(1);
}

$driveNames = [];
foreach ($data['drive_files'] as $df) {
    $driveNames[strtolower(trim($df['name']))] = true;
    $cleanDf = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $df['name']));
    if ($cleanDf !== '') {
        $driveNames[$cleanDf] = true;
    }
}

$localFiles = scandir($folder);
$deletedCount = 0;
$keptCount = 0;
$keptList = [];

foreach ($localFiles as $f) {
    if ($f === '.' || $f === '..') continue;
    $filePath = $folder . '\\' . $f;
    if (!is_file($filePath)) continue;

    $fNameLower = strtolower(trim($f));
    $cleanLocal = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $f));

    if (isset($driveNames[$fNameLower]) || isset($driveNames[$cleanLocal])) {
        // Sudah ada di Google Drive -> Hapus dari folder lokal
        @unlink($filePath);
        $deletedCount++;
    } else {
        // Belum ada di Google Drive -> Simpan untuk diupload
        $keptCount++;
        $keptList[] = $f;
    }
}

echo "========================================\n";
echo "HASIL PENYORTIRAN FOLDER LOKAL:\n";
echo " - File yang SUDAH di Google Drive (BERHASIL DIHAPUS): $deletedCount file\n";
echo " - File yang BELUM di Google Drive (DISIMPAN DI FOLDER): $keptCount file\n";
echo "========================================\n";