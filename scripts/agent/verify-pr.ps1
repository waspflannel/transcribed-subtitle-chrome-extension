param(
    [switch]$SkipAppChecks
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")

Write-Host "Verifying PR readiness for $Root"

& (Join-Path $ScriptDir "check.ps1") -SkipAppChecks:$SkipAppChecks

Write-Host ""
Write-Host "PR evidence checklist:"
Write-Host "- Summary of what changed"
Write-Host "- Validation commands and results"
Write-Host "- Screenshots/videos/logs/traces when relevant"
Write-Host "- Known limitations"
Write-Host "- Follow-up debt added to docs/exec-plans/tech-debt-tracker.md"
