#Requires -Version 5.1
<#
.SYNOPSIS
    Impacchetta il codice applicativo (PHP/HTML/CSS/JS + vendor/) in
    opensagra-update.zip - il pacchetto per l'aggiornamento leggero del
    wrapper (ApplyUpdate in wrapper/self_update.go), senza reinstallare.

.DESCRIPTION
    Costruisce esattamente l'albero che install.ps1 (Copy-AppFiles)
    installerebbe, piu' vendor/ - stessa lista di esclusione di
    $Script:ExcludeFromCopy in install.ps1, tenerle allineate a mano.

    NON include wrapper.exe, opensagra-uninstaller.exe, install.ps1,
    uninstall.ps1: un aggiornamento leggero non tocca mai quelle cose (solo
    il codice applicativo). Vedi .github/workflows/release.yml per QUANDO
    questo pacchetto viene effettivamente costruito e allegato a una
    release - solo se wrapper/, install.ps1, uninstall.ps1 e packaging/ non
    sono cambiati rispetto alla release precedente, altrimenti quella
    release richiede una reinstallazione completa.

    File come config/variabili.env, Caddyfile, uploads/* NON fanno parte di
    questo pacchetto perche' non sono tracciati da git (.gitignore) -
    l'estrazione in sovrascrittura (ApplyUpdate, mai un wipe-and-replace) li
    lascia quindi intatti senza bisogno di nessuna esclusione esplicita qui.

.PARAMETER Out
    Percorso dello zip risultante. Default: opensagra-update.zip nella root
    del repo (gitignored, artefatto di build).

.EXAMPLE
    .\packaging\make-update.ps1
#>
[CmdletBinding()]
param(
    [string]$Out
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Out) { $Out = Join-Path $RepoRoot 'opensagra-update.zip' }

# Stessi nomi di $Script:ExcludeFromCopy in install.ps1, piu' quelli che li'
# non servono escludere perche' Copy-AppFiles copia dalla sorgente del
# pacchetto di release (dove wrapper.exe/uninstaller.exe non sono tracciati
# da git comunque, quindi non compaiono in git ls-files) - qui partiamo
# invece direttamente da git ls-files, quindi vanno esclusi esplicitamente
# anche 'wrapper' (il sorgente Go), '.github' e 'README.md'.
$ExcludeTopLevel = @(
    '.git', '.vscode', 'e2e', 'docs', 'node_modules', 'install.ps1',
    '.gitignore', '.gitattributes', 'archive', 'playwright-report',
    'test-results', 'package.json', 'package-lock.json', 'playwright.config.js',
    'bt.html', 'navbar example.html', 'wrapper', 'private', 'packaging',
    'uninstall.ps1', '.github', 'README.md'
)

Write-Host '1/3  Elenco i file dell''app (working tree)...' -ForegroundColor Cyan
$rawFiles = git -C $RepoRoot ls-files -z --cached --others --exclude-standard
if ($LASTEXITCODE -ne 0) { throw 'git ls-files fallito.' }
$fileList = @($rawFiles -split "`0" | Where-Object { $_ -ne '' }) | Where-Object {
    $top = ($_ -split '/')[0]
    $ExcludeTopLevel -notcontains $top
}
Write-Host "     $($fileList.Count) file" -ForegroundColor DarkGray

Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path $Out) { Remove-Item $Out -Force }
$zip = [System.IO.Compression.ZipFile]::Open($Out, 'Create')
try {
    foreach ($rel in $fileList) {
        $full = Join-Path $RepoRoot ($rel -replace '/', '\')
        if (-not (Test-Path $full -PathType Leaf)) { continue }
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $full, $rel) | Out-Null
    }

    $vendorDir = Join-Path $RepoRoot 'vendor'
    if (Test-Path $vendorDir) {
        $vendorFiles = Get-ChildItem $vendorDir -Recurse -File
        Write-Host "2/3  Aggiungo vendor/ ($($vendorFiles.Count) file)..." -ForegroundColor Cyan
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

Write-Host '3/3  Fatto.' -ForegroundColor Cyan
$info = Get-Item $Out
Write-Host ''
Write-Host "OK: $Out" -ForegroundColor Green
Write-Host ('  {0:N1} MB - {1}' -f ($info.Length / 1MB), $info.LastWriteTime)
