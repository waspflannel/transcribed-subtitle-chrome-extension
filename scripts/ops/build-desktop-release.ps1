[CmdletBinding()]
param(
    [string]$OutputDirectory = (Join-Path $PSScriptRoot '..\..\dist\desktop'),
    [string]$GuideUrl = 'http://127.0.0.1:8001/#how-to-install',
    [string]$Image,
    [switch]$SkipImageBuild
)

$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$desktop = Join-Path $root 'scripts\desktop'
$extension = Join-Path $root 'app\extension'
$version = (Get-Content -LiteralPath (Join-Path $extension 'package.json') -Raw | ConvertFrom-Json).version
if (-not $Image) { $Image = 'transcribe-desktop:' + $version + '-' + (Get-Date -Format 'yyyyMMddHHmmss') }
$guide = [Uri]$GuideUrl
if ($guide.Scheme -ne 'https' -and -not ($guide.Scheme -eq 'http' -and $guide.Host -eq '127.0.0.1')) { throw 'The guide must use HTTPS or local loopback HTTP.' }
if ($Image -notmatch '^transcribe-desktop:[a-zA-Z0-9_.-]+$') { throw 'Use a transcribe-desktop image tag.' }

if (-not $SkipImageBuild) {
    docker build --platform linux/amd64 --file (Join-Path $desktop 'Dockerfile') --tag $Image $root
    if ($LASTEXITCODE -ne 0) { throw 'Desktop runtime image build failed.' }
}
foreach ($serviceImage in @('postgres:16-alpine', 'redis:7-alpine')) {
    docker pull --platform linux/amd64 $serviceImage
    if ($LASTEXITCODE -ne 0) { throw "Could not prepare $serviceImage." }
}

$oldApiUrl = $env:WXT_BACKEND_API_BASE_URL
$env:WXT_BACKEND_API_BASE_URL = 'http://127.0.0.1:8001/v1'
Push-Location $extension
try {
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Desktop extension build failed.' }
} finally { Pop-Location; $env:WXT_BACKEND_API_BASE_URL = $oldApiUrl }

# Every build gets a fresh directory. Never remove or overwrite an existing download or user data.
$destination = Join-Path ([IO.Path]::GetFullPath($OutputDirectory)) ('Transcribe-' + $version + '-windows-x64-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $destination | Out-Null
foreach ($file in @('runtime.ps1', 'compose.yaml')) {
    Copy-Item -LiteralPath (Join-Path $desktop $file) -Destination $destination
}
$compiler = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
& $compiler /nologo /target:winexe /reference:System.Windows.Forms.dll /reference:System.Drawing.dll /reference:System.Web.Extensions.dll ('/out:' + (Join-Path $destination 'Transcribe.exe')) (Join-Path $desktop 'control-panel.cs')
if ($LASTEXITCODE -ne 0) { throw 'Could not build the native Windows launcher.' }
Copy-Item -LiteralPath (Join-Path $extension '.output\chrome-mv3') -Destination (Join-Path $destination 'extension') -Recurse
$archive = Join-Path $destination 'runtime-images.tar'
docker image save --output $archive $Image postgres:16-alpine redis:7-alpine
if ($LASTEXITCODE -ne 0) { throw 'Could not bundle the runtime images.' }
@{
    version = $version
    image = $Image
    guideUrl = $GuideUrl
    archiveSha256 = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $destination 'package.json') -Encoding UTF8
$zip = $destination + '.zip'
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory($destination, $zip, [IO.Compression.CompressionLevel]::Optimal, $true)
Write-Output "Desktop download: $zip"
Write-Output "Extract and open: $(Join-Path $destination 'Transcribe.exe')"
