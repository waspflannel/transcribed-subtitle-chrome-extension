[CmdletBinding()]
param(
    [ValidateSet("staging", "production")]
    [string]$Target = "production",
    [switch]$SkipRuntimeCheck
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Artisan = Join-Path $ScriptDir "artisan.ps1"

function Invoke-Checked {
    param(
        [string]$FilePath,
        [string[]]$Arguments
    )

    & $FilePath @Arguments

    if ($LASTEXITCODE -ne 0) {
        throw "Command failed: $FilePath $($Arguments -join ' ')"
    }
}

Invoke-Checked -FilePath $Artisan -Arguments @("config:clear")
Invoke-Checked -FilePath $Artisan -Arguments @("ops:production-check", "--target=$Target", "--json", "--no-ansi")

if (-not $SkipRuntimeCheck) {
    Invoke-Checked -FilePath $Artisan -Arguments @("subtitles:runtime-check", "--strict", "--json", "--no-ansi")
}

Write-Host "Production readiness checks completed for target '$Target'."
