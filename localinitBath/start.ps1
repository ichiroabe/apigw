$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new()

$root = (Resolve-Path "$PSScriptRoot\..").Path
$php  = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'

if (-not (Test-Path $php)) {
    Write-Host "PHP not found: $php"
    Read-Host 'Press Enter'
    exit 1
}

$conns = Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue
if ($conns) {
    Write-Host "[Warn] Port 8080 in use (PID=$($conns.OwningProcess -join ','))"
    $ans = Read-Host 'Continue anyway? [Y/N]'
    if ($ans -ne 'Y' -and $ans -ne 'y') { exit 0 }
}

Set-Location $root
Write-Host '================================================'
Write-Host '  apiphp PHP built-in server'
Write-Host '================================================'
Write-Host '  URL : http://localhost:8080/'
Write-Host '  Stop: Ctrl+C'
Write-Host ''
& $php -S 0.0.0.0:8080 router.php