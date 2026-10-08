[CmdletBinding()]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ArtisanArgs
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Backend = Join-Path $Root "app\backend"

if (-not $ArtisanArgs -or $ArtisanArgs.Count -eq 0) {
    $ArtisanArgs = @("list")
}

. (Join-Path $ScriptDir 'php-runtime.ps1')
$phpBinary = Resolve-PhpBinary -BackendPath $Backend

Push-Location $Backend
try {
    & $phpBinary artisan @ArtisanArgs

    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
} finally {
    Pop-Location
}
