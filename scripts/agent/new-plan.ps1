param(
    [Parameter(Mandatory = $true)]
    [string]$Title
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Template = Join-Path $Root "docs\exec-plans\templates\exec-plan-template.md"
$ActiveDir = Join-Path $Root "docs\exec-plans\active"

if (-not (Test-Path $Template)) {
    throw "Missing execution plan template: $Template"
}

New-Item -ItemType Directory -Force -Path $ActiveDir | Out-Null

$Slug = $Title.ToLowerInvariant()
$Slug = $Slug -replace "[^a-z0-9]+", "-"
$Slug = $Slug.Trim("-")
if ([string]::IsNullOrWhiteSpace($Slug)) {
    $Slug = "plan"
}

$Date = Get-Date -Format "yyyy-MM-dd"
$Destination = Join-Path $ActiveDir "$Date-$Slug.md"

if (Test-Path $Destination) {
    throw "Plan already exists: $Destination"
}

$Content = Get-Content -LiteralPath $Template -Raw
$Content = $Content -replace "# Plan: <title>", "# Plan: $Title"
$Content = $Content -replace "Created: YYYY-MM-DD", "Created: $Date"
$Content = $Content -replace "Last updated: YYYY-MM-DD", "Last updated: $Date"
$Content = $Content -replace "YYYY-MM-DD", $Date

Set-Content -LiteralPath $Destination -Value $Content -Encoding UTF8
Write-Host $Destination
