# Prepare a cPanel upload folder for this release (run from repo root).
# Output: deploy/staging/ — upload contents per deploy/DEPLOY.md

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$app = Join-Path $root 'app'
$staging = Join-Path $PSScriptRoot 'staging'

if (Test-Path $staging) {
    Remove-Item $staging -Recurse -Force
}

New-Item -ItemType Directory -Path $staging | Out-Null

Write-Host 'Building production frontend (VITE_APP_BASE=/portfolio/build/)...'
Push-Location $app
$env:VITE_APP_BASE = '/portfolio/build/'
npm run build | Out-Host
if (Test-Path 'public/hot') {
    Remove-Item 'public/hot' -Force
}
Pop-Location

function Copy-Tree($src, $dest) {
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
    Copy-Item -Path (Join-Path $src '*') -Destination $dest -Recurse -Force
}

# Laravel config shipped with the app (excludes dev-only DBConfig templates).
function Copy-SafeConfigFiles($srcConfig, $destConfig) {
    $exclude = @('DBConfig.php', 'DBConfig.php.template')
    New-Item -ItemType Directory -Path $destConfig -Force | Out-Null
    Get-ChildItem -Path $srcConfig -File | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination (Join-Path $destConfig $_.Name) -Force
    }
}

Copy-Tree (Join-Path $app 'app') (Join-Path $staging 'lidoportfolio/app')
Copy-Tree (Join-Path $app 'routes') (Join-Path $staging 'lidoportfolio/routes')
Copy-Tree (Join-Path $app 'resources/views') (Join-Path $staging 'lidoportfolio/resources/views')
Copy-Tree (Join-Path $app 'database/migrations') (Join-Path $staging 'lidoportfolio/database/migrations')
Copy-SafeConfigFiles (Join-Path $app 'config') (Join-Path $staging 'lidoportfolio/config')
New-Item -ItemType Directory -Path (Join-Path $staging 'lidoportfolio/bootstrap') -Force | Out-Null
Copy-Item (Join-Path $app 'bootstrap/app.php') (Join-Path $staging 'lidoportfolio/bootstrap/app.php') -Force
Copy-Item (Join-Path $app 'composer.json') (Join-Path $staging 'lidoportfolio/composer.json') -Force
Copy-Tree (Join-Path $app 'public/build') (Join-Path $staging 'lidoportfolio/public/build')
Copy-Tree (Join-Path $app 'public/build') (Join-Path $staging 'portfolio/build')

Copy-Item (Join-Path $app 'public/favicon.ico') (Join-Path $staging 'portfolio/favicon.ico') -Force
Copy-Item (Join-Path $PSScriptRoot 'cpanel-migrate.php') (Join-Path $staging 'portfolio/cpanel-migrate.php') -Force
Copy-Item (Join-Path $PSScriptRoot 'cpanel-backfill-sell-realizations.php') (Join-Path $staging 'portfolio/cpanel-backfill-sell-realizations.php') -Force
Copy-Item (Join-Path $PSScriptRoot 'cpanel-mobile-debug.php') (Join-Path $staging 'portfolio/cpanel-mobile-debug.php') -Force
Copy-Item (Join-Path $PSScriptRoot 'cpanel-ping.php') (Join-Path $staging 'portfolio/cpanel-ping.php') -Force
Copy-Item (Join-Path $PSScriptRoot 'cpanel-api-probe.php') (Join-Path $staging 'portfolio/cpanel-api-probe.php') -Force
Copy-Item (Join-Path $PSScriptRoot 'portfolio-OK.txt') (Join-Path $staging 'portfolio/portfolio-OK.txt') -Force
Copy-Item (Join-Path $PSScriptRoot 'portfolio-mobile-debug.html') (Join-Path $staging 'portfolio/mobile-debug.html') -Force
Copy-Item (Join-Path $PSScriptRoot 'public_html-portfolio-.htaccess') (Join-Path $staging 'portfolio/.htaccess') -Force

Write-Host ''
Write-Host "Staging ready: $staging"
Write-Host 'Upload:'
Write-Host '  staging/lidoportfolio/*  ->  public_html/lidoportfolio/  (merge — includes config/, app/, routes, migrations, views, bootstrap, composer.json, public/build/)'
Write-Host '  staging/portfolio/build  ->  public_html/portfolio/build/'
Write-Host '  staging/portfolio/favicon.ico  ->  public_html/portfolio/favicon.ico'
Write-Host '  staging/portfolio/mobile-debug.html  ->  public_html/portfolio/'
Write-Host '  staging/portfolio/cpanel-mobile-debug.php  ->  public_html/portfolio/'
Write-Host '  staging/portfolio/cpanel-migrate.php  ->  public_html/portfolio/'
Write-Host '  staging/portfolio/.htaccess  ->  public_html/portfolio/.htaccess  (REQUIRED — includes index.php rule)'
Write-Host 'Config: staging includes app/config/*.php except DBConfig.php (dev template). Run cpanel-config-cache.php after upload if config changed.'
Write-Host 'Key mobile fix files: resources/views/app.blade.php + both build/ folders'
Write-Host 'Then visit cpanel-migrate.php?token=... and delete the script.'
