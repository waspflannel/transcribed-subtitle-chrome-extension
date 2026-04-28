$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")

$Required = @(
    "AGENTS.md",
    "ARCHITECTURE.md",
    "docs/DESIGN.md",
    "docs/USING_AGENT_HARNESS.md",
    "docs/FRONTEND.md",
    "docs/PLANS.md",
    "docs/PRODUCT_SENSE.md",
    "docs/QUALITY_SCORE.md",
    "docs/RELIABILITY.md",
    "docs/SECURITY.md",
    "docs/OBSERVABILITY.md",
    "docs/REVIEW.md",
    "docs/AUTONOMY.md",
    "docs/exec-plans/templates/exec-plan-template.md",
    "scripts/agent/check.ps1"
)

$Missing = @()
foreach ($Path in $Required) {
    if (-not (Test-Path (Join-Path $Root $Path))) {
        $Missing += $Path
    }
}

$State = [ordered]@{
    root = "$Root"
    gitRoot = Test-Path (Join-Path $Root ".git")
    appExists = Test-Path (Join-Path $Root "app")
    docsExists = Test-Path (Join-Path $Root "docs")
    missing = $Missing
    suggestedCommands = @(
        ".\scripts\agent\check.ps1",
        ".\scripts\agent\new-plan.ps1 -Title `"First implementation slice`"",
        ".\scripts\agent\doc-gardening.ps1"
    )
}

$State | ConvertTo-Json -Depth 4

if ($Missing.Count -gt 0) {
    exit 1
}
