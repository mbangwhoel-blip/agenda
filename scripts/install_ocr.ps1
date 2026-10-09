param(
    [switch]$SkipChocoCheck
)

$ErrorActionPreference = 'Stop'

Write-Host "=== Install OCR Dependencies (Windows) ===" -ForegroundColor Cyan

function Test-IsAdmin {
    $currentIdentity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($currentIdentity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-IsAdmin)) {
    throw "Script harus dijalankan di PowerShell 'Run as Administrator'."
}

if (-not $SkipChocoCheck) {
    if (-not (Get-Command choco -ErrorAction SilentlyContinue)) {
        throw "Chocolatey tidak ditemukan. Install Chocolatey dulu, lalu jalankan ulang script ini."
    }
}

Write-Host "0) Cleaning stale Chocolatey lock files..." -ForegroundColor Yellow
Get-ChildItem -Path "C:\ProgramData\chocolatey\lib" -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match '^[a-f0-9]{40}$' } |
    Remove-Item -Force -ErrorAction SilentlyContinue

Write-Host "1) Installing tesseract, poppler, imagemagick..." -ForegroundColor Yellow
choco install tesseract poppler imagemagick -y --no-progress

Write-Host "2) Verifying binaries..." -ForegroundColor Yellow
$bins = @("tesseract", "pdftotext", "pdftoppm", "magick")
foreach ($bin in $bins) {
    $cmd = Get-Command $bin -ErrorAction SilentlyContinue
    if ($cmd) {
        Write-Host ("   OK   : {0} -> {1}" -f $bin, $cmd.Source) -ForegroundColor Green
    } else {
        Write-Host ("   MISS : {0}" -f $bin) -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Install selesai. Restart Apache (XAMPP) lalu test upload surat lagi." -ForegroundColor Cyan
