[CmdletBinding()]
param([string]$PackageDirectory, [switch]$Smoke)

$ErrorActionPreference = 'Stop'
$desktop = $PSScriptRoot
. (Join-Path $desktop 'runtime.ps1') -PackageDirectory $PackageDirectory
function Assert-Desktop([bool]$Condition, [string]$Message) { if (-not $Condition) { throw $Message } }

$fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ('transcribe-desktop-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixtureRoot | Out-Null
$environmentPath = Join-Path $fixtureRoot 'runtime.env'
New-RuntimeEnvironment -Path $environmentPath
$originalHash = (Get-FileHash -LiteralPath $environmentPath).Hash
New-RuntimeEnvironment -Path $environmentPath
Assert-Desktop ((Get-FileHash -LiteralPath $environmentPath).Hash -eq $originalHash) 'Repeated setup replaced credentials.'
$environmentContent = Get-Content -LiteralPath $environmentPath -Raw
Assert-Desktop ($environmentContent -match 'APP_KEY=base64:[A-Za-z0-9+/]{43}=') 'Encryption key must contain 32 random bytes.'
Assert-Desktop ($environmentContent -match 'DB_PASSWORD=[a-f0-9]{64}') 'Database password must contain 32 random bytes.'
Assert-Desktop ($environmentContent -match 'SUBTITLE_GENERATION_WORKERS=3' -and $environmentContent -match 'SUBTITLE_BATCH_WORKERS=6') 'New installs must use desktop-sized worker counts.'
$olderEnvironmentPath = Join-Path $fixtureRoot 'older-runtime.env'
[IO.File]::WriteAllText($olderEnvironmentPath, "APP_KEY=base64:kept`nSUBTITLE_BATCH_WORKERS=2")
New-RuntimeEnvironment -Path $olderEnvironmentPath
$olderContent = Get-Content -LiteralPath $olderEnvironmentPath -Raw
Assert-Desktop ($olderContent -match 'APP_KEY=base64:kept' -and $olderContent -match 'SUBTITLE_GENERATION_WORKERS=3') 'Repeated setup must add missing worker counts.'
Assert-Desktop ($olderContent -match 'SUBTITLE_BATCH_WORKERS=2' -and $olderContent -notmatch 'SUBTITLE_BATCH_WORKERS=6') 'Repeated setup must keep user worker counts.'

$previousDataDirectory = $env:TRANSCRIBE_DATA_DIR
$previousImage = $env:TRANSCRIBE_IMAGE
$env:TRANSCRIBE_DATA_DIR = $fixtureRoot.Replace('\', '/')
$env:TRANSCRIBE_IMAGE = 'transcribe-desktop:preview'
try {
    if (Get-Command docker -ErrorAction SilentlyContinue) {
        $configOutput = docker compose --file (Join-Path $desktop 'compose.yaml') --env-file $environmentPath config --format json
        Assert-Desktop ($LASTEXITCODE -eq 0) 'Desktop Compose configuration failed.'
        $config = ($configOutput -join "`n") | ConvertFrom-Json
        Assert-Desktop ($config.services.backend.ports[0].host_ip -eq '127.0.0.1' -and "$($config.services.backend.ports[0].published)" -eq '8001') 'Backend must bind only to loopback port 8001.'
        Assert-Desktop (-not $config.services.postgres.ports -and -not $config.services.redis.ports) 'Database and queue must not publish host ports.'
        Assert-Desktop ($config.services.backend.environment.APP_DEBUG -eq 'false') 'Runtime debug must be off.'
        Assert-Desktop ($config.services.backend.restart -eq 'no') 'The panel must control startup.'
        Assert-Desktop ($config.services.backend.volumes[0].type -eq 'volume') 'Runtime storage must persist outside the package.'
    } else {
        Write-Output 'Skipped desktop Compose checks: docker is not installed.'
    }
    foreach ($file in @('runtime.ps1', '../ops/build-desktop-release.ps1')) {
        $tokens = $null
        $parseErrors = $null
        [Management.Automation.Language.Parser]::ParseFile((Join-Path $desktop $file), [ref]$tokens, [ref]$parseErrors) | Out-Null
        Assert-Desktop ($parseErrors.Count -eq 0) "PowerShell syntax failed: $file"
    }
    $compiler = "$env:WINDIR\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
    if (Test-Path -LiteralPath $compiler) {
        & $compiler /nologo /target:winexe /reference:System.Windows.Forms.dll /reference:System.Drawing.dll /reference:System.Web.Extensions.dll ('/out:' + (Join-Path $fixtureRoot 'Transcribe.exe')) (Join-Path $desktop 'control-panel.cs')
        Assert-Desktop ($LASTEXITCODE -eq 0) 'Native control panel compilation failed.'
    } else {
        Write-Output 'Skipped control panel compilation: .NET Framework csc.exe is not installed.'
    }
    Write-Output 'Desktop setup preservation, private port and script checks passed.'

    if ($Smoke) {
        Assert-Desktop (-not [string]::IsNullOrEmpty($PackageDirectory)) 'Smoke test needs a built package directory.'
        $project = 'transcribe-desktop-smoke-' + [guid]::NewGuid().ToString('N').Substring(0, 10)
        $smokeData = Join-Path $fixtureRoot 'instance'
        $runtime = Join-Path $PackageDirectory 'runtime.ps1'
        function Invoke-DesktopRuntime([string]$RuntimeAction) {
            & "$env:WINDIR\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File $runtime -Action $RuntimeAction -PackageDirectory $PackageDirectory -DataDirectory $smokeData -ProjectName $project
            Assert-Desktop ($LASTEXITCODE -eq 0) "Windows PowerShell runtime action failed: $RuntimeAction"
        }
        $composeArguments = @('--context', 'desktop-linux', 'compose', '--project-name', $project, '--file',
            (Join-Path $PackageDirectory 'compose.yaml'), '--env-file', (Join-Path $smokeData 'runtime.env'))
        try {
            Invoke-DesktopRuntime 'Setup'
            $credentialHash = (Get-FileHash -LiteralPath (Join-Path $smokeData 'runtime.env')).Hash
            Invoke-DesktopRuntime 'Start'
            $status = Invoke-DesktopRuntime 'Status' | ConvertFrom-Json
            Assert-Desktop $status.healthy 'Backend must report healthy after startup.'
            $headers = @{ 'X-Extension-Install-Id' = [guid]::NewGuid().ToString(); Origin = 'chrome-extension://' + ('a' * 32) }
            $settingsUrl = 'http://127.0.0.1:8001/v1/settings'
            $fakeKey = 'desktop-smoke-placeholder'
            $response = Invoke-RestMethod -Uri $settingsUrl -Method Put -Headers $headers -ContentType 'application/json' -Body (@{
                retentionDays = 17; providers = @{ elevenlabs = @{ apiKey = $fakeKey } }
            } | ConvertTo-Json -Depth 4)
            Assert-Desktop (($response | ConvertTo-Json -Depth 6) -notmatch $fakeKey) 'Settings response must never expose provider keys.'
            Invoke-DesktopRuntime 'Stop'
            $status = Invoke-DesktopRuntime 'Status' | ConvertFrom-Json
            Assert-Desktop (-not $status.running) 'Stop must stop all application services.'
            Invoke-DesktopRuntime 'Setup'
            Assert-Desktop ((Get-FileHash -LiteralPath (Join-Path $smokeData 'runtime.env')).Hash -eq $credentialHash) 'Setup must preserve the application key.'
            # Move only this disposable instance's key file to exercise recovery without deleting it.
            $keyPath = [IO.Path]::GetFullPath((Join-Path $smokeData 'runtime.env'))
            Assert-Desktop ($keyPath.StartsWith([IO.Path]::GetFullPath($fixtureRoot) + '\', [StringComparison]::OrdinalIgnoreCase)) 'Unsafe key recovery fixture.'
            Move-Item -LiteralPath $keyPath -Destination (Join-Path $fixtureRoot 'key-recovery-fixture.env')
            Invoke-DesktopRuntime 'Setup'
            Assert-Desktop ((Get-FileHash -LiteralPath $keyPath).Hash -eq $credentialHash) 'Missing key must be restored from backup, never regenerated over saved data.'
            Invoke-DesktopRuntime 'Start'
            $settings = Invoke-RestMethod -Uri $settingsUrl -Headers $headers
            Assert-Desktop ($settings.retentionDays -eq 17) 'Saved settings must survive Stop/Start.'
            Assert-Desktop ((Get-Content -LiteralPath (Join-Path $smokeData 'runtime.env') -Raw) -notmatch $fakeKey) 'Provider keys belong in encrypted database settings.'
            Assert-Desktop (@(Get-ChildItem -Path (Join-Path $smokeData 'backups') -Filter 'before-start-*.dump').Count -eq 1) 'Startup must leave one database backup for this version.'
            Assert-Desktop (-not (Test-Path -LiteralPath (Join-Path $smokeData 'backups/migration-pending.txt'))) 'A successful start must clear the migration marker.'
            $untrustedStatus = 0
            try {
                $untrustedStatus = (Invoke-WebRequest -UseBasicParsing -Uri $settingsUrl -Headers @{ 'X-Extension-Install-Id' = $headers['X-Extension-Install-Id']; Origin = 'https://untrusted.example' }).StatusCode
            } catch { if ($_.Exception.Response) { $untrustedStatus = [int]$_.Exception.Response.StatusCode } }
            Assert-Desktop ($untrustedStatus -eq 403) 'Container packaging must preserve origin restrictions.'
            Write-Output 'Desktop container smoke passed: startup, migrations, worker health, settings, restart and origin checks.'
        } finally {
            # Only this test-created project may lose its disposable volumes.
            Assert-Desktop ($project -match '^transcribe-desktop-smoke-[a-f0-9]{10}$') 'Unsafe cleanup project.'
            if (Test-Path -LiteralPath (Join-Path $smokeData 'runtime.env')) {
                docker @composeArguments down --volumes --remove-orphans
                if ($LASTEXITCODE -ne 0) { Write-Warning 'Disposable desktop project cleanup failed.' }
            }
        }
    }
} finally {
    $env:TRANSCRIBE_DATA_DIR = $previousDataDirectory
    $env:TRANSCRIBE_IMAGE = $previousImage
    $resolvedFixture = [IO.Path]::GetFullPath($fixtureRoot)
    $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if ($resolvedFixture.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path $resolvedFixture -Leaf) -like 'transcribe-desktop-test-*') {
        Remove-Item -LiteralPath $resolvedFixture -Recurse -Force
    }
}
