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
$AgentCheck = Join-Path $Root "scripts\agent\check.ps1"

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

Invoke-Checked -FilePath "php" -Arguments @("artisan", "config:clear") -WorkingDirectory $Backend
Invoke-Checked -FilePath "php" -Arguments @("artisan", "ops:production-check", "--target=$Target", "--no-ansi") -WorkingDirectory $Backend
Invoke-Checked -FilePath "php" -Arguments @("artisan", "subtitles:runtime-check", "--strict", "--no-ansi") -WorkingDirectory $Backend

if (-not $SkipMigrations) {
    Invoke-Checked -FilePath "php" -Arguments @("artisan", "migrate", "--force", "--no-ansi") -WorkingDirectory $Backend
}

Invoke-Checked -FilePath "php" -Arguments @("artisan", "optimize", "--no-ansi") -WorkingDirectory $Backend
Invoke-Checked -FilePath "php" -Arguments @("artisan", "queue:restart", "--no-ansi") -WorkingDirectory $Backend

if (-not $SkipHealthCheck -and $HealthUrl) {
    $response = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 20

    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
        throw "Health check failed with status $($response.StatusCode): $HealthUrl"
    }
}

Write-Host "Managed Laravel deploy completed for target '$Target'."
