$ErrorActionPreference = 'Stop'
$Root = Resolve-Path (Join-Path $PSScriptRoot '../../..')
$Check = Join-Path $Root 'scripts/ops/check-extension-release.ps1'
$Deploy = Join-Path $Root 'scripts/ops/deploy-managed-laravel.ps1'
$package = Get-Content -Raw -LiteralPath (Join-Path $Root 'app/extension/package.json') | ConvertFrom-Json
$ApiUrl = 'https://release-check.example.com/v1'
$outputDirectory = Join-Path $Root 'app/extension/.output'
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
$fixturePath = Join-Path $outputDirectory ('release-check-'+[guid]::NewGuid().ToString('N')+'.zip')
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Assert-Rejected([scriptblock]$Action, [string]$Message) {
    $rejected = $false
    try { & $Action | Out-Null } catch {
        if ($_.Exception.Message -notlike "*$Message*") { throw }
        $rejected = $true
    }
    if (-not $rejected) { throw "Expected rejection: $Message" }
}

function Write-FixtureArchive([hashtable]$Manifest, [string]$ExtraFile = '') {
    if (Test-Path -LiteralPath $fixturePath) { Remove-Item -LiteralPath $fixturePath }
    $archive = [IO.Compression.ZipFile]::Open($fixturePath, [IO.Compression.ZipArchiveMode]::Create)
    try {
        $writer = [IO.StreamWriter]::new($archive.CreateEntry('manifest.json').Open())
        try { $writer.Write(($Manifest | ConvertTo-Json -Depth 5)) } finally { $writer.Dispose() }
        if ($ExtraFile) { $archive.CreateEntry($ExtraFile) | Out-Null }
    } finally { $archive.Dispose() }
}

try {
    & $Check -ApiBaseUrl $ApiUrl
    foreach ($invalidUrl in @(
        'http://api.example.com/v1', 'https://localhost/v1', 'https://localhost./v1', 'https://api.localhost./v1', 'https://127.0.0.1/v1',
        'https://[::1]/v1', 'https://api.localhost/v1', 'https://user:password@api.example.com/v1',
        'https://api.example.com/v1?key=test', 'https://api.example.com/v1#token', 'https://api.example.com/',
        'https://api.example.com/v1//', 'https://api.example.com/v1///', 'https://api.example.com/V1'
    )) {
        Assert-Rejected { & $Check -ApiBaseUrl $invalidUrl } 'Release API URL'
    }

    $manifest = @{
        manifest_version = 3
        version = $package.version
        host_permissions = @('*://*.youtube.com/*', 'https://release-check.example.com/*')
    }
    Write-FixtureArchive $manifest
    & $Check -ApiBaseUrl $ApiUrl -ArchivePath $fixturePath

    foreach ($badPermissions in @(
        @('*://*.youtube.com/*', 'http://127.0.0.1:8001/*'),
        @('*://*.youtube.com/*', 'https://wrong-host.example.com/*'),
        @('*://*.youtube.com/*', 'https://release-check.example.com/*', '<all_urls>')
    )) {
        $manifest.host_permissions = $badPermissions
        Write-FixtureArchive $manifest
        Assert-Rejected { & $Check -ApiBaseUrl $ApiUrl -ArchivePath $fixturePath } 'host permissions'
    }

    $manifest.host_permissions = @('*://*.youtube.com/*', 'https://release-check.example.com/*')
    $manifest.optional_host_permissions = @('<all_urls>')
    Write-FixtureArchive $manifest
    Assert-Rejected { & $Check -ApiBaseUrl $ApiUrl -ArchivePath $fixturePath } 'host permissions'
    $manifest.Remove('optional_host_permissions')
    $manifest.version = '0.0.0'
    Write-FixtureArchive $manifest
    Assert-Rejected { & $Check -ApiBaseUrl $ApiUrl -ArchivePath $fixturePath } 'match package.json'
    $manifest.version = $package.version
    Write-FixtureArchive $manifest '.env.production'
    Assert-Rejected { & $Check -ApiBaseUrl $ApiUrl -ArchivePath $fixturePath } 'environment or private-key'

    # These reject before any install, migration or HTTP call can run.
    Assert-Rejected { & $Deploy } 'Provide an HTTPS HealthUrl'
    Assert-Rejected { & $Deploy -HealthUrl 'http://localhost/up' } 'Provide an HTTPS HealthUrl'
    Write-Host 'Release guard checks passed.'
} finally {
    if (Test-Path -LiteralPath $fixturePath) { Remove-Item -LiteralPath $fixturePath }
}
