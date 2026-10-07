#Requires -Version 5.1
<#
.SYNOPSIS
    Impacchetta il codice applicativo (PHP/HTML/CSS/JS + vendor/) in
    opensagra-update.zip - il pacchetto per l'aggiornamento leggero del
    wrapper (ApplyUpdate in wrapper/self_update.go), senza reinstallare.

.DESCRIPTION
    I file sono quelli del manifesto (wrapper/manifest, generato qui da
    wrapper/cmd/mkmanifest): codice app + vendor/. Accanto allo zip scrive
    la sua impronta (.sha256) e il manifesto (.manifest.json), i tre asset
    che la CI allega alla release.

    NON include wrapper.exe, opensagra-uninstaller.exe, installer e
    disinstaller: un aggiornamento leggero non tocca mai quelle cose (solo il
    codice applicativo). La CI lo allega a OGNI release insieme al suo
    .sha256 (scritto qui accanto); se usarlo o reinstallare lo decide il
    wrapper dal diff fra la versione installata e quella nuova
    (lightUpdatePlan in wrapper/update_check.go, piano Fase 6a).

    File come config/variabili.env, Caddyfile, uploads/* NON fanno parte di
    questo pacchetto perche' non sono tracciati da git (.gitignore) -
    l'estrazione in sovrascrittura (ApplyUpdate, mai un wipe-and-replace) li
    lascia quindi intatti senza bisogno di nessuna esclusione esplicita qui.

.PARAMETER Out
    Percorso dello zip risultante. Default: opensagra-app-update.zip nella
    root del repo (artefatto di build). Il nome deve restare quello di
    updateZipAssetName in wrapper/update_check.go.

.EXAMPLE
    .\packaging\make-update.ps1
#>
[CmdletBinding()]
param(
    [string]$Out
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
if (-not $Out) { $Out = Join-Path $RepoRoot 'opensagra-app-update.zip' }
# Nome che deve restare quello di updateManifestAssetName (wrapper/update_check.go).
$ManifestOut = $Out -replace '\.zip$', '.manifest.json'

. (Join-Path $PSScriptRoot 'Get-ReleaseVersion.ps1')
$releaseVersion = Get-ReleaseVersion -RepoRoot $RepoRoot

# Il manifesto (wrapper/manifest) e' l'UNICA lista dei file del codice app:
# lo zip si costruisce da quella, cosi' "cosa installa un aggiornamento" e
# "cosa il wrapper considera installato" non possono divergere. Lo zip lo
# contiene anche dentro, in config/.app_manifest.json: dopo l'estrazione
# l'installazione sa di che versione sono i suoi file.
Write-Host "1/3  Manifesto dei file (v$releaseVersion)..." -ForegroundColor Cyan
Push-Location (Join-Path $RepoRoot 'wrapper')
try {
    go run ./cmd/mkmanifest -root .. -version $releaseVersion -out $ManifestOut
    if ($LASTEXITCODE -ne 0) { throw 'mkmanifest fallito.' }
} finally {
    Pop-Location
}
$manifest = Get-Content -Raw $ManifestOut | ConvertFrom-Json
Write-Host "     $($manifest.files.Count) file" -ForegroundColor DarkGray

Write-Host '2/3  Zip...' -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path $Out) { Remove-Item $Out -Force }
$zip = [System.IO.Compression.ZipFile]::Open($Out, 'Create')
try {
    foreach ($rel in $manifest.files) {
        $full = Join-Path $RepoRoot ($rel -replace '/', '\')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $full, $rel) | Out-Null
    }
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $ManifestOut, 'config/.app_manifest.json') | Out-Null
} finally {
    $zip.Dispose()
}

# Impronta nel formato di sha256sum ("<hex>  <nome>"): il wrapper rifiuta lo
# zip se manca o non corrisponde (checkSHA256File in wrapper/self_update.go).
$hash = (Get-FileHash -Algorithm SHA256 $Out).Hash.ToLower()
"$hash  $(Split-Path -Leaf $Out)" | Out-File -FilePath "$Out.sha256" -Encoding ascii -NoNewline

Write-Host '3/3  Fatto.' -ForegroundColor Cyan
$info = Get-Item $Out
Write-Host ''
Write-Host "OK: $Out" -ForegroundColor Green
Write-Host ('  {0:N1} MB - {1}' -f ($info.Length / 1MB), $info.LastWriteTime)
Write-Host "    $ManifestOut"
Write-Host "    $Out.sha256"
