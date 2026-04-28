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
    "docs/design-docs/index.md",
    "docs/design-docs/core-beliefs.md",
    "docs/exec-plans/tech-debt-tracker.md",
    "docs/exec-plans/templates/exec-plan-template.md",
    "docs/product-specs/index.md",
    "docs/references/index.md",
    "docs/quality/golden-principles.md"
)

$Errors = New-Object System.Collections.Generic.List[string]
$Warnings = New-Object System.Collections.Generic.List[string]

foreach ($Path in $Required) {
    if (-not (Test-Path (Join-Path $Root $Path))) {
        $Errors.Add("Missing required documentation file: $Path")
    }
}

$AgentsPath = Join-Path $Root "AGENTS.md"
if (Test-Path $AgentsPath) {
    $LineCount = (Get-Content -LiteralPath $AgentsPath).Count
    if ($LineCount -gt 150) {
        $Errors.Add("AGENTS.md has $LineCount lines. Keep it under 150 lines and move detail into docs/.")
    }
}

$MarkdownFiles = Get-ChildItem -LiteralPath (Join-Path $Root "docs") -Recurse -Filter *.md -ErrorAction SilentlyContinue
foreach ($File in $MarkdownFiles) {
    $Text = Get-Content -LiteralPath $File.FullName -Raw
    if ($Text -match "TODO|TBD") {
        $Warnings.Add("Open placeholder remains in $($File.FullName)")
    }
}

foreach ($Warning in $Warnings) {
    Write-Warning $Warning
}

if ($Errors.Count -gt 0) {
    foreach ($ErrorItem in $Errors) {
        Write-Error $ErrorItem
    }
    exit 1
}

Write-Host "Documentation harness lint passed."
