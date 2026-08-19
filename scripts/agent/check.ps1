param(
    [switch]$SkipAppChecks
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")

Write-Host "Running agent scaffold checks in $Root"

& (Join-Path $ScriptDir "lint-docs.ps1")

if (-not $SkipAppChecks) {
    $Contracts = Join-Path $Root "packages\contracts"
    if (Test-Path (Join-Path $Contracts "package.json")) {
        Push-Location $Contracts
        try {
            npm run check
            if ($LASTEXITCODE -ne 0) {
                throw "Contract checks failed with exit code $LASTEXITCODE."
            }
        } finally {
            Pop-Location
        }
    } else {
        Write-Host "No contract package detected."
    }

    $Backend = Join-Path $Root "app\backend"
    if (Test-Path (Join-Path $Backend "artisan")) {
        Push-Location $Backend
        try {
            php artisan test --compact
            if ($LASTEXITCODE -ne 0) {
                throw "Backend tests failed with exit code $LASTEXITCODE."
            }
        } finally {
            Pop-Location
        }
    } else {
        Write-Host "No Laravel backend detected."
    }

    $Extension = Join-Path $Root "app\extension"
    if (Test-Path (Join-Path $Extension "package.json")) {
        Push-Location $Extension
        try {
            npm test
            if ($LASTEXITCODE -ne 0) {
                throw "Extension tests failed with exit code $LASTEXITCODE."
            }
            npm run compile
            if ($LASTEXITCODE -ne 0) {
                throw "Extension compile failed with exit code $LASTEXITCODE."
            }
            npm run build
            if ($LASTEXITCODE -ne 0) {
                throw "Extension build failed with exit code $LASTEXITCODE."
            }
        } finally {
            Pop-Location
        }
    } else {
        Write-Host "No WXT extension detected."
    }
}

Write-Host "Agent scaffold checks completed."
