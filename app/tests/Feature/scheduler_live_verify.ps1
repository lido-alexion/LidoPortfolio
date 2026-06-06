$ErrorActionPreference = "Stop"
$base = "http://127.0.0.1:8001/api"
$appRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path

function Get-ScheduleLine {
    Push-Location $appRoot
    try {
        $out = php artisan schedule:list 2>&1 | Out-String
        $line = ($out -split "`n" | Where-Object { $_ -match "portfolio:daily-sync" } | Select-Object -First 1)
        if (-not $line) { throw "Could not find portfolio:daily-sync in schedule:list output.`n$out" }
        return $line.Trim()
    } finally {
        Pop-Location
    }
}

$email = "sched" + [guid]::NewGuid().ToString("N").Substring(0, 8) + "@example.com"
$password = "SchedPass@123"
$headers = @{ Accept = "application/json" }
$registerBody = @{
    name = "Sched User"
    email = $email
    password = $password
    password_confirmation = $password
} | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "$base/auth/register" -Headers $headers -ContentType "application/json" -Body $registerBody | Out-Null
$loginBody = @{ email = $email; password = $password } | ConvertTo-Json
$token = (Invoke-RestMethod -Method Post -Uri "$base/auth/login" -Headers $headers -ContentType "application/json" -Body $loginBody).token
$auth = @{ Authorization = "Bearer $token"; Accept = "application/json" }

$beforeLine = Get-ScheduleLine
$beforeSettings = Invoke-RestMethod -Method Get -Uri "$base/settings" -Headers $auth

$newCronTime = "09:45"
$newTimezone = "America/New_York"
$updateBody = @{
    cron_time = $newCronTime
    cron_timezone = $newTimezone
} | ConvertTo-Json

$updated = Invoke-RestMethod -Method Put -Uri "$base/settings" -Headers $auth -ContentType "application/json" -Body $updateBody
$afterLine = Get-ScheduleLine

$restoreBody = @{
    cron_time = $beforeSettings.data.cron_time
    cron_timezone = $beforeSettings.data.cron_timezone
} | ConvertTo-Json
Invoke-RestMethod -Method Put -Uri "$base/settings" -Headers $auth -ContentType "application/json" -Body $restoreBody | Out-Null
$restoredLine = Get-ScheduleLine

if ($updated.data.cron_time -ne $newCronTime) {
    throw "API did not persist cron_time. Expected $newCronTime got $($updated.data.cron_time)"
}
if ($updated.data.cron_timezone -ne $newTimezone) {
    throw "API did not persist cron_timezone. Expected $newTimezone got $($updated.data.cron_timezone)"
}
if ($beforeLine -eq $afterLine) {
    throw "schedule:list did not change after updating cron settings.`nBefore: $beforeLine`nAfter:  $afterLine"
}
if ($restoredLine -ne $beforeLine) {
    throw "schedule:list did not restore to original after revert.`nOriginal: $beforeLine`nRestored: $restoredLine"
}

Write-Output "SCHEDULER LIVE VERIFY PASS"
Write-Output "User: $email"
Write-Output "Before settings: cron_time=$($beforeSettings.data.cron_time) cron_timezone=$($beforeSettings.data.cron_timezone)"
Write-Output "Before schedule: $beforeLine"
Write-Output "Updated via API: cron_time=$newCronTime cron_timezone=$newTimezone"
Write-Output "After schedule:  $afterLine"
Write-Output "Restored schedule: $restoredLine"
