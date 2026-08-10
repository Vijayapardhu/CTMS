# CTMS — start the demonstration environment.
#
#   .\demo.ps1              start everything, rebuilding the demo world
#   .\demo.ps1 -Keep        start everything, keeping yesterday's data
#   .\demo.ps1 -SeedOnly    rebuild the data and stop
#
# Everything runs from this one script so that nothing depends on somebody
# remembering the right order in a meeting room.

[CmdletBinding()]
param(
    [switch]$Keep,
    [switch]$SeedOnly
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$backend = Join-Path $root 'backend'
$panel = Join-Path $root 'admin_panel'

function Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }
function Note($text) { Write-Host "   $text" -ForegroundColor DarkGray }
function Warn($text) { Write-Host "   $text" -ForegroundColor Yellow }

Step 'Checking what is installed'

foreach ($tool in @('php', 'node', 'npm')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "$tool is not on PATH. Install it before running the demonstration."
    }
    Note "$tool $((& $tool --version 2>&1 | Select-Object -First 1))"
}

# The demonstration deliberately runs on SQLite with a synchronous queue: no
# PostgreSQL, no Redis, no worker. Production is the other shape — see
# backend/.env.example — and this script does not pretend otherwise.
Note 'Database: SQLite · Queue: synchronous · Cache: file (see backend/.env.demo)'

if (-not (Test-Path (Join-Path $backend 'vendor'))) {
    Step 'Installing backend dependencies'
    Push-Location $backend
    composer install --no-interaction
    Pop-Location
}

if (-not (Test-Path (Join-Path $panel 'node_modules'))) {
    Step 'Installing panel dependencies'
    Push-Location $panel
    npm ci
    Pop-Location
}

Push-Location $backend

# The template is committed; the working copy is not, because it carries an
# application key and that is a secret even in a demonstration.
if (-not (Test-Path '.env.demo')) {
    Step 'Creating backend/.env.demo from the template'
    Copy-Item '.env.demo.example' '.env.demo'
}

if (-not (Select-String -Path '.env.demo' -Pattern '^APP_KEY=base64:' -Quiet)) {
    Step 'Generating an application key'
    php artisan --env=demo key:generate --force | Out-Null
}

if (-not $Keep) {
    Step 'Building the demonstration world'
    php artisan --env=demo ctms:demo --fresh
} else {
    Note 'Keeping the existing demonstration data (-Keep).'
}

Pop-Location

if ($SeedOnly) {
    Step 'Done'
    Note 'Data rebuilt. Start the servers yourself, or run without -SeedOnly.'
    exit 0
}

Step 'Checking the map credential'

$envLocal = Join-Path $panel '.env.local'
if ((Test-Path $envLocal) -and (Select-String -Path $envLocal -Pattern '^VITE_GOOGLE_MAPS_API_KEY=.+' -Quiet)) {
    Note 'Browser key found. Live Operations will render a map.'
} else {
    Warn 'No VITE_GOOGLE_MAPS_API_KEY in admin_panel/.env.local.'
    Warn 'Live Operations will show its map-unavailable state; everything else works.'
    Warn 'See docs/admin-panel/google-maps-setup.md.'
}

Step 'Starting the backend on http://127.0.0.1:8000'
$api = Start-Process -PassThru -WorkingDirectory $backend -FilePath 'php' `
    -ArgumentList '-d', 'variables_order=EGPCS', 'artisan', 'serve', '--env=demo', '--host=127.0.0.1', '--port=8000'
Note "pid $($api.Id)"

Step 'Starting the admin panel on http://127.0.0.1:5173'
$web = Start-Process -PassThru -WorkingDirectory $panel -FilePath 'npm' -ArgumentList 'run', 'dev'
Note "pid $($web.Id)"

Write-Host @"

  CTMS is running.

    Admin panel   http://127.0.0.1:5173
    API           http://127.0.0.1:8000/api/v1

    viewer@ctms.edu       Transport Assistant     (VIEWER)
    supervisor@ctms.edu   Transport Supervisor    (SUPPORT)
    head@ctms.edu         Transport Head          (OPERATIONS)
    admin@ctms.edu        System Administrator    (SUPER_ADMIN)
    driver1@ctms.edu      Ravi Kumar              (DRIVER)

    password              Ctms@2026

  The walkthrough is docs/admin-panel/demo-walkthrough.md.
  Press Ctrl+C to stop both.

"@ -ForegroundColor Green

try {
    Wait-Process -Id $api.Id, $web.Id
} finally {
    Step 'Stopping'
    foreach ($p in @($api, $web)) {
        if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
    }
}
