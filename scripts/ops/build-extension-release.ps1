[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ApiBaseUrl,
    [switch]$SkipTests
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Extension = Join-Path $Root "app\extension"

& (Join-Path $ScriptDir 'check-extension-release.ps1') -ApiBaseUrl $ApiBaseUrl
$previousApiBaseUrl = $env:WXT_BACKEND_API_BASE_URL
$env:WXT_BACKEND_API_BASE_URL = $ApiBaseUrl

try {
    Push-Location $Extension

    $package = Get-Content -Raw -LiteralPath (Join-Path $Extension "package.json") | ConvertFrom-Json

    # WXT and contracts are devDependencies, but contribute code to the browser bundle.
    npm audit --audit-level=high

    if ($LASTEXITCODE -ne 0) {
        throw "Extension dependency audit failed."
    }

    if (-not $SkipTests) {
        npm test

        if ($LASTEXITCODE -ne 0) {
            throw "Extension tests failed."
        }

        npm run compile

        if ($LASTEXITCODE -ne 0) {
            throw "Extension TypeScript compile failed."
        }
    }

    # WXT zip builds once; verify the packaged manifest, not an earlier build directory.
    npm run zip

    if ($LASTEXITCODE -ne 0) {
        throw "WXT zip failed."
    }

    $archivePath = Join-Path $Extension ".output/$($package.name)-$($package.version)-chrome.zip"
    & (Join-Path $ScriptDir 'check-extension-release.ps1') -ApiBaseUrl $ApiBaseUrl -ArchivePath $archivePath
    Write-Host "Extension release artifact: $archivePath"
} finally {
    Pop-Location
    $env:WXT_BACKEND_API_BASE_URL = $previousApiBaseUrl
}
