[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$BackupPath,
    [string]$DatabaseUrl = $env:DATABASE_URL,
    [string]$HostName = $env:PGHOST,
    [string]$Port = $env:PGPORT,
    [string]$Database = $env:PGDATABASE,
    [string]$Username = $env:PGUSER,
    [switch]$ConfirmRestore
)

$ErrorActionPreference = "Stop"

if (-not $ConfirmRestore) {
    throw "Restore is destructive. Rerun with -ConfirmRestore after pointing this script at a disposable restore-test database."
}

if (-not (Test-Path -LiteralPath $BackupPath)) {
    throw "Backup file not found: $BackupPath"
}

$arguments = @("--clean", "--if-exists", "--no-owner", "--no-privileges")

if ($DatabaseUrl) {
    $arguments += @("--dbname", $DatabaseUrl)
} else {
    if (-not $Database) {
        throw "Provide -DatabaseUrl or set -Database / PGDATABASE."
    }

    if ($HostName) {
        $arguments += @("--host", $HostName)
    }

    if ($Port) {
        $arguments += @("--port", $Port)
    }

    if ($Username) {
        $arguments += @("--username", $Username)
    }

    $arguments += @("--dbname", $Database)
}

$arguments += $BackupPath

& pg_restore @arguments

if ($LASTEXITCODE -ne 0) {
    throw "pg_restore failed."
}

Write-Host "Postgres restore test completed from $BackupPath"
