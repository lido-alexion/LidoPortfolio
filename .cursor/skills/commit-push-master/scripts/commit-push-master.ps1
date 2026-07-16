# Requires: files already staged on master. Commits and pushes to origin/master only.
param(
    [Parameter(Mandatory = $true)]
    [string]$Message
)

$ErrorActionPreference = 'Stop'

function Fail([string]$Text) {
    Write-Error $Text
    exit 1
}

$root = git rev-parse --show-toplevel 2>$null
if (-not $root) {
    Fail 'Not inside a git repository.'
}
Set-Location $root

$branch = (git branch --show-current).Trim()
if ($branch -ne 'master') {
    Write-Host "Checking out master (was on '$branch')..."
    git checkout master
    if ($LASTEXITCODE -ne 0) {
        Fail 'Failed to checkout master.'
    }
}

$staged = git diff --cached --name-only
if (-not $staged) {
    Fail 'Nothing staged. Stage files first, then re-run this script.'
}

# Safety: refuse if commit would include obvious secrets
$blocked = @('.env', 'credentials.json', 'DBConfig.php')
foreach ($path in $staged) {
    $name = Split-Path $path -Leaf
    if ($blocked -contains $name) {
        Fail "Refusing to commit sensitive file: $path"
    }
}

git commit -m $Message
if ($LASTEXITCODE -ne 0) {
    Fail 'git commit failed.'
}

git push origin master
if ($LASTEXITCODE -ne 0) {
    Fail 'git push origin master failed.'
}

Write-Host ''
Write-Host 'OK: committed and pushed to origin/master'
git log -1 --oneline
git branch -vv
git status --short
