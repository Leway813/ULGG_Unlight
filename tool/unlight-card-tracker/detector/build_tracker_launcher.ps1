param(
    [string]$Python = ""
)

$ErrorActionPreference = "Stop"
$detectorRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$specPath = Join-Path $detectorRoot "UnlightTrackerLauncher.spec"
$workPath = Join-Path `
    ([System.IO.Path]::GetTempPath()) `
    ("unlight-tracker-build-" + [guid]::NewGuid().ToString("N"))
$distPath = Join-Path $detectorRoot "dist"
$outputPath = Join-Path $distPath "UnlightTrackerLauncher"

Push-Location $detectorRoot
try {
    if (Test-Path -LiteralPath $outputPath) {
        for ($attempt = 1; $attempt -le 5; $attempt++) {
            try {
                Remove-Item `
                    -LiteralPath $outputPath `
                    -Recurse `
                    -Force `
                    -ErrorAction Stop
                break
            }
            catch {
                if ($attempt -eq 5) {
                    throw
                }
                Start-Sleep -Milliseconds 500
            }
        }
    }

    if ($Python) {
        & $Python -m PyInstaller `
            --noconfirm `
            --clean `
            --workpath $workPath `
            --distpath $distPath `
            $specPath
    }
    else {
        & py -3.11 -m PyInstaller `
            --noconfirm `
            --clean `
            --workpath $workPath `
            --distpath $distPath `
            $specPath
    }
    if ($LASTEXITCODE -ne 0) {
        throw "PyInstaller build failed with exit code $LASTEXITCODE"
    }

    $exe = Join-Path $outputPath "UnlightTrackerLauncher.exe"
    if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) {
        throw "Launcher EXE was not created: $exe"
    }
    Write-Output "Built: $exe"
}
finally {
    Pop-Location
    if (Test-Path -LiteralPath $workPath) {
        Remove-Item -LiteralPath $workPath -Recurse -Force
    }
}
