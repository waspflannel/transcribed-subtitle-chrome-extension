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

try {
    $uri = [System.Uri]$ApiBaseUrl
} catch {
    throw "ApiBaseUrl must be an absolute URL."
}

if ($uri.Scheme -ne "https") {
    throw "Production extension builds require an HTTPS API base URL."
}

$expectedHostPermission = "$($uri.Scheme)://$($uri.Authority)/*"
$previousApiBaseUrl = $env:WXT_BACKEND_API_BASE_URL
$env:WXT_BACKEND_API_BASE_URL = $ApiBaseUrl

try {
    Push-Location $Extension

    $package = Get-Content -Raw -LiteralPath (Join-Path $Extension "package.json") | ConvertFrom-Json

    if (-not $package.version -or $package.version -eq "0.0.0") {
        throw "Set a real Chrome Web Store release version in app/extension/package.json before building a release."
    }

    npm audit --omit=dev --audit-level=high

    if ($LASTEXITCODE -ne 0) {
        throw "Production extension dependency audit failed."
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

    npm run build

    if ($LASTEXITCODE -ne 0) {
        throw "WXT build failed."
    }

    $manifestPath = Join-Path $Extension ".output\chrome-mv3\manifest.json"
    $manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json

    if ($manifest.host_permissions -notcontains $expectedHostPermission) {
        throw "Built manifest does not include expected host permission: $expectedHostPermission"
    }

    if ($manifest.host_permissions | Where-Object { $_ -match '^http://(localhost|127\.0\.0\.1)(:\d+)?/\*$' }) {
        throw "Built manifest still contains a local backend host permission."
    }

    npm run zip

    if ($LASTEXITCODE -ne 0) {
        throw "WXT zip failed."
    }

    Get-ChildItem -LiteralPath (Join-Path $Extension ".output") -Filter "*.zip" |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 3 |
        ForEach-Object { Write-Host "Extension release artifact: $($_.FullName)" }
} finally {
    Pop-Location
    $env:WXT_BACKEND_API_BASE_URL = $previousApiBaseUrl
}
