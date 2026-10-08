[CmdletBinding()]
param(
    [string]$Php,
    [string]$HostName = "127.0.0.1",
    [ValidateRange(1, 65535)][int]$Port = 8001,
    [int]$WorkerTimeoutSeconds = 1320,
    [int]$WorkerMaxTimeSeconds = 0,
    [int]$WorkerMemoryMb = 256,
    [int]$WorkerSleepSeconds = 0,
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

. (Join-Path $ScriptDir 'php-runtime.ps1')

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
            connection = "$($_.connection)"
            queues = @($_.queues)
            worker_count = [int]$_.worker_count
            timeout_seconds = if ($_.timeout_seconds) { [int]$_.timeout_seconds } else { $WorkerTimeoutSeconds }
        }
    })
}

function Get-RecordedRuntimeProcesses {
    if (-not (Test-Path -LiteralPath $PidPath)) { return @() }
    try { return @(Get-Content -LiteralPath $PidPath -Raw | ConvertFrom-Json) }
    catch { return @() }
}

function Test-RecordedProcess {
    param($Process, $Record)
    try {
        return $Process -and $Record.startedAtUtc -and $Process.CreationDate -and
            $Process.CreationDate.ToUniversalTime() -eq ([datetime]$Record.startedAtUtc).ToUniversalTime() -and
            $Process.ExecutablePath -eq $Record.executablePath -and $Process.CommandLine -ceq $Record.commandLine
    } catch { return $false }
}

function Get-ExistingRuntimeProcesses {
    $allProcesses = @(Get-CimInstance Win32_Process)
    $records = @{}
    foreach ($record in (Get-RecordedRuntimeProcesses)) { $records[[int]$record.pid] = $record }
    $pending = [System.Collections.Generic.Queue[object]]::new()
    $seen = @{}
    # An absolute Artisan path identifies this checkout. A relative command needs a matching saved birth time.
    $artisan = [regex]::Escape((Join-Path $Backend 'artisan').Replace('\', '/'))
    $pattern = '(?:^|\s)"?' + $artisan + '"?\s+(serve|queue:work)(?:\s|$)'
    foreach ($process in $allProcesses) {
        if ([int]$process.ProcessId -eq $PID) { continue }
        $kind = $null
        $record = $records[[int]$process.ProcessId]
        if ((Test-RecordedProcess $process $record) -and $record.kind -in @('backend', 'worker')) {
            $kind = $record.kind
        } elseif ($process.ExecutablePath -eq $script:PhpBinary -and
            "$($process.CommandLine)".Replace('\', '/') -match $pattern) {
            $kind = if ($Matches[1] -eq 'serve') { 'backend' } else { 'worker' }
        }
        if ($kind) { $pending.Enqueue(@{ process = $process; kind = $kind; depth = 0 }) }
    }
    while ($pending.Count -gt 0) {
        $entry = $pending.Dequeue()
        $process = $entry.process
        $processId = [int]$process.ProcessId
        if ($seen.ContainsKey($processId) -or $processId -eq $PID) { continue }
        $seen[$processId] = $true
        [pscustomobject]@{
            ProcessId = $processId; Name = $process.Name; Kind = $entry.kind; Depth = $entry.depth
            CommandLine = $process.CommandLine; ExecutablePath = $process.ExecutablePath
            StartedAtUtc = $process.CreationDate.ToUniversalTime().ToString('o')
        }
        foreach ($child in $allProcesses) {
            # Parent PIDs can be reused too: an older child cannot belong to this parent.
            if ($child.ParentProcessId -eq $processId -and $child.CreationDate -ge $process.CreationDate) {
                $pending.Enqueue(@{ process = $child; kind = $entry.kind; depth = $entry.depth + 1 })
            }
        }
    }
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

    if ($RestartQueueWorkersFirst -and ($Processes | Where-Object { $_.Kind -eq "worker" }).Count -gt 0) {
        Write-Host "Signaling queue workers to restart..."
        Invoke-ArtisanOutput -ArtisanArgs @("queue:restart", "--no-ansi") | Out-Null

        if ($GracefulWorkerShutdownSeconds -gt 0) {
            Start-Sleep -Seconds $GracefulWorkerShutdownSeconds
        }
    }

    # Stop children before parents so the tree tears down cleanly.
    foreach ($process in ($Processes | Sort-Object Depth -Descending)) {
        if ([int]$process.ProcessId -eq [int]$PID) {
            continue
        }

        $current = Get-CimInstance Win32_Process -Filter "ProcessId = $($process.ProcessId)" -ErrorAction SilentlyContinue
        if (Test-RecordedProcess $current $process) {
            Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
        }
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
    $args = @(('"' + (Join-Path $Backend 'artisan') + '"'), "serve", "--host=$HostName", "--port=$Port")

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
    param([object[]]$WorkerGroups)

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
                ('"' + (Join-Path $Backend 'artisan') + '"'),
                "queue:work",
                $group.connection,
                "--name=$workerName",
                "--queue=$queues",
                "--tries=$WorkerTries",
                "--timeout=$($group.timeout_seconds)",
                "--sleep=$WorkerSleepSeconds",
                "--memory=$WorkerMemoryMb",
                "--max-time=$WorkerMaxTimeSeconds"
            )

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

    if ($StartedProcesses.Count -eq 0) {
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
        $process | Add-Member -NotePropertyName startedAtUtc -NotePropertyValue $running.CreationDate.ToUniversalTime().ToString('o')
        $process | Add-Member -NotePropertyName executablePath -NotePropertyValue $running.ExecutablePath
        $process | Add-Member -NotePropertyName commandLine -NotePropertyValue $running.CommandLine
    }
}

if ($DryRun) {
    Write-Host "Dry run: would select the local Postgres/Redis profile with APP_URL=http://${HostName}:$Port."
    Write-Host "Docker startup: $(!$SkipDocker); migrations: $(!$SkipMigrate); restart backend: $(!$SkipBackend); restart workers: $(!$SkipWorkers)."
    return
}

$profileArgs = @{ AppUrl = "http://${HostName}:$Port"; PreserveEnvironment = $SkipBackend }
if ($Php) {
    $profileArgs.Php = $Php
}
if ($SkipDocker) {
    $profileArgs.SkipDocker = $true
}
if ($SkipMigrate) {
    $profileArgs.SkipMigrate = $true
}

& (Join-Path $ScriptDir "use-postgres-redis.ps1") @profileArgs

$script:PhpBinary = Resolve-PhpBinary -Preferred $Php -BackendPath $Backend
Write-Host "Using PHP: $script:PhpBinary"

$runtimeOutput = Invoke-ArtisanOutput -ArtisanArgs @("subtitles:runtime-check", "--json", "--strict", "--no-ansi")
$runtime = $runtimeOutput | ConvertFrom-Json

if (-not $runtime.ok) {
    throw "Subtitle runtime check failed: $($runtime.problems -join '; ')"
}

$queueConnection = "$($runtime.summary.subtitleQueueConnection)"
$workerGroups = Get-WorkerGroupsFromRuntime -Runtime $runtime
$plannedWorkerCount = ($workerGroups | Measure-Object -Property worker_count -Sum).Sum

Write-Host "Worker mode: configured groups"
Write-Host "Queue connection: $queueConnection"
Write-Host "Planned workers: $plannedWorkerCount"

$existing = @(Get-ExistingRuntimeProcesses | Where-Object {
    ($_.Kind -eq 'backend' -and -not $SkipBackend) -or ($_.Kind -eq 'worker' -and -not $SkipWorkers)
})
$preservedRecords = @(Get-RecordedRuntimeProcesses | Where-Object {
    ($_.kind -eq 'backend' -and $SkipBackend) -or ($_.kind -eq 'worker' -and $SkipWorkers)
})
Stop-ExistingProcesses -Processes $existing -RestartQueueWorkersFirst:(!$SkipWorkers)

$startedProcesses = [System.Collections.Generic.List[object]]::new()
$backendProcess = Start-BackendServer
if ($backendProcess -ne $null) {
    $startedProcesses.Add($backendProcess) | Out-Null
}

foreach ($workerProcess in (Start-WorkerProcesses -WorkerGroups $workerGroups)) {
    $startedProcesses.Add($workerProcess) | Out-Null
}

Test-StartedProcesses -StartedProcesses @($startedProcesses)

New-Item -ItemType Directory -Force -Path $RuntimeLogDir | Out-Null
@($preservedRecords + @($startedProcesses)) | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $PidPath
Write-Host "Wrote PID summary: $PidPath"

Write-Host "Local backend URL: http://${HostName}:$Port"
Write-Host "Started processes: $($startedProcesses.Count)"
