$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Resolve-Path (Join-Path $ScriptDir "..\..")
$Docs = Join-Path $Root "docs"

if (-not (Test-Path $Docs)) {
    throw "Missing docs directory: $Docs"
}

$Findings = New-Object System.Collections.Generic.List[object]

Get-ChildItem -LiteralPath $Docs -Recurse -Filter *.md | ForEach-Object {
    $Text = Get-Content -LiteralPath $_.FullName -Raw
    if ($Text -match "TODO|TBD|Unscored|No .* yet") {
        $Findings.Add([ordered]@{
            file = $_.FullName
            signal = "placeholder-or-unscored-content"
        })
    }
}

$Findings | ConvertTo-Json -Depth 3

if ($Findings.Count -eq 0) {
    Write-Host "No doc-gardening findings."
} else {
    Write-Host "Review findings above and open targeted cleanup plans for stale or placeholder docs."
}
