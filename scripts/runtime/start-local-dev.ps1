[CmdletBinding()]
param(
    [string]$Php,
    [int]$Port = 8001,
    [switch]$SkipDocker,
    [switch]$SkipMigrate,
    [switch]$SkipExtension
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Extension = Join-Path $Root "app\extension"
$launcherArgs = @{}
$npmPath = $null

if (-not $SkipExtension) {
    $npmCommand = Get-Command npm.cmd -ErrorAction SilentlyContinue
    if (-not $npmCommand) {
        $npmCommand = Get-Command npm -ErrorAction SilentlyContinue
    }

    if (-not $npmCommand) {
        throw "npm was not found. Install Node.js, then rerun this script."
    }

    $npmPath = $npmCommand.Source
}

if ($Php) {
    $launcherArgs.Php = $Php
}

$launcherArgs.Port = $Port

if ($SkipDocker) {
    $launcherArgs.SkipDocker = $true
}

if ($SkipMigrate) {
    $launcherArgs.SkipMigrate = $true
}

& (Join-Path $ScriptDir "start-local-backend-workers.ps1") @launcherArgs

& (Join-Path $ScriptDir "start-local-stripe.ps1")

if ($SkipExtension) {
    Write-Host "Local containers, backend, and workers are running."
    return
}

Write-Host "Starting the extension dev server. Press Ctrl+C to stop it."
Push-Location $Extension
try {
    & $npmPath run dev

    if ($LASTEXITCODE -ne 0) {
        throw "Extension dev server exited with code $LASTEXITCODE."
    }
} finally {
    Pop-Location
}
