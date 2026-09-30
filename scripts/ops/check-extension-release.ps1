[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ApiBaseUrl,
    [string]$ArchivePath
)

$ErrorActionPreference = 'Stop'
$Root = Resolve-Path (Join-Path $PSScriptRoot '../..')
$package = Get-Content -Raw -LiteralPath (Join-Path $Root 'app/extension/package.json') | ConvertFrom-Json
$uri = $null
if (-not [Uri]::TryCreate($ApiBaseUrl, [UriKind]::Absolute, [ref]$uri) -or
    $uri.Scheme -notin @('http', 'https') -or ($uri.Scheme -eq 'http' -and -not $uri.IsLoopback) -or
    $uri.UserInfo -or $uri.Query -or $uri.Fragment -or
    $uri.AbsolutePath -cnotmatch '^/v1/?$') {
    throw 'Release API URL must be an absolute /v1 address using HTTPS or loopback HTTP, without credentials, query or fragment.'
}

$version = [string]$package.version
if ($version -notmatch '^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(\.(0|[1-9]\d*))?$' -or
    ($version.Split('.') | Where-Object { [double]$_ -gt 65535 }).Count -gt 0 -or
    ($version.Split('.') | Where-Object { [double]$_ -gt 0 }).Count -eq 0) {
    throw 'Set a non-placeholder Chrome release version with components between 0 and 65535.'
}

if (-not $ArchivePath) {
    Write-Host "Extension release configuration passed for version $version."
    return
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath $ArchivePath).Path)
try {
    $manifestEntries = @($archive.Entries | Where-Object { $_.FullName -eq 'manifest.json' })
    if ($manifestEntries.Count -ne 1) { throw 'Release ZIP must contain one root manifest.json.' }
    if ($archive.Entries | Where-Object { $_.FullName -match '(^|/)(\.env($|\.)|[^/]+\.(pem|key)$)' }) {
        throw 'Release ZIP contains an environment or private-key file.'
    }
    $reader = [IO.StreamReader]::new($manifestEntries[0].Open())
    try { $manifest = $reader.ReadToEnd() | ConvertFrom-Json } finally { $reader.Dispose() }
    if ($manifest.manifest_version -ne 3 -or $manifest.version -ne $version) {
        throw 'Release ZIP manifest must be Chrome MV3 and match package.json version.'
    }
    $expectedPermissions = @('*://*.youtube.com/*', "$($uri.GetLeftPart([UriPartial]::Authority))/*")
    $actualPermissions = @($manifest.host_permissions)
    if ($actualPermissions.Count -ne $expectedPermissions.Count -or
        @(Compare-Object $expectedPermissions $actualPermissions).Count -ne 0 -or
        @($manifest.optional_host_permissions).Where({ $_ }).Count -gt 0) {
        throw 'Release ZIP host permissions must contain only YouTube and the selected API origin.'
    }
} finally {
    $archive.Dispose()
}

Write-Host "Extension release ZIP verified: $ArchivePath"
