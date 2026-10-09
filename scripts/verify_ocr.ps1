$ErrorActionPreference = 'Stop'

Write-Host "=== OCR Tool Verification ===" -ForegroundColor Cyan

$checks = @(
    @{ Name = "tesseract"; Args = "--version"; Env = "AGENDA_OCR_TESSERACT"; Local = "C:\xampp\htdocs\agenda-php\tools\ocr\tesseract\tesseract.exe" },
    @{ Name = "pdftotext"; Args = "-v"; Env = "AGENDA_OCR_PDFTOTEXT"; Local = "C:\xampp\htdocs\agenda-php\tools\ocr\poppler\pdftotext.exe" },
    @{ Name = "pdftoppm"; Args = "-v"; Env = "AGENDA_OCR_PDFTOPPM"; Local = "C:\xampp\htdocs\agenda-php\tools\ocr\poppler\pdftoppm.exe" },
    @{ Name = "magick"; Args = "-version"; Env = "AGENDA_OCR_MAGICK"; Local = "C:\xampp\htdocs\agenda-php\tools\ocr\imagemagick\magick.exe" }
)

foreach ($c in $checks) {
    $name = $c.Name
    $args = $c.Args
    $bin = $null

    $envPath = [Environment]::GetEnvironmentVariable($c.Env, "Process")
    if ([string]::IsNullOrWhiteSpace($envPath)) {
        $envPath = [Environment]::GetEnvironmentVariable($c.Env, "User")
    }
    if ([string]::IsNullOrWhiteSpace($envPath)) {
        $envPath = [Environment]::GetEnvironmentVariable($c.Env, "Machine")
    }
    if (-not [string]::IsNullOrWhiteSpace($envPath) -and (Test-Path $envPath)) {
        $bin = $envPath
    }

    if (-not $bin -and (Test-Path $c.Local)) {
        $bin = $c.Local
    }

    if (-not $bin) {
        $cmd = Get-Command $name -ErrorAction SilentlyContinue
        if ($cmd) {
            $bin = $cmd.Source
        }
    }

    if (-not $bin) {
        Write-Host ("MISS : {0}" -f $name) -ForegroundColor Red
        continue
    }

    Write-Host ("OK   : {0} -> {1}" -f $name, $bin) -ForegroundColor Green
    try {
        & $bin $args.Split(" ")
    } catch {
        Write-Host ("WARN : gagal eksekusi {0} {1}" -f $name, $args) -ForegroundColor Yellow
    }
    Write-Host ""
}
