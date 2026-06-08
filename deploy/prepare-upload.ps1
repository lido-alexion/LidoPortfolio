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

Copy-Tree (Join-Path $app 'app') (Join-Path $staging 'lidoportfolio/app')
Copy-Tree (Join-Path $app 'routes') (Join-Path $staging 'lidoportfolio/routes')
Copy-Tree (Join-Path $app 'database/migrations') (Join-Path $staging 'lidoportfolio/database/migrations')
Copy-Tree (Join-Path $app 'public/build') (Join-Path $staging 'lidoportfolio/public/build')
Copy-Tree (Join-Path $app 'public/build') (Join-Path $staging 'portfolio/build')

Copy-Item (Join-Path $PSScriptRoot 'cpanel-migrate.php') (Join-Path $staging 'portfolio/cpanel-migrate.php') -Force

Write-Host ''
Write-Host "Staging ready: $staging"
Write-Host 'Upload:'
Write-Host '  staging/lidoportfolio/*  ->  public_html/lidoportfolio/  (merge)'
Write-Host '  staging/portfolio/build  ->  public_html/portfolio/build/'
Write-Host '  staging/portfolio/cpanel-migrate.php  ->  public_html/portfolio/'
Write-Host 'Then visit cpanel-migrate.php?token=... and delete the script.'
