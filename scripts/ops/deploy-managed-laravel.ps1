[CmdletBinding()]
param(
    [ValidateSet("staging", "production")]
    [string]$Target = "production",
    [string]$BackendPath,
    [string]$HealthUrl,
    [switch]$SkipRepositoryChecks,
    [switch]$SkipComposerInstall,
    [switch]$SkipMigrations,
    [switch]$SkipHealthCheck
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Backend = if ($BackendPath) { Resolve-Path $BackendPath } else { Resolve-Path (Join-Path $Root "app\backend") }
$Contracts = Resolve-Path (Join-Path $Root "packages\contracts")
$AgentCheck = Join-Path $Root "scripts\agent\check.ps1"

if (-not $SkipHealthCheck) {
    $healthUri = $null
    if (-not [Uri]::TryCreate($HealthUrl, [UriKind]::Absolute, [ref]$healthUri) -or
        $healthUri.Scheme -ne 'https' -or $healthUri.UserInfo -or $healthUri.Query -or $healthUri.Fragment) {
        throw 'Provide an HTTPS HealthUrl for the deployed app, or explicitly use SkipHealthCheck.'
    }
}

function Invoke-Checked {
    param(
        [string]$FilePath,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory
    )

    Push-Location $WorkingDirectory
    try {
        & $FilePath @Arguments

        if ($LASTEXITCODE -ne 0) {
            throw "Command failed in ${WorkingDirectory}: $FilePath $($Arguments -join ' ')"
        }
    } finally {
        Pop-Location
    }
}

if (-not $SkipRepositoryChecks) {
    Invoke-Checked -FilePath $AgentCheck -WorkingDirectory $Root
}

if (-not $SkipComposerInstall) {
    Invoke-Checked -FilePath "composer" -Arguments @("install", "--no-dev", "--prefer-dist", "--optimize-autoloader", "--no-interaction") -WorkingDirectory $Backend
}

Invoke-Checked -FilePath "composer" -Arguments @("audit", "--locked", "--no-dev", "--no-interaction") -WorkingDirectory $Backend
Invoke-Checked -FilePath "npm" -Arguments @("audit", "--audit-level=high") -WorkingDirectory $Contracts

Invoke-Checked -FilePath "php" -Arguments @("artisan", "config:clear") -WorkingDirectory $Backend
Invoke-Checked -FilePath "php" -Arguments @("artisan", "ops:production-check", "--target=$Target", "--no-ansi") -WorkingDirectory $Backend
Invoke-Checked -FilePath "php" -Arguments @("artisan", "subtitles:runtime-check", "--strict", "--no-ansi") -WorkingDirectory $Backend

if (-not $SkipMigrations) {
    Invoke-Checked -FilePath "php" -Arguments @("artisan", "migrate", "--force", "--no-ansi") -WorkingDirectory $Backend
}

Invoke-Checked -FilePath "php" -Arguments @("artisan", "optimize", "--no-ansi") -WorkingDirectory $Backend
Invoke-Checked -FilePath "php" -Arguments @("artisan", "queue:restart", "--no-ansi") -WorkingDirectory $Backend

if (-not $SkipHealthCheck) {
    $response = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 20

    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
        throw "Health check failed with status $($response.StatusCode): $HealthUrl"
    }
}

Write-Host "Managed Laravel deploy completed for target '$Target'."
