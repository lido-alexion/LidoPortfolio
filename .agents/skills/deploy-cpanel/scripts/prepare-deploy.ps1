# Build (optional), stage via prepare-upload.ps1, emit deploy upload table.
# Usage (repo root):
#   powershell -ExecutionPolicy Bypass -File .cursor/skills/deploy-cpanel/scripts/prepare-deploy.ps1
#   powershell -ExecutionPolicy Bypass -File .cursor/skills/deploy-cpanel/scripts/prepare-deploy.ps1 -SinceCommit abc1234
#   powershell -ExecutionPolicy Bypass -File .cursor/skills/deploy-cpanel/scripts/prepare-deploy.ps1 -SkipBuild
#   powershell -ExecutionPolicy Bypass -File .cursor/skills/deploy-cpanel/scripts/prepare-deploy.ps1 -Debug
param(
    [string]$SinceCommit = '',
    [switch]$SkipBuild,
    [switch]$Debug
)

$ErrorActionPreference = 'Stop'

function Fail([string]$Text) {
    Write-Error $Text
    exit 1
}

$root = (git rev-parse --show-toplevel 2>$null).Trim()
if (-not $root) {
    Fail 'Not inside a git repository.'
}
Set-Location $root

$repoRoot = $root -replace '\\', '/'
$deployDir = Join-Path $root 'deploy'
$traceLog = Join-Path $deployDir 'deploy-trace.log'
function Trace([string]$Message) {
    if ($Debug) {
        Add-Content -Path $traceLog -Value $Message
    }
}
if ($Debug) {
    Remove-Item $traceLog -ErrorAction SilentlyContinue
    Trace "root=$root since=$SinceCommit skipBuild=$SkipBuild"
}
$outMd = Join-Path $deployDir 'deploy-table.md'
$outJson = Join-Path $deployDir 'deploy-manifest.json'

# --- changed files since commit ---
if ($SinceCommit -eq '') {
    $upstream = git rev-parse --abbrev-ref '@{u}' 2>$null
    if ($LASTEXITCODE -eq 0 -and $upstream) {
        $mergeBase = git merge-base HEAD $upstream 2>$null
        if ($mergeBase -and (git rev-parse HEAD) -ne (git rev-parse $upstream)) {
            $SinceCommit = $mergeBase
        }
    }
    if ($SinceCommit -eq '') {
        $SinceCommit = 'HEAD~1'
    }
}

# Include committed range + working tree (tracked mods and untracked files).
$changedList = [System.Collections.Generic.List[string]]::new()
$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$diffLines = @(git -c core.quotepath=false diff --name-only "$SinceCommit" 2>$null)
$untrackedLines = @(git -c core.quotepath=false ls-files --others --exclude-standard 2>$null)
$ErrorActionPreference = $prevEap
foreach ($line in $diffLines) {
    if ($line) { $changedList.Add(([string]$line).Trim()) }
}
foreach ($line in $untrackedLines) {
    if ($line) { $changedList.Add(([string]$line).Trim()) }
}
$changed = @($changedList | Where-Object { $_ } | Sort-Object -Unique)
Trace "changed=$($changed.Count)"

# --- build + stage ---
if (-not $SkipBuild) {
    Write-Host 'Running prepare-upload.ps1 (npm build + staging)...'
    & (Join-Path $deployDir 'prepare-upload.ps1')
    if ($LASTEXITCODE -ne 0) {
        Fail 'prepare-upload.ps1 failed.'
    }
}

# --- read bundle names from manifest ---
$manifestPath = Join-Path $root 'app/public/build/manifest.json'
if (-not (Test-Path $manifestPath)) {
    Fail "Missing $manifestPath - run without -SkipBuild first."
}
Trace "manifestPath=$manifestPath"
$manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
Trace "manifestLoaded=true"
$mainJs = $null
$mainCss = @()
$appProp = $manifest.PSObject.Properties | Where-Object { $_.Name -eq 'resources/js/app.jsx' } | Select-Object -First 1
if ($appProp -and $appProp.Value.file) {
    $mainJs = [string]$appProp.Value.file
    if ($appProp.Value.css) {
        $mainCss += @($appProp.Value.css | ForEach-Object { [string]$_ })
    }
}
$lidoProp = $manifest.PSObject.Properties | Where-Object { $_.Name -eq 'resources/js/src/styles/lido-app.css' } | Select-Object -First 1
if ($lidoProp -and $lidoProp.Value.file) {
    $mainCss += @([string]$lidoProp.Value.file)
}
$mainCss = @($mainCss | Where-Object { $_ } | Select-Object -Unique)
Trace "mainJs=$mainJs mainCssCount=$($mainCss.Count)"

# --- classify paths ---
$skipPattern = '(^app/tests/|^app/node_modules/|^deploy/staging/|\.phpunit|public/hot$|(^|/)implementation\.md$|(^|/)\.env$|DBConfig\.php$)'
$frontendPattern = '(^app/resources/js/|^app/resources/css/|^app/resources/views/|^app/vite\.|^app/public/build/|^app/public/docs/|^app/scripts/generate-static-docs)'

$rows = [System.Collections.Generic.List[object]]::new()
$seenTarget = @{}
$hasFrontend = $false
$hasMigration = $false
$hasConfig = $false
$newCpanelScripts = [System.Collections.Generic.List[string]]::new()

if ($env:DEPLOY_DEBUG -eq '1') {
    Write-Host "DEBUG changed count: $($changed.Count)"
}

function Add-Row([string]$Source, [string]$Target, [string]$Notes) {
    if ($script:seenTarget.ContainsKey($Target)) {
        return
    }
    $script:seenTarget[$Target] = $true
    $script:rows.Add([pscustomobject]@{
            Source = $Source
            Target = $Target
            Notes  = $Notes
        })
}

foreach ($rel in $changed) {
    $rel = ($rel -replace '\\', '/').Trim()
    if ($rel -match $skipPattern) {
        continue
    }
    if ($rel -match $frontendPattern) {
        $hasFrontend = $true
        continue
    }
    if ($rel -match '^deploy/(cpanel-[^/]+\.php)$') {
        $cpanelScript = $Matches[1]
        $newCpanelScripts.Add($cpanelScript) | Out-Null
        Add-Row `
            "$repoRoot/deploy/$cpanelScript" `
            "/home/USER/public_html/portfolio/$cpanelScript" `
            'Upload; delete after one-off use if applicable'
        continue
    }
    if ($rel -match '^app/database/migrations/(.+)$') {
        $hasMigration = $true
        Add-Row `
            "$repoRoot/$rel" `
            "/home/USER/public_html/lidoportfolio/database/migrations/$($Matches[1])" `
            'New migration'
        continue
    }
    if ($rel -match '^app/config/(.+)$' -and $rel -notmatch 'DBConfig\.php') {
        $hasConfig = $true
        Add-Row `
            "$repoRoot/$rel" `
            "/home/USER/public_html/lidoportfolio/config/$($Matches[1])" `
            'Merge'
        continue
    }
    if ($rel -match '^app/routes/(.+)$') {
        Add-Row `
            "$repoRoot/$rel" `
            "/home/USER/public_html/lidoportfolio/routes/$($Matches[1])" `
            'Merge'
        continue
    }
    if ($rel -match '^app/app/(.+)$') {
        Add-Row `
            "$repoRoot/$rel" `
            "/home/USER/public_html/lidoportfolio/app/$($Matches[1])" `
            'Merge'
        continue
    }
    if ($rel -match '^app/resources/views/(.+)$') {
        $hasFrontend = $true
        continue
    }
    Trace "unmatched=$rel"
}

Trace "rows=$($rows.Count) hasFrontend=$hasFrontend"

if ($hasFrontend) {
    Add-Row `
        "$repoRoot/app/public/build/" `
        '/home/USER/public_html/lidoportfolio/public/build/' `
        "Replace entire directory - verify $mainJs"
    Add-Row `
        "$repoRoot/deploy/staging/portfolio/build/" `
        '/home/USER/public_html/portfolio/build/' `
        'Replace entire directory - same bundle as above'
    Add-Row `
        "$repoRoot/app/public/docs/" `
        '/home/USER/public_html/portfolio/docs/' `
        'Replace entire directory - static HTML documentation'
    Add-Row `
        "$repoRoot/app/public/docs/" `
        '/home/USER/public_html/lidoportfolio/public/docs/' `
        'Replace entire directory - static HTML documentation (Laravel public)'
}

# --- markdown output ---
$sinceLabel = $SinceCommit
$bundleLine = if ($mainJs) { $mainJs } else { '(run build first)' }
$cssLine = if ($mainCss.Count -gt 0) { ($mainCss -join ', ') } else { '(run build first)' }

$md = @(
    '# Deploy upload table (generated)',
    '',
    "Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm')",
    "Changed since: ``$sinceLabel``",
    "Main JS bundle: **``$bundleLine``**",
    "Main CSS: **``$cssLine``**",
    '',
    'Replace ``USER`` with your cPanel username (e.g. ``p7xatiz6j0mk``).',
    '',
    '| Source (full path) | Target (full path) | Notes |',
    '|---|---|---|'
)

if ($rows.Count -eq 0) {
    $md += '| *(no product file changes detected)* | | Run with ``-SinceCommit <ref>`` if needed |'
} else {
    foreach ($row in $rows) {
        $md += "| ``$($row.Source)`` | ``$($row.Target)`` | $($row.Notes) |"
    }
}

$md += ''
$md += '## After upload'
$md += ''
if ($hasMigration) {
    $md += '1. **Migrations:** `https://YOUR-DOMAIN/portfolio/cpanel-migrate.php?token=YOUR_TOKEN`'
    $md += '2. Delete `cpanel-migrate.php` from server after success (if you uploaded it for this release only).'
} else {
    $md += '1. No migration detected in this diff - skip `cpanel-migrate.php` unless you know schema changed.'
}
if ($hasConfig) {
    $md += '2. **Config cache:** `https://YOUR-DOMAIN/portfolio/cpanel-config-cache.php?token=YOUR_TOKEN`'
}
$md += ''
$md += '## Smoke test'
$md += ''
$md += "- Hard-refresh (Ctrl+F5); Network should load **``$bundleLine``**"
$md += '- Confirm both ``lidoportfolio/public/build/`` and ``portfolio/build/`` were updated together (if frontend changed).'
$md += ''
$md += '_Regenerate: ``powershell -ExecutionPolicy Bypass -File .cursor/skills/deploy-cpanel/scripts/prepare-deploy.ps1``_'

$mdText = ($md -join "`n")
Set-Content -Path $outMd -Value $mdText -Encoding UTF8

$manifestOut = [ordered]@{
    generated_at  = (Get-Date).ToString('o')
    since_commit    = $sinceLabel
    main_js         = $mainJs
    main_css        = @($mainCss)
    has_frontend    = [bool]$hasFrontend
    has_migration   = [bool]$hasMigration
    has_config      = [bool]$hasConfig
    changed_files   = @($changed)
    upload_rows     = if ($rows.Count -gt 0) {
        @($rows | ForEach-Object { @{ Source = $_.Source; Target = $_.Target; Notes = $_.Notes } })
    } else {
        @()
    }
    markdown_path   = ($outMd -replace '\\', '/')
}
$manifestOut | ConvertTo-Json -Depth 6 | Set-Content -Path $outJson -Encoding UTF8

Write-Host ''
Write-Host 'Deploy table written:'
Write-Host "  $outMd"
Write-Host "  $outJson"
Write-Host "Main bundle: $bundleLine"
if ($mainCss.Count -gt 0) {
    Write-Host "CSS: $cssLine"
}
Write-Host ''
Write-Host $mdText
