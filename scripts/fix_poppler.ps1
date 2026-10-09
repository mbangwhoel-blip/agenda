$ErrorActionPreference = 'Stop'

$targetDir = "C:\xampp\htdocs\agenda-php\tools\ocr\poppler"
$zipPath = "$targetDir\poppler.zip"
$extractPath = "$targetDir\temp_extract"
$url = "https://github.com/oschwartz10612/poppler-windows/releases/download/v24.02.0-0/Release-24.02.0-0.zip"

Write-Host "=== Fixing Poppler (pdftotext & pdftoppm) ===" -ForegroundColor Cyan

if (-not (Test-Path $targetDir)) {
    New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
}

Write-Host "1) Downloading Poppler binaries..." -ForegroundColor Yellow
Invoke-WebRequest -Uri $url -OutFile $zipPath

Write-Host "2) Extracting zip..." -ForegroundColor Yellow
Expand-Archive -Path $zipPath -DestinationPath $extractPath -Force

Write-Host "3) Copying binaries to $targetDir..." -ForegroundColor Yellow
# The zip usually contains a 'Library' or 'bin' folder depending on version. 
# For oschwartz10612, it's inside 'Library/bin' or just 'bin'.
$binFolder = Get-ChildItem -Path $extractPath -Recurse -Directory -Filter "bin" | Select-Object -First 1

if ($binFolder) {
    Copy-Item -Path "$($binFolder.FullName)\*" -Destination $targetDir -Force
} else {
    # Fallback: try to find files directly
    Get-ChildItem -Path $extractPath -Recurse -File -Include "*.exe", "*.dll" | Copy-Item -Destination $targetDir -Force
}

Write-Host "4) Cleaning up..." -ForegroundColor Yellow
Remove-Item -Path $zipPath -Force
Remove-Item -Path $extractPath -Recurse -Force

Write-Host "Checking result..." -ForegroundColor Yellow
if (Test-Path "$targetDir\pdftotext.exe") {
    Write-Host "SUCCESS: pdftotext.exe is now ready." -ForegroundColor Green
} else {
    Write-Host "FAILED: pdftotext.exe not found after extraction." -ForegroundColor Red
}

if (Test-Path "$targetDir\pdftoppm.exe") {
    Write-Host "SUCCESS: pdftoppm.exe is now ready." -ForegroundColor Green
} else {
    Write-Host "FAILED: pdftoppm.exe not found after extraction." -ForegroundColor Red
}

Write-Host "Selesai! Silakan jalankan ulang scripts\verify_ocr.ps1" -ForegroundColor Cyan
