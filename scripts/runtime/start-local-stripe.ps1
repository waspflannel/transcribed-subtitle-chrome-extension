[CmdletBinding()]
param(
    [switch]$Restart,
    [switch]$Foreground
)

$ErrorActionPreference = "Stop"
$Backend = Join-Path (Split-Path -Parent $PSScriptRoot) "..\app\backend"
$EnvPath = Join-Path $Backend ".env"
$LogDir = Join-Path $Backend "storage\logs\local-runtime"
$PidPath = Join-Path $LogDir "stripe-listener.pid"
$LogPath = Join-Path $LogDir "stripe-listener.log"
$ErrorPath = Join-Path $LogDir "stripe-listener.err.log"

if (-not (Test-Path -LiteralPath $EnvPath)) { return }
$settings = @{}
foreach ($line in [IO.File]::ReadAllLines($EnvPath)) {
    if ($line -match '^([A-Z_]+)=(.*)$') {
        $settings[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'")
    }
}
if (-not $settings.STRIPE_SECRET) { return }
if ($settings.APP_ENV -ne "local" -or $settings.STRIPE_SECRET -notmatch '^(sk|rk)_test_') {
    throw "Local Stripe forwarding requires APP_ENV=local and a test API key."
}
$appUrl = [Uri]$settings.APP_URL
if (-not $appUrl.IsLoopback -or $appUrl.Scheme -notin @("http", "https")) {
    throw "Local Stripe forwarding requires a loopback APP_URL."
}
$stripe = (Get-Command stripe -ErrorAction Stop).Source

if ($Foreground) {
    # The CLI reads the key from its environment, never from process arguments.
    $env:STRIPE_API_KEY = $settings.STRIPE_SECRET
    $secretOutput = & $stripe listen --print-secret --skip-update --color off 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0 -or $secretOutput -notmatch 'whsec_[a-zA-Z0-9]+') {
        throw "Could not retrieve the sandbox webhook signing secret."
    }
    $secretLine = "STRIPE_WEBHOOK_SECRET=$($Matches[0])"
    $envText = [IO.File]::ReadAllText($EnvPath)
    if ($envText -match '(?m)^STRIPE_WEBHOOK_SECRET=.*$') {
        $envText = [regex]::Replace($envText, '(?m)^STRIPE_WEBHOOK_SECRET=.*$', $secretLine)
    } else {
        $envText += "`n$secretLine`n"
    }
    [IO.File]::WriteAllText($EnvPath, $envText, [Text.UTF8Encoding]::new($false))
    Push-Location $Backend
    try {
        & php artisan config:clear --no-ansi | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Could not clear backend configuration cache." }
        $events = "checkout.session.completed,customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,invoice.payment_failed"
        $forwardTo = $appUrl.AbsoluteUri.TrimEnd('/') + "/stripe/webhook"
        & $stripe listen --events $events --forward-to $forwardTo --skip-update --color off 2>&1 |
            ForEach-Object { $_.ToString() -replace '(?:[sr]k|pk)_(?:test|live)_[a-zA-Z0-9]+|whsec_[a-zA-Z0-9]+', '[redacted]' }
        if ($LASTEXITCODE -ne 0) { throw "Stripe webhook listener stopped with an error." }
    } finally {
        Remove-Item Env:STRIPE_API_KEY -ErrorAction SilentlyContinue
        Pop-Location
    }
    return
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
if (Test-Path -LiteralPath $PidPath) {
    $listenerId = [int]([IO.File]::ReadAllText($PidPath).Trim())
    $existing = Get-CimInstance Win32_Process -Filter "ProcessId = $listenerId" -ErrorAction SilentlyContinue
    if ($existing -and $existing.CommandLine.Contains($PSCommandPath) -and $existing.CommandLine.Contains("-Foreground")) {
        if (-not $Restart) {
            Write-Host "Local Stripe listener is already running (PID $listenerId). Use -Restart after rotating keys."
            return
        }
        Get-CimInstance Win32_Process -Filter "ParentProcessId = $listenerId" |
            Where-Object { $_.Name -eq "stripe.exe" } |
            ForEach-Object { Stop-Process -Id $_.ProcessId -ErrorAction SilentlyContinue }
        Stop-Process -Id $listenerId -ErrorAction SilentlyContinue
    }
}
$shellPath = (Get-Process -Id $PID).Path
$listener = Start-Process -FilePath $shellPath -ArgumentList @("-NoProfile", "-File", "`"$PSCommandPath`"", "-Foreground") -WindowStyle Hidden -RedirectStandardOutput $LogPath -RedirectStandardError $ErrorPath -PassThru
[IO.File]::WriteAllText($PidPath, [string]$listener.Id)
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    Start-Sleep -Milliseconds 500
    if ($listener.HasExited) { throw "Stripe listener exited. See $ErrorPath" }
    if ((Test-Path -LiteralPath $LogPath) -and ((Get-Content -LiteralPath $LogPath -Raw -ErrorAction SilentlyContinue) -match 'Ready!')) {
        Write-Host "Local Stripe webhooks are forwarding to $($appUrl.AbsoluteUri.TrimEnd('/'))/stripe/webhook (PID $($listener.Id))."
        return
    }
}
throw "Stripe listener did not become ready. See $LogPath"
