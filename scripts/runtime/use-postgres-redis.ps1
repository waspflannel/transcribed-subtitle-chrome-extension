[CmdletBinding()]
param(
    [string]$Php,
    [switch]$SkipDocker,
    [switch]$SkipMigrate
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Backend = Join-Path $Root "app\backend"
$EnvPath = Join-Path $Backend ".env"
$ExampleEnvPath = Join-Path $Backend ".env.example"

function Invoke-CandidatePhp {
    param(
        [string]$Candidate,
        [string[]]$PhpArgs,
        [string[]]$CommandArgs
    )

    $previousErrorActionPreference = $global:ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        & $Candidate @PhpArgs @CommandArgs 2>$null
    } finally {
        $global:ErrorActionPreference = $previousErrorActionPreference
    }
}

function Test-PhpInvocationHasPdoPgsql {
    param(
        [string]$Candidate,
        [string[]]$PhpArgs
    )

    if (-not $Candidate -or -not (Test-Path $Candidate)) {
        return $false
    }

    Invoke-CandidatePhp -Candidate $Candidate -PhpArgs $PhpArgs -CommandArgs @("-r", "exit(extension_loaded('pdo_pgsql') ? 0 : 1);") | Out-Null
    return $LASTEXITCODE -eq 0
}

function Test-PhpInvocationCanRunArtisan {
    param(
        [string]$Candidate,
        [string[]]$PhpArgs
    )

    Push-Location $Backend
    try {
        Invoke-CandidatePhp -Candidate $Candidate -PhpArgs $PhpArgs -CommandArgs @("artisan", "--version") | Out-Null
        return $LASTEXITCODE -eq 0
    } finally {
        Pop-Location
    }
}

function New-PhpRuntime {
    param(
        [string]$Binary,
        [string[]]$RuntimeArgs
    )

    return [pscustomobject]@{
        Binary = (Resolve-Path $Binary).Path
        RuntimeArgs = $RuntimeArgs
    }
}

function Resolve-PhpBinary {
    $candidates = @()

    if ($Php) {
        $candidates += (New-PhpRuntime -Binary $Php -RuntimeArgs @())
    }

    $pathPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($pathPhp) {
        $candidates += (New-PhpRuntime -Binary $pathPhp.Source -RuntimeArgs @())
    }

    $wingetPhp = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
    if (Test-Path $wingetPhp) {
        $candidates += (New-PhpRuntime -Binary $wingetPhp -RuntimeArgs @())
    }

    foreach ($candidate in $candidates) {
        Write-Verbose "Checking PHP candidate: $($candidate.Binary) $($candidate.RuntimeArgs -join ' ')"
        $hasPdoPgsql = Test-PhpInvocationHasPdoPgsql -Candidate $candidate.Binary -PhpArgs $candidate.RuntimeArgs
        $canRunArtisan = $false

        if ($hasPdoPgsql) {
            $canRunArtisan = Test-PhpInvocationCanRunArtisan -Candidate $candidate.Binary -PhpArgs $candidate.RuntimeArgs
        }

        Write-Verbose "Candidate result: pdo_pgsql=$hasPdoPgsql artisan=$canRunArtisan"

        if (
            $hasPdoPgsql -and
            $canRunArtisan
        ) {
            return $candidate
        }
    }

    throw "No PHP binary with pdo_pgsql was found. Install PHP 8.4 (`winget install --id PHP.PHP.8.4`) and enable pdo_pgsql in php.ini, or pass -Php C:\path\to\php.exe."
}

function Invoke-RuntimePhp {
    param([string[]]$CommandArgs)

    & $phpRuntime.Binary @($phpRuntime.RuntimeArgs) @CommandArgs
    if ($LASTEXITCODE -ne 0) {
        throw "PHP command failed: $($CommandArgs -join ' ')"
    }
}

function Set-EnvValue {
    param(
        [string]$Path,
        [string]$Key,
        [string]$Value
    )

    $line = "$Key=$Value"
    $content = if (Test-Path $Path) { Get-Content -LiteralPath $Path } else { @() }
    $pattern = "^$([regex]::Escape($Key))="
    $updated = $false
    $next = foreach ($existing in $content) {
        if ($existing -match $pattern) {
            $updated = $true
            $line
        } else {
            $existing
        }
    }

    if (-not $updated) {
        $next += $line
    }

    Set-Content -LiteralPath $Path -Value $next
}

$phpRuntime = Resolve-PhpBinary
$phpPrefix = @($phpRuntime.Binary) + @($phpRuntime.RuntimeArgs)
Write-Host "Using PHP: $($phpPrefix -join ' ')"

if (-not (Test-Path $EnvPath)) {
    Copy-Item -LiteralPath $ExampleEnvPath -Destination $EnvPath
}

$runtimeEnv = @{
    DB_CONNECTION = "pgsql"
    DB_HOST = "127.0.0.1"
    DB_PORT = "55432"
    DB_DATABASE = "transcribed_subtitle_extension"
    DB_USERNAME = "subtitle"
    DB_PASSWORD = "subtitle"
    DB_SCHEMA = "public"
    DB_SSLMODE = "prefer"
    CACHE_STORE = "database"
    QUEUE_CONNECTION = "redis"
    SUBTITLE_QUEUE_CONNECTION = "redis"
    SUBTITLE_QUEUE = "subtitle-ai"
    REDIS_CLIENT = "predis"
    REDIS_HOST = "127.0.0.1"
    REDIS_PORT = "56379"
    REDIS_DB = "0"
    REDIS_CACHE_DB = "1"
    REDIS_QUEUE_CONNECTION = "queue"
    REDIS_QUEUE_HOST = "127.0.0.1"
    REDIS_QUEUE_PORT = "56379"
    REDIS_QUEUE_DB = "2"
    REDIS_QUEUE = "subtitle-ai"
}

foreach ($entry in $runtimeEnv.GetEnumerator()) {
    Set-EnvValue -Path $EnvPath -Key $entry.Key -Value $entry.Value
}

if (-not $SkipDocker) {
    Push-Location $Root
    try {
        docker compose up -d postgres redis
    } catch {
        throw "Docker services could not be started. Start Docker Desktop, then rerun this script."
    } finally {
        Pop-Location
    }
}

Push-Location $Backend
try {
    Invoke-RuntimePhp @("artisan", "config:clear")
    Invoke-RuntimePhp @("artisan", "subtitles:runtime-check")

    if (-not $SkipMigrate) {
        Invoke-RuntimePhp @("artisan", "migrate", "--force")
    }
} finally {
    Pop-Location
}

Write-Host "Postgres + Redis runtime profile is selected in app/backend/.env."
