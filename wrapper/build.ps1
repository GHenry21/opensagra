#Requires -Version 5.1
<#
.SYNOPSIS
    Compila wrapper\opensagra-wrapper.exe per la release.

.DESCRIPTION
    Da lanciare sulla macchina di sviluppo PRIMA di impacchettare una release:
    install.ps1 gira sulla macchina di destinazione (che puo' non avere Go) e
    si aspetta l'exe gia' pronto in wrapper\opensagra-wrapper.exe.

    Puro Go, CGO_ENABLED=0: nessun compilatore C necessario (systray, registry,
    driver mysql sono tutti puro Go - niente webview/cgo, la finestra di stato
    e' HTTP + browser). Manifest/icona/info versione (rsrc_windows_amd64.syso)
    sono gia' committati: `go build` li include da solo.

    Stessi passi della Action in .github/workflows/build-wrapper.yml (quella
    gira solo su GitHub, questo e' l'equivalente locale).

.PARAMETER Tidy
    Esegue anche `go mod tidy` prima della build (aggiorna go.mod/go.sum se
    servisse). Normalmente non serve: e' gia' tutto committato.

.PARAMETER Console
    Build senza `-H=windowsgui`: tiene la console per vedere stdout/stderr in
    diretta durante lo sviluppo, invece dell'exe "silenzioso" di produzione.

.EXAMPLE
    .\wrapper\build.ps1
.EXAMPLE
    .\wrapper\build.ps1 -Console   # per debug, con output a video
#>
[CmdletBinding()]
param(
    [switch]$Tidy,
    [switch]$Console
)

$ErrorActionPreference = 'Stop'
$WrapperDir = $PSScriptRoot
$OutExe = Join-Path $WrapperDir 'opensagra-wrapper.exe'

function Resolve-GoExe {
    $cmd = Get-Command go.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $fallback = "$env:ProgramFiles\Go\bin\go.exe"
    if (Test-Path $fallback) { return $fallback }
    throw "Go non trovato sul PATH. Installa con: winget install GoLang.Go"
}

$goExe = Resolve-GoExe
$goVersion = (& $goExe version)
Write-Host "Go: $goExe" -ForegroundColor Cyan
Write-Host "    $goVersion" -ForegroundColor DarkGray

Push-Location $WrapperDir
try {
    $env:CGO_ENABLED = '0'

    if ($Tidy) {
        Write-Host "`ngo mod tidy..." -ForegroundColor Cyan
        & $goExe mod tidy
        if ($LASTEXITCODE -ne 0) { throw 'go mod tidy fallito.' }
    }

    Write-Host "`ngo vet..." -ForegroundColor Cyan
    & $goExe vet ./...
    if ($LASTEXITCODE -ne 0) { throw 'go vet ha trovato problemi - build interrotta.' }

    $ldflags = if ($Console) { '' } else { '-H=windowsgui' }
    Write-Host "`ngo build$(if ($Console) { ' (console, debug)' } else { ' (-H=windowsgui, produzione)' })..." -ForegroundColor Cyan
    $buildArgs = @('build')
    if ($ldflags) { $buildArgs += @('-ldflags', $ldflags) }
    $buildArgs += @('-o', $OutExe, './...')
    & $goExe @buildArgs
    if ($LASTEXITCODE -ne 0) { throw 'go build fallito.' }
}
finally {
    Pop-Location
}

$info = Get-Item $OutExe
$ver = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($OutExe)
Write-Host "`nOK: $OutExe" -ForegroundColor Green
Write-Host ("  {0:N0} byte - {1}" -f $info.Length, $info.LastWriteTime)
Write-Host ("  {0} v{1} - {2}" -f $ver.ProductName, $ver.FileVersion, $ver.FileDescription)
