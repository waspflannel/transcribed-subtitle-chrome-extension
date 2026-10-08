[CmdletBinding()]
param(
    [string]$Php,
    [string]$AppUrl = "http://127.0.0.1:8001",
    [switch]$PreserveEnvironment,
    [switch]$SkipDocker,
    [switch]$SkipMigrate
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Backend = Join-Path $Root "app\backend"
$EnvPath = Join-Path $Backend ".env"
$ExampleEnvPath = Join-Path $Backend ".env.example"

. (Join-Path $ScriptDir 'php-runtime.ps1')

function Invoke-RuntimePhp {
    param([string[]]$CommandArgs)
    & $phpBinary @CommandArgs
    if ($LASTEXITCODE -ne 0) { throw "PHP command failed: $($CommandArgs -join ' ')" }
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
    if ($content -contains $line) { return }
    if ($PreserveEnvironment) {
        throw "The local profile needs to change $Key. Restart both backend and workers without -SkipBackend to apply it."
    }
    $updated = $false
    $next = @(foreach ($existing in $content) {
        if ($existing -match $pattern) {
            $updated = $true
            $line
        } else {
            $existing
        }
    })

    if (-not $updated) {
        $next += $line
    }

    Set-Content -LiteralPath $Path -Value $next
}

$phpBinary = Resolve-PhpBinary -Preferred $Php -BackendPath $Backend
Write-Host "Using PHP: $phpBinary"

if (-not (Test-Path $EnvPath)) {
    if ($PreserveEnvironment) { throw 'The local profile is missing. Start without -SkipBackend to create it.' }
    Copy-Item -LiteralPath $ExampleEnvPath -Destination $EnvPath
}

$runtimeEnv = @{
    APP_URL = $AppUrl
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
    SUBTITLE_GENERATION_QUEUE = "subtitle-generation"
    SUBTITLE_BATCH_QUEUE = "subtitle-batch"
    SUBTITLE_CONCURRENCY_CACHE_STORE = "subtitle_concurrency"
    SUBTITLE_CONCURRENCY_REDIS_CONNECTION = "cache"
    SUBTITLE_CONCURRENCY_REDIS_LOCK_CONNECTION = "cache"
    REDIS_CLIENT = "predis"
    REDIS_HOST = "127.0.0.1"
    REDIS_PORT = "56379"
    REDIS_DB = "0"
    REDIS_CACHE_DB = "1"
    REDIS_QUEUE_CONNECTION = "queue"
    REDIS_QUEUE_HOST = "127.0.0.1"
    REDIS_QUEUE_PORT = "56379"
    REDIS_QUEUE_DB = "2"
    REDIS_QUEUE = "subtitle-generation"
}

foreach ($entry in $runtimeEnv.GetEnumerator()) {
    Set-EnvValue -Path $EnvPath -Key $entry.Key -Value $entry.Value
}

if (-not $SkipDocker) {
    Push-Location $Root
    try {
        docker compose up --wait postgres redis

        if ($LASTEXITCODE -ne 0) {
            throw "docker compose up failed with code $LASTEXITCODE."
        }
    } catch {
        throw "Docker services could not be started. Start Docker Desktop, then rerun this script."
    } finally {
        Pop-Location
    }
}

Push-Location $Backend
try {
    Invoke-RuntimePhp @("artisan", "config:clear")
    Invoke-RuntimePhp @("artisan", "subtitles:runtime-check", "--json") | Out-Null

    if (-not $SkipMigrate) {
        Invoke-RuntimePhp @("artisan", "migrate", "--force")
    }
} finally {
    Pop-Location
}

Write-Host "Postgres + Redis runtime profile is selected in app/backend/.env."
