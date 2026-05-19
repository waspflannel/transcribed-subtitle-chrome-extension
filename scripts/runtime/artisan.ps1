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

$candidates = @()

function Add-PhpCandidate {
    param([string]$Binary)

    if (-not $Binary -or -not (Test-Path -LiteralPath $Binary)) {
        return
    }

    $resolved = (Resolve-Path -LiteralPath $Binary).Path
    foreach ($candidate in $candidates) {
        if ($candidate.Binary -eq $resolved) {
            return
        }
    }

    $script:candidates += [pscustomobject]@{
        Binary = $resolved
    }
}

$pathPhp = Get-Command php -ErrorAction SilentlyContinue
if ($pathPhp) {
    Add-PhpCandidate -Binary $pathPhp.Source
}

$wingetPhp = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
Add-PhpCandidate -Binary $wingetPhp

$runtime = $null

foreach ($candidate in $candidates) {
    & $candidate.Binary -r "exit(extension_loaded('pdo_pgsql') ? 0 : 1);" 2>$null

    if ($LASTEXITCODE -eq 0) {
        $runtime = $candidate
        break
    }
}

if ($runtime -eq $null) {
    throw "No PHP binary with pdo_pgsql was found. Install PHP 8.4 (`winget install --id PHP.PHP.8.4`) and enable pdo_pgsql in php.ini, or put another PHP build with pdo_pgsql on PATH."
}

Push-Location $Backend
try {
    & $runtime.Binary artisan @ArtisanArgs

    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
} finally {
    Pop-Location
}
