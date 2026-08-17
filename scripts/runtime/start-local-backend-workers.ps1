[CmdletBinding()]
param(
    [string]$Php,
    [string]$HostName = "127.0.0.1",
    [int]$Port = 8001,
    [int]$WorkerTimeoutSeconds = 1200,
    [int]$WorkerMaxTimeSeconds = 0,
    [int]$WorkerMemoryMb = 256,
    [int]$WorkerSleepSeconds = 1,
    [int]$WorkerTries = 0,
    [int]$GracefulWorkerShutdownSeconds = 5,
    [switch]$SkipDocker,
    [switch]$SkipMigrate,
    [switch]$SkipBackend,
    [switch]$SkipWorkers,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Backend = Join-Path $Root "app\backend"
$RuntimeLogDir = Join-Path $Backend "storage\logs\local-runtime"
$WorkerLogDir = Join-Path $Backend "storage\logs\subtitle-workers"
$PidPath = Join-Path $RuntimeLogDir "local-runtime-pids.json"
$Timestamp = Get-Date -Format "yyyyMMddHHmmss"

function Add-PhpCandidate {
    param(
        [System.Collections.Generic.List[object]]$Candidates,
        [string]$Binary
    )

    if (-not $Binary -or -not (Test-Path -LiteralPath $Binary)) {
        return
    }

    $resolved = (Resolve-Path -LiteralPath $Binary).Path

    foreach ($candidate in $Candidates) {
        if ($candidate.Binary -eq $resolved) {
            return
        }
    }

    $Candidates.Add([pscustomobject]@{ Binary = $resolved }) | Out-Null
}

function Resolve-PhpBinary {
    $candidates = [System.Collections.Generic.List[object]]::new()

    Add-PhpCandidate -Candidates $candidates -Binary $Php

    $pathPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($pathPhp) {
        Add-PhpCandidate -Candidates $candidates -Binary $pathPhp.Source
    }

    if ($env:LOCALAPPDATA) {
        $wingetPhp = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
        Add-PhpCandidate -Candidates $candidates -Binary $wingetPhp
    }

    foreach ($candidate in $candidates) {
        & $candidate.Binary -r "exit(extension_loaded('pdo_pgsql') ? 0 : 1);" 2>$null

        if ($LASTEXITCODE -ne 0) {
            continue
        }

        Push-Location $Backend
        try {
            & $candidate.Binary artisan --version *> $null

            if ($LASTEXITCODE -eq 0) {
                return $candidate.Binary
            }
        } finally {
            Pop-Location
        }
    }

    throw "No PHP binary with pdo_pgsql and artisan support was found. Pass -Php C:\path\to\php.exe."
}

function Invoke-ArtisanOutput {
    param([string[]]$ArtisanArgs)

    Push-Location $Backend
    try {
        $output = & $script:PhpBinary artisan @ArtisanArgs 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    if ($exitCode -ne 0) {
        throw "Artisan command failed: artisan $($ArtisanArgs -join ' ')`n$($output -join [Environment]::NewLine)"
    }

    return $output -join [Environment]::NewLine
}

function ConvertTo-SafeName {
    param([string]$Value)

    $safe = $Value -replace "[^a-zA-Z0-9_-]+", "-"
    if ($safe) {
        return $safe.Trim("-")
    }

    return "worker"
}

function Get-WorkerGroupsFromRuntime {
    param($Runtime)

    return @($Runtime.summary.subtitleWorkerGroups | ForEach-Object {
        [pscustomobject]@{
            name = "$($_.name)"
            queue_family = "$($_.queue_family)"
            queues = @($_.queues)
            worker_count = [int]$_.worker_count
        }
    })
}

function Test-CommandContainsAny {
    param(
        [string]$CommandLine,
        [string[]]$Needles
    )

    foreach ($needle in $Needles) {
        if ($needle -and $CommandLine.Contains($needle)) {
            return $true
        }
    }

    return $false
}

function Get-ProtectedProcessIds {
    param($ProcessesById)

    $protected = @{}
    $currentId = [int]$PID

    for ($depth = 0; $depth -lt 8 -and $currentId -gt 0; $depth++) {
        $protected[$currentId] = $true

        if (-not $ProcessesById.ContainsKey($currentId)) {
            break
        }

        $currentId = [int]$ProcessesById[$currentId].ParentProcessId
    }

    return $protected
}

function Get-RecordedRuntimeProcessIds {
    if (-not (Test-Path -LiteralPath $PidPath)) {
        return @()
    }

    try {
        $recorded = Get-Content -LiteralPath $PidPath -Raw | ConvertFrom-Json
    } catch {
        return @()
    }

    return @(
        @($recorded) |
            ForEach-Object { [int]$_.pid } |
            Where-Object { $_ -gt 0 } |
            Sort-Object -Unique
    )
}

function Test-IsBackendServeCommand {
    param([string]$CommandLine)

    if (-not $CommandLine) {
        return $false
    }

    $normalized = $CommandLine.Replace("\", "/")
    $backendForward = "$Backend".Replace("\", "/")

    return (
        ($normalized.Contains($backendForward) -and $normalized.Contains("server.php")) -or
        (
            $CommandLine -match "\bartisan\s+serve\b" -and
            (
                $CommandLine.Contains("--port=$Port") -or
                $CommandLine.Contains("--port $Port")
            )
        )
    )
}

function Test-IsSubtitleWorkerCommand {
    param(
        [string]$CommandLine,
        [string[]]$QueueNames
    )

    if (-not $CommandLine -or $CommandLine -notmatch "\bqueue:work\b") {
        return $false
    }

    return (
        $CommandLine.Contains("subtitle-auto-worker") -or
        $CommandLine.Contains("tse-local-") -or
        $CommandLine -match "subtitle-(generation|batch|ai)" -or
        (Test-CommandContainsAny -CommandLine $CommandLine -Needles $QueueNames)
    )
}

function Get-ExistingRuntimeProcesses {
    param([string[]]$QueueNames)

    $processMatches = [System.Collections.Generic.List[object]]::new()
    $allProcesses = @(Get-CimInstance Win32_Process)
    $processesById = @{}
    $childrenByParent = @{}
    $seedIds = @{}
    $protectedIds = @{}

    foreach ($process in $allProcesses) {
        $processId = [int]$process.ProcessId
        $parentId = [int]$process.ParentProcessId
        $processesById[$processId] = $process

        if (-not $childrenByParent.ContainsKey($parentId)) {
            $childrenByParent[$parentId] = [System.Collections.Generic.List[int]]::new()
        }

        $childrenByParent[$parentId].Add($processId) | Out-Null
    }

    $protectedIds = Get-ProtectedProcessIds -ProcessesById $processesById

    foreach ($recordedId in (Get-RecordedRuntimeProcessIds)) {
        if ($processesById.ContainsKey($recordedId) -and -not $protectedIds.ContainsKey($recordedId)) {
            $seedIds[$recordedId] = $true
        }
    }

    foreach ($process in $allProcesses) {
        $processId = [int]$process.ProcessId
        $command = "$($process.CommandLine)"

        if (-not $command -or $protectedIds.ContainsKey($processId)) {
            continue
        }

        if (
            (Test-IsBackendServeCommand -CommandLine $command) -or
            (Test-IsSubtitleWorkerCommand -CommandLine $command -QueueNames $QueueNames)
        ) {
            $seedIds[$processId] = $true
        }
    }

    $targetIds = [System.Collections.Generic.List[int]]::new()
    $pending = [System.Collections.Generic.Queue[int]]::new()

    foreach ($seedId in $seedIds.Keys) {
        $pending.Enqueue([int]$seedId)
    }

    while ($pending.Count -gt 0) {
        $processId = $pending.Dequeue()

        if ($protectedIds.ContainsKey($processId) -or -not $processesById.ContainsKey($processId)) {
            continue
        }

        if ($targetIds -contains $processId) {
            continue
        }

        $targetIds.Add($processId) | Out-Null

        if ($childrenByParent.ContainsKey($processId)) {
            foreach ($childId in $childrenByParent[$processId]) {
                if (-not $protectedIds.ContainsKey($childId)) {
                    $pending.Enqueue([int]$childId)
                }
            }
        }
    }

    foreach ($processId in ($targetIds | Sort-Object)) {
        $process = $processesById[$processId]
        $command = "$($process.CommandLine)"
        $kind = if (
            (Test-IsBackendServeCommand -CommandLine $command) -or
            ($command -and $command.Replace("\", "/").Contains("server.php"))
        ) {
            "backend"
        } elseif (Test-IsSubtitleWorkerCommand -CommandLine $command -QueueNames $QueueNames) {
            "worker"
        } else {
            "runtime-child"
        }

        $processMatches.Add([pscustomobject]@{
            ProcessId = $processId
            Name = "$($process.Name)"
            Kind = $kind
            CommandLine = $command
        }) | Out-Null
    }

    return @($processMatches)
}

function Stop-ExistingProcesses {
    param(
        [object[]]$Processes,
        [bool]$RestartQueueWorkersFirst
    )

    if ($Processes.Count -eq 0) {
        Write-Host "No existing local backend or subtitle worker processes found."
        return
    }

    Write-Host "Existing local runtime processes:"
    Write-Host ($Processes | Select-Object ProcessId, Kind, Name, CommandLine | Format-Table -AutoSize | Out-String)

    if ($DryRun) {
        Write-Host "Dry run: would stop $($Processes.Count) process(es)."
        return
    }

    if ($RestartQueueWorkersFirst -and ($Processes | Where-Object { $_.Kind -eq "worker" }).Count -gt 0) {
        Write-Host "Signaling queue workers to restart..."
        Invoke-ArtisanOutput -ArtisanArgs @("queue:restart", "--no-ansi") | Out-Null

        if ($GracefulWorkerShutdownSeconds -gt 0) {
            Start-Sleep -Seconds $GracefulWorkerShutdownSeconds
        }
    }

    # Stop children before parents so the tree tears down cleanly.
    foreach ($process in ($Processes | Sort-Object ProcessId -Descending)) {
        if ([int]$process.ProcessId -eq [int]$PID) {
            continue
        }

        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }

    Start-Sleep -Milliseconds 500
}

function Start-BackendServer {
    if ($SkipBackend) {
        return $null
    }

    New-Item -ItemType Directory -Force -Path $RuntimeLogDir | Out-Null

    $stdout = Join-Path $RuntimeLogDir "backend-serve-$Timestamp.log"
    $stderr = Join-Path $RuntimeLogDir "backend-serve-$Timestamp.err.log"
    $args = @("artisan", "serve", "--host=$HostName", "--port=$Port")

    if ($DryRun) {
        Write-Host "Dry run: would start backend: $script:PhpBinary $($args -join ' ')"
        return $null
    }

    $process = Start-Process `
        -FilePath $script:PhpBinary `
        -ArgumentList $args `
        -WorkingDirectory $Backend `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        -PassThru

    return [pscustomobject]@{
        kind = "backend"
        name = "backend-serve"
        pid = $process.Id
        stdout = $stdout
        stderr = $stderr
    }
}

function Start-WorkerProcesses {
    param(
        [object[]]$WorkerGroups,
        [string]$QueueConnection
    )

    if ($SkipWorkers) {
        return @()
    }

    New-Item -ItemType Directory -Force -Path $WorkerLogDir | Out-Null
    $started = [System.Collections.Generic.List[object]]::new()

    foreach ($group in $WorkerGroups) {
        $workerCount = [int]$group.worker_count
        if ($workerCount -lt 1) {
            continue
        }

        $queues = @($group.queues) -join ","
        $safeGroupName = ConvertTo-SafeName -Value "$($group.name)"

        for ($index = 1; $index -le $workerCount; $index++) {
            $workerName = "tse-local-$safeGroupName-{0:D2}" -f $index
            $stdout = Join-Path $WorkerLogDir "$workerName-$Timestamp.log"
            $stderr = Join-Path $WorkerLogDir "$workerName-$Timestamp.err.log"
            $args = @(
                "artisan",
                "queue:work",
                $QueueConnection,
                "--name=$workerName",
                "--queue=$queues",
                "--tries=$WorkerTries",
                "--timeout=$WorkerTimeoutSeconds",
                "--sleep=$WorkerSleepSeconds",
                "--memory=$WorkerMemoryMb",
                "--max-time=$WorkerMaxTimeSeconds"
            )

            if ($DryRun) {
                Write-Host "Dry run: would start worker: $script:PhpBinary $($args -join ' ')"
                continue
            }

            $process = Start-Process `
                -FilePath $script:PhpBinary `
                -ArgumentList $args `
                -WorkingDirectory $Backend `
                -WindowStyle Hidden `
                -RedirectStandardOutput $stdout `
                -RedirectStandardError $stderr `
                -PassThru

            $started.Add([pscustomobject]@{
                kind = "worker"
                name = $workerName
                group = "$($group.name)"
                queue = $queues
                pid = $process.Id
                stdout = $stdout
                stderr = $stderr
            }) | Out-Null
        }
    }

    return @($started)
}

function Test-StartedProcesses {
    param([object[]]$StartedProcesses)

    if ($DryRun -or $StartedProcesses.Count -eq 0) {
        return
    }

    Start-Sleep -Seconds 2

    foreach ($process in $StartedProcesses) {
        $running = Get-CimInstance Win32_Process -Filter "ProcessId = $($process.pid)" -ErrorAction SilentlyContinue

        if ($running -eq $null) {
            $stderr = if (Test-Path -LiteralPath $process.stderr) {
                Get-Content -LiteralPath $process.stderr -Raw
            } else {
                ""
            }

            throw "$($process.kind) '$($process.name)' exited immediately. stderr: $stderr"
        }
    }
}

$profileArgs = @{}
if ($Php) {
    $profileArgs.Php = $Php
}
if ($SkipDocker -or $DryRun) {
    $profileArgs.SkipDocker = $true
}
if ($SkipMigrate -or $DryRun) {
    $profileArgs.SkipMigrate = $true
}

& (Join-Path $ScriptDir "use-postgres-redis.ps1") @profileArgs

$script:PhpBinary = Resolve-PhpBinary
Write-Host "Using PHP: $script:PhpBinary"

$runtimeOutput = Invoke-ArtisanOutput -ArtisanArgs @("subtitles:runtime-check", "--json", "--strict", "--no-ansi")
$runtime = $runtimeOutput | ConvertFrom-Json

if (-not $runtime.ok) {
    throw "Subtitle runtime check failed: $($runtime.problems -join '; ')"
}

$queueConnection = "$($runtime.summary.subtitleQueueConnection)"
$workerGroups = Get-WorkerGroupsFromRuntime -Runtime $runtime
$queueNames = @($runtime.summary.subtitleGenerationQueues) + @($runtime.summary.subtitleBatchQueues)
$plannedWorkerCount = ($workerGroups | Measure-Object -Property worker_count -Sum).Sum

Write-Host "Worker mode: configured groups"
Write-Host "Queue connection: $queueConnection"
Write-Host "Planned workers: $plannedWorkerCount"

$existing = Get-ExistingRuntimeProcesses -QueueNames $queueNames
Stop-ExistingProcesses -Processes $existing -RestartQueueWorkersFirst:(!$SkipWorkers)

$startedProcesses = [System.Collections.Generic.List[object]]::new()
$backendProcess = Start-BackendServer
if ($backendProcess -ne $null) {
    $startedProcesses.Add($backendProcess) | Out-Null
}

foreach ($workerProcess in (Start-WorkerProcesses -WorkerGroups $workerGroups -QueueConnection $queueConnection)) {
    $startedProcesses.Add($workerProcess) | Out-Null
}

Test-StartedProcesses -StartedProcesses @($startedProcesses)

if (-not $DryRun) {
    New-Item -ItemType Directory -Force -Path $RuntimeLogDir | Out-Null
    @($startedProcesses) | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $PidPath
    Write-Host "Wrote PID summary: $PidPath"
}

Write-Host "Local backend URL: http://${HostName}:$Port"
Write-Host "Started processes: $($startedProcesses.Count)"
