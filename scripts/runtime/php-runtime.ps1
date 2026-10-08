function Resolve-PhpBinary {
    param([string]$Preferred, [string]$BackendPath)

    $candidates = @()
    if ($Preferred) { $candidates += $Preferred }
    $pathPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($pathPhp) { $candidates += $pathPhp.Source }
    if ($env:LOCALAPPDATA) {
        $candidates += Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
    }

    foreach ($candidate in ($candidates | Select-Object -Unique)) {
        if (-not (Test-Path -LiteralPath $candidate)) { continue }
        $binary = (Resolve-Path -LiteralPath $candidate).Path
        # Candidate probes may write stderr; a nonzero exit simply rejects that candidate.
        $previousPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        Push-Location $BackendPath
        try {
            & $binary -r "exit(extension_loaded('pdo_pgsql') ? 0 : 1);" *> $null
            if ($LASTEXITCODE -ne 0) { continue }
            & $binary artisan --version *> $null
            if ($LASTEXITCODE -eq 0) { return $binary }
        } finally {
            Pop-Location
            $ErrorActionPreference = $previousPreference
        }
    }

    throw 'No PHP binary with pdo_pgsql and Artisan support was found. Install PHP with pdo_pgsql enabled or pass its path with -Php.'
}
