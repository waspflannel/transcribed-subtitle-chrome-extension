[CmdletBinding()]
param(
    [ValidateSet('Setup', 'Start', 'Stop', 'Status')][string]$Action = 'Status',
    [string]$PackageDirectory = $PSScriptRoot,
    [string]$DataDirectory = (Join-Path $env:LOCALAPPDATA 'Transcribe'),
    [ValidatePattern('^[a-z0-9][a-z0-9_-]+$')][string]$ProjectName = 'transcribe-desktop',
    # Stop even when subtitle generations are running.
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
trap { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
$script:DockerPath = $null
$script:DockerPrefix = @('--context', 'desktop-linux')

function Write-Stage([string]$Message) {
    Write-Output "TRANSCRIBE: $Message"
}

function Find-Docker {
    $command = Get-Command docker.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    foreach ($directory in @(
        (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop'),
        (Join-Path $env:ProgramFiles 'Docker\Docker')
    )) {
        $candidate = Join-Path $directory 'resources\bin\docker.exe'
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    return $null
}

function Invoke-Docker {
    param([string[]]$Arguments, [switch]$Capture)
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $script:DockerPath @script:DockerPrefix @Arguments 2>&1
        $dockerExit = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previousPreference }
    if ($dockerExit -ne 0) {
        # Docker commands here never render the environment or inspect credential values.
        throw "Docker could not complete '$($Arguments[0])'. $($output -join [Environment]::NewLine)"
    }
    if ($Capture) { return $output }
    $output | ForEach-Object { Write-Output "$_" }
}

function Invoke-Compose {
    param([string[]]$Arguments, [switch]$Capture)
    Invoke-Docker -Arguments (@('compose') + $script:ComposeArguments + $Arguments) -Capture:$Capture
}

# Desktop-sized worker counts; server defaults (9 + 22 workers) are too heavy for one computer.
$script:DesktopDefaults = @('SUBTITLE_GENERATION_WORKERS=3', 'SUBTITLE_BATCH_WORKERS=6')

function New-RuntimeEnvironment([string]$Path) {
    if (Test-Path -LiteralPath $Path) {
        # Add defaults introduced after this file was created. Never change existing values.
        $existing = @(Get-Content -LiteralPath $Path)
        $missing = @($script:DesktopDefaults | Where-Object { -not ($existing -match ('^' + $_.Split('=')[0] + '=')) })
        if ($missing.Count -gt 0) { [IO.File]::AppendAllText($Path, "`n" + ($missing -join "`n") + "`n") }
        return
    }
    $key = New-Object byte[] 32
    $password = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($key); $rng.GetBytes($password) } finally { $rng.Dispose() }
    $content = (@(
        '# APP_KEY encrypts your saved keys and data. Keep a copy of this file outside %LOCALAPPDATA%\Transcribe.',
        'APP_NAME=Transcribe', 'APP_ENV=production', 'APP_DEBUG=false',
        ('APP_KEY=base64:' + [Convert]::ToBase64String($key)),
        'DB_CONNECTION=pgsql', 'DB_USERNAME=subtitle',
        ('DB_PASSWORD=' + ([BitConverter]::ToString($password).Replace('-', '').ToLowerInvariant())),
        'DB_CONNECT_TIMEOUT_SECONDS=5', 'CACHE_STORE=database',
        'QUEUE_CONNECTION=redis', 'SUBTITLE_QUEUE_CONNECTION=redis',
        'SUBTITLE_CONCURRENCY_CACHE_STORE=subtitle_concurrency',
        'REDIS_CLIENT=predis', 'REDIS_DB=0', 'REDIS_CACHE_DB=1',
        'REDIS_QUEUE_CONNECTION=queue', 'REDIS_QUEUE_DB=2',
        'LOG_CHANNEL=stderr', 'LOG_LEVEL=info', 'MAIL_MAILER=log',
        'YOUTUBE_AUDIO_BINARY=yt-dlp', 'FFMPEG_BINARY=ffmpeg'
    ) + $script:DesktopDefaults) -join "`n"
    # CreateNew prevents a repeated setup from replacing an existing encryption key.
    $file = [IO.File]::Open($Path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write)
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($content + "`n")
        $file.Write($bytes, 0, $bytes.Length)
    } finally { $file.Dispose() }
}

function Ensure-Docker {
    $script:DockerPath = Find-Docker
    if ($script:DockerPath) {
        try {
            $os = (Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}') -Capture) -join ''
            if ($os.Trim() -eq 'linux') {
                Invoke-Docker -Arguments @('compose', 'version') -Capture | Out-Null
                return
            }
        } catch { }
    }
    if (-not $script:DockerPath) {
        if ($Action -ne 'Setup') { throw 'Run Install all requirements first.' }
        if ([Environment]::Is64BitOperatingSystem -ne $true -or $env:PROCESSOR_ARCHITECTURE -eq 'ARM64') {
            throw 'This download requires a Windows x64 computer.'
        }
        Write-Stage 'Downloading Docker Desktop. Windows may request permission or a restart.'
        $installer = Join-Path $DataDirectory 'Docker Desktop Installer.exe'
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -UseBasicParsing -Uri 'https://desktop.docker.com/win/main/amd64/Docker%20Desktop%20Installer.exe' -OutFile $installer
        $signature = Get-AuthenticodeSignature -LiteralPath $installer
        if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch '(CN|O)=Docker Inc') {
            throw 'Docker installer signature could not be verified. Setup stopped.'
        }
        Write-Stage 'Installing Docker Desktop...'
        $installation = Start-Process -FilePath $installer -ArgumentList @('install', '--user', '--quiet', '--backend=wsl-2') -WindowStyle Hidden -Wait -PassThru
        if ($installation.ExitCode -eq 3010) { throw 'Restart Windows, then reopen Transcribe and run setup again.' }
        if ($installation.ExitCode -ne 0) { throw 'Docker installation did not finish. Open Docker Desktop to complete setup, then try again.' }
        $script:DockerPath = Find-Docker
        if (-not $script:DockerPath) { throw 'Restart Windows to finish Docker installation, then run setup again.' }
    }
    Write-Stage 'Starting Docker Desktop. Complete any first-time setup prompts.'
    # Desktop's lifecycle command is independent of the engine context, which may not exist until first launch.
    $desktopOutput = & $script:DockerPath desktop start --detach 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Open Docker Desktop and finish its setup, then try again. $desktopOutput" }
    $deadline = [DateTime]::UtcNow.AddMinutes(3)
    do {
        try {
            $os = (Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}') -Capture) -join ''
            if ($os.Trim() -eq 'linux') {
                Invoke-Docker -Arguments @('compose', 'version') -Capture | Out-Null
                return
            }
        } catch { }
        Start-Sleep -Seconds 3
    } while ([DateTime]::UtcNow -lt $deadline)
    throw 'Docker is not ready. Open Docker Desktop, finish setup or restart Windows if requested, then try again.'
}

function Get-RuntimeStatus {
    $installedPath = Join-Path $DataDirectory 'installed-image.txt'
    $ready = (Test-Path -LiteralPath $script:EnvironmentPath) -and (Test-Path -LiteralPath $installedPath) -and
        (Get-Content -LiteralPath $installedPath -Raw).Trim() -eq $script:PackageImage
    $running = $false
    $healthy = $false
    $backendRunning = $false
    if ($script:DockerPath -and (Test-Path -LiteralPath $script:EnvironmentPath)) {
        try {
            $containers = @((Invoke-Compose -Arguments @('ps', '--all', '--format', 'json') -Capture) |
                Where-Object { "$_".StartsWith('{') } | ForEach-Object { "$_" | ConvertFrom-Json })
            $running = @($containers | Where-Object { $_.State -eq 'running' }).Count -gt 0
            $backendRunning = @($containers | Where-Object { $_.Service -eq 'backend' -and $_.State -eq 'running' }).Count -eq 1
            $healthy = $ready -and @($containers | Where-Object { $_.State -eq 'running' -and $_.Health -eq 'healthy' }).Count -eq 3 -and
                @($containers | Where-Object { $_.Service -eq 'backend' -and $_.Image -eq $script:PackageImage }).Count -eq 1
        } catch { }
    }
    return [pscustomobject]@{ setupComplete = $ready; running = $running; healthy = $healthy; backendRunning = $backendRunning }
}

if ($MyInvocation.InvocationName -eq '.') { return }

$manifest = Get-Content -LiteralPath (Join-Path $PackageDirectory 'package.json') -Raw | ConvertFrom-Json
if ($manifest.image -notmatch '^transcribe-desktop:[a-zA-Z0-9_.-]+$') { throw 'Invalid package image.' }
$script:PackageImage = $manifest.image
$script:EnvironmentPath = Join-Path $DataDirectory 'runtime.env'
$env:TRANSCRIBE_IMAGE = $manifest.image
$env:TRANSCRIBE_DATA_DIR = [IO.Path]::GetFullPath($DataDirectory).Replace('\', '/').TrimEnd('/')
$script:ComposeArguments = @('--project-name', $ProjectName, '--file', (Join-Path $PackageDirectory 'compose.yaml'), '--env-file', $script:EnvironmentPath)
$script:DockerPath = Find-Docker

if ($Action -eq 'Status') { Get-RuntimeStatus | ConvertTo-Json -Compress; return }

# One operation per installation, including when two panel windows are opened.
$operationLock = New-Object Threading.Mutex($false, ('Local\Transcribe-' + $ProjectName))
if (-not $operationLock.WaitOne(0)) { $operationLock.Dispose(); throw 'Another Transcribe operation is already running.' }
try {
    New-Item -ItemType Directory -Force -Path $DataDirectory | Out-Null
    Ensure-Docker
    if ($Action -eq 'Setup') {
        Write-Stage 'Preparing your private instance...'
        if (-not (Test-Path -LiteralPath $script:EnvironmentPath)) {
            $keyBackup = Join-Path $DataDirectory 'backups/runtime.env'
            if (Test-Path -LiteralPath $keyBackup) {
                Copy-Item -LiteralPath $keyBackup -Destination $script:EnvironmentPath
                Write-Stage 'Restored your instance configuration from its local backup.'
            } else {
                $volumeNames = @(Invoke-Docker -Arguments @('volume', 'ls', '--format', '{{.Name}}') -Capture)
                if ($volumeNames -contains ($ProjectName + '_database')) {
                    throw 'Saved data already exists, but its encryption key is missing. Restore runtime.env from your backup before running setup.'
                }
            }
        }
        New-RuntimeEnvironment -Path $script:EnvironmentPath
        $archive = Join-Path $PackageDirectory 'runtime-images.tar'
        Write-Stage 'Checking the bundled runtime...'
        if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $manifest.archiveSha256) {
            throw 'The runtime download is incomplete or damaged. Download Transcribe again.'
        }
        Write-Stage 'Installing the bundled runtime. This can take a few minutes...'
        Invoke-Docker -Arguments @('image', 'load', '--input', $archive)
        $manifest.image | Set-Content -LiteralPath (Join-Path $DataDirectory 'installed-image.txt')
        Write-Stage 'Setup complete. Select Start backend.'
    } elseif ($Action -eq 'Start') {
        if (-not (Test-Path -LiteralPath $script:EnvironmentPath)) { throw 'Run Install all requirements first.' }
        if ((Get-Content -LiteralPath (Join-Path $DataDirectory 'installed-image.txt') -Raw).Trim() -ne $manifest.image) {
            throw 'Run Install all requirements to prepare this version. Your data and keys will be preserved.'
        }
        $status = Get-RuntimeStatus
        if ($status.healthy) { Write-Stage 'Ready. The backend is already running.'; return }
        if (-not $status.backendRunning) {
            # Fixed port: the packaged extension and guide are built for 127.0.0.1:8001.
            $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 8001)
            try { $listener.Start() } catch { throw 'Port 8001 is already in use. Stop the other backend, then select Start backend again.' } finally { $listener.Stop() }
        }
        Write-Stage 'Starting database and queue...'
        Invoke-Compose -Arguments @('up', '--wait', '--wait-timeout', '120', 'postgres', 'redis')
        Invoke-Compose -Arguments @('stop', 'backend')
        $backupDirectory = Join-Path $DataDirectory 'backups'
        New-Item -ItemType Directory -Force -Path $backupDirectory | Out-Null
        Copy-Item -LiteralPath $script:EnvironmentPath -Destination (Join-Path $backupDirectory 'runtime.env')
        # One snapshot per image version, refreshed on each start. A failed migration leaves the
        # marker behind, so a retry keeps the snapshot taken before it instead of dumping half-migrated data.
        $migrationMarker = Join-Path $backupDirectory 'migration-pending.txt'
        if (Test-Path -LiteralPath $migrationMarker) {
            Write-Stage 'Keeping the backup made before the last unfinished start...'
        } else {
            Write-Stage 'Backing up saved data before checking migrations...'
            $backup = Join-Path $backupDirectory ('before-start-' + $manifest.image.Split(':')[1] + '.dump')
            Invoke-Compose -Arguments @('exec', '-T', 'postgres', 'pg_dump', '-U', 'subtitle', '-d', 'transcribe', '-Fc', '-f', '/tmp/before-start.dump')
            Invoke-Compose -Arguments @('cp', 'postgres:/tmp/before-start.dump', "$backup.partial")
            Move-Item -LiteralPath "$backup.partial" -Destination $backup -Force
            $manifest.image | Set-Content -LiteralPath $migrationMarker
        }
        Write-Stage 'Applying migrations...'
        Invoke-Compose -Arguments @('run', '--rm', '--no-deps', 'backend', 'migrate')
        Remove-Item -LiteralPath $migrationMarker
        Write-Stage 'Starting backend, workers and scheduler...'
        Invoke-Compose -Arguments @('up', '--wait', '--wait-timeout', '240')
        if (-not (Get-RuntimeStatus).healthy) { throw 'The backend did not become healthy. Open the setup log for details.' }
        Write-Stage 'Ready. Open the extension guide to finish setup.'
    } else {
        if (-not $Force) {
            # Stopping interrupts generation and correction work; the panel asks before rerunning with -Force.
            $active = ''
            try {
                $active = (Invoke-Compose -Arguments @('exec', '-T', 'postgres', 'psql', '-U', 'subtitle', '-d', 'transcribe', '-tAc',
                    "select (select count(*) from subtitle_jobs where status = 'running') + (select count(*) from subtitle_track_lyrics_corrections where status in ('queued', 'running'))") -Capture) -join ''
            } catch { }
            if ($active.Trim() -match '^[1-9][0-9]*$') { Write-Output "TRANSCRIBE-ACTIVE: $($active.Trim())"; return }
        }
        Write-Stage 'Stopping Transcribe. Saved subtitles and keys will be kept.'
        Invoke-Compose -Arguments @('stop', '--timeout', '30')
        Write-Stage 'Backend stopped.'
    }
} finally { $operationLock.ReleaseMutex(); $operationLock.Dispose() }
