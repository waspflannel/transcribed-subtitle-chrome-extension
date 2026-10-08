$ErrorActionPreference = 'Stop'
$source = $PSScriptRoot
$fixture = Join-Path ([IO.Path]::GetTempPath()) ('transcribe-runtime-test-' + [guid]::NewGuid().ToString('N'))
$runtime = Join-Path $fixture 'scripts/runtime'
$backend = Join-Path $fixture 'app/backend'
$extension = Join-Path $fixture 'app/extension'
$previousApiUrl = $env:WXT_BACKEND_API_BASE_URL
function Assert-Runtime([bool]$Condition, [string]$Message) { if (-not $Condition) { throw $Message } }

try {
    New-Item -ItemType Directory -Force -Path $runtime, $extension, (Join-Path $backend 'bootstrap/cache'), (Join-Path $backend 'storage/logs/local-runtime') | Out-Null
    foreach ($file in @('start-local-backend-workers.ps1', 'start-local-dev.ps1', 'use-postgres-redis.ps1', 'php-runtime.ps1')) {
        Copy-Item -LiteralPath (Join-Path $source $file) -Destination $runtime
    }
    $envPath = Join-Path $backend '.env'
    $cachePath = Join-Path $backend 'bootstrap/cache/config.php'
    $recordsPath = Join-Path $backend 'storage/logs/local-runtime/local-runtime-pids.json'
    'APP_URL=http://keep-this.example' | Set-Content -LiteralPath $envPath
    'unchanged cached configuration' | Set-Content -LiteralPath $cachePath
    $fakePhp = Join-Path $fixture 'php.ps1'
    @'
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$CommandArgs)
$global:LASTEXITCODE = 0
if ($CommandArgs -contains 'subtitles:runtime-check') {
    '{"ok":true,"summary":{"subtitleQueueConnection":"redis","subtitleWorkerGroups":[{"name":"generation","connection":"redis","queues":["subtitle-generation"],"worker_count":1,"timeout_seconds":1320}]}}'
}
'@ | Set-Content -LiteralPath $fakePhp
    $fakeNpm = Join-Path $fixture 'npm.ps1'
    '$global:CapturedApiUrl = $env:WXT_BACKEND_API_BASE_URL; $global:LASTEXITCODE = 0' | Set-Content -LiteralPath $fakeNpm

    # Only process/provider edges are faked; every test runs the actual launcher scripts.
    function Get-Command {
        param([string]$Name, $ErrorAction)
        if ($Name -in @('npm', 'npm.cmd')) { return [pscustomobject]@{ Source = $fakeNpm } }
        if ($Name -eq 'php') { return [pscustomobject]@{ Source = $fakePhp } }
        throw "Unexpected command discovery: $Name"
    }
    function Get-CimInstance {
        param([string]$ClassName, [string]$Filter, $ErrorAction)
        $global:CimCalls++
        if ($Filter -match 'ProcessId = (\d+)') {
            $id = [int]$Matches[1]
            if ($id -eq $global:ReusedOnStop) {
                $global:FakeProcesses[$id].CreationDate = $global:FakeProcesses[$id].CreationDate.AddSeconds(1)
                $global:ReusedOnStop = 0
            }
            return $global:FakeProcesses[$id]
        }
        return @($global:FakeProcesses.Values)
    }
    function Start-Sleep { param($Seconds, $Milliseconds) }
    function Stop-Process {
        param([int]$Id, [switch]$Force, $ErrorAction)
        $global:StoppedIds += $Id
        $global:FakeProcesses.Remove($Id)
    }
    function Start-Process {
        param($FilePath, $ArgumentList, $WorkingDirectory, $WindowStyle, $RedirectStandardOutput, $RedirectStandardError, [switch]$PassThru)
        $global:NextProcessId++
        $id = $global:NextProcessId
        $global:StartedIds += $id
        $global:FakeProcesses[$id] = [pscustomobject]@{
            ProcessId = $id; ParentProcessId = 0; CreationDate = [datetime]'2026-10-08T12:01:00Z'
            Name = 'php.exe'; ExecutablePath = $FilePath; CommandLine = 'php ' + ($ArgumentList -join ' ')
        }
        return [pscustomobject]@{ Id = $id }
    }
    function Reset-Fixture {
        $global:CimCalls = 0
        $global:StoppedIds = @()
        $global:StartedIds = @()
        $global:ReusedOnStop = 0
        $global:NextProcessId = 1000
        $global:FakeProcesses = @{}
        $birth = [datetime]'2026-10-08T12:00:00Z'
        foreach ($row in @(
            @(10, 0, ('php "' + (Join-Path $backend 'artisan') + '" serve --port=8001')),
            @(9, 10, 'php server.php'),
            @(20, 0, 'php artisan queue:work redis --queue=subtitle-generation'),
            @(19, 20, 'ffmpeg audio.wav'),
            @(30, 0, 'notepad unsaved-work.txt'),
            @(40, 0, 'php "C:\another-project\artisan" queue:work redis --queue=subtitle-generation'),
            @(50, 0, 'php artisan queue:work redis --queue=subtitle-generation')
        )) {
            $global:FakeProcesses[$row[0]] = [pscustomobject]@{
                ProcessId = $row[0]; ParentProcessId = $row[1]; CreationDate = $birth
                Name = 'php.exe'; ExecutablePath = $fakePhp; CommandLine = $row[2]
            }
        }
        @(
            [pscustomobject]@{ pid = 10; kind = 'backend'; startedAtUtc = $birth.ToUniversalTime().ToString('o'); executablePath = $fakePhp; commandLine = $global:FakeProcesses[10].CommandLine },
            [pscustomobject]@{ pid = 20; kind = 'worker'; startedAtUtc = $birth.ToUniversalTime().ToString('o'); executablePath = $fakePhp; commandLine = $global:FakeProcesses[20].CommandLine },
            [pscustomobject]@{ pid = 30; kind = 'worker'; startedAtUtc = $birth.AddDays(-1).ToUniversalTime().ToString('o'); executablePath = $fakePhp; commandLine = $global:FakeProcesses[30].CommandLine },
            [pscustomobject]@{ pid = 50; kind = 'worker' }
        ) | ConvertTo-Json | Set-Content -LiteralPath $recordsPath
    }

    Reset-Fixture
    $envHash = (Get-FileHash -LiteralPath $envPath).Hash
    $cacheHash = (Get-FileHash -LiteralPath $cachePath).Hash
    & (Join-Path $runtime 'start-local-backend-workers.ps1') -DryRun -Port 8123
    Assert-Runtime ((Get-FileHash -LiteralPath $envPath).Hash -eq $envHash -and (Get-FileHash -LiteralPath $cachePath).Hash -eq $cacheHash) 'Dry run changed configuration.'
    Assert-Runtime ($global:CimCalls -eq 0 -and $global:StartedIds.Count -eq 0) 'Dry run touched process state.'

    & (Join-Path $runtime 'start-local-backend-workers.ps1') -Php $fakePhp -SkipDocker -SkipMigrate -SkipWorkers
    Assert-Runtime (($global:StoppedIds -join ',') -eq '9,10') 'Backend restart must stop only its own tree, children first.'
    $saved = @(Get-Content -LiteralPath $recordsPath -Raw | ConvertFrom-Json)
    Assert-Runtime (@($saved | Where-Object { $_.pid -eq 20 }).Count -eq 1) 'Skipped worker identity was lost.'
    Assert-Runtime (@($saved | Where-Object { $_.pid -eq 1001 -and $_.startedAtUtc -and $_.commandLine }).Count -eq 1) 'New process identity was not recorded.'

    Reset-Fixture
    $envWriteTime = (Get-Item -LiteralPath $envPath).LastWriteTimeUtc
    & (Join-Path $runtime 'start-local-backend-workers.ps1') -Php $fakePhp -SkipDocker -SkipMigrate -SkipBackend
    Assert-Runtime (($global:StoppedIds -join ',') -eq '19,20') 'Worker restart killed a backend, reused PID, legacy PID or unrelated queue.'
    Assert-Runtime ((Get-Item -LiteralPath $envPath).LastWriteTimeUtc -eq $envWriteTime) 'Worker-only restart rewrote .env, triggering Laravel serve reload.'

    Reset-Fixture
    $rejected = $false
    try {
        & (Join-Path $runtime 'start-local-backend-workers.ps1') -Php $fakePhp -SkipDocker -SkipMigrate -SkipBackend -Port 8123
    } catch {
        if ($_.Exception.Message -notlike '*without -SkipBackend*') { throw }
        $rejected = $true
    }
    Assert-Runtime ($rejected -and $global:StoppedIds.Count -eq 0 -and $global:StartedIds.Count -eq 0) 'Worker-only launch changed the backend port.'
    Assert-Runtime ((Get-Item -LiteralPath $envPath).LastWriteTimeUtc -eq $envWriteTime) 'Rejected profile change rewrote .env.'

    Reset-Fixture
    $global:ReusedOnStop = 10
    & (Join-Path $runtime 'start-local-backend-workers.ps1') -Php $fakePhp -SkipDocker -SkipMigrate -SkipWorkers
    Assert-Runtime ($global:StoppedIds -notcontains 10) 'A PID reused after discovery was killed.'

    Reset-Fixture
    & (Join-Path $runtime 'start-local-backend-workers.ps1') -Php $fakePhp -SkipDocker -SkipMigrate -SkipBackend -SkipWorkers
    Assert-Runtime ($global:StoppedIds.Count -eq 0 -and $global:StartedIds.Count -eq 0) 'Skipping both services changed process state.'

    Reset-Fixture
    $env:WXT_BACKEND_API_BASE_URL = 'https://keep-this.example/v1'
    & (Join-Path $runtime 'start-local-dev.ps1') -Php $fakePhp -SkipDocker -SkipMigrate -Port 8123
    Assert-Runtime ($global:CapturedApiUrl -eq 'http://127.0.0.1:8123/v1') 'Development port did not reach the extension.'
    Assert-Runtime ((Get-Content -LiteralPath $envPath) -contains 'APP_URL=http://127.0.0.1:8123') 'Development port did not reach the backend configuration.'
    Assert-Runtime ($env:WXT_BACKEND_API_BASE_URL -eq 'https://keep-this.example/v1') 'Development launcher did not restore its environment.'
    Write-Host 'Runtime ownership, skip flags, dry-run, port and PHP resolver checks passed.'
} finally {
    $env:WXT_BACKEND_API_BASE_URL = $previousApiUrl
    $resolvedFixture = [IO.Path]::GetFullPath($fixture)
    $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if ($resolvedFixture.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path $resolvedFixture -Leaf) -like 'transcribe-runtime-test-*') {
        Remove-Item -LiteralPath $resolvedFixture -Recurse -Force
    }
}
