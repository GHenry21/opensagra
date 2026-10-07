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
    sono GENERATI qui ad ogni build via goversioninfo (pure Go, `go run` -
    nessun compilatore C, quindi nessuna dipendenza da windres/MinGW nemmeno
    per questo: coerente col resto). Non sono piu' committati: prima erano un
    file rsrc.rc statico con la versione scritta a mano (facile dimenticare di
    aggiornarla ad ogni release, ed e' successo - vedi Get-ReleaseVersion.ps1).

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
$RepoRoot = Split-Path -Parent $WrapperDir
$OutExe = Join-Path $WrapperDir 'opensagra-wrapper.exe'
$SysoPath = Join-Path $WrapperDir 'rsrc_windows_amd64.syso'
$VersionInfoJson = Join-Path $WrapperDir 'versioninfo.json'
# Pinnata per riproducibilita' (stesso spirito di Import-Module ps2exe -Force
# nei packaging script: qui non serve -Force perche' `go run pkg@versione`
# scarica/compila/esegue quella build esatta ogni volta, senza installazione
# persistente da tenere aggiornata).
$GoVersionInfoPkg = 'github.com/josephspurrier/goversioninfo/cmd/goversioninfo@v1.7.0'

. (Join-Path $RepoRoot 'packaging\Get-ReleaseVersion.ps1')

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

    $releaseVersion = Get-ReleaseVersion -RepoRoot $RepoRoot
    $verParts = ConvertTo-VersionParts -Version $releaseVersion
    $verString = "$($verParts[0]).$($verParts[1]).$($verParts[2]).0"
    Write-Host "`nGenero risorse (icona/manifest/versione $releaseVersion) con goversioninfo..." -ForegroundColor Cyan

    $versionInfo = [ordered]@{
        FixedFileInfo = [ordered]@{
            FileVersion    = [ordered]@{ Major = $verParts[0]; Minor = $verParts[1]; Patch = $verParts[2]; Build = 0 }
            ProductVersion = [ordered]@{ Major = $verParts[0]; Minor = $verParts[1]; Patch = $verParts[2]; Build = 0 }
        }
        StringFileInfo = [ordered]@{
            FileVersion      = $verString
            ProductVersion   = $verString
            CompanyName      = 'OpenSagra'
            FileDescription  = 'OpenSagra - pannello di controllo'
            InternalName     = 'opensagra-wrapper'
            OriginalFilename = 'opensagra-wrapper.exe'
            ProductName      = 'OpenSagra'
        }
        IconPath     = 'assets/opensagra.ico'
        ManifestPath = 'opensagra.manifest'
    }
    $versionInfo | ConvertTo-Json -Depth 5 | Set-Content -Path $VersionInfoJson -Encoding ascii

    & $goExe run $GoVersionInfoPkg -o $SysoPath -64
    if ($LASTEXITCODE -ne 0) { throw 'goversioninfo fallito (rsrc_windows_amd64.syso non generato).' }

    # -X inietta la versione reale in main.WrapperVersion senza doverla
    # scrivere a mano nel codice (stessa logica di config/.installed_version
    # per install.ps1 - vedi Get-ReleaseVersion.ps1).
    $ldflags = "-X main.WrapperVersion=$releaseVersion"
    if (-not $Console) { $ldflags = "-H=windowsgui $ldflags" }
    Write-Host "`ngo build$(if ($Console) { ' (console, debug)' } else { ' (-H=windowsgui, produzione)' })..." -ForegroundColor Cyan
    $buildArgs = @('build', '-ldflags', $ldflags, '-o', $OutExe, '.')
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
