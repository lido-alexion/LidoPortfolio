# Session-based API smoke (Sanctum SPA). Server must be running on $baseHost.
$baseHost = "http://127.0.0.1:8001"
$base = "$baseHost/api"
$email = "smoke" + [guid]::NewGuid().ToString("N").Substring(0, 8) + "@example.com"
$password = "SmokePass@123"

function Get-XsrfHeader {
    param($WebSession)
    $cookie = $WebSession.Cookies.GetCookies($baseHost) | Where-Object { $_.Name -eq "XSRF-TOKEN" } | Select-Object -First 1
    if (-not $cookie) { throw "XSRF-TOKEN cookie missing. Call /sanctum/csrf-cookie first." }
  return @{ "X-XSRF-TOKEN" = [Uri]::UnescapeDataString($cookie.Value) }
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Uri,
        [hashtable]$WebSession,
        [string]$Body = $null
    )
    $headers = @{
        Accept = "application/json"
        Origin = $baseHost
        Referer = "$baseHost/"
    }
    $headers += Get-XsrfHeader -WebSession $WebSession

    $params = @{
        Method = $Method
        Uri = $Uri
        Headers = $headers
        WebSession = $WebSession
        ContentType = "application/json"
    }
    if ($Body) { $params.Body = $Body }
    return Invoke-RestMethod @params
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Invoke-WebRequest -Uri "$baseHost/sanctum/csrf-cookie" -WebSession $session | Out-Null

$registerBody = (@{
    name = "Smoke User"
    email = $email
    password = $password
    password_confirmation = $password
} | ConvertTo-Json)

Invoke-Api -Method Post -Uri "$base/auth/register" -WebSession $session -Body $registerBody | Out-Null

$loginBody = (@{ email = $email; password = $password } | ConvertTo-Json)
$login = Invoke-Api -Method Post -Uri "$base/auth/login" -WebSession $session -Body $loginBody

if (-not $login.user) { throw "Login response missing user object (session auth expected, not Bearer token)." }

$stockBody = (@{
    symbol = "SMK" + [guid]::NewGuid().ToString("N").Substring(0, 4)
    exchange = "NSE"
    name = "Smoke Stock"
    sector = "Testing"
} | ConvertTo-Json)

$stock = Invoke-Api -Method Post -Uri "$base/stocks" -WebSession $session -Body $stockBody
$stockId = $stock.data.id

$txBody = (@{
    stock_id = $stockId
    type = "buy"
    quantity = 5
    price = 100
    brokerage = 1
    transaction_date = (Get-Date -Format "yyyy-MM-dd")
    notes = "smoke"
} | ConvertTo-Json)

Invoke-Api -Method Post -Uri "$base/transactions" -WebSession $session -Body $txBody | Out-Null
$holdings = Invoke-Api -Method Get -Uri "$base/holdings" -WebSession $session
$dashboard = Invoke-Api -Method Get -Uri "$base/dashboard" -WebSession $session
$portfolio = Invoke-Api -Method Get -Uri "$base/analytics/portfolio" -WebSession $session
$stockAnalytics = Invoke-Api -Method Get -Uri "$base/analytics/stocks/$stockId" -WebSession $session
$alerts = Invoke-Api -Method Get -Uri "$base/alerts" -WebSession $session

if (-not ($dashboard.PSObject.Properties.Name -contains "portfolio_value")) { throw "Dashboard missing portfolio_value." }
if (-not ($portfolio.PSObject.Properties.Name -contains "holdings")) { throw "Portfolio analytics missing holdings." }
if (-not ($stockAnalytics.PSObject.Properties.Name -contains "xirr")) { throw "Stock analytics missing xirr." }
if (-not ($alerts.PSObject.Properties.Name -contains "data")) { throw "Alerts response missing data." }

Write-Output "API smoke PASS for user: $email"
