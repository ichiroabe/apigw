$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new()

$root    = (Resolve-Path "$PSScriptRoot\..").Path
$dbPath  = Join-Path $root 'data\app.db'
$storage = Join-Path $root 'phpfilefolder\_storage'
$php     = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'

Write-Host '================================================'
Write-Host '  apiphp Local DB initialize (admin only)'
Write-Host '================================================'
Write-Host ''
Write-Host "Project: $root"
Write-Host 'Targets:'
Write-Host "  - DB:      $dbPath"
Write-Host "  - Storage: $storage\*"
Write-Host ''
Write-Host 'After this, only admin/admin remains. Auto re-created on next access.'
Write-Host ''

$ans = Read-Host 'Confirm? [Y/N]'
if ($ans -ne 'Y' -and $ans -ne 'y') {
    Write-Host 'Cancelled.'
    exit 0
}

Write-Host ''
Write-Host '[1/4] Stop running PHP server...'
$conns = Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue
foreach ($c in $conns) {
    $procId = $c.OwningProcess
    Write-Host "  Stopping PID $procId"
    Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
}
Start-Sleep -Milliseconds 500

Write-Host '[2/4] Delete DB file...'
if (Test-Path $dbPath) {
    Remove-Item $dbPath -Force -ErrorAction SilentlyContinue
    if (Test-Path $dbPath) {
        Write-Host "  [FAIL] Could not delete $dbPath"
        Read-Host 'Press Enter'
        exit 1
    }
    Write-Host '  Deleted'
} else {
    Write-Host '  Not found'
}

Write-Host '[3/4] Delete storage files...'
if (Test-Path $storage) {
    $items = Get-ChildItem $storage -File -ErrorAction SilentlyContinue
    foreach ($f in $items) {
        Remove-Item $f.FullName -Force -ErrorAction SilentlyContinue
    }
    Write-Host "  Deleted $($items.Count) files"
} else {
    New-Item -ItemType Directory -Path $storage -Force | Out-Null
    Write-Host '  Created directory'
}

Write-Host '[4/4] Re-create DB...'
if (Test-Path $php) {
    Push-Location $root
    & $php -r "require 'includes/db.php'; db(); echo 'DB created.';"
    Pop-Location
} else {
    Write-Host "  PHP not found: $php"
    Write-Host '  DB will be auto-created on first browser access.'
}

Write-Host ''
Write-Host '================================================'
Write-Host '  Done.'
Write-Host '================================================'
Write-Host ''
Write-Host '  User : admin / admin'
Write-Host '  Start: start.bat'
Write-Host ''
Read-Host 'Press Enter to exit'