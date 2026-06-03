[CmdletBinding()]
param(
    [string]$DatabaseUrl = $env:DATABASE_URL,
    [string]$HostName = $env:PGHOST,
    [string]$Port = $env:PGPORT,
    [string]$Database = $env:PGDATABASE,
    [string]$Username = $env:PGUSER,
    [string]$OutputDirectory = "backups"
)

$ErrorActionPreference = "Stop"
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$outputDirectoryItem = New-Item -ItemType Directory -Force -Path $OutputDirectory
$resolvedOutputDirectory = (Resolve-Path -LiteralPath $outputDirectoryItem.FullName).Path
$backupPath = Join-Path $resolvedOutputDirectory "transcribed-subtitle-extension-$timestamp.dump"
$arguments = @("--format=custom", "--no-owner", "--no-privileges", "--file", $backupPath)

if ($DatabaseUrl) {
    $arguments += $DatabaseUrl
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

    $arguments += $Database
}

& pg_dump @arguments

if ($LASTEXITCODE -ne 0) {
    throw "pg_dump failed."
}

Write-Host "Postgres backup written to $backupPath"
