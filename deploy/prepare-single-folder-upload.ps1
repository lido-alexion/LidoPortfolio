# FEAT-031 — prepare the hardened single-folder cPanel release.
# Output maps to two destinations:
#   staging-single-folder/portfolio/* -> /home/USER/public_html/portfolio/
#   staging-single-folder/config/*    -> /home/USER/config/ (examples only)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$appRoot = Join-Path $repoRoot 'app'
$stagingRoot = Join-Path $PSScriptRoot 'staging-single-folder'
$webRoot = Join-Path $stagingRoot 'portfolio'
$laravelRoot = Join-Path $webRoot 'laravel'

if (Test-Path $stagingRoot) {
    Remove-Item $stagingRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $laravelRoot -Force | Out-Null

Write-Host 'Building the production frontend (VITE_APP_BASE=/portfolio/build/)...'
Push-Location $appRoot
$previousViteBase = $env:VITE_APP_BASE
try {
    $env:VITE_APP_BASE = '/portfolio/build/'
    npm run build | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "Frontend build failed with exit code $LASTEXITCODE."
    }
} finally {
    $env:VITE_APP_BASE = $previousViteBase
    Pop-Location
}

if (Test-Path (Join-Path $appRoot 'public/hot')) {
    throw 'Refusing to package while app/public/hot exists.'
}

function Copy-Tree([string] $Source, [string] $Destination) {
    if (-not (Test-Path $Source)) {
        throw "Required source path is missing: $Source"
    }
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Copy-Item -Path (Join-Path $Source '*') -Destination $Destination -Recurse -Force
}

function Copy-File([string] $Source, [string] $Destination) {
    if (-not (Test-Path $Source)) {
        throw "Required source file is missing: $Source"
    }
    New-Item -ItemType Directory -Path (Split-Path -Parent $Destination) -Force | Out-Null
    Copy-Item -Path $Source -Destination $Destination -Force
}

foreach ($directory in @('app', 'bootstrap', 'config', 'database', 'resources', 'routes')) {
    Copy-Tree (Join-Path $appRoot $directory) (Join-Path $laravelRoot $directory)
}

foreach ($file in @('artisan', 'composer.json', 'composer.lock')) {
    Copy-File (Join-Path $appRoot $file) (Join-Path $laravelRoot $file)
}

# Never ship local credentials, dev database templates, caches, logs, or a
# second public build. Writable runtime folders are created empty below.
Remove-Item (Join-Path $laravelRoot 'config/DBConfig.php') -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $laravelRoot 'config/DBConfig.php.template') -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $laravelRoot 'bootstrap/cache/*') -Force -ErrorAction SilentlyContinue

foreach ($directory in @(
    'bootstrap/cache',
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/testing',
    'storage/framework/views',
    'storage/logs'
)) {
    New-Item -ItemType Directory -Path (Join-Path $laravelRoot $directory) -Force | Out-Null
}

Copy-File (Join-Path $PSScriptRoot 'public_html-lidoportfolio-.htaccess') (Join-Path $laravelRoot '.htaccess')
Copy-Tree (Join-Path $appRoot 'public/build') (Join-Path $webRoot 'build')
Copy-Tree (Join-Path $appRoot 'public/docs') (Join-Path $webRoot 'docs')
Copy-File (Join-Path $appRoot 'public/favicon.ico') (Join-Path $webRoot 'favicon.ico')
Copy-File (Join-Path $PSScriptRoot 'portfolio-single-folder-index.php') (Join-Path $webRoot 'index.php')
Copy-File (Join-Path $PSScriptRoot 'portfolio-single-folder.htaccess') (Join-Path $webRoot '.htaccess')

# Helpers remain temporary and token-gated. They are included for migration
# and must be deleted from production after use.
Get-ChildItem -Path $PSScriptRoot -Filter 'cpanel-*.php' -File | ForEach-Object {
    Copy-File $_.FullName (Join-Path $webRoot $_.Name)
}

Copy-File (Join-Path $appRoot '.env.production.example') (Join-Path $stagingRoot 'config/LidoPortfolio.env.example')

$forbidden = @(
    (Join-Path $laravelRoot '.env'),
    (Join-Path $laravelRoot 'public/hot'),
    (Join-Path $laravelRoot 'public/build'),
    (Join-Path $laravelRoot 'config/DBConfig.php')
)
foreach ($path in $forbidden) {
    if (Test-Path $path) {
        throw "Unsafe release artifact was packaged: $path"
    }
}

Write-Host ''
Write-Host "Single-folder staging ready: $stagingRoot"
Write-Host 'Upload staging-single-folder/portfolio/* to public_html/portfolio/.'
Write-Host 'Create /home/USER/config/LidoPortfolio.env from the staged example; do not upload it under public_html.'
Write-Host 'Run Composer in public_html/portfolio/laravel, update cron to that artisan path, verify, then remove temporary cpanel-*.php helpers.'
