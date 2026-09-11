#Requires -Version 5.1
<#
.SYNOPSIS
    Impacchetta OpenSagra in UN SOLO opensagra-installer.exe: doppio click ->
    un prompt UAC -> installazione (install.ps1) parte da sola.

.DESCRIPTION
    Per test rapidi dell'installer completo su un'altra macchina: un file solo
    da copiare/condividere, niente ZIP da scompattare a mano, niente "click
    destro > esegui con PowerShell" su install.ps1.

    NON e' (ancora) il pacchetto di release ufficiale - non firma nulla (vedi
    docs/PIANO-MODIFICHE-WRAPPER.md §6, firma SmartScreen decisa NO), e va
    rilanciato ad ogni release. E' lo strumento per "voglio provare tutto
    l'installer su un'altra macchina adesso".

    Meccanismo:
    1. wrapper\build.ps1 -> wrapper\opensagra-wrapper.exe fresco
    2. `git archive HEAD` -> snapshot pulito dei soli file tracciati (niente
       .git, node_modules, log di test, cruft della working tree)
    3. dentro quello zip si aggiungono wrapper\opensagra-wrapper.exe (compilato,
       gitignored - non e' tracciato) e vendor\ (Composer, gitignored - deve
       gia' esistere: `composer install` a parte, PRIMA di lanciare questo)
    4. ps2exe compila packaging\installer-bootstrap.ps1 in un unico exe, con
       quello zip incorporato come risorsa (-embedFiles) e -requireAdmin (UAC
       al lancio, non serve elevarsi di nuovo dentro install.ps1)

.PARAMETER Out
    Percorso dell'exe risultante. Default: opensagra-installer.exe nella root
    del repo (gitignored, e' un artefatto di build come wrapper\*.exe).

.EXAMPLE
    .\packaging\make-installer.ps1
#>
[CmdletBinding()]
param(
    [string]$Out
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Out) { $Out = Join-Path $RepoRoot 'opensagra-installer.exe' }

$Work = Join-Path $env:TEMP ('opensagra-pkg-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force -Path $Work | Out-Null

try {
    Write-Host '1/5  Compilo il wrapper...' -ForegroundColor Cyan
    & (Join-Path $RepoRoot 'wrapper\build.ps1') | Out-Null
    $wrapperExe = Join-Path $RepoRoot 'wrapper\opensagra-wrapper.exe'
    if (-not (Test-Path $wrapperExe)) { throw 'wrapper\opensagra-wrapper.exe non trovato dopo la build.' }

    Write-Host '2/5  Esporto i file tracciati da HEAD (git archive)...' -ForegroundColor Cyan
    $payloadZip = Join-Path $Work 'payload.zip'
    Push-Location $RepoRoot
    try {
        git archive --format=zip -o $payloadZip HEAD
        if ($LASTEXITCODE -ne 0) { throw 'git archive fallito.' }
    } finally {
        Pop-Location
    }
    $headSha = (git -C $RepoRoot rev-parse --short HEAD)
    Write-Host "     HEAD: $headSha (attenzione: modifiche non committate NON incluse)" -ForegroundColor DarkGray

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::Open($payloadZip, 'Update')
    try {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $wrapperExe, 'wrapper/opensagra-wrapper.exe') | Out-Null

        $vendorDir = Join-Path $RepoRoot 'vendor'
        if (Test-Path $vendorDir) {
            $vendorFiles = Get-ChildItem $vendorDir -Recurse -File
            Write-Host "3/5  Aggiungo vendor/ ($($vendorFiles.Count) file)..." -ForegroundColor Cyan
            foreach ($f in $vendorFiles) {
                $rel = 'vendor/' + $f.FullName.Substring($vendorDir.Length + 1).Replace('\', '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f.FullName, $rel) | Out-Null
            }
        } else {
            Write-Warning "vendor/ non trovato in $RepoRoot - esegui 'composer install' prima di impacchettare."
        }
    } finally {
        $zip.Dispose()
    }

    $sizeMB = [math]::Round((Get-Item $payloadZip).Length / 1MB, 1)
    Write-Host "     payload: $sizeMB MB" -ForegroundColor DarkGray

    Write-Host '4/5  Compilo con ps2exe (embedFiles + requireAdmin)...' -ForegroundColor Cyan
    if (-not (Get-Module -ListAvailable -Name ps2exe)) {
        throw "Modulo ps2exe non installato. Install-Module ps2exe -Scope CurrentUser -Force"
    }
    Import-Module ps2exe -Force

    $bootstrap = Join-Path $PSScriptRoot 'installer-bootstrap.ps1'
    $iconPath = Join-Path $RepoRoot 'wrapper\assets\opensagra.ico'

    $ps2exeArgs = @{
        inputFile    = $bootstrap
        outputFile   = $Out
        noConsole    = $true
        requireAdmin = $true   # UAC al doppio click - install.ps1 si trova gia' elevato
        title        = 'Installazione OpenSagra'
        product      = 'OpenSagra'
        description  = "Installer (da HEAD $headSha)"
        version      = '0.1.0.0'
        embedFiles   = @{ '%TEMP%\opensagra-installer-payload.zip' = $payloadZip }
    }
    if (Test-Path $iconPath) { $ps2exeArgs.iconFile = $iconPath }

    Invoke-ps2exe @ps2exeArgs
    if (-not (Test-Path $Out)) { throw 'ps2exe non ha prodotto l''exe.' }

    Write-Host '5/5  Fatto.' -ForegroundColor Cyan
}
finally {
    Remove-Item $Work -Recurse -Force -ErrorAction SilentlyContinue
}

$info = Get-Item $Out
Write-Host ''
Write-Host "OK: $Out" -ForegroundColor Green
Write-Host ('  {0:N1} MB - {1}' -f ($info.Length / 1MB), $info.LastWriteTime)
Write-Host '  Copialo sull''altra macchina e lancialo (un prompt UAC, poi parte install.ps1).'
