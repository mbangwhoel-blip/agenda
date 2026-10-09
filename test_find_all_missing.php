<?php
$json = file_get_contents("https://skmbblkl.my.id/agendakepala/test_gdrive.php?api=file_status");
$data = json_decode($json, true);

$missingFiles = [];
foreach ($data['agendas'] ?? [] as $ag) {
    $att = (string)$ag['attachment'];
    if (!str_starts_with($att, 'gdrive:')) {
        $missingFiles[strtolower(basename($att))] = $ag;
    }
}

echo "Mencari " . count($missingFiles) . " file di seluruh folder Downloads & Komputer...\n";

$searchDirs = [
    "C:\\Users\\DELL\\Downloads",
    "C:\\Users\\DELL\\Desktop",
    "C:\\Users\\DELL\\Documents",
    "C:\\xampp\\htdocs"
];

$foundLocations = [];

foreach ($searchDirs as $sDir) {
    if (!is_dir($sDir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $fName = strtolower($file->getFilename());
            if (isset($missingFiles[$fName])) {
                $foundLocations[$fName] = $file->getPathname();
            }
        }
    }
}

echo "========================================\n";
echo "Total file yang DITEMUKAN di laptop: " . count($foundLocations) . " dari " . count($missingFiles) . "\n";
echo "========================================\n";

// Kelompokkan berdasarkan folder tempat file ditemukan
$folderGroups = [];
foreach ($foundLocations as $fn => $path) {
    $dir = dirname($path);
    if (!isset($folderGroups[$dir])) {
        $folderGroups[$dir] = 0;
    }
    $folderGroups[$dir]++;
}

echo "Lokasi folder penyimpanan file-file tersebut di laptop:\n";
foreach ($folderGroups as $dir => $cnt) {
    echo " - $dir : $cnt file\n";
}